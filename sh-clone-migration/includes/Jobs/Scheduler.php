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

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Keeps jobs moving when the browser is not watching, and makes sure nothing
 * the plugin created outlives its usefulness.
 */
class Scheduler {

	/**
	 * Seconds a browser-driven job must sit untouched before the worker
	 * assumes its tab was closed.
	 */
	const WORKER_IDLE = 120;

	/**
	 * Seconds a background job (a backup) must sit untouched before the
	 * worker takes over from the loopback chain that normally drives it.
	 *
	 * A working chain saves at the end of each slice and starts the next one
	 * a moment later, and a slice in progress holds the job's lock (checked
	 * separately). Short enough that the worker, whose own slices can last
	 * half a minute, gets the job again at the next minute.
	 */
	const WORKER_IDLE_BACKGROUND = 20;

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
	 * net for the moment it is closed, and the fallback driver for background
	 * jobs (scheduled backups) when loopback requests do not get through.
	 *
	 * @return void
	 */
	public function runWorker() {
		// The setting is about browser migrations. Background jobs have no
		// other fallback driver, so the worker keeps taking care of them.
		$browser_jobs = $this->plugin->settings()->getBool( 'enable_cron_worker', true );

		$runner = $this->plugin->runner();
		$job    = self::pickWorkerJob( $this->plugin->jobs()->all( null, 10 ), $runner->jobsDirectory(), time(), $browser_jobs );
		if ( null === $job ) {
			return;
		}

		// One job per cron run: never stack migrations.
		$this->plugin->logger()->channel( $job->id() )->info(
			JobLock::cancelRequested( $runner->jobsDirectory(), $job->id() )
				? 'Carrying out the cancel request from WP-Cron (the request advancing the job stopped before it could).'
				: 'Resuming the job from WP-Cron.'
		);
		$job = $runner->tick( $job );

		// Busy means another request took the job between the probe and the
		// tick: nothing was advanced, so there is nothing to follow up on.
		if ( ! $job->runtime( 'busy', false ) && function_exists( 'do_action' ) ) {
			/**
			 * Fires after the WP-Cron worker advanced a job, so a background
			 * job can arrange its next slice.
			 *
			 * @param Job $job The job as the tick left it.
			 */
			do_action( 'shcm_worker_ticked', $job );
		}
	}

	/**
	 * The job the worker should advance, if any.
	 *
	 * A job with a pending cancel request comes first: the user is waiting
	 * for it and carrying it out takes a moment. Background jobs come next:
	 * nobody else is going to drive them, while an abandoned browser
	 * migration may still be resumed by its user.
	 *
	 * @param Job[]  $jobs         Candidate jobs, newest first.
	 * @param string $jobs_dir     Jobs directory (for the lock probe).
	 * @param int    $now          Current time.
	 * @param bool   $browser_jobs Whether browser-driven jobs may be taken
	 *                             ("Background worker" setting).
	 * @return Job|null
	 */
	public static function pickWorkerJob( array $jobs, $jobs_dir, $now, $browser_jobs = true ) {
		$background = null;
		$fallback   = null;
		foreach ( $jobs as $job ) {
			if ( ! $job instanceof Job || ( ! $browser_jobs && ! $job->param( 'background' ) ) || ! self::workerMayTick( $job, $jobs_dir, $now ) ) {
				continue;
			}
			if ( JobLock::cancelRequested( $jobs_dir, $job->id() ) ) {
				return $job;
			}
			if ( $job->param( 'background' ) ) {
				if ( null === $background ) {
					$background = $job;
				}
			} elseif ( null === $fallback ) {
				$fallback = $job;
			}
		}
		return null !== $background ? $background : $fallback;
	}

	/**
	 * Whether the worker may advance a job.
	 *
	 * @param Job    $job      Job.
	 * @param string $jobs_dir Jobs directory (for the lock probe).
	 * @param int    $now      Current time.
	 * @return bool
	 */
	public static function workerMayTick( Job $job, $jobs_dir, $now ) {
		if ( ! $job->isRunnable() ) {
			return false;
		}

		// A cancel request left behind by a request that stopped before it
		// could carry it out. Cancelling needs no password, and there is no
		// driver to wait for: only a live holder of the lock takes precedence.
		if ( JobLock::cancelRequested( $jobs_dir, $job->id() ) ) {
			return ! JobLock::isLocked( $jobs_dir, $job->id() );
		}

		$background = (bool) $job->param( 'background' );

		// A pending browser job is still being started by its own request.
		// A background job has no browser: it may never have been ticked.
		if ( Job::STATUS_PENDING === $job->status() && ! $background ) {
			return false;
		}

		// The password only exists in the request that supplied it, unless
		// a provider can hand it over again (JobRunner::providePassword()).
		if ( $job->isEncrypted() && ! $job->param( 'password_source' ) ) {
			return false;
		}

		// A background job that asked to continue later (an upload backing
		// off before a retry): BackgroundRunner booked a WP-Cron event for
		// that moment, and ticking it earlier would only wait again.
		if ( $background && (int) $job->shared( 'resume_at', 0 ) > (int) $now ) {
			return false;
		}

		// Only pick up jobs their usual driver appears to have abandoned.
		$idle = $background ? self::WORKER_IDLE_BACKGROUND : self::WORKER_IDLE;
		if ( (int) $now - (int) $job->get( 'updated_at' ) < $idle ) {
			return false;
		}

		// A long slice that has not saved for a while is still being worked on.
		if ( JobLock::isLocked( $jobs_dir, $job->id() ) ) {
			return false;
		}

		/**
		 * Whether the WP-Cron worker may advance a job it found idle (a backup
		 * waits while a restore runs, for example).
		 *
		 * @param bool $may Whether it may.
		 * @param Job  $job The job.
		 */
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'shcm_worker_may_tick', true, $job ) : true;
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public function runCleanup() {
		$settings = $this->plugin->settings();
		$storage  = $this->plugin->storage();
		$runnable = $this->runnableJobs();

		$hours = $settings->getInt( 'cleanup_temp_hours', 24 );
		if ( $hours > 0 ) {
			// A job paced by WP-Cron can run for longer than the temp file
			// age limit; its file queue is written once and only read later,
			// so its age says nothing about whether it is still needed.
			$active = self::jobIds( $runnable );
			$this->purgeDirectory( $storage->tmp(), $hours * HOUR_IN_SECONDS, $active );
			$this->purgeDirectory( $storage->incoming(), $hours * HOUR_IN_SECONDS, $active );
		}

		$days = $settings->getInt( 'retention_days', 30 );
		if ( $days > 0 ) {
			$this->plugin->jobs()->purgeOlderThan( $days );
			$this->purgeDirectory( $storage->logs(), $days * DAY_IN_SECONDS );
			$this->purgeDirectory( $storage->rollback(), $days * DAY_IN_SECONDS );
		}

		// Lock files and cancel markers of jobs that were deleted
		// (JobStore::delete() leaves them behind) or purged just above.
		JobLock::purgeStale( $storage->jobs() );

		$max = $settings->getInt( 'max_archives', 0 );
		if ( $max > 0 ) {
			$this->pruneArchives( $max, $runnable );
		}

		$this->purgeOrphanedSidecars( $storage->archives() );
	}

	/**
	 * Keep at most a number of archives made by hand (the max_archives
	 * setting), deleting the oldest.
	 *
	 * Archives of scheduled backups have their own retention: they neither
	 * count towards the limit nor are deleted here. Neither does an archive
	 * a job still works with (an export being written, an import reading
	 * it, an upload sending it); it counts once the job is done.
	 *
	 * @param int   $max      Archives to keep.
	 * @param Job[] $runnable Jobs that may still run.
	 * @return int Archives deleted.
	 */
	protected function pruneArchives( $max, array $runnable ) {
		$backups = $this->backupArchiveNames();
		if ( null === $backups ) {
			// Which archives are backups is unknown: pruning now could delete
			// a backup its retention is meant to keep. Try again tomorrow.
			$this->plugin->logger()->warning( 'Old archives were not removed (max_archives): the backup history could not be read.' );
			return 0;
		}

		$skip    = array_flip( array_merge( $backups, self::archivesInUse( $runnable ) ) );
		$catalog = new \SHCM\Archive\Catalog( $this->plugin->storage() );
		$counted = 0;
		$removed = 0;
		foreach ( $catalog->all() as $archive ) {
			$name = (string) $archive['name'];
			// Also spared by name: a backup the history forgot (its file was
			// damaged and set aside) is still a backup, with its own limits.
			if ( isset( $skip[ $name ] ) || preg_match( '/-backup-\d{8}-\d{4}-[a-f0-9]+\.wpress$/', $name ) ) {
				continue;
			}
			++$counted;
			if ( $counted > $max && $catalog->delete( $name ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Base names of the archives the backup history lists.
	 *
	 * @return string[]|null Null when the history could not be read.
	 */
	protected function backupArchiveNames() {
		if ( ! class_exists( '\\SHCM\\Backup\\History' ) ) {
			return array();
		}
		try {
			$backups = $this->plugin->backups();
			if ( method_exists( $backups, 'store' ) ) {
				// A damaged history reads as "no backups": every backup would
				// then count as a manual archive here.
				$store = $backups->store();
				if ( ! $store->readable( \SHCM\Backup\History::DOCUMENT ) || $store->damaged( \SHCM\Backup\History::DOCUMENT ) ) {
					return null;
				}
			}
			$history = $backups->history();
			// Every archive the history knows, not only the ones retention
			// manages: an entry without a kind must not become prunable here.
			$names = method_exists( $history, 'recordedArchives' ) ? $history->recordedArchives() : $history->archiveNames();
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'Reading the backup history failed: ' . $e->getMessage() );
			return null;
		}
		if ( ! is_array( $names ) ) {
			return null;
		}
		return array_values( array_filter( array_map( 'strval', $names ), 'strlen' ) );
	}

	/**
	 * Base names of the archives the given jobs work with ("archive_path").
	 *
	 * @param Job[] $jobs Jobs.
	 * @return string[]
	 */
	public static function archivesInUse( array $jobs ) {
		$names = array();
		foreach ( $jobs as $job ) {
			if ( ! $job instanceof Job || ! $job->isRunnable() ) {
				continue;
			}
			$path = (string) $job->param( 'archive_path', '' );
			if ( '' !== $path ) {
				$names[] = basename( str_replace( '\\', '/', $path ) );
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Remove checksum files and checksum attempt markers whose archive is
	 * gone (deleted over FTP, say), so they are not taken for another
	 * archive's.
	 *
	 * @param string $directory Archive directory.
	 * @return int Files removed.
	 */
	protected function purgeOrphanedSidecars( $directory ) {
		$removed = 0;
		foreach ( array( '.sha256', '.sha256-attempt', '.md5-attempt' ) as $suffix ) {
			$items = glob( Paths::trailingslash( $directory ) . '*.' . \SHCM\Archive\Format::EXTENSION . $suffix );
			foreach ( is_array( $items ) ? $items : array() as $sidecar ) {
				$archive = substr( $sidecar, 0, -strlen( $suffix ) );
				if ( ! file_exists( $archive ) && @unlink( $sidecar ) ) {
					++$removed;
				}
			}
		}
		return $removed;
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
	 * Every job that may still run.
	 *
	 * @return Job[]
	 */
	protected function runnableJobs() {
		$jobs = array();
		foreach ( $this->plugin->jobs()->all( null, 1000 ) as $job ) {
			if ( $job->isRunnable() && '' !== (string) $job->id() ) {
				$jobs[] = $job;
			}
		}
		return $jobs;
	}

	/**
	 * Ids of the given jobs.
	 *
	 * @param Job[] $jobs Jobs.
	 * @return string[]
	 */
	protected static function jobIds( array $jobs ) {
		$ids = array();
		foreach ( $jobs as $job ) {
			$ids[] = (string) $job->id();
		}
		return $ids;
	}

	/**
	 * Whether a file name belongs to one of the given jobs ("<id>" or
	 * "<id>-...", the naming every stage uses for its working files).
	 *
	 * @param string   $name    File base name.
	 * @param string[] $job_ids Job ids.
	 * @return bool
	 */
	public static function belongsToJob( $name, array $job_ids ) {
		$name = (string) $name;
		foreach ( $job_ids as $job_id ) {
			$job_id = (string) $job_id;
			if ( '' === $job_id ) {
				continue;
			}
			if ( $name === $job_id || 0 === strpos( $name, $job_id . '-' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete files older than a given age.
	 *
	 * @param string   $directory Directory.
	 * @param int      $max_age   Maximum age in seconds.
	 * @param string[] $keep_jobs Ids of jobs whose files are kept whatever their age.
	 * @return int Files removed.
	 */
	protected function purgeDirectory( $directory, $max_age, array $keep_jobs = array() ) {
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
			if ( self::belongsToJob( $name, $keep_jobs ) ) {
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
