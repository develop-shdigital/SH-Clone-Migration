<?php
/**
 * Configuration files in the storage directory.
 *
 * @package SHCM
 */

namespace SHCM\Backup;

use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Small JSON documents kept in wp-content/shcm-storage/config/.
 *
 * The backup schedule, the Google Drive connection and the backup history
 * deliberately do not live in wp_options: the options table travels inside
 * every archive and is replaced by every import, so a staging copy would
 * otherwise inherit production's schedule and Drive access. The storage
 * directory is never exported and never overwritten by an import.
 *
 * Each file starts with "<?php exit; ?>" so that a web server ignoring the
 * directory's .htaccess runs an empty script instead of serving the JSON.
 */
final class ConfigStore {

	const GUARD = "<?php exit; ?>\n";

	/**
	 * Directory.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Constructor.
	 *
	 * @param string $directory Directory (created on first write).
	 */
	public function __construct( $directory ) {
		$this->directory = rtrim( str_replace( '\\', '/', (string) $directory ), '/' );
	}

	/**
	 * Directory.
	 *
	 * @return string
	 */
	public function directory() {
		return $this->directory;
	}

	/**
	 * Path of a document.
	 *
	 * @param string $name Document name.
	 * @return string
	 * @throws \InvalidArgumentException When the name is not a plain slug.
	 */
	public function path( $name ) {
		// \z, not $: "$" also matches before a trailing newline.
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}\z/', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid configuration document name.' );
		}
		return $this->directory . '/' . $name . '.php';
	}

	/**
	 * Read a document.
	 *
	 * Check readable() before acting on an empty result: a document that
	 * exists but belongs to another system account (WP-CLI run as root, PHP
	 * as www-data) also reads as empty here.
	 *
	 * @param string $name Document name.
	 * @return array Empty when missing, unreadable or damaged.
	 */
	public function read( $name ) {
		$path = $this->path( $name );
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			return array();
		}
		$raw = @file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			return array();
		}
		if ( 0 === strpos( $raw, self::GUARD ) ) {
			$raw = substr( $raw, strlen( self::GUARD ) );
		}
		$data = Json::decode( $raw );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Whether this process can read a document: it does not exist yet, or it
	 * exists and is readable. False means the stored data is there but out
	 * of reach, so it must not be mistaken for "nothing configured".
	 *
	 * @param string $name Document name.
	 * @return bool
	 */
	public function readable( $name ) {
		$path = $this->path( $name );
		clearstatcache( true, $path );
		return ! file_exists( $path ) || is_readable( $path );
	}

	/**
	 * Problems that stop this process from using the stored configuration,
	 * as sentences for the admin (empty when there are none).
	 *
	 * @param string[] $names Documents to check.
	 * @return string[]
	 */
	public function problems( array $names ) {
		$problems = array();
		foreach ( $names as $name ) {
			if ( ! $this->readable( $name ) ) {
				$problems[] = sprintf(
					/* translators: 1: file path, 2: system user name */
					__( '%1$s cannot be read by the web server (it belongs to %2$s). Run WP-CLI as the web server user, or make the file readable for it.', 'sh-clone-migration' ),
					$this->path( $name ),
					self::owner( $this->path( $name ) )
				);
			}
		}
		if ( is_dir( $this->directory ) && ! is_writable( $this->directory ) ) {
			$problems[] = sprintf(
				/* translators: 1: directory path, 2: system user name */
				__( '%1$s is not writable by the web server (it belongs to %2$s), so settings cannot be saved.', 'sh-clone-migration' ),
				$this->directory,
				self::owner( $this->directory )
			);
		}
		return $problems;
	}

	/**
	 * Mode for new files: WordPress's FS_CHMOD_FILE, else 0644. The sealed
	 * values inside cannot be opened without wp-config.php; what matters is
	 * that WP-CLI and PHP, often different system accounts, can both read
	 * what the other wrote.
	 *
	 * @return int
	 */
	private static function fileMode() {
		return defined( 'FS_CHMOD_FILE' ) ? ( (int) FS_CHMOD_FILE & 0666 ) | 0644 : 0644;
	}

	/**
	 * Owner of a file, for messages.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function owner( $path ) {
		$uid = @fileowner( $path );
		if ( false === $uid ) {
			return '?';
		}
		if ( function_exists( 'posix_getpwuid' ) ) {
			$info = @posix_getpwuid( $uid );
			if ( is_array( $info ) && isset( $info['name'] ) ) {
				return (string) $info['name'];
			}
		}
		return 'uid ' . $uid;
	}

	/**
	 * Replace a document atomically.
	 *
	 * @param string $name Document name.
	 * @param array  $data Data.
	 * @return bool
	 */
	public function write( $name, array $data ) {
		$path = $this->path( $name );
		if ( ! $this->ensureDirectory() ) {
			return false;
		}
		$tmp = $path . '.' . getmypid() . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$ok  = false !== @file_put_contents( $tmp, self::GUARD . Json::encode( $data, true ), LOCK_EX );
		if ( ! $ok ) {
			@unlink( $tmp );
			return false;
		}
		@chmod( $tmp, self::fileMode() );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		clearstatcache( true, $path );
		return true;
	}

	/**
	 * Read, change and write a document under an exclusive lock, so that two
	 * requests (a cron run and an admin saving the form) cannot lose each
	 * other's changes.
	 *
	 * @param string   $name    Document name.
	 * @param callable $mutator Receives the current data, returns the new data.
	 * @return array The data written.
	 * @throws \RuntimeException When the document cannot be written.
	 */
	public function update( $name, callable $mutator ) {
		$path = $this->path( $name );
		if ( ! $this->ensureDirectory() ) {
			throw new \RuntimeException( 'The configuration directory is not writable: ' . $this->directory );
		}
		$lock = $this->openLock( $path . '.lock' );
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}
		try {
			if ( ! $this->readable( $name ) ) {
				// Writing now would replace the stored settings with an
				// empty document plus this change.
				throw new \RuntimeException( implode( ' ', $this->problems( array( $name ) ) ) );
			}
			$data = $mutator( $this->read( $name ) );
			if ( ! is_array( $data ) ) {
				throw new \RuntimeException( 'A configuration update must return an array.' );
			}
			if ( ! $this->write( $name, $data ) ) {
				throw new \RuntimeException( 'The configuration could not be saved to ' . $path );
			}
			return $data;
		} finally {
			if ( $lock ) {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
	}

	/**
	 * Delete a document.
	 *
	 * The lock file stays: removing it while another request holds or waits
	 * for the lock would let a third request lock a new file of the same
	 * name, and two read-modify-write cycles would then overlap. Like
	 * update(), it must not be called from inside an update() mutator for
	 * the same document: the lock is not re-entrant.
	 *
	 * @param string $name Document name.
	 * @return void
	 */
	public function delete( $name ) {
		$path = $this->path( $name );
		$lock = is_file( $path . '.lock' ) ? $this->openLock( $path . '.lock' ) : false;
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
		clearstatcache( true, $path );
		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Open (or create) a lock file. A lock file created by another system
	 * account may not be writable here; flock() works on a read-only handle.
	 *
	 * @param string $path Lock file.
	 * @return resource|false
	 */
	private function openLock( $path ) {
		$created = ! file_exists( $path );
		$handle  = @fopen( $path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( $created ) {
			@chmod( $path, self::fileMode() );
		}
		return $handle;
	}

	/**
	 * Create the directory with its web server protection.
	 *
	 * @return bool
	 */
	private function ensureDirectory() {
		if ( ! is_dir( $this->directory ) && ! @mkdir( $this->directory, 0755, true ) && ! is_dir( $this->directory ) ) {
			return false;
		}
		if ( ! is_file( $this->directory . '/index.php' ) ) {
			@file_put_contents( $this->directory . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! is_file( $this->directory . '/.htaccess' ) ) {
			@file_put_contents(
				$this->directory . '/.htaccess',
				"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
			);
		}
		return is_writable( $this->directory );
	}
}
