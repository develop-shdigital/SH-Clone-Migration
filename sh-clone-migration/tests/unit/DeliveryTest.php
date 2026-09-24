<?php
/**
 * Download and integrity tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Archive\Catalog;
use SHCM\Archive\FileDigest;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\Budget;
use SHCM\Support\HttpRange;
use SHCM\Support\Json;

/**
 * HTTP range handling, the SHA-256 a downloaded copy is checked against, and
 * the JSON layer that carries file names which are not UTF-8.
 */
class DeliveryTest extends TestCase {

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
		$this->dir = sys_get_temp_dir() . '/shcm-delivery-' . bin2hex( random_bytes( 6 ) );
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

	public function testNoRangeServesTheWholeFile() {
		$this->assertSame( array( 'status' => 200, 'start' => 0, 'end' => 999, 'length' => 1000 ), HttpRange::resolve( 1000, '' ) );
	}

	public function testClosedRange() {
		$this->assertSame( array( 'status' => 206, 'start' => 100, 'end' => 199, 'length' => 100 ), HttpRange::resolve( 1000, 'bytes=100-199' ) );
	}

	public function testSuffixRangeIsTheTail() {
		$this->assertSame( array( 'status' => 206, 'start' => 500, 'end' => 999, 'length' => 500 ), HttpRange::resolve( 1000, 'bytes=-500' ) );
		$this->assertSame( array( 'status' => 206, 'start' => 0, 'end' => 999, 'length' => 1000 ), HttpRange::resolve( 1000, 'bytes=-5000' ) );
		$this->assertSame( 416, HttpRange::resolve( 1000, 'bytes=-0' )['status'] );
	}

	public function testOpenRangeRunsToTheEnd() {
		$this->assertSame( array( 'status' => 206, 'start' => 990, 'end' => 999, 'length' => 10 ), HttpRange::resolve( 1000, 'bytes=990-' ) );
	}

	public function testEndPastTheFileIsClamped() {
		$this->assertSame( array( 'status' => 206, 'start' => 0, 'end' => 999, 'length' => 1000 ), HttpRange::resolve( 1000, 'bytes=0-99999' ) );
		$this->assertSame( array( 'status' => 206, 'start' => 990, 'end' => 999, 'length' => 10 ), HttpRange::resolve( 1000, 'bytes=990-2000' ) );
	}

	public function testStartPastTheFileIsUnsatisfiable() {
		$this->assertSame( 416, HttpRange::resolve( 1000, 'bytes=1000-' )['status'] );
		$this->assertSame( 416, HttpRange::resolve( 1000, 'bytes=5000-6000' )['status'] );
	}

	public function testInvalidOrUnsupportedRangesAreIgnored() {
		foreach ( array( 'bytes=5-2', 'bytes=-', 'items=0-5', 'bytes=0-1,5-9', 'garbage', 'bytes=abc-def', 'bytes=0-99999999999999999999' ) as $header ) {
			$this->assertSame( 200, HttpRange::resolve( 1000, $header )['status'], $header );
			$this->assertSame( 1000, HttpRange::resolve( 1000, $header )['length'], $header );
		}
	}

	public function testIfRangeOnlyHonoursTheCurrentVersion() {
		$etag = '"abc123"';
		$date = 'Fri, 11 Sep 2026 08:49:16 GMT';
		$this->assertSame( 206, HttpRange::resolve( 1000, 'bytes=10-19', $etag, $etag, $date )['status'] );
		$this->assertSame( 206, HttpRange::resolve( 1000, 'bytes=10-19', $date, $etag, $date )['status'] );
		$this->assertSame( 200, HttpRange::resolve( 1000, 'bytes=10-19', '"stale"', $etag, $date )['status'] );
		$this->assertSame( 200, HttpRange::resolve( 1000, 'bytes=10-19', 'W/"abc123"', $etag, $date )['status'] );
		$this->assertSame( 200, HttpRange::resolve( 1000, 'bytes=10-19', 'Thu, 10 Sep 2026 08:49:16 GMT', $etag, $date )['status'] );
	}

	public function testEmptyFile() {
		$this->assertSame( 200, HttpRange::resolve( 0, 'bytes=0-10' )['status'] );
		$this->assertSame( 0, HttpRange::resolve( 0, '' )['length'] );
	}

	public function testJsonCarriesNonUtf8BytesLosslessly() {
		$value = array(
			'rel'           => "uploads/Preisliste_M\xe4rz.pdf",
			'ok'            => 'ünïcode ✓',
			"k\xe9y"        => 1,
			'nested'        => array( "\x80\x81" ),
			'looks_like_it' => 'b64:not tagged',
		);
		$json = Json::encode( $value );
		$this->assertTrue( Json::isUtf8( $json ) );
		$this->assertSame( $value, Json::decode( $json ) );
	}

	public function testJsonNeverWritesPartialDocuments() {
		$this->expectException( \RuntimeException::class );
		Json::encode( array( 'x' => NAN ) );
	}

	public function testPrintableReplacesBadBytesOnly() {
		$this->assertSame( 'ünïcode', Json::printable( 'ünïcode' ) );
		$printable = Json::printable( "M\xe4rz" );
		$this->assertTrue( Json::isUtf8( $printable ) );
		$this->assertStringStartsWith( 'M', $printable );
		$this->assertStringEndsWith( 'rz', $printable );
	}

	public function testFileDigestResumesAcrossRequests() {
		if ( ! FileDigest::resumable() ) {
			$this->markTestSkipped( 'This PHP build cannot serialise hash contexts.' );
		}
		$path = $this->dir . '/big.bin';
		file_put_contents( $path, random_bytes( FileDigest::SLICE * 2 + 12345 ) );

		$state    = array();
		$requests = 0;
		do {
			// An expired budget: one slice per "request".
			$state = FileDigest::advance( $path, $state, new Budget( 0.000001, 0 ) );
			$state = json_decode( json_encode( $state ), true ); // Survives the job file.
			++$requests;
		} while ( empty( $state['digest'] ) && $requests < 10 );

		$this->assertSame( 3, $requests );
		$this->assertSame( hash_file( 'sha256', $path ), $state['digest'] );
	}

	public function testFileDigestNoticesAFileThatChanged() {
		if ( ! FileDigest::resumable() ) {
			$this->markTestSkipped( 'This PHP build cannot serialise hash contexts.' );
		}
		$path = $this->dir . '/grows.bin';
		file_put_contents( $path, random_bytes( FileDigest::SLICE + 10 ) );
		$state = FileDigest::advance( $path, array(), new Budget( 0.000001, 0 ) );
		file_put_contents( $path, 'more', FILE_APPEND );
		$this->expectException( \RuntimeException::class );
		FileDigest::advance( $path, $state, new Budget( 0.000001, 0 ) );
	}

	public function testChecksumFileRoundTripAndStaleness() {
		$catalog = new Catalog( new Storage( $this->dir ) );
		$path    = $this->dir . '/archives/site-0123456789abcdef.wpress';
		file_put_contents( $path, 'archive' );
		$hex = hash_file( 'sha256', $path );

		$this->assertTrue( Catalog::writeChecksum( $path, $hex ) );
		$this->assertSame( $hex . '  site-0123456789abcdef.wpress' . "\n", file_get_contents( $path . '.sha256' ) );
		$this->assertSame( $hex, $catalog->sha256( $path ) );

		// A checksum file older than the archive belongs to an earlier file.
		touch( $path . '.sha256', time() - 100 );
		touch( $path, time() );
		clearstatcache();
		$this->assertSame( '', $catalog->sha256( $path ) );

		$this->assertFalse( Catalog::writeChecksum( $path, 'not-a-digest' ) );
	}

	public function testDeletingAnArchiveRemovesItsChecksum() {
		$catalog = new Catalog( new Storage( $this->dir ) );
		$path    = $this->dir . '/archives/site-0123456789abcdef.wpress';
		file_put_contents( $path, "SHCMWPRS" );
		Catalog::writeChecksum( $path, hash( 'sha256', 'x' ) );
		$this->assertTrue( $catalog->delete( basename( $path ) ) );
		$this->assertFileDoesNotExist( $path . '.sha256' );
	}
}
