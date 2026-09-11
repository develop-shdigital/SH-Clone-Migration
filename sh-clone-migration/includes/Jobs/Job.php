<?php
/**
 * Migration job model.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * A resumable unit of migration work.
 *
 * Jobs are persisted as JSON files rather than database rows: an import
 * replaces the entire database half way through its own run, so the job that
 * is driving it cannot live there.
 */
class Job {

	const TYPE_EXPORT = 'export';
	const TYPE_IMPORT = 'import';
	const TYPE_REPLACE = 'search_replace';

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Job data.
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Values that must never be written to disk (the migration password).
	 *
	 * @var array
	 */
	protected $runtime = array();

	/**
	 * Constructor.
	 *
	 * @param array $data Job data.
	 */
	public function __construct( array $data ) {
		$this->data = array_merge( self::defaults(), $data );
	}

	/**
	 * Default job shape.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'            => '',
			'type'          => self::TYPE_EXPORT,
			'status'        => self::STATUS_PENDING,
			'stage'         => 'initialize',
			'stage_index'   => 0,
			'stages'        => array(),
			'progress'      => 0.0,
			'message'       => '',
			'params'        => array(),
			'state'         => array(),
			'totals'        => array(),
			'error'         => null,
			'warnings'      => array(),
			'created_at'    => 0,
			'updated_at'    => 0,
			'started_at'    => 0,
			'finished_at'   => 0,
			'user_id'       => 0,
			'site_url'      => '',
			'ticks'         => 0,
			'stage_progress' => 0.0,
		);
	}

	/**
	 * Create a new job.
	 *
	 * @param string $type   Job type.
	 * @param array  $params Parameters.
	 * @param array  $stages Stage keys in order.
	 * @return self
	 */
	public static function create( $type, array $params, array $stages ) {
		$id = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
		return new self(
			array(
				'id'         => $id,
				'type'       => $type,
				'status'     => self::STATUS_PENDING,
				'stages'     => $stages,
				'stage'      => isset( $stages[0] ) ? $stages[0] : 'initialize',
				'params'     => $params,
				'created_at' => time(),
				'updated_at' => time(),
				'user_id'    => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'site_url'   => function_exists( 'home_url' ) ? home_url( '/' ) : '',
			)
		);
	}

	/**
	 * Magic-free accessor.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * Setter.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
		return $this;
	}

	/**
	 * Job id.
	 *
	 * @return string
	 */
	public function id() {
		return $this->data['id'];
	}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function type() {
		return $this->data['type'];
	}

	/**
	 * Job status.
	 *
	 * @return string
	 */
	public function status() {
		return $this->data['status'];
	}

	/**
	 * Current stage key.
	 *
	 * @return string
	 */
	public function stage() {
		return $this->data['stage'];
	}

	/**
	 * Parameter accessor.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function param( $key, $default = null ) {
		return isset( $this->data['params'][ $key ] ) ? $this->data['params'][ $key ] : $default;
	}

	/**
	 * Set a parameter.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function setParam( $key, $value ) {
		$this->data['params'][ $key ] = $value;
		return $this;
	}

	/**
	 * Per-stage state accessor.
	 *
	 * @param string $stage   Stage key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function stageState( $stage, $default = array() ) {
		return isset( $this->data['state'][ $stage ] ) ? $this->data['state'][ $stage ] : $default;
	}

	/**
	 * Persist per-stage state.
	 *
	 * @param string $stage Stage key.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function setStageState( $stage, $value ) {
		$this->data['state'][ $stage ] = $value;
		return $this;
	}

	/**
	 * Shared state accessor (values that outlive a single stage).
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function shared( $key, $default = null ) {
		return isset( $this->data['state']['shared'][ $key ] ) ? $this->data['state']['shared'][ $key ] : $default;
	}

	/**
	 * Set shared state.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function setShared( $key, $value ) {
		if ( ! isset( $this->data['state']['shared'] ) ) {
			$this->data['state']['shared'] = array();
		}
		$this->data['state']['shared'][ $key ] = $value;
		return $this;
	}

	/**
	 * Record a non fatal warning.
	 *
	 * @param string $message Message.
	 * @return self
	 */
	public function addWarning( $message ) {
		$this->data['warnings'][] = array(
			'time'    => time(),
			'message' => (string) $message,
		);
		// Keep the list bounded: a migration with 50k unreadable files should
		// not produce a 50k entry job file.
		if ( count( $this->data['warnings'] ) > 500 ) {
			$this->data['warnings'] = array_slice( $this->data['warnings'], -500 );
		}
		return $this;
	}

	/**
	 * Whether the job reached a terminal state.
	 *
	 * @return bool
	 */
	public function isFinished() {
		return in_array(
			$this->data['status'],
			array( self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ),
			true
		);
	}

	/**
	 * Whether the job may still be advanced.
	 *
	 * @return bool
	 */
	public function isRunnable() {
		return in_array(
			$this->data['status'],
			array( self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED ),
			true
		);
	}

	/**
	 * Store a value for the lifetime of this request only.
	 *
	 * The migration password lives here: it is needed to continue writing an
	 * encrypted archive, and it is never persisted with the job.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function setRuntime( $key, $value ) {
		$this->runtime[ $key ] = $value;
		return $this;
	}

	/**
	 * Read a request scoped value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function runtime( $key, $default = null ) {
		return array_key_exists( $key, $this->runtime ) ? $this->runtime[ $key ] : $default;
	}

	/**
	 * Whether the job works on an encrypted archive.
	 *
	 * @return bool
	 */
	public function isEncrypted() {
		return (bool) $this->param( 'encrypted', false );
	}

	/**
	 * The migration password for this request.
	 *
	 * @return string
	 */
	public function password() {
		return (string) $this->runtime( 'password', '' );
	}

	/**
	 * Array representation.
	 *
	 * @return array
	 */
	public function toArray() {
		return $this->data;
	}

	/**
	 * A trimmed representation for the admin UI, without the bulky internals.
	 *
	 * @return array
	 */
	public function toPublicArray() {
		$data = $this->data;
		unset( $data['state'] );
		$data['warnings'] = array_slice( $this->data['warnings'], -25 );
		return $data;
	}
}
