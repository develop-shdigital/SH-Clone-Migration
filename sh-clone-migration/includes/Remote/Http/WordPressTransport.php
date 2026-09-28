<?php
/**
 * HTTP transport over the WordPress HTTP API.
 *
 * @package SHCM
 */

namespace SHCM\Remote\Http;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Sends requests with wp_remote_request().
 *
 * Deliberately not wp_safe_remote_request(): that one rejects loopback
 * addresses, which would break the end-to-end tests against a local fake
 * Google server; the URLs used here come from code, never from user input.
 */
final class WordPressTransport implements HttpTransport {

	const DEFAULT_TIMEOUT = 30.0;

	/**
	 * Send a request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     Absolute URL.
	 * @param array  $headers Request headers, name => value.
	 * @param string $body    Raw request body.
	 * @param array  $options Options: 'timeout' (float, seconds).
	 * @return HttpResponse
	 */
	public function request(
		$method,
		#[\SensitiveParameter]
		$url,
		#[\SensitiveParameter]
		array $headers = array(),
		#[\SensitiveParameter]
		$body = '',
		array $options = array()
	) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return HttpResponse::failure( 'The WordPress HTTP API is not available.' );
		}

		$timeout = isset( $options['timeout'] ) && is_numeric( $options['timeout'] ) && $options['timeout'] > 0
			? (float) $options['timeout']
			: self::DEFAULT_TIMEOUT;

		$args = array(
			'method'      => strtoupper( (string) $method ),
			'headers'     => $headers,
			'body'        => (string) $body,
			'timeout'     => $timeout,
			// Requests treats 308 "Resume Incomplete" as a redirect: it must come back as is.
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => self::userAgent(),
			'httpversion' => '1.1',
		);

		try {
			$response = wp_remote_request( (string) $url, $args );
		} catch ( \Throwable $e ) {
			// Core converts its own failures to WP_Error; this catches filters that throw.
			return HttpResponse::failure( $e->getMessage() );
		}

		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) || ! is_array( $response ) ) {
			$message = is_object( $response ) && method_exists( $response, 'get_error_message' )
				? (string) $response->get_error_message()
				: '';
			return HttpResponse::failure( $message );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$error  = 0 === $status ? 'The server sent no HTTP status.' : '';

		return new HttpResponse( $status, self::flattenHeaders( wp_remote_retrieve_headers( $response ) ), $body, $error );
	}

	/**
	 * User agent sent with every request.
	 *
	 * @return string
	 */
	public static function userAgent() {
		return 'SH-Clone-Migration/' . ( defined( 'SHCM_VERSION' ) ? SHCM_VERSION : 'dev' );
	}

	/**
	 * Turn what wp_remote_retrieve_headers() returns into name => string.
	 *
	 * That is a Requests CaseInsensitiveDictionary (values are strings, or
	 * arrays for repeated headers) or a plain array.
	 *
	 * @param mixed $headers Headers.
	 * @return array<string, string>
	 */
	public static function flattenHeaders( $headers ) {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		if ( $headers instanceof \Traversable ) {
			$copy = array();
			foreach ( $headers as $name => $value ) {
				$copy[ $name ] = $value;
			}
			$headers = $copy;
		}
		return is_array( $headers ) ? HttpResponse::normalizeHeaders( $headers ) : array();
	}
}
