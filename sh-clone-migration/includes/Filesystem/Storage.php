<?php
/**
 * Plugin owned storage directory.
 *
 * @package SHCM
 */

namespace SHCM\Filesystem;

use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Owns wp-content/shcm-storage and everything below it.
 *
 * The location is deterministic on purpose: an import replaces the whole
 * database, so the plugin cannot rely on an option to find its own working
 * directory half way through a restore.
 */
class Storage {

	const DIR_NAME = 'shcm-storage';

	/**
	 * Base directory.
	 *
	 * @var string
	 */
	protected $base;

	/**
	 * Constructor.
	 *
	 * @param string|null $base Optional override (tests, WP-CLI).
	 */
	public function __construct( $base = null ) {
		if ( null === $base ) {
			$base = Paths::contentDir() . '/' . self::DIR_NAME;
		}
		$this->base = Paths::normalize( $base );
	}

	/**
	 * Base directory.
	 *
	 * @return string
	 */
	public function base() {
		return $this->base;
	}

	/**
	 * Archive directory.
	 *
	 * @return string
	 */
	public function archives() {
		return $this->base . '/archives';
	}

	/**
	 * Job state directory.
	 *
	 * @return string
	 */
	public function jobs() {
		return $this->base . '/jobs';
	}

	/**
	 * Temporary working directory.
	 *
	 * @return string
	 */
	public function tmp() {
		return $this->base . '/tmp';
	}

	/**
	 * Log directory.
	 *
	 * @return string
	 */
	public function logs() {
		return $this->base . '/logs';
	}

	/**
	 * Incoming chunked upload directory.
	 *
	 * @return string
	 */
	public function incoming() {
		return $this->base . '/incoming';
	}

	/**
	 * Rollback point directory.
	 *
	 * @return string
	 */
	public function rollback() {
		return $this->base . '/rollback';
	}

	/**
	 * All managed sub directories.
	 *
	 * @return string[]
	 */
	public function directories() {
		return array(
			$this->base,
			$this->archives(),
			$this->jobs(),
			$this->tmp(),
			$this->logs(),
			$this->incoming(),
			$this->rollback(),
		);
	}

	/**
	 * Create the directory tree and its access protection files.
	 *
	 * @return bool True when every directory exists and is writable.
	 */
	public function prepare() {
		$ok = true;
		foreach ( $this->directories() as $dir ) {
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				$ok = false;
				continue;
			}
			if ( ! is_writable( $dir ) ) {
				$ok = false;
			}
		}
		$this->writeProtection();
		return $ok;
	}

	/**
	 * Write (or refresh) the web server access protection files.
	 *
	 * @return void
	 */
	public function writeProtection() {
		if ( ! is_dir( $this->base ) ) {
			return;
		}

		$htaccess = "# SH Clone Migration - deny all direct web access.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			. "Options -Indexes\n";
		$this->putIfChanged( $this->base . '/.htaccess', $htaccess );

		$webconfig = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n"
			. "\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n"
			. "\t</system.webServer>\n</configuration>\n";
		$this->putIfChanged( $this->base . '/web.config', $webconfig );

		foreach ( $this->directories() as $dir ) {
			if ( is_dir( $dir ) ) {
				$this->putIfChanged( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
		}
	}

	/**
	 * Write a file only when its contents differ.
	 *
	 * @param string $path     Target file.
	 * @param string $contents Contents.
	 * @return void
	 */
	protected function putIfChanged( $path, $contents ) {
		if ( is_file( $path ) && @file_get_contents( $path ) === $contents ) {
			return;
		}
		@file_put_contents( $path, $contents, LOCK_EX );
	}

	/**
	 * Free disk space on the storage volume.
	 *
	 * @return int Bytes, or -1 when the value cannot be determined.
	 */
	public function freeSpace() {
		$dir = is_dir( $this->base ) ? $this->base : Paths::contentDir();
		$fn  = 'disk_free_space';
		if ( ! function_exists( $fn ) ) {
			return -1;
		}
		$free = @$fn( $dir );
		return false === $free ? -1 : (int) $free;
	}

	/**
	 * Total disk space on the storage volume.
	 *
	 * @return int Bytes, or -1 when unknown.
	 */
	public function totalSpace() {
		$dir = is_dir( $this->base ) ? $this->base : Paths::contentDir();
		$fn  = 'disk_total_space';
		if ( ! function_exists( $fn ) ) {
			return -1;
		}
		$total = @$fn( $dir );
		return false === $total ? -1 : (int) $total;
	}

	/**
	 * Human readable free space.
	 *
	 * @return string
	 */
	public function freeSpaceForHumans() {
		$free = $this->freeSpace();
		return $free < 0 ? __( 'unknown', 'sh-clone-migration' ) : Bytes::format( $free );
	}

	/**
	 * Recursively delete a directory that must live inside the storage base.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public function deleteDirectory( $dir ) {
		$dir = Paths::normalize( $dir );
		if ( ! Paths::isInside( $dir, $this->base ) || $dir === $this->base ) {
			return false;
		}
		return self::rmdirRecursive( $dir );
	}

	/**
	 * Recursive directory removal helper.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public static function rmdirRecursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return ! file_exists( $dir ) ? true : @unlink( $dir );
		}
		$items = @scandir( $dir );
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rmdirRecursive( $path );
			} else {
				@unlink( $path );
			}
		}
		return @rmdir( $dir );
	}

	/**
	 * Generate a collision free, unguessable file name inside a directory.
	 *
	 * @param string $dir       Directory.
	 * @param string $prefix    Name prefix.
	 * @param string $extension Extension without the dot.
	 * @return string Absolute path.
	 */
	public function uniquePath( $dir, $prefix, $extension ) {
		do {
			$name = $prefix . '-' . bin2hex( random_bytes( 8 ) ) . '.' . $extension;
			$path = Paths::trailingslash( $dir ) . $name;
		} while ( file_exists( $path ) );
		return $path;
	}
}
