<?php
/**
 * Job persistence.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Stores jobs as JSON files inside the plugin storage directory.
 *
 * Writes go to a temporary file and are renamed into place, so a job file is
 * never observed half written even if the request dies during the save.
 */
class JobStore {

	/**
	 * Storage helper.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage Storage helper.
	 */
	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Path of a job file.
	 *
	 * @param string $id Job id.
	 * @return string
	 */
	public function path( $id ) {
		return Paths::trailingslash( $this->storage->jobs() ) . $this->sanitizeId( $id ) . '.json';
	}

	/**
	 * Sanitise a job id.
	 *
	 * @param string $id Raw id.
	 * @return string
	 */
	public function sanitizeId( $id ) {
		$clean = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $id );
		return substr( (string) $clean, 0, 64 );
	}

	/**
	 * Persist a job.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function save( Job $job ) {
		$job->set( 'updated_at', time() );
		$path = $this->path( $job->id() );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			$this->storage->prepare();
		}
		$tmp = $path . '.' . getmypid() . '.tmp';
		$ok  = false !== @file_put_contents( $tmp, Json::encode( $job->toArray(), true ), LOCK_EX );
		if ( ! $ok ) {
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}

	/**
	 * Load a job.
	 *
	 * @param string $id Job id.
	 * @return Job|null
	 */
	public function load( $id ) {
		$path = $this->path( $id );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$data = Json::decode( (string) @file_get_contents( $path ) );
		if ( null === $data || empty( $data['id'] ) ) {
			return null;
		}
		return new Job( $data );
	}

	/**
	 * Delete a job file and its log.
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public function delete( $id ) {
		$path = $this->path( $id );
		$log  = Paths::trailingslash( $this->storage->logs() ) . $this->sanitizeId( $id ) . '.log';
		if ( is_file( $log ) ) {
			@unlink( $log );
		}
		return is_file( $path ) ? @unlink( $path ) : true;
	}

	/**
	 * All jobs, newest first.
	 *
	 * @param string|null $type   Optional type filter.
	 * @param int         $limit  Maximum number of jobs.
	 * @return Job[]
	 */
	public function all( $type = null, $limit = 50 ) {
		$dir = $this->storage->jobs();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( Paths::trailingslash( $dir ) . '*.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		$jobs = array();
		foreach ( $files as $file ) {
			$data = Json::decode( (string) @file_get_contents( $file ) );
			if ( null === $data || empty( $data['id'] ) ) {
				continue;
			}
			if ( null !== $type && ( ! isset( $data['type'] ) || $data['type'] !== $type ) ) {
				continue;
			}
			$jobs[] = new Job( $data );
			if ( count( $jobs ) >= $limit ) {
				break;
			}
		}
		return $jobs;
	}

	/**
	 * The most recent job that can still be resumed.
	 *
	 * @param string|null $type Optional type filter.
	 * @return Job|null
	 */
	public function findResumable( $type = null ) {
		foreach ( $this->all( $type, 20 ) as $job ) {
			if ( $job->isRunnable() && Job::STATUS_PENDING !== $job->status() ) {
				return $job;
			}
			if ( $job->isRunnable() && Job::STATUS_PENDING === $job->status() ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * Remove job files older than a number of days.
	 *
	 * @param int $days Retention in days. 0 disables the cleanup.
	 * @return int Number of jobs removed.
	 */
	public function purgeOlderThan( $days ) {
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$removed = 0;
		foreach ( $this->all( null, 1000 ) as $job ) {
			if ( $job->isFinished() && (int) $job->get( 'updated_at' ) < $cutoff ) {
				$this->delete( $job->id() );
				++$removed;
			}
		}
		return $removed;
	}
}
