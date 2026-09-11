<?php
/**
 * Per-request time and memory budget.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Tells a stage when to stop working and hand control back so the job can be
 * resumed by the next request.
 *
 * Every long running loop in the engine consults this instead of assuming it
 * owns the request.
 */
class Budget {

	/**
	 * Start timestamp with microseconds.
	 *
	 * @var float
	 */
	protected $start;

	/**
	 * Seconds this request may use.
	 *
	 * @var float
	 */
	protected $seconds;

	/**
	 * Memory ceiling in bytes, 0 when unlimited.
	 *
	 * @var int
	 */
	protected $memory_ceiling;

	/**
	 * Constructor.
	 *
	 * @param float $seconds        Time budget.
	 * @param int   $memory_ceiling Memory ceiling in bytes.
	 */
	public function __construct( $seconds, $memory_ceiling ) {
		$this->start          = microtime( true );
		$this->seconds        = (float) $seconds;
		$this->memory_ceiling = (int) $memory_ceiling;
	}

	/**
	 * Build a budget from the configured settings and the PHP environment.
	 *
	 * @param int $configured_seconds Configured budget, 0 for auto.
	 * @param int $memory_guard       Percentage of the memory limit to allow.
	 * @return self
	 */
	public static function create( $configured_seconds = 0, $memory_guard = 80 ) {
		$max_execution = (int) ini_get( 'max_execution_time' );

		if ( $configured_seconds > 0 ) {
			$seconds = $configured_seconds;
		} elseif ( 0 === $max_execution ) {
			// No limit reported (CLI, or a host that removed it): still hand
			// control back regularly so progress is visible and resumable.
			$seconds = 25.0;
		} else {
			$seconds = max( 5.0, $max_execution * 0.6 );
		}

		// Try to lift the execution limit; if the host forbids it the budget
		// above already keeps us inside the original window.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}

		$limit   = Bytes::parseIni( ini_get( 'memory_limit' ) );
		$ceiling = $limit > 0 ? (int) ( $limit * ( max( 40, min( 95, $memory_guard ) ) / 100 ) ) : 0;

		return new self( $seconds, $ceiling );
	}

	/**
	 * Seconds elapsed.
	 *
	 * @return float
	 */
	public function elapsed() {
		return microtime( true ) - $this->start;
	}

	/**
	 * Seconds left.
	 *
	 * @return float
	 */
	public function remaining() {
		return max( 0.0, $this->seconds - $this->elapsed() );
	}

	/**
	 * Whether the request should stop working.
	 *
	 * @return bool
	 */
	public function expired() {
		if ( $this->elapsed() >= $this->seconds ) {
			return true;
		}
		if ( $this->memory_ceiling > 0 && memory_get_usage( true ) >= $this->memory_ceiling ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether enough time is left to start another unit of work.
	 *
	 * @param float $estimated_seconds Estimated cost of the next unit.
	 * @return bool
	 */
	public function allows( $estimated_seconds = 0.5 ) {
		return $this->remaining() > $estimated_seconds && ! $this->expired();
	}

	/**
	 * Whether a loop should run another iteration.
	 *
	 * The first unit of work in a request always goes ahead, even if the
	 * budget was already spent when the stage was entered. Without that a
	 * host with a very small budget could hand control back and forth forever
	 * without the migration ever moving.
	 *
	 * @param int $completed Units of work completed in this call.
	 * @return bool
	 */
	public function shouldContinue( $completed ) {
		if ( $completed < 1 ) {
			return true;
		}
		return ! $this->expired();
	}

	/**
	 * Total budget in seconds.
	 *
	 * @return float
	 */
	public function total() {
		return $this->seconds;
	}
}
