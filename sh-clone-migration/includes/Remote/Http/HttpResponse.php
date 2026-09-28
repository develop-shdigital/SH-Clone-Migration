<?php
/**
 * Outbound HTTP response.
 *
 * @package SHCM
 */

namespace SHCM\Remote\Http;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * The answer to one HttpTransport request.
 *
 * Header names are lower-cased. When a header was sent more than once only
 * the last value is kept: none of the headers the Drive client reads
 * (Location, Range, Content-Range, Content-Type) is meaningful as a list.
 */
final class HttpResponse {

	/**
	 * HTTP status code; 0 when no response arrived (network error, timeout).
	 *
	 * @var int
	 */
	public $status = 0;

	/**
	 * Response headers, lower-case name => value.
	 *
	 * @var array<string, string>
	 */
	public $headers = array();

	/**
	 * Raw response body.
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * Transport error text when $status is 0, otherwise ''.
	 *
	 * @var string
	 */
	public $error = '';

	/**
	 * Constructor.
	 *
	 * @param int    $status  HTTP status (0 = transport failure).
	 * @param array  $headers Headers: name => value (a value may be a list), or "Name: value" lines.
	 * @param string $body    Body.
	 * @param string $error   Transport error text.
	 */
	public function __construct( $status = 0, array $headers = array(), $body = '', $error = '' ) {
		$this->status  = (int) $status;
		$this->headers = self::normalizeHeaders( $headers );
		$this->body    = (string) $body;
		$this->error   = (string) $error;
	}

	/**
	 * A response for a request that never got an answer.
	 *
	 * @param string $error What went wrong.
	 * @return self
	 */
	public static function failure( $error ) {
		return new self( 0, array(), '', '' === (string) $error ? 'The HTTP request failed.' : (string) $error );
	}

	/**
	 * Lower-case the names and flatten repeated headers to their last value.
	 *
	 * @param array|\Traversable $headers Headers.
	 * @return array<string, string>
	 */
	public static function normalizeHeaders( $headers ) {
		$normalized = array();
		if ( ! is_array( $headers ) && ! $headers instanceof \Traversable ) {
			return $normalized;
		}
		foreach ( $headers as $name => $value ) {
			if ( is_int( $name ) ) {
				// A raw "Name: value" line.
				if ( ! is_string( $value ) || false === strpos( $value, ':' ) ) {
					continue;
				}
				list( $name, $value ) = explode( ':', $value, 2 );
			}
			if ( is_array( $value ) ) {
				$value = empty( $value ) ? '' : end( $value );
			}
			if ( null !== $value && ! is_scalar( $value ) ) {
				continue;
			}
			$name = strtolower( trim( (string) $name ) );
			if ( '' === $name ) {
				continue;
			}
			// Unset first so that a later duplicate also moves to the end.
			unset( $normalized[ $name ] );
			$normalized[ $name ] = trim( (string) $value );
		}
		return $normalized;
	}

	/**
	 * One header value.
	 *
	 * @param string $name Header name (any case).
	 * @return string|null Null when the header is absent.
	 */
	public function header( $name ) {
		$name = strtolower( trim( (string) $name ) );
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
	}

	/**
	 * The body decoded as a JSON object or array.
	 *
	 * @return array|null Null when the body is empty or not a JSON object/array.
	 */
	public function json() {
		if ( '' === trim( $this->body ) ) {
			return null;
		}
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Whether the status is 2xx.
	 *
	 * @return bool
	 */
	public function isSuccess() {
		return $this->status >= 200 && $this->status < 300;
	}
}
