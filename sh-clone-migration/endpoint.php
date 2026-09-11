<?php
/**
 * Standalone migration endpoint.
 *
 * WordPress refuses every request while a .maintenance file exists, including
 * admin-ajax. A restore needs maintenance mode on and needs to keep talking to
 * the server, so this entry point bootstraps WordPress with WP_INSTALLING set,
 * which is the documented way core lets its own installer through.
 *
 * It is not a back door: the request still has to carry a valid authentication
 * cookie, the migration capability and a valid nonce, exactly like the
 * admin-ajax route.
 *
 * @package SHCM
 */

// phpcs:disable WordPress.Security.NonceVerification.Missing

if ( ! defined( 'WP_INSTALLING' ) ) {
	define( 'WP_INSTALLING', true );
}
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}

/**
 * Send a JSON envelope identical to the one wp_send_json_* produces.
 *
 * @param bool  $success Success flag.
 * @param array $data    Payload.
 * @param int   $status  HTTP status.
 * @return void
 */
function shcm_endpoint_respond( $success, array $data, $status = 200 ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		http_response_code( $status );
	}
	echo wp_json_encode(
		array(
			'success' => (bool) $success,
			'data'    => $data,
		)
	);
	exit;
}

/**
 * Find wp-load.php by walking up from this file.
 *
 * @return string|null
 */
function shcm_endpoint_locate_wp_load() {
	$directory = __DIR__;
	for ( $depth = 0; $depth < 12; $depth++ ) {
		$candidate = $directory . '/wp-load.php';
		if ( is_file( $candidate ) ) {
			return $candidate;
		}
		$parent = dirname( $directory );
		if ( $parent === $directory ) {
			break;
		}
		$directory = $parent;
	}
	return null;
}

$shcm_wp_load = shcm_endpoint_locate_wp_load();
if ( null === $shcm_wp_load ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	http_response_code( 500 );
	echo '{"success":false,"data":{"message":"WordPress could not be located from the migration endpoint."}}';
	exit;
}

require_once $shcm_wp_load;

// WordPress deliberately skips every active plugin while wp_installing() is
// true, so this file loads the migration plugin itself. That is a feature
// here: the restore runs with a minimal WordPress, and a half restored plugin
// on disk cannot fatal the request that is driving the restore.
if ( ! function_exists( 'shcm_bootstrap' ) ) {
	$shcm_main = __DIR__ . '/sh-clone-migration.php';
	if ( ! is_file( $shcm_main ) ) {
		shcm_endpoint_respond( false, array( 'message' => 'SH Clone Migration is not installed at the expected location.' ), 500 );
	}
	require_once $shcm_main;
}

if ( ! function_exists( 'shcm_bootstrap' ) ) {
	shcm_endpoint_respond( false, array( 'message' => 'SH Clone Migration could not be loaded.' ), 500 );
}

$shcm_plugin = shcm_bootstrap();

$shcm_action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
$shcm_action = str_replace( 'shcm_', '', $shcm_action );

// Only the actions a restore needs are reachable here; everything else keeps
// using admin-ajax, where WordPress performs its usual request handling.
$shcm_allowed = array( 'tick', 'status', 'cancel', 'resumable' );
if ( ! in_array( $shcm_action, $shcm_allowed, true ) ) {
	shcm_endpoint_respond( false, array( 'message' => 'This action is not available on the migration endpoint.' ), 400 );
}

// A restore replaces the users table, so the operator's cookie stops being
// valid mid-run. A job token, issued when the job was created by a fully
// authenticated request, authorises finishing that one job.
$shcm_token   = isset( $_REQUEST[ \SHCM\Security\JobToken::PARAM ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ \SHCM\Security\JobToken::PARAM ] ) ) : '';
$shcm_job_id  = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ) : '';
$shcm_by_token = false;

if ( '' !== $shcm_token && '' !== $shcm_job_id ) {
	$shcm_job      = $shcm_plugin->jobs()->load( $shcm_job_id );
	$shcm_by_token = \SHCM\Security\JobToken::authorises( $shcm_job, $shcm_token, $shcm_action );
}

if ( ! $shcm_by_token ) {
	if ( ! is_user_logged_in() ) {
		shcm_endpoint_respond( false, array( 'message' => 'Authentication required.' ), 401 );
	}
	if ( ! \SHCM\Security\Capabilities::currentUserCan() ) {
		shcm_endpoint_respond( false, array( 'message' => 'You do not have permission to run migrations on this site.' ), 403 );
	}

	$shcm_nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $shcm_nonce, \SHCM\Security\Request::NONCE_ACTION ) ) {
		shcm_endpoint_respond( false, array( 'message' => 'Your session expired. Reload the page and try again.' ), 403 );
	}
}

try {
	$shcm_ajax   = new \SHCM\Admin\Ajax( $shcm_plugin );
	$shcm_result = $shcm_ajax->dispatch( $shcm_action );
} catch ( \Throwable $shcm_error ) {
	$shcm_plugin->logger()->error( 'Endpoint ' . $shcm_action . ' failed: ' . $shcm_error->getMessage() );
	shcm_endpoint_respond( false, array( 'message' => $shcm_error->getMessage() ), 500 );
}

shcm_endpoint_respond( true, (array) $shcm_result );
