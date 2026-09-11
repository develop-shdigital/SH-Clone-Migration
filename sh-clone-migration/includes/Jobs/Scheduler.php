<?php
/**
 * Background worker and housekeeping.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

use SHCM\Compatibility\PostMigration;
use SHCM\Core\Plugin;
use SHCM\Filesystem\Paths;
use SHCM\Import\MaintenanceMode;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps jobs moving when the browser is not watching, and makes sure nothing
 * the plugin created outlives its usefulness.
 */
class Scheduler {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'addSchedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'shcm_worker', array( $this, 'runWorker' ) );
		add_action( 'shcm_cleanup', array( $this, 'runCleanup' ) );
		add_action( 'init', array( $this, 'guardMaintenanceMode' ), 1 );

		PostMigration::register();
	}

	/**
	 * Add the one minute schedule used by the worker.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function addSchedule( $schedules ) {
		if ( ! isset( $schedules['shcm_minute'] ) ) {
			$schedules['shcm_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (SH Clone Migration)', 'sh-clone-migration' ),
			);
		}
		return $schedules;
	}

	/**
	 * Advance a stalled job.
	 *
	 * The browser drives a migration while the tab is open; this is the safety
	 * net for the moment it is closed, and the only driver for a WP-Cron based
	 * export on a headless site.
	 *
	 * @return void
	 */
	public function runWorker() {
		if ( ! $this->plugin->settings()->getBool( 'enable_cron_worker', true ) ) {
			return;
		}

		$store = $this->plugin->jobs();
		foreach ( $store->all( null, 10 ) as $job ) {
			if ( ! $job->isRunnable() || Job::STATUS_PENDING === $job->status() ) {
				continue;
			}
			if ( $job->isEncrypted() ) {
				// The password only ever exists in the request that supplied
				// it, so an encrypted job cannot be resumed unattended.
				continue;
			}
			// Only pick up jobs the browser appears to have abandoned.
			if ( time() - (int) $job->get( 'updated_at' ) < 120 ) {
				continue;
			}

			$this->plugin->logger()->channel( $job->id() )->info( 'Resuming the job from WP-Cron.' );
			$this->plugin->runner()->tick( $job );
			return; // One job per cron run: never stack migrations.
		}
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public function runCleanup() {
		$settings = $this->plugin->settings();
		$storage  = $this->plugin->storage();

		$hours = $settings->getInt( 'cleanup_temp_hours', 24 );
		if ( $hours > 0 ) {
			$this->purgeDirectory( $storage->tmp(), $hours * HOUR_IN_SECONDS );
			$this->purgeDirectory( $storage->incoming(), $hours * HOUR_IN_SECONDS );
		}

		$days = $settings->getInt( 'retention_days', 30 );
		if ( $days > 0 ) {
			$this->plugin->jobs()->purgeOlderThan( $days );
			$this->purgeDirectory( $storage->logs(), $days * DAY_IN_SECONDS );
			$this->purgeDirectory( $storage->rollback(), $days * DAY_IN_SECONDS );
		}

		$max = $settings->getInt( 'max_archives', 0 );
		if ( $max > 0 ) {
			$catalog  = new \SHCM\Archive\Catalog( $storage );
			$archives = $catalog->all();
			foreach ( array_slice( $archives, $max ) as $archive ) {
				$catalog->delete( $archive['name'] );
			}
		}
	}

	/**
	 * Never let a maintenance page outlive the migration that created it.
	 *
	 * @return void
	 */
	public function guardMaintenanceMode() {
		if ( ! MaintenanceMode::isEnabled() ) {
			return;
		}

		$store  = $this->plugin->jobs();
		$active = false;
		foreach ( $store->all( Job::TYPE_IMPORT, 5 ) as $job ) {
			if ( $job->isRunnable() && time() - (int) $job->get( 'updated_at' ) < 900 ) {
				$active = true;
				break;
			}
		}

		if ( ! $active ) {
			MaintenanceMode::disable();
			$this->plugin->logger()->warning( 'Maintenance mode was left behind by an abandoned migration and has been switched off.' );
		}
	}

	/**
	 * Delete files older than a given age.
	 *
	 * @param string $directory Directory.
	 * @param int    $max_age   Maximum age in seconds.
	 * @return int Files removed.
	 */
	protected function purgeDirectory( $directory, $max_age ) {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}
		$removed = 0;
		$cutoff  = time() - $max_age;
		$items   = glob( Paths::trailingslash( $directory ) . '*' );
		if ( ! is_array( $items ) ) {
			return 0;
		}
		foreach ( $items as $item ) {
			$name = basename( $item );
			if ( 'index.php' === $name || '.htaccess' === $name || 'web.config' === $name ) {
				continue;
			}
			if ( filemtime( $item ) > $cutoff ) {
				continue;
			}
			if ( is_dir( $item ) ) {
				\SHCM\Filesystem\Storage::rmdirRecursive( $item );
			} else {
				@unlink( $item );
			}
			++$removed;
		}
		return $removed;
	}
}
