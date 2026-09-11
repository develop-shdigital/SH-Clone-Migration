<?php
/**
 * Serialization aware string rewriting.
 *
 * @package SHCM
 */

namespace SHCM\URL;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Rewrites the string literals inside a PHP serialized payload and recomputes
 * every length prefix.
 *
 * This parses the serialized format directly instead of calling unserialize()
 * and re-serializing, which matters for real sites:
 *
 *  - objects of classes that are not loaded survive untouched instead of
 *    turning into __PHP_Incomplete_Class,
 *  - back references (r: / R:) keep pointing at the right value,
 *  - custom Serializable payloads (C:) keep their own encoding,
 *  - nothing is silently dropped by a failed unserialize().
 *
 * If the payload does not parse cleanly the rewriter reports failure and the
 * caller leaves the value alone rather than corrupting it.
 */
class SerializedRewriter {

	/**
	 * Input string.
	 *
	 * @var string
	 */
	protected $in = '';

	/**
	 * Cursor.
	 *
	 * @var int
	 */
	protected $pos = 0;

	/**
	 * Input length.
	 *
	 * @var int
	 */
	protected $len = 0;

	/**
	 * String replacement callback.
	 *
	 * @var callable
	 */
	protected $callback;

	/**
	 * Whether anything changed.
	 *
	 * @var bool
	 */
	protected $changed = false;

	/**
	 * Number of string literals rewritten.
	 *
	 * @var int
	 */
	protected $rewritten = 0;

	/**
	 * Recursion guard.
	 *
	 * @var int
	 */
	protected $depth = 0;

	/**
	 * Maximum nesting depth.
	 */
	const MAX_DEPTH = 64;

	/**
	 * Rewrite a serialized payload.
	 *
	 * @param string   $serialized Serialized payload.
	 * @param callable $callback   Receives a string literal, returns its replacement.
	 * @return array{ok:bool,value:string,changed:bool,strings:int}
	 */
	public function rewrite( $serialized, callable $callback ) {
		$this->in        = (string) $serialized;
		$this->len       = strlen( $this->in );
		$this->pos       = 0;
		$this->callback  = $callback;
		$this->changed   = false;
		$this->rewritten = 0;
		$this->depth     = 0;

		try {
			$out = $this->value();
		} catch ( \RuntimeException $e ) {
			return array(
				'ok'      => false,
				'value'   => $serialized,
				'changed' => false,
				'strings' => 0,
			);
		}

		// Trailing whitespace is tolerated, anything else means we mis-parsed.
		if ( '' !== trim( substr( $this->in, $this->pos ) ) ) {
			return array(
				'ok'      => false,
				'value'   => $serialized,
				'changed' => false,
				'strings' => 0,
			);
		}

		return array(
			'ok'      => true,
			'value'   => $out,
			'changed' => $this->changed,
			'strings' => $this->rewritten,
		);
	}

	/**
	 * Parse and rewrite one value.
	 *
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function value() {
		if ( ++$this->depth > self::MAX_DEPTH ) {
			throw new \RuntimeException( 'Serialized payload nested too deeply.' );
		}
		try {
			return $this->parseValue();
		} finally {
			--$this->depth;
		}
	}

	/**
	 * Value dispatcher.
	 *
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function parseValue() {
		if ( $this->pos >= $this->len ) {
			throw new \RuntimeException( 'Unexpected end of serialized payload.' );
		}
		$type = $this->in[ $this->pos ];

		switch ( $type ) {
			case 'N':
				$this->expect( 'N;' );
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				return $this->scalarToken( $type );

			case 's':
				return $this->stringToken();

			case 'a':
				return $this->arrayToken();

			case 'O':
				return $this->objectToken();

			case 'C':
				return $this->customToken();

			case 'E':
				return $this->enumToken();
		}

		throw new \RuntimeException( sprintf( 'Unsupported serialized type "%s".', $type ) );
	}

	/**
	 * Simple "x:value;" tokens.
	 *
	 * @param string $type Type char.
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function scalarToken( $type ) {
		$this->expect( $type . ':' );
		$end = strpos( $this->in, ';', $this->pos );
		if ( false === $end ) {
			throw new \RuntimeException( 'Unterminated scalar token.' );
		}
		$raw       = substr( $this->in, $this->pos, $end - $this->pos );
		$this->pos = $end + 1;
		return $type . ':' . $raw . ';';
	}

	/**
	 * String token, the only place a replacement can happen.
	 *
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function stringToken() {
		$this->expect( 's:' );
		$length = $this->readInt();
		$this->expect( ':"' );
		if ( $this->pos + $length > $this->len ) {
			throw new \RuntimeException( 'String token runs past the end of the payload.' );
		}
		$value     = substr( $this->in, $this->pos, $length );
		$this->pos += $length;
		$this->expect( '";' );

		$replaced = $this->replaceString( $value );

		return 's:' . strlen( $replaced ) . ':"' . $replaced . '";';
	}

	/**
	 * Apply the callback, recursing into nested serialized payloads.
	 *
	 * @param string $value String literal.
	 * @return string
	 */
	protected function replaceString( $value ) {
		// A serialized payload stored inside another serialized string is
		// common in WordPress options; rewrite it with the same rules so its
		// own length prefixes stay correct.
		if ( $this->looksSerialized( $value ) ) {
			$nested = new self();
			$result = $nested->rewrite( $value, $this->callback );
			if ( $result['ok'] ) {
				if ( $result['changed'] ) {
					$this->changed    = true;
					$this->rewritten += $result['strings'];
				}
				return $result['value'];
			}
		}

		$replaced = call_user_func( $this->callback, $value );
		if ( $replaced !== $value ) {
			$this->changed = true;
			++$this->rewritten;
		}
		return $replaced;
	}

	/**
	 * Cheap check for a nested serialized payload.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	protected function looksSerialized( $value ) {
		if ( strlen( $value ) < 4 || ! isset( $value[1] ) || ':' !== $value[1] ) {
			return false;
		}
		return (bool) preg_match( '/^[aOsbid]:/', $value );
	}

	/**
	 * Array token.
	 *
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function arrayToken() {
		$this->expect( 'a:' );
		$count = $this->readInt();
		$this->expect( ':{' );
		$out = 'a:' . $count . ':{';
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->value(); // Key.
			$out .= $this->value(); // Value.
		}
		$this->expect( '}' );
		return $out . '}';
	}

	/**
	 * Object token.
	 *
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function objectToken() {
		$this->expect( 'O:' );
		$name_length = $this->readInt();
		$this->expect( ':"' );
		if ( $this->pos + $name_length > $this->len ) {
			throw new \RuntimeException( 'Class name runs past the end of the payload.' );
		}
		$class     = substr( $this->in, $this->pos, $name_length );
		$this->pos += $name_length;
		$this->expect( '":' );
		$count = $this->readInt();
		$this->expect( ':{' );

		$out = 'O:' . strlen( $class ) . ':"' . $class . '":' . $count . ':{';
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->value(); // Property name.
			$out .= $this->value(); // Property value.
		}
		$this->expect( '}' );
		return $out . '}';
	}

	/**
	 * Custom (Serializable) token: the payload is opaque, so only the raw bytes
	 * are rewritten and the byte count is recomputed.
	 *
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function customToken() {
		$this->expect( 'C:' );
		$name_length = $this->readInt();
		$this->expect( ':"' );
		$class     = substr( $this->in, $this->pos, $name_length );
		$this->pos += $name_length;
		$this->expect( '":' );
		$data_length = $this->readInt();
		$this->expect( ':{' );
		if ( $this->pos + $data_length > $this->len ) {
			throw new \RuntimeException( 'Custom payload runs past the end of the payload.' );
		}
		$data      = substr( $this->in, $this->pos, $data_length );
		$this->pos += $data_length;
		$this->expect( '}' );

		$replaced = call_user_func( $this->callback, $data );
		if ( $replaced !== $data ) {
			$this->changed = true;
			++$this->rewritten;
		}

		return 'C:' . strlen( $class ) . ':"' . $class . '":' . strlen( $replaced ) . ':{' . $replaced . '}';
	}

	/**
	 * Enum token (PHP 8.1+).
	 *
	 * @return string
	 * @throws \RuntimeException On malformed input.
	 */
	protected function enumToken() {
		$this->expect( 'E:' );
		$length = $this->readInt();
		$this->expect( ':"' );
		$value     = substr( $this->in, $this->pos, $length );
		$this->pos += $length;
		$this->expect( '";' );
		return 'E:' . strlen( $value ) . ':"' . $value . '";';
	}

	/**
	 * Consume an expected literal.
	 *
	 * @param string $literal Literal.
	 * @return void
	 * @throws \RuntimeException When the input does not match.
	 */
	protected function expect( $literal ) {
		$length = strlen( $literal );
		if ( substr( $this->in, $this->pos, $length ) !== $literal ) {
			throw new \RuntimeException(
				sprintf( 'Expected "%1$s" at offset %2$d of the serialized payload.', $literal, $this->pos )
			);
		}
		$this->pos += $length;
	}

	/**
	 * Read a non negative integer.
	 *
	 * @return int
	 * @throws \RuntimeException When no digits are present.
	 */
	protected function readInt() {
		$start = $this->pos;
		while ( $this->pos < $this->len && ctype_digit( $this->in[ $this->pos ] ) ) {
			++$this->pos;
		}
		if ( $start === $this->pos ) {
			throw new \RuntimeException( 'Expected a number in the serialized payload.' );
		}
		return (int) substr( $this->in, $start, $this->pos - $start );
	}
}
