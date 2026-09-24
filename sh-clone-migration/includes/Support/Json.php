<?php
/**
 * JSON helpers with consistent flags and error handling.
 *
 * @package SHCM
 */

namespace SHCM\Support;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Small wrapper around json_encode/json_decode.
 */
class Json {

	/**
	 * Marker in front of a base64 encoded byte string that is not valid UTF-8.
	 *
	 * JSON can only carry UTF-8 text, but file names on disk are arbitrary
	 * bytes (Latin-1 names from old FTP clients, CP437 names from Windows
	 * ZIPs). Such a string is stored as this marker plus its base64 form and
	 * turned back into the original bytes on decode, so nothing is lost. A NUL
	 * character never occurs in a real file name.
	 */
	const BINARY_MARKER = "\0b64:";

	/**
	 * Encode a value, never escaping slashes or unicode.
	 *
	 * @param mixed $value  Value.
	 * @param bool  $pretty Pretty print.
	 * @return string
	 * @throws \RuntimeException When the value cannot be represented.
	 */
	public static function encode( $value, $pretty = false ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$encoded = json_encode( $value, $flags );
		if ( false === $encoded && in_array( json_last_error(), array( JSON_ERROR_UTF8, JSON_ERROR_UTF16 ), true ) ) {
			$encoded = json_encode( self::tagBinary( $value ), $flags );
		}
		if ( false === $encoded ) {
			// Never write a partial document: a queue entry or archive header
			// with a field silently turned into null loses data.
			throw new \RuntimeException( 'JSON encoding failed: ' . json_last_error_msg() );
		}
		return $encoded;
	}

	/**
	 * Decode a JSON string into an associative array.
	 *
	 * @param string $json JSON text.
	 * @return array|null Null when the payload is not a JSON object/array.
	 */
	public static function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}
		if ( false !== strpos( $json, '\u0000b64:' ) ) {
			$decoded = self::untagBinary( $decoded );
		}
		return $decoded;
	}

	/**
	 * A string that is safe to show and to send as JSON: invalid UTF-8 bytes
	 * are replaced, so a message naming an oddly encoded file still displays.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function printable( $value ) {
		$value = (string) $value;
		if ( self::isUtf8( $value ) ) {
			return $value;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$previous = ini_get( 'mbstring.substitute_character' );
			@ini_set( 'mbstring.substitute_character', '0xFFFD' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
			$clean = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
			@ini_set( 'mbstring.substitute_character', $previous ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
			if ( is_string( $clean ) && self::isUtf8( $clean ) ) {
				return $clean;
			}
		}
		return (string) preg_replace( '/[\x80-\xFF]/', '?', $value );
	}

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function isUtf8( $value ) {
		return 1 === preg_match( '//u', (string) $value );
	}

	/**
	 * Replace invalid UTF-8 strings (keys and values) with tagged base64.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected static function tagBinary( $value ) {
		if ( is_string( $value ) ) {
			return self::isUtf8( $value ) ? $value : self::BINARY_MARKER . base64_encode( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? self::tagBinary( $key ) : $key ] = self::tagBinary( $item );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Reverse tagBinary().
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected static function untagBinary( $value ) {
		if ( is_string( $value ) ) {
			if ( 0 === strncmp( $value, self::BINARY_MARKER, strlen( self::BINARY_MARKER ) ) ) {
				$bytes = base64_decode( substr( $value, strlen( self::BINARY_MARKER ) ), true );
				return false === $bytes ? $value : $bytes;
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? self::untagBinary( $key ) : $key ] = self::untagBinary( $item );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Detect whether a string looks like a JSON document worth parsing.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function looksLikeJson( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$trimmed = ltrim( $value );
		if ( '' === $trimmed ) {
			return false;
		}
		$first = $trimmed[0];
		if ( '{' !== $first && '[' !== $first && '"' !== $first ) {
			return false;
		}
		json_decode( $value );
		return JSON_ERROR_NONE === json_last_error();
	}
}
