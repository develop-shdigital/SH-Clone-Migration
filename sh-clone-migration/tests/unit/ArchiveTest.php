<?php
/**
 * Archive format tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Archive\Format;
use SHCM\Archive\Reader;
use SHCM\Archive\Verifier;
use SHCM\Archive\Writer;

/**
 * Round trip, resume, encryption and corruption behaviour of the container.
 */
class ArchiveTest extends TestCase {

	/**
	 * Files created by a test.
	 *
	 * @var string[]
	 */
	protected $temporary = array();

	/**
	 * Remove temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->temporary as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}
		$this->temporary = array();
		parent::tearDown();
	}

	/**
	 * A scratch file path.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	protected function path( $suffix = '.wpress' ) {
		$path              = sys_get_temp_dir() . '/shcm-test-' . bin2hex( random_bytes( 6 ) ) . $suffix;
		$this->temporary[] = $path;
		return $path;
	}

	/**
	 * Read a whole entry.
	 *
	 * @param Reader $reader Reader.
	 * @param string $path   Entry path.
	 * @return string
	 */
	protected function readEntry( Reader $reader, $path ) {
		$entry = $reader->findEntry( $path );
		$this->assertNotNull( $entry, 'Entry ' . $path . ' is missing.' );
		$out = '';
		foreach ( $reader->blocks( $entry ) as $block ) {
			$out .= $block;
		}
		return $out;
	}

	public function testRoundTripPreservesContent() {
		$path    = $this->path();
		$payload = str_repeat( "compressible content ünïcode ✓\n", 2000 );
		$binary  = random_bytes( 300000 );

		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->addString( 'manifest.json', '{"a":1}' );
		$writer->addString( 'files/text.txt', $payload );
		$writer->addString( 'files/binary.bin', $binary );
		$writer->addString( 'files/empty.txt', '' );
		$writer->addDirectory( 'files/dir' );
		$writer->close();
		$writer->release();

		$reader = new Reader( $path );
		$this->assertSame( '{"a":1}', $this->readEntry( $reader, 'manifest.json' ) );
		$this->assertSame( $payload, $this->readEntry( $reader, 'files/text.txt' ) );
		$this->assertSame( $binary, $this->readEntry( $reader, 'files/binary.bin' ) );
		$this->assertSame( '', $this->readEntry( $reader, 'files/empty.txt' ) );

		$footer = $reader->footer();
		$this->assertIsArray( $footer );
		$this->assertSame( 5, $footer['entries'] );
	}

	public function testCompressionActuallyShrinksCompressibleData() {
		$payload = str_repeat( 'aaaaaaaaaabbbbbbbbbb', 50000 );

		$plain = $this->path();
		$writer = Writer::create( $plain, array( 'compress' => false ) );
		$writer->addString( 'files/x.txt', $payload );
		$writer->close();
		$writer->release();

		$compressed = $this->path();
		$writer = Writer::create( $compressed, array( 'compress' => true ) );
		$writer->addString( 'files/x.txt', $payload );
		$writer->close();
		$writer->release();

		$this->assertLessThan( filesize( $plain ) / 10, filesize( $compressed ) );

		$reader = new Reader( $compressed );
		$this->assertSame( $payload, $this->readEntry( $reader, 'files/x.txt' ) );
	}

	public function testWriterResumesMidEntry() {
		$path    = $this->path();
		$payload = random_bytes( 500000 );

		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->addString( 'manifest.json', '{"format":1}' );
		$writer->beginEntry( 'files/big.bin' );
		$writer->append( substr( $payload, 0, 120000 ) );
		$state = $writer->pause();
		$writer->release();

		$writer = Writer::resume( $state );
		$writer->append( substr( $payload, 120000, 200000 ) );
		$state = $writer->pause();
		$writer->release();

		$writer = Writer::resume( $state );
		$writer->append( substr( $payload, 320000 ) );
		$writer->finishEntry();
		$writer->close();
		$writer->release();

		$reader = new Reader( $path );
		$this->assertSame( $payload, $this->readEntry( $reader, 'files/big.bin' ) );

		$verifier = new Verifier( $reader );
		$result   = $verifier->verifyAll();
		$this->assertTrue( $result['ok'], implode( ' ', $result['errors'] ) );
	}

	public function testResumeTruncatesATornTail() {
		$path = $this->path();

		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->beginEntry( 'files/a.txt' );
		$writer->append( str_repeat( 'a', 100000 ) );
		$state = $writer->pause();
		$writer->release();

		// Simulate a request that died half way through writing a block.
		file_put_contents( $path, random_bytes( 5000 ), FILE_APPEND );
		$this->assertGreaterThan( $state['size'], filesize( $path ) );

		$writer = Writer::resume( $state );
		$writer->append( str_repeat( 'b', 1000 ) );
		$writer->finishEntry();
		$writer->close();
		$writer->release();

		$reader = new Reader( $path );
		$this->assertSame( str_repeat( 'a', 100000 ) . str_repeat( 'b', 1000 ), $this->readEntry( $reader, 'files/a.txt' ) );
	}

	public function testEncryptionRequiresTheRightPassword() {
		if ( ! \SHCM\Crypto\Cipher::isAvailable() ) {
			$this->markTestSkipped( 'No cipher available on this server.' );
		}

		$path    = $this->path();
		$payload = str_repeat( 'secret payload ', 5000 );

		$writer = Writer::create( $path, array( 'password' => 'hunter2 hunter2' ) );
		$writer->addString( 'manifest.json', '{"ok":true}' );
		$writer->addString( 'files/secret.txt', $payload );
		$writer->close();
		$writer->release();

		// The raw bytes must not contain the plaintext.
		$this->assertStringNotContainsString( 'secret payload', (string) file_get_contents( $path ) );

		$this->expectException( \RuntimeException::class );
		new Reader( $path );
	}

	public function testEncryptedArchiveReadsBackWithThePassword() {
		if ( ! \SHCM\Crypto\Cipher::isAvailable() ) {
			$this->markTestSkipped( 'No cipher available on this server.' );
		}

		$path    = $this->path();
		$payload = random_bytes( 200000 );

		$writer = Writer::create( $path, array( 'password' => 'hunter2 hunter2', 'block_size' => 65536 ) );
		$writer->addString( 'files/secret.bin', $payload );
		$writer->close();
		$writer->release();

		$reader = new Reader( $path, 'hunter2 hunter2' );
		$this->assertSame( $payload, $this->readEntry( $reader, 'files/secret.bin' ) );

		$wrong = null;
		try {
			new Reader( $path, 'not the password' );
		} catch ( \RuntimeException $e ) {
			$wrong = $e->getMessage();
		}
		$this->assertStringContainsString( 'password is incorrect', (string) $wrong );
	}

	public function testCorruptionIsDetected() {
		$path = $this->path();

		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->addString( 'manifest.json', '{"a":1}' );
		$writer->addString( 'files/data.bin', random_bytes( 300000 ) );
		$writer->close();
		$writer->release();

		$handle = fopen( $path, 'r+b' );
		fseek( $handle, (int) ( filesize( $path ) / 2 ) );
		fwrite( $handle, random_bytes( 256 ) );
		fclose( $handle );

		$reader   = new Reader( $path );
		$verifier = new Verifier( $reader );
		$result   = $verifier->verifyAll();

		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function testTruncatedArchiveHasNoFooter() {
		$path = $this->path();

		$writer = Writer::create( $path );
		$writer->addString( 'manifest.json', '{"a":1}' );
		$writer->addString( 'files/data.bin', random_bytes( 50000 ) );
		$writer->close();
		$writer->release();

		$full = (string) file_get_contents( $path );
		file_put_contents( $path, substr( $full, 0, strlen( $full ) - 64 ) );

		$reader = new Reader( $path );
		$this->assertNull( $reader->footer() );
		$this->assertFalse( $reader->isComplete() );

		$verifier = new Verifier( $reader );
		$result   = $verifier->structure();
		$this->assertFalse( $result['ok'] );
	}

	public function testEntryHashIsStableAcrossBlockSizes() {
		$payload = str_repeat( 'x', 100000 );
		$this->assertSame(
			Format::hashString( $payload, 1048576 ),
			Format::hashString( $payload, 1048576 )
		);
		$this->assertNotSame(
			Format::hashString( $payload, 1024 ),
			Format::hashString( $payload . 'y', 1024 )
		);
	}

	public function testNonArchiveFileIsRejected() {
		$path = $this->path( '.txt' );
		file_put_contents( $path, 'this is not an archive' );

		$this->expectException( \RuntimeException::class );
		new Reader( $path );
	}

	public function testFooterCanBeReadWithoutOpeningAnEncryptedArchive() {
		if ( ! \SHCM\Crypto\Cipher::isAvailable() ) {
			$this->markTestSkipped( 'No cipher available on this server.' );
		}

		$path = $this->path();

		$writer = Writer::create( $path, array( 'password' => 'a strong password' ) );
		$writer->addString( 'manifest.json', '{"a":1}' );
		$writer->close( array( 'files' => 7 ) );
		$writer->release();

		$footer = Reader::readFooter( $path );
		$this->assertIsArray( $footer );
		$this->assertSame( 7, $footer['files'] );

		$prologue = Reader::peek( $path );
		$this->assertTrue( $prologue['encrypted'] );
	}

	public function testExtractionResumesAtBlockBoundaries() {
		$path    = $this->path();
		$payload = random_bytes( 400000 );

		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->addString( 'files/data.bin', $payload );
		$writer->close();
		$writer->release();

		$reader = new Reader( $path );
		$entry  = $reader->findEntry( 'files/data.bin' );
		$target = $this->path( '.out' );
		$handle = fopen( $target, 'wb' );

		$state = array();
		$loops = 0;
		do {
			$state = $reader->extractTo( $entry, $handle, $state, 70000 );
			++$loops;
			$this->assertLessThan( 50, $loops, 'Extraction did not make progress.' );
		} while ( empty( $state['done'] ) );
		fclose( $handle );

		$this->assertGreaterThan( 1, $loops );
		$this->assertSame( $payload, file_get_contents( $target ) );
		$this->assertSame( $entry['hash'], $state['hash'] );
	}

	public function testAbortedEntryLeavesNoTrace() {
		$path   = $this->path();
		$writer = Writer::create( $path, array( 'block_size' => 65536 ) );
		$writer->addString( 'manifest.json', '{}' );
		$writer->addString( 'files/kept.txt', 'kept' );
		$writer->beginEntry( 'files/torn.bin' );
		$writer->append( random_bytes( 200000 ) );
		$writer->abortEntry();
		$writer->addString( 'files/after.txt', 'after' );
		$writer->close();
		$writer->release();

		$reader = new Reader( $path );
		$this->assertSame( 'kept', $this->readEntry( $reader, 'files/kept.txt' ) );
		$this->assertSame( 'after', $this->readEntry( $reader, 'files/after.txt' ) );
		$this->assertNull( $reader->findEntry( 'files/torn.bin' ) );
		$this->assertSame( 3, $reader->footer()['entries'] );
		$this->assertTrue( ( new Verifier( new Reader( $path ) ) )->verifyAll()['ok'] );
	}

	public function testVerificationResumesInsideALargeEntry() {
		$path   = $this->path();
		$writer = Writer::create( $path, array( 'block_size' => 65536, 'compress' => false ) );
		$writer->addString( 'files/small.txt', 'small' );
		$writer->addString( 'files/large.bin', random_bytes( 65536 * 20 + 7 ) );
		$writer->addString( 'files/last.txt', 'last' );
		$writer->close();
		$writer->release();

		$verifier = new Verifier( new Reader( $path ) );
		$state    = $verifier->initialState();
		$calls    = 0;
		do {
			// A fresh reader and an expired budget per call, as across requests.
			$verifier = new Verifier( new Reader( $path ) );
			$state    = $verifier->verifyEntries( $state, new \SHCM\Jobs\Budget( 0.000001, 0 ) );
			$state    = json_decode( json_encode( $state ), true );
			++$calls;
		} while ( empty( $state['done'] ) && $calls < 100 );

		$this->assertSame( array(), $state['errors'] );
		$this->assertSame( 3, $state['checked'] );
		$this->assertGreaterThan( 15, $calls, 'the large entry was verified across many calls' );
	}

	public function testBackslashesInNamesAreKept() {
		$path   = $this->path();
		$writer = Writer::create( $path );
		$writer->addString( 'files/wp-content/images\\logo.png', 'png' );
		$writer->close();
		$writer->release();
		$this->assertSame( 'png', $this->readEntry( new Reader( $path ), 'files/wp-content/images\\logo.png' ) );
	}

	public function testNonUtf8EntryNamesRoundTrip() {
		$name   = "files/wp-content/Preisliste_M\xe4rz.pdf";
		$path   = $this->path();
		$writer = Writer::create( $path );
		$writer->addString( 'manifest.json', '{}' );
		$writer->addString( $name, 'pdf' );
		$writer->close();
		$writer->release();
		$this->assertSame( 'pdf', $this->readEntry( new Reader( $path ), $name ) );
		$this->assertTrue( ( new Verifier( new Reader( $path ) ) )->verifyAll()['ok'] );
	}
}
