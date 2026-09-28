<?php
/**
 * Per-job advisory lock.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Makes sure only one request advances a given job at a time.
 *
 * The lock is an flock() on jobs/<id>.lock. Browser ticks, the WP-Cron worker,
 * loopback requests and WP-CLI may all try to advance the same job; two of
 * them writing the same archive corrupt it. flock() is released by the
 * operating system when the process dies, so a killed request never leaves a
 * job locked forever, which is why a lease or heartbeat is not needed.
 *
 * Locks belong to an open file handle (an open file description on Linux),
 * not to a process: two JobLock instances in the same process exclude each
 * other exactly like two processes do.
 *
 * Lock files are left in place on release. Deleting a lock file while another
 * request has it open would let two requests lock two different inodes of
 * the "same" lock; stale files are removed by purgeStale() instead, for jobs
 * whose job file is gone.
 *
 * The class also owns the other per-job coordination file, the cancel marker
 * jobs/<id>.cancel (see requestCancel()).
 */
class JobLock {

	/**
	 * Jobs directory.
	 *
	 * @var string
	 */
	protected $directory;

	/**
	 * Open, locked handles by job id.
	 *
	 * @var array<string, resource>
	 */
	protected $handles = array();

	/**
	 * Constructor.
	 *
	 * @param string $directory Jobs directory (Storage::jobs()).
	 */
	public function __construct( $directory ) {
		$this->directory = rtrim( str_replace( '\\', '/', (string) $directory ), '/' );
	}

	/**
	 * Release every lock this instance still holds.
	 */
	public function __destruct() {
		foreach ( array_keys( $this->handles ) as $job_id ) {
			$this->release( (string) $job_id );
		}
	}

	/**
	 * Jobs directory.
	 *
	 * @return string
	 */
	public function directory() {
		return $this->directory;
	}

	/**
	 * Whether a string is a job id this class will build a path from.
	 *
	 * Same character rule as JobStore::sanitizeId(), but an id that would
	 * need sanitising is refused rather than silently mapped to another one.
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	public static function isValidId( $job_id ) {
		if ( ! is_string( $job_id ) || '' === $job_id || strlen( $job_id ) > 64 ) {
			return false;
		}
		return 1 === preg_match( '/^[A-Za-z0-9\-]+$/', $job_id );
	}

	/**
	 * Path of a job's lock file.
	 *
	 * @param string $directory Jobs directory.
	 * @param string $job_id    Job id (validated by the caller).
	 * @return string
	 */
	protected static function lockPath( $directory, $job_id ) {
		return rtrim( str_replace( '\\', '/', (string) $directory ), '/' ) . '/' . $job_id . '.lock';
	}

	/**
	 * Path of a job's cancel marker.
	 *
	 * @param string $directory Jobs directory.
	 * @param string $job_id    Job id (validated by the caller).
	 * @return string
	 */
	protected static function cancelPath( $directory, $job_id ) {
		return rtrim( str_replace( '\\', '/', (string) $directory ), '/' ) . '/' . $job_id . '.cancel';
	}

	/**
	 * Ask the request advancing a job to cancel it.
	 *
	 * The request is a marker file next to the job file, never a change to
	 * the job file itself: the request holding the lock rewrites that file
	 * after every step, so a status written by anyone else between its check
	 * and its save would be lost. Nobody but the canceller writes the marker,
	 * so it survives until the holder (or the next tick, if the holder died)
	 * carries the cancel out and calls clearCancel().
	 *
	 * @param string $directory Jobs directory.
	 * @param string $job_id    Job id.
	 * @return bool Whether the marker is in place.
	 */
	public static function requestCancel( $directory, $job_id ) {
		if ( ! self::isValidId( $job_id ) ) {
			return false;
		}
		$path = self::cancelPath( $directory, $job_id );
		if ( self::fileExists( $path ) ) {
			return true;
		}
		// Written under a temporary name and renamed into place, so the
		// marker is never seen half written (its content is the request time).
		$tmp = $path . '.' . getmypid() . '-' . str_replace( '.', '', uniqid( '', true ) ) . '.tmp';
		if ( false === @file_put_contents( $tmp, (string) time() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return self::fileExists( $path );
		}
		return true;
	}

	/**
	 * Whether a cancel was requested for a job and not carried out yet.
	 *
	 * @param string $directory Jobs directory.
	 * @param string $job_id    Job id.
	 * @return bool
	 */
	public static function cancelRequested( $directory, $job_id ) {
		if ( ! self::isValidId( $job_id ) ) {
			return false;
		}
		return self::fileExists( self::cancelPath( $directory, $job_id ) );
	}

	/**
	 * Remove a job's cancel marker (the cancel was carried out, or came too
	 * late because the job had finished).
	 *
	 * @param string $directory Jobs directory.
	 * @param string $job_id    Job id.
	 * @return bool Whether no marker is left.
	 */
	public static function clearCancel( $directory, $job_id ) {
		if ( ! self::isValidId( $job_id ) ) {
			return true;
		}
		$path = self::cancelPath( $directory, $job_id );
		if ( ! self::fileExists( $path ) ) {
			return true;
		}
		return @unlink( $path ) || ! self::fileExists( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Whether a file exists, asking the filesystem rather than PHP's stat
	 * cache: markers are created and removed by other processes.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	protected static function fileExists( $path ) {
		clearstatcache( true, $path );
		return is_file( $path );
	}

	/**
	 * Take the lock for a job without waiting.
	 *
	 * Idempotent: acquiring a lock this instance already holds returns true.
	 * When the lock file cannot be opened at all (read-only filesystem,
	 * missing directory) or flock() is not supported, this returns true
	 * without holding anything, which is the behaviour before locking existed;
	 * isHeld() then reports false.
	 *
	 * @param string $job_id Job id.
	 * @return bool False when another handle holds the lock, or the id is invalid.
	 */
	public function acquire( $job_id ) {
		if ( ! self::isValidId( $job_id ) ) {
			return false;
		}
		if ( isset( $this->handles[ $job_id ] ) ) {
			return true;
		}

		$path = self::lockPath( $this->directory, $job_id );

		// purgeStale() may unlink the file between our fopen() and flock();
		// the lock would then be on an orphaned inode that the next request
		// does not see. Retry on the file that is actually at the path.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$handle = @fopen( $path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $handle ) {
				// Created by another system account (WP-CLI run as a different
				// user than PHP): flock() does not need write access.
				$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( false === $handle ) {
				return true;
			}

			$would_block = 0;
			if ( ! @flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( $would_block ) {
					return false;
				}
				// flock() itself is unsupported here (some network filesystems).
				return true;
			}

			if ( self::sameFile( $handle, $path ) ) {
				$this->handles[ $job_id ] = $handle;
				return true;
			}

			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		// The file keeps being replaced under us: treat it as contended.
		return false;
	}

	/**
	 * Release a job's lock (no-op when this instance does not hold it).
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function release( $job_id ) {
		$job_id = (string) $job_id;
		if ( ! isset( $this->handles[ $job_id ] ) ) {
			return;
		}
		$handle = $this->handles[ $job_id ];
		unset( $this->handles[ $job_id ] );
		if ( is_resource( $handle ) ) {
			@flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Whether this instance holds a real lock on the job.
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	public function isHeld( $job_id ) {
		return isset( $this->handles[ (string) $job_id ] );
	}

	/**
	 * Whether any handle (this process or another) holds a job's lock.
	 *
	 * A probe: it takes and drops a shared lock on a fresh handle. An id this
	 * class refuses to lock is reported as locked, so the worker skips a job
	 * that tick() could never advance. A missing or unopenable lock file
	 * means "not locked".
	 *
	 * @param string $directory Jobs directory.
	 * @param string $job_id    Job id.
	 * @return bool
	 */
	public static function isLocked( $directory, $job_id ) {
		if ( ! self::isValidId( $job_id ) ) {
			return true;
		}
		$path = self::lockPath( $directory, $job_id );
		if ( ! is_file( $path ) ) {
			return false;
		}
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return false;
		}
		$would_block = 0;
		$locked      = false;
		if ( @flock( $handle, LOCK_SH | LOCK_NB, $would_block ) ) {
			flock( $handle, LOCK_UN );
		} elseif ( $would_block ) {
			$locked = true;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $locked;
	}

	/**
	 * Delete lock files whose job file no longer exists and nobody holds,
	 * and cancel markers whose job file no longer exists.
	 *
	 * JobStore::delete() does not know about these files, so the daily
	 * housekeeping calls this.
	 *
	 * @param string $directory Jobs directory.
	 * @return int Files removed.
	 */
	public static function purgeStale( $directory ) {
		$directory = rtrim( str_replace( '\\', '/', (string) $directory ), '/' );
		if ( ! is_dir( $directory ) ) {
			return 0;
		}
		$items = glob( $directory . '/*.lock' );
		if ( ! is_array( $items ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $items as $path ) {
			$job_id = basename( $path, '.lock' );
			if ( ! self::isValidId( $job_id ) || is_file( $directory . '/' . $job_id . '.json' ) ) {
				continue;
			}
			$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $handle ) {
				continue;
			}
			// Unlink while holding the exclusive lock: a request that locked
			// the file first makes this fail, and acquire() re-checks the
			// inode for one that opened it just before the unlink.
			if ( @flock( $handle, LOCK_EX | LOCK_NB ) ) {
				if ( @unlink( $path ) ) {
					++$removed;
				}
				flock( $handle, LOCK_UN );
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		// A cancel marker only means something while its job exists. Job ids
		// are never reused, so an orphan cannot cancel a later job.
		$markers = glob( $directory . '/*.cancel' );
		foreach ( is_array( $markers ) ? $markers : array() as $path ) {
			$job_id = basename( $path, '.cancel' );
			if ( ! self::isValidId( $job_id ) || is_file( $directory . '/' . $job_id . '.json' ) ) {
				continue;
			}
			if ( @unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				++$removed;
			}
		}

		// Temporary files of a marker write that died before its rename.
		$partial = glob( $directory . '/*.cancel.*.tmp' );
		foreach ( is_array( $partial ) ? $partial : array() as $path ) {
			if ( (int) @filemtime( $path ) < time() - 3600 && @unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Whether an open handle still refers to the file at a path.
	 *
	 * @param resource $handle Open handle.
	 * @param string   $path   Path.
	 * @return bool
	 */
	protected static function sameFile( $handle, $path ) {
		clearstatcache( true, $path );
		$on_disk = @stat( $path );
		if ( false === $on_disk ) {
			return false;
		}
		$open = @fstat( $handle );
		if ( false === $open ) {
			return true;
		}
		// Some platforms report no inode numbers (0); nothing to compare then.
		if ( empty( $on_disk['ino'] ) || empty( $open['ino'] ) ) {
			return true;
		}
		return $on_disk['ino'] === $open['ino'] && $on_disk['dev'] === $open['dev'];
	}
}
