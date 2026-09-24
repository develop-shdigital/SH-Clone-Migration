<?php
/**
 * Drive an export one tiny request at a time.
 *
 *   wp eval-file tests/wordpress/export-harness.php name=edge include_core=1 mutate=wp-content/uploads/big/video.mp4
 *
 * budget=normal uses the regular per-request budget instead.
 *
 * Every tick gets an already expired budget, so each stage does the least
 * work it can (one directory, one file slice, one table batch) and has to
 * resume in the next request, which is the path a slow host exercises. When
 * mutate= names a file, bytes are appended to it once, after its copy has
 * started, to prove the export discards the torn copy and starts again.
 *
 * Prints "ARCHIVE <path>" and "TICKS <n>" on success.
 *
 * @package SHCM
 */

use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;

$options = array();
foreach ( (array) $args as $arg ) {
	if ( false !== strpos( $arg, '=' ) ) {
		list( $key, $value ) = explode( '=', $arg, 2 );
		$options[ $key ]     = $value;
	}
}

$plugin = shcm_bootstrap();

// The same job Controller::startExport() creates, minus its first tick
// (which, with a normal budget, would finish a small site in one go).
$params = array(
	'name'                   => isset( $options['name'] ) ? $options['name'] : 'harness',
	'include_core'           => ! empty( $options['include_core'] ),
	'include_database'       => true,
	'include_foreign_tables' => false,
	'exclude_tables'         => array(),
	'exclusions'             => array(),
	'encrypted'              => false,
);
$job = Job::create( Job::TYPE_EXPORT, $params, $plugin->registry()->stagesFor( Job::TYPE_EXPORT, $params ) );
$plugin->jobs()->save( $job );

$job_id   = $job->id();
$mutate   = isset( $options['mutate'] ) ? ABSPATH . ltrim( $options['mutate'], '/' ) : '';
$mutated  = false;
$ticks    = 0;
$restarts = 0;

while ( true ) {
	$job = $plugin->jobs()->load( $job_id );
	if ( $job->isFinished() ) {
		break;
	}
	$job = $plugin->runner()->tick( $job, isset( $options['budget'] ) && 'normal' === $options['budget'] ? Budget::create() : new Budget( 0.000001, 0 ) );
	++$ticks;

	$files = $job->stageState( 'files', array() );
	if ( '' !== $mutate && ! $mutated && ! empty( $files['current']['absolute'] )
		&& realpath( $files['current']['absolute'] ) === realpath( $mutate ) && $files['current']['offset'] > 0 ) {
		// The copy of this file is under way: change it underneath.
		file_put_contents( $mutate, str_repeat( 'appended-after-copy-started ', 1000 ), FILE_APPEND );
		touch( $mutate, time() + 5 );
		clearstatcache();
		$mutated = true;
		fwrite( STDOUT, "MUTATED at offset {$files['current']['offset']}\n" );
	}
	if ( $ticks > 200000 ) {
		fwrite( STDERR, "Too many ticks\n" );
		exit( 2 );
	}
}

$job = $plugin->jobs()->load( $job_id );
if ( 'completed' !== $job->status() ) {
	$error = $job->get( 'error' );
	fwrite( STDERR, 'FAILED: ' . ( is_array( $error ) && isset( $error['message'] ) ? $error['message'] : 'unknown' ) . "\n" );
	exit( 1 );
}

foreach ( (array) $job->get( 'warnings' ) as $warning ) {
	fwrite( STDOUT, 'WARNING ' . $warning['message'] . "\n" );
}
fwrite( STDOUT, 'MUTATED ' . ( $mutated ? 'yes' : 'no' ) . "\n" );
fwrite( STDOUT, 'TICKS ' . $ticks . "\n" );
fwrite( STDOUT, 'SHA256 ' . $job->shared( 'archive_sha256', '' ) . "\n" );
fwrite( STDOUT, 'DATABASE ' . wp_json_encode( $job->shared( 'database_totals' ) ) . "\n" );
fwrite( STDOUT, 'GROUPS ' . wp_json_encode( $job->shared( 'entry_groups' ) ) . "\n" );
fwrite( STDOUT, 'ARCHIVE ' . $job->param( 'archive_path' ) . "\n" );
