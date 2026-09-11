<?php
/**
 * Streaming .wpress archive reader.
 *
 * @package SHCM
 */

namespace SHCM\Archive;

use SHCM\Crypto\Cipher;
use SHCM\Support\Bytes;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Reads archives block by block.
 *
 * Nothing larger than a single block is ever held in memory, and every read
 * position (entry offset, block index, raw offset) can be persisted so an
 * import can continue in the next request.
 */
class Reader {

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	protected $handle;

	/**
	 * Archive path.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Prologue values.
	 *
	 * @var array
	 */
	protected $prologue = array();

	/**
	 * Offset of the first entry.
	 *
	 * @var int
	 */
	protected $first_entry_offset = 0;

	/**
	 * Optional cipher.
	 *
	 * @var Cipher|null
	 */
	protected $cipher = null;

	/**
	 * Maximum accepted stored block size, a guard against hostile archives.
	 *
	 * @var int
	 */
	protected $max_block = 67108864;

	/**
	 * Constructor.
	 *
	 * @param string $path     Archive path.
	 * @param string $password Migration password.
	 * @throws \RuntimeException When the archive cannot be opened or is not an SHCM archive.
	 */
	public function __construct( $path, $password = '' ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'Archive %s cannot be read.', $path ) );
		}
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( sprintf( 'Archive %s cannot be opened.', $path ) );
		}
		$this->handle = $handle;
		$this->path   = $path;

		$magic = fread( $handle, strlen( Format::MAGIC ) );
		if ( Format::MAGIC !== $magic ) {
			throw new \RuntimeException( 'This file is not an SH Clone Migration archive.' );
		}
		$length = $this->readU32();
		if ( $length <= 0 || $length > 1048576 ) {
			throw new \RuntimeException( 'The archive prologue is malformed.' );
		}
		$json           = fread( $handle, $length );
		$this->prologue = Json::decode( $json );
		if ( null === $this->prologue ) {
			throw new \RuntimeException( 'The archive prologue could not be parsed.' );
		}
		$this->first_entry_offset = ftell( $handle );

		if ( ! empty( $this->prologue['encrypted'] ) ) {
			if ( '' === $password ) {
				throw new \RuntimeException( 'This archive is encrypted. A migration password is required.' );
			}
			$this->cipher = Cipher::fromParams( $password, isset( $this->prologue['encryption'] ) ? $this->prologue['encryption'] : array() );
		}

		if ( ! empty( $this->prologue['block_size'] ) ) {
			$this->max_block = max( $this->max_block, ( (int) $this->prologue['block_size'] ) * 2 + 4096 );
		}
	}

	/**
	 * Whether the archive is encrypted.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	public static function isEncrypted( $path ) {
		$info = self::peek( $path );
		return ! empty( $info['encrypted'] );
	}

	/**
	 * Read only the prologue of an archive, without needing the password.
	 *
	 * @param string $path Archive path.
	 * @return array|null
	 */
	public static function peek( $path ) {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return null;
		}
		$magic = fread( $handle, strlen( Format::MAGIC ) );
		if ( Format::MAGIC !== $magic ) {
			fclose( $handle );
			return null;
		}
		$raw = fread( $handle, 4 );
		if ( 4 !== strlen( (string) $raw ) ) {
			fclose( $handle );
			return null;
		}
		$length = Bytes::unpackU32( $raw );
		if ( $length <= 0 || $length > 1048576 ) {
			fclose( $handle );
			return null;
		}
		$json = fread( $handle, $length );
		fclose( $handle );
		return Json::decode( $json );
	}

	/**
	 * Read the footer of an archive without opening it.
	 *
	 * The footer is never encrypted, so an archive can be described (size,
	 * entry count, completeness) before its password is known.
	 *
	 * @param string $path Archive path.
	 * @return array|null
	 */
	public static function readFooter( $path ) {
		$size = (int) @filesize( $path );
		if ( $size < 32 ) {
			return null;
		}
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return null;
		}
		fseek( $handle, $size - 16 );
		$pointer = fread( $handle, 8 );
		$magic   = fread( $handle, 8 );
		if ( Format::MAGIC_END !== $magic || 8 !== strlen( (string) $pointer ) ) {
			fclose( $handle );
			return null;
		}
		$offset = Bytes::unpackU64( $pointer );
		if ( $offset <= 0 || $offset >= $size ) {
			fclose( $handle );
			return null;
		}
		fseek( $handle, $offset );
		$raw = fread( $handle, 4 );
		if ( 4 !== strlen( (string) $raw ) ) {
			fclose( $handle );
			return null;
		}
		$length = Bytes::unpackU32( $raw );
		if ( $length <= 0 || $length > 4194304 ) {
			fclose( $handle );
			return null;
		}
		$json = fread( $handle, $length );
		fclose( $handle );
		return Json::decode( $json );
	}

	/**
	 * Prologue values.
	 *
	 * @return array
	 */
	public function prologue() {
		return $this->prologue;
	}

	/**
	 * Archive path.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Archive size on disk.
	 *
	 * @return int
	 */
	public function fileSize() {
		return (int) filesize( $this->path );
	}

	/**
	 * Offset of the first entry header.
	 *
	 * @return int
	 */
	public function firstEntryOffset() {
		return $this->first_entry_offset;
	}

	/**
	 * Seek to an absolute entry header offset.
	 *
	 * @param int $offset Offset.
	 * @return void
	 */
	public function seek( $offset ) {
		fseek( $this->handle, (int) $offset );
	}

	/**
	 * Rewind to the first entry.
	 *
	 * @return void
	 */
	public function rewindEntries() {
		$this->seek( $this->first_entry_offset );
	}

	/**
	 * Read the next entry header. The handle is left at the payload start.
	 *
	 * @return array|null Entry descriptor, or null at the end of the entry list.
	 * @throws \RuntimeException When the archive is truncated or malformed.
	 */
	public function nextEntry() {
		$offset = ftell( $this->handle );
		$raw    = fread( $this->handle, 4 );
		if ( false === $raw || strlen( $raw ) < 4 ) {
			throw new \RuntimeException( 'The archive ends unexpectedly: the entry list is truncated.' );
		}
		$length = Bytes::unpackU32( $raw );
		if ( 0 === $length ) {
			return null;
		}
		if ( $length > 1048576 ) {
			throw new \RuntimeException( 'The archive contains an implausible entry header.' );
		}
		$json = fread( $this->handle, $length );
		if ( false === $json || strlen( $json ) < $length ) {
			throw new \RuntimeException( 'The archive ends unexpectedly inside an entry header.' );
		}
		$meta = Json::decode( $json );
		if ( null === $meta || empty( $meta['p'] ) ) {
			throw new \RuntimeException( 'The archive contains an unreadable entry header.' );
		}

		return array(
			'header_offset'  => $offset,
			'payload_offset' => ftell( $this->handle ),
			'path'           => (string) $meta['p'],
			'type'           => isset( $meta['t'] ) ? $meta['t'] : Format::TYPE_FILE,
			'size'           => isset( $meta['s'] ) ? (int) ltrim( $meta['s'], '0' ) : 0,
			'stored'         => isset( $meta['z'] ) ? (int) ltrim( $meta['z'], '0' ) : 0,
			'blocks'         => isset( $meta['n'] ) ? (int) ltrim( $meta['n'], '0' ) : 0,
			'hash'           => isset( $meta['h'] ) ? (string) $meta['h'] : '',
			'mtime'          => isset( $meta['m'] ) ? (int) $meta['m'] : 0,
			'mode'           => isset( $meta['x'] ) ? octdec( $meta['x'] ) : 0644,
			'group'          => isset( $meta['g'] ) ? (string) $meta['g'] : '',
			'root'           => isset( $meta['r'] ) ? (string) $meta['r'] : '',
			'target'         => isset( $meta['lt'] ) ? (string) $meta['lt'] : '',
		);
	}

	/**
	 * Offset of the entry that follows the given one.
	 *
	 * @param array $entry Entry descriptor.
	 * @return int
	 */
	public function entryEndOffset( array $entry ) {
		return $entry['payload_offset'] + $entry['stored'];
	}

	/**
	 * Skip the payload of an entry.
	 *
	 * @param array $entry Entry descriptor.
	 * @return void
	 */
	public function skipEntry( array $entry ) {
		$this->seek( $this->entryEndOffset( $entry ) );
	}

	/**
	 * Iterate the raw blocks of an entry.
	 *
	 * @param array $entry       Entry descriptor.
	 * @param int   $start_block Block index to start from.
	 * @return \Generator Yields raw block strings.
	 * @throws \RuntimeException When a block is corrupt.
	 */
	public function blocks( array $entry, $start_block = 0 ) {
		$offset = $this->blockOffset( $entry, $start_block );
		$this->seek( $offset );
		$end = $this->entryEndOffset( $entry );

		while ( ftell( $this->handle ) < $end ) {
			yield $this->readBlock( $entry );
		}
	}

	/**
	 * Byte offset of a block index inside an entry payload.
	 *
	 * Skipping is a pure seek operation: no block is decoded on the way.
	 *
	 * @param array $entry Entry descriptor.
	 * @param int   $index Block index.
	 * @return int
	 * @throws \RuntimeException When the payload is truncated.
	 */
	public function blockOffset( array $entry, $index ) {
		$offset = $entry['payload_offset'];
		if ( $index <= 0 ) {
			return $offset;
		}
		$end = $this->entryEndOffset( $entry );
		for ( $i = 0; $i < $index; $i++ ) {
			if ( $offset + 9 > $end ) {
				throw new \RuntimeException( 'The archive payload is truncated while skipping blocks.' );
			}
			fseek( $this->handle, $offset );
			$head = fread( $this->handle, 9 );
			if ( false === $head || strlen( $head ) < 9 ) {
				throw new \RuntimeException( 'The archive payload is truncated while skipping blocks.' );
			}
			$stored = Bytes::unpackU32( substr( $head, 1, 4 ) );
			$offset += 9 + $stored;
		}
		return $offset;
	}

	/**
	 * Read and decode one block at the current position.
	 *
	 * @param array $entry Entry descriptor (for error messages).
	 * @return string Raw block data.
	 * @throws \RuntimeException When the block is malformed.
	 */
	protected function readBlock( array $entry ) {
		$head = fread( $this->handle, 9 );
		if ( false === $head || strlen( $head ) < 9 ) {
			throw new \RuntimeException( sprintf( 'Truncated block header in entry %s.', $entry['path'] ) );
		}
		$flags  = ord( $head[0] );
		$stored = Bytes::unpackU32( substr( $head, 1, 4 ) );
		$raw    = Bytes::unpackU32( substr( $head, 5, 4 ) );

		if ( $stored > $this->max_block || $raw > $this->max_block ) {
			throw new \RuntimeException( sprintf( 'Implausible block size in entry %s.', $entry['path'] ) );
		}

		$payload = 0 === $stored ? '' : fread( $this->handle, $stored );
		if ( false === $payload || strlen( $payload ) !== $stored ) {
			throw new \RuntimeException( sprintf( 'Truncated block payload in entry %s.', $entry['path'] ) );
		}

		if ( $flags & Format::FLAG_ENCRYPT ) {
			if ( null === $this->cipher ) {
				throw new \RuntimeException( 'The archive is encrypted but no password was supplied.' );
			}
			$payload = $this->cipher->decrypt( $payload );
		}

		if ( $flags & Format::FLAG_DEFLATE ) {
			$inflated = @gzinflate( $payload, $raw );
			if ( false === $inflated ) {
				throw new \RuntimeException( sprintf( 'Cannot inflate a block of entry %s: the archive is corrupted.', $entry['path'] ) );
			}
			$payload = $inflated;
		}

		if ( strlen( $payload ) !== $raw ) {
			throw new \RuntimeException( sprintf( 'Block size mismatch in entry %s: the archive is corrupted.', $entry['path'] ) );
		}

		return $payload;
	}

	/**
	 * Read an entire entry into memory.
	 *
	 * @param array $entry Entry descriptor.
	 * @param int   $limit Refuse to read more than this many bytes.
	 * @return string
	 * @throws \RuntimeException When the entry is larger than the limit.
	 */
	public function readString( array $entry, $limit = 33554432 ) {
		if ( $entry['size'] > $limit ) {
			throw new \RuntimeException( sprintf( 'Entry %s is too large to read into memory.', $entry['path'] ) );
		}
		$out = '';
		foreach ( $this->blocks( $entry ) as $block ) {
			$out .= $block;
		}
		return $out;
	}

	/**
	 * Find an entry by logical path, scanning from the start.
	 *
	 * @param string $path Logical path.
	 * @return array|null
	 */
	public function findEntry( $path ) {
		$this->rewindEntries();
		while ( true ) {
			$entry = $this->nextEntry();
			if ( null === $entry ) {
				return null;
			}
			if ( $entry['path'] === $path ) {
				return $entry;
			}
			$this->skipEntry( $entry );
		}
	}

	/**
	 * Extract part of an entry into a writable stream.
	 *
	 * @param array    $entry      Entry descriptor.
	 * @param resource $target     Writable stream, positioned by the caller.
	 * @param array    $state      Extraction state: block, raw, hash (hex).
	 * @param int      $max_bytes  Stop after this many raw bytes, 0 for no limit.
	 * @return array Updated state with a "done" flag.
	 */
	public function extractTo( array $entry, $target, array $state = array(), $max_bytes = 0 ) {
		$block_index = isset( $state['block'] ) ? (int) $state['block'] : 0;
		$raw_written = isset( $state['raw'] ) ? (int) $state['raw'] : 0;
		$hash        = isset( $state['hash'] ) ? hex2bin( $state['hash'] ) : Format::initialHashState();

		$offset  = $this->blockOffset( $entry, $block_index );
		$end     = $this->entryEndOffset( $entry );
		$written = 0;
		$this->seek( $offset );

		while ( ftell( $this->handle ) < $end ) {
			$block = $this->readBlock( $entry );
			$bytes = fwrite( $target, $block );
			if ( false === $bytes || $bytes !== strlen( $block ) ) {
				throw new \RuntimeException( sprintf( 'Cannot write %s: the disk may be full.', $entry['path'] ) );
			}
			$hash         = Format::advanceHash( $hash, $block );
			$raw_written += strlen( $block );
			$written     += strlen( $block );
			++$block_index;

			if ( $max_bytes > 0 && $written >= $max_bytes ) {
				break;
			}
		}

		$done = ftell( $this->handle ) >= $end;

		return array(
			'block' => $block_index,
			'raw'   => $raw_written,
			'hash'  => bin2hex( $hash ),
			'done'  => $done,
			'wrote' => $written,
		);
	}

	/**
	 * Stream an entry through a callback, starting at a raw byte offset.
	 *
	 * Used by the database importer, which needs to continue in the middle of a
	 * dump without re-reading (or re-executing) what it already consumed.
	 *
	 * @param array    $entry      Entry descriptor.
	 * @param int      $raw_offset Raw byte offset to resume from.
	 * @param int      $max_bytes  Stop after roughly this many raw bytes.
	 * @param callable $callback   Receives ( string $chunk, int $raw_offset_after ).
	 *                             Returning false stops the stream.
	 * @return array position and done flag.
	 */
	public function streamFrom( array $entry, $raw_offset, $max_bytes, callable $callback ) {
		$position = 0;

		// Locate the block containing $raw_offset by walking block headers.
		$offset = $entry['payload_offset'];
		$end    = $this->entryEndOffset( $entry );
		while ( $offset < $end ) {
			fseek( $this->handle, $offset );
			$head = fread( $this->handle, 9 );
			if ( false === $head || strlen( $head ) < 9 ) {
				break;
			}
			$stored = Bytes::unpackU32( substr( $head, 1, 4 ) );
			$raw    = Bytes::unpackU32( substr( $head, 5, 4 ) );
			if ( $position + $raw > $raw_offset ) {
				break;
			}
			$position += $raw;
			$offset   += 9 + $stored;
		}

		$this->seek( $offset );
		$consumed = 0;
		$skip     = $raw_offset - $position;

		while ( ftell( $this->handle ) < $end ) {
			$block = $this->readBlock( $entry );
			if ( $skip > 0 ) {
				$block = (string) substr( $block, $skip );
				$skip  = 0;
			}
			$raw_offset += strlen( $block );
			$consumed   += strlen( $block );
			$continue    = call_user_func( $callback, $block, $raw_offset );
			if ( false === $continue ) {
				return array(
					'offset' => $raw_offset,
					'done'   => false,
				);
			}
			if ( $max_bytes > 0 && $consumed >= $max_bytes ) {
				break;
			}
		}

		return array(
			'offset' => $raw_offset,
			'done'   => ftell( $this->handle ) >= $end,
		);
	}

	/**
	 * Read the archive footer.
	 *
	 * @return array|null
	 */
	public function footer() {
		$size = $this->fileSize();
		$tail = 8 + 8;
		if ( $size < $tail ) {
			return null;
		}
		fseek( $this->handle, $size - $tail );
		$pointer = fread( $this->handle, 8 );
		$magic   = fread( $this->handle, 8 );
		if ( Format::MAGIC_END !== $magic ) {
			return null;
		}
		$offset = Bytes::unpackU64( $pointer );
		if ( $offset <= 0 || $offset >= $size ) {
			return null;
		}
		fseek( $this->handle, $offset );
		$length = $this->readU32();
		if ( $length <= 0 || $length > 4194304 ) {
			return null;
		}
		return Json::decode( fread( $this->handle, $length ) );
	}

	/**
	 * Whether the archive carries a complete end marker.
	 *
	 * @return bool
	 */
	public function isComplete() {
		return null !== $this->footer();
	}

	/**
	 * Read a big endian uint32 at the current position.
	 *
	 * @return int
	 */
	protected function readU32() {
		$raw = fread( $this->handle, 4 );
		if ( false === $raw || strlen( $raw ) < 4 ) {
			return 0;
		}
		return Bytes::unpackU32( $raw );
	}

	/**
	 * Close the handle.
	 */
	public function close() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}
}
