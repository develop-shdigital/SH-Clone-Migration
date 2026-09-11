<?php
/**
 * URL replacement tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\URL\Replacer;
use SHCM\URL\RuleBuilder;

/**
 * The rules a real WordPress database needs, and the ones it must not get.
 */
class ReplacerTest extends TestCase {

	/**
	 * Build the standard test replacer.
	 *
	 * @param array $options Rule builder options.
	 * @return Replacer
	 */
	protected function replacer( array $options = array() ) {
		return RuleBuilder::forUrls( 'https://old.test', 'https://new.example.com/blog', $options );
	}

	public function testPlainUrl() {
		$result = $this->replacer()->apply( 'Visit https://old.test/page for info' );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'Visit https://new.example.com/blog/page for info', $result['value'] );
	}

	public function testOtherSchemeIsReplacedToo() {
		$result = $this->replacer()->apply( 'http://old.test/page' );
		$this->assertSame( 'https://new.example.com/blog/page', $result['value'] );
	}

	public function testProtocolRelativeUrl() {
		$result = $this->replacer()->apply( '<img src="//old.test/a.png">' );
		$this->assertSame( '<img src="//new.example.com/blog/a.png">', $result['value'] );
	}

	public function testEscapedSlashesInsideJson() {
		$json   = '{"url":"https:\/\/old.test\/contact","other":1}';
		$result = $this->replacer()->apply( $json );

		$this->assertTrue( $result['changed'] );
		$decoded = json_decode( $result['value'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'https://new.example.com/blog/contact', $decoded['url'] );
	}

	public function testPercentEncodedUrl() {
		$result = $this->replacer()->apply( 'redirect_to=https%3A%2F%2Fold.test%2Fwp-admin' );
		$this->assertSame( 'redirect_to=https%3A%2F%2Fnew.example.com%2Fblog%2Fwp-admin', $result['value'] );
	}

	public function testLowercasePercentEncoding() {
		$result = $this->replacer()->apply( 'u=https%3a%2f%2fold.test%2fx' );
		$this->assertStringContainsString( 'new.example.com', $result['value'] );
	}

	public function testSerializedValueKeepsItsLengths() {
		$payload = serialize( array( 'home' => 'https://old.test', 'nested' => array( 'img' => 'https://old.test/a.png' ) ) );
		$result  = $this->replacer()->apply( $payload );

		$this->assertTrue( $result['changed'] );
		$this->assertTrue( $result['serialized'] );
		$back = unserialize( $result['value'] );
		$this->assertSame( 'https://new.example.com/blog', $back['home'] );
		$this->assertSame( 'https://new.example.com/blog/a.png', $back['nested']['img'] );
	}

	public function testUnparsableSerializedValueIsLeftAlone() {
		$broken = 'a:1:{s:3:"url";s:99:"https://old.test";}';
		$result = $this->replacer()->apply( $broken );

		$this->assertFalse( $result['changed'] );
		$this->assertTrue( $result['failed'] );
		$this->assertSame( $broken, $result['value'] );
	}

	public function testWindowsPathIsNotMistakenForASerializedPayload() {
		$replacer = new Replacer( array( 'https://old.test' => 'https://new.test' ) );
		$replacer->addProbe( 'old.test' );

		$result = $replacer->apply( 'C:\\sites\\old\\https://old.test' );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'C:\\sites\\old\\https://new.test', $result['value'] );
	}

	public function testBareDomainIsKeptByDefault() {
		$result = $this->replacer()->apply( 'Write to us at hello@old.test' );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 1, $this->replacer()->countRemaining( 'hello@old.test' ) );
	}

	public function testBareDomainCanBeOptedIn() {
		$replacer = $this->replacer( array( 'include_bare_domain' => true ) );
		$result   = $replacer->apply( 'Write to us at hello@old.test' );

		$this->assertTrue( $result['changed'] );
		$this->assertStringContainsString( 'new.example.com', $result['value'] );
	}

	public function testUnrelatedDomainsAreUntouched() {
		$value  = 'See https://not-old.test/x and https://example.org/old.test';
		$result = $this->replacer()->apply( $value );
		$this->assertSame( $value, $result['value'] );
	}

	public function testFilesystemPathsAreReplaced() {
		$replacer = RuleBuilder::forUrls(
			'https://old.test',
			'https://new.example.com',
			array( 'paths' => array( '/var/www/old' => '/home/user/new' ) )
		);

		$result = $replacer->apply( serialize( array( 'css' => '/var/www/old/wp-content/uploads/x.css' ) ) );
		$this->assertTrue( $result['changed'] );
		$back = unserialize( $result['value'] );
		$this->assertSame( '/home/user/new/wp-content/uploads/x.css', $back['css'] );
	}

	public function testLongestRuleWinsSoUrlsAreNotDoubleReplaced() {
		$result = $this->replacer()->apply( 'https://old.test and //old.test' );
		$this->assertSame( 'https://new.example.com/blog and //new.example.com/blog', $result['value'] );
	}

	public function testProbeSkipsValuesThatCannotMatch() {
		$replacer = $this->replacer();
		$this->assertFalse( $replacer->mightMatch( 'nothing to see here' ) );
		$this->assertTrue( $replacer->mightMatch( 'https://OLD.TEST/x' ) );
	}

	public function testStatisticsAreCollected() {
		$replacer = $this->replacer();
		$replacer->apply( 'https://old.test/a' );
		$replacer->apply( serialize( array( 'u' => 'https://old.test/b' ) ) );
		$replacer->apply( 'nothing' );

		$stats = $replacer->stats();
		$this->assertSame( 2, $stats['values_changed'] );
		$this->assertSame( 1, $stats['serialized_repaired'] );
	}

	public function testGutenbergBlockAttributes() {
		$block  = '<!-- wp:image {"id":12,"url":"https://old.test/x.png"} --><img src="https://old.test/x.png"/><!-- /wp:image -->';
		$result = $this->replacer()->apply( $block );

		$this->assertStringNotContainsString( 'old.test', $result['value'] );
		$this->assertSame( 2, substr_count( $result['value'], 'https://new.example.com/blog/x.png' ) );
	}

	public function testNormalisationOfInputUrls() {
		$this->assertSame( 'https://example.com', RuleBuilder::normalizeUrl( 'https://example.com/' ) );
		$this->assertSame( 'https://example.com/sub', RuleBuilder::normalizeUrl( 'https://example.com/sub/' ) );
		$this->assertSame( 'http://example.com', RuleBuilder::normalizeUrl( 'example.com' ) );
		$this->assertSame( 'https://example.com:8080', RuleBuilder::normalizeUrl( 'HTTPS://Example.com:8080' ) );
		$this->assertSame( '', RuleBuilder::normalizeUrl( '' ) );
	}

	public function testPortsAreHandled() {
		$replacer = RuleBuilder::forUrls( 'http://source.test:8081', 'http://destination.test:8082' );
		$result   = $replacer->apply( 'http://source.test:8081/a/b' );
		$this->assertSame( 'http://destination.test:8082/a/b', $result['value'] );
	}
}
