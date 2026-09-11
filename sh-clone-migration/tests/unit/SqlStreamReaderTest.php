<?php
/**
 * SQL statement splitter tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Database\SqlStreamReader;

/**
 * A dump is fed in at arbitrary chunk boundaries; statements must come out
 * exactly as they went in.
 */
class SqlStreamReaderTest extends TestCase {

	/**
	 * Split SQL, feeding it in chunks of a given size.
	 *
	 * @param string $sql        SQL text.
	 * @param int    $chunk_size Chunk size.
	 * @return string[]
	 */
	protected function split( $sql, $chunk_size = 7 ) {
		$reader     = new SqlStreamReader();
		$statements = array();

		for ( $offset = 0; $offset < strlen( $sql ); $offset += $chunk_size ) {
			$reader->feed( substr( $sql, $offset, $chunk_size ) );
			while ( true ) {
				$statement = $reader->next();
				if ( null === $statement ) {
					break;
				}
				if ( '' !== $statement ) {
					$statements[] = $statement;
				}
			}
		}

		$last = $reader->flush();
		if ( null !== $last ) {
			$statements[] = $last;
		}

		return $statements;
	}

	public function testSimpleStatements() {
		$statements = $this->split( "SELECT 1;\nSELECT 2;\n" );
		$this->assertSame( array( 'SELECT 1', 'SELECT 2' ), $statements );
	}

	public function testSemicolonInsideAStringDoesNotSplit() {
		$statements = $this->split( "INSERT INTO t VALUES ('a;b'),('c;d');\nSELECT 1;\n" );
		$this->assertCount( 2, $statements );
		$this->assertSame( "INSERT INTO t VALUES ('a;b'),('c;d')", $statements[0] );
	}

	public function testEscapedQuotes() {
		$sql        = "INSERT INTO t VALUES ('it\\'s here; really'),('\"double\"');\n";
		$statements = $this->split( $sql );
		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( "it\\'s here; really", $statements[0] );
	}

	public function testDoubledQuotes() {
		$statements = $this->split( "INSERT INTO t VALUES ('two '' quotes; here');\n" );
		$this->assertCount( 1, $statements );
	}

	public function testBacktickIdentifiersWithSemicolons() {
		$statements = $this->split( "CREATE TABLE `weird;name` (a INT);\nSELECT 1;\n" );
		$this->assertCount( 2, $statements );
		$this->assertSame( 'CREATE TABLE `weird;name` (a INT)', $statements[0] );
	}

	public function testComments() {
		$sql = "-- a comment; with a semicolon\n"
			. "# another; comment\n"
			. "/* block; comment */\n"
			. "SELECT 1;\n";

		$statements = $this->split( $sql );
		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( 'SELECT 1', $statements[ count( $statements ) - 1 ] );
	}

	public function testTrailingBackslashAtChunkBoundary() {
		$sql        = "INSERT INTO t VALUES ('ends with a backslash\\\\');\nSELECT 2;\n";
		$statements = $this->split( $sql, 3 );
		$this->assertCount( 2, $statements );
	}

	public function testVariousChunkSizesProduceTheSameResult() {
		$sql = "DROP TABLE IF EXISTS `wp_posts`;\n"
			. "CREATE TABLE `wp_posts` (id INT, txt TEXT);\n"
			. "INSERT INTO `wp_posts` VALUES (1,'a;b'),(2,'c\\'d;e'),(3,'--x;');\n"
			. "/*!40000 ALTER TABLE `wp_posts` ENABLE KEYS */;\n";

		$reference = $this->split( $sql, 1024 );
		$this->assertCount( 4, $reference );

		foreach ( array( 1, 2, 3, 5, 13, 64, 1000 ) as $size ) {
			$this->assertSame( $reference, $this->split( $sql, $size ), 'Chunk size ' . $size );
		}
	}

	public function testBufferedBytesTracksTheResumePoint() {
		$reader = new SqlStreamReader();
		$reader->feed( "SELECT 1;SELECT 2" );

		$this->assertSame( 'SELECT 1', $reader->next() );
		$this->assertSame( 8, $reader->bufferedBytes() );
		$this->assertNull( $reader->next() );
		$this->assertSame( 'SELECT 2', $reader->flush() );
	}

	public function testEmptyStatementsAreSkipped() {
		$statements = $this->split( ";;\nSELECT 1;;\n" );
		$this->assertSame( array( 'SELECT 1' ), $statements );
	}

	public function testLargeInsertWithManySemicolonsIsFast() {
		$values = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$values[] = "('semi;colon;heavy;value;number;{$i}')";
		}
		$sql = 'INSERT INTO t VALUES ' . implode( ',', $values ) . ";\n";

		$start      = microtime( true );
		$statements = $this->split( $sql, 65536 );
		$elapsed    = microtime( true ) - $start;

		$this->assertCount( 1, $statements );
		$this->assertLessThan( 2.0, $elapsed, 'Splitting should stay linear.' );
	}
}
