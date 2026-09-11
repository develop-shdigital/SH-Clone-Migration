<?php
/**
 * Post migration compatibility tasks.
 *
 * @package SHCM
 */

namespace SHCM\Compatibility;

use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the clean-up each major ecosystem needs after a site is moved.
 *
 * Work that only touches the database or the filesystem happens immediately.
 * Anything that needs a plugin's own API is deferred: right after a restore the
 * plugins are on disk but not loaded in the running request, so the task is
 * queued and executed on the next admin request instead of being skipped.
 */
class PostMigration {

	const PENDING_OPTION = 'shcm_pending_compatibility';

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Database handle.
	 */
	public function __construct( $db = null ) {
		global $wpdb;
		$this->db = $db ? $db : $wpdb;
	}

	/**
	 * Register the deferred runner.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'runPending' ), 5 );
		add_action( 'shcm_run_pending_compatibility', array( __CLASS__, 'runPending' ) );
	}

	/**
	 * Queue the deferred tasks.
	 *
	 * @param array $components Component flags.
	 * @return void
	 */
	public function queue( array $components ) {
		update_option( self::PENDING_OPTION, $components, false );
	}

	/**
	 * Execute the deferred tasks once their plugins are loaded.
	 *
	 * @return array Log of what ran.
	 */
	public static function runPending() {
		$pending = get_option( self::PENDING_OPTION );
		if ( empty( $pending ) || ! is_array( $pending ) ) {
			return array();
		}

		$done = array();

		if ( ! empty( $pending['elementor'] ) && did_action( 'elementor/loaded' ) ) {
			$done['elementor'] = Elementor::regenerate();
			unset( $pending['elementor'] );
		}
		if ( ! empty( $pending['woocommerce'] ) && class_exists( 'WooCommerce' ) ) {
			$done['woocommerce'] = WooCommerce::refresh();
			unset( $pending['woocommerce'] );
		}
		if ( ! empty( $pending['acf'] ) && class_exists( 'ACF' ) ) {
			$done['acf'] = ACF::refresh();
			unset( $pending['acf'] );
		}

		if ( ! empty( $pending['flush_rewrite'] ) ) {
			flush_rewrite_rules( false );
			$done['flush_rewrite'] = true;
			unset( $pending['flush_rewrite'] );
		}

		// Anything still pending stays queued for the next request; give up
		// after a week so a missing plugin does not keep the flag forever.
		if ( empty( $pending ) || ( isset( $pending['queued_at'] ) && $pending['queued_at'] < time() - WEEK_IN_SECONDS ) ) {
			delete_option( self::PENDING_OPTION );
		} else {
			update_option( self::PENDING_OPTION, $pending, false );
		}

		return $done;
	}

	/**
	 * Everything that can be done right now, without the plugins being loaded.
	 *
	 * @param Storage $storage Storage helper.
	 * @return array Report.
	 */
	public function runImmediate( Storage $storage ) {
		$report = array();

		$report['transients'] = $this->clearTransients();
		$report['caches']     = $this->clearObjectCache();
		$report['elementor']  = Elementor::clearGeneratedFiles( $this->db );
		$report['woocommerce'] = WooCommerce::clearSessions( $this->db );
		$report['acf']        = ACF::clearCaches( $this->db );
		$report['opcache']    = $this->resetOpcache();

		unset( $storage );

		return $report;
	}

	/**
	 * Delete stale transients: after a move they refer to the old domain.
	 *
	 * @return int Rows removed.
	 */
	public function clearTransients() {
		$options = $this->db->prefix . 'options';
		$removed = 0;

		$removed += (int) $this->db->query(
			"DELETE FROM `{$options}` WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_transient\_timeout\_%'" // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$removed += (int) $this->db->query(
			"DELETE FROM `{$options}` WHERE option_name LIKE '\_site\_transient\_%' OR option_name LIKE '\_site\_transient\_timeout\_%'" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( is_multisite() ) {
			$sitemeta = $this->db->base_prefix . 'sitemeta';
			$exists   = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $sitemeta ) );
			if ( $exists ) {
				$removed += (int) $this->db->query(
					"DELETE FROM `{$sitemeta}` WHERE meta_key LIKE '\_site\_transient\_%'" // phpcs:ignore WordPress.DB.PreparedSQL
				);
			}
		}

		return $removed;
	}

	/**
	 * Flush the object cache.
	 *
	 * @return bool
	 */
	public function clearObjectCache() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			return (bool) wp_cache_flush();
		}
		return false;
	}

	/**
	 * Reset the opcode cache so restored PHP files are picked up immediately.
	 *
	 * @return bool
	 */
	public function resetOpcache() {
		if ( function_exists( 'opcache_reset' ) && ini_get( 'opcache.enable' ) ) {
			return (bool) @opcache_reset();
		}
		return false;
	}

	/**
	 * Delete generated cache directories that must not survive a move.
	 *
	 * @return int Directories removed.
	 */
	public function clearGeneratedDirectories() {
		$removed  = 0;
		$content  = Paths::contentDir();
		$targets  = array(
			$content . '/cache',
			$content . '/et-cache',
			$content . '/uploads/cache',
			$content . '/uploads/elementor/css',
			$content . '/uploads/oxygen/css',
			$content . '/uploads/bb-plugin/cache',
			$content . '/uploads/wp-rocket',
		);

		foreach ( $targets as $dir ) {
			if ( is_dir( $dir ) && Paths::isInside( $dir, $content ) ) {
				Storage::rmdirRecursive( $dir );
				++$removed;
			}
		}

		return $removed;
	}
}
