<?php
/**
 * Byte helpers.
 *
 * @package SHCM
 */

namespace SHCM\Support;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Size parsing/formatting helpers that stay accurate above the 32-bit range.
 */
class Bytes {

	/**
	 * Parse a php.ini style shorthand size ("128M", "1G", "-1") into bytes.
	 *
	 * @param string|int $value Shorthand value.
	 * @return int Bytes, or -1 when unlimited.
	 */
	public static function parseIni( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( '-1' === $value ) {
			return -1;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		switch ( $unit ) {
			case 'g':
				$number *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$number *= 1024 * 1024;
				break;
			case 'k':
				$number *= 1024;
				break;
		}
		return (int) $number;
	}

	/**
	 * Human readable byte size.
	 *
	 * @param int|float $bytes    Size in bytes.
	 * @param int       $decimals Decimal places.
	 * @return string
	 */
	public static function format( $bytes, $decimals = 2 ) {
		$bytes = (float) $bytes;
		if ( $bytes < 0 ) {
			return '∞';
		}
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$index = 0;
		while ( $bytes >= 1024 && $index < count( $units ) - 1 ) {
			$bytes /= 1024;
			++$index;
		}
		if ( 0 === $index ) {
			return sprintf( '%d %s', $bytes, $units[ $index ] );
		}
		return sprintf( '%s %s', number_format( $bytes, $decimals ), $units[ $index ] );
	}

	/**
	 * Pack an unsigned 32-bit integer, big endian.
	 *
	 * @param int $value Value.
	 * @return string
	 */
	public static function packU32( $value ) {
		return pack( 'N', $value );
	}

	/**
	 * Unpack an unsigned 32-bit integer, big endian.
	 *
	 * @param string $binary 4 raw bytes.
	 * @return int
	 */
	public static function unpackU32( $binary ) {
		$parts = unpack( 'N', $binary );
		return (int) $parts[1];
	}

	/**
	 * Pack an unsigned 64-bit integer, big endian (PHP 7.4 safe).
	 *
	 * @param int $value Value.
	 * @return string
	 */
	public static function packU64( $value ) {
		$high = ( $value >> 32 ) & 0xFFFFFFFF;
		$low  = $value & 0xFFFFFFFF;
		return pack( 'NN', $high, $low );
	}

	/**
	 * Unpack an unsigned 64-bit integer, big endian.
	 *
	 * @param string $binary 8 raw bytes.
	 * @return int
	 */
	public static function unpackU64( $binary ) {
		$parts = unpack( 'Nhigh/Nlow', $binary );
		return (int) ( ( $parts['high'] << 32 ) | $parts['low'] );
	}

	/**
	 * Format a size as a fixed width, zero padded decimal string.
	 *
	 * Sizes are stored as strings inside archive headers so that a header can be
	 * patched in place after the payload has been streamed, and so that sizes
	 * above PHP_INT_MAX on 32-bit builds survive a JSON round trip.
	 *
	 * @param int $value Value.
	 * @return string
	 */
	public static function pad( $value ) {
		return str_pad( (string) max( 0, (int) $value ), 20, '0', STR_PAD_LEFT );
	}
}
