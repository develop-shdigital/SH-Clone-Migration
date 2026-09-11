<?php
/**
 * Serialized payload rewriting tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\URL\SerializedRewriter;

/**
 * The rewriter must never produce a payload PHP cannot read back.
 */
class SerializedRewriterTest extends TestCase {

	/**
	 * Rewrite a payload, replacing "OLD" with a longer string.
	 *
	 * @param string $serialized Payload.
	 * @param string $from       Needle.
	 * @param string $to         Replacement.
	 * @return array
	 */
	protected function rewrite( $serialized, $from = 'https://old.test', $to = 'https://new.example.com/sub' ) {
		$rewriter = new SerializedRewriter();
		return $rewriter->rewrite(
			$serialized,
			static function ( $value ) use ( $from, $to ) {
				return str_replace( $from, $to, $value );
			}
		);
	}

	public function testScalarsAreUntouched() {
		foreach ( array( 'N;', 'b:1;', 'b:0;', 'i:42;', 'i:-42;', 'd:1.5;', 'd:INF;' ) as $payload ) {
			$result = $this->rewrite( $payload );
			$this->assertTrue( $result['ok'], $payload );
			$this->assertSame( $payload, $result['value'] );
			$this->assertFalse( $result['changed'] );
		}
	}

	public function testStringLengthIsRecalculated() {
		$original = serialize( 'go to https://old.test now' );
		$result   = $this->rewrite( $original );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'go to https://new.example.com/sub now', unserialize( $result['value'] ) );
	}

	public function testNestedArraysAndKeys() {
		$data = array(
			'https://old.test' => array(
				'child' => array( 'https://old.test/a', 42, true, null ),
			),
			'plain'            => 'nothing here',
		);

		$result = $this->rewrite( serialize( $data ) );
		$this->assertTrue( $result['ok'] );

		$back = unserialize( $result['value'] );
		$this->assertArrayHasKey( 'https://new.example.com/sub', $back );
		$this->assertSame( 'https://new.example.com/sub/a', $back['https://new.example.com/sub']['child'][0] );
		$this->assertSame( 42, $back['https://new.example.com/sub']['child'][1] );
		$this->assertTrue( $back['https://new.example.com/sub']['child'][2] );
		$this->assertNull( $back['https://new.example.com/sub']['child'][3] );
	}

	public function testUnicodeAndBinaryStringsKeepTheirByteLength() {
		$data   = array( 'emoji' => 'ünïcode ✓ 🎉 https://old.test', 'binary' => "\x00\x01\x02binary\xff" );
		$result = $this->rewrite( serialize( $data ) );

		$this->assertTrue( $result['ok'] );
		$back = unserialize( $result['value'] );
		$this->assertSame( 'ünïcode ✓ 🎉 https://new.example.com/sub', $back['emoji'] );
		$this->assertSame( "\x00\x01\x02binary\xff", $back['binary'] );
	}

	public function testObjectOfAnUnknownClassSurvives() {
		$inner   = 'https://old.test/logo.png';
		$payload = 'O:18:"Some_Missing_Class":2:{s:3:"url";s:' . strlen( $inner ) . ':"' . $inner . '";s:1:"n";i:3;}';

		$result = $this->rewrite( $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'O:18:"Some_Missing_Class"', $result['value'] );
		$this->assertMatchesRegularExpression( '/s:(\d+):"(https:[^"]*)"/', $result['value'] );
		preg_match( '/s:(\d+):"(https:[^"]*)"/', $result['value'], $matches );
		$this->assertSame( strlen( $matches[2] ), (int) $matches[1] );
	}

	public function testReferencesArePreserved() {
		$object      = new \stdClass();
		$object->url = 'https://old.test/ref';
		$payload     = serialize( array( $object, $object ) );

		$result = $this->rewrite( $payload );
		$this->assertTrue( $result['ok'] );

		$back = unserialize( $result['value'] );
		$this->assertSame( $back[0], $back[1], 'The back reference should still point at the same instance.' );
		$this->assertSame( 'https://new.example.com/sub/ref', $back[0]->url );
	}

	public function testNestedSerializedStringIsRewrittenToo() {
		$payload = serialize( array( 'inner' => serialize( array( 'url' => 'https://old.test/deep' ) ) ) );

		$result = $this->rewrite( $payload );
		$this->assertTrue( $result['ok'] );

		$outer = unserialize( $result['value'] );
		$inner = unserialize( $outer['inner'] );
		$this->assertSame( 'https://new.example.com/sub/deep', $inner['url'] );
	}

	public function testCustomSerializablePayloadKeepsItsByteCount() {
		$data    = 'https://old.test/x;a;b';
		$payload = 'C:9:"ArrayList":' . strlen( $data ) . ':{' . $data . '}';

		$result = $this->rewrite( $payload );

		$this->assertTrue( $result['ok'] );
		preg_match( '/^C:9:"ArrayList":(\d+):\{(.*)\}$/s', $result['value'], $matches );
		$this->assertNotEmpty( $matches );
		$this->assertSame( strlen( $matches[2] ), (int) $matches[1] );
	}

	public function testEnumsPassThrough() {
		$payload = 'a:2:{s:1:"e";E:11:"Suit:Hearts";s:1:"u";s:16:"https://old.test";}';
		$result  = $this->rewrite( $payload );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'E:11:"Suit:Hearts"', $result['value'] );
		$this->assertStringContainsString( 's:27:"https://new.example.com/sub"', $result['value'] );
	}

	public function testBrokenPayloadIsRefused() {
		$broken = 'a:1:{s:3:"url";s:99:"https://old.test";}';
		$result = $this->rewrite( $broken );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( $broken, $result['value'] );
		$this->assertFalse( $result['changed'] );
	}

	public function testTrailingGarbageIsRefused() {
		$result = $this->rewrite( serialize( array( 'a' => 'https://old.test' ) ) . 'trailing' );
		$this->assertFalse( $result['ok'] );
	}

	public function testDeepNestingIsRefusedRatherThanExploding() {
		$payload = 'i:1;';
		for ( $i = 0; $i < 200; $i++ ) {
			$payload = 'a:1:{i:0;' . $payload . '}';
		}

		$result = $this->rewrite( $payload );
		$this->assertFalse( $result['ok'] );
	}

	public function testShorterReplacementAlsoUpdatesLengths() {
		$payload = serialize( array( 'u' => 'https://a-very-long-source-domain.example' ) );
		$result  = $this->rewrite( $payload, 'https://a-very-long-source-domain.example', 'https://s.io' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'u' => 'https://s.io' ), unserialize( $result['value'] ) );
	}
}
