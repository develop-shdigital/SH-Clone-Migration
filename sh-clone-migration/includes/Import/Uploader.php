<?php
/**
 * Chunked archive upload.
 *
 * @package SHCM
 */

namespace SHCM\Import;

use SHCM\Archive\Format;
use SHCM\Archive\Reader;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Receives an archive in pieces so that neither upload_max_filesize nor a
 * dropped connection can cap how large a migration may be.
 *
 * Each chunk is appended at the offset the client claims, and the server
 * refuses anything that does not line up with what it already has: a retried
 * chunk is idempotent, a mis-ordered one is rejected rather than corrupting
 * the file.
 */
class Uploader {

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage Storage.
	 */
	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Begin an upload.
	 *
	 * @param string $filename Client file name.
	 * @param int    $size     Declared total size.
	 * @return array Upload descriptor.
	 * @throws \RuntimeException When the upload cannot be started.
	 */
	public function begin( $filename, $size ) {
		$this->storage->prepare();

		$filename = $this->sanitizeName( $filename );
		$id       = bin2hex( random_bytes( 16 ) );

		$meta = array(
			'id'       => $id,
			'name'     => $filename,
			'size'     => max( 0, (int) $size ),
			'received' => 0,
			'started'  => time(),
			'user'     => get_current_user_id(),
		);

		if ( false === @file_put_contents( $this->partPath( $id ), '' ) ) {
			throw new \RuntimeException( __( 'The upload directory is not writable.', 'sh-clone-migration' ) );
		}
		$this->writeMeta( $id, $meta );

		return $meta;
	}

	/**
	 * Append a chunk.
	 *
	 * @param string $id     Upload id.
	 * @param int    $offset Byte offset this chunk starts at.
	 * @param string $tmp    Temporary file path from $_FILES.
	 * @return array Updated descriptor.
	 * @throws \RuntimeException On any mismatch.
	 */
	public function appendChunk( $id, $offset, $tmp ) {
		$meta = $this->meta( $id );
		$path = $this->partPath( $id );

		$current = (int) @filesize( $path );
		$offset  = (int) $offset;

		if ( $offset === $current - $this->chunkSize( $tmp ) ) {
			// The client retried a chunk that already landed; report success
			// without writing it twice.
			$meta['received'] = $current;
			$this->writeMeta( $id, $meta );
			unset( $meta['hash'] );
			return $meta;
		}

		if ( $offset !== $current ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: expected offset, 2: received offset */
					__( 'Upload out of sequence: expected offset %1$d but received %2$d. Resume from the reported offset.', 'sh-clone-migration' ),
					$current,
					$offset
				)
			);
		}

		$in = @fopen( $tmp, 'rb' );
		if ( ! $in ) {
			throw new \RuntimeException( __( 'The uploaded chunk could not be read.', 'sh-clone-migration' ) );
		}
		$out = @fopen( $path, 'ab' );
		if ( ! $out ) {
			fclose( $in );
			throw new \RuntimeException( __( 'The upload file could not be written.', 'sh-clone-migration' ) );
		}

		// The SHA-256 of the whole upload is built chunk by chunk, so it is
		// ready the moment the last chunk lands and can be compared with the
		// one the source site showed after its export.
		$context = $this->hashContext( $meta, $current );

		$written = 0;
		while ( ! feof( $in ) ) {
			$buffer = fread( $in, 1048576 );
			if ( false === $buffer || '' === $buffer ) {
				break;
			}
			$bytes = fwrite( $out, $buffer );
			if ( false === $bytes || strlen( $buffer ) !== $bytes ) {
				fclose( $in );
				fclose( $out );
				// Leave the part file exactly as it was before this chunk.
				$this->truncatePart( $path, $current );
				throw new \RuntimeException( __( 'Writing the upload failed. The disk may be full.', 'sh-clone-migration' ) );
			}
			if ( null !== $context ) {
				hash_update( $context, $buffer );
			}
			$written += $bytes;
		}
		fclose( $in );
		fflush( $out );
		fclose( $out );

		$meta['received'] = (int) @filesize( $path );
		$meta['updated']  = time();
		$meta['hash']     = null === $context ? '' : base64_encode( serialize( $context ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$meta['hashed']   = null === $context ? 0 : $meta['received'];
		$this->writeMeta( $id, $meta );
		unset( $meta['hash'] );

		if ( 0 === $offset ) {
			// Reject anything that is not one of our archives as early as
			// possible, before the rest of a multi gigabyte upload arrives.
			$this->assertMagic( $path );
		}

		return $meta;
	}

	/**
	 * Finish an upload and move it into the archive directory.
	 *
	 * @param string $id Upload id.
	 * @return array Archive descriptor.
	 * @throws \RuntimeException When the upload is incomplete or invalid.
	 */
	public function finish( $id ) {
		$meta = $this->meta( $id );
		$path = $this->partPath( $id );
		$size = (int) @filesize( $path );

		if ( $meta['size'] > 0 && $size !== $meta['size'] ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: expected size, 2: received size */
					__( 'The upload is incomplete: expected %1$d bytes but received %2$d.', 'sh-clone-migration' ),
					$meta['size'],
					$size
				)
			);
		}

		$this->assertMagic( $path );

		$footer = Reader::readFooter( $path );
		if ( null === $footer ) {
			throw new \RuntimeException(
				__( 'Migration archive validation failed. The archive appears to be incomplete or corrupted.', 'sh-clone-migration' )
			);
		}

		$digest  = '';
		$context = $this->hashContext( $meta, $size );
		if ( null !== $context && ! empty( $meta['hashed'] ) && (int) $meta['hashed'] === $size ) {
			$digest = hash_final( $context );
		}

		$target = $this->uniqueArchivePath( $meta['name'] );
		if ( ! @rename( $path, $target ) ) {
			throw new \RuntimeException( __( 'The uploaded archive could not be moved into the storage directory.', 'sh-clone-migration' ) );
		}
		@chmod( $target, 0640 );
		$this->deleteMeta( $id );
		if ( '' !== $digest ) {
			\SHCM\Archive\Catalog::writeChecksum( $target, $digest );
		}

		return array(
			'name'   => basename( $target ),
			'path'   => $target,
			'size'   => (int) @filesize( $target ),
			'sha256' => $digest,
		);
	}

	/**
	 * The running SHA-256 of an upload, or null when this PHP build cannot
	 * carry a hash context from one request to the next (or the upload
	 * began without one).
	 *
	 * @param array $meta     Upload descriptor.
	 * @param int   $received Bytes actually in the part file.
	 * @return \HashContext|null
	 */
	protected function hashContext( array $meta, $received ) {
		if ( ! \SHCM\Archive\FileDigest::resumable() ) {
			return null;
		}
		$received = (int) $received;
		if ( 0 === $received ) {
			return hash_init( 'sha256' );
		}
		if ( empty( $meta['hash'] ) || ! isset( $meta['hashed'] ) || (int) $meta['hashed'] !== $received ) {
			return null;
		}
		$context = @unserialize( base64_decode( (string) $meta['hash'] ), array( 'allowed_classes' => array( 'HashContext' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return $context instanceof \HashContext ? $context : null;
	}

	/**
	 * Cut a part file back to a size.
	 *
	 * @param string $path Part file.
	 * @param int    $size Size.
	 * @return void
	 */
	protected function truncatePart( $path, $size ) {
		$handle = @fopen( $path, 'r+b' );
		if ( $handle ) {
			ftruncate( $handle, max( 0, (int) $size ) );
			fclose( $handle );
		}
	}

	/**
	 * Current state of an upload.
	 *
	 * @param string $id Upload id.
	 * @return array
	 */
	public function status( $id ) {
		$meta             = $this->meta( $id );
		$meta['received'] = (int) @filesize( $this->partPath( $id ) );
		unset( $meta['hash'] );
		return $meta;
	}

	/**
	 * Abort an upload and remove its partial file.
	 *
	 * @param string $id Upload id.
	 * @return void
	 */
	public function abort( $id ) {
		$path = $this->partPath( $id );
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
		$this->deleteMeta( $id );
	}

	/**
	 * Register a file that is already on the server.
	 *
	 * @param string $path Absolute path inside the archive directory.
	 * @return array
	 * @throws \RuntimeException When the file is not a usable archive.
	 */
	public function adopt( $path ) {
		$path = Paths::normalize( $path );
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( __( 'The archive file does not exist.', 'sh-clone-migration' ) );
		}
		$this->assertMagic( $path );

		if ( Paths::isInside( $path, $this->storage->archives() ) ) {
			return array(
				'name' => basename( $path ),
				'path' => $path,
				'size' => (int) filesize( $path ),
			);
		}

		$target = $this->uniqueArchivePath( basename( $path ) );
		if ( ! @rename( $path, $target ) && ! @copy( $path, $target ) ) {
			throw new \RuntimeException( __( 'The archive could not be moved into the storage directory.', 'sh-clone-migration' ) );
		}

		return array(
			'name' => basename( $target ),
			'path' => $target,
			'size' => (int) filesize( $target ),
		);
	}

	/**
	 * Verify the archive magic bytes.
	 *
	 * @param string $path File path.
	 * @return void
	 * @throws \RuntimeException When the file is not an SHCM archive.
	 */
	protected function assertMagic( $path ) {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( __( 'The uploaded file could not be read.', 'sh-clone-migration' ) );
		}
		$magic = fread( $handle, strlen( Format::MAGIC ) );
		fclose( $handle );

		if ( Format::MAGIC !== $magic ) {
			throw new \RuntimeException(
				__( 'This file is not an SH Clone Migration archive. Only .wpress files created by this plugin can be restored.', 'sh-clone-migration' )
			);
		}
	}

	/**
	 * Size of an uploaded chunk.
	 *
	 * @param string $tmp Temporary file.
	 * @return int
	 */
	protected function chunkSize( $tmp ) {
		return (int) @filesize( $tmp );
	}

	/**
	 * A collision free path in the archive directory.
	 *
	 * @param string $filename Desired name.
	 * @return string
	 */
	protected function uniqueArchivePath( $filename ) {
		$filename = $this->sanitizeName( $filename );
		$base     = preg_replace( '/\.' . Format::EXTENSION . '$/', '', $filename );

		// An unguessable suffix, for the same reason exported archives get one.
		do {
			$path = Paths::trailingslash( $this->storage->archives() )
				. $base . '-' . bin2hex( random_bytes( 8 ) ) . '.' . Format::EXTENSION;
		} while ( file_exists( $path ) );

		return $path;
	}

	/**
	 * Clean a client supplied file name.
	 *
	 * @param string $filename Raw name.
	 * @return string
	 */
	protected function sanitizeName( $filename ) {
		$filename = sanitize_file_name( basename( (string) $filename ) );
		$filename = preg_replace( '/[^A-Za-z0-9._\-]/', '-', $filename );
		if ( '' === $filename ) {
			$filename = 'migration';
		}
		if ( ! preg_match( '/\.' . Format::EXTENSION . '$/i', $filename ) ) {
			$filename .= '.' . Format::EXTENSION;
		}
		return $filename;
	}

	/**
	 * Path of the partial upload.
	 *
	 * @param string $id Upload id.
	 * @return string
	 */
	protected function partPath( $id ) {
		return Paths::trailingslash( $this->storage->incoming() ) . $this->sanitizeId( $id ) . '.part';
	}

	/**
	 * Path of the upload metadata.
	 *
	 * @param string $id Upload id.
	 * @return string
	 */
	protected function metaPath( $id ) {
		return Paths::trailingslash( $this->storage->incoming() ) . $this->sanitizeId( $id ) . '.json';
	}

	/**
	 * Validate an upload id.
	 *
	 * @param string $id Raw id.
	 * @return string
	 * @throws \RuntimeException When the id is malformed.
	 */
	protected function sanitizeId( $id ) {
		$id = (string) $id;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			throw new \RuntimeException( __( 'Invalid upload identifier.', 'sh-clone-migration' ) );
		}
		return $id;
	}

	/**
	 * Read upload metadata.
	 *
	 * @param string $id Upload id.
	 * @return array
	 * @throws \RuntimeException When the upload is unknown.
	 */
	protected function meta( $id ) {
		$path = $this->metaPath( $id );
		$meta = is_file( $path ) ? Json::decode( (string) file_get_contents( $path ) ) : null;
		if ( null === $meta ) {
			throw new \RuntimeException( __( 'This upload is unknown or has expired. Start it again.', 'sh-clone-migration' ) );
		}
		if ( (int) $meta['user'] !== get_current_user_id() && ! current_user_can( 'manage_network_options' ) ) {
			throw new \RuntimeException( __( 'This upload belongs to another user.', 'sh-clone-migration' ) );
		}
		return $meta;
	}

	/**
	 * Persist upload metadata.
	 *
	 * @param string $id   Upload id.
	 * @param array  $meta Metadata.
	 * @return void
	 */
	protected function writeMeta( $id, array $meta ) {
		@file_put_contents( $this->metaPath( $id ), Json::encode( $meta ), LOCK_EX );
	}

	/**
	 * Delete upload metadata.
	 *
	 * @param string $id Upload id.
	 * @return void
	 */
	protected function deleteMeta( $id ) {
		$path = $this->metaPath( $id );
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
	}
}
