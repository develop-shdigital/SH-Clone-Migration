<?php
/**
 * Database engine checks against a real MySQL/MariaDB server.
 *
 * Run inside a WordPress install with a scratch schema the database user can
 * write to:
 *
 *   SHCM_SCRATCH_DB=shcm_scratch wp eval-file tests/db/database-engine-test.php
 *
 * Every check prints PASS or FAIL; the exit code is the number of failures.
 *
 * @package SHCM
 */

use SHCM\Database\Exporter;
use SHCM\Database\Inspector;
use SHCM\Jobs\Budget;

$scratch = getenv( 'SHCM_SCRATCH_DB' ) ? getenv( 'SHCM_SCRATCH_DB' ) : 'shcm_scratch';
$fails   = 0;

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

$root = $connect( 'wp_' );
foreach ( (array) $root->get_col( 'SHOW TABLES' ) as $table ) {
	$root->query( "DROP TABLE IF EXISTS `{$table}`" );
}
$core = array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links', 'termmeta' );
foreach ( array( 'wp_', 'wp_shop_' ) as $prefix ) {
	foreach ( $core as $name ) {
		$root->query( "CREATE TABLE `{$prefix}{$name}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, v VARCHAR(20)) ENGINE=InnoDB" );
	}
}
// A plugin table that ends in "options" must not be mistaken for a site.
$root->query( 'CREATE TABLE `wp_foo_options` (id INT PRIMARY KEY) ENGINE=InnoDB' );
$root->query( 'CREATE TABLE `wp_foo_data` (id INT PRIMARY KEY) ENGINE=InnoDB' );
// A table no installation claims.
$root->query( 'CREATE TABLE `custom_log` (id INT PRIMARY KEY) ENGINE=InnoDB' );
// A forum plugin whose tables look like part of a site (Simple:Press).
foreach ( array( 'sfoptions', 'sfposts', 'sftopics', 'sfforums', 'sfmeta' ) as $name ) {
	$root->query( "CREATE TABLE `wp_{$name}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
}

echo "Ownership in a database shared by two installations\n";
foreach ( array( 'wp_', 'wp_shop_' ) as $prefix ) {
	$other      = 'wp_' === $prefix ? 'wp_shop_' : 'wp_';
	$inspector  = new Inspector( $connect( $prefix ) );
	$exportable = array_keys( $inspector->exportableTables() );
	$ours       = array_filter(
		$exportable,
		static function ( $t ) use ( $prefix, $other ) {
			return 0 === strpos( $t, $prefix ) && ( 'wp_' !== $prefix || 0 !== strpos( $t, $other ) );
		}
	);
	$theirs     = array_filter(
		$exportable,
		static function ( $t ) use ( $prefix, $other ) {
			return 0 === strpos( $t, $other ) && ( 'wp_' !== $other || 0 !== strpos( $t, $prefix ) );
		}
	);
	$check( "prefix {$prefix}: all 12 core tables selected", 12 === count( array_intersect( $exportable, array_map( static function ( $n ) use ( $prefix ) { return $prefix . $n; }, $core ) ) ) );
	$check( "prefix {$prefix}: none of {$other}'s core tables selected", 0 === count( array_intersect( $exportable, array_map( static function ( $n ) use ( $other ) { return $other . $n; }, $core ) ) ), implode( ',', $theirs ) );
	$check( "prefix {$prefix}: other installation reported", array( $other ) === $inspector->foreignInstallations(), implode( ',', $inspector->foreignInstallations() ) );
	$check( "prefix {$prefix}: unclaimed table custom_log kept", in_array( 'custom_log', $exportable, true ) );
}
$inspector = new Inspector( $connect( 'wp_' ) );
$check( 'prefix wp_: plugin tables wp_foo_options / wp_foo_data kept', 2 === count( array_intersect( array_keys( $inspector->exportableTables() ), array( 'wp_foo_options', 'wp_foo_data' ) ) ) );
$check( 'prefix wp_: forum tables wp_sfoptions/wp_sfposts/... kept, not taken for a site', 5 === count( preg_grep( '/^wp_sf/', array_keys( $inspector->exportableTables() ) ) ) && ! in_array( 'wp_sf', $inspector->foreignInstallations(), true ), implode( ',', $inspector->foreignInstallations() ) );
$check( 'wp_shop_users is not ours for wp_', ! $inspector->isOwnTableName( 'wp_shop_users' ) );
$check( 'wp_users is ours for wp_', $inspector->isOwnTableName( 'wp_users' ) );

echo "Key selection\n";
$root->query( 'CREATE TABLE `t_composite` (object_id BIGINT UNSIGNED NOT NULL, term_taxonomy_id BIGINT UNSIGNED NOT NULL, term_order INT NOT NULL DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id)) ENGINE=InnoDB' );
$root->query( 'CREATE TABLE `t_nullable` (ext_id INT NULL, v VARCHAR(10), UNIQUE KEY u (ext_id)) ENGINE=InnoDB' );
$root->query( 'CREATE TABLE `t_float` (f FLOAT NOT NULL, v VARCHAR(10), PRIMARY KEY (f)) ENGINE=InnoDB' );
$root->query( 'CREATE TABLE `t_nokey` (a INT, b VARCHAR(10)) ENGINE=InnoDB' );
$root->query( 'CREATE TABLE `t_text` (slug VARCHAR(40) NOT NULL, v INT, UNIQUE KEY s (slug)) ENGINE=InnoDB' );
$root->query( 'CREATE TABLE `t_hash` (url VARCHAR(1200) NOT NULL, v INT, UNIQUE KEY u (url) USING HASH) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
$check( 'composite primary key found', array( 'object_id', 'term_taxonomy_id' ) === $inspector->keyColumns( 't_composite' ) );
$check( 'unique key on a nullable column rejected', array() === $inspector->keyColumns( 't_nullable' ) );
$check( 'NOT NULL unique text key accepted', array( 'slug' ) === $inspector->keyColumns( 't_text' ) );
$check( 'long-unique HASH key rejected', array() === $inspector->keyColumns( 't_hash' ), implode( ',', $inspector->keyColumns( 't_hash' ) ) );

/**
 * Dump a table batch by batch (one batch per "request"), running a callback
 * between batches, and return the rows the dump contains.
 */
$dump = static function ( $db, Inspector $inspector, $table, $batch, callable $between = null, $max_requests = 1000 ) {
	$exporter = new Exporter( $db, $inspector, array( 'rows_per_query' => $batch ) );
	$state    = array();
	$sql      = '';
	$requests = 0;
	do {
		$budget = new Budget( 0.000001, 0 ); // Expired at once: exactly one batch per call.
		$state  = $exporter->dumpRows(
			$table,
			$state,
			$budget,
			static function ( $chunk ) use ( &$sql ) {
				$sql .= $chunk;
			}
		);
		if ( ! empty( $state['error'] ) ) {
			return array( 'error' => $state['error'], 'state' => $state, 'sql' => $sql );
		}
		++$requests;
		if ( null !== $between ) {
			$between( $requests );
		}
	} while ( empty( $state['done'] ) && $requests < $max_requests );
	return array( 'state' => $state, 'sql' => $sql, 'requests' => $requests );
};

$tuples = static function ( $sql ) {
	preg_match_all( '/\((\d+),(\d+),(\d+)\)/', $sql, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $row ) {
		$out[] = $row[1] . ':' . $row[2];
	}
	return $out;
};

echo "Composite key paging while the table changes\n";
$values = array();
for ( $i = 1; $i <= 3000; $i++ ) {
	$values[] = "({$i},1,0)";
}
$root->query( 'INSERT INTO t_composite VALUES ' . implode( ',', $values ) );
$result = $dump(
	$root,
	$inspector,
	't_composite',
	1000,
	static function ( $n ) use ( $root ) {
		if ( 1 === $n ) {
			$root->query( 'DELETE FROM t_composite WHERE object_id = 10 AND term_taxonomy_id = 1' );
			$root->query( 'INSERT INTO t_composite VALUES (5,2,0)' );
		}
	}
);
$rows    = $tuples( $result['sql'] );
$counts  = array_count_values( $rows );
$dupes   = array_filter( $counts, static function ( $c ) { return $c > 1; } );
$check( 'no row dumped twice', empty( $dupes ), implode( ',', array_keys( $dupes ) ) );
$check( 'untouched row 1001:1 present', isset( $counts['1001:1'] ) );
$check( 'every row that existed throughout is present', 2999 === count( array_intersect( array_keys( $counts ), array_map( static function ( $i ) { return $i . ':1'; }, array_diff( range( 1, 3000 ), array( 10 ) ) ) ) ) );
$check( 'row count recorded matches the dump', (int) $result['state']['rows'] === count( $rows ), $result['state']['rows'] . ' vs ' . count( $rows ) );

echo "Nullable unique key terminates and dumps every row\n";
$values = array();
for ( $i = 0; $i < 1500; $i++ ) {
	$values[] = "(NULL,'n{$i}')";
}
for ( $i = 1; $i <= 1000; $i++ ) {
	$values[] = "({$i},'v{$i}')";
}
$root->query( 'INSERT INTO t_nullable VALUES ' . implode( ',', $values ) );
$result = $dump( $root, $inspector, 't_nullable', 1000, null, 20 );
$check( 'finished', ! empty( $result['state']['done'] ), 'requests ' . ( isset( $result['requests'] ) ? $result['requests'] : '?' ) );
$check( 'all 2500 rows dumped exactly once', 2500 === (int) $result['state']['rows'] && 2500 === preg_match_all( "/'[nv]\d+'/", $result['sql'] ), (string) $result['state']['rows'] );

echo "Float key does not loop\n";
$values = array();
for ( $i = 1; $i <= 50; $i++ ) {
	$values[] = '(' . ( $i / 10 ) . ",'f{$i}')";
}
$root->query( 'INSERT INTO t_float VALUES ' . implode( ',', $values ) );
$result = $dump( $root, $inspector, 't_float', 7, null, 50 );
$check( 'finished with all 50 rows', ! empty( $result['state']['done'] ) && 50 === (int) $result['state']['rows'], (string) $result['state']['rows'] );

echo "Text key\n";
$values = array();
for ( $i = 1; $i <= 250; $i++ ) {
	$values[] = "('slug-" . sprintf( '%04d', $i ) . "',{$i})";
}
$root->query( 'INSERT INTO t_text VALUES ' . implode( ',', $values ) );
$result = $dump( $root, $inspector, 't_text', 60 );
$check( 'all 250 rows, in order, once', 250 === (int) $result['state']['rows'] && 250 === count( array_unique( preg_match_all( "/'slug-(\d{4})'/", $result['sql'], $m ) ? $m[1] : array() ) ) );

echo "Table without a key is ordered\n";
$values = array();
for ( $i = 1; $i <= 300; $i++ ) {
	$values[] = '(' . ( 301 - $i ) . ",'k{$i}')";
}
$root->query( 'INSERT INTO t_nokey VALUES ' . implode( ',', $values ) );
$result = $dump( $root, $inspector, 't_nokey', 100 );
$check( 'all 300 rows once', 300 === (int) $result['state']['rows'] && 300 === count( array_unique( preg_match_all( "/'k(\d+)'/", $result['sql'], $m ) ? $m[1] : array() ) ) );
$check( 'rows come out in the ORDER BY order', 0 === strpos( $result['sql'], 'INSERT INTO `t_nokey` (`a`, `b`) VALUES (1,' ), substr( $result['sql'], 0, 60 ) );

echo "Long-unique HASH key with values sharing a long prefix\n";
$prefix = 'https://example.com/' . str_repeat( 'a', 1100 ) . '/';
$values = array();
for ( $i = 1; $i <= 250; $i++ ) {
	$values[] = "('" . $prefix . sprintf( '%04d', $i ) . "',{$i})";
}
$root->query( 'INSERT INTO t_hash VALUES ' . implode( ',', $values ) );
$result = $dump( $root, $inspector, 't_hash', 40 );
$check( 'all 250 rows exactly once', 250 === (int) $result['state']['rows'] && 250 === count( array_unique( preg_match_all( "/\/(\d{4})'/", $result['sql'], $m ) ? $m[1] : array() ) ) );

echo "BIT columns keep their values; a BIT key loses no rows\n";
$root->query( 'CREATE TABLE `t_bit` (id BIT(8) NOT NULL PRIMARY KEY, active BIT(1) NOT NULL, mask BIT(8) NOT NULL) ENGINE=InnoDB' );
$values = array();
for ( $i = 0; $i < 20; $i++ ) {
	$values[] = '(' . $i . ',' . ( $i % 2 ) . ',' . ( $i * 7 % 256 ) . ')';
}
$root->query( 'INSERT INTO t_bit VALUES ' . implode( ',', $values ) );
$result = $dump( $root, $inspector, 't_bit', 3 );
$check( 'all 20 rows', 20 === (int) $result['state']['rows'], (string) $result['state']['rows'] );
$root->query( 'CREATE TABLE `t_bit_copy` LIKE `t_bit`' );
foreach ( array_filter( explode( ";\n", str_replace( 'INSERT INTO `t_bit`', 'INSERT INTO `t_bit_copy`', $result['sql'] ) ) ) as $statement ) {
	mysqli_query( $root->dbh, $statement );
}
$original = $root->get_results( 'SELECT id+0 AS id, active+0 AS a, mask+0 AS m FROM t_bit ORDER BY id', ARRAY_A );
$restored = $root->get_results( 'SELECT id+0 AS id, active+0 AS a, mask+0 AS m FROM t_bit_copy ORDER BY id', ARRAY_A );
$check( 'restored BIT values identical', $original === $restored, wp_json_encode( array_slice( $restored, 0, 3 ) ) );

echo "Non-ASCII key in a table that mixes character sets\n";
$root->query( 'CREATE TABLE `t_mixed` (slug VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY, hash CHAR(8) CHARACTER SET ascii NOT NULL) ENGINE=InnoDB' );
$values = array();
for ( $i = 1; $i <= 120; $i++ ) {
	$values[] = "('straße-ü-🎉-" . sprintf( '%04d', $i ) . "','h" . sprintf( '%07d', $i ) . "')";
}
// Through the raw connection: wpdb itself refuses this INSERT, for the very
// reason the dump must not send key values through wpdb::query().
mysqli_query( $root->dbh, 'INSERT INTO t_mixed VALUES ' . implode( ',', $values ) );
$result = $dump( $root, $inspector, 't_mixed', 25 );
$check( 'finished with all 120 rows', empty( $result['error'] ) && ! empty( $result['state']['done'] ) && 120 === (int) $result['state']['rows'], isset( $result['error'] ) ? $result['error'] : (string) $result['state']['rows'] );

echo "A read error mid-table is reported, never taken for the end of the table\n";
$values = array();
for ( $i = 1; $i <= 5000; $i++ ) {
	$values[] = "({$i},'r{$i}')";
}
$root->query( 'CREATE TABLE `t_locked` (id INT NOT NULL PRIMARY KEY, v VARCHAR(10)) ENGINE=InnoDB' );
$root->query( 'INSERT INTO t_locked VALUES ' . implode( ',', $values ) );
$reader = $connect( 'wp_' );
$locker = $connect( 'wp_' );
$reader->query( 'SET SESSION lock_wait_timeout = 1' );
$reader->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
$result = $dump(
	$reader,
	new Inspector( $reader ),
	't_locked',
	1000,
	static function ( $n ) use ( $locker ) {
		if ( 1 === $n ) {
			$locker->query( 'LOCK TABLES t_locked WRITE' );
		}
	},
	3
);
$check( 'error reported', ! empty( $result['error'] ), isset( $result['error'] ) ? '' : 'no error' );
$check( 'table not marked done', empty( $result['state']['done'] ) );
$check( 'cursor kept on the last written row (1000 rows)', 1000 === (int) $result['state']['rows'], (string) $result['state']['rows'] );
$locker->query( 'UNLOCK TABLES' );
// Resume from the saved state once the lock is gone.
$exporter = new Exporter( $reader, new Inspector( $reader ), array( 'rows_per_query' => 1000 ) );
$state    = $result['state'];
$sql      = $result['sql'];
do {
	$state = $exporter->dumpRows( 't_locked', $state, new Budget( 0.000001, 0 ), static function ( $c ) use ( &$sql ) { $sql .= $c; } );
} while ( empty( $state['done'] ) && empty( $state['error'] ) );
$check( 'resumed dump holds all 5000 rows exactly once', 5000 === (int) $state['rows'] && 5000 === count( array_unique( preg_match_all( "/'r(\d+)'/", $sql, $m ) ? $m[1] : array() ) ), (string) $state['rows'] );

echo "Statement time limit mid-table\n";
$reader2 = $connect( 'wp_' );
$result  = $dump(
	$reader2,
	new Inspector( $reader2 ),
	't_locked',
	1000,
	static function ( $n ) use ( $reader2 ) {
		if ( 1 === $n ) {
			$reader2->query( 'SET SESSION max_statement_time = 0.000001' );
		}
	},
	3
);
$check( 'max_statement_time error reported, table not done', ! empty( $result['error'] ) && empty( $result['state']['done'] ), isset( $result['error'] ) ? $result['error'] : 'no error' );

echo "Triggers are filtered to exported tables\n";
$root->query( 'CREATE TRIGGER trg_ours BEFORE INSERT ON wp_posts FOR EACH ROW SET NEW.v = NEW.v' );
$root->query( 'CREATE TRIGGER trg_theirs BEFORE INSERT ON wp_shop_posts FOR EACH ROW SET NEW.v = NEW.v' );
$names = array_map(
	static function ( $t ) {
		return $t['name'];
	},
	$inspector->triggers( array_keys( $inspector->exportableTables() ) )
);
$check( 'only the trigger on our table', array( 'trg_ours' ) === $names, implode( ',', $names ) );

foreach ( (array) $root->get_col( 'SHOW TABLES' ) as $table ) {
	$root->query( "DROP TABLE IF EXISTS `{$table}`" );
}

printf( "\n%s: %d failure(s)\n", $fails ? 'FAILED' : 'OK', $fails );
exit( $fails ? 1 : 0 );
