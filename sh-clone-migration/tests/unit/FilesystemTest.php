<?php
/**
 * Filesystem safety and queue tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Filesystem\ExclusionMatcher;
use SHCM\Filesystem\FileQueue;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\SafePath;

/**
 * Path handling is the part of a restore that can escape the installation, so
 * it gets the most hostile inputs.
 */
class FilesystemTest extends TestCase {

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Create a scratch directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/shcm-fs-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir, 0755, true );
	}

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\SHCM\Filesystem\Storage::rmdirRecursive( $this->dir );
		parent::tearDown();
	}

	public function testTraversalIsRejected() {
		$hostile = array(
			'../evil.php',
			'a/../../evil.php',
			'/etc/passwd',
			'C:\\Windows\\evil.php',
			'phar://payload/evil.php',
			'http://example.com/evil.php',
			"nul\0byte.php",
			'..',
			'',
		);

		foreach ( $hostile as $path ) {
			$this->assertNull( SafePath::sanitizeRelative( $path ), 'Should reject: ' . $path );
		}
	}

	public function testHarmlessPathsAreAccepted() {
		$this->assertSame( 'wp-content/uploads/a.png', SafePath::sanitizeRelative( 'wp-content/uploads/a.png' ) );
		$this->assertSame( 'a/b/c.txt', SafePath::sanitizeRelative( './a/b/./c.txt' ) );
		$this->assertSame( 'ünïcode ✓.png', SafePath::sanitizeRelative( 'ünïcode ✓.png' ) );
	}

	public function testBackslashIsASeparatorOnlyWhereTheSystemSaysSo() {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->assertSame( 'a/b.txt', SafePath::sanitizeRelative( 'a\\b.txt' ) );
			$this->assertNull( SafePath::sanitizeRelative( '..\\..\\evil.php' ) );
			return;
		}
		// On Linux "images\logo.png" is one file name (Windows ZIPs extracted
		// there produce these); it is kept, not turned into a directory.
		$this->assertSame( 'images\\logo.png', SafePath::sanitizeRelative( 'images\\logo.png' ) );
		// "..\..\evil.php" is then a single, harmless file name.
		$this->assertSame( $this->dir . '/..\\..\\evil.php', SafePath::resolve( $this->dir, '..\\..\\evil.php' ) );
	}

	public function testResolveStaysInsideTheBase() {
		$this->assertSame( $this->dir . '/a/b.txt', SafePath::resolve( $this->dir, 'a/b.txt' ) );
		$this->assertNull( SafePath::resolve( $this->dir, '../outside.txt' ) );
	}

	public function testSymlinkEscapeIsDetected() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() is unavailable.' );
		}

		$outside = sys_get_temp_dir() . '/shcm-outside-' . bin2hex( random_bytes( 4 ) );
		mkdir( $outside, 0755, true );
		symlink( $outside, $this->dir . '/link' );

		$target = $this->dir . '/link/payload.php';
		$this->assertTrue( SafePath::escapesViaSymlink( $this->dir, $target ) );
		$this->assertFalse( SafePath::escapesViaSymlink( $this->dir, $this->dir . '/normal.php' ) );

		unlink( $this->dir . '/link' );
		rmdir( $outside );
	}

	public function testControlCharactersAreRejected() {
		$this->assertFalse( SafePath::isAcceptableName( "bad\nname.php" ) );
		$this->assertTrue( SafePath::isAcceptableName( 'good name (1).php' ) );
	}

	public function testExclusionMatching() {
		$matcher = new ExclusionMatcher(
			array(
				'wp-content/cache',
				'*.log',
				'*/node_modules',
				'wp-content/uploads/backwpup*',
			)
		);

		$this->assertTrue( $matcher->matches( 'wp-content/cache' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/cache/deep/file.php' ) );
		$this->assertTrue( $matcher->matches( 'debug.log' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/uploads/debug.log' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/themes/x/node_modules' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/themes/x/node_modules/pkg/index.js' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/uploads/backwpup-123/file.zip' ) );

		$this->assertFalse( $matcher->matches( 'wp-content/cached/file.php' ) );
		$this->assertFalse( $matcher->matches( 'wp-content/uploads/logo.png' ) );
		$this->assertFalse( $matcher->matches( 'wp-content/plugins/logger/index.php' ) );
	}

	public function testExclusionMatcherIsEmptyByDefault() {
		$matcher = new ExclusionMatcher();
		$this->assertTrue( $matcher->isEmpty() );
		$this->assertFalse( $matcher->matches( 'anything' ) );
	}

	public function testFileQueueRoundTripAndResume() {
		$queue = new FileQueue( $this->dir . '/queue.ndjson' );
		for ( $i = 0; $i < 500; $i++ ) {
			$queue->push( array( 'rel' => 'file-' . $i . '.txt', 'size' => $i ) );
		}
		$queue->closeWriter();

		$queue->openReader( 0 );
		$first = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$first[] = $queue->next();
		}
		$offset = $queue->tell();
		$queue->closeReader();

		$this->assertSame( 'file-0.txt', $first[0]['rel'] );
		$this->assertSame( 'file-9.txt', $first[9]['rel'] );

		$resumed = new FileQueue( $this->dir . '/queue.ndjson' );
		$resumed->openReader( $offset );
		$next = $resumed->next();
		$this->assertSame( 'file-10.txt', $next['rel'] );

		$count = 1;
		while ( null !== $resumed->next() ) {
			++$count;
		}
		$this->assertSame( 490, $count );
	}

	public function testPathHelpers() {
		$this->assertSame( '/a/b', Paths::normalize( '/a//b/' ) );
		$this->assertSame( '/a/b/', Paths::trailingslash( '/a/b' ) );
		$this->assertTrue( Paths::isInside( '/a/b/c', '/a/b' ) );
		$this->assertFalse( Paths::isInside( '/a/bc', '/a/b' ) );
		$this->assertSame( 'c/d', Paths::relativeTo( '/a/b/c/d', '/a/b' ) );
		$this->assertNull( Paths::relativeTo( '/x/y', '/a/b' ) );
	}

	public function testGroupClassification() {
		$this->assertSame( 'plugins', Paths::group( Paths::ROOT_CONTENT, 'plugins/akismet/akismet.php' ) );
		$this->assertSame( 'themes', Paths::group( Paths::ROOT_CONTENT, 'themes/twentytwentyfive/style.css' ) );
		$this->assertSame( 'uploads', Paths::group( Paths::ROOT_CONTENT, 'uploads/2024/01/a.png' ) );
		$this->assertSame( 'mu-plugins', Paths::group( Paths::ROOT_CONTENT, 'mu-plugins/loader.php' ) );
		$this->assertSame( 'other', Paths::group( Paths::ROOT_CONTENT, 'custom-dir/file.txt' ) );
		$this->assertSame( 'core', Paths::group( Paths::ROOT_CORE, 'wp-admin/index.php' ) );
	}
}
