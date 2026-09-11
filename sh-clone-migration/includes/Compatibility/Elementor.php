<?php
/**
 * Elementor compatibility.
 *
 * @package SHCM
 */

namespace SHCM\Compatibility;

use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor stores absolute URLs inside JSON in post meta and compiles CSS
 * files that embed them, so a moved site needs both a data pass and a
 * regeneration pass.
 *
 * The data pass is handled by the URL replacement engine (Elementor's JSON has
 * escaped slashes, which the rule builder covers). This class deals with the
 * generated files and the plugin level caches.
 */
class Elementor {

	/**
	 * Whether Elementor data is present in this installation.
	 *
	 * @param \wpdb $db Database handle.
	 * @return bool
	 */
	public static function isPresent( $db ) {
		$table = $db->prefix . 'postmeta';
		$found = $db->get_var(
			$db->prepare( "SELECT meta_id FROM `{$table}` WHERE meta_key = %s LIMIT 1", '_elementor_data' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		return null !== $found;
	}

	/**
	 * Remove compiled CSS and the meta that points at it.
	 *
	 * @param \wpdb $db Database handle.
	 * @return array Report.
	 */
	public static function clearGeneratedFiles( $db ) {
		$report = array(
			'files'   => 0,
			'meta'    => 0,
			'options' => 0,
		);

		if ( ! self::isPresent( $db ) ) {
			return $report;
		}

		$css_dir = Paths::uploadsDir() . '/elementor/css';
		if ( is_dir( $css_dir ) && Paths::isInside( $css_dir, Paths::contentDir() ) ) {
			$files = glob( $css_dir . '/*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) && @unlink( $file ) ) {
						$report['files']++;
					}
				}
			}
		}

		$postmeta = $db->prefix . 'postmeta';
		$report['meta'] = (int) $db->query(
			$db->prepare( "DELETE FROM `{$postmeta}` WHERE meta_key = %s", '_elementor_css' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$options = $db->prefix . 'options';
		$report['options'] = (int) $db->query(
			"DELETE FROM `{$options}` WHERE option_name IN ('_elementor_global_css', 'elementor_global_css', '_elementor_assets_data')" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$termmeta = $db->prefix . 'termmeta';
		$db->query(
			$db->prepare( "DELETE FROM `{$termmeta}` WHERE meta_key = %s", '_elementor_css' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return $report;
	}

	/**
	 * Run Elementor's own regeneration routines, once the plugin is loaded.
	 *
	 * @return array Report.
	 */
	public static function regenerate() {
		$report = array( 'ran' => array() );

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			$instance = \Elementor\Plugin::$instance;

			if ( isset( $instance->files_manager ) && method_exists( $instance->files_manager, 'clear_cache' ) ) {
				$instance->files_manager->clear_cache();
				$report['ran'][] = 'files_manager::clear_cache';
			}
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$report['ran'][] = 'css_regeneration_scheduled';
			}
		}

		if ( class_exists( '\Elementor\Core\Upgrade\Manager' ) ) {
			// Elementor runs its own upgrade routines when the version option
			// differs; make sure they are triggered on the next load.
			$report['ran'][] = 'upgrade_check';
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'elementor/core/files/clear_cache' );
		}

		return $report;
	}
}
