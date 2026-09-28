<?php
/**
 * Export: apply the backup retention policy.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Archive\Catalog;
use SHCM\Backup\BackupManager;
use SHCM\Core\Settings;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\Remote\GoogleDrive\DriveException;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the newest N backups on this server and on Google Drive.
 *
 * Only backups count: manual exports, uploaded archives and archives sent to
 * Drive by hand are never deleted here. On Drive only files this site
 * uploaded as backups are considered (their private appProperties say so),
 * never "whatever is in the folder". A local backup whose Drive upload failed
 * is the only copy, so retention leaves it alone.
 */
class BackupRetentionStage extends AbstractStage {

	/**
	 * Backups.
	 *
	 * @var BackupManager
	 */
	protected $backups;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param Storage       $storage  Storage.
	 * @param Logger        $logger   Logger.
	 * @param BackupManager $backups  Backups.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger, BackupManager $backups ) {
		parent::__construct( $settings, $storage, $logger );
		$this->backups = $backups;
	}

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'retention';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Removing old backups', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 2;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		unset( $budget );
		$backup = (array) $job->param( 'backup', array() );
		$state  = (array) $job->stageState( $this->key(), array() );
		if ( ! empty( $state['done'] ) || ! isset( $backup['kind'] ) || 'backup' !== $backup['kind'] ) {
			return $this->complete( __( 'Retention applied', 'sh-clone-migration' ) );
		}

		$history = $this->backups->history();
		$path    = (string) $job->param( 'archive_path' );
		$current = basename( $path );
		$entry   = $job->param( 'upload_only' ) ? (string) $job->param( 'history_id', '' ) : $job->id();
		if ( '' !== $entry && '' !== $current ) {
			// The run's own entry learns its archive name now, so that the
			// local pass below counts it.
			$history->record( $entry, array( 'archive' => $current ) );
		}

		$remote  = (array) $job->shared( 'remote_upload', array() );
		$removed = array(
			'remote' => 0,
			'local'  => 0,
		);

		if ( ! empty( $backup['gdrive'] ) && isset( $remote['status'] ) && 'uploaded' === $remote['status'] ) {
			$removed['remote'] = $this->pruneRemote( $job, (int) $backup['keep_remote'], (string) $remote['file_id'], (string) $remote['folder_id'] );
		}
		$removed['local'] = $this->pruneLocal( $job, (int) $backup['keep_local'], $current, ! empty( $backup['gdrive'] ), $remote );

		$state['done']    = true;
		$state['removed'] = $removed;
		$job->setStageState( $this->key(), $state );

		if ( $removed['remote'] + $removed['local'] > 0 ) {
			$this->logger->info( sprintf( 'Retention: removed %1$d old backup(s) from Google Drive and %2$d from this server.', $removed['remote'], $removed['local'] ) );
		}
		return $this->complete( __( 'Retention applied', 'sh-clone-migration' ) );
	}

	/**
	 * Delete the oldest backups on Drive beyond the ones to keep.
	 *
	 * @param Job    $job      Job.
	 * @param int    $keep     Backups to keep on Drive.
	 * @param string $current  File id just uploaded (always kept).
	 * @param string $folder   Folder id.
	 * @return int Files deleted.
	 */
	protected function pruneRemote( Job $job, $keep, $current, $folder ) {
		$connection = $this->backups->connection();
		if ( ! $connection->isUsable() || '' === $folder ) {
			return 0;
		}
		$keep = max( 1, (int) $keep );
		try {
			$files = $this->backups->drive()->listBackups( $folder, $connection->siteId(), 'backup' );
		} catch ( DriveException $e ) {
			$job->addWarning( sprintf( __( 'Old backups on Google Drive were not cleaned up: %s', 'sh-clone-migration' ), $e->getMessage() ) );
			return 0;
		}

		// Newest backup first, by when the backup was made (shcm_time), not
		// when it reached Drive: a retried upload of an old backup is still an
		// old backup and must not push newer ones out. The file just uploaded
		// comes first among copies of the same moment.
		$made = static function ( array $file ) {
			$time = isset( $file['app']['shcm_time'] ) ? (string) $file['app']['shcm_time'] : '';
			return '' !== $time && ctype_digit( $time ) ? (int) $time : (int) $file['created'];
		};
		usort(
			$files,
			static function ( $a, $b ) use ( $made, $current ) {
				$order = $made( $b ) - $made( $a );
				if ( 0 !== $order ) {
					return $order;
				}
				if ( (string) $a['id'] === $current || (string) $b['id'] === $current ) {
					return (string) $a['id'] === $current ? -1 : 1;
				}
				return (int) $b['created'] - (int) $a['created'];
			}
		);

		$kept    = 0;
		$deleted = 0;
		$seen    = array();
		$history = $this->backups->history();
		foreach ( $files as $file ) {
			$id        = (string) $file['id'];
			$backup_id = isset( $file['app']['shcm_job'] ) ? (string) $file['app']['shcm_job'] : '';
			// The same backup sent twice: the extra copy does not count as
			// another backup, it only takes space.
			$duplicate = '' !== $backup_id && isset( $seen[ $backup_id ] ) && $id !== $current;
			if ( ! $duplicate && ( $id === $current || $kept < $keep ) ) {
				++$kept;
				if ( '' !== $backup_id ) {
					$seen[ $backup_id ] = true;
				}
				continue;
			}
			try {
				$this->backups->drive()->deleteFile( $id );
				++$deleted;
				$this->logger->info( sprintf( 'Retention: deleted %1$s from Google Drive (file id %2$s).', $file['name'], $id ) );
				foreach ( $history->all( \SHCM\Backup\History::MAX ) as $entry ) {
					if ( isset( $entry['remote']['file_id'] ) && $entry['remote']['file_id'] === $id ) {
						$history->record(
							(string) $entry['id'],
							array(
								'remote' => array(
									'status'  => 'deleted',
									'deleted' => time(),
								),
							)
						);
					}
				}
			} catch ( DriveException $e ) {
				$job->addWarning( sprintf( __( 'An old backup on Google Drive could not be deleted (%1$s): %2$s', 'sh-clone-migration' ), $file['name'], $e->getMessage() ) );
			}
		}
		return $deleted;
	}

	/**
	 * Delete the oldest local backups beyond the ones to keep.
	 *
	 * @param Job    $job     Job.
	 * @param int    $keep    Backups to keep on this server (0 = none once on Drive).
	 * @param string $current Archive of this run.
	 * @param bool   $gdrive  Whether backups go to Drive.
	 * @param array  $remote  This run's upload result.
	 * @return int Archives deleted.
	 */
	protected function pruneLocal( Job $job, $keep, $current, $gdrive, array $remote ) {
		$catalog   = new Catalog( $this->storage );
		$history   = $this->backups->history();
		$protected = $this->archivesInUse( $job );
		$names     = $history->archiveNames();
		if ( '' !== $current && ! in_array( $current, $names, true ) ) {
			array_unshift( $names, $current );
		}

		$candidates = array();
		foreach ( $names as $name ) {
			$path = $catalog->resolve( $name );
			if ( null === $path ) {
				continue;
			}
			$entry = $name === $current ? null : $history->forArchive( $name );
			if ( null !== $entry && isset( $entry['local'] ) && is_array( $entry['local'] ) && ( ( isset( $entry['local']['kept'] ) && false === $entry['local']['kept'] ) || ! empty( $entry['local']['deleted'] ) ) ) {
				// This backup's own file was deleted; a file of that name now is
				// one somebody put back (to restore it): not ours to delete.
				continue;
			}
			$candidates[ $name ] = (int) @filemtime( $path );
		}
		if ( isset( $candidates[ $current ] ) && ! $job->param( 'upload_only' ) ) {
			// The run's own archive always counts first, whatever its mtime
			// says after the clock was stepped back. (A retried upload of an
			// older backup keeps its place by age.)
			$candidates[ $current ] = PHP_INT_MAX;
		}
		arsort( $candidates );

		$uploaded   = isset( $remote['status'] ) && 'uploaded' === $remote['status'];
		$kept       = 0;
		$deleted    = 0;
		$stranded   = 0;
		$local_only = 0;
		foreach ( array_keys( $candidates ) as $name ) {
			if ( in_array( $name, $protected, true ) ) {
				continue;
			}
			$entry  = $history->forArchive( $name );
			$status = isset( $entry['remote']['status'] ) ? (string) $entry['remote']['status'] : '';
			if ( $name === $current ) {
				$safe = ! $gdrive || $uploaded;
			} else {
				// Judged by what happened to that backup, not by this run's
				// settings: a local-only run must not delete the only copy of
				// a backup whose upload failed. With "keep 0 on this server",
				// only backups that have a copy on Drive go.
				$safe = in_array( $status, array( 'uploaded', 'deleted' ), true ) || ( 'off' === $status && (int) $keep > 0 );
			}
			if ( $kept < (int) $keep || ! $safe ) {
				if ( ! $safe && $kept >= (int) $keep && $name !== $current ) {
					if ( 'off' === $status ) {
						++$local_only;
					} else {
						++$stranded;
					}
				}
				++$kept;
				continue;
			}
			if ( $catalog->delete( $name ) ) {
				++$deleted;
				$this->logger->info( sprintf( 'Retention: deleted %s from this server.', $name ) );
				if ( null !== $entry ) {
					$history->record(
						(string) $entry['id'],
						array(
							'local' => array(
								'kept'    => false,
								'deleted' => time(),
							),
						)
					);
				}
			}
		}
		if ( $stranded > 0 ) {
			$job->addWarning(
				sprintf(
					/* translators: %d: number of backups */
					_n( '%d older backup exists only on this server because it was not uploaded to Google Drive; it was not deleted. Retry its upload from the history.', '%d older backups exist only on this server because they were not uploaded to Google Drive; they were not deleted. Retry their uploads from the history.', $stranded, 'sh-clone-migration' ),
					$stranded
				)
			);
		}
		if ( $local_only > 0 ) {
			$job->addWarning(
				sprintf(
					/* translators: %d: number of backups */
					_n( '%d older backup was made before Google Drive was used and has no copy there; it was kept although "0 on this server" is set. Delete it on the Backups screen when you no longer need it.', '%d older backups were made before Google Drive was used and have no copy there; they were kept although "0 on this server" is set. Delete them on the Backups screen when you no longer need them.', $local_only, 'sh-clone-migration' ),
					$local_only
				)
			);
		}
		return $deleted;
	}

	/**
	 * Archives other runnable jobs are working with.
	 *
	 * @param Job $job This job.
	 * @return string[] Base names.
	 */
	protected function archivesInUse( Job $job ) {
		$names = array();
		foreach ( shcm_bootstrap()->jobs()->all( null, 50 ) as $other ) {
			if ( $other->id() !== $job->id() && $other->isRunnable() ) {
				$path = (string) $other->param( 'archive_path', '' );
				if ( '' !== $path ) {
					$names[] = basename( $path );
				}
			}
		}
		return $names;
	}
}
