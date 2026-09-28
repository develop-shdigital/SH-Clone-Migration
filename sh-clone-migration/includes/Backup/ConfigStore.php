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
	 * @param string $name Document name.
	 * @return array Empty when missing or unreadable.
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
		@chmod( $tmp, 0640 );
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
		$lock = @fopen( $path . '.lock', 'c' );
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}
		try {
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
		$lock = is_file( $path . '.lock' ) ? @fopen( $path . '.lock', 'c' ) : false;
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
