<?php
/**
 * Shared job driving for WP-CLI commands.
 *
 * @package SHCM
 */

namespace SHCM\CLI;

use SHCM\Jobs\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a job to the end from the command line.
 *
 * A trait rather than a base class: WP-CLI turns every public method of a
 * command class into a subcommand, so the helpers must not be inherited as
 * public API. Classes using it provide $plugin and $controller.
 */
trait DrivesJobs {

	/**
	 * Drive a job until it finishes.
	 *
	 * @param string $job_id   Job id.
	 * @param string $password Migration password.
	 * @param bool   $progress Print progress lines.
	 * @return array Final job snapshot.
	 */
	protected function drive( $job_id, $password, $progress = true ) {
		$store  = $this->plugin->jobs();
		$runner = $this->plugin->runner();
		$last   = '';

		while ( true ) {
			$job = $store->load( $job_id );
			if ( null === $job ) {
				\WP_CLI::error( 'That migration job no longer exists.' );
			}
			if ( $job->isFinished() ) {
				return $this->controller->snapshot( $job );
			}

			// The job asked to continue later (an upload backing off after a
			// rate limit, say): wait here instead of ticking in a tight loop.
			$resume_at = (int) $job->shared( 'resume_at', 0 );
			if ( $resume_at > time() ) {
				$this->progressLine( $job, $progress, $last );
				sleep( (int) min( 60, max( 1, $resume_at - time() ) ) );
				continue;
			}

			$job->setRuntime( 'password', $password );
			$job = $runner->tick( $job, $this->budget() );

			$this->progressLine( $job, $progress, $last );

			if ( $job->isFinished() ) {
				return $this->controller->snapshot( $job );
			}
			if ( $job->runtime( 'busy' ) ) {
				// Another request (a browser tab, the background runner) holds
				// the job right now.
				sleep( 2 );
			}
		}
	}

	/**
	 * Print a progress line when it changed.
	 *
	 * @param \SHCM\Jobs\Job $job      Job.
	 * @param bool           $progress Print progress lines.
	 * @param string         $last     Last line printed (by reference).
	 * @return void
	 */
	protected function progressLine( $job, $progress, &$last ) {
		$line = sprintf( '[%5.1f%%] %s', (float) $job->get( 'progress' ), $job->get( 'message' ) );
		if ( $progress && $line !== $last ) {
			// log(), not line(): WP-CLI's global --quiet silences it.
			\WP_CLI::log( $line );
			$last = $line;
		}
	}

	/**
	 * Run a command body, turning any exception into a clean CLI error.
	 *
	 * A stack trace is the wrong answer to "the password is wrong".
	 *
	 * @param callable $callback Body.
	 * @return mixed
	 */
	protected function guard( callable $callback ) {
		try {
			return call_user_func( $callback );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Budget for one CLI tick.
	 *
	 * The command line has no request timeout, so the default slice is long;
	 * a configured time budget still wins, which keeps CLI and browser runs
	 * behaving identically when a host needs short slices.
	 *
	 * @return Budget
	 */
	protected function budget() {
		$configured = $this->plugin->settings()->getInt( 'time_budget' );
		return Budget::create( $configured > 0 ? $configured : 60, $this->plugin->settings()->getInt( 'memory_guard', 80 ) );
	}
}
