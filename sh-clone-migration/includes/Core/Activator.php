<?php
/**
 * Activation and deactivation.
 *
 * @package SHCM
 */

namespace SHCM\Core;

use SHCM\Filesystem\Storage;
use SHCM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares (and tidies up after) the plugin without ever touching site content.
 */
class Activator {

	/**
	 * Run on activation and after an update.
	 *
	 * @return void
	 */
	public static function activate() {
		$storage = new Storage();
		$storage->prepare();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		update_option( 'shcm_version', SHCM_VERSION, false );

		Capabilities::grant();

		if ( ! wp_next_scheduled( 'shcm_worker' ) ) {
			wp_schedule_event( time() + 60, 'shcm_minute', 'shcm_worker' );
		}
		if ( ! wp_next_scheduled( 'shcm_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'shcm_cleanup' );
		}

		ServerRules::install();
	}

	/**
	 * Run on deactivation. Nothing is deleted here.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'shcm_worker' );
		wp_clear_scheduled_hook( 'shcm_cleanup' );

		// A migration must never leave the site behind a maintenance page.
		\SHCM\Import\MaintenanceMode::disable();

		ServerRules::remove();
	}
}
