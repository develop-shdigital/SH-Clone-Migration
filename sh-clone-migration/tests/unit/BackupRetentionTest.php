<?php
/**
 * Regression tests of what backup retention deletes and what it keeps.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

// BackupRetentionStage and BackupManager only load inside WordPress
// ("defined( 'ABSPATH' ) || exit"). Nothing in the unit suite depends on
// ABSPATH being undefined, so a scratch value is enough.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/shcm-unit-abspath/' );
}

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SHCM\Archive\Reader;
use SHCM\Archive\Writer;
use SHCM\Backup\BackupManager;
use SHCM\Backup\ConfigStore;
use SHCM\Backup\History;
use SHCM\Core\Plugin;
use SHCM\Core\Settings;
use SHCM\Export\Stages\BackupRetentionStage;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Jobs\JobStore;
use SHCM\Jobs\Scheduler;
use SHCM\Logging\Logger;
use SHCM\Remote\GoogleDrive\Connection;
use SHCM\Remote\GoogleDrive\Endpoints;
use SHCM\Remote\GoogleDrive\OAuth;
use SHCM\Remote\Http\HttpResponse;
use SHCM\Remote\Http\HttpTransport;
use SHCM\Security\SecretBox;

/**
 * Google Drive as retention sees it: Client::listBackups() and deleteFile().
 *
 * listBackups() applies the same filter as the real query ('<folder>' in
 * parents, not trashed, appProperties shcm_site = X [and shcm_kind = K]) and
 * the same order (createdTime desc). "created" is the moment a file reached
 * Drive; the appProperty shcm_time is when its backup was made.
 */
class RetentionDrive {

	/**
	 * Files by id, shaped like Client::normalizeFile() plus their folder.
	 *
	 * @var array[]
	 */
	public $files = array();

	/**
	 * Ids passed to deleteFile(), in order.
	 *
	 * @var string[]
	 */
	public $deleted = array();

	/**
	 * Add a file.
	 *
	 * @param string $id      File id.
	 * @param string $name    File name.
	 * @param int    $created Upload time (Drive's createdTime).
	 * @param string $folder  Parent folder id.
	 * @param array  $app     appProperties.
	 * @return void
	 */
	public function add( $id, $name, $created, $folder, array $app ) {
		$this->files[ $id ] = array(
			'id'      => $id,
			'name'    => $name,
			'size'    => 0,
			'created' => $created,
			'md5'     => '',
			'sha256'  => '',
			'app'     => array_map( 'strval', $app ),
			'link'    => '',
			'folder'  => $folder,
		);
	}

	/**
	 * All backups of a site in a folder, newest upload first.
	 *
	 * @param string $folder_id Folder id.
	 * @param string $site_id   Site id.
	 * @param string $kind      shcm_kind filter ('' = any).
	 * @return array[]
	 */
	public function listBackups( $folder_id, $site_id, $kind = '' ) {
		$out = array();
		foreach ( $this->files as $file ) {
			if ( $file['folder'] !== $folder_id || ! isset( $file['app']['shcm_site'] ) || $file['app']['shcm_site'] !== $site_id ) {
				continue;
			}
			if ( '' !== $kind && ( ! isset( $file['app']['shcm_kind'] ) || $file['app']['shcm_kind'] !== $kind ) ) {
				continue;
			}
			$out[] = $file;
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		return $out;
	}

	/**
	 * Delete a file.
	 *
	 * @param string $id File id.
	 * @return bool
	 */
	public function deleteFile( $id ) {
		$this->deleted[] = $id;
		unset( $this->files[ $id ] );
		return true;
	}
}

/**
 * A usable Google Drive connection with a fixed site id.
 */
class RetentionConnection {

	/**
	 * Whether uploads and retention may use Drive.
	 *
	 * @return bool
	 */
	public function isUsable() {
		return true;
	}

	/**
	 * This site's backup identity.
	 *
	 * @return string
	 */
	public function siteId() {
		return BackupRetentionTest::SITE;
	}
}

/**
 * Settings with fixed values instead of the options table.
 */
class RetentionSettings extends Settings {

	/**
	 * Constructor.
	 *
	 * @param array $values Values over the defaults.
	 */
	public function __construct( array $values = array() ) {
		$this->values = array_merge( self::defaults(), $values );
	}
}

/**
 * The scheduler with its max_archives pass callable on its own.
 */
class RetentionScheduler extends Scheduler {

	/**
	 * Run the max_archives pass.
	 *
	 * @param int $max Archives to keep.
	 * @return int Archives deleted.
	 */
	public function pruneNow( $max ) {
		return $this->pruneArchives( $max, array() );
	}
}

/**
 * A transport for a server without network access: every request is
 * recorded and gets no answer.
 */
class UnreachableTransport implements HttpTransport {

	/**
	 * Recorded requests: method, url.
	 *
	 * @var array[]
	 */
	public $requests = array();

	/**
	 * Send a request.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param array  $options Options.
	 * @return HttpResponse
	 */
	public function request(
		$method,
		#[\SensitiveParameter]
		$url,
		#[\SensitiveParameter]
		array $headers = array(),
		#[\SensitiveParameter]
		$body = '',
		array $options = array()
	) {
		unset( $headers, $body, $options );
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
		);
		return HttpResponse::failure( 'Could not resolve host.' );
	}
}

/**
 * Backup retention (BackupRetentionStage), the history it works from, the
 * max_archives housekeeping, the bookkeeping of failed backups and the
 * uninstaller: none of them may delete the only copy of a backup, a newer
 * backup, or a file somebody put back by hand.
 */
class BackupRetentionTest extends TestCase {

	/**
	 * Site id of the fake Drive connection.
	 */
	const SITE = 'aaaaaaaaaaaaaaaa';

	/**
	 * Drive folder of the backups.
	 */
	const FOLDER = 'folder1';

	/**
	 * One day.
	 */
	const DAY = 86400;

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * The container shcm_bootstrap() returns.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Backups.
	 *
	 * @var BackupManager
	 */
	protected $backups;

	/**
	 * Fake Drive.
	 *
	 * @var RetentionDrive
	 */
	protected $drive;

	/**
	 * Build the backup module on a scratch storage directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir     = sys_get_temp_dir() . '/shcm-retention-' . bin2hex( random_bytes( 6 ) );
		$this->storage = new Storage( $this->dir . '/storage' );
		$this->storage->prepare();
		$this->logger = new Logger( $this->storage, 'info' );
		$this->drive  = new RetentionDrive();

		// BackupRetentionStage::archivesInUse() asks shcm_bootstrap() for the
		// job store. shcm_bootstrap() keeps the first container it returns
		// for the rest of the process, so this test configures that one
		// (marked booted, so no WordPress hooks are registered) instead of
		// replacing it.
		$booted = new \ReflectionProperty( Plugin::class, 'booted' );
		if ( PHP_VERSION_ID < 80100 ) {
			$booted->setAccessible( true );
		}
		$booted->setValue( Plugin::instance(), true );
		$this->plugin = shcm_bootstrap();
		$this->configure( $this->plugin, array() );

		$this->backups = new BackupManager( $this->plugin );
		$this->backups->setService( 'connection', new RetentionConnection() );
		$this->backups->setService( 'drive', $this->drive );
		$this->plugin->setService( 'backups', $this->backups );
	}

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->logger->close( 'plugin' );
		Storage::rmdirRecursive( $this->dir );
		parent::tearDown();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Point a container at this test's storage, logger and settings.
	 *
	 * @param Plugin $plugin   Container.
	 * @param array  $settings Settings over the defaults.
	 * @return Plugin
	 */
	private function configure( Plugin $plugin, array $settings ) {
		$plugin->setService( 'settings', new RetentionSettings( $settings ) );
		$plugin->setService( 'storage', $this->storage );
		$plugin->setService( 'logger', $this->logger );
		$plugin->setService( 'jobs', new JobStore( $this->storage ) );
		return $plugin;
	}

	/**
	 * The scheduler with the given settings, sharing this test's backups.
	 *
	 * @param array $settings Settings over the defaults.
	 * @return RetentionScheduler
	 */
	private function scheduler( array $settings ) {
		$plugin = $this->configure(
			new Plugin(),
			array_merge(
				array(
					'cleanup_temp_hours' => 0,
					'retention_days'     => 0,
				),
				$settings
			)
		);
		$plugin->setService( 'backups', $this->backups );
		return new RetentionScheduler( $plugin );
	}

	/**
	 * The name a backup run gives its archive.
	 *
	 * @param string $date Ymd.
	 * @param string $hex  One hex digit, repeated as the random suffix.
	 * @return string
	 */
	private static function backupName( $date, $hex ) {
		return 'example.com-backup-' . $date . '-0300-' . str_repeat( $hex, 16 ) . '.wpress';
	}

	/**
	 * Put a file in the archive directory.
	 *
	 * @param string $name  Base name.
	 * @param int    $mtime Modification time.
	 * @return string Path.
	 */
	private function archive( $name, $mtime ) {
		$path = $this->storage->archives() . '/' . $name;
		file_put_contents( $path, 'archive ' . $name );
		touch( $path, $mtime );
		return $path;
	}

	/**
	 * Write a complete archive (with its footer).
	 *
	 * @param string $name  Base name.
	 * @param int    $mtime Modification time.
	 * @return string Path.
	 */
	private function completeArchive( $name, $mtime ) {
		$path   = $this->storage->archives() . '/' . $name;
		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->addString( 'manifest.json', '{"type":"backup"}' );
		$writer->addString( 'files/readme.txt', str_repeat( "backup content\n", 500 ) );
		$writer->close();
		$writer->release();
		touch( $path, $mtime );
		clearstatcache( true, $path );
		return $path;
	}

	/**
	 * Archives left on the server, sorted.
	 *
	 * @return string[]
	 */
	private function left() {
		clearstatcache();
		$names = array_map( 'basename', (array) glob( $this->storage->archives() . '/*.wpress' ) );
		sort( $names );
		return $names;
	}

	/**
	 * A Drive copy of a backup, with the appProperties the upload stage sets.
	 *
	 * @param string   $id       File id.
	 * @param string   $name     File name.
	 * @param int      $uploaded When it reached Drive.
	 * @param int|null $made     When the backup was made (shcm_time); null for
	 *                           a file uploaded before the property existed.
	 * @param string   $job      History entry of the backup (shcm_job).
	 * @return void
	 */
	private function driveBackup( $id, $name, $uploaded, $made, $job ) {
		$app = array(
			'shcm_site' => self::SITE,
			'shcm_kind' => 'backup',
			'shcm_job'  => $job,
			'shcm_v'    => '1',
		);
		if ( null !== $made ) {
			$app['shcm_time'] = (string) $made;
		}
		$this->drive->add( $id, $name, $uploaded, self::FOLDER, $app );
	}

	/**
	 * Job parameters of a backup run (or, with $extra, an upload-only job).
	 *
	 * @param string $archive Archive base name.
	 * @param array  $backup  Backup settings over the defaults.
	 * @param array  $extra   Further parameters.
	 * @return array
	 */
	private function params( $archive, array $backup, array $extra = array() ) {
		return array_merge(
			array(
				'archive_path' => $this->storage->archives() . '/' . $archive,
				'background'   => true,
				'backup'       => array_merge(
					array(
						'kind'        => 'backup',
						'trigger'     => 'schedule',
						'gdrive'      => true,
						'keep_local'  => 10,
						'keep_remote' => 10,
					),
					$backup
				),
			),
			$extra
		);
	}

	/**
	 * Run the retention stage of a job, as JobRunner would.
	 *
	 * @param array      $params Job parameters.
	 * @param array      $remote This run's upload result (shared remote_upload).
	 * @param array|null $entry  History entry startBackup() records for the job.
	 * @return Job The job, completed.
	 */
	private function runRetention( array $params, array $remote = array(), $entry = null ) {
		$job = Job::create( Job::TYPE_EXPORT, $params, array( 'retention' ) );
		if ( null !== $entry ) {
			$this->backups->history()->record( $job->id(), $entry );
		}
		if ( ! empty( $remote ) ) {
			$job->setShared( 'remote_upload', $remote );
		}
		$this->plugin->jobs()->save( $job );

		$stage  = new BackupRetentionStage( $this->plugin->settings(), $this->storage, $this->logger, $this->backups );
		$result = $stage->run( $job, new Budget( 30, 512 * 1024 * 1024 ) );
		$this->assertTrue( $result->isSuccess() );

		$job->set( 'status', Job::STATUS_COMPLETED );
		$this->plugin->jobs()->save( $job );
		return $job;
	}

	/**
	 * The history entry startBackup() records for a new run.
	 *
	 * @param bool $gdrive Whether the run uploads to Drive.
	 * @return array
	 */
	private static function running( $gdrive ) {
		return array(
			'kind'   => 'backup',
			'status' => 'running',
			'remote' => array( 'status' => $gdrive ? 'pending' : 'off' ),
		);
	}

	/**
	 * A finished backup in the history.
	 *
	 * @param string $id      Entry id.
	 * @param string $archive Archive base name.
	 * @param array  $remote  Remote fields.
	 * @param array  $local   Local fields.
	 * @return void
	 */
	private function recordBackup( $id, $archive, array $remote, array $local = array( 'kept' => true ) ) {
		$this->backups->history()->record(
			$id,
			array(
				'kind'    => 'backup',
				'status'  => isset( $remote['status'] ) && in_array( $remote['status'], array( 'failed', 'pending' ), true ) ? 'partial' : 'success',
				'archive' => $archive,
				'local'   => $local,
				'remote'  => $remote,
			)
		);
	}

	/**
	 * Warning messages of a job.
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private static function warnings( Job $job ) {
		return array_values( array_column( (array) $job->get( 'warnings', array() ), 'message' ) );
	}

	/**
	 * Ids of the Drive files left, sorted.
	 *
	 * @return string[]
	 */
	private function driveLeft() {
		$ids = array_map( 'strval', array_keys( $this->drive->files ) );
		sort( $ids );
		return $ids;
	}

	/* ------------------------------------------------------------------
	 * Google Drive retention
	 * ------------------------------------------------------------------ */

	/**
	 * Retrying the upload of an older backup gives it the newest upload
	 * time on Drive; ranked by that, it pushed out the genuinely newer
	 * backup. Ranked by when the backup was made, only the oldest one goes.
	 *
	 * @return void
	 */
	public function testRetryingAnOlderUploadNeverDeletesANewerBackupFromDrive() {
		$now   = time();
		$old   = self::backupName( '20260923', 'a' );
		$newer = self::backupName( '20260925', 'b' );

		$this->driveBackup( 'fileD', self::backupName( '20260918', 'd' ), $now - 10 * self::DAY, $now - 10 * self::DAY, 'jobD' );
		$this->recordBackup( 'jobD', self::backupName( '20260918', 'd' ), array( 'status' => 'uploaded', 'file_id' => 'fileD' ), array( 'kept' => false ) );
		// Monday's backup: its upload failed, the archive stayed here.
		$this->archive( $old, $now - 5 * self::DAY );
		$this->recordBackup( 'jobA', $old, array( 'status' => 'failed', 'error' => 'Google Drive is not connected.' ) );
		$this->driveBackup( 'fileB', $newer, $now - 3 * self::DAY, $now - 3 * self::DAY, 'jobB' );
		$this->recordBackup( 'jobB', $newer, array( 'status' => 'uploaded', 'file_id' => 'fileB' ), array( 'kept' => false ) );
		$this->driveBackup( 'fileC', self::backupName( '20260927', 'c' ), $now - self::DAY, $now - self::DAY, 'jobC' );
		$this->recordBackup( 'jobC', self::backupName( '20260927', 'c' ), array( 'status' => 'uploaded', 'file_id' => 'fileC' ), array( 'kept' => false ) );

		// Today the admin retries Monday's upload (startUpload(archive, 'backup', 'jobA')).
		$this->driveBackup( 'fileA', $old, $now, $now - 5 * self::DAY, 'jobA' );
		$this->backups->history()->record( 'jobA', array( 'remote' => array( 'status' => 'pending', 'error' => '' ) ) );
		$this->runRetention(
			$this->params(
				$old,
				array(
					'trigger'     => 'manual',
					'keep_remote' => 2,
				),
				array(
					'upload_only' => true,
					'history_id'  => 'jobA',
				)
			),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'fileA',
				'folder_id' => self::FOLDER,
			)
		);

		$this->assertSame( array( 'fileD' ), $this->drive->deleted, 'Only the oldest backup is beyond "keep 2" (plus the retried upload).' );
		$this->assertSame( array( 'fileA', 'fileB', 'fileC' ), $this->driveLeft() );
		$history = $this->backups->history();
		$this->assertSame( 'deleted', $history->get( 'jobD' )['remote']['status'] );
		$this->assertSame( 'uploaded', $history->get( 'jobB' )['remote']['status'] );
		$this->assertSame( 'uploaded', $history->get( 'jobC' )['remote']['status'] );
	}

	/**
	 * Files uploaded before shcm_time existed rank by their upload time,
	 * together with the files that carry it.
	 *
	 * @return void
	 */
	public function testDriveFilesWithoutABackupTimeRankByTheirUploadTime() {
		$now     = time();
		$current = self::backupName( '20260928', 'e' );
		// Two backups from before the property existed.
		$this->driveBackup( 'legacy1', self::backupName( '20260927', '1' ), $now - self::DAY, null, 'jobL1' );
		$this->driveBackup( 'legacy2', self::backupName( '20260926', '2' ), $now - 2 * self::DAY, null, 'jobL2' );
		// A backup made four days ago, uploaded again this morning.
		$this->driveBackup( 'retried', self::backupName( '20260924', '3' ), $now - (int) ( self::DAY / 2 ), $now - 4 * self::DAY, 'jobR' );
		$this->driveBackup( 'fileE', $current, $now, $now, 'jobE' );
		$this->archive( $current, $now );

		$this->runRetention(
			$this->params( $current, array( 'keep_remote' => 3 ) ),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'fileE',
				'folder_id' => self::FOLDER,
			),
			self::running( true )
		);

		$this->assertSame( array( 'retried' ), $this->drive->deleted );
		$this->assertSame( array( 'fileE', 'legacy1', 'legacy2' ), $this->driveLeft() );
	}

	/**
	 * The same backup uploaded twice (shcm_job equal): the extra copy is
	 * deleted and does not take the place of another backup.
	 *
	 * @return void
	 */
	public function testASecondCopyOfTheSameBackupIsDeletedAndDoesNotCount() {
		$now     = time();
		$current = self::backupName( '20260928', 'f' );
		$this->driveBackup( 'copyB1', self::backupName( '20260927', 'b' ), $now - self::DAY, $now - self::DAY, 'jobB' );
		$this->driveBackup( 'copyB2', self::backupName( '20260927', 'b' ), $now - (int) ( self::DAY / 2 ), $now - self::DAY, 'jobB' );
		$this->driveBackup( 'fileC', self::backupName( '20260926', 'c' ), $now - 2 * self::DAY, $now - 2 * self::DAY, 'jobC' );
		$this->driveBackup( 'fileD', self::backupName( '20260925', 'd' ), $now - 3 * self::DAY, $now - 3 * self::DAY, 'jobD' );
		$this->driveBackup( 'fileE', self::backupName( '20260924', 'e' ), $now - 4 * self::DAY, $now - 4 * self::DAY, 'jobE' );
		$this->driveBackup( 'fileF', $current, $now, $now, 'jobF' );
		$this->archive( $current, $now );

		$this->runRetention(
			$this->params( $current, array( 'keep_remote' => 3 ) ),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'fileF',
				'folder_id' => self::FOLDER,
			),
			self::running( true )
		);

		$deleted = $this->drive->deleted;
		sort( $deleted );
		$this->assertSame( array( 'copyB1', 'fileD', 'fileE' ), $deleted, 'The extra copy goes, and three different backups stay.' );
		$this->assertSame( array( 'copyB2', 'fileC', 'fileF' ), $this->driveLeft() );
	}

	/**
	 * The file this run uploaded stays even when it is older than the
	 * backups "keep" allows, and it wins over an earlier copy of itself.
	 *
	 * @return void
	 */
	public function testTheFileJustUploadedIsAlwaysKept() {
		$now = time();
		$old = self::backupName( '20260923', 'a' );
		$this->driveBackup( 'fileC', self::backupName( '20260927', 'c' ), $now - self::DAY, $now - self::DAY, 'jobC' );
		$this->driveBackup( 'fileB', self::backupName( '20260926', 'b' ), $now - 2 * self::DAY, $now - 2 * self::DAY, 'jobB' );
		// The first upload of this backup did reach Drive, although it was
		// recorded as failed; the retry sends it again.
		$this->driveBackup( 'firstA', $old, $now - 5 * self::DAY, $now - 5 * self::DAY, 'jobA' );
		$this->driveBackup( 'retryA', $old, $now, $now - 5 * self::DAY, 'jobA' );
		$this->archive( $old, $now - 5 * self::DAY );
		$this->recordBackup( 'jobA', $old, array( 'status' => 'pending' ) );

		$this->runRetention(
			$this->params(
				$old,
				array(
					'trigger'     => 'manual',
					'keep_remote' => 0,
				),
				array(
					'upload_only' => true,
					'history_id'  => 'jobA',
				)
			),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'retryA',
				'folder_id' => self::FOLDER,
			)
		);

		$this->assertSame( array( 'fileC', 'retryA' ), $this->driveLeft(), 'The newest backup and the file just uploaded stay.' );
		$deleted = $this->drive->deleted;
		sort( $deleted );
		$this->assertSame( array( 'fileB', 'firstA' ), $deleted );
	}

	/* ------------------------------------------------------------------
	 * Local retention
	 * ------------------------------------------------------------------ */

	/**
	 * A run without Drive judges older backups by their own upload record:
	 * a backup whose upload failed or is pending is the only copy and stays;
	 * backups on Drive or made without Drive go beyond keep_local.
	 *
	 * @return void
	 */
	public function testALocalOnlyRunNeverDeletesBackupsWhoseUploadFailedOrIsPending() {
		$now     = time();
		$history = $this->backups->history();
		$older   = array(
			'uploaded1' => array( self::backupName( '20260927', '1' ), array( 'status' => 'uploaded', 'file_id' => 'f1' ) ),
			'failed'    => array( self::backupName( '20260926', '2' ), array( 'status' => 'failed', 'error' => 'Google Drive needs to be reconnected.' ) ),
			'pending'   => array( self::backupName( '20260925', '3' ), array( 'status' => 'pending', 'error' => '' ) ),
			'uploaded2' => array( self::backupName( '20260924', '4' ), array( 'status' => 'uploaded', 'file_id' => 'f4' ) ),
			'deleted'   => array( self::backupName( '20260923', '5' ), array( 'status' => 'deleted', 'file_id' => 'f5' ) ),
			'off'       => array( self::backupName( '20260922', '6' ), array( 'status' => 'off' ) ),
		);
		$hour = 1;
		foreach ( $older as $id => $backup ) {
			$this->archive( $backup[0], $now - $hour++ * 3600 );
			$this->recordBackup( $id, $backup[0], $backup[1] );
		}

		// "Back Up Now" with "Store on Google Drive" unticked.
		$current = self::backupName( '20260928', 'c' );
		$this->archive( $current, $now );
		$job = $this->runRetention(
			$this->params(
				$current,
				array(
					'trigger'    => 'manual',
					'gdrive'     => false,
					'keep_local' => 2,
				)
			),
			array(),
			self::running( false )
		);

		$expected = array( $current, $older['uploaded1'][0], $older['failed'][0], $older['pending'][0] );
		sort( $expected );
		$this->assertSame( $expected, $this->left() );
		foreach ( array( 'uploaded2', 'deleted', 'off' ) as $id ) {
			$this->assertFalse( $history->get( $id )['local']['kept'], $id );
		}
		foreach ( array( 'failed', 'pending', 'uploaded1' ) as $id ) {
			$this->assertTrue( $history->get( $id )['local']['kept'], $id );
		}
		$warnings = self::warnings( $job );
		$this->assertCount( 1, $warnings );
		$this->assertStringStartsWith( '2 older backups exist only on this server because they were not uploaded to Google Drive', $warnings[0] );
	}

	/**
	 * "Keep 0 on this server" deletes a backup only once it has a copy on
	 * Drive; backups made before Drive was used stay and are reported.
	 *
	 * @return void
	 */
	public function testKeepZeroDeletesOnlyBackupsThatHaveACopyOnDrive() {
		$now   = time();
		$older = array(
			'uploaded' => array( self::backupName( '20260927', '1' ), array( 'status' => 'uploaded', 'file_id' => 'f1' ) ),
			'off1'     => array( self::backupName( '20260926', '2' ), array( 'status' => 'off' ) ),
			'deleted'  => array( self::backupName( '20260925', '3' ), array( 'status' => 'deleted', 'file_id' => 'f3' ) ),
			'off2'     => array( self::backupName( '20260924', '4' ), array( 'status' => 'off' ) ),
			'failed'   => array( self::backupName( '20260923', '5' ), array( 'status' => 'failed', 'error' => 'Quota exceeded.' ) ),
		);
		$day = 1;
		foreach ( $older as $id => $backup ) {
			$this->archive( $backup[0], $now - $day++ * self::DAY );
			$this->recordBackup( $id, $backup[0], $backup[1] );
		}
		$current = self::backupName( '20260928', 'c' );
		$this->archive( $current, $now );
		$this->driveBackup( 'fileC', $current, $now, $now, 'current' );

		$job = $this->runRetention(
			$this->params( $current, array( 'keep_local' => 0 ) ),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'fileC',
				'folder_id' => self::FOLDER,
			),
			self::running( true )
		);

		$expected = array( $older['off1'][0], $older['off2'][0], $older['failed'][0] );
		sort( $expected );
		$this->assertSame( $expected, $this->left(), 'This run\'s archive (now on Drive) and the backups with a Drive copy are deleted; the others stay.' );
		$warnings = self::warnings( $job );
		$this->assertCount( 2, $warnings );
		$this->assertStringStartsWith( '1 older backup exists only on this server because it was not uploaded to Google Drive', $warnings[0] );
		$this->assertStringStartsWith( '2 older backups were made before Google Drive was used and have no copy there', $warnings[1] );
	}

	/**
	 * A backup's archive was deleted by retention; the file of that name the
	 * user copied back to restore it is not retention's to delete.
	 *
	 * @return void
	 */
	public function testAnArchivePutBackForARestoreIsNotDeleted() {
		$now     = time();
		$pruned  = self::backupName( '20260901', '1' );
		$partial = self::backupName( '20260902', '2' );
		$this->recordBackup(
			'job1',
			$pruned,
			array( 'status' => 'uploaded', 'file_id' => 'f1' ),
			array(
				'kept'    => false,
				'deleted' => $now - 20 * self::DAY,
			)
		);
		$this->recordBackup( 'job2', $partial, array( 'status' => 'uploaded', 'file_id' => 'f2' ), array( 'kept' => false ) );
		// Downloaded from Drive and copied over SFTP yesterday.
		$this->archive( $pruned, $now - 3600 );
		$this->archive( $partial, $now - 7200 );

		$current = self::backupName( '20260928', 'c' );
		$this->archive( $current, $now );
		$this->driveBackup( 'fileC', $current, $now, $now, 'current' );
		$this->runRetention(
			$this->params( $current, array( 'keep_local' => 0 ) ),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'fileC',
				'folder_id' => self::FOLDER,
			),
			self::running( true )
		);

		$expected = array( $pruned, $partial );
		sort( $expected );
		$this->assertSame( $expected, $this->left(), 'Only this run\'s archive (on Drive, keep 0) was deleted.' );
	}

	/**
	 * After the clock was stepped back the previous archive looks newer than
	 * this run's; the run's own archive still counts first.
	 *
	 * @return void
	 */
	public function testTheRunsOwnArchiveIsKeptAfterTheClockWasSteppedBack() {
		$now      = time();
		$previous = self::backupName( '20260928', '1' );
		// Written before NTP stepped the clock back by 15 minutes.
		$this->archive( $previous, $now + 600 );
		$this->recordBackup( 'job1', $previous, array( 'status' => 'off' ) );
		$current = 'example.com-backup-20260928-0305-' . str_repeat( '2', 16 ) . '.wpress';
		$this->archive( $current, $now );

		$this->runRetention(
			$this->params(
				$current,
				array(
					'trigger'    => 'manual',
					'gdrive'     => false,
					'keep_local' => 1,
				)
			),
			array(),
			self::running( false )
		);

		$this->assertSame( array( $current ), $this->left() );
	}

	/**
	 * A retried upload is not "this run's backup": the archive it sends
	 * keeps its place by age and newer backups are not pushed out for it.
	 *
	 * @return void
	 */
	public function testARetriedUploadDoesNotGiveTheOlderArchivePriority() {
		$now   = time();
		$old   = self::backupName( '20260925', 'a' );
		$newer = self::backupName( '20260927', 'b' );
		$mid   = self::backupName( '20260926', 'c' );
		$this->archive( $newer, $now - self::DAY );
		$this->recordBackup( 'jobB', $newer, array( 'status' => 'uploaded', 'file_id' => 'fileB' ) );
		$this->archive( $mid, $now - 2 * self::DAY );
		$this->recordBackup( 'jobC', $mid, array( 'status' => 'uploaded', 'file_id' => 'fileC' ) );
		$this->archive( $old, $now - 3 * self::DAY );
		$this->recordBackup( 'jobA', $old, array( 'status' => 'pending', 'error' => '' ) );
		$this->driveBackup( 'fileA', $old, $now, $now - 3 * self::DAY, 'jobA' );

		$this->runRetention(
			$this->params(
				$old,
				array(
					'trigger'    => 'manual',
					'keep_local' => 1,
				),
				array(
					'upload_only' => true,
					'history_id'  => 'jobA',
				)
			),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'fileA',
				'folder_id' => self::FOLDER,
			)
		);

		$this->assertSame( array( $newer ), $this->left(), 'The newest backup is the one kept; the retried (now uploaded) archive goes by age.' );
		$this->assertFalse( $this->backups->history()->get( 'jobA' )['local']['kept'] );
	}

	/**
	 * The run's own failed upload is not "an older backup"; an older backup
	 * whose upload failed is, and is counted once.
	 *
	 * @return void
	 */
	public function testTheStrandedWarningCountsOnlyOlderBackups() {
		$now     = time();
		$current = self::backupName( '20260928', '1' );
		$this->archive( $current, $now );
		$failed = array(
			'status' => 'failed',
			'error'  => 'Google Drive is not connected.',
		);

		$first = $this->runRetention( $this->params( $current, array( 'keep_local' => 0 ) ), $failed, self::running( true ) );

		$this->assertSame( array(), self::warnings( $first ), 'The only backup on the site is not an older backup.' );
		$this->assertSame( array( $current ), $this->left() );

		// It is recorded as partial with a failed upload (onJobCompleted()).
		$this->backups->history()->record( $first->id(), array( 'status' => 'partial', 'local' => array( 'kept' => true ), 'remote' => $failed ) );
		$next = self::backupName( '20260929', '2' );
		$this->archive( $next, $now + 60 );
		$second = $this->runRetention( $this->params( $next, array( 'keep_local' => 0 ) ), $failed, self::running( true ) );

		$warnings = self::warnings( $second );
		$this->assertCount( 1, $warnings );
		$this->assertStringStartsWith( '1 older backup exists only on this server because it was not uploaded to Google Drive', $warnings[0] );
		$expected = array( $current, $next );
		sort( $expected );
		$this->assertSame( $expected, $this->left() );
	}

	/* ------------------------------------------------------------------
	 * History
	 * ------------------------------------------------------------------ */

	/**
	 * The history cap drops entries of skipped, failed and pruned runs
	 * before entries whose archive is still on the server.
	 *
	 * @return void
	 */
	public function testTheHistoryCapForgetsFinishedRunsBeforeBackupsStillOnTheServer() {
		$history = new History( new ConfigStore( $this->dir . '/history-only' ) );
		$history->record(
			'kept',
			array(
				'kind'    => 'backup',
				'status'  => 'success',
				'archive' => self::backupName( '20250101', 'a' ),
				'local'   => array( 'kept' => true ),
			)
		);
		$history->record(
			'pruned',
			array(
				'kind'    => 'backup',
				'status'  => 'success',
				'archive' => self::backupName( '20250102', 'b' ),
				'local'   => array(
					'kept'    => false,
					'deleted' => time(),
				),
			)
		);
		$history->record(
			'failed',
			array(
				'kind'    => 'backup',
				'status'  => 'failed',
				'archive' => self::backupName( '20250103', 'c' ),
				'local'   => array( 'kept' => false ),
			)
		);
		for ( $i = 1; $i <= History::MAX + 50; $i++ ) {
			$history->record(
				'skipped-' . $i,
				array(
					'kind'   => 'backup',
					'status' => 'skipped',
				)
			);
		}

		$all = $history->all( 10000 );
		$this->assertCount( History::MAX, $all );
		$this->assertNotNull( $history->get( 'kept' ), 'The oldest entry, whose archive is still here, is kept.' );
		$this->assertSame( 'kept', $all[ count( $all ) - 1 ]['id'], 'The order stays newest first.' );
		$this->assertSame( 'skipped-' . ( History::MAX + 50 ), $all[0]['id'] );
		$this->assertNull( $history->get( 'pruned' ) );
		$this->assertNull( $history->get( 'failed' ) );
		$this->assertNull( $history->get( 'skipped-1' ) );
		$this->assertSame( array( self::backupName( '20250101', 'a' ) ), $history->archiveNames() );
	}

	/**
	 * "Keep 100 on this server" keeps 100 archives, however many runs
	 * (skipped ones included) the history has recorded since.
	 *
	 * @return void
	 */
	public function testKeepOneHundredLocalBackupsKeepsOneHundredArchives() {
		$history = $this->backups->history();
		$start   = time() - 200 * self::DAY;
		$names   = array();
		for ( $i = 1; $i <= 110; $i++ ) {
			$name    = sprintf( 'example.com-backup-%s-0300-%016x.wpress', gmdate( 'Ymd', $start + $i * self::DAY ), $i );
			$names[] = $name;
			$this->archive( $name, $start + $i * self::DAY );
			$job = $this->runRetention(
				$this->params(
					$name,
					array(
						'gdrive'     => false,
						'keep_local' => 100,
					)
				),
				array(),
				self::running( false )
			);
			// onJobCompleted().
			$history->record(
				$job->id(),
				array(
					'status' => 'success',
					'local'  => array( 'kept' => true ),
					'remote' => array( 'status' => 'off' ),
				)
			);
			// Runs skipped in between (site busy, schedule paused).
			$history->record( 'skipped-' . $i . '-a', array( 'kind' => 'backup', 'status' => 'skipped' ) );
			$history->record( 'skipped-' . $i . '-b', array( 'kind' => 'backup', 'status' => 'skipped' ) );
		}

		$expected = array_slice( $names, 10 );
		sort( $expected );
		$this->assertSame( $expected, $this->left(), 'The 100 newest backups are kept and the 10 oldest deleted.' );
	}

	/* ------------------------------------------------------------------
	 * max_archives housekeeping
	 * ------------------------------------------------------------------ */

	/**
	 * max_archives counts manual exports only: an archive the history knows,
	 * whatever its name, and an archive with a backup's name, which the
	 * history may have lost, are neither counted nor deleted.
	 *
	 * @return void
	 */
	public function testMaxArchivesNeverDeletesBackups() {
		$now     = time();
		$history = $this->backups->history();
		$known   = self::backupName( '20260101', '1' );
		$legacy  = 'legacy-backup-7f3a.wpress';
		$untyped = 'example.com-20251201-101010-' . str_repeat( '2', 16 ) . '.wpress';
		$forgot  = self::backupName( '20260102', '3' );
		$manual  = array();
		$this->archive( $known, $now - 100 * self::DAY );
		$this->recordBackup( 'job1', $known, array( 'status' => 'off' ) );
		$this->archive( $legacy, $now - 99 * self::DAY );
		$this->recordBackup( 'job2', $legacy, array( 'status' => 'failed' ) );
		$this->archive( $untyped, $now - 98 * self::DAY );
		$history->record( 'job3', array( 'archive' => $untyped ) );
		$this->archive( $forgot, $now - 97 * self::DAY );
		for ( $i = 1; $i <= 4; $i++ ) {
			$manual[ $i ] = 'example.com-2026090' . $i . '-120000-' . str_repeat( dechex( 10 + $i ), 16 ) . '.wpress';
			$this->archive( $manual[ $i ], $now - ( 10 - $i ) * self::DAY );
		}

		$this->assertSame( 2, $this->scheduler( array( 'max_archives' => 2 ) )->pruneNow( 2 ) );

		$expected = array( $known, $legacy, $untyped, $forgot, $manual[3], $manual[4] );
		sort( $expected );
		$this->assertSame( $expected, $this->left(), 'The two oldest manual exports go; no backup does.' );
	}

	/**
	 * A damaged history reads as "no backups": max_archives would count every
	 * backup as a manual export. The whole pass is skipped instead.
	 *
	 * @return void
	 */
	public function testMaxArchivesIsSkippedWhileTheHistoryIsDamaged() {
		$now     = time();
		$failed  = self::backupName( '20260920', '1' );
		$current = self::backupName( '20260927', '2' );
		$this->archive( $failed, $now - 8 * self::DAY );
		$this->recordBackup( 'job1', $failed, array( 'status' => 'failed' ) );
		$this->archive( $current, $now - self::DAY );
		$this->recordBackup( 'job2', $current, array( 'status' => 'uploaded', 'file_id' => 'f2' ) );
		$manual = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$manual[] = 'example.com-2026092' . $i . '-120000-' . str_repeat( dechex( 10 + $i ), 16 ) . '.wpress';
			$this->archive( end( $manual ), $now - ( 5 - $i ) * self::DAY );
		}
		$before = $this->left();

		// Cut short by a partial copy of wp-content or a full disk.
		$path = $this->backups->store()->path( History::DOCUMENT );
		$raw  = (string) file_get_contents( $path );
		file_put_contents( $path, substr( $raw, 0, (int) ( strlen( $raw ) / 2 ) ) );
		$this->assertTrue( $this->backups->store()->damaged( History::DOCUMENT ) );

		$scheduler = $this->scheduler( array( 'max_archives' => 1 ) );
		$this->assertSame( 0, $scheduler->pruneNow( 1 ) );
		$this->assertSame( $before, $this->left() );
		$scheduler->runCleanup();
		$this->assertSame( $before, $this->left(), 'The daily cleanup deletes nothing either.' );

		$this->logger->close( 'plugin' );
		$this->assertStringContainsString( 'the backup history could not be read', (string) file_get_contents( $this->logger->path( 'plugin' ) ) );
	}

	/**
	 * The next write after the history was damaged starts a new file and
	 * keeps the damaged one next to it instead of overwriting it.
	 *
	 * @return void
	 */
	public function testTheNextRecordSetsADamagedHistoryAside() {
		$history = $this->backups->history();
		$this->recordBackup( 'job1', self::backupName( '20260920', '1' ), array( 'status' => 'failed' ) );
		$this->recordBackup( 'job2', self::backupName( '20260927', '2' ), array( 'status' => 'uploaded', 'file_id' => 'f2' ) );
		$store   = $this->backups->store();
		$path    = $store->path( History::DOCUMENT );
		$raw     = (string) file_get_contents( $path );
		$damaged = substr( $raw, 0, (int) ( strlen( $raw ) / 2 ) );
		file_put_contents( $path, $damaged );

		$history->record(
			'job3',
			array(
				'kind'   => 'backup',
				'status' => 'running',
			)
		);

		$aside = glob( $store->directory() . '/' . History::DOCUMENT . '.damaged-*.php' );
		$this->assertCount( 1, $aside );
		$this->assertSame( $damaged, file_get_contents( $aside[0] ), 'The damaged file is kept as it was.' );
		$this->assertStringStartsWith( ConfigStore::GUARD, $damaged, 'It is still guarded against being served.' );
		$this->assertFalse( $store->damaged( History::DOCUMENT ) );
		$this->assertSame( array( 'job3' ), array_column( $history->all( 100 ), 'id' ) );
	}

	/* ------------------------------------------------------------------
	 * Failed backups
	 * ------------------------------------------------------------------ */

	/**
	 * A backup that failed after its archive was finished (while verifying
	 * or uploading) keeps the archive, recorded as kept with a failed
	 * upload, and later runs with "keep 0" leave it alone.
	 *
	 * @return void
	 */
	public function testAFailedBackupWithACompleteArchiveKeepsIt() {
		$history = $this->backups->history();
		$name    = self::backupName( '20260927', '1' );
		$path    = $this->completeArchive( $name, time() - self::DAY );
		$this->assertNotNull( Reader::readFooter( $path ) );

		$job = Job::create( Job::TYPE_EXPORT, $this->params( $name, array( 'keep_local' => 0 ) ), array( 'verify', 'upload', 'retention' ) );
		$history->record( $job->id(), self::running( true ) + array( 'trigger' => 'schedule' ) );
		$job->set( 'status', Job::STATUS_FAILED );
		$this->plugin->jobs()->save( $job );
		$error = new \RuntimeException( 'The request advancing this job stopped three times in a row.' );
		$this->backups->onJobFailed( $job, $error );

		$this->assertFileExists( $path );
		$entry = $history->get( $job->id() );
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( $name, $entry['archive'] );
		$this->assertSame( $error->getMessage(), $entry['error'], 'The message comes from the exception JobRunner passes.' );
		$this->assertTrue( $entry['local']['kept'] );
		$this->assertSame( 'failed', $entry['remote']['status'] );
		$this->assertSame( $error->getMessage(), $entry['remote']['error'] );

		// The next night Drive works again, "keep 0 on this server".
		$next = self::backupName( '20260928', '2' );
		$this->archive( $next, time() );
		$this->driveBackup( 'fileN', $next, time(), time(), 'next' );
		$run = $this->runRetention(
			$this->params( $next, array( 'keep_local' => 0 ) ),
			array(
				'status'    => 'uploaded',
				'file_id'   => 'fileN',
				'folder_id' => self::FOLDER,
			),
			self::running( true )
		);
		$this->assertSame( array( $name ), $this->left() );
		$warnings = self::warnings( $run );
		$this->assertCount( 1, $warnings );
		$this->assertStringStartsWith( '1 older backup exists only on this server', $warnings[0] );
	}

	/**
	 * A backup that failed while writing its archive leaves a partial file:
	 * it is deleted and recorded as not kept.
	 *
	 * @return void
	 */
	public function testAFailedBackupWithAPartialArchiveDeletesIt() {
		$history = $this->backups->history();
		$name    = self::backupName( '20260927', '1' );
		$path    = $this->completeArchive( $name, time() );
		$handle  = fopen( $path, 'r+b' );
		ftruncate( $handle, (int) ( filesize( $path ) / 2 ) );
		fclose( $handle );
		clearstatcache( true, $path );
		$this->assertNull( Reader::readFooter( $path ) );

		$job = Job::create( Job::TYPE_EXPORT, $this->params( $name, array( 'keep_local' => 0 ) ), array( 'files', 'verify', 'upload', 'retention' ) );
		$history->record( $job->id(), self::running( true ) );
		$job->set( 'status', Job::STATUS_FAILED );
		$this->plugin->jobs()->save( $job );
		$this->backups->onJobFailed( $job, new \RuntimeException( 'Disk full while writing the archive.' ) );

		$this->assertFileDoesNotExist( $path );
		$entry = $history->get( $job->id() );
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertFalse( $entry['local']['kept'] );
		$this->assertSame( 'Disk full while writing the archive.', $entry['error'] );
		$this->assertSame( 'failed', $entry['remote']['status'] );
	}

	/* ------------------------------------------------------------------
	 * Uninstall
	 * ------------------------------------------------------------------ */

	/**
	 * What uninstall.php does with the Drive connection: revoke (best
	 * effort, here without network) and disconnect. The site's backup
	 * identity survives, so after a reinstall its earlier Drive backups are
	 * still found and pruned.
	 *
	 * @return void
	 */
	public function testUninstallKeepsTheSiteIdentity() {
		$store      = new ConfigStore( $this->storage->config() );
		$box        = new SecretBox( str_repeat( 'k', 40 ) );
		$connection = new Connection( $store, $box, 'fingerprint' );
		$connection->setCredentials( 'client.apps.googleusercontent.com', 'client-secret' );
		$connection->storeTokens( '1//refresh-token', 'ya29.access-token', 3600, OAuth::SCOPE );
		$connection->setFolder( 'folder1', 'Backups' );
		$site = $connection->siteId();

		$transport = new UnreachableTransport();
		try {
			$revoked = ( new OAuth( $connection, $transport, Endpoints::defaults() ) )->revoke();
		} catch ( \Throwable $e ) {
			$revoked = false;
		}
		$connection->disconnect();

		$this->assertFalse( $revoked );
		$this->assertCount( 1, $transport->requests, 'The grant was offered back to Google.' );
		$reinstalled = new Connection( $store, $box, 'fingerprint' );
		$this->assertNull( $reinstalled->refreshToken() );
		$this->assertSame( '', $reinstalled->status()['folder_id'] );
		$this->assertSame( $site, $reinstalled->siteId() );
		$reinstalled->storeTokens( '1//new-refresh-token', 'ya29.new-access-token', 3600, OAuth::SCOPE );
		$this->assertSame( $site, $reinstalled->siteId(), 'Connecting again after the reinstall keeps the identity.' );
	}

	/**
	 * uninstall.php itself (not a full uninstall): the connection file stays
	 * with the site id, without the tokens.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheUninstallScriptKeepsTheSiteIdentity() {
		$content = $this->dir . '/wp-content';
		$home    = $this->dir . '/site/';
		mkdir( $content . '/shcm-storage/config', 0755, true );
		mkdir( $home, 0755, true );
		define( 'WP_UNINSTALL_PLUGIN', 'sh-clone-migration/sh-clone-migration.php' );
		define( 'WP_CONTENT_DIR', $content );
		define( 'SHCM_SECRET_KEY', str_repeat( 's', 40 ) );
		// The WordPress functions uninstall.php calls, for this process only.
		$shims = $this->dir . '/uninstall-shims.php';
		file_put_contents(
			$shims,
			"<?php\n"
			. "function get_option( \$name, \$fallback = false ) { return \$fallback; }\n"
			. "function wp_clear_scheduled_hook( \$hook ) { return 0; }\n"
			. "function wp_unschedule_hook( \$hook ) { return 0; }\n"
			. "function delete_transient( \$name ) { return true; }\n"
			. 'function get_home_path() { return ' . var_export( $home, true ) . "; }\n"
		);
		require $shims;

		$store      = new ConfigStore( $content . '/shcm-storage/config' );
		$connection = new Connection( $store, SecretBox::fromWordPress() );
		$connection->setCredentials( 'client.apps.googleusercontent.com', 'client-secret' );
		$connection->storeTokens( '1//refresh-token', 'ya29.access-token', 3600, OAuth::SCOPE );
		$connection->setFolder( 'folder1', 'Backups' );
		$site = $connection->siteId();

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFileExists( $content . '/shcm-storage/config/gdrive.php' );
		$reinstalled = new Connection( new ConfigStore( $content . '/shcm-storage/config' ), SecretBox::fromWordPress() );
		$this->assertNull( $reinstalled->refreshToken() );
		$this->assertSame( $site, $reinstalled->siteId() );
	}
}
