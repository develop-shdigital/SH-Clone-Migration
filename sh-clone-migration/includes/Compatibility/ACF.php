<?php
/**
 * Advanced Custom Fields compatibility.
 *
 * @package SHCM
 */

namespace SHCM\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * ACF stores its values as ordinary post meta and options, which the
 * serialization aware replacement engine already handles correctly.
 *
 * What is left is its caches: field group lookups and the JSON sync store.
 */
class ACF {

	/**
	 * Whether ACF data is present.
	 *
	 * @param \wpdb $db Database handle.
	 * @return bool
	 */
	public static function isPresent( $db ) {
		$posts = $db->prefix . 'posts';
		$found = $db->get_var(
			$db->prepare( "SELECT ID FROM `{$posts}` WHERE post_type IN (%s, %s) LIMIT 1", 'acf-field-group', 'acf-field' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		return null !== $found;
	}

	/**
	 * Remove ACF's cached lookups.
	 *
	 * @param \wpdb $db Database handle.
	 * @return array Report.
	 */
	public static function clearCaches( $db ) {
		$report = array( 'transients' => 0 );

		if ( ! self::isPresent( $db ) ) {
			return $report;
		}

		$options = $db->prefix . 'options';
		$report['transients'] = (int) $db->query(
			"DELETE FROM `{$options}` WHERE option_name LIKE '\_transient\_acf%'
			 OR option_name LIKE '\_transient\_timeout\_acf%'" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return $report;
	}

	/**
	 * Flush ACF's runtime caches once the plugin is loaded.
	 *
	 * @return array Report.
	 */
	public static function refresh() {
		$report = array( 'ran' => array() );

		if ( function_exists( 'acf_flush_value_cache' ) ) {
			acf_flush_value_cache();
			$report['ran'][] = 'acf_flush_value_cache';
		}
		if ( function_exists( 'acf_get_local_json_files' ) ) {
			$report['ran'][] = 'json_sync_available';
		}

		return $report;
	}
}
