<?php
/**
 * Support helper tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Core\Result;
use SHCM\Core\Settings;
use SHCM\Crypto\Cipher;
use SHCM\Database\PrefixRewriter;
use SHCM\Logging\Redactor;
use SHCM\Support\Bytes;
use SHCM\Support\Json;

/**
 * The small pieces everything else is built on.
 */
class SupportTest extends TestCase {

	public function testIniSizeParsing() {
		$this->assertSame( 134217728, Bytes::parseIni( '128M' ) );
		$this->assertSame( 1073741824, Bytes::parseIni( '1G' ) );
		$this->assertSame( 2048, Bytes::parseIni( '2K' ) );
		$this->assertSame( -1, Bytes::parseIni( '-1' ) );
		$this->assertSame( 0, Bytes::parseIni( '' ) );
		$this->assertSame( 512, Bytes::parseIni( '512' ) );
	}

	public function testByteFormatting() {
		$this->assertSame( '0 B', Bytes::format( 0 ) );
		$this->assertSame( '1.00 KB', Bytes::format( 1024 ) );
		$this->assertSame( '1.50 MB', Bytes::format( 1572864 ) );
		$this->assertSame( '∞', Bytes::format( -1 ) );
	}

	public function testIntegerPacking() {
		foreach ( array( 0, 1, 255, 65535, 4294967295 ) as $value ) {
			$this->assertSame( $value, Bytes::unpackU32( Bytes::packU32( $value ) ) );
		}
		foreach ( array( 0, 1, 4294967296, 187904819200, PHP_INT_MAX >> 4 ) as $value ) {
			$this->assertSame( $value, Bytes::unpackU64( Bytes::packU64( $value ) ) );
		}
	}

	public function testSizePaddingIsFixedWidth() {
		$this->assertSame( 20, strlen( Bytes::pad( 0 ) ) );
		$this->assertSame( 20, strlen( Bytes::pad( 123456789012345 ) ) );
		$this->assertSame( '00000000000000000042', Bytes::pad( 42 ) );
	}

	public function testJsonHelpers() {
		$this->assertSame( '{"url":"https://a.test/b"}', Json::encode( array( 'url' => 'https://a.test/b' ) ) );
		$this->assertSame( array( 'a' => 1 ), Json::decode( '{"a":1}' ) );
		$this->assertNull( Json::decode( 'not json' ) );
		$this->assertNull( Json::decode( '' ) );
		$this->assertTrue( Json::looksLikeJson( '{"a":1}' ) );
		$this->assertFalse( Json::looksLikeJson( 'plain text' ) );
	}

	public function testResultShape() {
		$ok = Result::ok( 'stage', 'all good', array( 'progress' => 1.0 ) );
		$this->assertTrue( $ok->isSuccess() );
		$this->assertSame( 'stage', $ok->stage() );

		$fail = Result::fail( 'stage', 'broke', 'technical detail', true, 'try again' );
		$this->assertFalse( $fail->isSuccess() );
		$this->assertTrue( $fail->isRecoverable() );
		$this->assertSame( 'try again', $fail->suggestion() );

		$array = $fail->toArray();
		$this->assertArrayHasKey( 'recoverable', $array );
	}

	public function testRedactorHidesSecrets() {
		$redactor = new Redactor();
		$redactor->addLiteral( 'my-database-password' );

		$this->assertStringNotContainsString(
			'my-database-password',
			$redactor->scrub( 'connecting with my-database-password now' )
		);
		$this->assertStringNotContainsString( 'sk_live_1234567890', $redactor->scrub( 'api_key=sk_live_1234567890' ) );
		$this->assertStringNotContainsString( 'hunter2', $redactor->scrub( '"password": "hunter2"' ) );
		$this->assertStringNotContainsString( 'abcdef', $redactor->scrub( 'Authorization: Bearer abcdef' ) );
		$this->assertStringNotContainsString( 'topsecret', $redactor->scrub( 'mysql://user:topsecret@localhost/db' ) );
		$this->assertSame( 'nothing sensitive here', $redactor->scrub( 'nothing sensitive here' ) );
	}

	public function testPrefixRewriterOnlyTouchesIdentifiers() {
		$rewriter = new PrefixRewriter( 'wpsrc_', 'wpdst_' );

		$this->assertSame(
			'DROP TABLE IF EXISTS `wpdst_posts`',
			$rewriter->statement( 'DROP TABLE IF EXISTS `wpsrc_posts`' )
		);
		$this->assertSame(
			'CREATE TABLE `wpdst_posts` (id INT, KEY `idx` (id))',
			$rewriter->statement( 'CREATE TABLE `wpsrc_posts` (id INT, KEY `idx` (id))' )
		);

		// Row data mentioning a table name must survive untouched.
		$insert = "INSERT INTO `wpsrc_options` VALUES (1,'note','a value mentioning `wpsrc_posts` and wpsrc_users')";
		$this->assertSame(
			"INSERT INTO `wpdst_options` VALUES (1,'note','a value mentioning `wpsrc_posts` and wpsrc_users')",
			$rewriter->statement( $insert )
		);
	}

	public function testPrefixRewriterIsANoopWhenPrefixesMatch() {
		$rewriter = new PrefixRewriter( 'wp_', 'wp_' );
		$this->assertTrue( $rewriter->isNoop() );
		$this->assertSame( 'DROP TABLE `wp_posts`', $rewriter->statement( 'DROP TABLE `wp_posts`' ) );
	}

	public function testPrefixRewriterTranslatesTableNames() {
		$rewriter = new PrefixRewriter( 'old_', 'new_' );
		$this->assertSame( 'new_posts', $rewriter->table( 'old_posts' ) );
		$this->assertSame( 'unrelated_table', $rewriter->table( 'unrelated_table' ) );
	}

	public function testCipherRoundTrip() {
		if ( ! Cipher::isAvailable() ) {
			$this->markTestSkipped( 'No cipher available.' );
		}

		$init      = Cipher::initialise( 'a long enough password' );
		$cipher    = $init['cipher'];
		$plaintext = random_bytes( 5000 );

		$encrypted = $cipher->encrypt( $plaintext );
		$this->assertNotSame( $plaintext, $encrypted );
		$this->assertSame( $plaintext, $cipher->decrypt( $encrypted ) );

		$reopened = Cipher::fromParams( 'a long enough password', $init['params'] );
		$this->assertSame( $plaintext, $reopened->decrypt( $encrypted ) );

		$this->expectException( \RuntimeException::class );
		Cipher::fromParams( 'the wrong password', $init['params'] );
	}

	public function testCipherRejectsTamperedBlocks() {
		if ( ! Cipher::isAvailable() ) {
			$this->markTestSkipped( 'No cipher available.' );
		}

		$init      = Cipher::initialise( 'a long enough password' );
		$encrypted = $init['cipher']->encrypt( 'hello' );
		$tampered  = substr( $encrypted, 0, -1 ) . chr( ord( substr( $encrypted, -1 ) ) ^ 0xFF );

		$this->assertNull( $init['cipher']->decryptOrNull( $tampered ) );
	}

	public function testSettingsSanitisation() {
		$settings = new Settings();
		$clean    = $settings->sanitize(
			array(
				'block_size'        => 10,
				'compression'       => 'nonsense',
				'compression_level' => 42,
				'memory_guard'      => 500,
				'include_core'      => 'yes',
				'exclude_patterns'  => "*.log\n../escape\n\n  *.tmp  ",
				'unknown_key'       => 'ignored',
			),
			Settings::defaults()
		);

		$this->assertSame( 65536, $clean['block_size'] );
		$this->assertSame( 'auto', $clean['compression'] );
		$this->assertSame( 9, $clean['compression_level'] );
		$this->assertSame( 95, $clean['memory_guard'] );
		$this->assertTrue( $clean['include_core'] );
		$this->assertSame( array( '*.log', '/escape', '*.tmp' ), $clean['exclude_patterns'] );
		$this->assertArrayNotHasKey( 'unknown_key', $clean );
	}

	public function testDefaultExclusionsCoverTheStorageDirectory() {
		$this->assertContains( 'wp-content/' . \SHCM\Filesystem\Storage::DIR_NAME, Settings::defaultExclusions() );
	}
}
