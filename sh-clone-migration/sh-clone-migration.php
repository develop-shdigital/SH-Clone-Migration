<?php
/**
 * Plugin Name: SH Clone Migration
 * Plugin URI: https://github.com/develop-shdigital/SH-Clone-Migration
 * Description: Complete WordPress website cloning and migration system. Exports an entire site (database + files) into a single portable .wpress archive and restores it on any other WordPress installation.
 * Version: 1.0.0
 * Author: SH
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sh-clone-migration
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * @package SHCM
 */

defined( 'ABSPATH' ) || exit;

define( 'SHCM_VERSION', '1.0.0' );
define( 'SHCM_PLUGIN_FILE', __FILE__ );
define( 'SHCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHCM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once SHCM_PLUGIN_DIR . 'includes/bootstrap.php';

shcm_bootstrap();
