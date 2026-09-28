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

		$parts = array(
			defined( 'ABSPATH' ) ? rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) : '',
			defined( 'DB_NAME' ) ? (string) DB_NAME : '',
			defined( 'DB_HOST' ) ? strtolower( (string) DB_HOST ) : '',
			isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '',
			self::label(),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Human readable name of this installation.
	 *
	 * @return string
	 */
	public static function label() {
		$home = function_exists( 'home_url' ) ? (string) home_url() : '';
		return rtrim( preg_replace( '#^https?://#i', '', $home ), '/' );
	}
}
