<?php
/**
 * Migration logger.
 *
 * @package SHCM
 */

namespace SHCM\Logging;

use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Append-only per-job log files.
 *
 * Deliberately not error_log(): migration logs need to survive a database
 * replacement, be downloadable per job, and be scrubbed of secrets.
 */
class Logger {

	const LEVELS = array(
		'debug'   => 10,
		'info'    => 20,
		'warning' => 30,
		'error'   => 40,
	);

	/**
	 * Storage helper.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	protected $redactor;

	/**
	 * Minimum level that gets written.
	 *
	 * @var int
	 */
	protected $threshold;

	/**
	 * Current channel (job id or "plugin").
	 *
	 * @var string
	 */
	protected $channel = 'plugin';

	/**
	 * Open file handles per channel.
	 *
	 * @var array<string,resource>
	 */
	protected $handles = array();

	/**
	 * Constructor.
	 *
	 * @param Storage       $storage  Storage helper.
	 * @param string        $level    Minimum level.
	 * @param Redactor|null $redactor Redactor.
	 */
	public function __construct( Storage $storage, $level = 'info', ?Redactor $redactor = null ) {
		$this->storage   = $storage;
		$this->redactor  = $redactor ? $redactor : new Redactor();
		$this->threshold = isset( self::LEVELS[ $level ] ) ? self::LEVELS[ $level ] : self::LEVELS['info'];
	}

	/**
	 * Switch the active channel.
	 *
	 * @param string $channel Channel name (usually a job id).
	 * @return self
	 */
	public function channel( $channel ) {
		$this->channel = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $channel );
		if ( '' === $this->channel ) {
			$this->channel = 'plugin';
		}
		return $this;
	}

	/**
	 * Active channel.
	 *
	 * @return string
	 */
	public function currentChannel() {
		return $this->channel;
	}

	/**
	 * Redactor instance.
	 *
	 * @return Redactor
	 */
	public function redactor() {
		return $this->redactor;
	}

	/**
	 * Log file path for a channel.
	 *
	 * @param string|null $channel Channel.
	 * @return string
	 */
	public function path( $channel = null ) {
		$channel = null === $channel ? $this->channel : preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $channel );
		return Paths::trailingslash( $this->storage->logs() ) . $channel . '.log';
	}

	/**
	 * Write a log line.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context values appended as JSON.
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		$weight = isset( self::LEVELS[ $level ] ) ? self::LEVELS[ $level ] : self::LEVELS['info'];
		if ( $weight < $this->threshold ) {
			return;
		}

		$line = sprintf(
			'[%s] %-7s %s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$this->redactor->scrub( $message )
		);

		if ( ! empty( $context ) ) {
			$line .= ' ' . $this->redactor->scrub( \SHCM\Support\Json::encode( $context ) );
		}

		$handle = $this->handle( $this->channel );
		if ( $handle ) {
			fwrite( $handle, $line . "\n" );
		}
	}

	/**
	 * Debug level helper.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Info level helper.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Warning level helper.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Error level helper.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Read the tail of a log file.
	 *
	 * @param string $channel Channel.
	 * @param int    $lines   Number of lines.
	 * @return string[]
	 */
	public function tail( $channel, $lines = 200 ) {
		$path = $this->path( $channel );
		if ( ! is_file( $path ) ) {
			return array();
		}
		$this->close( $channel );

		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return array();
		}
		$buffer = '';
		$chunk  = 8192;
		fseek( $handle, 0, SEEK_END );
		$position = ftell( $handle );
		while ( $position > 0 && substr_count( $buffer, "\n" ) <= $lines ) {
			$read     = (int) min( $chunk, $position );
			$position -= $read;
			fseek( $handle, $position );
			$buffer = fread( $handle, $read ) . $buffer;
		}
		fclose( $handle );

		$all = preg_split( '/\r\n|\n|\r/', trim( $buffer ) );
		return array_slice( $all, -$lines );
	}

	/**
	 * Get or open the handle for a channel.
	 *
	 * @param string $channel Channel.
	 * @return resource|null
	 */
	protected function handle( $channel ) {
		if ( isset( $this->handles[ $channel ] ) ) {
			return $this->handles[ $channel ];
		}
		$dir = $this->storage->logs();
		if ( ! is_dir( $dir ) ) {
			$this->storage->prepare();
		}
		$handle = @fopen( $this->path( $channel ), 'ab' );
		if ( ! $handle ) {
			return null;
		}
		$this->handles[ $channel ] = $handle;
		return $handle;
	}

	/**
	 * Close a channel handle.
	 *
	 * @param string $channel Channel.
	 * @return void
	 */
	public function close( $channel ) {
		if ( isset( $this->handles[ $channel ] ) ) {
			fclose( $this->handles[ $channel ] );
			unset( $this->handles[ $channel ] );
		}
	}

	/**
	 * Close every open handle.
	 */
	public function __destruct() {
		foreach ( array_keys( $this->handles ) as $channel ) {
			$this->close( $channel );
		}
	}
}
