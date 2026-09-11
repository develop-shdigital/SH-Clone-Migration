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
	 * @param Job         $job    Job.
	 * @param Budget|null $budget Optional budget override.
	 * @return Job
	 */
	public function tick( Job $job, ?Budget $budget = null ) {
		if ( ! $job->isRunnable() ) {
			return $job;
		}

		$this->logger->channel( $job->id() );

		if ( null === $budget ) {
			$budget = Budget::create(
				$this->settings->getInt( 'time_budget' ),
				$this->settings->getInt( 'memory_guard', 80 )
			);
		}

		// A cancel request from another process writes straight to the job
		// file. Check it before the first save of this tick, or that save
		// would quietly overwrite the cancellation.
		if ( $this->isCancelled( $job ) ) {
			return $this->cancelJob( $job );
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

			// A cancel request from another process writes straight to the job
			// file, so check it before every step.
			if ( $this->isCancelled( $job ) ) {
				return $this->cancelJob( $job );
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

			$this->store->save( $job );

			if ( $budget->expired() ) {
				$job->set( 'status', Job::STATUS_PAUSED );
				$job->set( 'message', $job->get( 'message' ) );
				$this->store->save( $job );
				return $job;
			}
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
	 * Whether the job file was marked cancelled by another request.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	protected function isCancelled( Job $job ) {
		$stored = $this->store->load( $job->id() );
		return $stored && Job::STATUS_CANCELLED === $stored->status();
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
		$job->set( 'status', Job::STATUS_COMPLETED );
		$job->set( 'progress', 100.0 );
		$job->set( 'stage_progress', 1.0 );
		$job->set( 'finished_at', time() );
		$job->set( 'message', __( 'Migration completed.', 'sh-clone-migration' ) );
		$this->store->save( $job );
		$this->logger->info( sprintf( 'Job %s completed in %d seconds.', $job->id(), max( 0, time() - (int) $job->get( 'started_at' ) ) ) );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'shcm_job_completed', $job );
		}
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
		$this->logger->error( sprintf( 'Job %s failed in stage %s: %s', $job->id(), $job->stage(), $error->getMessage() ) );
		$this->logger->error( $error->getTraceAsString() );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'shcm_job_failed', $job, $error );
		}
		return $job;
	}

	/**
	 * Mark a job cancelled and clean up after it.
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	public function cancelJob( Job $job ) {
		$this->runCleanup( $job, null );
		$job->set( 'status', Job::STATUS_CANCELLED );
		$job->set( 'finished_at', time() );
		$job->set( 'message', __( 'Migration cancelled.', 'sh-clone-migration' ) );
		$this->store->save( $job );
		$this->logger->warning( sprintf( 'Job %s cancelled.', $job->id() ) );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'shcm_job_cancelled', $job );
		}
		return $job;
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
