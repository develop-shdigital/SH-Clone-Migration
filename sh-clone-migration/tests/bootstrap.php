<?php
/**
 * PHPUnit bootstrap.
 *
 * The unit suite runs the pure PHP parts of the plugin without a WordPress
 * installation; the handful of WordPress functions the engine touches are
 * shimmed here.
 *
 * @package SHCM
 */

define( 'SHCM_ALLOW_STANDALONE', true );
define( 'SHCM_VERSION', '1.0.0' );
define( 'SHCM_TESTS_DIR', __DIR__ );

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';
require_once __DIR__ . '/wp-shims.php';
