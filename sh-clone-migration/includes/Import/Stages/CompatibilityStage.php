<?php
/**
 * Import: post migration tasks.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Compatibility\ACF;
use SHCM\Compatibility\Elementor;
use SHCM\Compatibility\PostMigration;
use SHCM\Compatibility\WooCommerce;
use SHCM\Core\Settings;
use SHCM\Database\Inspector;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the restored site back into a working state: the right theme, the right
 * plugins, fresh rewrite rules and no stale caches.
 */
class CompatibilityStage extends AbstractStage {

	/**
	 * Inspector.
	 *
	 * @var Inspector
	 */
	protected $inspector;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Storage   $storage   Storage.
	 * @param Logger    $logger    Logger.
	 * @param Inspector $inspector Inspector.
	 * @param \wpdb     $db        Database handle.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger, Inspector $inspector, $db ) {
		parent::__construct( $settings, $storage, $logger );
		$this->inspector = $inspector;
		$this->db        = $db;
	}

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'compatibility';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Running post migration tasks', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 6;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		unset( $budget );

		$report = array();

		$report['theme']   = $this->restoreTheme( $job );
		$report['plugins'] = $this->restorePlugins( $job );

		$post = new PostMigration( $this->db );
		$report['cleanup'] = $post->runImmediate( $this->storage );

		$components = array(
			'elementor'     => Elementor::isPresent( $this->db ),
			'woocommerce'   => WooCommerce::isPresent( $this->db ),
			'acf'           => ACF::isPresent( $this->db ),
			'flush_rewrite' => true,
			'queued_at'     => time(),
		);
		$post->queue( array_filter( $components ) );
		$report['queued'] = array_keys( array_filter( $components ) );

		$this->flushRewriteRules( $job );

		$job->setShared( 'compatibility_report', $report );

		$this->logger->info(
			sprintf(
				'Post migration tasks done. Theme: %1$s. Plugins reactivated: %2$d. Transients cleared: %3$d.',
				isset( $report['theme']['stylesheet'] ) ? $report['theme']['stylesheet'] : '-',
				isset( $report['plugins']['activated'] ) ? count( $report['plugins']['activated'] ) : 0,
				isset( $report['cleanup']['transients'] ) ? $report['cleanup']['transients'] : 0
			)
		);

		return $this->complete( __( 'Post migration tasks completed', 'sh-clone-migration' ) );
	}

	/**
	 * Restore the source theme when its files made it across.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	protected function restoreTheme( Job $job ) {
		$theme = (array) $job->shared( 'source_theme', array() );
		$sheet = isset( $theme['stylesheet'] ) ? (string) $theme['stylesheet'] : '';
		$tpl   = isset( $theme['template'] ) ? (string) $theme['template'] : '';

		if ( '' === $sheet ) {
			return array( 'stylesheet' => '' );
		}

		$themes_dir = Paths::contentDir() . '/themes';
		$available  = is_dir( $themes_dir . '/' . $sheet );
		$parent_ok  = '' === $tpl || is_dir( $themes_dir . '/' . $tpl );

		if ( ! $available || ! $parent_ok ) {
			$job->addWarning(
				sprintf(
					/* translators: %s: theme directory */
					__( 'The active theme "%s" is not present after the restore. WordPress will fall back to a default theme.', 'sh-clone-migration' ),
					$sheet
				)
			);
			return array(
				'stylesheet' => $sheet,
				'restored'   => false,
			);
		}

		$options = $this->db->prefix . 'options';
		foreach ( array(
			'stylesheet' => $sheet,
			'template'   => '' !== $tpl ? $tpl : $sheet,
		) as $name => $value ) {
			$this->db->query(
				$this->db->prepare(
					"UPDATE `{$options}` SET option_value = %s WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$value,
					$name
				)
			);
		}

		return array(
			'stylesheet' => $sheet,
			'template'   => $tpl,
			'restored'   => true,
		);
	}

	/**
	 * Reactivate the plugins the source had active, skipping any whose files
	 * did not arrive.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	protected function restorePlugins( Job $job ) {
		$serialized = (string) $job->shared( 'source_active_plugins', '' );
		$active     = $serialized ? maybe_unserialize( $serialized ) : array();
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		if ( ! $this->settings->getBool( 'restore_active_plugins', true ) ) {
			return array(
				'activated' => array(),
				'skipped'   => $active,
				'note'      => 'disabled_by_setting',
			);
		}

		$plugin_dir = Paths::pluginDir();
		$activated  = array();
		$missing    = array();

		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( '' === $file || false !== strpos( $file, '..' ) ) {
				continue;
			}
			if ( is_file( $plugin_dir . '/' . $file ) ) {
				$activated[] = $file;
			} else {
				$missing[] = $file;
			}
		}

		// Keep this plugin active so the admin screens keep working.
		$self = defined( 'SHCM_PLUGIN_BASENAME' ) ? SHCM_PLUGIN_BASENAME : 'sh-clone-migration/sh-clone-migration.php';
		if ( $this->settings->getBool( 'reactivate_self', true ) && ! in_array( $self, $activated, true ) ) {
			$activated[] = $self;
		}

		sort( $activated );

		$options = $this->db->prefix . 'options';
		$this->db->query(
			$this->db->prepare(
				"UPDATE `{$options}` SET option_value = %s WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				serialize( $activated ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'active_plugins'
			)
		);

		if ( ! empty( $missing ) ) {
			$job->addWarning(
				sprintf(
					/* translators: %s: plugin list */
					__( 'These plugins were active on the source site but their files are missing here, so they stay deactivated: %s', 'sh-clone-migration' ),
					implode( ', ', array_slice( $missing, 0, 20 ) )
				)
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'activated' => $activated,
			'missing'   => $missing,
		);
	}

	/**
	 * Regenerate the rewrite rules for the restored permalink structure.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	protected function flushRewriteRules( Job $job ) {
		$options = $this->db->prefix . 'options';
		$this->db->query(
			$this->db->prepare( "DELETE FROM `{$options}` WHERE option_name = %s", 'rewrite_rules' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// The rules are rebuilt on the next front end or admin request, when
		// the restored plugins and their custom post types are actually loaded.
		$job->setShared( 'rewrite_rules_flushed', true );
	}

	/**
	 * Cleanup.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		if ( null !== $error && $job->shared( 'maintenance' ) ) {
			MaintenanceMode::disable();
			$job->setShared( 'maintenance', false );
		}
	}
}
