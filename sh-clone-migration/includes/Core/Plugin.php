<?php
/**
 * Plugin container and bootstrap.
 *
 * @package SHCM
 */

namespace SHCM\Core;

use SHCM\Admin\Ajax;
use SHCM\Admin\Menu;
use SHCM\Admin\Notices;
use SHCM\Admin\Rest;
use SHCM\Database\Inspector;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\JobRunner;
use SHCM\Jobs\JobStore;
use SHCM\Jobs\Registry;
use SHCM\Jobs\Scheduler;
use SHCM\Logging\Logger;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Wires the plugin together.
 *
 * Services are created lazily so that a front end request that never touches
 * the migration engine pays almost nothing for the plugin being active.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Resolved services.
	 *
	 * @var array
	 */
	protected $services = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		register_activation_hook( SHCM_PLUGIN_FILE, array( Activator::class, 'activate' ) );
		register_deactivation_hook( SHCM_PLUGIN_FILE, array( Activator::class, 'deactivate' ) );

		add_action( 'init', array( $this, 'loadTextdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'maybeUpgrade' ), 5 );

		if ( is_admin() ) {
			( new Menu( $this ) )->register();
			( new Notices( $this ) )->register();
			add_action( 'admin_init', array( ServerRules::class, 'maybeInstall' ) );
		}

		( new Ajax( $this ) )->register();
		( new Rest( $this ) )->register();
		( new Scheduler( $this ) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'shcm', \SHCM\CLI\Commands::class );
			\WP_CLI::add_command( 'sh-migration', \SHCM\CLI\Commands::class );
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function loadTextdomain() {
		load_plugin_textdomain( 'sh-clone-migration', false, dirname( SHCM_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Run housekeeping after an update, and make sure the storage directory is
	 * protected even if it was recreated by a restore.
	 *
	 * @return void
	 */
	public function maybeUpgrade() {
		$installed = get_option( 'shcm_version' );
		if ( SHCM_VERSION === $installed ) {
			return;
		}
		Activator::activate();
		update_option( 'shcm_version', SHCM_VERSION, false );
	}

	/**
	 * Settings service.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->service(
			'settings',
			static function () {
				return new Settings();
			}
		);
	}

	/**
	 * Storage service.
	 *
	 * @return Storage
	 */
	public function storage() {
		return $this->service(
			'storage',
			static function () {
				return new Storage();
			}
		);
	}

	/**
	 * Logger service.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->service(
			'logger',
			function () {
				return new Logger( $this->storage(), $this->settings()->get( 'log_level', 'info' ) );
			}
		);
	}

	/**
	 * Environment service.
	 *
	 * @return Environment
	 */
	public function environment() {
		return $this->service(
			'environment',
			function () {
				return new Environment( $this->storage() );
			}
		);
	}

	/**
	 * Database inspector.
	 *
	 * @return Inspector
	 */
	public function inspector() {
		return $this->service(
			'inspector',
			static function () {
				return new Inspector();
			}
		);
	}

	/**
	 * Job store.
	 *
	 * @return JobStore
	 */
	public function jobs() {
		return $this->service(
			'jobs',
			function () {
				return new JobStore( $this->storage() );
			}
		);
	}

	/**
	 * Stage registry.
	 *
	 * @return Registry
	 */
	public function registry() {
		return $this->service(
			'registry',
			function () {
				return new Registry( $this );
			}
		);
	}

	/**
	 * Job runner.
	 *
	 * @return JobRunner
	 */
	public function runner() {
		return $this->service(
			'runner',
			function () {
				return new JobRunner( $this->jobs(), $this->registry(), $this->logger(), $this->settings() );
			}
		);
	}

	/**
	 * Lazily resolve a service.
	 *
	 * @param string   $key     Service key.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	protected function service( $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = call_user_func( $factory );
		}
		return $this->services[ $key ];
	}

	/**
	 * Replace a service (used by the test-suite and WP-CLI).
	 *
	 * @param string $key     Key.
	 * @param mixed  $service Service.
	 * @return void
	 */
	public function setService( $key, $service ) {
		$this->services[ $key ] = $service;
	}

	/**
	 * Forget cached services after a database replacement.
	 *
	 * @return void
	 */
	public function flushServices() {
		unset( $this->services['settings'], $this->services['inspector'] );
	}
}
