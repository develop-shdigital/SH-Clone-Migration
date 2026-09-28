<?php
/**
 * Google Drive errors.
 *
 * @package SHCM
 */

namespace SHCM\Remote\GoogleDrive;

use SHCM\Logging\Redactor;
use SHCM\Remote\Http\HttpResponse;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * A failed Google Drive or Google OAuth request.
 *
 * The message is shown to site owners, stored in job files and backup
 * history, printed by WP-CLI and sent in notification emails, none of which
 * pass through the log redactor. It therefore never contains tokens, the
 * client secret, an authorization code, an upload session URI or a response
 * body: only the HTTP status, Google's error reason (dropped when it contains
 * a secret) and Google's short error message, cleaned by safeText().
 *
 * kind() tells callers what to do: RATE_LIMITED and SERVER are worth
 * retrying later (isRetryable()); everything else needs a person or a new
 * upload session.
 */
class DriveException extends \RuntimeException {

	const AUTH_REVOKED    = 'auth_revoked';
	const CLIENT_INVALID  = 'client_invalid';
	const UNAUTHORIZED    = 'unauthorized';
	const RATE_LIMITED    = 'rate_limited';
	const SERVER          = 'server';
	const QUOTA           = 'quota';
	const NOT_FOUND       = 'not_found';
	const SESSION_EXPIRED = 'session_expired';
	const FORBIDDEN       = 'forbidden';
	const BAD_REQUEST     = 'bad_request';
	const INTEGRITY       = 'integrity';
	const NOT_CONNECTED   = 'not_connected';

	/**
	 * Longest detail text taken from a response.
	 */
	const MAX_DETAIL = 300;

	/**
	 * Error kind (one of the constants).
	 *
	 * @var string
	 */
	protected $kind;

	/**
	 * HTTP status, 0 when there was no response.
	 *
	 * @var int
	 */
	protected $http_status;

	/**
	 * Google's error reason ("storageQuotaExceeded", "invalid_grant", ...).
	 *
	 * @var string
	 */
	protected $reason;

	/**
	 * Constructor.
	 *
	 * The documented order is ( kind, message ); ( message, kind ) is
	 * accepted as well because it mirrors \RuntimeException.
	 *
	 * @param string          $kind        One of the kind constants.
	 * @param string          $message     User-safe message.
	 * @param int             $http_status HTTP status (0 = none).
	 * @param string          $reason      Google's error reason.
	 * @param \Throwable|null $previous    Previous exception.
	 */
	public function __construct( $kind, $message = '', $http_status = 0, $reason = '', $previous = null ) {
		if ( ! self::isKind( $kind ) && self::isKind( $message ) ) {
			$swap    = $kind;
			$kind    = $message;
			$message = $swap;
		}
		$this->kind        = (string) $kind;
		$this->http_status = (int) $http_status;
		$this->reason      = (string) $reason;
		parent::__construct( (string) $message, (int) $http_status, $previous instanceof \Throwable ? $previous : null );
	}

	/**
	 * All error kinds.
	 *
	 * @return string[]
	 */
	public static function kinds() {
		return array(
			self::AUTH_REVOKED,
			self::CLIENT_INVALID,
			self::UNAUTHORIZED,
			self::RATE_LIMITED,
			self::SERVER,
			self::QUOTA,
			self::NOT_FOUND,
			self::SESSION_EXPIRED,
			self::FORBIDDEN,
			self::BAD_REQUEST,
			self::INTEGRITY,
			self::NOT_CONNECTED,
		);
	}

	/**
	 * Whether a value is a known kind.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function isKind( $value ) {
		return is_string( $value ) && in_array( $value, self::kinds(), true );
	}

	/**
	 * Error kind.
	 *
	 * @return string
	 */
	public function kind() {
		return $this->kind;
	}

	/**
	 * HTTP status (0 when there was no response).
	 *
	 * @return int
	 */
	public function httpStatus() {
		return $this->http_status;
	}

	/**
	 * Google's error reason, '' when unknown.
	 *
	 * @return string
	 */
	public function reason() {
		return $this->reason;
	}

	/**
	 * Whether trying again later can succeed (rate limits, server trouble,
	 * network failures). Backing off is the caller's job.
	 *
	 * @return bool
	 */
	public function isRetryable() {
		return self::RATE_LIMITED === $this->kind || self::SERVER === $this->kind;
	}

	/**
	 * Map a failed Drive API response to an exception.
	 *
	 * @param HttpResponse $response Response (status 0 = transport failure).
	 * @param string[]     $secrets  Secret values that must not appear in the message.
	 * @return self
	 */
	public static function fromResponse(
		HttpResponse $response,
		#[\SensitiveParameter]
		array $secrets = array()
	) {
		$status = (int) $response->status;
		if ( 0 === $status ) {
			$detail = self::safeText( $response->error, $secrets );
			return new self(
				self::SERVER,
				'Could not connect to Google Drive' . ( '' !== $detail ? ': ' . $detail : '.' ),
				0,
				'network'
			);
		}

		list( $reason, $detail ) = self::describe( $response, $secrets );
		$label                   = $status . ( '' !== $reason ? ' ' . $reason : '' );
		$suffix                  = '' !== $detail ? ': ' . $detail : '.';

		if ( 429 === $status || ( 403 === $status && in_array( $reason, array( 'userRateLimitExceeded', 'rateLimitExceeded' ), true ) ) ) {
			return new self( self::RATE_LIMITED, sprintf( 'Google Drive is limiting the request rate (%s)%s', $label, $suffix ), $status, $reason );
		}
		if ( 403 === $status && 'storageQuotaExceeded' === $reason ) {
			return new self( self::QUOTA, sprintf( 'Google Drive rejected the request (%s)%s', $label, $suffix ), $status, $reason );
		}
		if ( $status >= 500 || 408 === $status ) {
			return new self( self::SERVER, sprintf( 'Google Drive is temporarily unavailable (%s)%s', $label, $suffix ), $status, $reason );
		}
		if ( 401 === $status ) {
			return new self( self::UNAUTHORIZED, sprintf( 'Google Drive did not accept the authorization (%s)%s', $label, $suffix ), $status, $reason );
		}
		if ( 403 === $status ) {
			return new self( self::FORBIDDEN, sprintf( 'Google Drive rejected the request (%s)%s', $label, $suffix ), $status, $reason );
		}
		if ( 404 === $status || 410 === $status ) {
			return new self( self::NOT_FOUND, sprintf( 'Google Drive could not find the file or folder (%s)%s', $label, $suffix ), $status, $reason );
		}
		if ( $status >= 400 ) {
			return new self( self::BAD_REQUEST, sprintf( 'Google Drive rejected the request (%s)%s', $label, $suffix ), $status, $reason );
		}
		// 1xx, 3xx or an unexpected 2xx: something between us and Google answered.
		return new self( self::SERVER, sprintf( 'Google Drive sent an unexpected answer (HTTP %s)%s', $label, $suffix ), $status, $reason );
	}

	/**
	 * Extract Google's error reason and message from a response.
	 *
	 * Understands the Drive API format {"error":{"code","message","errors":[{"reason"}],"status"}},
	 * the OAuth token endpoint format {"error":"...","error_description":"..."} and short
	 * plain-text bodies ("Not Found"). HTML error pages are ignored.
	 *
	 * @param HttpResponse $response Response.
	 * @param string[]     $secrets  Secret values to remove.
	 * @return array{0: string, 1: string} Reason and message ('' when unknown or not safe to show).
	 */
	public static function describe(
		HttpResponse $response,
		#[\SensitiveParameter]
		array $secrets = array()
	) {
		$reason  = '';
		$message = '';
		$json    = $response->json();

		if ( is_array( $json ) && isset( $json['error'] ) && is_array( $json['error'] ) ) {
			$error   = $json['error'];
			$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';
			if ( isset( $error['errors'][0]['reason'] ) && is_string( $error['errors'][0]['reason'] ) ) {
				$reason = $error['errors'][0]['reason'];
			} elseif ( isset( $error['details'] ) && is_array( $error['details'] ) ) {
				foreach ( $error['details'] as $item ) {
					if ( is_array( $item ) && isset( $item['reason'] ) && is_string( $item['reason'] ) ) {
						$reason = $item['reason'];
						break;
					}
				}
			}
			if ( '' === $reason && isset( $error['status'] ) && is_string( $error['status'] ) ) {
				$reason = $error['status'];
			}
		} elseif ( is_array( $json ) && isset( $json['error'] ) && is_string( $json['error'] ) ) {
			$reason  = $json['error'];
			$message = isset( $json['error_description'] ) && is_string( $json['error_description'] ) ? $json['error_description'] : '';
		} elseif ( null === $json ) {
			$type = (string) $response->header( 'content-type' );
			$text = trim( $response->body );
			if ( '' !== $text && false === stripos( $type, 'html' ) && '<' !== $text[0] && strlen( $text ) <= 200 ) {
				$message = $text;
			}
		}

		// The reason ends up in messages and in the stored connection error, so a
		// value that is (or contains) a secret or a token shape is dropped whole:
		// a client secret such as "GOCSPX-..." fits the character class.
		if ( ! preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $reason ) || self::safeText( $reason, $secrets ) !== $reason ) {
			$reason = '';
		}

		return array( $reason, self::safeText( $message, $secrets ) );
	}

	/**
	 * Make a piece of text from Google (or a transport error) safe to show.
	 *
	 * Removes the given secrets and anything shaped like a token (via the log
	 * redactor), drops query strings from URLs (an upload session id or an
	 * authorization code travels there), flattens it to one line and caps
	 * its length.
	 *
	 * @param string   $text    Text.
	 * @param string[] $secrets Secret values that must not appear.
	 * @return string
	 */
	public static function safeText(
		#[\SensitiveParameter]
		$text,
		#[\SensitiveParameter]
		array $secrets = array()
	) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}

		$redactor = new Redactor();
		foreach ( $secrets as $secret ) {
			if ( is_string( $secret ) && '' !== $secret ) {
				// Also registers the form- and URL-encoded forms.
				$redactor->addLiteral( $secret );
			}
		}
		$text = $redactor->scrub( $text );

		$stripped = preg_replace( '#\b(https?://[^\s?\#\'"<>]*)[?\#][^\s\'"<>]*#i', '$1', $text );
		$text     = null === $stripped ? $text : $stripped;

		$flat = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = trim( preg_replace( '/ {2,}/', ' ', null === $flat ? $text : $flat ) );

		if ( strlen( $text ) > self::MAX_DETAIL ) {
			$text = rtrim( function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_DETAIL, 'UTF-8' ) : substr( $text, 0, self::MAX_DETAIL ) ) . '...';
		}

		return Json::printable( $text );
	}
}
