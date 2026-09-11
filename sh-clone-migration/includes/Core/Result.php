<?php
/**
 * Structured operation result.
 *
 * @package SHCM
 */

namespace SHCM\Core;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Every major operation returns one of these so callers never have to guess
 * whether a boolean false meant "nothing to do" or "it exploded".
 */
class Result {

	/**
	 * Success flag.
	 *
	 * @var bool
	 */
	protected $success;

	/**
	 * Machine readable stage identifier.
	 *
	 * @var string
	 */
	protected $stage;

	/**
	 * Human readable message.
	 *
	 * @var string
	 */
	protected $message;

	/**
	 * Extra payload.
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Technical detail, safe for logs.
	 *
	 * @var string
	 */
	protected $technical = '';

	/**
	 * Whether the caller can retry/resume.
	 *
	 * @var bool
	 */
	protected $recoverable = false;

	/**
	 * Suggested user action.
	 *
	 * @var string
	 */
	protected $suggestion = '';

	/**
	 * Constructor.
	 *
	 * @param bool   $success Success flag.
	 * @param string $stage   Stage identifier.
	 * @param string $message Message.
	 * @param array  $data    Payload.
	 */
	public function __construct( $success, $stage = '', $message = '', array $data = array() ) {
		$this->success = (bool) $success;
		$this->stage   = (string) $stage;
		$this->message = (string) $message;
		$this->data    = $data;
	}

	/**
	 * Build a success result.
	 *
	 * @param string $stage   Stage identifier.
	 * @param string $message Message.
	 * @param array  $data    Payload.
	 * @return self
	 */
	public static function ok( $stage = '', $message = '', array $data = array() ) {
		return new self( true, $stage, $message, $data );
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $stage       Stage identifier.
	 * @param string $message     Human readable message.
	 * @param string $technical   Technical detail.
	 * @param bool   $recoverable Whether a retry may succeed.
	 * @param string $suggestion  Suggested action.
	 * @return self
	 */
	public static function fail( $stage, $message, $technical = '', $recoverable = false, $suggestion = '' ) {
		$result              = new self( false, $stage, $message );
		$result->technical   = (string) $technical;
		$result->recoverable = (bool) $recoverable;
		$result->suggestion  = (string) $suggestion;
		return $result;
	}

	/**
	 * Success flag.
	 *
	 * @return bool
	 */
	public function isSuccess() {
		return $this->success;
	}

	/**
	 * Stage identifier.
	 *
	 * @return string
	 */
	public function stage() {
		return $this->stage;
	}

	/**
	 * Message.
	 *
	 * @return string
	 */
	public function message() {
		return $this->message;
	}

	/**
	 * Payload.
	 *
	 * @return array
	 */
	public function data() {
		return $this->data;
	}

	/**
	 * Technical detail.
	 *
	 * @return string
	 */
	public function technical() {
		return $this->technical;
	}

	/**
	 * Recoverability flag.
	 *
	 * @return bool
	 */
	public function isRecoverable() {
		return $this->recoverable;
	}

	/**
	 * Suggested action.
	 *
	 * @return string
	 */
	public function suggestion() {
		return $this->suggestion;
	}

	/**
	 * Array representation, ready for JSON transport.
	 *
	 * @return array
	 */
	public function toArray() {
		return array(
			'success'     => $this->success,
			'stage'       => $this->stage,
			'message'     => $this->message,
			'data'        => $this->data,
			'technical'   => $this->technical,
			'recoverable' => $this->recoverable,
			'suggestion'  => $this->suggestion,
		);
	}
}
