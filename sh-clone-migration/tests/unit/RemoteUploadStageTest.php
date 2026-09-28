<?php
/**
 * Google Drive upload stage tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

require_once __DIR__ . '/Support/UploadRig.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SHCM\Export\Stages\RemoteUploadStage;
use SHCM\Jobs\Job;
use SHCM\Remote\Http\HttpResponse;
use SHCM\Tests\Unit\Support\DriveEmulator;
use SHCM\Tests\Unit\Support\UploadRig;

/**
 * RemoteUploadStage against an in-memory Google Drive, through the real
 * Client, OAuth and Connection: chunking, verification, backoff, restarts
 * and the rule that a backup job never fails because of Drive.
 */
class RemoteUploadStageTest extends TestCase {

	const VERIFIED = 'Uploaded to Google Drive and verified';

	const GAVE_UP = 'Backup kept on this server; the upload to Google Drive failed';

	const KEPT = 'The backup was kept on this server but not uploaded to Google Drive: ';

	/**
	 * Rigs to clean up.
	 *
	 * @var UploadRig[]
	 */
	protected $rigs = array();

	/**
	 * Remove the rigs' scratch directories.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->rigs as $rig ) {
			$rig->cleanup();
		}
		$this->rigs = array();
		parent::tearDown();
	}

	/**
	 * A rig: a connected Drive and a backup job for a ~1 MB archive, sent in
	 * 256 KiB chunks (four of them).
	 *
	 * @param array $params Job parameters over the backup defaults.
	 * @return UploadRig
	 */
	protected function rig( array $params = array() ) {
		$rig          = new UploadRig( 1000000, $params );
		$this->rigs[] = $rig;
		return $rig;
	}

	/**
	 * Expected log line of a chunk PUT.
	 *
	 * @param int $start  First byte.
	 * @param int $end    Last byte.
	 * @param int $total  Archive size.
	 * @param int $status Answer.
	 * @return string
	 */
	private static function chunk( $start, $end, $total, $status ) {
		return sprintf( 'bytes %d-%d/%d -> %d', $start, $end, $total, $status );
	}

	/**
	 * Expected log line of a status query.
	 *
	 * @param int $total  Archive size.
	 * @param int $status Answer.
	 * @return string
	 */
	private static function query( $total, $status ) {
		return sprintf( 'bytes */%d -> %d', $total, $status );
	}

	/**
	 * Assert the backoffs waited out: the configured delays plus up to five
	 * seconds of jitter (a second less when the clock ticked meanwhile).
	 *
	 * @param int[] $delays Configured delays.
	 * @param int[] $waits  Measured waits.
	 * @return void
	 */
	private function assertBackoff( array $delays, array $waits ) {
		$this->assertCount( count( $delays ), $waits, 'Waits: ' . implode( ', ', $waits ) );
		foreach ( $delays as $index => $delay ) {
			$this->assertGreaterThanOrEqual( $delay - 1, $waits[ $index ] );
			$this->assertLessThanOrEqual( $delay + 5, $waits[ $index ] );
		}
	}

	/**
	 * Whether one of the job's warnings starts with $prefix.
	 *
	 * @param UploadRig $rig    Rig.
	 * @param string    $prefix Prefix.
	 * @return void
	 */
	private function assertWarning( UploadRig $rig, $prefix ) {
		foreach ( $rig->warnings() as $warning ) {
			if ( 0 === strpos( $warning, $prefix ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}
		$this->fail( 'No warning starts with "' . $prefix . '". Warnings: ' . json_encode( $rig->warnings() ) );
	}

	/**
	 * The upload is sent in 256 KiB chunks to one session, stored byte for
	 * byte in this site's backup folder, verified by SHA-256 and recorded in
	 * the shared remote_upload value.
	 */
	public function testUploadsInChunksAndVerifiesBySha256() {
		$rig    = $this->rig();
		$size   = filesize( $rig->archive );
		$sha256 = hash_file( 'sha256', $rig->archive );
		$before = time();

		// Mid-upload the session URI (a credential) is only kept sealed.
		$rig->stepUntil(
			function ( $state ) {
				return isset( $state['offset'] ) && $state['offset'] > 0;
			}
		);
		$upload_id = key( $rig->drive->sessions );
		$this->assertIsString( $rig->state()['session'] );
		$this->assertStringNotContainsString( $upload_id, json_encode( $rig->job->toArray() ) );

		$result = $rig->runToEnd();

		$this->assertSame( self::VERIFIED, $result->message() );
		$this->assertTrue( $result->data()['complete'] );
		$this->assertSame(
			array(
				self::chunk( 0, 262143, $size, 308 ),
				self::chunk( 262144, 524287, $size, 308 ),
				self::chunk( 524288, 786431, $size, 308 ),
				self::chunk( 786432, $size - 1, $size, 200 ),
			),
			$rig->drive->puts()
		);
		$this->assertSame( 1, $rig->drive->count( 'POST', '/upload/drive/v3/files' ) );
		foreach ( $rig->drive->log as $entry ) {
			if ( 'PUT' === $entry['method'] ) {
				$this->assertArrayNotHasKey( 'Authorization', $entry['headers'], 'The session URI is the credential; chunks carry no token.' );
				$this->assertSame( 64.0, $entry['options']['timeout'], 'One minute plus a second per 64 KiB.' );
			}
		}

		$folders = $rig->drive->folders();
		$this->assertCount( 1, $folders );
		$this->assertSame(
			array(
				'shcm_role' => 'backup_root',
				'shcm_site' => $rig->connection->siteId(),
			),
			$folders[0]['appProperties']
		);
		$this->assertSame( $folders[0]['id'], $rig->connection->status()['folder_id'] );

		$uploads = $rig->drive->uploads();
		$this->assertCount( 1, $uploads );
		$file = $uploads[0];
		$this->assertSame( file_get_contents( $rig->archive ), $file['content'] );
		$this->assertSame( basename( $rig->archive ), $file['name'] );
		$this->assertSame( array( $folders[0]['id'] ), $file['parents'] );
		// What retention and the backup list rely on (other properties may be added).
		$this->assertSame( $rig->connection->siteId(), $file['appProperties']['shcm_site'] );
		$this->assertSame( 'backup', $file['appProperties']['shcm_kind'] );
		$this->assertSame( $rig->job->id(), $file['appProperties']['shcm_job'] );
		$this->assertSame( $sha256, $file['appProperties']['shcm_sha256'] );

		$remote = $rig->job->shared( 'remote_upload' );
		$this->assertGreaterThanOrEqual( $before, $remote['uploaded_at'] );
		$this->assertLessThanOrEqual( time(), $remote['uploaded_at'] );
		unset( $remote['uploaded_at'] );
		$this->assertSame(
			array(
				'status'    => 'uploaded',
				'provider'  => 'gdrive',
				'file_id'   => $file['id'],
				'name'      => basename( $rig->archive ),
				'size'      => $size,
				'link'      => 'https://drive.google.com/file/d/' . $file['id'] . '/view',
				'folder_id' => $folders[0]['id'],
				'verified'  => 'sha256',
			),
			$remote
		);
		$this->assertSame( array(), $rig->warnings() );
		$this->assertSame( 'done', $rig->state()['phase'] );
		$this->assertArrayNotHasKey( 'session', $rig->state() );

		// A finished stage sends nothing more.
		$requests = count( $rig->drive->log );
		$this->assertTrue( $rig->run()->data()['complete'] );
		$this->assertCount( $requests, $rig->drive->log );
	}

	/**
	 * Without a SHA-256 from Drive the stage computes the archive's MD5 and
	 * compares that.
	 */
	public function testVerifiesByMd5WhenDriveReportsNoSha256() {
		$rig                     = $this->rig();
		$rig->drive->omit_sha256 = true;

		$result = $rig->runToEnd();

		$this->assertSame( self::VERIFIED, $result->message() );
		$remote = $rig->job->shared( 'remote_upload' );
		$this->assertSame( 'uploaded', $remote['status'] );
		$this->assertSame( 'md5', $remote['verified'] );
		$this->assertSame( array(), $rig->warnings() );
		$this->assertArrayNotHasKey( 'md5', $rig->state(), 'The digest progress is dropped once used.' );
	}

	/**
	 * Without any checksum the stage asks again a few times, ten seconds
	 * apart, then settles for the size and says so in a warning.
	 */
	public function testFallsBackToTheSizeAfterWaitingForAChecksumThatNeverComes() {
		$rig                     = $this->rig();
		$rig->drive->omit_sha256 = true;
		$rig->drive->omit_md5    = true;
		$rig->stepUntil(
			function ( $state ) {
				return isset( $state['phase'] ) && 'verify' === $state['phase'];
			}
		);
		$uploads = $rig->drive->uploads();
		$this->assertCount( 1, $uploads );

		for ( $attempt = 1; $attempt < RemoteUploadStage::VERIFY_ATTEMPTS; $attempt++ ) {
			$result = $rig->run();
			$this->assertSame( 'Waiting for Google Drive to report the checksum', $result->message() );
			$this->assertFalse( $result->data()['complete'] );
			$this->assertTrue( $result->data()['yield'] );
			$this->assertGreaterThanOrEqual( 9, $rig->pendingWait() );
			$this->assertLessThanOrEqual( 10, $rig->pendingWait() );
			$rig->elapse();
		}
		$result = $rig->run();

		$this->assertSame( self::VERIFIED, $result->message() );
		$this->assertSame( 'size', $rig->job->shared( 'remote_upload' )['verified'] );
		$this->assertSame( array( 'Google Drive did not report a checksum for the uploaded backup; it was verified by its size only.' ), $rig->warnings() );
		$this->assertSame( RemoteUploadStage::VERIFY_ATTEMPTS, $rig->drive->count( 'GET', '/drive/v3/files/' . $uploads[0]['id'] . '?' ) );
	}

	/**
	 * A copy on Drive whose checksum does not match is deleted and the
	 * archive uploaded again, for either checksum.
	 *
	 * @param bool   $omit_sha256 Whether Drive leaves the SHA-256 out.
	 * @param string $method      Expected verification method.
	 * @param string $label       Checksum name in the message.
	 */
	#[DataProvider( 'checksums' )]
	public function testACopyWithTheWrongChecksumIsDeletedAndUploadedAgain( $omit_sha256, $method, $label ) {
		$rig                     = $this->rig();
		$rig->drive->omit_sha256 = $omit_sha256;
		$rig->stepUntil(
			function ( $state ) {
				return isset( $state['phase'] ) && 'verify' === $state['phase'];
			}
		);
		$uploads = $rig->drive->uploads();
		$bad     = $uploads[0]['id'];
		// One flipped bit, same size.
		$rig->drive->files[ $bad ]['content'][100] = chr( ord( $rig->drive->files[ $bad ]['content'][100] ) ^ 1 );

		$result = $rig->runToEnd();

		$this->assertSame( self::VERIFIED, $result->message() );
		$this->assertSame( $method, $rig->job->shared( 'remote_upload' )['verified'] );
		$this->assertArrayNotHasKey( $bad, $rig->drive->files, 'The damaged copy is deleted.' );
		$this->assertSame( 1, $rig->drive->count( 'DELETE', '/drive/v3/files/' . $bad ) );
		$this->assertSame( 2, $rig->drive->count( 'POST', '/upload/drive/v3/files' ) );
		$uploads = $rig->drive->uploads();
		$this->assertCount( 1, $uploads );
		$this->assertSame( file_get_contents( $rig->archive ), $uploads[0]['content'] );
		$this->assertSame( 1, $rig->state()['restarts'] );
		$this->assertSame( array( 'The upload to Google Drive was started again: The ' . $label . ' Google Drive reports does not match the archive.' ), $rig->warnings() );
	}

	/**
	 * Checksums Drive may report.
	 *
	 * @return array
	 */
	public static function checksums() {
		return array(
			'sha256' => array( false, 'sha256', 'SHA-256' ),
			'md5'    => array( true, 'md5', 'MD5' ),
		);
	}

	/**
	 * A rate limit or server error on a chunk ends the request with a
	 * backoff; called again early nothing is sent; afterwards the stage asks
	 * where the upload stands and carries on.
	 *
	 * @param HttpResponse $error Answer to the chunk.
	 */
	#[DataProvider( 'transientErrors' )]
	public function testATransientErrorOnAChunkBacksOffAndResumes( HttpResponse $error ) {
		$rig  = $this->rig();
		$size = filesize( $rig->archive );
		$rig->drive->failChunk( 524288, $error );

		$result = $rig->run();

		$this->assertFalse( $result->data()['complete'] );
		$this->assertTrue( $result->data()['yield'], 'A backoff ends the request instead of calling the stage again.' );
		$this->assertStringStartsWith( 'Google Drive is busy or unreachable; trying again in ', $result->message() );
		$state = $rig->state();
		$this->assertSame( 1, $state['retries'] );
		$this->assertTrue( $state['query'] );
		$this->assertSame( 524288, $state['offset'] );
		$this->assertGreaterThanOrEqual( 29, $rig->pendingWait() );
		$this->assertLessThanOrEqual( 35, $rig->pendingWait() );
		$this->assertStringContainsString( 'Retry 1 in ', $rig->logText() );

		$requests = count( $rig->drive->log );
		$early    = $rig->run();
		$this->assertTrue( $early->data()['yield'] );
		$this->assertCount( $requests, $rig->drive->log, 'Nothing is sent before the backoff is over.' );
		$this->assertSame( 1, $rig->state()['retries'] );

		$rig->elapse();
		$result = $rig->runToEnd();

		$this->assertSame( self::VERIFIED, $result->message() );
		$this->assertSame(
			array(
				self::chunk( 0, 262143, $size, 308 ),
				self::chunk( 262144, 524287, $size, 308 ),
				self::chunk( 524288, 786431, $size, $error->status ),
				self::query( $size, 308 ),
				self::chunk( 524288, 786431, $size, 308 ),
				self::chunk( 786432, $size - 1, $size, 200 ),
			),
			$rig->drive->puts()
		);
		$this->assertSame( 0, $rig->state()['retries'] );
		$this->assertSame( array(), $rig->warnings(), 'A retry that worked is not worth a warning.' );
		$this->assertSame( file_get_contents( $rig->archive ), $rig->drive->uploads()[0]['content'] );
	}

	/**
	 * Transient chunk errors.
	 *
	 * @return array
	 */
	public static function transientErrors() {
		return array(
			'503 backendError'      => array( DriveEmulator::apiError( 503, 'backendError' ) ),
			'429 rateLimitExceeded' => array( DriveEmulator::apiError( 429, 'rateLimitExceeded' ) ),
		);
	}

	/**
	 * Regression: the status query after each failed chunk answers "nothing
	 * new", and those answers used to count towards the limit of four
	 * answers without progress, so the fourth failure in a row ended the
	 * upload although Google never refused any data.
	 *
	 * @param HttpResponse $error Answer to the chunk.
	 */
	#[DataProvider( 'repeatedFailures' )]
	public function testFourFailedChunkPutsInARowStillEndUploadedAndVerified( HttpResponse $error ) {
		$rig  = $this->rig();
		$size = filesize( $rig->archive );
		$rig->drive->failChunk( 524288, $error, 4 );

		$result = $rig->runToEnd();

		$this->assertSame( self::VERIFIED, $result->message(), 'Warnings: ' . json_encode( $rig->warnings() ) );
		$this->assertSame( 4, $rig->drive->faultsServed() );
		$expected = array(
			self::chunk( 0, 262143, $size, 308 ),
			self::chunk( 262144, 524287, $size, 308 ),
		);
		for ( $i = 0; $i < 4; $i++ ) {
			$expected[] = self::chunk( 524288, 786431, $size, $error->status );
			$expected[] = self::query( $size, 308 );
		}
		$expected[] = self::chunk( 524288, 786431, $size, 308 );
		$expected[] = self::chunk( 786432, $size - 1, $size, 200 );
		$this->assertSame( $expected, $rig->drive->puts() );
		$this->assertBackoff( array( 30, 60, 120, 240 ), $rig->waits );

		$remote = $rig->job->shared( 'remote_upload' );
		$this->assertSame( 'uploaded', $remote['status'] );
		$this->assertSame( 'sha256', $remote['verified'] );
		$this->assertSame( 0, $rig->state()['retries'] );
		$this->assertSame( 0, $rig->state()['stalls'] );
		$this->assertSame( array(), $rig->warnings() );
	}

	/**
	 * Chunk failures that are retried.
	 *
	 * @return array
	 */
	public static function repeatedFailures() {
		return array(
			'503 backendError' => array( DriveEmulator::apiError( 503, 'backendError' ) ),
			'network error'    => array( DriveEmulator::networkError( 'cURL error 56: Recv failure: Connection reset by peer' ) ),
		);
	}

	/**
	 * The other side of the regression above: chunks Drive answers without
	 * keeping any of the data still count as stalls.
	 */
	public function testChunksDriveKeepsRefusingStillCountAsStalls() {
		$rig                      = $this->rig();
		$size                     = filesize( $rig->archive );
		$rig->drive->commit_limit = 0;

		$result = $rig->run();

		$this->assertTrue( $result->data()['yield'] );
		$this->assertSame( array_fill( 0, 4, self::chunk( 0, 262143, $size, 308 ) ), $rig->drive->puts() );
		$this->assertSame( 4, $rig->state()['stalls'] );
		$this->assertSame( 1, $rig->state()['retries'] );
		$this->assertStringContainsString( 'Google Drive keeps refusing the upload data.', $rig->logText() );
	}

	/**
	 * A 404 on a chunk means the session is gone: a new one is opened and
	 * the upload starts again from the first byte.
	 */
	public function testAnExpiredSessionStartsTheUploadAgain() {
		$rig  = $this->rig();
		$size = filesize( $rig->archive );
		$rig->drive->failChunk( 524288, new HttpResponse( 404, array( 'Content-Type' => 'text/plain' ), 'Not Found' ) );

		$result = $rig->runToEnd();

		$this->assertSame( self::VERIFIED, $result->message() );
		$this->assertSame(
			array(
				self::chunk( 0, 262143, $size, 308 ),
				self::chunk( 262144, 524287, $size, 308 ),
				self::chunk( 524288, 786431, $size, 404 ),
				self::chunk( 0, 262143, $size, 308 ),
				self::chunk( 262144, 524287, $size, 308 ),
				self::chunk( 524288, 786431, $size, 308 ),
				self::chunk( 786432, $size - 1, $size, 200 ),
			),
			$rig->drive->puts()
		);
		$this->assertSame( 2, $rig->drive->count( 'POST', '/upload/drive/v3/files' ) );
		$sessions = array_keys( $rig->drive->sessions );
		$used     = array();
		foreach ( $rig->drive->log as $entry ) {
			if ( 'PUT' === $entry['method'] ) {
				$used[] = DriveEmulator::uploadId( $entry );
			}
		}
		$this->assertSame( array_merge( array_fill( 0, 3, $sessions[0] ), array_fill( 0, 4, $sessions[1] ) ), $used );
		$uploads = $rig->drive->uploads();
		$this->assertCount( 1, $uploads );
		$this->assertSame( file_get_contents( $rig->archive ), $uploads[0]['content'] );
		$this->assertSame( 1, $rig->state()['restarts'] );
		$this->assertCount( 1, $rig->warnings() );
		$this->assertWarning( $rig, 'The upload to Google Drive was started again: The Google Drive upload session has expired or was rejected (404)' );
	}

	/**
	 * When Drive keeps only part of a chunk, the next chunk starts where
	 * Drive says it stands, not where this side stopped sending.
	 */
	public function testResumesFromDrivesOffsetWhenDriveKeepsLessThanWasSent() {
		$rig                      = $this->rig();
		$rig->drive->commit_limit = 100000;

		$result = $rig->runToEnd( 100 );

		$this->assertSame( self::VERIFIED, $result->message() );
		$starts = array();
		foreach ( $rig->drive->log as $entry ) {
			if ( 'PUT' === $entry['method'] && preg_match( '#^bytes (\d+)-#', $entry['headers']['Content-Range'], $match ) ) {
				$starts[] = (int) $match[1];
			}
		}
		$this->assertSame( range( 0, 1000000, 100000 ), $starts );
		$this->assertSame( file_get_contents( $rig->archive ), $rig->drive->uploads()[0]['content'], 'Nothing skipped, nothing twice.' );
		$this->assertSame( 0, $rig->state()['stalls'] );
		$this->assertSame( array(), $rig->warnings() );
	}

	/**
	 * Regression: Drive may acknowledge every byte with a 308 and finish the
	 * file a moment later. The stage then asks for the final status; it used
	 * to send a chunk of zero bytes, which the client refuses.
	 */
	public function testAFullRange308IsFollowedByAStatusQueryNotAnEmptyChunk() {
		$rig                            = $this->rig();
		$size                           = filesize( $rig->archive );
		$rig->drive->full_range_answers = 1;

		$result = $rig->runToEnd();

		$this->assertSame( self::VERIFIED, $result->message() );
		$this->assertSame(
			array(
				self::chunk( 0, 262143, $size, 308 ),
				self::chunk( 262144, 524287, $size, 308 ),
				self::chunk( 524288, 786431, $size, 308 ),
				self::chunk( 786432, $size - 1, $size, 308 ),
				self::query( $size, 200 ),
			),
			$rig->drive->puts()
		);
		$this->assertSame( file_get_contents( $rig->archive ), $rig->drive->uploads()[0]['content'] );
	}

	/**
	 * Regression, through the real JobRunner: when Drive acknowledges every
	 * byte but never finishes the file, the backup job used to fail on the
	 * zero-byte chunk. Only status queries may follow; the stage eventually
	 * gives up on Drive, and the job completes with its archive kept.
	 */
	public function testDriveNeverFinishingAfterAFullRange308DoesNotFailTheBackupJob() {
		$rig                            = $this->rig();
		$size                           = filesize( $rig->archive );
		$sha256                         = hash_file( 'sha256', $rig->archive );
		$rig->drive->full_range_answers = PHP_INT_MAX;

		$job = $rig->runJob( 1 );
		$this->assertSame( Job::STATUS_PAUSED, $job->status(), 'Error: ' . json_encode( $job->get( 'error' ) ) );

		$job = $rig->runJob();

		$this->assertNotSame( Job::STATUS_FAILED, $job->status(), 'Error: ' . json_encode( $job->get( 'error' ) ) );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$this->assertNull( $job->get( 'error' ) );
		$remote = $job->shared( 'remote_upload' );
		$this->assertSame( 'failed', $remote['status'] );
		$this->assertSame( 'server', $remote['kind'] );
		$this->assertWarning( $rig, self::KEPT );
		$this->assertFileExists( $rig->archive );
		$this->assertSame( $sha256, hash_file( 'sha256', $rig->archive ) );

		$puts = $rig->drive->puts();
		$this->assertSame( self::chunk( 786432, $size - 1, $size, 308 ), $puts[3] );
		$this->assertGreaterThan( 4, count( $puts ) );
		foreach ( array_slice( $puts, 4 ) as $line ) {
			$this->assertSame( self::query( $size, 308 ), $line, 'Once Drive has every byte, only status queries are sent.' );
		}
	}

	/**
	 * A grant revoked at Google (Drive answers 401, and the refresh is
	 * refused with invalid_grant) is not retried: the stage gives up and the
	 * connection asks for a reconnect.
	 *
	 * @param int $token_status Status of the token endpoint's answer.
	 */
	#[DataProvider( 'refusedRefreshes' )]
	public function testARevokedGrantGivesUpAndAsksForAReconnect( $token_status ) {
		$rig = $this->rig();
		$rig->stepUntil(
			function ( $state ) {
				return isset( $state['phase'] ) && 'verify' === $state['phase'];
			}
		);
		$rig->drive->fail(
			function ( $method, $url, $headers ) {
				unset( $method, $url );
				return isset( $headers['Authorization'] );
			},
			DriveEmulator::apiError( 401, 'authError', 'Invalid Credentials' ),
			PHP_INT_MAX
		);
		$rig->drive->fail(
			function ( $method, $url ) {
				unset( $method );
				return false !== strpos( $url, 'oauth2.googleapis.com/token' );
			},
			DriveEmulator::json(
				$token_status,
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'Token has been expired or revoked.',
				)
			),
			PHP_INT_MAX
		);

		$result = $rig->run();

		$this->assertSame( self::GAVE_UP, $result->message() );
		$this->assertTrue( $result->data()['complete'] );
		$this->assertSame( 0, $rig->pendingWait(), 'A revoked grant is not retried.' );
		$remote = $rig->job->shared( 'remote_upload' );
		$this->assertSame( 'failed', $remote['status'] );
		$this->assertSame( 'auth_revoked', $remote['kind'] );
		$this->assertStringContainsString( 'revoked', $remote['error'] );
		$this->assertStringNotContainsString( UploadRig::REFRESH_TOKEN, $remote['error'] );
		$this->assertSame( 'reconnect', $rig->connection->status()['state'] );
		$this->assertSame( 1, $rig->drive->count( 'POST', 'oauth2.googleapis.com/token' ) );
		$this->assertCount( 1, $rig->warnings() );
		$this->assertWarning( $rig, self::KEPT . 'Google Drive access was revoked or has expired' );

		$requests = count( $rig->drive->log );
		$rig->run();
		$this->assertCount( $requests, $rig->drive->log );
	}

	/**
	 * Token endpoint statuses carrying invalid_grant.
	 *
	 * @return array
	 */
	public static function refusedRefreshes() {
		return array(
			'400 invalid_grant (what Google sends)' => array( 400 ),
			'401 invalid_grant'                     => array( 401 ),
		);
	}

	/**
	 * An archive that changes size mid-upload is not sent any further; the
	 * backup job still completes and keeps it.
	 */
	public function testAnArchiveThatChangesDuringTheUploadIsKeptAndTheJobCompletes() {
		$rig = $this->rig();
		$rig->stepUntil(
			function ( $state ) {
				return isset( $state['offset'] ) && $state['offset'] > 0;
			}
		);
		file_put_contents( $rig->archive, 'appended', FILE_APPEND );
		$sent = count( $rig->drive->puts() );

		$job = $rig->runJob();

		$this->assertSame( Job::STATUS_COMPLETED, $job->status(), 'Error: ' . json_encode( $job->get( 'error' ) ) );
		$remote = $job->shared( 'remote_upload' );
		$this->assertSame(
			array(
				'status' => 'failed',
				'error'  => 'The archive changed or disappeared during the upload.',
				'kind'   => 'local',
			),
			$remote
		);
		$this->assertCount( $sent, $rig->drive->puts(), 'Nothing more is sent.' );
		$this->assertSame( array(), $rig->drive->uploads() );
		$this->assertFileExists( $rig->archive );
		$this->assertWarning( $rig, self::KEPT . 'The archive changed or disappeared during the upload.' );
	}

	/**
	 * Through the real JobRunner: after the first try and six retries with
	 * growing backoffs the stage gives up on Drive; the backup job completes
	 * with a warning and keeps the archive.
	 */
	public function testGivesUpAfterTheLastRetryWithoutFailingTheBackupJob() {
		$rig = $this->rig();
		$rig->drive->failChunk( 524288, DriveEmulator::apiError( 503, 'backendError' ), PHP_INT_MAX );

		$job = $rig->runJob();

		$this->assertSame( Job::STATUS_COMPLETED, $job->status(), 'Error: ' . json_encode( $job->get( 'error' ) ) );
		$this->assertNull( $job->get( 'error' ) );
		$remote = $job->shared( 'remote_upload' );
		$this->assertSame( 'failed', $remote['status'] );
		$this->assertSame( 'server', $remote['kind'] );
		$this->assertStringContainsString( '(503 backendError)', $remote['error'] );
		$this->assertSame( 7, $rig->drive->faultsServed(), 'The first try and six retries.' );
		$this->assertBackoff( array( 30, 60, 120, 240, 480, 960 ), $rig->waits );
		$this->assertSame( 0, (int) $job->shared( 'resume_at', 0 ) );
		$this->assertSame( array(), $rig->drive->uploads() );
		$this->assertFileExists( $rig->archive );
		$this->assertWarning( $rig, self::KEPT . 'Google Drive is temporarily unavailable (503 backendError)' );
	}

	/**
	 * In an upload-only job the upload is the whole job, so giving up does
	 * fail it (and still leaves the archive alone).
	 */
	public function testGivingUpInAnUploadOnlyJobFailsTheJob() {
		$rig = $this->rig( array( 'upload_only' => true ) );
		$rig->drive->failChunk( 524288, DriveEmulator::apiError( 503, 'backendError' ), PHP_INT_MAX );

		$job = $rig->runJob();

		$this->assertSame( Job::STATUS_FAILED, $job->status() );
		$this->assertStringStartsWith( 'Upload to Google Drive failed: Google Drive is temporarily unavailable (503 backendError)', $job->get( 'error' )['message'] );
		$this->assertNull( $job->shared( 'remote_upload' ) );
		$this->assertFileExists( $rig->archive );
	}
}
