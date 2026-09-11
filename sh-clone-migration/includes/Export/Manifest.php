<?php
/**
 * Archive manifest.
 *
 * @package SHCM
 */

namespace SHCM\Export;

use SHCM\Archive\Format;
use SHCM\Database\Inspector;
use SHCM\Filesystem\Paths;
use SHCM\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the manifest and the safe configuration snapshot stored in an archive.
 *
 * Nothing secret goes in here: no database credentials, no authentication
 * keys, no salts. The manifest is readable without the migration password so
 * that the import screen can describe an archive before decrypting it.
 */
class Manifest {

	/**
	 * Build the manifest for an export job.
	 *
	 * @param Job       $job       Job.
	 * @param Inspector $inspector Inspector.
	 * @return array
	 */
	public static function build( Job $job, Inspector $inspector ) {
		$site    = (array) $job->shared( 'site', array() );
		$plugins = (array) $job->shared( 'plugins', array() );
		$themes  = (array) $job->shared( 'themes', array() );
		$files   = (array) $job->shared( 'file_totals', array() );
		$tables  = (array) $job->shared( 'tables', array() );

		return array(
			'format'      => Format::VERSION,
			'generator'   => 'SH Clone Migration',
			'version'     => defined( 'SHCM_VERSION' ) ? SHCM_VERSION : 'dev',
			'job'         => $job->id(),
			'created'     => time(),
			'created_utc' => gmdate( 'c' ),
			'site'        => $site,
			'wordpress'   => array(
				'version'      => $job->shared( 'wordpress_version', '' ),
				'abspath'      => Paths::abspath(),
				'content_dir'  => Paths::contentDir(),
				'plugin_dir'   => Paths::pluginDir(),
				'uploads_dir'  => Paths::uploadsDir(),
				'table_prefix' => $inspector->prefix(),
				'multisite'    => is_multisite(),
			),
			'php'         => array(
				'version' => PHP_VERSION,
				'sapi'    => PHP_SAPI,
			),
			'database'    => array(
				'server'  => self::databaseServer(),
				'charset' => $inspector->charset(),
				'prefix'  => $inspector->prefix(),
				'tables'  => count( $tables ),
				'size'    => (int) $job->shared( 'database_size', 0 ),
				'table_list' => $tables,
				'skipped' => (array) $job->shared( 'tables_skipped', array() ),
			),
			'files'       => array(
				'count'  => isset( $files['files'] ) ? (int) $files['files'] : 0,
				'size'   => isset( $files['bytes'] ) ? (int) $files['bytes'] : 0,
				'groups' => isset( $files['groups'] ) ? $files['groups'] : array(),
				'roots'  => array_keys( (array) $job->shared( 'roots', array() ) ),
			),
			'plugins'     => isset( $plugins['installed'] ) ? $plugins['installed'] : array(),
			'mu_plugins'  => isset( $plugins['mu'] ) ? $plugins['mu'] : array(),
			'dropins'     => isset( $plugins['dropins'] ) ? $plugins['dropins'] : array(),
			'active_plugins' => isset( $plugins['active'] ) ? $plugins['active'] : array(),
			'network_active_plugins' => isset( $plugins['network_active'] ) ? $plugins['network_active'] : array(),
			'themes'      => isset( $themes['installed'] ) ? $themes['installed'] : array(),
			'active_theme' => array(
				'stylesheet' => isset( $themes['stylesheet'] ) ? $themes['stylesheet'] : '',
				'template'   => isset( $themes['template'] ) ? $themes['template'] : '',
			),
			'components'  => (array) $job->shared( 'components', array() ),
			'archive'     => array(
				'block_size'  => (int) $job->param( 'block_size', Format::DEFAULT_BLOCK_SIZE ),
				'compression' => $job->param( 'compression', 'gzip' ),
				'encrypted'   => (bool) $job->param( 'encrypted', false ),
				'include_core' => (bool) $job->param( 'include_core', false ),
			),
			'exclusions'  => (array) $job->param( 'exclusions', array() ),
		);
	}

	/**
	 * Database server description.
	 *
	 * @return string
	 */
	protected static function databaseServer() {
		global $wpdb;
		$version = isset( $wpdb ) ? $wpdb->get_var( 'SELECT VERSION()' ) : '';
		return is_string( $version ) ? $version : '';
	}

	/**
	 * WordPress constants that are safe to record and useful on the other side.
	 *
	 * Database credentials and authentication salts are deliberately absent:
	 * the destination keeps its own, and an archive must never carry them.
	 *
	 * @return array
	 */
	public static function configMetadata() {
		$constants = array(
			'WP_DEBUG',
			'WP_DEBUG_LOG',
			'WP_DEBUG_DISPLAY',
			'WP_MEMORY_LIMIT',
			'WP_MAX_MEMORY_LIMIT',
			'WP_POST_REVISIONS',
			'AUTOSAVE_INTERVAL',
			'EMPTY_TRASH_DAYS',
			'WPLANG',
			'WP_CACHE',
			'DISALLOW_FILE_EDIT',
			'DISALLOW_FILE_MODS',
			'AUTOMATIC_UPDATER_DISABLED',
			'WP_AUTO_UPDATE_CORE',
			'FS_METHOD',
			'UPLOADS',
			'COOKIE_DOMAIN',
			'CONCATENATE_SCRIPTS',
			'SCRIPT_DEBUG',
			'WP_SITEURL',
			'WP_HOME',
			'WP_CONTENT_DIR',
			'WP_CONTENT_URL',
			'WP_PLUGIN_DIR',
			'WPMU_PLUGIN_DIR',
			'MULTISITE',
			'SUBDOMAIN_INSTALL',
			'DOMAIN_CURRENT_SITE',
			'PATH_CURRENT_SITE',
			'SITE_ID_CURRENT_SITE',
			'BLOG_ID_CURRENT_SITE',
			'WP_ALLOW_MULTISITE',
		);

		$values = array();
		foreach ( $constants as $constant ) {
			if ( defined( $constant ) ) {
				$value = constant( $constant );
				if ( is_scalar( $value ) || null === $value ) {
					$values[ $constant ] = $value;
				}
			}
		}

		$config = array(
			'constants'    => $values,
			'table_prefix' => isset( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : '',
			'php_version'  => PHP_VERSION,
			'server'       => array(
				'software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			),
		);

		// The server configuration files are recorded for reference only. They
		// are never written to the destination automatically, because their
		// contents are specific to the source server.
		$htaccess = Paths::abspath() . '/.htaccess';
		if ( is_readable( $htaccess ) && filesize( $htaccess ) < 262144 ) {
			$config['htaccess'] = (string) file_get_contents( $htaccess );
		}
		$robots = Paths::abspath() . '/robots.txt';
		if ( is_readable( $robots ) && filesize( $robots ) < 65536 ) {
			$config['robots'] = (string) file_get_contents( $robots );
		}

		return $config;
	}
}
