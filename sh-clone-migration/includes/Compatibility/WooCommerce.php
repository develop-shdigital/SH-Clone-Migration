<?php
/**
 * WooCommerce compatibility.
 *
 * @package SHCM
 */

namespace SHCM\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce keeps sessions, lookup tables and a pile of transients that all
 * refer to the site it was running on.
 *
 * Payment gateway credentials are migrated as-is because they are part of the
 * store configuration, but customer sessions are dropped: they are tied to
 * cookies for the old domain and are worthless (and mildly risky) on the new
 * one.
 */
class WooCommerce {

	/**
	 * Whether WooCommerce data is present.
	 *
	 * @param \wpdb $db Database handle.
	 * @return bool
	 */
	public static function isPresent( $db ) {
		$table = $db->prefix . 'woocommerce_sessions';
		return (bool) $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Drop sessions and stale transients.
	 *
	 * @param \wpdb $db Database handle.
	 * @return array Report.
	 */
	public static function clearSessions( $db ) {
		$report = array(
			'sessions'   => false,
			'transients' => 0,
		);

		if ( ! self::isPresent( $db ) ) {
			return $report;
		}

		$sessions = $db->prefix . 'woocommerce_sessions';
		$db->query( "TRUNCATE TABLE `{$sessions}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$report['sessions'] = true;

		$options = $db->prefix . 'options';
		$report['transients'] = (int) $db->query(
			"DELETE FROM `{$options}` WHERE option_name LIKE '\_transient\_wc\_%'
			 OR option_name LIKE '\_transient\_timeout\_wc\_%'
			 OR option_name LIKE '\_transient\_woocommerce\_%'
			 OR option_name LIKE '\_transient\_timeout\_woocommerce\_%'" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return $report;
	}

	/**
	 * Run WooCommerce's own refresh routines once it is loaded.
	 *
	 * @return array Report.
	 */
	public static function refresh() {
		$report = array( 'ran' => array() );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
			$report['ran'][] = 'wc_delete_product_transients';
		}
		if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
			wc_delete_shop_order_transients();
			$report['ran'][] = 'wc_delete_shop_order_transients';
		}
		if ( class_exists( '\WC_Install' ) && method_exists( '\WC_Install', 'check_version' ) ) {
			\WC_Install::check_version();
			$report['ran'][] = 'WC_Install::check_version';
		}
		if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
			$report['ran'][] = 'lookup_tables_available';
		}

		return $report;
	}
}
