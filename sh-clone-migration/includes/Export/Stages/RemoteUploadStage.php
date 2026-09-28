<?php
/**
 * Export: upload the finished archive to Google Drive.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Archive\Catalog;
use SHCM\Archive\FileDigest;
use SHCM\Archive\Reader;
use SHCM\Backup\BackupManager;
use SHCM\Backup\SiteIdentity;
use SHCM\Core\Settings;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\Remote\GoogleDrive\DriveException;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a verified archive to Google Drive with a resumable upload, a few
 * megabytes per request, and checks the size and checksum Drive reports.
 *
 * The stage runs only after the archive is final (after verify), so the
 * bytes can no longer change. The upload session URI is itself a credential,
 * so it is kept sealed in the job state. Every call resumes from what Drive
 * says it has, not from what this side believes it sent.
 *
 * In a backup job a permanent failure does not fail the job: the local
 * backup is good, and throwing it away because Drive was unreachable would
 * make things worse. The failure is recorded, reported and can be retried.
 */
class RemoteUploadStage extends AbstractStage {

	const UNIT            = 262144;
	const CHUNK           = 8388608;
	const MAX_CHUNK       = 67108864;
	const MAX_RESTARTS    = 3;
	const SESSION_MAX_AGE = 518400;
	const VERIFY_ATTEMPTS = 6;
	const SESSION_CONTEXT = 'gdrive-upload-session';

	/**
	 * Seconds to wait before retry number n (1-based).
	 *
	 * @var int[]
	 */
	protected static $backoff = array( 30, 60, 120, 240, 480, 960 );

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
		return 'upload';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Uploading to Google Drive', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 25;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		$state = array_merge(
			array(
				'phase'    => 'prepare',
				'offset'   => 0,
				'restarts' => 0,
				'retries'  => 0,
				'stalls'   => 0,
				'verify'   => 0,
				'query'    => false,
			),
			(array) $job->stageState( $this->key(), array() )
		);
		if ( 'done' === $state['phase'] ) {
			return $this->complete( __( 'Upload finished', 'sh-clone-migration' ) );
		}

		$wait = (int) $job->shared( 'resume_at', 0 );
		if ( $wait > time() ) {
			return $this->waiting( $this->retryMessage( $wait - time() ), $this->fraction( $state ) );
		}
		if ( $wait > 0 ) {
			$job->setShared( 'resume_at', 0 );
		}

		try {
			$result = $this->advance( $job, $state, $budget );
		} catch ( DriveException $e ) {
			$result = $this->driveError( $job, $state, $e );
		} catch ( \Exception $e ) {
			// Local trouble, or an answer from Drive this code did not expect:
			// never a reason to fail the backup itself, which is on disk.
			$result = $this->giveUp( $job, $state, $e->getMessage(), 'local' );
		}

		$job->setStageState( $this->key(), $state );
		return $result;
	}

	/**
	 * Do as much as the budget allows.
	 *
	 * @param Job    $job    Job.
	 * @param array  $state  Stage state (by reference).
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 * @throws DriveException On Drive errors.
	 * @throws \RuntimeException On local errors.
	 */
	protected function advance( Job $job, array &$state, Budget $budget ) {
		$units = 0;
		while ( $budget->shouldContinue( $units ) ) {
			++$units;
			switch ( $state['phase'] ) {
				case 'prepare':
					$this->prepare( $job, $state );
					break;

				case 'session':
					$this->openSession( $job, $state );
					break;

				case 'upload':
					$this->uploadChunk( $state );
					break;

				case 'verify':
					$outcome = $this->verify( $job, $state, $budget );
					if ( 'wait' === $outcome ) {
						$job->setShared( 'resume_at', time() + 10 );
						return $this->waiting( __( 'Waiting for Google Drive to report the checksum', 'sh-clone-migration' ), 0.99 );
					}
					break;

				case 'done':
					$job->setShared( 'resume_at', 0 );
					return $this->complete( __( 'Uploaded to Google Drive and verified', 'sh-clone-migration' ) );
			}
		}

		if ( 'done' === $state['phase'] ) {
			$job->setShared( 'resume_at', 0 );
			return $this->complete( __( 'Uploaded to Google Drive and verified', 'sh-clone-migration' ) );
		}
		return $this->progress( $this->progressMessage( $state ), $this->fraction( $state ) );
	}

	/**
	 * Check the archive and the connection, and find the Drive folder.
	 *
	 * @param Job   $job   Job.
	 * @param array $state Stage state (by reference).
	 * @return void
	 * @throws DriveException On Drive errors.
	 * @throws \RuntimeException On local errors.
	 */
	protected function prepare( Job $job, array &$state ) {
		$path = (string) $job->param( 'archive_path' );
		clearstatcache( true, $path );
		if ( '' === $path || ! is_file( $path ) ) {
			throw new \RuntimeException( __( 'The archive to upload is missing.', 'sh-clone-migration' ) );
		}
		if ( null === Reader::readFooter( $path ) ) {
			throw new \RuntimeException( __( 'The archive is incomplete and was not uploaded.', 'sh-clone-migration' ) );
		}
		$size = (int) filesize( $path );

		$sha256 = (string) $job->shared( 'archive_sha256', '' );
		if ( '' === $sha256 ) {
			$sha256 = (string) $job->param( 'archive_sha256', '' );
		}
		if ( '' === $sha256 ) {
			$sha256 = ( new Catalog( $this->storage ) )->sha256( $path );
		}

		$connection = $this->backups->connection();
		if ( ! $connection->isUsable() ) {
			$status = $connection->status();
			throw new DriveException( $this->connectionProblem( $status['state'] ), DriveException::NOT_CONNECTED );
		}
		$this->backups->protectSecrets();

		$drive = $this->backups->drive();
		$about = $drive->about();
		if ( ! empty( $about['email'] ) ) {
			$connection->setAccount( (string) $about['email'], isset( $about['name'] ) ? (string) $about['name'] : '' );
		}
		if ( null !== $about['limit'] && (int) $about['limit'] - (int) $about['usage'] < $size ) {
			throw new DriveException(
				sprintf(
					/* translators: 1: free space, 2: archive size */
					__( 'Not enough space on Google Drive: %1$s free, %2$s needed.', 'sh-clone-migration' ),
					Bytes::format( max( 0, (int) $about['limit'] - (int) $about['usage'] ) ),
					Bytes::format( $size )
				),
				DriveException::QUOTA
			);
		}
		if ( ! empty( $about['max_upload'] ) && $size > (int) $about['max_upload'] ) {
			throw new DriveException( __( 'The archive is larger than Google Drive accepts for a single file.', 'sh-clone-migration' ), DriveException::BAD_REQUEST );
		}

		$status = $connection->status();
		$folder = $drive->ensureFolder( $connection->siteId(), $this->folderName(), (string) $status['folder_id'] );
		$connection->setFolder( (string) $folder['id'], (string) $folder['name'] );

		$state['path']      = $path;
		$state['size']      = $size;
		$state['sha256']    = $sha256;
		$state['folder_id'] = (string) $folder['id'];
		$state['phase']     = 'session';
		$this->logger->info( sprintf( 'Uploading %1$s (%2$s) to Google Drive folder "%3$s".', basename( $path ), Bytes::format( $size ), $folder['name'] ) );
	}

	/**
	 * Start a resumable upload session.
	 *
	 * @param Job   $job   Job.
	 * @param array $state Stage state (by reference).
	 * @return void
	 * @throws DriveException On Drive errors.
	 */
	protected function openSession( Job $job, array &$state ) {
		$backup     = (array) $job->param( 'backup', array() );
		$connection = $this->backups->connection();
		// When the backup was made: the backup job's start, or for a retried
		// upload the start its history entry recorded. A file's mtime is only
		// the last resort (a copy without preserved times would look new).
		$made = 0;
		if ( ! $job->param( 'upload_only' ) ) {
			$made = (int) $job->get( 'started_at' );
		} elseif ( '' !== (string) $job->param( 'history_id', '' ) ) {
			$entry = $this->backups->history()->get( (string) $job->param( 'history_id' ) );
			$made  = isset( $entry['started'] ) ? (int) $entry['started'] : 0;
		}
		if ( $made <= 0 ) {
			$made = (int) @filemtime( $state['path'] );
		}
		$properties = array(
			'shcm_site' => $connection->siteId(),
			'shcm_kind' => isset( $backup['kind'] ) && 'backup' === $backup['kind'] ? 'backup' : 'manual',
			'shcm_job'  => $job->param( 'upload_only' ) ? (string) $job->param( 'history_id', $job->id() ) : $job->id(),
			// So that a retried upload does not look like the newest backup
			// on Drive (retention ranks by this).
			'shcm_time' => (string) $made,
			'shcm_v'    => '1',
		);
		if ( '' !== $state['sha256'] ) {
			$properties['shcm_sha256'] = $state['sha256'];
		}
		$description = sprintf(
			/* translators: 1: site, 2: date */
			__( 'Backup of %1$s made by SH Clone Migration on %2$s.', 'sh-clone-migration' ),
			SiteIdentity::label(),
			gmdate( 'Y-m-d H:i' ) . ' UTC'
		);
		if ( '' !== $state['sha256'] ) {
			$description .= ' SHA-256: ' . $state['sha256'];
		}

		$uri = $this->backups->drive()->startUpload( basename( $state['path'] ), (int) $state['size'], $state['folder_id'], $properties, $description );
		$this->logger->redactor()->addLiteral( $uri );

		$state['session']         = $this->backups->box()->seal( $uri, self::SESSION_CONTEXT );
		$state['session_started'] = time();
		$state['offset']          = 0;
		$state['query']           = false;
		$state['stalls']          = 0;
		$state['phase']           = 'upload';
	}

	/**
	 * Send one chunk (or ask Drive where it stands after an interruption).
	 *
	 * @param array $state Stage state (by reference).
	 * @return void
	 * @throws DriveException On Drive errors.
	 * @throws \RuntimeException On local errors.
	 */
	protected function uploadChunk( array &$state ) {
		$uri = isset( $state['session'] ) ? $this->backups->box()->open( (string) $state['session'], self::SESSION_CONTEXT ) : null;
		if ( null === $uri || time() - (int) $state['session_started'] > self::SESSION_MAX_AGE ) {
			// Sessions last a week; an unreadable or old one is started again.
			$state['phase'] = 'session';
			return;
		}
		$this->logger->redactor()->addLiteral( $uri );
		$drive = $this->backups->drive();

		$offset = (int) $state['offset'];
		$total  = (int) $state['size'];
		if ( $offset >= $total ) {
			// Drive acknowledged every byte without finishing the upload (a
			// 308 covering the whole file): ask for the final answer instead
			// of sending an empty chunk.
			$state['query'] = true;
		}

		if ( ! empty( $state['query'] ) ) {
			$reply          = $drive->queryUpload( $uri, $total );
			$state['query'] = false;
			// A status query sends nothing, so "no progress" is expected and
			// must not use up the stall allowance meant for refused data;
			// unless there is nothing left to send, where only the stall
			// limit keeps it from asking forever.
			$this->absorb( $state, $reply, $offset >= $total );
			return;
		}

		clearstatcache( true, $state['path'] );
		if ( ! is_file( $state['path'] ) || (int) filesize( $state['path'] ) !== $total ) {
			throw new \RuntimeException( __( 'The archive changed or disappeared during the upload.', 'sh-clone-migration' ) );
		}

		$length = (int) min( $this->chunkSize(), $total - $offset );
		$data   = $this->read( $state['path'], $offset, $length );
		$reply  = $drive->uploadChunk( $uri, $data, $offset, $total, $this->timeout( $length ) );
		unset( $data );
		$this->absorb( $state, $reply );
	}

	/**
	 * Take in what Drive says it has.
	 *
	 * @param array $state       Stage state (by reference).
	 * @param array $reply       array( done, offset, file ).
	 * @param bool  $count_stall Whether an answer without progress counts
	 *                           towards the stall limit.
	 * @return void
	 * @throws DriveException When the upload stops making progress.
	 */
	protected function absorb( array &$state, array $reply, $count_stall = true ) {
		if ( ! empty( $reply['done'] ) ) {
			$state['file']   = is_array( $reply['file'] ) ? $reply['file'] : array();
			$state['offset'] = (int) $state['size'];
			$state['phase']  = 'verify';
			$state['verify'] = 0;
			unset( $state['session'] );
			return;
		}
		$offset = (int) $reply['offset'];
		if ( $offset > (int) $state['offset'] ) {
			$state['retries'] = 0;
			$state['stalls']  = 0;
		} elseif ( $count_stall ) {
			++$state['stalls'];
			if ( $state['stalls'] > 3 ) {
				throw new DriveException( __( 'Google Drive keeps refusing the upload data.', 'sh-clone-migration' ), DriveException::SERVER );
			}
		}
		$state['offset'] = max( 0, min( $offset, (int) $state['size'] ) );
	}

	/**
	 * Compare what Drive stored with the archive.
	 *
	 * @param Job    $job    Job.
	 * @param array  $state  Stage state (by reference).
	 * @param Budget $budget Budget.
	 * @return string 'done', 'wait' or 'continue'.
	 * @throws DriveException On Drive errors or a mismatch.
	 */
	protected function verify( Job $job, array &$state, Budget $budget ) {
		$id = isset( $state['file']['id'] ) ? (string) $state['file']['id'] : '';
		if ( '' === $id ) {
			throw new DriveException( __( 'Google Drive did not return the uploaded file.', 'sh-clone-migration' ), DriveException::INTEGRITY );
		}
		$drive = $this->backups->drive();
		try {
			$file = $drive->getFile( $id );
		} catch ( DriveException $e ) {
			if ( DriveException::NOT_FOUND === $e->kind() ) {
				throw new DriveException( __( 'The uploaded file vanished from Google Drive.', 'sh-clone-migration' ), DriveException::INTEGRITY );
			}
			throw $e;
		}

		if ( (int) $file['size'] !== (int) $state['size'] ) {
			throw new DriveException(
				sprintf(
					/* translators: 1: bytes on Drive, 2: bytes locally */
					__( 'Google Drive stored %1$s bytes instead of %2$s.', 'sh-clone-migration' ),
					number_format( (int) $file['size'] ),
					number_format( (int) $state['size'] )
				),
				DriveException::INTEGRITY
			);
		}

		$method = '';
		if ( '' !== (string) $file['sha256'] && '' !== (string) $state['sha256'] ) {
			if ( ! hash_equals( strtolower( (string) $state['sha256'] ), strtolower( (string) $file['sha256'] ) ) ) {
				throw new DriveException( __( 'The SHA-256 Google Drive reports does not match the archive.', 'sh-clone-migration' ), DriveException::INTEGRITY );
			}
			$method = 'sha256';
		} elseif ( '' !== (string) $file['md5'] ) {
			$digest       = FileDigest::advance( $state['path'], isset( $state['md5'] ) ? (array) $state['md5'] : array(), $budget, null, 'md5' );
			$state['md5'] = $digest;
			if ( ! empty( $digest['unavailable'] ) ) {
				$method = 'size';
			} elseif ( empty( $digest['digest'] ) ) {
				return 'continue';
			} elseif ( ! hash_equals( (string) $digest['digest'], strtolower( (string) $file['md5'] ) ) ) {
				throw new DriveException( __( 'The MD5 Google Drive reports does not match the archive.', 'sh-clone-migration' ), DriveException::INTEGRITY );
			} else {
				$method = 'md5';
			}
		} else {
			++$state['verify'];
			if ( $state['verify'] < self::VERIFY_ATTEMPTS ) {
				return 'wait';
			}
			$method = 'size';
			$job->addWarning( __( 'Google Drive did not report a checksum for the uploaded backup; it was verified by its size only.', 'sh-clone-migration' ) );
		}

		$job->setShared(
			'remote_upload',
			array(
				'status'      => 'uploaded',
				'provider'    => 'gdrive',
				'file_id'     => (string) $file['id'],
				'name'        => (string) $file['name'],
				'size'        => (int) $file['size'],
				'link'        => (string) $file['link'],
				'folder_id'   => (string) $state['folder_id'],
				'verified'    => $method,
				'uploaded_at' => time(),
			)
		);
		$this->logger->info( sprintf( 'Uploaded to Google Drive and verified by %1$s (file id %2$s).', 'size' === $method ? 'size' : strtoupper( $method ), $file['id'] ) );
		$state['phase'] = 'done';
		unset( $state['md5'] );
		return 'done';
	}

	/**
	 * Decide what a Drive error means for the upload.
	 *
	 * @param Job            $job   Job.
	 * @param array          $state Stage state (by reference).
	 * @param DriveException $e     Error.
	 * @return \SHCM\Core\Result
	 */
	protected function driveError( Job $job, array &$state, DriveException $e ) {
		if ( $e->isRetryable() ) {
			/**
			 * Seconds to wait before each retry of a Google Drive request.
			 *
			 * @param int[] $delays One entry per retry; more entries mean more retries.
			 */
			$delays = array_values( array_map( 'intval', (array) apply_filters( 'shcm_gdrive_backoff', self::$backoff ) ) );
			++$state['retries'];
			if ( $state['retries'] > count( $delays ) ) {
				return $this->giveUp( $job, $state, $e->getMessage(), $e->kind() );
			}
			$delay          = max( 1, $delays[ $state['retries'] - 1 ] ) + ( $delays[ $state['retries'] - 1 ] >= 30 ? random_int( 0, 5 ) : 0 );
			$state['query'] = 'upload' === $state['phase'];
			$job->setShared( 'resume_at', time() + $delay );
			$this->logger->warning( sprintf( 'Google Drive: %1$s Retry %2$d in %3$d s.', $e->getMessage(), $state['retries'], $delay ) );
			return $this->waiting( $this->retryMessage( $delay ), $this->fraction( $state ) );
		}

		if ( DriveException::SESSION_EXPIRED === $e->kind() || DriveException::INTEGRITY === $e->kind() ) {
			++$state['restarts'];
			if ( $state['restarts'] > self::MAX_RESTARTS ) {
				return $this->giveUp( $job, $state, $e->getMessage(), $e->kind() );
			}
			if ( DriveException::INTEGRITY === $e->kind() && ! empty( $state['file']['id'] ) ) {
				try {
					$this->backups->drive()->deleteFile( (string) $state['file']['id'] );
				} catch ( DriveException $ignored ) {
					unset( $ignored ); // A leftover copy is pruned by retention.
				}
			}
			$this->logger->warning( sprintf( 'Google Drive: %s Starting the upload again.', $e->getMessage() ) );
			$job->addWarning( sprintf( __( 'The upload to Google Drive was started again: %s', 'sh-clone-migration' ), $e->getMessage() ) );
			unset( $state['session'], $state['file'], $state['md5'] );
			$state['offset'] = 0;
			$state['phase']  = isset( $state['folder_id'] ) ? 'session' : 'prepare';
			return $this->progress( __( 'Starting the upload again', 'sh-clone-migration' ), 0.0 );
		}

		return $this->giveUp( $job, $state, $e->getMessage(), $e->kind() );
	}

	/**
	 * Stop trying.
	 *
	 * @param Job    $job     Job.
	 * @param array  $state   Stage state (by reference).
	 * @param string $message Reason.
	 * @param string $kind    Error kind.
	 * @return \SHCM\Core\Result
	 * @throws \RuntimeException In an upload-only job (the upload is the job).
	 */
	protected function giveUp( Job $job, array &$state, $message, $kind ) {
		$job->setShared( 'resume_at', 0 );
		$this->logger->error( 'Upload to Google Drive failed: ' . $message );
		if ( $job->param( 'upload_only' ) ) {
			throw new \RuntimeException( sprintf( __( 'Upload to Google Drive failed: %s', 'sh-clone-migration' ), $message ) );
		}
		$job->setShared(
			'remote_upload',
			array(
				'status' => 'failed',
				'error'  => (string) $message,
				'kind'   => (string) $kind,
			)
		);
		$job->addWarning( sprintf( __( 'The backup was kept on this server but not uploaded to Google Drive: %s', 'sh-clone-migration' ), $message ) );
		unset( $state['session'] );
		$state['phase'] = 'done';
		return $this->complete( __( 'Backup kept on this server; the upload to Google Drive failed', 'sh-clone-migration' ) );
	}

	/**
	 * Read part of the archive.
	 *
	 * @param string $path   Path.
	 * @param int    $offset Offset.
	 * @param int    $length Length.
	 * @return string
	 * @throws \RuntimeException When the file cannot be read.
	 */
	protected function read( $path, $offset, $length ) {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( __( 'The archive could not be opened for the upload.', 'sh-clone-migration' ) );
		}
		$data = '';
		if ( 0 === fseek( $handle, $offset ) ) {
			while ( strlen( $data ) < $length && ! feof( $handle ) ) {
				$part = fread( $handle, $length - strlen( $data ) );
				if ( false === $part || '' === $part ) {
					break;
				}
				$data .= $part;
			}
		}
		fclose( $handle );
		if ( strlen( $data ) !== $length ) {
			throw new \RuntimeException( __( 'The archive could not be read for the upload.', 'sh-clone-migration' ) );
		}
		return $data;
	}

	/**
	 * Chunk size: large (Google recommends it), a multiple of 256 KiB, and
	 * small enough for the memory that is left.
	 *
	 * @return int
	 */
	protected function chunkSize() {
		$size = (int) apply_filters( 'shcm_gdrive_chunk_size', self::CHUNK );
		$size = max( self::UNIT, min( self::MAX_CHUNK, $size ) );

		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		if ( $limit > 0 ) {
			$free = $limit - memory_get_usage( true );
			// The chunk exists about three times over while it is sent
			// (read buffer, request body, transport buffer).
			$size = min( $size, (int) floor( max( self::UNIT, $free / 3 ) ) );
		}
		return max( self::UNIT, $size - ( $size % self::UNIT ) );
	}

	/**
	 * HTTP timeout for a chunk (assumes at least 64 KiB/s).
	 *
	 * @param int $length Bytes.
	 * @return float
	 */
	protected function timeout( $length ) {
		return (float) min( 600, 60 + (int) ceil( $length / 65536 ) );
	}

	/**
	 * Drive folder name for this site.
	 *
	 * @return string
	 */
	protected function folderName() {
		return sprintf( 'SH Clone Migration backups (%s)', SiteIdentity::label() );
	}

	/**
	 * Explain an unusable connection.
	 *
	 * @param string $state Connection state.
	 * @return string
	 */
	protected function connectionProblem( $state ) {
		switch ( $state ) {
			case 'reconnect':
				return __( 'Google Drive needs to be reconnected (access was revoked or expired).', 'sh-clone-migration' );
			case 'other_site':
				return __( 'The Google Drive connection belongs to another copy of this site. Confirm or reconnect it on the Scheduled Backups screen.', 'sh-clone-migration' );
			case 'not_configured':
				return __( 'Google Drive is not set up.', 'sh-clone-migration' );
			default:
				return __( 'Google Drive is not connected.', 'sh-clone-migration' );
		}
	}

	/**
	 * Progress message.
	 *
	 * @param array $state Stage state.
	 * @return string
	 */
	protected function progressMessage( array $state ) {
		if ( 'upload' === $state['phase'] && ! empty( $state['size'] ) ) {
			return sprintf(
				/* translators: 1: bytes sent, 2: total */
				__( 'Uploading to Google Drive: %1$s of %2$s', 'sh-clone-migration' ),
				Bytes::format( (int) $state['offset'] ),
				Bytes::format( (int) $state['size'] )
			);
		}
		if ( 'verify' === $state['phase'] ) {
			return __( 'Checking the copy on Google Drive', 'sh-clone-migration' );
		}
		return __( 'Preparing the upload to Google Drive', 'sh-clone-migration' );
	}

	/**
	 * Waiting message.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	protected function retryMessage( $seconds ) {
		return sprintf(
			/* translators: %d: seconds */
			__( 'Google Drive is busy or unreachable; trying again in %d seconds', 'sh-clone-migration' ),
			max( 1, (int) $seconds )
		);
	}

	/**
	 * Stage progress.
	 *
	 * @param array $state Stage state.
	 * @return float
	 */
	protected function fraction( array $state ) {
		if ( empty( $state['size'] ) ) {
			return 0.0;
		}
		return min( 0.99, (int) $state['offset'] / (int) $state['size'] );
	}
}
