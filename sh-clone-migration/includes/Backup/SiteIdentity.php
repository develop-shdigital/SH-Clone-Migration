<?php
/**
 * Which installation this is.
 *
 * @package SHCM
 */

namespace SHCM\Backup;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * A fingerprint of the installation, taken from values that come from
 * wp-config.php and the filesystem rather than from the database.
 *
 * The schedule and the Drive connection remember the fingerprint of the site
 * that set them up. A copy of the site made by other means (a host's staging
 * tool, a manual copy of wp-content and the database) carries the files over
 * but gets a different fingerprint, so it does not start uploading into, and
 * pruning, the original site's Google Drive folder.
 */
final class SiteIdentity {

	/**
	 * Fingerprint of this installation.
	 *
	 * @return string 64 hex characters.
	 */
	public static function fingerprint() {
		global $wpdb;

		// Resolved: WP-CLI takes ABSPATH from --path as typed (a symlink to the
		// current release, say), PHP on the web from the real directory.
		// DB_HOST is left out on purpose: hosts move database servers and
		// rewrite it ("localhost" becoming "127.0.0.1") while the site stays
		// the same one.
		$abspath = defined( 'ABSPATH' ) ? (string) ABSPATH : '';
		$real    = '' !== $abspath ? realpath( $abspath ) : false;
		$parts   = array(
			rtrim( str_replace( '\\', '/', false !== $real ? $real : $abspath ), '/' ),
			defined( 'DB_NAME' ) ? (string) DB_NAME : '',
			isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '',
			self::label(),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Human readable name of this installation: its address, the network's
	 * on multisite (backups cover the whole network, and every site of it
	 * must compute the same fingerprint).
	 *
	 * @return string
	 */
	public static function label() {
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'network_home_url' ) ) {
			$home = (string) network_home_url();
		} else {
			$home = function_exists( 'home_url' ) ? (string) home_url() : '';
		}
		return rtrim( preg_replace( '#^https?://#i', '', $home ), '/' );
	}
}
