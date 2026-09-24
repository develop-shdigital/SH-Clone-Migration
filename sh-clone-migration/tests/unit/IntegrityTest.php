<?php
/**
 * Integrity and reporting tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Admin\ArchiveSummary;
use SHCM\Archive\Catalog;
use SHCM\Archive\FileDigest;
use SHCM\Archive\Format;
use SHCM\Archive\Reader;
use SHCM\Archive\Verifier;
use SHCM\Archive\Writer;
use SHCM\Core\Settings;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Jobs\JobRunner;
use SHCM\Jobs\JobStore;
use SHCM\Jobs\StageResolver;
use SHCM\Logging\Logger;
use SHCM\Support\Json;

/**
 * A stage that raises many warnings in one step, as FilesStage does when a
 * cache directory is purged between the scan and the copy.
 */
class WarningStage extends AbstractStage {

	/**
	 * Warnings to raise.
	 *
	 * @var int
	 */
	public $count = 1200;

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'warn';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Warning';
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
		for ( $i = 1; $i <= $this->count; $i++ ) {
			$job->addWarning( sprintf( 'File vanished and is not in the archive: cache/f%04d.html', $i ) );
		}
		return $this->complete( 'done' );
	}
}

/**
 * Resolves the one warning stage.
 */
class WarningResolver implements StageResolver {

	/**
	 * Stage.
	 *
	 * @var WarningStage
	 */
	protected $stage;

	/**
	 * Constructor.
	 *
	 * @param WarningStage $stage Stage.
	 */
	public function __construct( WarningStage $stage ) {
		$this->stage = $stage;
	}

	/**
	 * Resolve.
	 *
	 * @param string $type      Type.
	 * @param string $stage_key Key.
	 * @return WarningStage|null
	 */
	public function resolve( $type, $stage_key ) {
		unset( $type );
		return 'warn' === $stage_key ? $this->stage : null;
	}

	/**
	 * Stages.
	 *
	 * @param string $type   Type.
	 * @param array  $params Params.
	 * @return string[]
	 */
	public function stagesFor( $type, array $params = array() ) {
		unset( $type, $params );
		return array( 'warn' );
	}
}

/**
 * What an archive claims about itself (counts, completeness, checksums) has to
 * be what it holds, and damage has to be caught.
 */
class IntegrityTest extends TestCase {

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Create the scratch directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/shcm-integrity-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/archives', 0755, true );
	}

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Storage::rmdirRecursive( $this->dir );
		parent::tearDown();
	}

	/**
	 * An archive path in the scratch archive directory.
	 *
	 * @param string $name Base name.
	 * @return string
	 */
	protected function archivePath( $name ) {
		return $this->dir . '/archives/' . $name . '.wpress';
	}

	/**
	 * Write an archive whose manifest plans more than was written.
	 *
	 * @param string $path     Path.
	 * @param bool   $complete Close it with a footer.
	 * @return void
	 */
	protected function plannedArchive( $path, $complete ) {
		$writer = Writer::create( $path );
		$writer->addString(
			'manifest.json',
			Json::encode(
				array(
					'site'      => array( 'home' => 'https://example.test' ),
					'database'  => array( 'tables' => 12 ),
					'files'     => array( 'count' => 1000 ),
					'wordpress' => array(
						'version'      => '6.8',
						'table_prefix' => 'wp_',
					),
				)
			)
		);
		$writer->addString( 'database/tables/wp_options.sql', 'INSERT INTO wp_options VALUES (1);' );
		for ( $i = 0; $i < 7; $i++ ) {
			$writer->addString( 'files/wp-content/plugins/p/f' . $i . '.php', '<?php' );
		}
		if ( $complete ) {
			$writer->close(
				array(
					'files'         => 7,
					'files_skipped' => 993,
					'tables'        => 11,
					'database'      => array(
						'included'  => true,
						'prefix'    => 'wp_',
						'tables'    => 11,
						'rows'      => 500,
						'sql_bytes' => 12345,
					),
					'groups'        => array(
						'plugins' => array(
							'entries' => 7,
							'bytes'   => 35,
						),
					),
				)
			);
		} else {
			$writer->pause();
		}
		$writer->release();
	}

	public function testIncompleteArchiveClaimsNoContents() {
		$path = $this->archivePath( 'failed-0123456789abcdef' );
		$this->plannedArchive( $path, false );

		$info = ( new Catalog( new Storage( $this->dir ) ) )->describe( $path );
		$this->assertFalse( $info['complete'] );
		$this->assertSame( 0, $info['files'], 'the manifest plan is not the contents' );
		$this->assertSame( 0, $info['tables'] );

		$db = ArchiveSummary::database( $info );
		$this->assertTrue( $db['missing'] );
		$this->assertStringContainsString( 'unknown', $db['text'] );
		$this->assertStringContainsString( 'unknown', ArchiveSummary::files( $info ) );
	}

	public function testCompleteArchiveShowsWhatWasWrittenNotWhatWasPlanned() {
		$path = $this->archivePath( 'plain-0123456789abcdef' );
		$this->plannedArchive( $path, true );

		$info = ( new Catalog( new Storage( $this->dir ) ) )->describe( $path );
		$this->assertTrue( $info['complete'] );
		$this->assertSame( 7, $info['files'] );
		$this->assertSame( 11, $info['tables'] );
		$this->assertSame( 'wp_', $info['prefix'], 'descriptive fields still come from the manifest' );

		$line = ArchiveSummary::files( $info );
		$this->assertStringStartsWith( 'Files: 7 (plugins 7)', $line );
		$this->assertStringContainsString( '993 files skipped', $line );
		$this->assertStringContainsString( '11 tables, 500 rows', ArchiveSummary::database( $info )['text'] );
	}

	public function testResumeRefusesAnArchiveShorterThanItsSavedState() {
		$path   = $this->archivePath( 'short-0123456789abcdef' );
		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->addString( 'files/a.bin', random_bytes( 100000 ) );
		$state = $writer->pause();
		$writer->release();

		// Another request cut the file short after the state was saved.
		$handle = fopen( $path, 'r+b' );
		ftruncate( $handle, $state['size'] - 10 );
		fclose( $handle );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/shorter/' );
		Writer::resume( $state );
	}

	public function testDamagedDirectoryHeaderIsCaught() {
		$path   = $this->archivePath( 'header-0123456789abcdef' );
		$writer = Writer::create( $path );
		$writer->addString( 'manifest.json', '{}' );
		$writer->addDirectory( 'files/wp-content/uploads/empty' );
		$writer->close();
		$writer->release();

		$this->assertTrue( ( new Verifier( new Reader( $path ) ) )->verifyAll()['ok'] );

		// Give the directory a size, keeping the header length.
		$bytes = file_get_contents( $path );
		$start = strpos( $bytes, '"p":"files/wp-content/uploads/empty"' );
		$size  = strpos( $bytes, '"s":"', $start ) + 5;
		$field = strcspn( $bytes, '"', $size );
		$bytes = substr_replace( $bytes, str_pad( '5', $field, '0', STR_PAD_LEFT ), $size, $field );
		file_put_contents( $path, $bytes );

		$result = ( new Verifier( new Reader( $path ) ) )->verifyAll();
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( preg_grep( '/Damaged entry header/', $result['errors'] ) );
	}

	public function testLedgerSchemeTwoCoversLinkTargets() {
		$path    = $this->archivePath( 'links-0123456789abcdef' );
		$writer  = Writer::create( $path );
		$digest  = Format::initialHashState();
		$entries = array(
			$writer->addString( 'manifest.json', '{}' ),
			$writer->addSymlink( 'files/wp-content/themes/alias', 'target-a', array( 'mode' => 0777 ) ),
			$writer->addString( 'files/wp-content/themes/real/style.css', 'body{}' ),
		);
		foreach ( $entries as $entry ) {
			$digest = Format::advanceHash( $digest, Format::ledgerItem( 2, $entry['path'], $entry['type'], $entry['target'], $entry['mode'], $entry['hash'] ) );
		}
		$writer->close(
			array(
				'checksum_digest' => bin2hex( $digest ),
				'checksum_scheme' => 2,
			)
		);
		$writer->release();

		$this->assertTrue( ( new Verifier( new Reader( $path ) ) )->verifyAll()['ok'] );

		// Redirect the link. A link has no payload, so only the ledger can
		// notice.
		$bytes = file_get_contents( $path );
		file_put_contents( $path, str_replace( '"lt":"target-a"', '"lt":"target-b"', $bytes ) );

		$result = ( new Verifier( new Reader( $path ) ) )->verifyAll();
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( preg_grep( '/digest does not match/', $result['errors'] ) );
	}

	public function testOneShotDigestWhereHashContextsCannotBeSaved() {
		$path = $this->dir . '/one-shot.bin';
		file_put_contents( $path, random_bytes( 300000 ) );

		// First call only announces the long step; the second hashes.
		$state = FileDigest::advance( $path, array(), new Budget( 30, 0 ), false );
		$this->assertArrayNotHasKey( 'digest', $state );
		$state = FileDigest::advance( $path, $state, new Budget( 30, 0 ), false );
		$this->assertSame( hash_file( 'sha256', $path ), $state['digest'] );
		$this->assertFileDoesNotExist( $path . '.sha256-attempt', 'the attempt marker is removed after success' );
	}

	public function testOneShotDigestGivesUpAfterRepeatedKills() {
		$path = $this->dir . '/killed.bin';
		file_put_contents( $path, 'data' );
		$state = FileDigest::advance( $path, array(), new Budget( 30, 0 ), false );

		// Two earlier requests were killed inside hash_file(): each left its
		// attempt behind in the marker, which the job file never saw.
		file_put_contents( $path . '.sha256-attempt', '2' );
		$state = FileDigest::advance( $path, $state, new Budget( 30, 0 ), false );

		$this->assertTrue( $state['unavailable'] );
		$this->assertArrayNotHasKey( 'digest', $state );
		$this->assertFileDoesNotExist( $path . '.sha256-attempt' );
	}

	public function testOneShotDigestRetriesOnceAfterAKill() {
		$path = $this->dir . '/retry.bin';
		file_put_contents( $path, 'data' );
		$state = FileDigest::advance( $path, array(), new Budget( 30, 0 ), false );
		file_put_contents( $path . '.sha256-attempt', '1' );
		$state = FileDigest::advance( $path, $state, new Budget( 30, 0 ), false );
		$this->assertSame( hash( 'sha256', 'data' ), $state['digest'] );
	}

	public function testBinaryStringsSurviveJson() {
		$value = array(
			'name'  => "Preisliste_M\xe4rz.pdf",
			'plain' => 'ascii',
			'utf8'  => 'Straße',
		);
		$this->assertSame( $value, Json::decode( Json::encode( $value ) ) );
		$this->assertStringNotContainsString( "\xe4", Json::encode( $value ) );
	}

	public function testEveryWarningReachesTheLog() {
		$storage = new Storage( $this->dir . '/storage' );
		$storage->prepare();
		$logger = new Logger( $storage, 'info' );
		$store  = new JobStore( $storage );
		$stage  = new WarningStage( new Settings(), $storage, $logger );
		$runner = new JobRunner( $store, new WarningResolver( $stage ), $logger, new Settings() );

		$job = Job::create( 'export', array(), array( 'warn' ) );
		$store->save( $job );
		$job = $runner->tick( $job );
		$logger->close( $job->id() );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$this->assertSame( 1200, (int) $job->get( 'warnings_total' ) );
		$this->assertCount( 500, $job->get( 'warnings' ), 'the job file stays bounded' );

		$log = (string) file_get_contents( $logger->path( $job->id() ) );
		$this->assertStringContainsString( 'cache/f0001.html', $log, 'the first warning of the step is in the log' );
		$this->assertStringContainsString( 'cache/f1200.html', $log );
		$this->assertSame( 1200, substr_count( $log, 'not in the archive: cache/f' ) );
	}
}
