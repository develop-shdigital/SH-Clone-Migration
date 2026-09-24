<?php
/**
 * Import safety checks against a real MySQL/MariaDB server.
 *
 *   SHCM_SCRATCH_DB=shcm_scratch wp eval-file tests/db/import-safety-test.php
 *
 * Archives are built with the real Writer and restored with the real import
 * DatabaseStage into a scratch schema, one request at a time. Every check
 * prints PASS or FAIL; the exit code is the number of failures.
 *
 * @package SHCM
 */

use SHCM\Archive\Format;
use SHCM\Archive\Writer;
use SHCM\Database\Inspector;
use SHCM\Import\Stages\DatabaseStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Json;

$scratch = getenv( 'SHCM_SCRATCH_DB' ) ? getenv( 'SHCM_SCRATCH_DB' ) : 'shcm_scratch';
$fails   = 0;
$plugin  = shcm_bootstrap();
$work    = $plugin->storage()->tmp() . '/import-safety-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $work );

$check = static function ( $label, $ok, $detail = '' ) use ( &$fails ) {
	if ( ! $ok ) {
		++$fails;
	}
	printf( "  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || '' === $detail ? '' : '  [' . $detail . ']' );
};

$connect = static function ( $prefix ) use ( $scratch ) {
	$db = new wpdb( DB_USER, DB_PASSWORD, $scratch, DB_HOST );
	$db->suppress_errors( true );
	$db->show_errors( false );
	$db->set_prefix( $prefix );
	return $db;
};

$reset = static function ( $db ) {
	foreach ( (array) $db->get_col( 'SHOW TABLES' ) as $table ) {
		$db->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
};

$site_tables = array( 'options', 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'links' );
$create_site = static function ( $db, $prefix, $with_users = true ) use ( $site_tables ) {
	$tables = $with_users ? array_merge( $site_tables, array( 'users', 'usermeta' ) ) : $site_tables;
	foreach ( $tables as $name ) {
		if ( 'options' === $name ) {
			$db->query( "CREATE TABLE `{$prefix}options` (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes') ENGINE=InnoDB" );
			$db->query( "INSERT INTO `{$prefix}options` (option_name, option_value) VALUES ('blogname','LIVE {$prefix}'),('home','http://live.test'),('siteurl','http://live.test'),('active_plugins','a:0:{}')" );
		} else {
			$db->query( "CREATE TABLE `{$prefix}{$name}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, v TEXT) ENGINE=InnoDB" );
		}
	}
};

/**
 * Build an archive: manifest, db meta and one entry per table dump.
 *
 * @param array  $tables name => SQL body (after the header).
 * @param string $prefix Source prefix.
 */
$build = static function ( $path, $prefix, array $tables, array $meta_overrides = array(), $block = 1048576 ) {
	$writer = Writer::create( $path, array( 'block_size' => $block ) );
	$writer->addString( Format::ENTRY_MANIFEST, Json::encode( array( 'site' => array( 'home' => 'http://source.test' ), 'wordpress' => array( 'table_prefix' => $prefix, 'multisite' => false ) ) ) );
	$list = array();
	foreach ( array_keys( $tables ) as $name ) {
		$list[] = array( 'name' => $name, 'type' => 'table', 'rows' => 0 );
	}
	$writer->addString( Format::ENTRY_DB_META, Json::encode( array_merge( array( 'included' => true, 'prefix' => $prefix, 'charset' => 'utf8mb4', 'tables' => $list ), $meta_overrides ) ) );
	foreach ( $tables as $name => $body ) {
		if ( null === $body ) {
			continue; // Listed in the metadata, but no dump in the archive.
		}
		$writer->addString( Format::tableEntryPath( $name ), $body, array( 'group' => 'database' ) );
	}
	$writer->addString( 'files/wp-content/readme.txt', 'x' );
	$writer->close();
	$writer->release();
};

$options_dump = static function ( $table, $extra = '' ) {
	return "DROP TABLE IF EXISTS `{$table}`;\nCREATE TABLE `{$table}` (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes') ENGINE=InnoDB;\n"
		. "INSERT INTO `{$table}` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (1,'blogname','ARCHIVED','yes'),(2,'home','http://source.test','yes'),(3,'siteurl','http://source.test','yes'),(4,'active_plugins','a:2:{i:0;s:19:\"akismet/akismet.php\";i:1;s:27:\"woocommerce/woocommerce.php\";}','yes'),(5,'stylesheet','twentyfive','yes'),(6,'template','twentyfive','yes');\n" . $extra;
};

$simple_dump = static function ( $table, $rows = 3, $key = true ) {
	$sql = "DROP TABLE IF EXISTS `{$table}`;\nCREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL" . ( $key ? ' PRIMARY KEY' : '' ) . ", v TEXT) ENGINE=InnoDB;\n";
	for ( $i = 1; $i <= $rows; $i++ ) {
		$sql .= "INSERT INTO `{$table}` (`id`, `v`) VALUES ({$i},'archived {$i}');\n";
	}
	return $sql;
};

$make_stage = static function ( $db ) use ( $plugin ) {
	return new DatabaseStage( $plugin->settings(), $plugin->storage(), $plugin->logger(), new Inspector( $db ), $db );
};

$make_job = static function ( $archive, $mode = 'replace' ) {
	$job = Job::create( 'import', array( 'archive_path' => $archive, 'import_mode' => $mode, 'include_database' => true ), array( 'database' ) );
	$job->setShared( 'manifest', array( 'wordpress' => array( 'multisite' => false ) ) );
	$job->setShared( 'destination', 'http://destination.test' );
	return $job;
};

/**
 * Run the stage request by request until it completes (or fails).
 */
$drive = static function ( $stage, Job $job, $seconds = 60, $max = 500 ) {
	$error = '';
	for ( $i = 0; $i < $max; $i++ ) {
		try {
			$result = $stage->run( $job, new Budget( $seconds, 0 ) );
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
			break;
		}
		$data = $result->data();
		if ( ! empty( $data['complete'] ) ) {
			break;
		}
	}
	return array( 'error' => $error, 'requests' => $i + 1 );
};

$db = $connect( 'wp_' );

echo "A single statement larger than the 8 MB checkpoint\n";
$reset( $db );
$create_site( $db, 'wp_' );
$big     = str_repeat( 'x', 12000000 );
$archive = $work . '/big.wpress';
$build( $archive, 'wp_', array( 'wp_options' => $options_dump( 'wp_options', "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (7,'big','" . $big . "','no');\nINSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (8,'after_big','yes','no');\n" ) ) );
$job = $make_job( $archive );
$run = $drive( $make_stage( $db ), $job, 60, 40 );
$check( 'restore finishes', '' === $run['error'] && $run['requests'] < 40, $run['error'] . ' requests=' . $run['requests'] );
$check( 'the 12 MB row and the row after it are there', 12000000 === (int) $db->get_var( "SELECT LENGTH(option_value) FROM wp_options WHERE option_name='big'" ) && 'yes' === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name='after_big'" ) );
unset( $big );

echo "A sibling installation sharing users (CUSTOM_USER_TABLE) is never dropped\n";
$reset( $db );
$create_site( $db, 'wp_' );
$create_site( $db, 'wp_shop_', false );
$archive = $work . '/plain.wpress';
$build( $archive, 'wp_', array( 'wp_options' => $options_dump( 'wp_options' ), 'wp_posts' => $simple_dump( 'wp_posts' ) ) );
$run = $drive( $make_stage( $db ), $make_job( $archive ) );
$left = $db->get_col( "SHOW TABLES LIKE 'wp\\_shop\\_%'" );
$check( 'all 10 wp_shop_ tables still there', 10 === count( $left ), count( $left ) . ' ' . $run['error'] );
$check( "sibling's blogname untouched", 'LIVE wp_shop_' === $db->get_var( "SELECT option_value FROM wp_shop_options WHERE option_name='blogname'" ) );

echo "An archive whose metadata lists tables it does not contain\n";
$reset( $db );
$create_site( $db, 'wp_' );
$archive = $work . '/legacy-nodb.wpress';
$build( $archive, 'wp_', array( 'wp_options' => null, 'wp_posts' => null ), array( 'included' => null ) );
$job = $make_job( $archive );
$run = $drive( $make_stage( $db ), $job );
$check( 'nothing dropped, database left as it was', 'LIVE wp_' === $db->get_var( "SELECT option_value FROM wp_options WHERE option_name='blogname'" ), $run['error'] );
$check( 'reported as an archive without a database', ! empty( $job->shared( 'database_absent' ) ) );

echo "A table that would land on a sibling installation's name is skipped\n";
$reset( $db );
$create_site( $db, 'wp_' );
$create_site( $db, 'wp_shop_' );
$db->query( "CREATE TABLE wp_shop_orders (id INT PRIMARY KEY, v TEXT) ENGINE=InnoDB" );
$db->query( "INSERT INTO wp_shop_orders VALUES (1,'LIVE order')" );
$archive = $work . '/collide.wpress';
$build( $archive, 'wp_', array( 'wp_options' => $options_dump( 'wp_options' ), 'wp_shop_orders' => $simple_dump( 'wp_shop_orders', 1 ) ) );
$job = $make_job( $archive );
$run = $drive( $make_stage( $db ), $job );
$check( "sibling's wp_shop_orders untouched", 'LIVE order' === $db->get_var( 'SELECT v FROM wp_shop_orders WHERE id = 1' ), (string) $db->get_var( 'SELECT v FROM wp_shop_orders WHERE id = 1' ) . ' ' . $run['error'] );
$check( 'and a warning says so', (bool) preg_grep( '/wp_shop_orders/', array_column( (array) $job->get( 'warnings' ), 'message' ) ) );

echo "A request killed part way through a keyless table\n";
$reset( $db );
$create_site( $db, 'wp_' );
$archive = $work . '/replay.wpress';
$build( $archive, 'wp_', array( 'wp_options' => $options_dump( 'wp_options' ), 'wp_keyless' => $simple_dump( 'wp_keyless', 3000, false ) ), array(), 65536 );
$job   = $make_job( $archive );
$stage = $make_stage( $db );
$state = null;
$guard = 0;
$marker = $plugin->storage()->tmp() . '/' . $job->id() . '-restore.marker';
while ( $guard++ < 200 ) {
	$saved = $job->stageState( 'database', array() );
	if ( 'restore' === ( isset( $saved['phase'] ) ? $saved['phase'] : '' ) && ! empty( $saved['entry'] ) && false !== strpos( $saved['entry']['path'], 'wp_keyless' ) && $saved['raw_offset'] > 0 ) {
		// The next request runs, then "dies": its state is never saved and
		// its marker stays behind.
		$before = Json::encode( $saved );
		$stage->run( $job, new Budget( 0.000001, 0 ) );
		$job->setStageState( 'database', Json::decode( $before ) );
		file_put_contents( $marker, Json::encode( array( 'entry_offset' => (int) $saved['entry_offset'], 'raw_offset' => (int) $saved['raw_offset'] ) ) );
		break;
	}
	$stage->run( $job, new Budget( 0.000001, 0 ) );
}
$run = $drive( $stage, $job );
$check( 'restore completes after the resume', '' === $run['error'], $run['error'] );
$check( 'no row inserted twice (3000 rows, 3000 distinct)', 3000 === (int) $db->get_var( 'SELECT COUNT(*) FROM wp_keyless' ) && 3000 === (int) $db->get_var( 'SELECT COUNT(DISTINCT id) FROM wp_keyless' ), $db->get_var( 'SELECT COUNT(*) FROM wp_keyless' ) . ' rows' );

echo "Real duplicate keys are reported, not silently dropped\n";
$reset( $db );
$create_site( $db, 'wp_' );
$archive = $work . '/dups.wpress';
$dups    = "DROP TABLE IF EXISTS `wp_steps`;\nCREATE TABLE `wp_steps` (step VARCHAR(10) NOT NULL PRIMARY KEY, v TEXT) ENGINE=InnoDB;\nINSERT INTO `wp_steps` (`step`, `v`) VALUES ('a','first'),('b','second');\nINSERT INTO `wp_steps` (`step`, `v`) VALUES ('b','dup'),('c','third');\n";
$build( $archive, 'wp_', array( 'wp_options' => $options_dump( 'wp_options' ), 'wp_steps' => $dups ) );
$job = $make_job( $archive );
$run = $drive( $make_stage( $db ), $job );
$warnings = array_column( (array) $job->get( 'warnings' ), 'message' );
$check( 'the other rows are restored', 3 === (int) $db->get_var( 'SELECT COUNT(*) FROM wp_steps' ), $run['error'] );
$check( 'a warning names the table and the row count', (bool) preg_grep( '/wp_steps \\(1\\)/', $warnings ), implode( ' | ', $warnings ) );

echo "The source's active plugins survive a kill right after they are overwritten\n";
$reset( $db );
$create_site( $db, 'wp_' );
$archive = $work . '/plugins.wpress';
$build( $archive, 'wp_', array( 'wp_options' => $options_dump( 'wp_options' ) ) );
$job   = $make_job( $archive );
$stage = $make_stage( $db );
$guard = 0;
while ( $guard++ < 50 ) {
	$phase = $job->stageState( 'database', array() );
	if ( isset( $phase['phase'] ) && 'finalize' === $phase['phase'] ) {
		break;
	}
	$stage->run( $job, new Budget( 60, 0 ) );
}
// Simulate the kill: afterRestore() overwrote active_plugins, the state was not saved.
$db->query( "UPDATE wp_options SET option_value = 'a:1:{i:0;s:41:\"sh-clone-migration/sh-clone-migration.php\";}' WHERE option_name = 'active_plugins'" );
$stage->run( $job, new Budget( 60, 0 ) );
$check( 'source active plugins still known', false !== strpos( (string) $job->shared( 'source_active_plugins' ), 'woocommerce' ), (string) $job->shared( 'source_active_plugins' ) );

$reset( $db );
\SHCM\Filesystem\Storage::rmdirRecursive( $work );
printf( "\n%s: %d failure(s)\n", $fails ? 'FAILED' : 'OK', $fails );
exit( $fails ? 1 : 0 );
