<?php
/**
 * Job stage contract.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

use SHCM\Core\Result;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * One resumable step of a migration job.
 */
interface StageInterface {

	/**
	 * Stable stage key, stored in the job state.
	 *
	 * @return string
	 */
	public function key();

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Relative weight used to compute overall progress.
	 *
	 * @return int
	 */
	public function weight();

	/**
	 * Do as much work as the budget allows.
	 *
	 * The returned Result carries `complete` (bool) and `progress` (0..1) in
	 * its data. A stage must be safe to call again after any return.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Time and memory budget.
	 * @return Result
	 */
	public function run( Job $job, Budget $budget );

	/**
	 * Clean up after a failure or cancellation.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error, when the job failed.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null );
}
