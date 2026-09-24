<?php
/**
 * Shared stage behaviour.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

use SHCM\Core\Result;
use SHCM\Core\Settings;
use SHCM\Filesystem\Storage;
use SHCM\Logging\Logger;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Base class for job stages.
 */
abstract class AbstractStage implements StageInterface {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Storage  $storage  Storage.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger ) {
		$this->settings = $settings;
		$this->storage  = $storage;
		$this->logger   = $logger;
	}

	/**
	 * Default weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 10;
	}

	/**
	 * Default cleanup does nothing.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		unset( $job, $error );
	}

	/**
	 * Build a "stage finished" result.
	 *
	 * @param string $message Message.
	 * @param array  $data    Extra data.
	 * @return Result
	 */
	protected function complete( $message, array $data = array() ) {
		return Result::ok(
			$this->key(),
			$message,
			array_merge(
				array(
					'complete' => true,
					'progress' => 1.0,
				),
				$data
			)
		);
	}

	/**
	 * Build a "nothing to do until later" result: the runner ends the
	 * request instead of calling the stage again straight away.
	 *
	 * @param string $message  Message.
	 * @param float  $progress Progress within the stage, 0..1.
	 * @return Result
	 */
	protected function waiting( $message, $progress ) {
		return $this->progress( $message, $progress, array( 'yield' => true ) );
	}

	/**
	 * Build a "more work to do" result.
	 *
	 * @param string $message  Message.
	 * @param float  $progress Progress within the stage, 0..1.
	 * @param array  $data     Extra data.
	 * @return Result
	 */
	protected function progress( $message, $progress, array $data = array() ) {
		return Result::ok(
			$this->key(),
			$message,
			array_merge(
				array(
					'complete' => false,
					'progress' => max( 0.0, min( 1.0, (float) $progress ) ),
				),
				$data
			)
		);
	}
}
