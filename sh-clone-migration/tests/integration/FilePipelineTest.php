<?php
/**
 * File pipeline integration test.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SHCM\Archive\Format;
use SHCM\Archive\Reader;
use SHCM\Archive\Verifier;
use SHCM\Archive\Writer;
use SHCM\Filesystem\ExclusionMatcher;
use SHCM\Filesystem\FileQueue;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\SafePath;
use SHCM\Filesystem\Scanner;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\Budget;

/**
 * Scans a real directory tree, archives it and restores it somewhere else,
 * then proves the two trees are identical.
 *
 * This is the export/import file path end to end, without WordPress.
 */
class FilePipelineTest extends TestCase {

	/**
	 * Scratch root.
	 *
	 * @var string
	 */
	protected $root;

	/**
	 * Source tree.
	 *
	 * @var string
	 */
	protected $source;

	/**
	 * Destination tree.
	 *
	 * @var string
	 */
	protected $destination;

	/**
	 * Archive path.
	 *
	 * @var string
	 */
	protected $archive;

	/**
	 * Build the scratch trees.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root        = sys_get_temp_dir() . '/shcm-pipeline-' . bin2hex( random_bytes( 6 ) );
		$this->source      = $this->root . '/source';
		$this->destination = $this->root . '/destination';
		$this->archive     = $this->root . '/site.wpress';

		mkdir( $this->source . '/wp-content/uploads/2024/01', 0755, true );
		mkdir( $this->source . '/wp-content/plugins/example', 0755, true );
		mkdir( $this->source . '/wp-content/themes/child', 0755, true );
		mkdir( $this->source . '/wp-content/uploads/empty-dir', 0755, true );
		mkdir( $this->source . '/wp-content/cache/should-be-skipped', 0755, true );
		mkdir( $this->destination, 0755, true );

		file_put_contents( $this->source . '/wp-content/uploads/2024/01/photo.jpg', random_bytes( 200000 ) );
		file_put_contents( $this->source . '/wp-content/uploads/2024/01/ünïcode ✓ (1).png', random_bytes( 1024 ) );
		file_put_contents( $this->source . '/wp-content/uploads/large.bin', random_bytes( 3 * 1024 * 1024 ) );
		file_put_contents( $this->source . '/wp-content/uploads/empty.txt', '' );
		file_put_contents( $this->source . '/wp-content/plugins/example/example.php', "<?php\n// plugin\n" );
		file_put_contents( $this->source . '/wp-content/themes/child/style.css', str_repeat( ".a{color:red}\n", 5000 ) );
		file_put_contents( $this->source . '/wp-content/cache/should-be-skipped/x.tmp', 'cache' );
		file_put_contents( $this->source . '/wp-content/debug.log', 'noisy' );
	}

	/**
	 * Remove the scratch trees.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Storage::rmdirRecursive( $this->root );
		parent::tearDown();
	}

	/**
	 * Snapshot a directory tree as path => md5 (directories as null).
	 *
	 * @param string $base Base directory.
	 * @return array
	 */
	protected function snapshot( $base ) {
		$map      = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$relative = Paths::relativeTo( Paths::normalize( $item->getPathname() ), Paths::normalize( $base ) );
			$map[ $relative ] = $item->isDir() ? null : md5_file( $item->getPathname() );
		}
		ksort( $map );
		return $map;
	}

	/**
	 * Archive the source tree the way the export stage does.
	 *
	 * @param array $exclusions Exclusion patterns.
	 * @return array Totals from the scan.
	 */
	protected function archiveSource( array $exclusions = array( 'wp-content/cache', '*.log' ) ) {
		$files = new FileQueue( $this->root . '/files.ndjson' );
		$dirs  = new FileQueue( $this->root . '/dirs.ndjson' );

		$scanner = new Scanner( $files, $dirs, new ExclusionMatcher( $exclusions ) );
		$scanner->seed( array( Paths::ROOT_CONTENT => $this->source . '/wp-content' ) );

		$state = $scanner->scan( array(), new Budget( 30, 0 ) );
		$this->assertTrue( $state['done'] );

		$writer = Writer::create( $this->archive, array( 'block_size' => 65536 ) );
		$writer->addString( Format::ENTRY_MANIFEST, '{"format":1}' );

		$files->closeWriter();
		$files->openReader( 0 );
		while ( true ) {
			$item = $files->next();
			if ( null === $item ) {
				break;
			}
			$path = Format::ENTRY_FILES . $item['root'] . '/' . $item['rel'];
			$abs  = $this->source . '/wp-content/' . $item['rel'];

			if ( 'd' === $item['type'] ) {
				$writer->addDirectory( $path, $item );
			} elseif ( 'l' === $item['type'] ) {
				$writer->addSymlink( $path, $item['target'], $item );
			} else {
				$writer->addFile( $path, $abs, $item );
			}
		}
		$files->closeReader();

		$writer->close();
		$writer->release();

		return $state['totals'];
	}

	/**
	 * Restore the archive the way the import stage does.
	 *
	 * @return array Paths that were refused.
	 */
	protected function restore() {
		$reader   = new Reader( $this->archive );
		$refused  = array();
		$base     = $this->destination . '/wp-content';

		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0755, true );
		}

		$reader->rewindEntries();
		while ( true ) {
			$entry = $reader->nextEntry();
			if ( null === $entry ) {
				break;
			}
			if ( 0 !== strpos( $entry['path'], Format::ENTRY_FILES ) ) {
				$reader->skipEntry( $entry );
				continue;
			}

			$relative = substr( $entry['path'], strlen( Format::ENTRY_FILES . Paths::ROOT_CONTENT . '/' ) );
			$target   = SafePath::resolve( $base, $relative );

			if ( null === $target ) {
				$refused[] = $entry['path'];
				$reader->skipEntry( $entry );
				continue;
			}

			if ( Format::TYPE_DIR === $entry['type'] ) {
				if ( ! is_dir( $target ) ) {
					mkdir( $target, 0755, true );
				}
				$reader->skipEntry( $entry );
				continue;
			}

			if ( Format::TYPE_LINK === $entry['type'] ) {
				$reader->skipEntry( $entry );
				continue;
			}

			$directory = dirname( $target );
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0755, true );
			}

			$handle = fopen( $target, 'wb' );
			$state  = $reader->extractTo( $entry, $handle, array(), 0 );
			fclose( $handle );

			$this->assertSame( $entry['hash'], $state['hash'], 'Checksum mismatch for ' . $entry['path'] );
		}

		return $refused;
	}

	public function testTreeSurvivesARoundTrip() {
		$totals = $this->archiveSource();

		$this->assertGreaterThan( 5, $totals['files'] );
		$this->assertGreaterThan( 3 * 1024 * 1024, $totals['bytes'] );

		$reader   = new Reader( $this->archive );
		$verifier = new Verifier( $reader );
		$result   = $verifier->verifyAll();
		$this->assertTrue( $result['ok'], implode( ' ', $result['errors'] ) );

		$refused = $this->restore();
		$this->assertSame( array(), $refused );

		$expected = $this->snapshot( $this->source . '/wp-content' );
		$actual   = $this->snapshot( $this->destination . '/wp-content' );

		// Excluded paths are absent from the restored tree.
		unset( $expected['cache'], $expected['cache/should-be-skipped'], $expected['cache/should-be-skipped/x.tmp'], $expected['debug.log'] );

		$this->assertSame( $expected, $actual );
	}

	public function testExcludedPathsNeverEnterTheArchive() {
		$this->archiveSource();

		$reader = new Reader( $this->archive );
		$paths  = array();
		$reader->rewindEntries();
		while ( true ) {
			$entry = $reader->nextEntry();
			if ( null === $entry ) {
				break;
			}
			$paths[] = $entry['path'];
			$reader->skipEntry( $entry );
		}

		$joined = implode( "\n", $paths );
		$this->assertStringNotContainsString( 'cache/', $joined );
		$this->assertStringNotContainsString( 'debug.log', $joined );
		$this->assertStringContainsString( 'uploads/large.bin', $joined );
	}

	public function testEmptyDirectoriesArePreserved() {
		$this->archiveSource();
		$this->restore();

		$this->assertDirectoryExists( $this->destination . '/wp-content/uploads/empty-dir' );
	}

	public function testHostileArchiveCannotEscapeTheDestination() {
		$writer = Writer::create( $this->archive, array( 'block_size' => 65536 ) );
		$writer->addString( Format::ENTRY_MANIFEST, '{"format":1}' );
		$writer->addString( Format::ENTRY_FILES . Paths::ROOT_CONTENT . '/../../../evil.php', '<?php echo "pwned";' );
		$writer->addString( Format::ENTRY_FILES . Paths::ROOT_CONTENT . '/normal.txt', 'fine' );
		$writer->close();
		$writer->release();

		$refused = $this->restore();

		$this->assertCount( 1, $refused );
		$this->assertFileDoesNotExist( $this->root . '/evil.php' );
		$this->assertFileDoesNotExist( dirname( $this->root ) . '/evil.php' );
		$this->assertFileExists( $this->destination . '/wp-content/normal.txt' );
	}

	public function testSymlinksAreRecordedRatherThanFollowed() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() is unavailable.' );
		}

		symlink( $this->source . '/wp-content/uploads/large.bin', $this->source . '/wp-content/uploads/link.bin' );
		$this->archiveSource();

		$reader = new Reader( $this->archive );
		$entry  = $reader->findEntry( Format::ENTRY_FILES . Paths::ROOT_CONTENT . '/uploads/link.bin' );

		$this->assertNotNull( $entry );
		$this->assertSame( Format::TYPE_LINK, $entry['type'] );
		$this->assertSame( 0, $entry['size'], 'A symlink must not drag its target into the archive.' );
	}

	public function testScanIsResumable() {
		$files = new FileQueue( $this->root . '/files2.ndjson' );
		$dirs  = new FileQueue( $this->root . '/dirs2.ndjson' );

		$scanner = new Scanner( $files, $dirs, new ExclusionMatcher() );
		$scanner->seed( array( Paths::ROOT_CONTENT => $this->source . '/wp-content' ) );

		// An exhausted budget still processes one directory per call.
		$state = array();
		$loops = 0;
		do {
			$state = $scanner->scan( $state, new Budget( -1, 0 ) );
			++$loops;
			$this->assertLessThan( 100, $loops, 'The scan made no progress.' );
		} while ( empty( $state['done'] ) );

		$this->assertGreaterThan( 3, $loops, 'The scan should have needed several passes.' );
		$this->assertGreaterThan( 5, $state['totals']['files'] );
	}
}
