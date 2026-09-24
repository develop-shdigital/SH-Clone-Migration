<?php
/**
 * HTTP Range request resolution.
 *
 * @package SHCM
 */

namespace SHCM\Support;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Decides which bytes of a file a GET request should receive (RFC 9110).
 *
 * Download managers split a large download into parallel Range requests and
 * resume interrupted ones with open or suffix ranges, so getting this wrong
 * does not produce an error: it silently hands the client the wrong bytes.
 * Kept free of WordPress so every branch is unit tested.
 */
class HttpRange {

	/**
	 * Resolve a request against a file.
	 *
	 * @param int    $size          File size in bytes.
	 * @param string $range         Value of the Range header, '' when absent.
	 * @param string $if_range      Value of the If-Range header, '' when absent.
	 * @param string $etag          The file's current entity tag, quoted.
	 * @param string $last_modified The file's Last-Modified value (HTTP date).
	 * @return array{status:int,start:int,end:int,length:int}
	 *         status 200 (whole file), 206 (one range) or 416 (unsatisfiable).
	 */
	public static function resolve( $size, $range, $if_range = '', $etag = '', $last_modified = '' ) {
		$size  = max( 0, (int) $size );
		$whole = self::whole( $size );
		$range = trim( (string) $range );

		if ( '' === $range || 0 === $size ) {
			return $whole;
		}

		// A validator that no longer matches means the client holds part of a
		// different file: it must get the whole current one, not a slice of it.
		$if_range = trim( (string) $if_range );
		if ( '' !== $if_range && ! self::validatorMatches( $if_range, $etag, $last_modified ) ) {
			return $whole;
		}

		if ( ! preg_match( '/^bytes\s*=\s*(.+)$/i', $range, $matches ) ) {
			return $whole; // Unknown range unit: ignore the header.
		}

		$spec = trim( $matches[1] );

		// Several ranges would need a multipart/byteranges body. Serving the
		// whole file instead is explicitly allowed and every client copes.
		if ( false !== strpos( $spec, ',' ) ) {
			return $whole;
		}

		if ( ! preg_match( '/^(\d*)\s*-\s*(\d*)$/', $spec, $parts ) ) {
			return $whole; // Malformed: ignore rather than guess.
		}

		$first = $parts[1];
		$last  = $parts[2];

		if ( '' === $first && '' === $last ) {
			return $whole;
		}
		if ( strlen( $first ) > 18 || strlen( $last ) > 18 ) {
			return $whole; // Beyond a 64-bit integer; not a real request.
		}

		if ( '' === $first ) {
			// Suffix range: the final N bytes.
			$suffix = (int) $last;
			if ( 0 === $suffix ) {
				return self::unsatisfiable( $size );
			}
			return self::partial( max( 0, $size - $suffix ), $size - 1 );
		}

		$start = (int) $first;
		if ( '' !== $last && (int) $last < $start ) {
			return $whole; // Syntactically invalid ("5-2"): ignore the header.
		}
		if ( $start >= $size ) {
			return self::unsatisfiable( $size ); // Includes an open "1000-" on a 1000 byte file.
		}
		$end = '' === $last ? $size - 1 : min( (int) $last, $size - 1 );

		return self::partial( $start, $end );
	}

	/**
	 * Whether an If-Range value matches the current file.
	 *
	 * Entity tags compare strongly (a weak tag never matches); dates must be
	 * an exact match of the Last-Modified value.
	 *
	 * @param string $if_range      If-Range value.
	 * @param string $etag          Current entity tag.
	 * @param string $last_modified Current Last-Modified value.
	 * @return bool
	 */
	public static function validatorMatches( $if_range, $etag, $last_modified ) {
		if ( 0 === strpos( $if_range, 'W/' ) ) {
			return false;
		}
		if ( '"' === substr( $if_range, 0, 1 ) ) {
			return '' !== $etag && hash_equals( (string) $etag, $if_range );
		}
		return '' !== $last_modified && $if_range === $last_modified;
	}

	/**
	 * The whole file.
	 *
	 * @param int $size Size.
	 * @return array
	 */
	protected static function whole( $size ) {
		return array(
			'status' => 200,
			'start'  => 0,
			'end'    => max( 0, $size - 1 ),
			'length' => $size,
		);
	}

	/**
	 * One satisfiable range.
	 *
	 * @param int $start First byte.
	 * @param int $end   Last byte, inclusive.
	 * @return array
	 */
	protected static function partial( $start, $end ) {
		return array(
			'status' => 206,
			'start'  => $start,
			'end'    => $end,
			'length' => $end - $start + 1,
		);
	}

	/**
	 * An unsatisfiable range.
	 *
	 * @param int $size Size.
	 * @return array
	 */
	protected static function unsatisfiable( $size ) {
		return array(
			'status' => 416,
			'start'  => 0,
			'end'    => 0,
			'length' => 0,
		);
	}
}
