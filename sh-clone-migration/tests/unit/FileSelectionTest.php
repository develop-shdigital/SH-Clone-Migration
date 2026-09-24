<?php
/**
 * File selection tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Archive\Format;
use SHCM\Filesystem\ExclusionMatcher;
use SHCM\Filesystem\FileQueue;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Scanner;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\Budget;
use SHCM\Support\Json;

/**
 * What the scanner puts into an archive: symlinks, odd file names, very large
 * directories, exclusions.
 */
class FileSelectionTest extends TestCase {

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
		$this->dir = sys_get_temp_dir() . '/shcm-select-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/site/wp-content/plugins', 0755, true );
		mkdir( $this->dir . '/shared/uploads/2026', 0755, true );
		mkdir( $this->dir . '/queues', 0755, true );
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
	 * Scan the given roots completely and return the queued items.
	 *
	 * @param array            $roots      Roots.
	 * @param ExclusionMatcher $exclusions Exclusions.
	 * @param array            $warnings   Warnings raised (out).
	 * @param int              $requests   Requests used (out).
	 * @return array[]
	 */
	protected function scanAll( array $roots, ?ExclusionMatcher $exclusions = null, &$warnings = array(), &$requests = 0 ) {
		$files = $this->dir . '/queues/files.ndjson';
		$dirs  = $this->dir . '/queues/dirs.ndjson';
		@unlink( $files );
		@unlink( $dirs );

		$make = function () use ( $files, $dirs, $exclusions, $roots ) {
			$scanner = new Scanner( new FileQueue( $files ), new FileQueue( $dirs ), $exclusions ? $exclusions : new ExclusionMatcher() );
			$scanner->roots( $roots );
			return $scanner;
		};

		$scanner = $make();
		$scanner->seed( $roots );
		$warnings = $scanner->warnings();
		$state    = array( 'files_size' => 0, 'dirs_size' => ( new FileQueue( $dirs ) )->size() );
		$requests = 0;
		do {
			$scanner  = $make();
			$state    = $scanner->scan( $state, new Budget( 0.000001, 0 ) );
			$state    = json_decode( \SHCM\Support\Json::encode( $state ), true );
			$state    = \SHCM\Support\Json::decode( json_encode( $state ) );
			$warnings = array_merge( $warnings, $scanner->warnings() );
			++$requests;
		} while ( empty( $state['done'] ) && $requests < 100000 );

		$queue = new FileQueue( $files );
		$items = array();
		while ( null !== ( $item = $queue->next() ) ) {
			$items[ $item['root'] . ':' . $item['rel'] ] = $item;
		}
		return $items;
	}

	public function testSymlinkedUploadsDirectoryIsItsOwnRoot() {
		file_put_contents( $this->dir . '/shared/uploads/2026/photo.jpg', 'jpg' );
		symlink( $this->dir . '/shared/uploads', $this->dir . '/site/wp-content/uploads' );

		$roots = array(
			Paths::ROOT_CONTENT => $this->dir . '/site/wp-content',
			Paths::ROOT_UPLOADS => $this->dir . '/site/wp-content/uploads',
		);
		$items = $this->scanAll( $roots );

		$this->assertArrayHasKey( 'uploads:2026/photo.jpg', $items, 'The media library must be archived' );
		$this->assertArrayNotHasKey( 'wp-content:uploads', $items, 'The link itself is not archived as well' );
	}

	public function testSymlinkedDirectoryOutsideTheSiteIsFollowedOnce() {
		mkdir( $this->dir . '/outside/linked/assets', 0755, true );
		file_put_contents( $this->dir . '/outside/linked/linked.php', '<?php' );
		file_put_contents( $this->dir . '/outside/linked/assets/app.js', 'js' );
		symlink( $this->dir . '/outside/linked', $this->dir . '/site/wp-content/plugins/linked' );
		// A loop inside the followed tree must not be walked again.
		symlink( '..', $this->dir . '/outside/linked/assets/up' );

		$items = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ) );

		$this->assertSame( 'f', $items['wp-content:plugins/linked/linked.php']['type'] );
		$this->assertSame( 'f', $items['wp-content:plugins/linked/assets/app.js']['type'] );
		$this->assertSame( 'l', $items['wp-content:plugins/linked/assets/up']['type'] );
		$this->assertArrayNotHasKey( 'wp-content:plugins/linked/assets/up/linked.php', $items );
	}

	public function testLinkToAParentOfTheSiteIsReportedNotFollowed() {
		symlink( $this->dir, $this->dir . '/site/wp-content/escape' );
		$warnings = array();
		$items    = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ), null, $warnings );

		foreach ( array_keys( $items ) as $key ) {
			$this->assertStringStartsNotWith( 'wp-content:escape/', $key );
		}
		$this->assertNotEmpty( preg_grep( '/contains the site itself/', $warnings ) );
	}

	public function testLinkInsideTheSiteIsKeptAsALink() {
		mkdir( $this->dir . '/site/wp-content/themes/real', 0755, true );
		symlink( 'real', $this->dir . '/site/wp-content/themes/alias' );
		$items = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ) );
		$this->assertSame( 'l', $items['wp-content:themes/alias']['type'] );
		$this->assertSame( 'real', $items['wp-content:themes/alias']['target'] );
	}

	public function testNonUtf8NamesSurviveTheQueues() {
		$latin1 = "Preisliste_M\xe4rz";
		mkdir( $this->dir . '/site/wp-content/' . $latin1, 0755 );
		file_put_contents( $this->dir . '/site/wp-content/' . $latin1 . "/Pr\xe9sentation.pdf", 'pdf' );
		file_put_contents( $this->dir . '/site/wp-content/images\\logo.png', 'png' );

		$items = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ) );

		$this->assertArrayHasKey( 'wp-content:' . $latin1 . "/Pr\xe9sentation.pdf", $items );
		$this->assertArrayHasKey( 'wp-content:images\\logo.png', $items );
	}

	public function testContentIsNotWalkedTwiceWhenCoreIsIncluded() {
		file_put_contents( $this->dir . '/site/index.php', '<?php' );
		file_put_contents( $this->dir . '/site/wp-content/plugins/a.php', '<?php' );
		$items = $this->scanAll(
			array(
				Paths::ROOT_CORE    => $this->dir . '/site',
				Paths::ROOT_CONTENT => $this->dir . '/site/wp-content',
			)
		);
		$this->assertArrayHasKey( 'wp-root:index.php', $items );
		$this->assertArrayHasKey( 'wp-content:plugins/a.php', $items );
		$this->assertArrayNotHasKey( 'wp-root:wp-content/plugins/a.php', $items );
	}

	public function testAVeryLargeDirectoryIsScannedAcrossRequests() {
		mkdir( $this->dir . '/site/wp-content/uploads', 0755 );
		for ( $i = 0; $i < 1234; $i++ ) {
			touch( $this->dir . '/site/wp-content/uploads/f' . $i . '.jpg' );
		}
		$requests = 0;
		$warnings = array();
		$items    = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ), null, $warnings, $requests );
		$uploads  = preg_grep( '/^wp-content:uploads\//', array_keys( $items ) );
		$this->assertCount( 1234, $uploads, 'every file exactly once' );
		$this->assertGreaterThan( 3, $requests );
	}

	public function testAnchoredDirectoryExclusionsDoNotMatchByName() {
		$matcher = new ExclusionMatcher( array( '*.log' ) );
		$matcher->addAnchored( array( 'cache', 'wp-content/uploads/backups' ) );

		$this->assertTrue( $matcher->matches( 'cache' ) );
		$this->assertTrue( $matcher->matches( 'cache/x.html' ) );
		$this->assertFalse( $matcher->matches( 'wp-content/plugins/foo/vendor/symfony/cache' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/uploads/backups/a.zip' ) );
		$this->assertFalse( $matcher->matches( 'wp-content/uploads/2024/backups' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/debug.log' ) );
	}

	public function testSeparateRootsMatchTheirWpContentAlias() {
		mkdir( $this->dir . '/shared/uploads/backups', 0755, true );
		file_put_contents( $this->dir . '/shared/uploads/backups/old.zip', 'zip' );
		file_put_contents( $this->dir . '/shared/uploads/2026/keep.jpg', 'jpg' );
		$matcher = new ExclusionMatcher( array( 'wp-content/uploads/backups' ) );
		$items   = $this->scanAll( array( Paths::ROOT_UPLOADS => $this->dir . '/shared/uploads' ), $matcher );
		$this->assertArrayHasKey( 'uploads:2026/keep.jpg', $items );
		$this->assertArrayNotHasKey( 'uploads:backups/old.zip', $items );
	}

	public function testCollapseResolvesDotSegments() {
		$this->assertSame( '/etc', Paths::collapse( '/var/www/site/wp-content/uploads/../../../../../etc' ) );
		$this->assertSame( '/var/www/site/files', Paths::collapse( '/var/www/site/./a/../files' ) );
		$this->assertSame( '../x', Paths::collapse( 'a/../../x' ) );
		$this->assertSame( '/', Paths::collapse( '/..' ) );
		$this->assertFalse( Paths::isInside( Paths::collapse( '/var/www/site/uploads/../../../etc' ), '/var/www/site' ) );
	}

	public function testQueueTruncatesBackToACommittedSize() {
		$queue = new FileQueue( $this->dir . '/queues/q.ndjson' );
		$queue->push( array( 'a' => 1 ) );
		$queue->closeWriter();
		$committed = $queue->size();
		$queue->push( array( 'b' => 2 ) );
		$queue->closeWriter();
		$queue->truncate( $committed );
		$this->assertSame( array( 'a' => 1 ), $queue->next() );
		$this->assertNull( $queue->next() );
	}

	public function testQueueNeverReadsHalfALine() {
		$path = $this->dir . '/queues/torn.ndjson';
		file_put_contents( $path, "{\"a\":1}\n{\"b\":" );
		$queue = new FileQueue( $path );
		$this->assertSame( array( 'a' => 1 ), $queue->next() );
		$this->assertNull( $queue->next() );
	}

	public function testQueueRefusesACorruptLine() {
		$path = $this->dir . '/queues/bad.ndjson';
		file_put_contents( $path, "not json\n" );
		$this->expectException( \RuntimeException::class );
		( new FileQueue( $path ) )->next();
	}

	public function testTableEntryPathsNeverCollide() {
		$this->assertSame( 'database/tables/wp_posts.sql', Format::tableEntryPath( 'wp_posts' ) );
		$this->assertNotSame( Format::tableEntryPath( 'wp_a.b' ), Format::tableEntryPath( 'wp_a_b' ) );
		$this->assertNotSame( Format::tableEntryPath( 'wp_ä' ), Format::tableEntryPath( 'wp_ö' ) );
		$this->assertSame( 'database/tables/wp_a_b.sql', Format::legacyTableEntryPath( 'wp_a.b' ) );
	}

	public function testLinkToTheFilesystemRootIsRefused() {
		symlink( '/', $this->dir . '/site/wp-content/rootfs' );
		$warnings = array();
		$items    = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ), null, $warnings );

		foreach ( array_keys( $items ) as $key ) {
			$this->assertStringStartsNotWith( 'wp-content:rootfs', $key );
		}
		$this->assertNotEmpty( preg_grep( '/rootfs points at \/, a directory that contains the site itself/', $warnings ) );
	}

	public function testLinkIntoASystemDirectoryIsRefused() {
		if ( ! is_dir( '/proc/self' ) ) {
			$this->markTestSkipped( 'No /proc on this system.' );
		}
		symlink( '/proc/self', $this->dir . '/site/wp-content/proc' );
		$warnings = array();
		$items    = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ), null, $warnings );

		foreach ( array_keys( $items ) as $key ) {
			$this->assertStringStartsNotWith( 'wp-content:proc', $key );
		}
		$this->assertNotEmpty( preg_grep( '/a system directory/', $warnings ) );
	}

	public function testTwoLinksToTheSameOutsideDirectoryAreBothArchived() {
		mkdir( $this->dir . '/outside/library/src', 0755, true );
		file_put_contents( $this->dir . '/outside/library/src/lib.php', '<?php' );
		symlink( $this->dir . '/outside/library', $this->dir . '/site/wp-content/plugins/one' );
		symlink( $this->dir . '/outside/library', $this->dir . '/site/wp-content/plugins/two' );

		$items = $this->scanAll( array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' ) );

		$this->assertSame( 'f', $items['wp-content:plugins/one/src/lib.php']['type'] );
		$this->assertSame( 'f', $items['wp-content:plugins/two/src/lib.php']['type'], 'the second link is not mistaken for a loop' );
	}

	public function testALinkThatResolvesToAnotherRootIsKeptAsALink() {
		file_put_contents( $this->dir . '/shared/uploads/2026/photo.jpg', 'jpg' );
		symlink( $this->dir . '/shared/uploads', $this->dir . '/site/wp-content/uploads' );
		// A second name for the same media library (old multisite layout).
		symlink( $this->dir . '/shared/uploads', $this->dir . '/site/wp-content/blogs.dir' );

		$items = $this->scanAll(
			array(
				Paths::ROOT_CONTENT => $this->dir . '/site/wp-content',
				Paths::ROOT_UPLOADS => $this->dir . '/site/wp-content/uploads',
			)
		);

		$this->assertArrayHasKey( 'uploads:2026/photo.jpg', $items );
		$this->assertSame( 'l', $items['wp-content:blogs.dir']['type'] );
		$this->assertArrayNotHasKey( 'wp-content:blogs.dir/2026/photo.jpg', $items, 'the library is not archived twice' );
	}

	public function testFilesDeletedBetweenRequestsAreNeitherSkippedNorDuplicated() {
		mkdir( $this->dir . '/site/wp-content/cache', 0755 );
		for ( $i = 0; $i < 1500; $i++ ) {
			touch( sprintf( '%s/site/wp-content/cache/f%04d.html', $this->dir, $i ) );
		}
		$roots = array( Paths::ROOT_CONTENT => $this->dir . '/site/wp-content' );
		$files = $this->dir . '/queues/files.ndjson';
		$dirs  = $this->dir . '/queues/dirs.ndjson';
		$make  = function () use ( $files, $dirs, $roots ) {
			$scanner = new Scanner( new FileQueue( $files ), new FileQueue( $dirs ), new ExclusionMatcher() );
			$scanner->roots( $roots );
			return $scanner;
		};

		$make()->seed( $roots );
		$state    = array( 'files_size' => 0, 'dirs_size' => ( new FileQueue( $dirs ) )->size() );
		$requests = 0;
		$deleted  = array();
		$spooled  = false;
		do {
			$state = $make()->scan( $state, new Budget( 0.000001, 0 ) );
			$state = Json::decode( Json::encode( $state ) );
			++$requests;
			if ( ! $spooled && is_file( $files . '.partial' ) ) {
				// A cache purge between two requests, while the listing of
				// the directory is part way through its spool.
				$spooled = true;
				for ( $i = 0; $i < 1500; $i += 7 ) {
					$name = sprintf( 'f%04d.html', $i );
					if ( @unlink( $this->dir . '/site/wp-content/cache/' . $name ) ) {
						$deleted[ $name ] = true;
					}
				}
			}
		} while ( empty( $state['done'] ) && $requests < 100000 );

		$queue = new FileQueue( $files );
		$seen  = array();
		while ( null !== ( $item = $queue->next() ) ) {
			if ( 0 === strpos( $item['rel'], 'cache/' ) ) {
				$seen[] = substr( $item['rel'], 6 );
			}
		}

		$this->assertTrue( $spooled, 'the purge happened while the listing was spooled' );
		$this->assertNotEmpty( $deleted );
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'no file queued twice' );
		$this->assertLessThan( 1500, count( $seen ), 'files deleted before they were reached are not queued' );
		for ( $i = 0; $i < 1500; $i++ ) {
			$name = sprintf( 'f%04d.html', $i );
			if ( ! isset( $deleted[ $name ] ) ) {
				$this->assertContains( $name, $seen, $name . ' still exists and must be queued' );
			}
		}
	}
}
