<?php
/**
 * On-disk work queue.
 *
 * @package SHCM
 */

namespace SHCM\Filesystem;

use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * A newline delimited JSON file used as an append-only queue.
 *
 * The file list of a large site can be millions of entries, which must never
 * live in the job state (or in memory). Producers append, consumers remember a
 * byte offset, and nothing else needs to be persisted.
 */
class FileQueue {

	/**
	 * Queue file path.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Write handle.
	 *
	 * @var resource|null
	 */
	protected $writer = null;

	/**
	 * Read handle.
	 *
	 * @var resource|null
	 */
	protected $reader = null;

	/**
	 * Write buffer.
	 *
	 * @var string
	 */
	protected $buffer = '';

	/**
	 * Buffer flush threshold in bytes.
	 *
	 * @var int
	 */
	protected $flush_at = 262144;

	/**
	 * Constructor.
	 *
	 * @param string $path Queue file path.
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Queue file path.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Whether the queue file exists.
	 *
	 * @return bool
	 */
	public function exists() {
		return is_file( $this->path );
	}

	/**
	 * Queue size in bytes.
	 *
	 * @return int
	 */
	public function size() {
		return is_file( $this->path ) ? (int) filesize( $this->path ) : 0;
	}

	/**
	 * Append an item.
	 *
	 * @param array $item Item.
	 * @return void
	 * @throws \RuntimeException When the queue cannot be written.
	 */
	public function push( array $item ) {
		if ( null === $this->writer ) {
			$dir = dirname( $this->path );
			if ( ! is_dir( $dir ) ) {
				@mkdir( $dir, 0755, true );
			}
			$this->writer = @fopen( $this->path, 'ab' );
			if ( ! $this->writer ) {
				throw new \RuntimeException( sprintf( 'Cannot open the work queue at %s', $this->path ) );
			}
		}
		$this->buffer .= Json::encode( $item ) . "\n";
		if ( strlen( $this->buffer ) >= $this->flush_at ) {
			$this->flush();
		}
	}

	/**
	 * Flush the write buffer.
	 *
	 * @return void
	 */
	public function flush() {
		if ( '' === $this->buffer || null === $this->writer ) {
			return;
		}
		fwrite( $this->writer, $this->buffer );
		fflush( $this->writer );
		$this->buffer = '';
	}

	/**
	 * Close the write handle.
	 *
	 * @return void
	 */
	public function closeWriter() {
		$this->flush();
		if ( is_resource( $this->writer ) ) {
			fclose( $this->writer );
		}
		$this->writer = null;
	}

	/**
	 * Open the queue for reading at a byte offset.
	 *
	 * @param int $offset Byte offset.
	 * @return void
	 * @throws \RuntimeException When the queue cannot be read.
	 */
	public function openReader( $offset = 0 ) {
		$this->closeReader();
		$this->reader = @fopen( $this->path, 'rb' );
		if ( ! $this->reader ) {
			throw new \RuntimeException( sprintf( 'Cannot read the work queue at %s', $this->path ) );
		}
		if ( $offset > 0 ) {
			fseek( $this->reader, (int) $offset );
		}
	}

	/**
	 * Read the next item.
	 *
	 * @return array|null
	 */
	public function next() {
		if ( null === $this->reader ) {
			$this->openReader( 0 );
		}
		while ( ! feof( $this->reader ) ) {
			$line = fgets( $this->reader );
			if ( false === $line ) {
				return null;
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$item = Json::decode( $line );
			if ( null !== $item ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Current read offset.
	 *
	 * @return int
	 */
	public function tell() {
		return null === $this->reader ? 0 : (int) ftell( $this->reader );
	}

	/**
	 * Close the read handle.
	 *
	 * @return void
	 */
	public function closeReader() {
		if ( is_resource( $this->reader ) ) {
			fclose( $this->reader );
		}
		$this->reader = null;
	}

	/**
	 * Delete the queue file.
	 *
	 * @return void
	 */
	public function delete() {
		$this->closeWriter();
		$this->closeReader();
		if ( is_file( $this->path ) ) {
			@unlink( $this->path );
		}
	}

	/**
	 * Close everything.
	 */
	public function __destruct() {
		$this->closeWriter();
		$this->closeReader();
	}
}
