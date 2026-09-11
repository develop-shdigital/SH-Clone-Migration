<?php
/**
 * Autoloader and bootstrap helpers.
 *
 * Kept deliberately free of WordPress-only calls so the same autoloader can be
 * reused by the standalone maintenance-safe endpoint, the WP-CLI commands and
 * the PHPUnit test-suite.
 *
 * @package SHCM
 */

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

if ( ! defined( 'SHCM_INCLUDES_DIR' ) ) {
	define( 'SHCM_INCLUDES_DIR', __DIR__ . '/' );
}

/**
 * PSR-4 style autoloader for the SHCM\ namespace.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function shcm_autoload( $class ) {
	if ( 0 !== strncmp( $class, 'SHCM\\', 5 ) ) {
		return;
	}
	$relative = substr( $class, 5 );
	$path     = SHCM_INCLUDES_DIR . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}
}

spl_autoload_register( 'shcm_autoload' );

// Minimal polyfills: WordPress ships these from 5.9 upwards, but the plugin
// also runs from WP-CLI/standalone contexts on older cores.
if ( ! function_exists( 'str_contains' ) ) {
	/**
	 * Polyfill for PHP < 8.0.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	function str_contains( $haystack, $needle ) {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}
if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Polyfill for PHP < 8.0.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	function str_starts_with( $haystack, $needle ) {
		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}
if ( ! function_exists( 'str_ends_with' ) ) {
	/**
	 * Polyfill for PHP < 8.0.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	function str_ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return 0 === $len || ( strlen( $haystack ) >= $len && substr( $haystack, -$len ) === $needle );
	}
}

/**
 * Boot the plugin inside a WordPress request.
 *
 * @return \SHCM\Core\Plugin
 */
function shcm_bootstrap() {
	static $plugin = null;
	if ( null === $plugin ) {
		$plugin = \SHCM\Core\Plugin::instance();
		$plugin->boot();
	}
	return $plugin;
}

// Time constants used by the engine, also available when WordPress is absent.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}
