<?php
/**
 * Google OAuth 2.0 for Google Drive.
 *
 * @package SHCM
 */

namespace SHCM\Remote\GoogleDrive;

use SHCM\Remote\Http\HttpResponse;
use SHCM\Remote\Http\HttpTransport;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Authorization code flow for a web server application, with the site
 * owner's own Google Cloud OAuth client and the drive.file scope only.
 *
 * Token endpoint failures are sorted by what the site owner has to do:
 * invalid_grant means the grant is gone (reconnect), invalid_client and
 * friends mean the client ID or secret is wrong, 5xx and network errors are
 * temporary and never disconnect anything.
 */
final class OAuth {

	const SCOPE = 'https://www.googleapis.com/auth/drive.file';

	const TIMEOUT = 30.0;

	/**
	 * Token endpoint errors that mean the OAuth client itself is unusable.
	 */
	const CLIENT_ERRORS = array( 'invalid_client', 'unauthorized_client', 'deleted_client' );

	/**
	 * Reason of the exception for a refresh rejected for a grant that another
	 * request replaced (or removed) while the refresh was in flight.
	 */
	const GRANT_REPLACED = 'grant_replaced';

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Transport.
	 *
	 * @var HttpTransport
	 */
	private $http;

	/**
	 * Endpoints.
	 *
	 * @var array<string, string>
	 */
	private $endpoints;

	/**
	 * Constructor.
	 *
	 * @param Connection    $connection Connection.
	 * @param HttpTransport $http       Transport.
	 * @param array         $endpoints  Endpoints (Endpoints::resolve()); missing keys use Google's.
	 */
	public function __construct( Connection $connection, HttpTransport $http, array $endpoints ) {
		$this->connection = $connection;
		$this->http       = $http;
		$this->endpoints  = Endpoints::normalize( $endpoints );
	}

	/**
	 * The connection this client authorizes.
	 *
	 * @return Connection
	 */
	public function connection() {
		return $this->connection;
	}

	/**
	 * URL of Google's consent screen.
	 *
	 * access_type=offline and prompt=consent on every connect: Google returns a
	 * refresh token only on a consented code exchange.
	 *
	 * @param string $redirect_uri Redirect URI, exactly as registered with the client.
	 * @param string $state        Anti-CSRF state value.
	 * @param string $login_hint   Optional email address to preselect.
	 * @return string
	 * @throws DriveException When no client credentials are configured.
	 */
	public function authorizationUrl( $redirect_uri, $state, $login_hint = '' ) {
		$this->requireCredentials();

		$params = array(
			'client_id'              => $this->connection->clientId(),
			'redirect_uri'           => (string) $redirect_uri,
			'response_type'          => 'code',
			'scope'                  => self::SCOPE,
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'false',
			'state'                  => (string) $state,
		);
		$login_hint = trim( (string) $login_hint );
		if ( '' !== $login_hint ) {
			$params['login_hint'] = $login_hint;
		}

		$base = $this->endpoints['auth'];
		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange the authorization code from the callback for tokens and store them.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri The same redirect URI as in the authorization URL.
	 * @return true
	 * @throws DriveException When Google refuses, or the grant lacks a refresh token or the Drive scope.
	 */
	public function exchangeCode(
		#[\SensitiveParameter]
		$code,
		$redirect_uri
	) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			throw new DriveException( DriveException::BAD_REQUEST, 'Google did not send an authorization code. Click Connect again.' );
		}
		$this->requireCredentials();
		$this->connection->noteSecret( $code );

		$payload = $this->tokenRequest(
			array(
				'code'          => $code,
				'client_id'     => $this->connection->clientId(),
				'client_secret' => $this->connection->clientSecret(),
				'redirect_uri'  => (string) $redirect_uri,
				'grant_type'    => 'authorization_code',
			),
			null
		);

		$refresh = isset( $payload['refresh_token'] ) && is_string( $payload['refresh_token'] ) ? $payload['refresh_token'] : '';
		if ( '' === $refresh ) {
			throw new DriveException(
				DriveException::BAD_REQUEST,
				'Google did not return a refresh token, so backups could not reach Google Drive without you. Remove the app\'s access at https://myaccount.google.com/permissions, then connect again.',
				200,
				'no_refresh_token'
			);
		}

		$scope = isset( $payload['scope'] ) && is_string( $payload['scope'] ) ? $payload['scope'] : '';
		if ( ! self::grantsDrive( $scope ) ) {
			throw new DriveException(
				DriveException::FORBIDDEN,
				'Google Drive could not be connected: access to Google Drive was not granted. Connect again and allow the app to see, edit, create and delete the Google Drive files it uses.',
				200,
				'scope_not_granted'
			);
		}

		$this->connection->storeTokens( $refresh, $payload['access_token'], self::lifetime( $payload ), $scope );
		return true;
	}

	/**
	 * A valid access token, refreshed when it expires within a minute.
	 *
	 * The refresh result is stored only while the stored grant is still the
	 * one that was used (compare-and-set). When another request connected
	 * again or disconnected during the round trip, the result belongs to the
	 * old grant: it is dropped and the current state is used instead, once.
	 *
	 * @param bool $force Refresh even if the stored token is still valid (after a 401).
	 * @return string
	 * @throws DriveException When the connection is not usable or the refresh fails.
	 */
	public function accessToken( $force = false ) {
		for ( $attempt = 0; ; $attempt++ ) {
			$this->assertUsable();

			// A retry follows a new grant, whose fresh access token is cached.
			if ( ! $force || $attempt > 0 ) {
				$cached = $this->connection->accessToken();
				if ( null !== $cached ) {
					return $cached;
				}
			}

			$refresh = $this->connection->refreshToken();
			if ( null === $refresh ) {
				throw new DriveException( DriveException::AUTH_REVOKED, 'The Google Drive authorization is missing. Reconnect Google Drive.' );
			}

			try {
				$payload = $this->tokenRequest(
					array(
						'client_id'     => $this->connection->clientId(),
						'client_secret' => $this->connection->clientSecret(),
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh,
					),
					$refresh
				);
			} catch ( DriveException $e ) {
				if ( self::GRANT_REPLACED === $e->reason() && 0 === $attempt ) {
					continue;
				}
				throw $e;
			}

			// Google does not rotate refresh tokens today; keep one if it ever does.
			$rotated = isset( $payload['refresh_token'] ) && is_string( $payload['refresh_token'] ) && $payload['refresh_token'] !== $refresh
				? $payload['refresh_token']
				: '';
			if ( $this->connection->storeAccessToken( $payload['access_token'], self::lifetime( $payload ), $rotated, $refresh ) || $attempt > 0 ) {
				// After a second lost race the token is still valid for the grant it came from; it is just not cached.
				return $payload['access_token'];
			}
		}
	}

	/**
	 * Revoke the grant at Google. Best effort, never throws.
	 *
	 * Does nothing for a connection stamped by another installation: its
	 * token is the original site's, and revoking it would disconnect that
	 * site as well.
	 *
	 * @return bool Whether Google confirmed (or the token was already invalid).
	 */
	public function revoke() {
		try {
			$status = $this->connection->status();
			if ( Connection::STATE_OTHER_SITE === $status['state'] ) {
				return false;
			}
			$token = $this->connection->refreshToken();
			if ( null === $token ) {
				$token = $this->connection->accessToken();
			}
			if ( null === $token ) {
				return false;
			}
			$response = $this->http->request(
				'POST',
				$this->endpoints['revoke'],
				array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				http_build_query( array( 'token' => $token ), '', '&' ),
				array( 'timeout' => 15.0 )
			);
			if ( 200 === $response->status ) {
				return true;
			}
			$json = $response->json();
			return 400 === $response->status && is_array( $json ) && isset( $json['error'] ) && 'invalid_token' === $json['error'];
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether a token response's scope list includes drive.file.
	 *
	 * @param string $scope Space separated scopes.
	 * @return bool
	 */
	public static function grantsDrive( $scope ) {
		$scopes = preg_split( '/\s+/', trim( (string) $scope ) );
		return is_array( $scopes ) && in_array( self::SCOPE, $scopes, true );
	}

	/**
	 * Throw unless the connection can be used.
	 *
	 * @return void
	 * @throws DriveException NOT_CONNECTED or AUTH_REVOKED.
	 */
	private function assertUsable() {
		$status = $this->connection->status();
		switch ( $status['state'] ) {
			case Connection::STATE_CONNECTED:
				return;
			case Connection::STATE_RECONNECT:
				throw new DriveException( DriveException::AUTH_REVOKED, $status['error'] );
			case Connection::STATE_OTHER_SITE:
				throw new DriveException( DriveException::NOT_CONNECTED, $status['error'] );
			case Connection::STATE_NOT_CONFIGURED:
				throw new DriveException( DriveException::NOT_CONNECTED, 'Google Drive is not set up: enter the OAuth client ID and client secret, then connect.' );
			default:
				throw new DriveException( DriveException::NOT_CONNECTED, 'Google Drive is not connected.' );
		}
	}

	/**
	 * Throw unless client credentials are available.
	 *
	 * @return void
	 * @throws DriveException NOT_CONNECTED.
	 */
	private function requireCredentials() {
		if ( ! $this->connection->hasCredentials() ) {
			throw new DriveException( DriveException::NOT_CONNECTED, 'Enter the Google OAuth client ID and client secret first.' );
		}
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array       $params       Form parameters.
	 * @param string|null $used_refresh The refresh token of a refresh (errors then change the connection state); null for a code exchange.
	 * @return array Token response with a non-empty 'access_token'.
	 * @throws DriveException On any failure.
	 */
	private function tokenRequest(
		#[\SensitiveParameter]
		array $params,
		#[\SensitiveParameter]
		$used_refresh
	) {
		$response = $this->http->request(
			'POST',
			$this->endpoints['token'],
			array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			http_build_query( $params, '', '&' ),
			array( 'timeout' => self::TIMEOUT )
		);

		if ( 200 === $response->status ) {
			$json = $response->json();
			if ( is_array( $json ) && isset( $json['access_token'] ) && is_string( $json['access_token'] ) && '' !== $json['access_token'] ) {
				$this->connection->noteSecret( $json['access_token'] );
				if ( isset( $json['refresh_token'] ) && is_string( $json['refresh_token'] ) ) {
					$this->connection->noteSecret( $json['refresh_token'] );
				}
				return $json;
			}
			throw new DriveException( DriveException::SERVER, 'Google\'s sign-in service sent an answer without an access token. Try again later.', 200, 'no_access_token' );
		}

		throw $this->tokenError( $response, $used_refresh );
	}

	/**
	 * Map a failed token endpoint response, updating the connection state
	 * when the grant or the client is unusable.
	 *
	 * @param HttpResponse $response     Response.
	 * @param string|null  $used_refresh The refresh token of a refresh; null for a code exchange.
	 * @return DriveException
	 */
	private function tokenError(
		HttpResponse $response,
		#[\SensitiveParameter]
		$used_refresh
	) {
		$secrets    = $this->connection->secrets();
		$status     = (int) $response->status;
		$refreshing = null !== $used_refresh;

		if ( 0 === $status ) {
			$detail = DriveException::safeText( $response->error, $secrets );
			$host   = (string) parse_url( $this->endpoints['token'], PHP_URL_HOST );
			return new DriveException(
				DriveException::SERVER,
				sprintf( 'Could not connect to Google\'s sign-in service (%s)%s', $host, '' !== $detail ? ': ' . $detail : '.' ),
				0,
				'network'
			);
		}

		list( $reason, $detail ) = DriveException::describe( $response, $secrets );
		$label                   = '' !== $reason ? $reason . ( '' !== $detail ? ': ' . $detail : '' ) : 'HTTP ' . $status . ( '' !== $detail ? ': ' . $detail : '' );

		if ( $status >= 500 || 408 === $status ) {
			return new DriveException(
				DriveException::SERVER,
				sprintf( 'Google\'s sign-in service is temporarily unavailable (HTTP %d). Try again later.', $status ),
				$status,
				$reason
			);
		}
		if ( 429 === $status ) {
			return new DriveException(
				DriveException::RATE_LIMITED,
				sprintf( 'Google\'s sign-in service is limiting requests (%s). Try again later.', $label ),
				$status,
				$reason
			);
		}

		if ( 'invalid_grant' === $reason ) {
			if ( ! $refreshing ) {
				return new DriveException(
					DriveException::BAD_REQUEST,
					sprintf( 'Google did not accept the authorization code (%s). It may have expired or been used already. Click Connect again.', $label ),
					$status,
					$reason
				);
			}
			$message = sprintf(
				'Google Drive access was revoked or has expired (%s). Reconnect Google Drive. If the Google Cloud app is still in "Testing" mode, publish it ("In production"): Google ends the authorizations of apps in testing after 7 days.',
				$label
			);
			if ( ! $this->connection->markReconnect( $message, $used_refresh ) ) {
				return self::grantReplaced( $status );
			}
			return new DriveException( DriveException::AUTH_REVOKED, $message, $status, $reason );
		}

		if ( in_array( $reason, self::CLIENT_ERRORS, true ) ) {
			// Also during a code exchange: the stored secret is what refreshes would use.
			$message = sprintf(
				'Google rejected the OAuth client (%s). Check the client ID and client secret in the Google Drive settings, then reconnect.',
				$label
			);
			if ( ! $this->connection->markReconnect( $message, $used_refresh ) ) {
				return self::grantReplaced( $status );
			}
			return new DriveException( DriveException::CLIENT_INVALID, $message, $status, $reason );
		}

		$hint = 'redirect_uri_mismatch' === $reason
			? ' The authorized redirect URI of the OAuth client must match this site\'s redirect URI exactly.'
			: '';
		return new DriveException(
			DriveException::BAD_REQUEST,
			sprintf( 'Google rejected the sign-in request (%s).%s', $label, $hint ),
			$status,
			$reason
		);
	}

	/**
	 * The error for a refresh that Google rejected after another request had
	 * already replaced or removed the grant it used: the current connection is
	 * not affected, so this is temporary (and accessToken() retries once).
	 *
	 * @param int $status HTTP status of the rejection.
	 * @return DriveException
	 */
	private static function grantReplaced( $status ) {
		return new DriveException(
			DriveException::SERVER,
			'The Google Drive authorization changed while it was being renewed. Try again.',
			$status,
			self::GRANT_REPLACED
		);
	}

	/**
	 * Access token lifetime from a token response.
	 *
	 * @param array $payload Token response.
	 * @return int Seconds.
	 */
	private static function lifetime( array $payload ) {
		return isset( $payload['expires_in'] ) && is_numeric( $payload['expires_in'] ) ? max( 0, (int) $payload['expires_in'] ) : 3600;
	}
}
