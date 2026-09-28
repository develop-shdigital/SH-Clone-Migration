<?php
/**
 * Job execution loop.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

use SHCM\Core\Settings;
use SHCM\Logging\Logger;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Advances a job as far as the current request's budget allows.
 *
 * The runner is the only place that mutates job status, so resume, cancel and
 * failure handling all behave identically no matter whether the tick came from
 * AJAX, WP-Cron or WP-CLI.
 */
class JobRunner {

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	protected $store;

	/**
	 * Stage resolver.
	 *
	 * @var StageResolver
	 */
	protected $resolver;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Per-job lock (created on first use).
	 *
	 * @var JobLock|null
	 */
	protected $lock = null;

	/**
	 * Constructor.
	 *
	 * @param JobStore      $store    Job store.
	 * @param StageResolver $resolver Stage resolver.
	 * @param Logger        $logger   Logger.
	 * @param Settings      $settings Settings.
	 */
	public function __construct( JobStore $store, StageResolver $resolver, Logger $logger, Settings $settings ) {
		$this->store    = $store;
		$this->resolver = $resolver;
		$this->logger   = $logger;
		$this->settings = $settings;
	}

	/**
	 * Advance a job.
	 *
	 * Only one request advances a job at a time. When another request holds
	 * the job's lock, nothing is changed: the job is returned as currently
	 * saved, with the runtime flag "busy" set.
	 *
	 * @param Job         $job    Job.
	 * @param Budget|null $budget Optional budget override.
	 * @return Job
	 */
	public function tick( Job $job, ?Budget $budget = null ) {
		if ( ! $job->isRunnable() ) {
			return $job;
		}

		$lock   = $this->lock();
		$job_id = $job->id();
		// Held by this very runner means a hook fired from inside a tick is
		// ticking the same job again: that is as busy as another request.
		if ( $lock->isHeld( $job_id ) || ! $lock->acquire( $job_id ) ) {
			return $this->busy( $job );
		}

		try {
			$job->setRuntime( 'busy', false );
			$this->refresh( $job );
			// Finished since the caller loaded it. A cancelled job was cleaned
			// up and announced by whoever cancelled it, so there is nothing
			// left to do (doing it again would announce it twice).
			if ( ! $job->isRunnable() ) {
				return $job;
			}
			$job = $this->advance( $job, $budget );
		} finally {
			$lock->release( $job_id );
		}

		// A cancel requested after this tick's last check, while it still
		// held the lock, would otherwise wait for the next tick. The canceller
		// tried the lock after writing its request and found it held, so the
		// request is visible here.
		if ( $job->isRunnable() && JobLock::cancelRequested( $this->jobsDirectory(), $job_id ) && $lock->acquire( $job_id ) ) {
			try {
				$job = $this->cancelLocked( $job, new \RuntimeException( 'Cancelled.' ) );
			} finally {
				$lock->release( $job_id );
			}
		}
		return $job;
	}

	/**
	 * The job as currently saved, flagged busy (another request holds it).
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	protected function busy( Job $job ) {
		$current = $this->store->load( $job->id() );
		if ( null === $current ) {
			$current = $job;
		}
		$current->setRuntime( 'busy', true );
		return $current;
	}

	/**
	 * Continue from the saved state, which is authoritative once the lock is
	 * held.
	 *
	 * The caller's copy may be older than it looks: one loaded while another
	 * request was half way through a tick has the same "ticks" count as the
	 * saved state, yet misses every step saved since. Going on from it would
	 * redo finished work, rewind an export's archive, or run a job that has
	 * completed meanwhile a second time. Every caller loads or saves the job
	 * right before ticking it, so nothing is lost by adopting the saved copy.
	 * The caller's object is updated in place so its runtime values (the
	 * password) stay with it.
	 *
	 * @param Job $job Job (updated in place).
	 * @return void
	 */
	protected function refresh( Job $job ) {
		$stored = $this->store->load( $job->id() );
		if ( null !== $stored ) {
			$this->adopt( $job, $stored );
		}
	}

	/**
	 * Copy the saved data of a job into another object for the same job.
	 *
	 * @param Job $job    Job (updated in place, runtime values kept).
	 * @param Job $source Saved copy.
	 * @return void
	 */
	protected function adopt( Job $job, Job $source ) {
		foreach ( $source->toArray() as $key => $value ) {
			$job->set( $key, $value );
		}
	}

	/**
	 * The password for an encrypted job that runs without a browser.
	 *
	 * Scheduled encrypted backups keep their password sealed in the backup
	 * configuration; the backup module hands it over through this filter.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function providePassword( Job $job ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return '';
		}
		return (string) apply_filters( 'shcm_job_password', '', $job );
	}

	/**
	 * The body of tick(), run while holding the job's lock.
	 *
	 * @param Job         $job    Job.
	 * @param Budget|null $budget Optional budget override.
	 * @return Job
	 */
	protected function advance( Job $job, ?Budget $budget = null ) {
		$this->logger->channel( $job->id() );

		// Every warning goes to the job log the moment it is raised. The job
		// file keeps only the newest 500, so a step that skips thousands of
		// files still leaves each name in the downloadable log.
		$logger = $this->logger;
		$job_id = $job->id();
		$job->onWarning(
			function ( $message ) use ( $logger, $job_id ) {
				$logger->channel( $job_id )->warning( $message );
			}
		);

		// A cancel requested while nobody held the job (its last holder died,
		// say) is carried out first. It needs neither the password nor a
		// budget, which is also why the worker picks such a job up at once.
		if ( $this->isCancelled( $job ) ) {
			return $this->stopCancelled( $job );
		}

		if ( $job->param( 'encrypted' ) && '' === (string) $job->password() && $job->param( 'password_source' ) ) {
			$job->setRuntime( 'password', $this->providePassword( $job ) );
		}

		if ( null === $budget ) {
			$budget = Budget::create(
				$this->settings->getInt( 'time_budget' ),
				$this->settings->getInt( 'memory_guard', 80 )
			);
		}

		if ( Job::STATUS_PENDING === $job->status() ) {
			$job->set( 'started_at', time() );
			$this->logger->info( sprintf( 'Job %s started (%s).', $job->id(), $job->type() ) );
		}
		$job->set( 'status', Job::STATUS_RUNNING );
		$job->set( 'ticks', (int) $job->get( 'ticks' ) + 1 );
		$this->store->save( $job );

		$guard = 0;
		while ( true ) {
			++$guard;
			if ( $guard > 10000 ) {
				return $this->failJob( $job, new \RuntimeException( 'Stage loop exceeded its iteration guard.' ) );
			}

			// Another request may have asked for a cancel: check before every
			// step.
			if ( $this->isCancelled( $job ) ) {
				return $this->stopCancelled( $job );
			}

			$stage_key = $job->stage();
			$stage     = $this->resolver->resolve( $job->type(), $stage_key );
			if ( null === $stage ) {
				return $this->failJob( $job, new \RuntimeException( sprintf( 'Unknown migration stage "%s".', $stage_key ) ) );
			}

			try {
				$result = $stage->run( $job, $budget );
			} catch ( \Throwable $e ) {
				$this->runCleanup( $job, $e );
				return $this->failJob( $job, $e );
			}

			if ( ! $result->isSuccess() ) {
				$error = new \RuntimeException( $result->message() );
				$this->runCleanup( $job, $error );
				return $this->failJob( $job, $error, $result->toArray() );
			}

			$data = $result->data();
			$job->set( 'message', $result->message() );
			$job->set( 'stage_progress', isset( $data['progress'] ) ? (float) $data['progress'] : 0.0 );
			$job->set( 'progress', $this->overallProgress( $job ) );

			if ( ! empty( $data['complete'] ) ) {
				$this->logger->info( sprintf( 'Stage %s completed.', $stage_key ) );
				$next = $this->nextStage( $job );
				if ( null === $next ) {
					return $this->completeJob( $job );
				}
				$job->set( 'stage', $next );
				$job->set( 'stage_index', (int) $job->get( 'stage_index' ) + 1 );
				$job->set( 'stage_progress', 0.0 );
			}

			// The stage may have run for the whole budget; a cancel requested
			// meanwhile is carried out instead of saving another step.
			if ( $this->isCancelled( $job ) ) {
				return $this->stopCancelled( $job );
			}

			// A stage that is only waiting (a retry backoff) asks to end the
			// request rather than be called again at once.
			$pause = $budget->expired() || ! empty( $data['yield'] );
			if ( $pause ) {
				$job->set( 'status', Job::STATUS_PAUSED );
			}
			$this->store->save( $job );
			if ( $pause ) {
				return $job;
			}
		}
	}

	/**
	 * End a tick whose job another request asked to cancel.
	 *
	 * The cancelling request could not run the cleanups while this one was
	 * working on the job, so they run here.
	 *
	 * @param Job $job Job (the ticking request's copy).
	 * @return Job
	 */
	protected function stopCancelled( Job $job ) {
		$error  = new \RuntimeException( 'Cancelled.' );
		$stored = $this->store->load( $job->id() );
		if ( null !== $stored && Job::STATUS_CANCELLED === $stored->status() ) {
			// Already cancelled, cleaned up and announced by another request
			// (possible only where flock() does not work). Undo what this
			// request's step may have set up since, but neither overwrite that
			// record nor announce the cancellation a second time.
			$this->runCleanup( $job, $error );
			$this->adopt( $job, $stored );
			$this->clearCancelRequest( $job->id() );
			return $job;
		}
		return $this->markCancelled( $job, $error );
	}

	/**
	 * Final status message: a backup (or an upload to Google Drive) says so
	 * instead of calling itself a migration.
	 *
	 * @param Job    $job    Job.
	 * @param string $status Job::STATUS_COMPLETED or Job::STATUS_CANCELLED.
	 * @return string
	 */
	protected function outcomeMessage( Job $job, $status ) {
		$backup    = $job->param( 'backup' );
		$completed = Job::STATUS_COMPLETED === $status;
		if ( is_array( $backup ) && ! empty( $backup['kind'] ) ) {
			if ( $job->param( 'upload_only' ) ) {
				return $completed ? __( 'Upload to Google Drive completed.', 'sh-clone-migration' ) : __( 'Upload to Google Drive cancelled.', 'sh-clone-migration' );
			}
			return $completed ? __( 'Backup completed.', 'sh-clone-migration' ) : __( 'Backup cancelled.', 'sh-clone-migration' );
		}
		return $completed ? __( 'Migration completed.', 'sh-clone-migration' ) : __( 'Migration cancelled.', 'sh-clone-migration' );
	}

	/**
	 * Run the cleanups, mark a job cancelled, save it and announce it.
	 *
	 * Only a request holding the job's lock gets here, so the cancellation is
	 * announced exactly once.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error passed to the stage cleanups.
	 * @return Job
	 */
	protected function markCancelled( Job $job, $error = null ) {
		$this->runCleanup( $job, $error );
		$job->set( 'status', Job::STATUS_CANCELLED );
		$job->set( 'finished_at', time() );
		$job->set( 'message', $this->outcomeMessage( $job, Job::STATUS_CANCELLED ) );
		if ( ! $this->store->save( $job ) ) {
			// Nothing was recorded (a full disk, say): keep the request, so
			// the next tick finishes the cancel and announces it then, once.
			$this->logger->error( sprintf( 'Job %s: the cancelled state could not be saved; the cancel stays pending.', $job->id() ) );
			return $job;
		}
		// Removed only once the cancelled status is saved: a request dying
		// in between must leave the cancel pending, not lose it.
		$this->clearCancelRequest( $job->id() );
		$this->logger->warning( sprintf( 'Job %s cancelled.', $job->id() ) );
		$this->announce( 'shcm_job_cancelled', $job );
		return $job;
	}

	/**
	 * Fire a job lifecycle action (a seam for the unit tests, which run
	 * without WordPress hooks).
	 *
	 * @param string $hook    Action name.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	protected function announce( $hook, ...$args ) {
		if ( function_exists( 'do_action' ) ) {
			do_action( $hook, ...$args );
		}
	}

	/**
	 * Run the current stage's cleanup handler, swallowing secondary errors.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	protected function runCleanup( Job $job, $error = null ) {
		try {
			$stage = $this->resolver->resolve( $job->type(), $job->stage() );
			if ( $stage ) {
				$stage->cleanup( $job, $error );
			}
			// Job level cleanup: every stage of this job type gets a chance to
			// undo anything global it switched on (maintenance mode, ...).
			foreach ( (array) $job->get( 'stages' ) as $key ) {
				if ( $key === $job->stage() ) {
					continue;
				}
				$other = $this->resolver->resolve( $job->type(), $key );
				if ( $other ) {
					$other->cleanup( $job, $error );
				}
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Cleanup handler failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether another request asked to cancel the job (its cancel marker), or
	 * the saved job already says cancelled.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	protected function isCancelled( Job $job ) {
		if ( JobLock::cancelRequested( $this->jobsDirectory(), $job->id() ) ) {
			return true;
		}
		$stored = $this->store->load( $job->id() );
		return $stored && Job::STATUS_CANCELLED === $stored->status();
	}

	/**
	 * Whether a cancel was requested for a job that has not stopped yet.
	 *
	 * The request advancing the job carries the cancel out after its current
	 * step; until then the job keeps its status, and the admin screens show
	 * "Cancelling…".
	 *
	 * @param Job|string $job Job, or job id (then the saved job is checked).
	 * @return bool
	 */
	public function cancelRequested( $job ) {
		$job_id = $job instanceof Job ? (string) $job->id() : (string) $job;
		if ( $job instanceof Job && $job->isFinished() ) {
			return false;
		}
		if ( ! JobLock::cancelRequested( $this->jobsDirectory(), $job_id ) ) {
			return false;
		}
		if ( $job instanceof Job ) {
			return true;
		}
		$stored = $this->store->load( $job_id );
		return null !== $stored && ! $stored->isFinished();
	}

	/**
	 * Remove a job's cancel marker.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	protected function clearCancelRequest( $job_id ) {
		JobLock::clearCancel( $this->jobsDirectory(), (string) $job_id );
	}

	/**
	 * The next stage key, or null when the job is done.
	 *
	 * @param Job $job Job.
	 * @return string|null
	 */
	protected function nextStage( Job $job ) {
		$stages = (array) $job->get( 'stages' );
		$index  = array_search( $job->stage(), $stages, true );
		if ( false === $index ) {
			return null;
		}
		return isset( $stages[ $index + 1 ] ) ? $stages[ $index + 1 ] : null;
	}

	/**
	 * Weighted overall progress in percent.
	 *
	 * @param Job $job Job.
	 * @return float
	 */
	public function overallProgress( Job $job ) {
		$stages = (array) $job->get( 'stages' );
		$total  = 0;
		$done   = 0;
		$found  = false;

		foreach ( $stages as $key ) {
			$stage  = $this->resolver->resolve( $job->type(), $key );
			$weight = $stage ? $stage->weight() : 10;
			$total += $weight;
			if ( $key === $job->stage() ) {
				$done += $weight * (float) $job->get( 'stage_progress' );
				$found = true;
			} elseif ( ! $found ) {
				$done += $weight;
			}
		}

		if ( $total <= 0 ) {
			return 0.0;
		}
		return round( min( 100.0, ( $done / $total ) * 100 ), 2 );
	}

	/**
	 * Mark a job completed.
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	protected function completeJob( Job $job ) {
		// Cancelled while the last stage ran: the cancellation wins, so the
		// job never reports (and announces) both outcomes.
		if ( $this->isCancelled( $job ) ) {
			return $this->stopCancelled( $job );
		}
		$job->set( 'status', Job::STATUS_COMPLETED );
		$job->set( 'progress', 100.0 );
		$job->set( 'stage_progress', 1.0 );
		$job->set( 'finished_at', time() );
		$job->set( 'message', $this->outcomeMessage( $job, Job::STATUS_COMPLETED ) );
		$this->store->save( $job );
		// A cancel requested after the check above came too late.
		$this->clearCancelRequest( $job->id() );
		$this->logger->info( sprintf( 'Job %s completed in %d seconds.', $job->id(), max( 0, time() - (int) $job->get( 'started_at' ) ) ) );
		$this->announce( 'shcm_job_completed', $job );
		return $job;
	}

	/**
	 * Mark a job failed.
	 *
	 * @param Job        $job     Job.
	 * @param \Throwable $error   Error.
	 * @param array      $details Extra details.
	 * @return Job
	 */
	protected function failJob( Job $job, $error, array $details = array() ) {
		// A stage often fails *because* it was cancelled under it (the backup
		// module removes a cancelled backup's partial archive). The user asked
		// for the cancellation; a "backup failed" notice would be noise.
		if ( $this->isCancelled( $job ) ) {
			$this->logger->warning( sprintf( 'Job %s stopped with an error after it was cancelled: %s', $job->id(), $error->getMessage() ) );
			return $this->stopCancelled( $job );
		}
		$job->set( 'status', Job::STATUS_FAILED );
		$job->set( 'finished_at', time() );
		$job->set(
			'error',
			array(
				'stage'       => $job->stage(),
				'message'     => $error->getMessage(),
				'technical'   => sprintf( '%s in %s:%d', get_class( $error ), $error->getFile(), $error->getLine() ),
				'recoverable' => isset( $details['recoverable'] ) ? (bool) $details['recoverable'] : true,
				'suggestion'  => isset( $details['suggestion'] ) ? $details['suggestion'] : __( 'Review the migration log, resolve the problem and resume or restart the job.', 'sh-clone-migration' ),
				'time'        => time(),
			)
		);
		$job->set( 'message', $error->getMessage() );
		$this->store->save( $job );
		// A cancel requested after the check above came too late.
		$this->clearCancelRequest( $job->id() );
		$this->logger->error( sprintf( 'Job %s failed in stage %s: %s', $job->id(), $job->stage(), $error->getMessage() ) );
		$this->logger->error( $error->getTraceAsString() );
		$this->announce( 'shcm_job_failed', $job, $error );
		return $job;
	}

	/**
	 * Mark a job cancelled and clean up after it.
	 *
	 * A finished job is returned unchanged: cancelling a completed export
	 * must not relabel it or run its cleanups. When another request is
	 * advancing the job, only a cancel request is recorded (see
	 * requestCancel()) and the job is returned as saved; that request carries
	 * the cancel out after its current step, because undoing work that is
	 * still in progress would race with it. cancelRequested() tells the
	 * caller that a cancel is pending.
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	public function cancelJob( Job $job ) {
		if ( $job->isFinished() ) {
			return $job;
		}

		$job_id = $job->id();
		$lock   = $this->lock();
		if ( $lock->isHeld( $job_id ) ) {
			// Called from inside this runner's own tick (a stage, a hook): the
			// tick carries the cancel out once the current step returns.
			$this->requestCancel( $job );
			return $job;
		}
		if ( ! $lock->acquire( $job_id ) ) {
			$current = $this->requestCancel( $job );
			// The holder may have let go since the first try. Then it may not
			// have seen the request, so carry the cancel out here; if it still
			// holds the lock it checks for the request after releasing it.
			if ( $current->isFinished() || ! $lock->acquire( $job_id ) ) {
				return $current;
			}
		}

		try {
			return $this->cancelLocked( $job, null );
		} finally {
			$lock->release( $job_id );
		}
	}

	/**
	 * Cancel a job whose lock this runner holds, from its newest saved state.
	 *
	 * A tick may have finished the job since the caller loaded it; the
	 * finished job is then returned as saved and a pending cancel request is
	 * dropped. Otherwise the saved state is copied into the caller's object,
	 * keeping its runtime values, and the job is cancelled.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error passed to the stage cleanups.
	 * @return Job
	 */
	protected function cancelLocked( Job $job, $error = null ) {
		$stored = $this->store->load( $job->id() );
		if ( null !== $stored ) {
			if ( $stored->isFinished() ) {
				$this->clearCancelRequest( $job->id() );
				return $stored;
			}
			$this->adopt( $job, $stored );
		}
		return $this->markCancelled( $job, $error );
	}

	/**
	 * Ask the request advancing a job to cancel it.
	 *
	 * Writes the cancel marker and nothing else: the job file belongs to the
	 * lock holder, which rewrites it after every step, and the cancellation
	 * is announced by whoever carries it out, once.
	 *
	 * @param Job $job Job.
	 * @return Job The job as saved (unchanged).
	 */
	protected function requestCancel( Job $job ) {
		$job_id  = $job->id();
		$current = $this->store->load( $job_id );
		if ( null === $current ) {
			$current = $job;
		}
		if ( $current->isFinished() ) {
			return $current;
		}
		if ( ! JobLock::requestCancel( $this->jobsDirectory(), $job_id ) ) {
			$this->logger->error( sprintf( 'Job %s could not be cancelled: the cancel request could not be written to the jobs directory.', $job_id ) );
			return $current;
		}

		// Finished while the request was written: the holder's last check may
		// have missed it, and a marker must not outlive its job.
		$after = $this->store->load( $job_id );
		if ( null !== $after && $after->isFinished() ) {
			$this->clearCancelRequest( $job_id );
			return $after;
		}
		$this->logger->warning( sprintf( 'Job %s: cancel requested; the request advancing it stops and cleans up.', $job_id ) );
		return $current;
	}

	/**
	 * Directory holding the job files (and their lock files).
	 *
	 * @return string
	 */
	public function jobsDirectory() {
		// JobStore::path() is the one place that knows the layout.
		return dirname( $this->store->path( 'lock' ) );
	}

	/**
	 * The lock guarding jobs against concurrent ticks.
	 *
	 * @return JobLock
	 */
	public function lock() {
		if ( null === $this->lock ) {
			$this->lock = new JobLock( $this->jobsDirectory() );
		}
		return $this->lock;
	}

	/**
	 * Job store.
	 *
	 * @return JobStore
	 */
	public function store() {
		return $this->store;
	}

	/**
	 * Stage resolver.
	 *
	 * @return StageResolver
	 */
	public function resolver() {
		return $this->resolver;
	}
}
