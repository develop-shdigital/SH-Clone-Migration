<?php
/**
 * Uninstaller.
 *
 * Website content is never touched here. Only data this plugin created is
 * removed, and only when the administrator explicitly asked for it in the
 * settings.
 *
 * @package SHCM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$shcm_settings = get_option( 'shcm_settings', array() );
$shcm_full     = is_array( $shcm_settings ) && ! empty( $shcm_settings['delete_data_on_uninstall'] );

// Scheduled events and the maintenance flag belong to the plugin in every case.
wp_clear_scheduled_hook( 'shcm_worker' );
wp_clear_scheduled_hook( 'shcm_cleanup' );
wp_clear_scheduled_hook( 'shcm_run_pending_compatibility' );

$shcm_maintenance = ABSPATH . '.maintenance';
if ( file_exists( $shcm_maintenance ) ) {
	@unlink( $shcm_maintenance );
}

// The download rule in .htaccess (normally removed on deactivation already):
// the site's root .htaccess and, for WordPress in its own directory, that one.
if ( ! function_exists( 'get_home_path' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
$shcm_htaccess_files = array_unique( array( get_home_path() . '.htaccess', ABSPATH . '.htaccess' ) );
foreach ( $shcm_htaccess_files as $shcm_htaccess ) {
	if ( is_file( $shcm_htaccess ) && is_writable( $shcm_htaccess ) ) {
		$shcm_rules    = (string) file_get_contents( $shcm_htaccess );
		$shcm_stripped = preg_replace( '/^# BEGIN SH Clone Migration\R.*?^# END SH Clone Migration[^\S\r\n]*\R?/ms', '', $shcm_rules );
		if ( is_string( $shcm_stripped ) && $shcm_stripped !== $shcm_rules ) {
			file_put_contents( $shcm_htaccess, ltrim( $shcm_stripped, "\r\n" ), LOCK_EX );
		}
	}
}
delete_transient( 'shcm_delivery_probe' );
delete_transient( 'shcm_htaccess_attempt' );

if ( ! $shcm_full ) {
	return;
}

delete_option( 'shcm_settings' );
delete_option( 'shcm_version' );
delete_option( 'shcm_pending_compatibility' );
delete_transient( 'shcm_uploads_size' );

if ( is_multisite() ) {
	delete_site_option( 'shcm_settings' );
	delete_site_option( 'shcm_version' );
}

$shcm_role = get_role( 'administrator' );
if ( $shcm_role && $shcm_role->has_cap( 'shcm_manage_migrations' ) ) {
	$shcm_role->remove_cap( 'shcm_manage_migrations' );
}

/**
 * Recursively delete the plugin storage directory.
 *
 * @param string $directory Directory.
 * @return void
 */
function shcm_uninstall_rmdir( $directory ) {
	if ( ! is_dir( $directory ) ) {
		return;
	}
	$items = @scandir( $directory );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $directory . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			shcm_uninstall_rmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $directory );
}

$shcm_storage = WP_CONTENT_DIR . '/shcm-storage';
if ( is_dir( $shcm_storage ) ) {
	shcm_uninstall_rmdir( $shcm_storage );
}

$shcm_maintenance_page = WP_CONTENT_DIR . '/maintenance.php';
if ( is_file( $shcm_maintenance_page ) && false !== strpos( (string) file_get_contents( $shcm_maintenance_page ), 'SH Clone Migration' ) ) {
	@unlink( $shcm_maintenance_page );
}
