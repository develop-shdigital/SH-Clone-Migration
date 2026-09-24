<?php
/**
 * Streaming .wpress archive writer.
 *
 * @package SHCM
 */

namespace SHCM\Archive;

use SHCM\Crypto\Cipher;
use SHCM\Support\Bytes;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Writes archives one block at a time.
 *
 * The writer can be paused after any block and resumed in a later request, so
 * a single archive may be produced across hundreds of HTTP requests without
 * ever restarting.
 */
class Writer {

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
	 * Raw block size.
	 *
	 * @var int
	 */
	protected $block_size;

	/**
	 * Whether payload blocks are deflated.
	 *
	 * @var bool
	 */
	protected $compress;

	/**
	 * Deflate level.
	 *
	 * @var int
	 */
	protected $level;

	/**
	 * Optional cipher.
	 *
	 * @var Cipher|null
	 */
	protected $cipher = null;

	/**
	 * Current entry state or null.
	 *
	 * @var array|null
	 */
	protected $entry = null;

	/**
	 * Pending raw bytes not yet written as a block.
	 *
	 * @var string
	 */
	protected $buffer = '';

	/**
	 * Totals.
	 *
	 * @var array
	 */
	protected $totals = array(
		'entries' => 0,
		'raw'     => 0,
		'stored'  => 0,
	);

	/**
	 * File extensions that are already compressed; deflating them again only
	 * burns CPU.
	 *
	 * @var string[]
	 */
	protected static $incompressible = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'ico', 'bmp',
		'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'ogg', 'ogv', 'flac', 'wav',
		'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'wpress',
		'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx',
	);

	/**
	 * Constructor is private: use create() or resume().
	 *
	 * @param resource $handle     File handle.
	 * @param string   $path       Archive path.
	 * @param int      $block_size Block size.
	 * @param bool     $compress   Compression flag.
	 * @param int      $level      Deflate level.
	 */
	protected function __construct( $handle, $path, $block_size, $compress, $level ) {
		$this->handle     = $handle;
		$this->path       = $path;
		$this->block_size = $block_size;
		$this->compress   = $compress;
		$this->level      = $level;
	}

	/**
	 * Create a new archive.
	 *
	 * @param string $path    Target path.
	 * @param array  $options block_size, compress, level, password, prologue.
	 * @return self
	 * @throws \RuntimeException On IO failure.
	 */
	public static function create( $path, array $options = array() ) {
		$block_size = isset( $options['block_size'] ) ? (int) $options['block_size'] : Format::DEFAULT_BLOCK_SIZE;
		$block_size = max( 65536, min( Format::MAX_BLOCK_SIZE, $block_size ) );
		$compress   = isset( $options['compress'] ) ? (bool) $options['compress'] : function_exists( 'gzdeflate' );
		$level      = isset( $options['level'] ) ? max( 1, min( 9, (int) $options['level'] ) ) : 6;

		$handle = @fopen( $path, 'w+b' );
		if ( ! $handle ) {
			throw new \RuntimeException( sprintf( 'Cannot create archive at %s', $path ) );
		}

		$writer = new self( $handle, $path, $block_size, $compress, $level );

		$prologue = array(
			'format'     => Format::VERSION,
			'generator'  => 'SH Clone Migration ' . ( defined( 'SHCM_VERSION' ) ? SHCM_VERSION : 'dev' ),
			'created'    => time(),
			'block_size' => $block_size,
			'compress'   => $compress ? 'deflate' : 'none',
			'encrypted'  => false,
		);
		if ( isset( $options['prologue'] ) && is_array( $options['prologue'] ) ) {
			$prologue = array_merge( $prologue, $options['prologue'] );
		}

		if ( ! empty( $options['password'] ) ) {
			$init                   = Cipher::initialise( $options['password'] );
			$writer->cipher         = $init['cipher'];
			$prologue['encrypted']  = true;
			$prologue['encryption'] = $init['params'];
			$writer->setEncryptionParams( $init['params'] );
		}

		$json = Json::encode( $prologue );
		fwrite( $handle, Format::MAGIC );
		fwrite( $handle, Bytes::packU32( strlen( $json ) ) );
		fwrite( $handle, $json );

		return $writer;
	}

	/**
	 * Resume writing a paused archive.
	 *
	 * @param array  $state    State returned by pause().
	 * @param string $password Migration password, when the archive is encrypted.
	 * @return self
	 * @throws \RuntimeException On IO failure.
	 */
	public static function resume( array $state, $password = '' ) {
		$path = $state['path'];
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( sprintf( 'Archive %s disappeared while the job was paused.', $path ) );
		}
		$handle = @fopen( $path, 'r+b' );
		if ( ! $handle ) {
			throw new \RuntimeException( sprintf( 'Cannot reopen archive at %s', $path ) );
		}

		// Discard anything written after the last committed block: a request
		// that died mid-block must not leave a torn tail behind.
		$committed = (int) $state['size'];
		clearstatcache( true, $path );
		$actual = (int) filesize( $path );
		if ( $actual > $committed ) {
			ftruncate( $handle, $committed );
		} elseif ( $actual < $committed ) {
			// Shorter than its committed state: seeking past the end would
			// leave a hole of zero bytes inside an entry.
			fclose( $handle );
			throw new \RuntimeException(
				sprintf( 'Archive %1$s is shorter (%2$d bytes) than its saved state (%3$d bytes); it was cut short by an interrupted request. Start the export again.', basename( $path ), $actual, $committed )
			);
		}
		fseek( $handle, $committed );

		$writer = new self( $handle, $path, (int) $state['block_size'], (bool) $state['compress'], (int) $state['level'] );
		$writer->totals = $state['totals'];
		$writer->entry  = null;

		if ( ! empty( $state['encrypted'] ) ) {
			if ( '' === $password ) {
				throw new \RuntimeException( 'A migration password is required to resume this archive.' );
			}
			$writer->cipher = Cipher::fromParams( $password, $state['encryption'] );
			$writer->setEncryptionParams( $state['encryption'] );
		}

		if ( ! empty( $state['entry'] ) ) {
			$writer->entry         = $state['entry'];
			$writer->entry['hash'] = hex2bin( $state['entry']['hash'] );
		}

		return $writer;
	}

	/**
	 * Pause the writer and return a serialisable state.
	 *
	 * @return array
	 */
	public function pause() {
		$this->flushBuffer();
		fflush( $this->handle );

		$entry = null;
		if ( null !== $this->entry ) {
			$entry         = $this->entry;
			$entry['hash'] = bin2hex( $this->entry['hash'] );
		}

		$state = array(
			'path'       => $this->path,
			'size'       => ftell( $this->handle ),
			'block_size' => $this->block_size,
			'compress'   => $this->compress,
			'level'      => $this->level,
			'totals'     => $this->totals,
			'entry'      => $entry,
			'encrypted'  => null !== $this->cipher,
		);

		if ( null !== $this->cipher ) {
			$state['encryption'] = $this->encryptionParams;
		}

		return $state;
	}

	/**
	 * Encryption parameters kept for pause/resume round trips.
	 *
	 * @var array
	 */
	protected $encryptionParams = array();

	/**
	 * Remember the encryption parameters (called by create()).
	 *
	 * @param array $params Parameters.
	 * @return void
	 */
	public function setEncryptionParams( array $params ) {
		$this->encryptionParams = $params;
	}

	/**
	 * Current archive size in bytes.
	 *
	 * @return int
	 */
	public function size() {
		return ftell( $this->handle );
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
	 * Totals so far.
	 *
	 * @return array
	 */
	public function totals() {
		return $this->totals;
	}

	/**
	 * Whether an entry is currently open.
	 *
	 * @return bool
	 */
	public function hasOpenEntry() {
		return null !== $this->entry;
	}

	/**
	 * Path of the currently open entry.
	 *
	 * @return string|null
	 */
	public function openEntryPath() {
		return null === $this->entry ? null : $this->entry['meta']['p'];
	}

	/**
	 * Start a new entry.
	 *
	 * @param string $logical_path Logical path inside the archive.
	 * @param array  $meta         type, mtime, mode, group, root, link target.
	 * @return void
	 * @throws \RuntimeException When another entry is still open.
	 */
	public function beginEntry( $logical_path, array $meta = array() ) {
		if ( null !== $this->entry ) {
			throw new \RuntimeException( sprintf( 'Entry %s is still open.', $this->entry['meta']['p'] ) );
		}

		$header = array(
			'p' => $this->sanitizeLogicalPath( $logical_path ),
			't' => isset( $meta['type'] ) ? $meta['type'] : Format::TYPE_FILE,
			's' => Bytes::pad( 0 ),
			'z' => Bytes::pad( 0 ),
			'n' => Bytes::pad( 0 ),
			'h' => str_repeat( '0', Format::HASH_WIDTH ),
			'm' => isset( $meta['mtime'] ) ? (int) $meta['mtime'] : time(),
			'x' => isset( $meta['mode'] ) ? sprintf( '%04o', $meta['mode'] & 0777 ) : '0644',
		);
		if ( ! empty( $meta['group'] ) ) {
			$header['g'] = (string) $meta['group'];
		}
		if ( ! empty( $meta['root'] ) ) {
			$header['r'] = (string) $meta['root'];
		}
		if ( ! empty( $meta['target'] ) ) {
			$header['lt'] = (string) $meta['target'];
		}

		$json = Json::encode( $header );
		$offset = ftell( $this->handle );
		fwrite( $this->handle, Bytes::packU32( strlen( $json ) ) );
		fwrite( $this->handle, $json );

		$extension    = strtolower( (string) pathinfo( $header['p'], PATHINFO_EXTENSION ) );
		$compressible = $this->compress && ! in_array( $extension, self::$incompressible, true );

		$this->entry = array(
			'meta'          => $header,
			'header_offset' => $offset,
			'header_length' => strlen( $json ),
			'raw'           => 0,
			'stored'        => 0,
			'blocks'        => 0,
			'hash'          => Format::initialHashState(),
			'compressible'  => $compressible,
		);
		$this->buffer = '';
	}

	/**
	 * Append raw data to the open entry.
	 *
	 * @param string $data Raw data.
	 * @return void
	 * @throws \RuntimeException When no entry is open.
	 */
	public function append( $data ) {
		if ( null === $this->entry ) {
			throw new \RuntimeException( 'No archive entry is open.' );
		}
		if ( '' === $data ) {
			return;
		}
		$this->buffer .= $data;
		while ( strlen( $this->buffer ) >= $this->block_size ) {
			$block        = substr( $this->buffer, 0, $this->block_size );
			$this->buffer = substr( $this->buffer, $this->block_size );
			$this->writeBlock( $block );
		}
	}

	/**
	 * Stream up to $max_bytes from a file handle into the open entry.
	 *
	 * @param resource $source    Readable stream.
	 * @param int      $max_bytes Maximum raw bytes to consume, 0 for "until EOF".
	 * @return int Bytes consumed.
	 */
	public function appendFromHandle( $source, $max_bytes = 0 ) {
		$consumed = 0;
		while ( ! feof( $source ) ) {
			$want = $this->block_size;
			if ( $max_bytes > 0 ) {
				$remaining = $max_bytes - $consumed;
				if ( $remaining <= 0 ) {
					break;
				}
				$want = (int) min( $want, $remaining );
			}
			$chunk = fread( $source, $want );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$this->append( $chunk );
			$consumed += strlen( $chunk );
		}
		return $consumed;
	}

	/**
	 * Close the open entry and patch its header with the final sizes and hash.
	 *
	 * @return array Entry summary.
	 * @throws \RuntimeException When no entry is open or the header cannot be patched.
	 */
	public function finishEntry() {
		if ( null === $this->entry ) {
			throw new \RuntimeException( 'No archive entry is open.' );
		}
		$this->flushBuffer();

		$entry              = $this->entry;
		$meta               = $entry['meta'];
		$meta['s']          = Bytes::pad( $entry['raw'] );
		$meta['z']          = Bytes::pad( $entry['stored'] );
		$meta['n']          = Bytes::pad( $entry['blocks'] );
		$meta['h']          = bin2hex( $entry['hash'] );

		$json = Json::encode( $meta );
		if ( strlen( $json ) !== $entry['header_length'] ) {
			throw new \RuntimeException( 'Archive header length changed while patching; refusing to corrupt the archive.' );
		}

		$end = ftell( $this->handle );
		fseek( $this->handle, $entry['header_offset'] + 4 );
		fwrite( $this->handle, $json );
		fseek( $this->handle, $end );

		$this->totals['entries']++;
		$this->totals['raw']    += $entry['raw'];
		$this->totals['stored'] += $entry['stored'];

		$this->entry  = null;
		$this->buffer = '';

		return array(
			'path'   => $meta['p'],
			'size'   => $entry['raw'],
			'stored' => $entry['stored'],
			'blocks' => $entry['blocks'],
			'hash'   => $meta['h'],
			'type'   => $meta['t'],
			'group'  => isset( $meta['g'] ) ? $meta['g'] : '',
			'target' => isset( $meta['lt'] ) ? (string) $meta['lt'] : '',
			'mode'   => octdec( $meta['x'] ),
		);
	}

	/**
	 * Throw away the open entry: truncate the archive back to where its
	 * header started, as if it had never been begun.
	 *
	 * Used when a file changed or became unreadable part way through, so the
	 * archive never holds a torn copy that would still pass verification.
	 *
	 * @return void
	 * @throws \RuntimeException When the archive cannot be truncated.
	 */
	public function abortEntry() {
		if ( null === $this->entry ) {
			return;
		}
		$offset = (int) $this->entry['header_offset'];
		fflush( $this->handle );
		if ( ! ftruncate( $this->handle, $offset ) ) {
			throw new \RuntimeException( 'The archive could not be truncated to discard an incomplete entry.' );
		}
		fseek( $this->handle, $offset );
		$this->entry  = null;
		$this->buffer = '';
	}

	/**
	 * Add a complete in-memory entry.
	 *
	 * @param string $logical_path Logical path.
	 * @param string $contents     Contents.
	 * @param array  $meta         Metadata.
	 * @return array Entry summary.
	 */
	public function addString( $logical_path, $contents, array $meta = array() ) {
		$this->beginEntry( $logical_path, $meta );
		$this->append( $contents );
		return $this->finishEntry();
	}

	/**
	 * Add a complete file from disk.
	 *
	 * @param string $logical_path Logical path.
	 * @param string $absolute     Absolute source path.
	 * @param array  $meta         Metadata.
	 * @return array Entry summary.
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public function addFile( $logical_path, $absolute, array $meta = array() ) {
		$handle = @fopen( $absolute, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( sprintf( 'Cannot read %s', $absolute ) );
		}
		$meta = array_merge(
			array(
				'type'  => Format::TYPE_FILE,
				'mtime' => @filemtime( $absolute ),
				'mode'  => @fileperms( $absolute ),
			),
			$meta
		);
		$this->beginEntry( $logical_path, $meta );
		$this->appendFromHandle( $handle );
		fclose( $handle );
		return $this->finishEntry();
	}

	/**
	 * Add a directory marker (used to preserve empty directories).
	 *
	 * @param string $logical_path Logical path.
	 * @param array  $meta         Metadata.
	 * @return array Entry summary.
	 */
	public function addDirectory( $logical_path, array $meta = array() ) {
		$meta['type'] = Format::TYPE_DIR;
		$this->beginEntry( $logical_path, $meta );
		return $this->finishEntry();
	}

	/**
	 * Add a symlink record.
	 *
	 * @param string $logical_path Logical path.
	 * @param string $target       Link target.
	 * @param array  $meta         Metadata.
	 * @return array Entry summary.
	 */
	public function addSymlink( $logical_path, $target, array $meta = array() ) {
		$meta['type']   = Format::TYPE_LINK;
		$meta['target'] = $target;
		$this->beginEntry( $logical_path, $meta );
		return $this->finishEntry();
	}

	/**
	 * Finalise the archive.
	 *
	 * @param array $footer Extra footer values.
	 * @return array Footer written.
	 */
	public function close( array $footer = array() ) {
		if ( null !== $this->entry ) {
			$this->finishEntry();
		}

		$footer = array_merge(
			array(
				'entries'   => $this->totals['entries'],
				'raw_size'  => (string) $this->totals['raw'],
				'stored'    => (string) $this->totals['stored'],
				'completed' => time(),
			),
			$footer
		);

		$json = Json::encode( $footer );
		fwrite( $this->handle, Bytes::packU32( 0 ) );
		$footer_offset = ftell( $this->handle );
		fwrite( $this->handle, Bytes::packU32( strlen( $json ) ) );
		fwrite( $this->handle, $json );
		fwrite( $this->handle, Bytes::packU64( $footer_offset ) );
		fwrite( $this->handle, Format::MAGIC_END );
		fflush( $this->handle );

		return $footer;
	}

	/**
	 * Close the underlying handle without finalising (used when pausing).
	 *
	 * @return void
	 */
	public function release() {
		if ( is_resource( $this->handle ) ) {
			fflush( $this->handle );
			fclose( $this->handle );
		}
	}

	/**
	 * Flush the pending buffer as a (possibly short) block.
	 *
	 * @return void
	 */
	protected function flushBuffer() {
		if ( '' === $this->buffer || null === $this->entry ) {
			$this->buffer = '';
			return;
		}
		$block        = $this->buffer;
		$this->buffer = '';
		$this->writeBlock( $block );
	}

	/**
	 * Encode and write one block.
	 *
	 * @param string $raw Raw block data.
	 * @return void
	 */
	protected function writeBlock( $raw ) {
		$flags   = 0;
		$payload = $raw;

		if ( $this->entry['compressible'] ) {
			$deflated = gzdeflate( $raw, $this->level );
			if ( false !== $deflated && strlen( $deflated ) < strlen( $raw ) ) {
				$payload = $deflated;
				$flags  |= Format::FLAG_DEFLATE;
			}
		}

		if ( null !== $this->cipher ) {
			$payload = $this->cipher->encrypt( $payload );
			$flags  |= Format::FLAG_ENCRYPT;
		}

		fwrite( $this->handle, chr( $flags ) );
		fwrite( $this->handle, Bytes::packU32( strlen( $payload ) ) );
		fwrite( $this->handle, Bytes::packU32( strlen( $raw ) ) );
		fwrite( $this->handle, $payload );

		$this->entry['raw']    += strlen( $raw );
		$this->entry['stored'] += 9 + strlen( $payload );
		$this->entry['blocks']++;
		$this->entry['hash'] = Format::advanceHash( $this->entry['hash'], $raw );
	}

	/**
	 * Normalise a logical entry path.
	 *
	 * @param string $path Path.
	 * @return string
	 * @throws \RuntimeException When the path escapes the archive namespace.
	 */
	protected function sanitizeLogicalPath( $path ) {
		// A backslash is an ordinary character in a Linux file name (Windows
		// ZIPs extracted there produce "images\logo.png"); it is kept, and the
		// importer decides what it can represent on the destination.
		$path = preg_replace( '#/+#', '/', (string) $path );
		$path = ltrim( $path, '/' );
		if ( '' === $path ) {
			throw new \RuntimeException( 'Refusing to write an entry with an empty path.' );
		}
		if ( false !== strpos( $path, "\0" ) ) {
			throw new \RuntimeException( 'Refusing to write an entry with a null byte in its path.' );
		}
		return $path;
	}
}
