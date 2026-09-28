<?php
/**
 * Outbound HTTP abstraction.
 *
 * @package SHCM
 */

namespace SHCM\Remote\Http;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Sends one HTTP request.
 *
 * The Google Drive client only talks to this interface, so the unit tests can
 * script Google's answers and WordPress stays out of the pure classes.
 *
 * Implementations must never follow redirects (a resumable upload answers
 * "308 Resume Incomplete", which has to come back verbatim) and must never
 * throw: a transport failure is reported as status 0 plus an error text.
 */
interface HttpTransport {

	/**
	 * Send a request.
	 *
	 * @param string $method  HTTP method (GET, POST, PUT, DELETE, ...).
	 * @param string $url     Absolute URL.
	 * @param array  $headers Request headers, name => value.
	 * @param string $body    Raw request body ('' for none).
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
	);
}
