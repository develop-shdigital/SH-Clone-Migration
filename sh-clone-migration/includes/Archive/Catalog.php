<?php
/**
 * Stored archive catalogue.
 *
 * @package SHCM
 */

namespace SHCM\Archive;

use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Lists, inspects and deletes the archives in the storage directory.
 */
class Catalog {

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
	 * All archives, newest first.
	 *
	 * @return array[]
	 */
	public function all() {
		$dir = $this->storage->archives();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( Paths::trailingslash( $dir ) . '*.' . Format::EXTENSION );
		if ( ! is_array( $files ) ) {
			return array();
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		$archives = array();
		foreach ( $files as $file ) {
			$archives[] = $this->describe( $file );
		}
		return $archives;
	}

	/**
	 * Describe a single archive.
	 *
	 * @param string $path Absolute path.
	 * @return array
	 */
	public function describe( $path ) {
		$info = array(
			'name'      => basename( $path ),
			'path'      => $path,
			'size'      => (int) @filesize( $path ),
			'created'   => (int) @filemtime( $path ),
			'encrypted' => false,
			'complete'  => false,
			'source'    => '',
			'files'     => 0,
			'tables'    => 0,
			'wordpress' => '',
			'php'       => '',
		);

		$prologue = Reader::peek( $path );
		if ( null === $prologue ) {
			$info['error'] = __( 'Not a valid SH Clone Migration archive.', 'sh-clone-migration' );
			return $info;
		}
		$info['encrypted'] = ! empty( $prologue['encrypted'] );
		$info['created']   = isset( $prologue['created'] ) ? (int) $prologue['created'] : $info['created'];
		$info['source']    = isset( $prologue['source'] ) ? $prologue['source'] : '';

		// The footer is never encrypted, so it can be read without the password.
		$footer = Reader::readFooter( $path );
		try {
			if ( is_array( $footer ) ) {
				$info['complete'] = true;
				$info['files']    = isset( $footer['files'] ) ? (int) $footer['files'] : 0;
				$info['tables']   = isset( $footer['tables'] ) ? (int) $footer['tables'] : 0;
				$info['entries']  = isset( $footer['entries'] ) ? (int) $footer['entries'] : 0;
				if ( empty( $info['source'] ) && isset( $footer['source'] ) ) {
					$info['source'] = $footer['source'];
				}
				// Archives from 1.0.1 on record what was actually written.
				if ( isset( $footer['database'] ) && is_array( $footer['database'] ) ) {
					$info['database_contents'] = array(
						'included'  => ! empty( $footer['database']['included'] ),
						'prefix'    => isset( $footer['database']['prefix'] ) ? (string) $footer['database']['prefix'] : '',
						'tables'    => isset( $footer['database']['tables'] ) ? (int) $footer['database']['tables'] : 0,
						'rows'      => isset( $footer['database']['rows'] ) ? (int) $footer['database']['rows'] : 0,
						'sql_bytes' => isset( $footer['database']['sql_bytes'] ) ? (int) $footer['database']['sql_bytes'] : 0,
					);
				}
				if ( isset( $footer['groups'] ) && is_array( $footer['groups'] ) ) {
					$info['groups'] = $footer['groups'];
				}
				$info['files_skipped'] = isset( $footer['files_skipped'] ) ? (int) $footer['files_skipped'] : 0;
				$info['warnings']      = isset( $footer['warnings'] ) ? (int) $footer['warnings'] : 0;
				$info['sha256']        = $this->sha256( $path );
			}
		} catch ( \Exception $e ) {
			$info['error'] = $e->getMessage();
		}

		if ( ! $info['encrypted'] ) {
			$manifest = $this->manifest( $path );
			if ( is_array( $manifest ) ) {
				$info['wordpress'] = isset( $manifest['wordpress']['version'] ) ? $manifest['wordpress']['version'] : '';
				$info['php']       = isset( $manifest['php']['version'] ) ? $manifest['php']['version'] : '';
				$info['database']  = isset( $manifest['database']['server'] ) ? $manifest['database']['server'] : '';
				$info['source']    = isset( $manifest['site']['home'] ) ? $manifest['site']['home'] : $info['source'];
				// The manifest's file and table counts are what the scan
				// planned, written before anything was copied. They are never
				// shown as the contents: a complete archive's footer records
				// what was written, and an incomplete archive has no known
				// contents at all.
				$info['prefix']    = isset( $manifest['wordpress']['table_prefix'] ) ? $manifest['wordpress']['table_prefix'] : '';
				$info['multisite'] = ! empty( $manifest['wordpress']['multisite'] );
			}
		}

		return $info;
	}

	/**
	 * Read the manifest of an archive.
	 *
	 * @param string $path     Archive path.
	 * @param string $password Migration password.
	 * @return array|null
	 */
	public function manifest( $path, $password = '' ) {
		try {
			$reader = new Reader( $path, $password );
			$entry  = $reader->findEntry( Format::ENTRY_MANIFEST );
			if ( null === $entry ) {
				$reader->close();
				return null;
			}
			$manifest = \SHCM\Support\Json::decode( $reader->readString( $entry ) );
			$reader->close();
			return $manifest;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Resolve an archive name to a path inside the archive directory.
	 *
	 * @param string $name File name.
	 * @return string|null
	 */
	public function resolve( $name ) {
		$name = basename( (string) $name );
		if ( '' === $name || false !== strpos( $name, "\0" ) ) {
			return null;
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._\-]*\.' . Format::EXTENSION . '$/', $name ) ) {
			return null;
		}
		$path = Paths::trailingslash( $this->storage->archives() ) . $name;
		if ( ! is_file( $path ) ) {
			return null;
		}
		if ( ! Paths::isInside( Paths::normalize( $path ), $this->storage->archives() ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Path of the checksum file that sits next to an archive.
	 *
	 * The file uses the sha256sum format ("<hex>  <name>"), so it can be
	 * checked with `sha256sum -c` as well as read back here.
	 *
	 * @param string $path Archive path.
	 * @return string
	 */
	public static function checksumPath( $path ) {
		return $path . '.sha256';
	}

	/**
	 * Record the SHA-256 of a finished archive.
	 *
	 * @param string $path Archive path.
	 * @param string $hex  Lower case hex digest.
	 * @return bool
	 */
	public static function writeChecksum( $path, $hex ) {
		if ( ! preg_match( '/^[0-9a-f]{64}$/', (string) $hex ) ) {
			return false;
		}
		$line = $hex . '  ' . basename( $path ) . "\n";
		return strlen( $line ) === (int) @file_put_contents( self::checksumPath( $path ), $line, LOCK_EX );
	}

	/**
	 * SHA-256 of an archive, when one was recorded for its current contents.
	 *
	 * A checksum file older than the archive belongs to an earlier file with
	 * the same name and is ignored.
	 *
	 * @param string $path Archive path.
	 * @return string Hex digest, or an empty string.
	 */
	public function sha256( $path ) {
		$sidecar = self::checksumPath( $path );
		if ( ! is_file( $sidecar ) ) {
			return '';
		}
		clearstatcache( true, $sidecar );
		clearstatcache( true, $path );
		if ( (int) @filemtime( $sidecar ) < (int) @filemtime( $path ) ) {
			return '';
		}
		$line = (string) @file_get_contents( $sidecar, false, null, 0, 512 );
		if ( ! preg_match( '/^([0-9a-f]{64})\s+\*?(.+?)\s*$/', $line, $matches ) ) {
			return '';
		}
		return basename( $path ) === $matches[2] ? $matches[1] : '';
	}

	/**
	 * Delete an archive and its checksum file.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public function delete( $name ) {
		$path = $this->resolve( $name );
		if ( null === $path ) {
			return false;
		}
		$deleted = @unlink( $path );
		if ( $deleted && is_file( self::checksumPath( $path ) ) ) {
			@unlink( self::checksumPath( $path ) );
		}
		return $deleted;
	}

	/**
	 * Total size of all archives.
	 *
	 * @return int
	 */
	public function totalSize() {
		$total = 0;
		foreach ( $this->all() as $archive ) {
			$total += $archive['size'];
		}
		return $total;
	}
}
