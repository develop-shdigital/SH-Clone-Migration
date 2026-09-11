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
	 * Encode a value, never escaping slashes or unicode.
	 *
	 * @param mixed $value  Value.
	 * @param bool  $pretty Pretty print.
	 * @return string
	 */
	public static function encode( $value, $pretty = false ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$encoded = json_encode( $value, $flags );
		return false === $encoded ? '{}' : $encoded;
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
		return $decoded;
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
