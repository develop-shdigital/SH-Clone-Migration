<?php
/**
 * Google Drive transport, OAuth, client and connection tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Backup\ConfigStore;
use SHCM\Filesystem\Storage;
use SHCM\Remote\GoogleDrive\Client;
use SHCM\Remote\GoogleDrive\Connection;
use SHCM\Remote\GoogleDrive\DriveException;
use SHCM\Remote\GoogleDrive\Endpoints;
use SHCM\Remote\GoogleDrive\OAuth;
use SHCM\Remote\Http\HttpResponse;
use SHCM\Remote\Http\HttpTransport;
use SHCM\Security\SecretBox;

/**
 * Transport that answers from a script and records every request.
 */
class GoogleDriveFakeTransport implements HttpTransport {

	/**
	 * Scripted responses, consumed in order.
	 *
	 * @var HttpResponse[]
	 */
	public $responses = array();

	/**
	 * Recorded requests: method, url, headers, body, options.
	 *
	 * @var array[]
	 */
	public $requests = array();

	/**
	 * Called with ( method, url ) before each answer, to let "another request"
	 * change the stored state while this one is in flight.
	 *
	 * @var callable|null
	 */
	public $during = null;

	/**
	 * Queue a response.
	 *
	 * @param int    $status  Status.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param string $error   Transport error.
	 * @return self
	 */
	public function queue( $status, array $headers = array(), $body = '', $error = '' ) {
		$this->responses[] = new HttpResponse( $status, $headers, $body, $error );
		return $this;
	}

	/**
	 * Queue a JSON response.
	 *
	 * @param int   $status  Status.
	 * @param array $data    Data.
	 * @param array $headers Extra headers.
	 * @return self
	 */
	public function json( $status, array $data, array $headers = array() ) {
		return $this->queue( $status, array_merge( array( 'Content-Type' => 'application/json; charset=UTF-8' ), $headers ), json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Send a request.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param array  $options Options.
	 * @return HttpResponse
	 * @throws \LogicException When nothing is scripted.
	 */
	public function request( $method, $url, array $headers = array(), $body = '', array $options = array() ) {
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
			'options' => $options,
		);
		if ( empty( $this->responses ) ) {
			throw new \LogicException( 'Unexpected request: ' . $method . ' ' . $url );
		}
		if ( is_callable( $this->during ) ) {
			call_user_func( $this->during, $method, $url );
		}
		return array_shift( $this->responses );
	}

	/**
	 * The last request.
	 *
	 * @return array
	 */
	public function last() {
		return end( $this->requests );
	}

	/**
	 * Query parameters of a recorded request.
	 *
	 * @param int $index Request index.
	 * @return array
	 */
	public function query( $index ) {
		parse_str( (string) parse_url( $this->requests[ $index ]['url'], PHP_URL_QUERY ), $query );
		return $query;
	}

	/**
	 * Form body of a recorded request.
	 *
	 * @param int $index Request index.
	 * @return array
	 */
	public function form( $index ) {
		parse_str( (string) $this->requests[ $index ]['body'], $form );
		return $form;
	}
}

/**
 * Tests for the Google Drive layer.
 */
class GoogleDriveTest extends TestCase {

	const CLIENT_ID     = 'client-123' . '.apps.googleusercontent.com'; // Split: fake, but scanner-shaped.
	const CLIENT_SECRET = 'CS-secret-client-5d6e7f';
	const REFRESH       = 'RT-secret-refresh-9f8e7d';
	const ACCESS        = 'AT-secret-access-1a2b3c';
	const CODE          = 'CODE-secret-auth-0a0b0c';
	const SESSION       = 'https://sess.example.test/upload/drive/v3/files?uploadType=resumable&upload_id=SESSION-secret-77aa';
	const FINGERPRINT   = 'fingerprint-of-this-site';
	const API           = 'https://www.googleapis.com/drive/v3';

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Config store.
	 *
	 * @var ConfigStore
	 */
	protected $store;

	/**
	 * Secret box.
	 *
	 * @var SecretBox
	 */
	protected $box;

	/**
	 * Fake clock.
	 *
	 * @var int
	 */
	protected $now = 1790000000;

	/**
	 * Transport.
	 *
	 * @var GoogleDriveFakeTransport
	 */
	protected $http;

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	protected $connection;

	/**
	 * OAuth.
	 *
	 * @var OAuth
	 */
	protected $oauth;

	/**
	 * Client.
	 *
	 * @var Client
	 */
	protected $client;

	/**
	 * Build the objects on a scratch config directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir        = sys_get_temp_dir() . '/shcm-gdrive-' . bin2hex( random_bytes( 6 ) );
		$this->store      = new ConfigStore( $this->dir );
		$this->box        = new SecretBox( str_repeat( 'k', 40 ) );
		$this->http       = new GoogleDriveFakeTransport();
		$this->connection = $this->makeConnection( self::FINGERPRINT );
		$this->oauth      = new OAuth( $this->connection, $this->http, Endpoints::defaults() );
		$this->client     = new Client( $this->oauth, $this->http, Endpoints::defaults() );
	}

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Storage::rmdirRecursive( $this->dir );
		parent::tearDown();
	}

	/**
	 * A connection on the shared store with the fake clock.
	 *
	 * @param string         $fingerprint Fingerprint.
	 * @param SecretBox|null $box         Box (default: the test box).
	 * @return Connection
	 */
	private function makeConnection( $fingerprint, $box = null ) {
		$test = $this;
		return new Connection(
			$this->store,
			null === $box ? $this->box : $box,
			$fingerprint,
			function () use ( $test ) {
				return $test->now;
			}
		);
	}

	/**
	 * Configure credentials and store tokens as a finished connect would.
	 *
	 * @return void
	 */
	private function connect() {
		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$this->connection->storeTokens( self::REFRESH, self::ACCESS, 3600, OAuth::SCOPE );
	}

	/**
	 * Run a callable that must throw a DriveException.
	 *
	 * @param callable $callable Code.
	 * @return DriveException
	 */
	private function catchDrive( callable $callable ) {
		try {
			$callable();
		} catch ( DriveException $e ) {
			return $e;
		}
		$this->fail( 'A DriveException was expected.' );
	}

	/**
	 * A Drive API error body.
	 *
	 * @param int    $code    Status.
	 * @param string $reason  Reason.
	 * @param string $message Message.
	 * @return array
	 */
	private static function apiError( $code, $reason, $message ) {
		return array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
				'errors'  => array(
					array(
						'message' => $message,
						'domain'  => 'global',
						'reason'  => $reason,
					),
				),
			),
		);
	}

	// ---------------------------------------------------------------------
	// HTTP response.
	// ---------------------------------------------------------------------

	public function testHttpResponseNormalisesHeaders() {
		$response = new HttpResponse(
			308,
			array(
				'Content-Type' => 'text/plain',
				'X-Multi'      => array( 'first', 'last' ),
				'RANGE'        => 'bytes=0-1',
				'range'        => 'bytes=0-262143',
				'Set-Cookie: a=b',
			),
			'Not Found'
		);

		$this->assertSame( 'text/plain', $response->header( 'content-type' ) );
		$this->assertSame( 'text/plain', $response->header( 'CONTENT-TYPE' ) );
		$this->assertSame( 'last', $response->header( 'x-multi' ) );
		$this->assertSame( 'bytes=0-262143', $response->header( 'Range' ) );
		$this->assertSame( 'a=b', $response->header( 'set-cookie' ) );
		$this->assertNull( $response->header( 'location' ) );
		$this->assertSame( array( 'content-type', 'x-multi', 'range', 'set-cookie' ), array_keys( $response->headers ) );
		$this->assertNull( $response->json() );

		$json = new HttpResponse( 200, array(), '{"a":1}' );
		$this->assertSame( array( 'a' => 1 ), $json->json() );
		$this->assertNull( ( new HttpResponse( 200, array(), '"scalar"' ) )->json() );
		$this->assertSame( 0, HttpResponse::failure( 'boom' )->status );
		$this->assertSame( 'boom', HttpResponse::failure( 'boom' )->error );
	}

	public function testEndpointDefaultsAndNormalisation() {
		$defaults = Endpoints::defaults();
		$this->assertSame( 'https://accounts.google.com/o/oauth2/v2/auth', $defaults['auth'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $defaults['token'] );
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $defaults['revoke'] );
		$this->assertSame( self::API, $defaults['api'] );
		$this->assertSame( 'https://www.googleapis.com/upload/drive/v3', $defaults['upload'] );

		// No constants in the unit suite: resolve() is the defaults (the shim filter passes through).
		$this->assertSame( $defaults, Endpoints::resolve() );

		$partial = Endpoints::normalize(
			array(
				'api'   => 'http://127.0.0.1:8091/drive/v3/',
				'token' => 'javascript:alert(1)',
			)
		);
		$this->assertSame( 'http://127.0.0.1:8091/drive/v3', $partial['api'] );
		$this->assertSame( $defaults['token'], $partial['token'] );
	}

	// ---------------------------------------------------------------------
	// OAuth.
	// ---------------------------------------------------------------------

	public function testAuthorizationUrlParameters() {
		$e = $this->catchDrive(
			function () {
				$this->oauth->authorizationUrl( 'https://example.test/wp-admin/admin-post.php', 'state123' );
			}
		);
		$this->assertSame( DriveException::NOT_CONNECTED, $e->kind() );

		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$url = $this->oauth->authorizationUrl( 'https://example.test/wp-admin/admin-post.php', 'state value/1', 'owner@example.test' );

		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array(
				'client_id'              => self::CLIENT_ID,
				'redirect_uri'           => 'https://example.test/wp-admin/admin-post.php',
				'response_type'          => 'code',
				'scope'                  => 'https://www.googleapis.com/auth/drive.file',
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'false',
				'state'                  => 'state value/1',
				'login_hint'             => 'owner@example.test',
			),
			$query
		);
		$this->assertStringNotContainsString( self::CLIENT_SECRET, $url );
		$this->assertStringNotContainsString( 'login_hint', $this->oauth->authorizationUrl( 'https://example.test/cb', 's' ) );
		$this->assertCount( 0, $this->http->requests );
	}

	public function testExchangeCodeStoresTokens() {
		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$this->http->json(
			200,
			array(
				'access_token'  => self::ACCESS,
				'expires_in'    => 3599,
				'refresh_token' => self::REFRESH,
				'scope'         => OAuth::SCOPE,
				'token_type'    => 'Bearer',
			)
		);

		$this->assertTrue( $this->oauth->exchangeCode( self::CODE, 'https://example.test/cb' ) );

		$request = $this->http->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $request['url'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $request['headers']['Content-Type'] );
		$this->assertSame(
			array(
				'code'          => self::CODE,
				'client_id'     => self::CLIENT_ID,
				'client_secret' => self::CLIENT_SECRET,
				'redirect_uri'  => 'https://example.test/cb',
				'grant_type'    => 'authorization_code',
			),
			$this->http->form( 0 )
		);

		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_CONNECTED, $status['state'] );
		$this->assertSame( $this->now, $status['connected_at'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $status['site_id'] );
		$this->assertTrue( $this->connection->isUsable() );
		$this->assertSame( self::REFRESH, $this->connection->refreshToken() );
		$this->assertSame( self::ACCESS, $this->connection->accessToken() );

		// Sealed at rest.
		$raw = file_get_contents( $this->store->path( 'gdrive' ) );
		foreach ( array( self::REFRESH, self::ACCESS, self::CLIENT_SECRET, self::CODE ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $raw );
		}
		$data = $this->store->read( 'gdrive' );
		$this->assertSame( self::FINGERPRINT, $data['fingerprint'] );
		$this->assertSame( 'connected', $data['status'] );
		$this->assertSame( $this->now + 3599, $data['access_expires'] );
		$this->assertSame( OAuth::SCOPE, $data['scope'] );
	}

	public function testExchangeCodeWithoutRefreshToken() {
		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$this->http->json(
			200,
			array(
				'access_token' => self::ACCESS,
				'expires_in'   => 3599,
				'scope'        => OAuth::SCOPE,
			)
		);

		$e = $this->catchDrive(
			function () {
				$this->oauth->exchangeCode( self::CODE, 'https://example.test/cb' );
			}
		);
		$this->assertSame( DriveException::BAD_REQUEST, $e->kind() );
		$this->assertStringContainsString( 'myaccount.google.com/permissions', $e->getMessage() );
		$this->assertStringContainsString( 'connect again', $e->getMessage() );
		$this->assertSame( Connection::STATE_NOT_CONNECTED, $this->connection->status()['state'] );
		$this->assertNull( $this->connection->refreshToken() );
	}

	public function testExchangeCodeWithoutDriveScope() {
		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$this->http->json(
			200,
			array(
				'access_token'  => self::ACCESS,
				'expires_in'    => 3599,
				'refresh_token' => self::REFRESH,
				'scope'         => 'openid https://www.googleapis.com/auth/userinfo.email',
			)
		);

		$e = $this->catchDrive(
			function () {
				$this->oauth->exchangeCode( self::CODE, 'https://example.test/cb' );
			}
		);
		$this->assertSame( DriveException::FORBIDDEN, $e->kind() );
		$this->assertStringContainsString( 'access to Google Drive was not granted', $e->getMessage() );
		$this->assertSame( Connection::STATE_NOT_CONNECTED, $this->connection->status()['state'] );
	}

	public function testExchangeCodeWithUsedCodeDoesNotMarkReconnect() {
		$this->connect();
		$this->http->json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Malformed auth code.',
			)
		);
		$e = $this->catchDrive(
			function () {
				$this->oauth->exchangeCode( self::CODE, 'https://example.test/cb' );
			}
		);
		$this->assertSame( DriveException::BAD_REQUEST, $e->kind() );
		$this->assertStringContainsString( 'Malformed auth code.', $e->getMessage() );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->status()['state'] );
	}

	public function testAccessTokenIsCachedAndRefreshedWhenExpiring() {
		$this->connect();

		// Valid for another hour: no request.
		$this->assertSame( self::ACCESS, $this->oauth->accessToken() );
		$this->assertCount( 0, $this->http->requests );

		// 59 minutes and 30 seconds later it expires in 30 s: refresh.
		$this->now += 3570;
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-second-token-xyz',
				'expires_in'   => 3599,
				'scope'        => OAuth::SCOPE,
				'token_type'   => 'Bearer',
			)
		);
		$this->assertSame( 'AT-second-token-xyz', $this->oauth->accessToken() );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame(
			array(
				'client_id'     => self::CLIENT_ID,
				'client_secret' => self::CLIENT_SECRET,
				'grant_type'    => 'refresh_token',
				'refresh_token' => self::REFRESH,
			),
			$this->http->form( 0 )
		);

		// Now cached again, the refresh token unchanged.
		$this->assertSame( 'AT-second-token-xyz', $this->oauth->accessToken() );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( self::REFRESH, $this->connection->refreshToken() );

		// A forced refresh that returns a new refresh token replaces the stored one.
		$this->http->json(
			200,
			array(
				'access_token'  => 'AT-third-token-xyz',
				'expires_in'    => 3599,
				'refresh_token' => 'RT-rotated-refresh-token',
			)
		);
		$this->assertSame( 'AT-third-token-xyz', $this->oauth->accessToken( true ) );
		$this->assertSame( 'RT-rotated-refresh-token', $this->connection->refreshToken() );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->status()['state'] );
	}

	public function testInvalidGrantMarksReconnect() {
		$this->connect();
		$this->now += 7200;
		$this->http->json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Token has been expired or revoked.',
			)
		);

		$e = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::AUTH_REVOKED, $e->kind() );
		$this->assertSame( 'invalid_grant', $e->reason() );
		$this->assertFalse( $e->isRetryable() );
		$this->assertStringContainsString( 'Reconnect Google Drive', $e->getMessage() );

		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_RECONNECT, $status['state'] );
		$this->assertSame( $e->getMessage(), $status['error'] );
		$this->assertFalse( $this->connection->isUsable() );

		// No further requests while reconnect is pending.
		$again = $this->catchDrive(
			function () {
				$this->client->about();
			}
		);
		$this->assertSame( DriveException::AUTH_REVOKED, $again->kind() );
		$this->assertCount( 1, $this->http->requests );

		// A new connect clears the state.
		$this->connection->storeTokens( 'RT-new-refresh-token', 'AT-new-access-token', 3600, OAuth::SCOPE );
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_CONNECTED, $status['state'] );
		$this->assertSame( '', $status['error'] );
	}

	public function testInvalidClientNeedsNewCredentials() {
		$this->connect();
		$this->now += 7200;
		$this->http->json(
			401,
			array(
				'error'             => 'invalid_client',
				'error_description' => 'The OAuth client was not found.',
			)
		);

		$e = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::CLIENT_INVALID, $e->kind() );
		$this->assertSame( 401, $e->httpStatus() );
		$this->assertStringContainsString( 'client ID and client secret', $e->getMessage() );

		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_RECONNECT, $status['state'] );
		$this->assertStringContainsString( 'client ID and client secret', $status['error'] );

		foreach ( array( 'unauthorized_client', 'deleted_client' ) as $error ) {
			$this->connection->storeTokens( self::REFRESH, '', 0, OAuth::SCOPE );
			$this->http->json( 400, array( 'error' => $error ) );
			$e = $this->catchDrive(
				function () {
					$this->oauth->accessToken();
				}
			);
			$this->assertSame( DriveException::CLIENT_INVALID, $e->kind(), $error );
		}
	}

	public function testTemporaryTokenFailuresKeepTheConnection() {
		$this->connect();
		$this->now += 7200;
		$this->http->queue( 503, array( 'Content-Type' => 'text/html' ), '<html>Service Unavailable</html>' );
		$this->http->queue( 0, array(), '', 'cURL error 28: Operation timed out after 30001 milliseconds' );
		$this->http->json( 429, array( 'error' => 'rate_limit_exceeded' ) );

		$server = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::SERVER, $server->kind() );
		$this->assertTrue( $server->isRetryable() );
		$this->assertStringNotContainsString( '<html>', $server->getMessage() );

		$network = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::SERVER, $network->kind() );
		$this->assertStringContainsString( 'Operation timed out', $network->getMessage() );

		$limited = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::RATE_LIMITED, $limited->kind() );

		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->status()['state'] );
		$this->assertSame( self::REFRESH, $this->connection->refreshToken() );
	}

	public function testRevoke() {
		$this->connect();
		$this->http->queue( 200, array(), '' );
		$this->assertTrue( $this->oauth->revoke() );
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'token' => self::REFRESH ), $this->http->form( 0 ) );

		$this->http->json( 400, array( 'error' => 'invalid_token' ) );
		$this->assertTrue( $this->oauth->revoke() );

		$this->http->queue( 0, array(), '', 'network down' );
		$this->assertFalse( $this->oauth->revoke() );

		// Never throws, even when the transport does.
		$this->assertFalse( $this->oauth->revoke() );

		// A copy of the site must not revoke the original's grant.
		$copy  = $this->makeConnection( 'fingerprint-of-a-copy' );
		$oauth = new OAuth( $copy, $this->http, Endpoints::defaults() );
		$count = count( $this->http->requests );
		$this->assertFalse( $oauth->revoke() );
		$this->assertCount( $count, $this->http->requests );
	}

	public function testRefreshDoesNotOverwriteAGrantStoredMeanwhile() {
		$this->connect();
		$this->now += 7200;
		// The admin's browser request, on the same config store.
		$admin = $this->makeConnection( self::FINGERPRINT );

		// While the background job refreshes with the old grant, the admin connects another account.
		$this->http->during = function () use ( $admin ) {
			$admin->storeTokens( 'RT-account-B', 'AT-account-B', 3600, OAuth::SCOPE );
		};
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-from-account-A',
				'expires_in'   => 3599,
			)
		);

		// The old grant's token is dropped; the job continues with the new grant.
		$this->assertSame( 'AT-account-B', $this->oauth->accessToken() );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'AT-account-B', $admin->accessToken() );
		$this->assertSame( 'RT-account-B', $admin->refreshToken() );
		$this->assertSame( Connection::STATE_CONNECTED, $admin->status()['state'] );
	}

	public function testRejectedRefreshDoesNotBreakAGrantStoredMeanwhile() {
		foreach ( array( 'invalid_grant', 'invalid_client' ) as $error ) {
			$this->connect();
			$this->now  += 7200;
			$admin       = $this->makeConnection( self::FINGERPRINT );
			$new_refresh = 'RT-new-grant-' . $error;

			// Google rejects the old grant after the admin already connected again.
			$this->http->during = function () use ( $admin, $new_refresh ) {
				$admin->storeTokens( $new_refresh, 'AT-new-grant', 3600, OAuth::SCOPE );
			};
			$this->http->json(
				400,
				array(
					'error'             => $error,
					'error_description' => 'Token has been expired or revoked.',
				)
			);

			$this->assertSame( 'AT-new-grant', $this->oauth->accessToken(), $error );
			$this->http->during = null;
			$status             = $admin->status();
			$this->assertSame( Connection::STATE_CONNECTED, $status['state'], $error );
			$this->assertSame( '', $status['error'], $error );
			$this->assertSame( 'AT-new-grant', $admin->accessToken(), $error );
			$this->assertSame( $new_refresh, $admin->refreshToken(), $error );
		}
	}

	public function testRefreshRacingADisconnectOrAnotherRefresh() {
		// Disconnected while the refresh was in flight: nothing is written back.
		$this->connect();
		$this->now += 7200;
		$admin      = $this->makeConnection( self::FINGERPRINT );
		$this->http->during = function () use ( $admin ) {
			$admin->disconnect();
		};
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-after-disconnect',
				'expires_in'   => 3599,
			)
		);
		$e = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::NOT_CONNECTED, $e->kind() );
		$data = $this->store->read( 'gdrive' );
		$this->assertArrayNotHasKey( 'access_token', $data );
		$this->assertArrayNotHasKey( 'refresh_token', $data );
		$this->assertSame( Connection::STATE_NOT_CONNECTED, $admin->status()['state'] );

		// The grant changes during both attempts: the second token is returned, not cached.
		$this->connect();
		$this->now += 7200;
		$round              = 0;
		$this->http->during = function () use ( $admin, &$round ) {
			++$round;
			$admin->storeTokens( 'RT-round-' . $round, '', 0, OAuth::SCOPE );
		};
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-round-1',
				'expires_in'   => 3599,
			)
		);
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-round-2',
				'expires_in'   => 3599,
			)
		);
		$this->assertSame( 'AT-round-2', $this->oauth->accessToken() );
		$this->assertSame( array( 'grant_type' => 'refresh_token', 'refresh_token' => 'RT-round-1' ), array_intersect_key( $this->http->form( 2 ), array_flip( array( 'grant_type', 'refresh_token' ) ) ) );
		$this->assertNull( $admin->accessToken() );
		$this->assertSame( 'RT-round-2', $admin->refreshToken() );

		// Rejected twice for replaced grants: a retryable error, and the connection stays usable.
		$this->http->json( 400, array( 'error' => 'invalid_grant' ) );
		$this->http->json( 400, array( 'error' => 'invalid_grant' ) );
		$e = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::SERVER, $e->kind() );
		$this->assertSame( OAuth::GRANT_REPLACED, $e->reason() );
		$this->assertTrue( $e->isRetryable() );
		$this->assertSame( Connection::STATE_CONNECTED, $admin->status()['state'] );
		$this->assertSame( array(), $this->http->responses );
	}

	public function testConnectionCompareAndSet() {
		$this->connect();

		// A refresh made with another refresh token than the stored one writes nothing.
		$this->assertFalse( $this->connection->storeAccessToken( 'AT-stale-grant', 3600, '', 'RT-some-older-grant' ) );
		$this->assertSame( self::ACCESS, $this->connection->accessToken() );
		$this->assertFalse( $this->connection->storeAccessToken( 'AT-stale-grant', 3600, 'RT-rotated-stale', 'RT-some-older-grant' ) );
		$this->assertSame( self::REFRESH, $this->connection->refreshToken() );
		$this->assertFalse( $this->connection->markReconnect( 'Revoked.', 'RT-some-older-grant' ) );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->status()['state'] );

		// With the stored one it does.
		$this->assertTrue( $this->connection->storeAccessToken( 'AT-current-grant', 3600, 'RT-rotated-current', self::REFRESH ) );
		$this->assertSame( 'AT-current-grant', $this->connection->accessToken() );
		$this->assertSame( 'RT-rotated-current', $this->connection->refreshToken() );
		$this->assertFalse( $this->connection->markReconnect( 'Revoked.', self::REFRESH ) );
		$this->assertTrue( $this->connection->markReconnect( 'Revoked.', 'RT-rotated-current' ) );
		$this->assertSame( Connection::STATE_RECONNECT, $this->connection->status()['state'] );

		// Without an expected token (the documented calls) the write is unconditional.
		$this->connection->storeTokens( self::REFRESH, self::ACCESS, 3600, OAuth::SCOPE );
		$this->assertTrue( $this->connection->storeAccessToken( 'AT-unconditional', 3600 ) );
		$this->assertSame( 'AT-unconditional', $this->connection->accessToken() );
		$this->assertTrue( $this->connection->markReconnect( 'Revoked.' ) );
		$this->assertSame( Connection::STATE_RECONNECT, $this->connection->status()['state'] );

		// Nothing stored to compare with: no write.
		$this->connection->disconnect();
		$this->assertFalse( $this->connection->storeAccessToken( 'AT-orphan', 3600, '', self::REFRESH ) );
		$this->assertArrayNotHasKey( 'access_token', $this->store->read( 'gdrive' ) );
	}

	// ---------------------------------------------------------------------
	// Client.
	// ---------------------------------------------------------------------

	public function testApiCallRefreshesOnceOn401AndRetries() {
		$this->connect();
		$this->http->json( 401, self::apiError( 401, 'authError', 'Invalid Credentials' ) );
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-after-401',
				'expires_in'   => 3599,
			)
		);
		$this->http->json(
			200,
			array(
				'user'         => array(
					'displayName'  => 'Site Owner',
					'emailAddress' => 'owner@example.test',
				),
				'storageQuota' => array( 'usage' => '1024' ),
			)
		);

		$about = $this->client->about();
		$this->assertSame( 'owner@example.test', $about['email'] );
		$this->assertCount( 3, $this->http->requests );
		$this->assertSame( 'Bearer ' . self::ACCESS, $this->http->requests[0]['headers']['Authorization'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $this->http->requests[1]['url'] );
		$this->assertSame( 'Bearer AT-after-401', $this->http->requests[2]['headers']['Authorization'] );
		$this->assertSame( $this->http->requests[0]['url'], $this->http->requests[2]['url'] );

		// A second 401 after the refresh is not retried again.
		$this->http->json( 401, self::apiError( 401, 'authError', 'Invalid Credentials' ) );
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-after-second-401',
				'expires_in'   => 3599,
			)
		);
		$this->http->json( 401, self::apiError( 401, 'authError', 'Invalid Credentials' ) );
		$e = $this->catchDrive(
			function () {
				$this->client->about();
			}
		);
		$this->assertSame( DriveException::UNAUTHORIZED, $e->kind() );
		$this->assertCount( 6, $this->http->requests );
		$this->assertSame( array(), $this->http->responses );
	}

	public function testAbout() {
		$this->connect();
		$this->http->json(
			200,
			array(
				'user'          => array(
					'displayName'  => 'Site Owner',
					'emailAddress' => 'owner@example.test',
				),
				'storageQuota'  => array(
					'limit' => '16106127360',
					'usage' => '5368709120',
				),
				'maxUploadSize' => '5242880000000',
			)
		);

		$this->assertSame(
			array(
				'email'      => 'owner@example.test',
				'name'       => 'Site Owner',
				'limit'      => 16106127360,
				'usage'      => 5368709120,
				'max_upload' => 5242880000000,
			),
			$this->client->about()
		);
		$request = $this->http->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertStringStartsWith( self::API . '/about?', $request['url'] );
		$this->assertSame( array( 'fields' => 'user(displayName,emailAddress),storageQuota(limit,usage),maxUploadSize' ), $this->http->query( 0 ) );
		$this->assertEquals( 30.0, $request['options']['timeout'] );

		// Unlimited storage has no limit.
		$this->http->json( 200, array( 'storageQuota' => array( 'usage' => '0' ) ) );
		$about = $this->client->about();
		$this->assertNull( $about['limit'] );
		$this->assertNull( $about['max_upload'] );
		$this->assertSame( '', $about['email'] );
	}

	public function testEnsureFolderUsesTheKnownFolder() {
		$this->connect();
		$this->http->json(
			200,
			array(
				'id'       => 'folder-known',
				'name'     => 'Backups',
				'mimeType' => Client::FOLDER_MIME,
				'trashed'  => false,
			)
		);

		$this->assertSame(
			array(
				'id'   => 'folder-known',
				'name' => 'Backups',
			),
			$this->client->ensureFolder( 'abcdef0123456789', 'SH Clone Migration Backups (example.test)', 'folder-known' )
		);
		$this->assertCount( 1, $this->http->requests );
		$this->assertStringStartsWith( self::API . '/files/folder-known?', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'fields' => 'id,name,mimeType,trashed' ), $this->http->query( 0 ) );
	}

	public function testEnsureFolderSearchesWhenTheKnownFolderIsTrashed() {
		$this->connect();
		$this->http->json(
			200,
			array(
				'id'       => 'folder-known',
				'name'     => 'Backups',
				'mimeType' => Client::FOLDER_MIME,
				'trashed'  => true,
			)
		);
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'   => 'folder-found',
						'name' => 'Older backups',
					),
				),
			)
		);

		$folder = $this->client->ensureFolder( 'abcdef0123456789', 'New name', 'folder-known' );
		$this->assertSame( 'folder-found', $folder['id'] );
		$this->assertSame( 'Older backups', $folder['name'] );

		$query = $this->http->query( 1 );
		$this->assertSame( 'GET', $this->http->requests[1]['method'] );
		$this->assertStringStartsWith( self::API . '/files?', $this->http->requests[1]['url'] );
		$this->assertSame(
			"mimeType = 'application/vnd.google-apps.folder' and trashed = false and appProperties has { key='shcm_role' and value='backup_root' } and appProperties has { key='shcm_site' and value='abcdef0123456789' }",
			$query['q']
		);
		$this->assertSame( 'files(id,name,createdTime)', $query['fields'] );
		$this->assertSame( 'drive', $query['spaces'] );
		$this->assertCount( 2, $this->http->requests );
	}

	public function testEnsureFolderCreatesWhenNothingIsFound() {
		$this->connect();
		$this->http->json( 404, self::apiError( 404, 'notFound', 'File not found: folder-gone.' ) );
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => 'folder-new',
				'name'        => 'SH Clone Migration Backups (example.test)',
				'createdTime' => '2026-09-28T13:34:35.777Z',
			)
		);
		// The search after the create finds only this folder.
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'          => 'folder-new',
						'name'        => 'SH Clone Migration Backups (example.test)',
						'createdTime' => '2026-09-28T13:34:35.777Z',
					),
				),
			)
		);

		$folder = $this->client->ensureFolder( 'abcdef0123456789', 'SH Clone Migration Backups (example.test)', 'folder-gone' );
		$this->assertSame( 'folder-new', $folder['id'] );
		$this->assertCount( 4, $this->http->requests );
		$this->assertSame( $this->http->query( 1 ), $this->http->query( 3 ) );

		$create = $this->http->requests[2];
		$this->assertSame( 'POST', $create['method'] );
		$this->assertSame( self::API . '/files?fields=id%2Cname%2CcreatedTime', $create['url'] );
		$this->assertSame( 'application/json; charset=UTF-8', $create['headers']['Content-Type'] );
		$this->assertSame(
			array(
				'name'          => 'SH Clone Migration Backups (example.test)',
				'mimeType'      => 'application/vnd.google-apps.folder',
				'appProperties' => array(
					'shcm_role' => 'backup_root',
					'shcm_site' => 'abcdef0123456789',
				),
			),
			json_decode( $create['body'], true )
		);

		// Without a known id the search comes first; server errors are not swallowed.
		$this->http->json( 500, self::apiError( 500, 'backendError', 'Backend Error' ) );
		$e = $this->catchDrive(
			function () {
				$this->client->ensureFolder( 'abcdef0123456789', 'Backups' );
			}
		);
		$this->assertSame( DriveException::SERVER, $e->kind() );
	}

	public function testEnsureFolderSearchesWhenTheKnownFolderIsForbidden() {
		$this->connect();
		// No longer visible to this OAuth client (e.g. created by another client ID).
		$this->http->json( 403, self::apiError( 403, 'insufficientFilePermissions', 'The user does not have sufficient permissions for file folder-known.' ) );
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'          => 'folder-found',
						'name'        => 'Backups',
						'createdTime' => '2026-01-01T00:00:00.000Z',
					),
				),
			)
		);
		$this->assertSame( 'folder-found', $this->client->ensureFolder( 'abcdef0123456789', 'Backups', 'folder-known' )['id'] );
		$this->assertCount( 2, $this->http->requests );
		$this->assertStringStartsWith( self::API . '/files?', $this->http->requests[1]['url'] );
		$this->assertStringContainsString( "value='backup_root'", $this->http->query( 1 )['q'] );

		// Forbidden with a 403 and nothing found: a new folder is created.
		$this->http->json( 403, self::apiError( 403, 'forbidden', 'Forbidden' ) );
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => 'folder-created',
				'name'        => 'Backups',
				'createdTime' => '2026-09-28T10:00:00.000Z',
			)
		);
		$this->http->json( 200, array( 'files' => array() ) );
		$this->assertSame( 'folder-created', $this->client->ensureFolder( 'abcdef0123456789', 'Backups', 'folder-known' )['id'] );
		$this->assertSame( 'POST', $this->http->requests[4]['method'] );

		// Anything else on the known folder is an error, not a reason to create another.
		$count = count( $this->http->requests );
		$this->http->json( 500, self::apiError( 500, 'backendError', 'Backend Error' ) );
		$e = $this->catchDrive(
			function () {
				$this->client->ensureFolder( 'abcdef0123456789', 'Backups', 'folder-known' );
			}
		);
		$this->assertSame( DriveException::SERVER, $e->kind() );
		$this->assertCount( $count + 1, $this->http->requests );
		$this->assertSame( array(), $this->http->responses );
	}

	public function testEnsureFolderPicksTheSameFolderInEveryProcess() {
		$this->connect();
		$pick = function ( array $files ) {
			$this->http->json( 200, array( 'files' => $files ) );
			return $this->client->ensureFolder( 'abcdef0123456789', 'Backups' )['id'];
		};

		// Oldest first, whatever order the search returns, with millisecond precision.
		$this->assertSame(
			'folder-older',
			$pick(
				array(
					array(
						'id'          => 'folder-a-newer',
						'createdTime' => '2026-09-28T13:34:35.777Z',
					),
					array(
						'id'          => 'folder-older',
						'createdTime' => '2026-09-28T13:34:35.1Z',
					),
				)
			)
		);
		// The same createdTime (two creates in one millisecond): the lowest id.
		$this->assertSame(
			'1fakeSX8WI1',
			$pick(
				array(
					array(
						'id'          => '1fakeoQHnL_',
						'createdTime' => '2026-09-28T13:34:35.777Z',
					),
					array(
						'id'          => '1fakeSX8WI1',
						'createdTime' => '2026-09-28T13:34:35.777Z',
					),
				)
			)
		);
		// A folder without a readable createdTime never beats one with a time.
		$this->assertSame(
			'folder-dated',
			$pick(
				array(
					array( 'id' => '0-undated' ),
					array(
						'id'          => 'folder-dated',
						'createdTime' => '2030-01-01T00:00:00Z',
					),
				)
			)
		);
		// Other time zone notations compare by the instant.
		$this->assertSame(
			'folder-utc-0930',
			$pick(
				array(
					array(
						'id'          => 'folder-a-1000',
						'createdTime' => '2026-09-28T12:00:00.000+02:00',
					),
					array(
						'id'          => 'folder-utc-0930',
						'createdTime' => '2026-09-28T09:30:00.000Z',
					),
				)
			)
		);
	}

	public function testEnsureFolderRaceLoserDeletesItsEmptyFolder() {
		$this->connect();
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => '1fakeoQHnL_',
				'name'        => 'Backups',
				'createdTime' => '2026-09-28T13:34:35.777Z',
			)
		);
		// Another process created its folder in the same millisecond; its id is lower.
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'          => '1fakeoQHnL_',
						'name'        => 'Backups',
						'createdTime' => '2026-09-28T13:34:35.777Z',
					),
					array(
						'id'          => '1fakeSX8WI1',
						'name'        => 'Backups (other)',
						'createdTime' => '2026-09-28T13:34:35.777Z',
					),
				),
			)
		);
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->queue( 204, array(), '' );

		$this->assertSame(
			array(
				'id'   => '1fakeSX8WI1',
				'name' => 'Backups (other)',
			),
			$this->client->ensureFolder( 'abcdef0123456789', 'Backups' )
		);
		$this->assertCount( 5, $this->http->requests );
		$children = $this->http->query( 3 );
		$this->assertSame( "'1fakeoQHnL_' in parents", $children['q'] );
		$this->assertSame( '1', $children['pageSize'] );
		$this->assertSame( 'DELETE', $this->http->requests[4]['method'] );
		$this->assertSame( self::API . '/files/1fakeoQHnL_', $this->http->requests[4]['url'] );

		// A losing folder that already holds a file (another request found it) is kept.
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => 'folder-late',
				'createdTime' => '2026-09-28T13:34:36.000Z',
			)
		);
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'          => 'folder-early',
						'name'        => 'Backups',
						'createdTime' => '2026-09-28T13:34:35.000Z',
					),
				),
			)
		);
		$this->http->json( 200, array( 'files' => array( array( 'id' => 'file-in-it' ) ) ) );
		$this->assertSame( 'folder-early', $this->client->ensureFolder( 'abcdef0123456789', 'Backups' )['id'] );
		$this->assertSame( 'GET', $this->http->last()['method'] );
		$this->assertSame( array(), $this->http->responses );
	}

	public function testEnsureFolderRaceWinnerKeepsItsFolder() {
		$this->connect();
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => 'folder-z-ours',
				'name'        => 'Backups',
				'createdTime' => '2026-09-28T13:34:35.100Z',
			)
		);
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'          => 'folder-a-theirs',
						'createdTime' => '2026-09-28T13:34:35.200Z',
					),
					array(
						'id'          => 'folder-z-ours',
						'createdTime' => '2026-09-28T13:34:35.100Z',
					),
				),
			)
		);
		$this->assertSame( 'folder-z-ours', $this->client->ensureFolder( 'abcdef0123456789', 'Backups' )['id'] );
		$this->assertCount( 3, $this->http->requests );

		// The search may not list a folder created a moment ago: ours still counts.
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => 'folder-fresh',
				'name'        => 'Backups',
				'createdTime' => '2026-09-28T13:40:00.000Z',
			)
		);
		$this->http->json( 200, array( 'files' => array() ) );
		$this->assertSame( 'folder-fresh', $this->client->ensureFolder( 'abcdef0123456789', 'Backups' )['id'] );
		$this->assertCount( 6, $this->http->requests );

		// A failing second search or cleanup never fails the call.
		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => 'folder-made',
				'name'        => 'Backups',
				'createdTime' => '2026-09-28T13:50:00.000Z',
			)
		);
		$this->http->json( 503, self::apiError( 503, 'backendError', 'Backend Error' ) );
		$this->assertSame( 'folder-made', $this->client->ensureFolder( 'abcdef0123456789', 'Backups' )['id'] );

		$this->http->json( 200, array( 'files' => array() ) );
		$this->http->json(
			200,
			array(
				'id'          => 'folder-loser',
				'createdTime' => '2026-09-28T14:00:00.000Z',
			)
		);
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'          => 'folder-winner',
						'name'        => 'Winner',
						'createdTime' => '2026-09-28T13:59:59.000Z',
					),
				),
			)
		);
		$this->http->json( 500, self::apiError( 500, 'backendError', 'Backend Error' ) );
		$this->assertSame( 'folder-winner', $this->client->ensureFolder( 'abcdef0123456789', 'Backups' )['id'] );
		$this->assertNotSame( 'DELETE', $this->http->last()['method'] );
		$this->assertSame( array(), $this->http->responses );
	}

	public function testListBackupsFollowsPagesAndEscapesTheQuery() {
		$this->connect();
		$this->http->json(
			200,
			array(
				'nextPageToken' => 'page-2-token',
				'files'         => array(
					array(
						'id'             => 'file-new',
						'name'           => 'example.test-backup-202609280300.wpress',
						'size'           => '73400320',
						'createdTime'    => '2026-09-28T03:00:12.345Z',
						'md5Checksum'    => 'D41D8CD98F00B204E9800998ECF8427E',
						'sha256Checksum' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
						'appProperties'  => array(
							'shcm_site' => "it's\\site",
							'shcm_kind' => 'backup',
						),
						'webViewLink'    => 'https://drive.google.com/file/d/file-new/view',
					),
				),
			)
		);
		$this->http->json(
			200,
			array(
				'files' => array(
					array(
						'id'          => 'file-old',
						'name'        => 'old.wpress',
						'size'        => '10',
						'createdTime' => '2026-09-27T03:00:00Z',
					),
				),
			)
		);

		$files = $this->client->listBackups( "folder'1", "it's\\site", 'backup' );

		$this->assertCount( 2, $files );
		$this->assertSame(
			array(
				'id'      => 'file-new',
				'name'    => 'example.test-backup-202609280300.wpress',
				'size'    => 73400320,
				'created' => gmmktime( 3, 0, 12, 9, 28, 2026 ),
				'md5'     => 'd41d8cd98f00b204e9800998ecf8427e',
				'sha256'  => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
				'app'     => array(
					'shcm_site' => "it's\\site",
					'shcm_kind' => 'backup',
				),
				'link'    => 'https://drive.google.com/file/d/file-new/view',
			),
			$files[0]
		);
		$this->assertSame( 10, $files[1]['size'] );
		$this->assertSame( '', $files[1]['md5'] );
		$this->assertSame( array(), $files[1]['app'] );

		$first = $this->http->query( 0 );
		$this->assertSame(
			"'folder\\'1' in parents and trashed = false and appProperties has { key='shcm_site' and value='it\\'s\\\\site' } and appProperties has { key='shcm_kind' and value='backup' }",
			$first['q']
		);
		$this->assertSame( 'createdTime desc', $first['orderBy'] );
		$this->assertSame( '100', $first['pageSize'] );
		$this->assertSame( 'nextPageToken,files(id,name,size,createdTime,md5Checksum,sha256Checksum,appProperties,webViewLink)', $first['fields'] );
		$this->assertArrayNotHasKey( 'pageToken', $first );

		$second = $this->http->query( 1 );
		$this->assertSame( 'page-2-token', $second['pageToken'] );
		$this->assertSame( $first['q'], $second['q'] );

		// Without a kind there is no shcm_kind term.
		$this->http->json( 200, array( 'files' => array() ) );
		$this->assertSame( array(), $this->client->listBackups( 'folder', 'site' ) );
		$this->assertStringNotContainsString( 'shcm_kind', $this->http->query( 2 )['q'] );
	}

	public function testGetFileAndDeleteFile() {
		$this->connect();
		$this->http->json(
			200,
			array(
				'id'   => 'file-1',
				'name' => 'a.wpress',
				'size' => '5',
			)
		);
		$file = $this->client->getFile( 'file-1' );
		$this->assertSame( 'file-1', $file['id'] );
		$this->assertSame( 5, $file['size'] );
		$this->assertSame( 'id,name,size,createdTime,md5Checksum,sha256Checksum,appProperties,webViewLink', $this->http->query( 0 )['fields'] );

		$this->http->queue( 404, array( 'Content-Type' => 'text/plain' ), 'Not Found' );
		$e = $this->catchDrive(
			function () {
				$this->client->getFile( 'file-gone' );
			}
		);
		$this->assertSame( DriveException::NOT_FOUND, $e->kind() );
		$this->assertStringContainsString( '404', $e->getMessage() );
		$this->assertStringContainsString( 'Not Found', $e->getMessage() );

		$this->http->queue( 204, array(), '' );
		$this->assertTrue( $this->client->deleteFile( 'file-1' ) );
		$this->assertSame( 'DELETE', $this->http->last()['method'] );
		$this->assertSame( self::API . '/files/file-1', $this->http->last()['url'] );
		$this->assertSame( 'Bearer ' . self::ACCESS, $this->http->last()['headers']['Authorization'] );

		$this->http->json( 404, self::apiError( 404, 'notFound', 'File not found: file-1.' ) );
		$this->assertFalse( $this->client->deleteFile( 'file-1' ) );

		$this->http->json( 403, self::apiError( 403, 'insufficientFilePermissions', 'The user does not have sufficient permissions for this file.' ) );
		$e = $this->catchDrive(
			function () {
				$this->client->deleteFile( 'file-1' );
			}
		);
		$this->assertSame( DriveException::FORBIDDEN, $e->kind() );
		$this->assertSame( 'insufficientFilePermissions', $e->reason() );
	}

	public function testApiStatusMapping() {
		$this->connect();
		$cases = array(
			// A request timeout on an ordinary API call is temporary (unlike on an upload session).
			array( 408, '', DriveException::SERVER ),
			array( 499, '', DriveException::BAD_REQUEST ),
			array( 400, json_encode( self::apiError( 400, 'invalidQuery', 'Invalid query' ) ), DriveException::BAD_REQUEST ),
			array( 410, '', DriveException::NOT_FOUND ),
			array( 429, '', DriveException::RATE_LIMITED ),
			array( 502, '', DriveException::SERVER ),
			array( 403, json_encode( self::apiError( 403, 'userRateLimitExceeded', 'User Rate Limit Exceeded' ) ), DriveException::RATE_LIMITED ),
			array( 403, json_encode( self::apiError( 403, 'storageQuotaExceeded', 'Quota' ) ), DriveException::QUOTA ),
			array( 403, json_encode( self::apiError( 403, 'appNotAuthorizedToFile', 'Not authorized' ) ), DriveException::FORBIDDEN ),
		);
		foreach ( $cases as $case ) {
			list( $status, $body, $kind ) = $case;
			$this->http->queue( $status, array( 'Content-Type' => 'application/json' ), $body );
			$e = $this->catchDrive(
				function () {
					$this->client->getFile( 'file-1' );
				}
			);
			$this->assertSame( $kind, $e->kind(), 'HTTP ' . $status );
			$this->assertSame( $status, $e->httpStatus() );
			$this->assertSame( in_array( $kind, array( DriveException::SERVER, DriveException::RATE_LIMITED ), true ), $e->isRetryable(), 'HTTP ' . $status );
		}
		$this->assertSame( array(), $this->http->responses );
	}

	public function testErrorReasonThatCarriesASecretIsDropped() {
		$secret = 'GOCSPX-' . 'AbCdEfGhIjKlMnOpQrStUvWxYz01'; // Split: fake, but scanner-shaped.
		$body   = function ( $reason ) {
			return json_encode(
				array(
					'error' => array(
						'code'    => 400,
						'message' => 'bad',
						'errors'  => array( array( 'reason' => $reason ) ),
					),
				)
			);
		};

		// Named as a secret by the caller.
		$e = DriveException::fromResponse( new HttpResponse( 400, array(), $body( 'Opaque-Secret-Value_42' ) ), array( 'Opaque-Secret-Value_42' ) );
		$this->assertSame( 'Google Drive rejected the request (400): bad', $e->getMessage() );
		$this->assertSame( '', $e->reason() );

		// Shaped like a Google credential, even when nobody named it.
		foreach ( array( $secret, 'ya29.a0AfB_byCxyz', 'prefix' . $secret ) as $reason ) {
			list( $described ) = DriveException::describe( new HttpResponse( 400, array(), $body( $reason ) ) );
			$this->assertSame( '', $described, $reason );
		}
		$e = DriveException::fromResponse( new HttpResponse( 400, array(), $body( $secret ) ), array( $secret ) );
		$this->assertStringNotContainsString( 'GOCSPX', $e->getMessage() );
		$this->assertSame( '', $e->reason() );

		// Ordinary reasons stay.
		$e = DriveException::fromResponse( new HttpResponse( 403, array(), json_encode( self::apiError( 403, 'storageQuotaExceeded', 'Full' ) ) ), array( $secret ) );
		$this->assertSame( 'storageQuotaExceeded', $e->reason() );

		// The token endpoint format, whose message is also stored as the connection error.
		$this->connect();
		$this->now += 7200;
		$this->http->json( 401, array( 'error' => $secret ) );
		$e = $this->catchDrive(
			function () {
				$this->oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::BAD_REQUEST, $e->kind() );
		$this->assertStringNotContainsString( 'GOCSPX', $e->getMessage() );
		$this->assertSame( '', $e->reason() );
		$this->assertStringContainsString( 'HTTP 401', $e->getMessage() );
	}

	public function testStartUpload() {
		$this->connect();
		$this->http->queue( 200, array( 'Location' => self::SESSION ), '' );

		$uri = $this->client->startUpload(
			'example.test-backup.wpress',
			73400320,
			'folder-1',
			array(
				'shcm_site'   => 'abcdef0123456789',
				'shcm_kind'   => 'backup',
				'shcm_sha256' => str_repeat( 'a', 64 ),
			),
			'SH Clone Migration backup'
		);
		$this->assertSame( self::SESSION, $uri );

		$request = $this->http->requests[0];
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame(
			'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id%2Cname%2Csize%2Cmd5Checksum%2Csha256Checksum%2CcreatedTime%2CwebViewLink%2CappProperties',
			$request['url']
		);
		$this->assertSame( 'Bearer ' . self::ACCESS, $request['headers']['Authorization'] );
		$this->assertSame( 'application/json; charset=UTF-8', $request['headers']['Content-Type'] );
		$this->assertSame( 'application/octet-stream', $request['headers']['X-Upload-Content-Type'] );
		$this->assertSame( '73400320', $request['headers']['X-Upload-Content-Length'] );
		$this->assertSame(
			array(
				'name'          => 'example.test-backup.wpress',
				'parents'       => array( 'folder-1' ),
				'mimeType'      => 'application/octet-stream',
				'description'   => 'SH Clone Migration backup',
				'appProperties' => array(
					'shcm_site'   => 'abcdef0123456789',
					'shcm_kind'   => 'backup',
					'shcm_sha256' => str_repeat( 'a', 64 ),
				),
			),
			json_decode( $request['body'], true )
		);

		// An empty property list is still a JSON object.
		$this->http->queue( 200, array( 'location' => self::SESSION ), '' );
		$this->client->startUpload( 'a.wpress', 1, 'folder-1', array() );
		$this->assertStringContainsString( '"appProperties":{}', $this->http->last()['body'] );

		// 124 bytes for key plus value is the limit; nothing is sent above it.
		$count = count( $this->http->requests );
		$this->http->queue( 200, array( 'Location' => self::SESSION ), '' );
		$this->client->startUpload( 'a.wpress', 1, 'folder-1', array( 'k' => str_repeat( 'v', 123 ) ) );
		$e = $this->catchDrive(
			function () {
				$this->client->startUpload( 'a.wpress', 1, 'folder-1', array( 'k' => str_repeat( 'v', 124 ) ) );
			}
		);
		$this->assertSame( DriveException::BAD_REQUEST, $e->kind() );
		$this->assertStringContainsString( '124', $e->getMessage() );
		$e = $this->catchDrive(
			function () {
				$this->client->startUpload( 'a.wpress', 1, 'folder-1', array( 'naïve' => str_repeat( 'v', 119 ) ) );
			}
		);
		$this->assertSame( DriveException::BAD_REQUEST, $e->kind() );
		$this->assertCount( $count + 1, $this->http->requests );

		// A missing Location is an error, and a 401 refreshes once.
		$this->http->queue( 200, array(), '' );
		$e = $this->catchDrive(
			function () {
				$this->client->startUpload( 'a.wpress', 1, 'folder-1', array() );
			}
		);
		$this->assertSame( DriveException::SERVER, $e->kind() );

		$this->http->json( 401, self::apiError( 401, 'authError', 'Invalid Credentials' ) );
		$this->http->json(
			200,
			array(
				'access_token' => 'AT-upload-refresh',
				'expires_in'   => 3599,
			)
		);
		$this->http->queue( 200, array( 'Location' => self::SESSION ), '' );
		$this->assertSame( self::SESSION, $this->client->startUpload( 'a.wpress', 1, 'folder-1', array() ) );
		$this->assertSame( 'Bearer AT-upload-refresh', $this->http->last()['headers']['Authorization'] );

		// The session URI is a secret the redactor must learn about.
		$this->assertContains( self::SESSION, $this->connection->secrets() );

		$this->http->json( 403, self::apiError( 403, 'storageQuotaExceeded', "The user's Drive storage quota has been exceeded." ) );
		$e = $this->catchDrive(
			function () {
				$this->client->startUpload( 'a.wpress', 1, 'folder-1', array() );
			}
		);
		$this->assertSame( DriveException::QUOTA, $e->kind() );
		$this->assertSame(
			"Google Drive rejected the request (403 storageQuotaExceeded): The user's Drive storage quota has been exceeded.",
			$e->getMessage()
		);
	}

	public function testUploadChunkResults() {
		$this->connect();
		$chunk = str_repeat( 'x', Client::CHUNK_MULTIPLE );
		$total = Client::CHUNK_MULTIPLE * 2 + 10;

		// 308 with Range.
		$this->http->queue( 308, array( 'Range' => 'bytes=0-262143' ), '' );
		$this->assertSame(
			array(
				'done'   => false,
				'offset' => 262144,
				'file'   => null,
			),
			$this->client->uploadChunk( self::SESSION, $chunk, 0, $total, 45.0 )
		);
		$request = $this->http->requests[0];
		$this->assertSame( 'PUT', $request['method'] );
		$this->assertSame( self::SESSION, $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertSame( '262144', $request['headers']['Content-Length'] );
		$this->assertSame( 'bytes 0-262143/524298', $request['headers']['Content-Range'] );
		// Explicit, or WordPress's cURL transport labels the PUT body form-urlencoded.
		$this->assertSame( 'application/octet-stream', $request['headers']['Content-Type'] );
		$this->assertSame( $chunk, $request['body'] );
		$this->assertEquals( 45.0, $request['options']['timeout'] );

		// 308 without Range: nothing arrived.
		$this->http->queue( 308, array(), '' );
		$result = $this->client->uploadChunk( self::SESSION, $chunk, 262144, $total );
		$this->assertFalse( $result['done'] );
		$this->assertSame( 0, $result['offset'] );
		$this->assertSame( 'bytes 262144-524287/524298', $this->http->last()['headers']['Content-Range'] );

		// Final chunk, 200 with the file.
		$this->http->json(
			200,
			array(
				'id'             => 'file-uploaded',
				'name'           => 'a.wpress',
				'size'           => (string) $total,
				'md5Checksum'    => 'abc',
				'sha256Checksum' => 'def',
				'createdTime'    => '2026-09-28T03:05:00Z',
				'appProperties'  => array( 'shcm_kind' => 'backup' ),
			)
		);
		$result = $this->client->uploadChunk( self::SESSION, 'tail-bytes', 524288, $total );
		$this->assertTrue( $result['done'] );
		$this->assertSame( $total, $result['offset'] );
		$this->assertSame( 'file-uploaded', $result['file']['id'] );
		$this->assertSame( $total, $result['file']['size'] );
		$this->assertSame( array( 'shcm_kind' => 'backup' ), $result['file']['app'] );
		$this->assertSame( 'bytes 524288-524297/524298', $this->http->last()['headers']['Content-Range'] );

		// 201 works as well.
		$this->http->json(
			201,
			array(
				'id'   => 'file-201',
				'size' => '10',
			)
		);
		$this->assertTrue( $this->client->uploadChunk( self::SESSION, 'tail-bytes', 0, 10 )['done'] );

		$cases = array(
			array( 404, array( 'Content-Type' => 'text/plain' ), 'Not Found', DriveException::SESSION_EXPIRED ),
			array( 410, array(), '', DriveException::SESSION_EXPIRED ),
			array( 400, array(), json_encode( self::apiError( 400, 'badRequest', 'Invalid Content-Range' ) ), DriveException::SESSION_EXPIRED ),
			array( 503, array(), json_encode( self::apiError( 503, 'backendError', 'Backend Error' ) ), DriveException::SERVER ),
			array( 500, array( 'Content-Type' => 'text/html' ), '<html><body>Error</body></html>', DriveException::SERVER ),
			array( 0, array(), '', DriveException::SERVER ),
			array( 429, array(), json_encode( self::apiError( 429, 'rateLimitExceeded', 'Rate Limit Exceeded' ) ), DriveException::RATE_LIMITED ),
			array( 403, array(), json_encode( self::apiError( 403, 'storageQuotaExceeded', "The user's Drive storage quota has been exceeded." ) ), DriveException::QUOTA ),
			array( 403, array(), json_encode( self::apiError( 403, 'rateLimitExceeded', 'Rate Limit Exceeded' ) ), DriveException::RATE_LIMITED ),
			array( 403, array(), json_encode( self::apiError( 403, 'userRateLimitExceeded', 'User Rate Limit Exceeded' ) ), DriveException::RATE_LIMITED ),
			array( 403, array(), json_encode( self::apiError( 403, 'forbidden', 'Forbidden' ) ), DriveException::SESSION_EXPIRED ),
			// Any other 4xx, including a request timeout and the 499 of a cancelled session.
			array( 408, array(), '', DriveException::SESSION_EXPIRED ),
			array( 499, array(), '', DriveException::SESSION_EXPIRED ),
			array( 302, array( 'Location' => 'https://captive.example.test/' ), '', DriveException::SERVER ),
		);
		foreach ( $cases as $case ) {
			list( $status, $headers, $body, $kind ) = $case;
			$this->http->queue( $status, $headers, $body, 0 === $status ? 'Connection reset by peer' : '' );
			$e = $this->catchDrive(
				function () use ( $chunk, $total ) {
					$this->client->uploadChunk( self::SESSION, $chunk, 0, $total );
				}
			);
			$this->assertSame( $kind, $e->kind(), 'HTTP ' . $status . ' ' . $body );
			$this->assertSame( in_array( $kind, array( DriveException::SERVER, DriveException::RATE_LIMITED ), true ), $e->isRetryable() );
			$this->assertStringNotContainsString( 'SESSION-secret', $e->getMessage() );
		}

		$this->assertSame( array(), $this->http->responses );
	}

	public function testUploadChunkAssertsTheProtocolRules() {
		$bad = array(
			array( str_repeat( 'x', 1000 ), 0, 5000 ),
			array( str_repeat( 'x', Client::CHUNK_MULTIPLE + 1 ), 0, Client::CHUNK_MULTIPLE * 4 ),
			array( '', 0, 10 ),
			array( 'abc', -1, 10 ),
			array( 'abcdef', 5, 10 ),
		);
		foreach ( $bad as $case ) {
			list( $data, $offset, $total ) = $case;
			try {
				$this->client->uploadChunk( self::SESSION, $data, $offset, $total );
				$this->fail( sprintf( 'Chunk of %d bytes at %d of %d was accepted.', strlen( $data ), $offset, $total ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringNotContainsString( 'SESSION-secret', $e->getMessage() );
			}
		}
		try {
			$this->client->uploadChunk( 'not a url', 'abc', 0, 3 );
			$this->fail( 'An invalid session URI was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( 'not a url', $e->getMessage() );
		}
		$this->assertCount( 0, $this->http->requests );

		// The last chunk may have any size, a middle one a multiple of 256 KiB.
		$this->http->queue( 308, array( 'Range' => 'bytes=0-524287' ), '' );
		$this->assertSame( 524288, $this->client->uploadChunk( self::SESSION, str_repeat( 'x', 524288 ), 0, 600000 )['offset'] );
		$this->http->queue( 308, array( 'Range' => 'bytes=0-999999' ), '' );
		$e = $this->catchDrive(
			function () {
				$this->client->uploadChunk( self::SESSION, 'abc', 0, 3 );
			}
		);
		$this->assertSame( DriveException::SERVER, $e->kind() );
	}

	public function testQueryUpload() {
		$this->http->queue( 308, array( 'Range' => 'bytes=0-8388607' ), '' );
		$this->assertSame(
			array(
				'done'   => false,
				'offset' => 8388608,
				'file'   => null,
			),
			$this->client->queryUpload( self::SESSION, 70000000 )
		);
		$request = $this->http->requests[0];
		$this->assertSame( 'PUT', $request['method'] );
		$this->assertSame( self::SESSION, $request['url'] );
		$this->assertSame( '', $request['body'] );
		$this->assertSame( '0', $request['headers']['Content-Length'] );
		$this->assertSame( 'bytes */70000000', $request['headers']['Content-Range'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertArrayNotHasKey( 'Content-Type', $request['headers'] );

		$this->http->json(
			200,
			array(
				'id'   => 'file-done',
				'size' => '70000000',
			)
		);
		$result = $this->client->queryUpload( self::SESSION, 70000000 );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'file-done', $result['file']['id'] );

		$this->http->queue( 404, array( 'Content-Type' => 'text/plain' ), 'Not Found' );
		$e = $this->catchDrive(
			function () {
				$this->client->queryUpload( self::SESSION, 70000000 );
			}
		);
		$this->assertSame( DriveException::SESSION_EXPIRED, $e->kind() );
		$this->assertSame( 404, $e->httpStatus() );
	}

	public function testDownloadRange() {
		$this->connect();
		$this->http->queue( 206, array( 'Content-Range' => 'bytes 100-109/5000' ), '0123456789' );
		$this->assertSame( '0123456789', $this->client->downloadRange( 'file-1', 100, 109 ) );
		$request = $this->http->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( self::API . '/files/file-1?alt=media', $request['url'] );
		$this->assertSame( 'bytes=100-109', $request['headers']['Range'] );
		$this->assertSame( 'Bearer ' . self::ACCESS, $request['headers']['Authorization'] );

		// The last part of a file is shorter than asked for.
		$this->http->queue( 206, array( 'Content-Range' => 'bytes 4990-4999/5000' ), '0123456789' );
		$this->assertSame( '0123456789', $this->client->downloadRange( 'file-1', 4990, 5989 ) );

		// A 200 is fine when the range covers the whole file.
		$this->http->queue( 200, array(), 'whole' );
		$this->assertSame( 'whole', $this->client->downloadRange( 'file-1', 0, 1048575 ) );

		// Not when it ignored an offset.
		$this->http->queue( 200, array(), 'whole file' );
		$e = $this->catchDrive(
			function () {
				$this->client->downloadRange( 'file-1', 5, 9 );
			}
		);
		$this->assertSame( DriveException::BAD_REQUEST, $e->kind() );
		// Also when the whole file is shorter than the range asked for.
		$this->http->queue( 200, array(), 'short' );
		$e = $this->catchDrive(
			function () {
				$this->client->downloadRange( 'file-1', 3, 1048575 );
			}
		);
		$this->assertSame( DriveException::BAD_REQUEST, $e->kind() );
		$this->assertSame( 'range_ignored', $e->reason() );

		// A different part than requested.
		$this->http->queue( 206, array( 'Content-Range' => 'bytes 0-9/5000' ), '0123456789' );
		$e = $this->catchDrive(
			function () {
				$this->client->downloadRange( 'file-1', 100, 109 );
			}
		);
		$this->assertSame( DriveException::INTEGRITY, $e->kind() );

		$this->http->json( 404, self::apiError( 404, 'notFound', 'File not found: file-1.' ) );
		$e = $this->catchDrive(
			function () {
				$this->client->downloadRange( 'file-1', 0, 9 );
			}
		);
		$this->assertSame( DriveException::NOT_FOUND, $e->kind() );

		$this->expectException( \InvalidArgumentException::class );
		$this->client->downloadRange( 'file-1', 10, 9 );
	}

	public function testExceptionsNeverContainSecrets() {
		// Development php.ini records (truncated) arguments in traces; make sure they are recorded here.
		$ignore_args = ini_get( 'zend.exception_ignore_args' );
		$max_length  = ini_get( 'zend.exception_string_param_max_len' );
		ini_set( 'zend.exception_ignore_args', '0' );
		ini_set( 'zend.exception_string_param_max_len', '15' );
		try {
			$this->assertNoSecretsInExceptions();
		} finally {
			ini_set( 'zend.exception_ignore_args', (string) $ignore_args );
			ini_set( 'zend.exception_string_param_max_len', (string) $max_length );
		}
	}

	public function testSafeText() {
		$text = DriveException::safeText(
			"cURL error 7: Failed to connect to https://proxy.example.test/relay?token=abc&key=unknownsecretvalue#frag\nport 443: Connection refused " . str_repeat( 'x', 400 ),
			array( 'abc-literal-secret' )
		);
		$this->assertStringContainsString( 'https://proxy.example.test/relay port 443', $text );
		$this->assertStringNotContainsString( 'unknownsecretvalue', $text );
		$this->assertStringNotContainsString( "\n", $text );
		$this->assertLessThanOrEqual( DriveException::MAX_DETAIL + 3, strlen( $text ) );
		$this->assertStringEndsWith( '...', $text );

		$this->assertSame( 'id [redacted] and [redacted]', DriveException::safeText( 'id abc-literal-secret and abc%2Bliteral', array( 'abc-literal-secret', 'abc+literal' ) ) );
		$this->assertSame( '', DriveException::safeText( '' ) );
		$this->assertSame( "bad \xEF\xBF\xBD byte", DriveException::safeText( "bad \xFF byte" ) );
	}

	/**
	 * Provoke every kind of failure with responses that echo secrets and
	 * check messages, stored state and traces.
	 *
	 * @return void
	 */
	private function assertNoSecretsInExceptions() {
		$secrets = array( self::CLIENT_SECRET, self::REFRESH, self::ACCESS, self::CODE, self::SESSION, 'SESSION-secret-77aa' );
		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$errors = array();

		// Token endpoint echoing the code and the client secret.
		$this->http->json(
			400,
			array(
				'error'             => 'invalid_request',
				'error_description' => 'Bad code ' . self::CODE . ' for client_secret=' . self::CLIENT_SECRET,
			)
		);
		$errors[] = $this->catchDrive(
			function () {
				$this->oauth->exchangeCode( self::CODE, 'https://example.test/cb' );
			}
		);
		// A transport error mentioning the form body, URL-encoded.
		$this->http->queue( 0, array(), '', 'Failed to send code=' . rawurlencode( self::CODE ) . '&client_secret=' . rawurlencode( self::CLIENT_SECRET ) );
		$errors[] = $this->catchDrive(
			function () {
				$this->oauth->exchangeCode( self::CODE, 'https://example.test/cb' );
			}
		);

		$this->connection->storeTokens( self::REFRESH, self::ACCESS, 3600, OAuth::SCOPE );

		// Drive API errors echoing the access token.
		$this->http->json( 403, self::apiError( 403, 'forbidden', 'Token ' . self::ACCESS . ' may not do this' ) );
		$errors[] = $this->catchDrive(
			function () {
				$this->client->about();
			}
		);
		$this->http->queue( 0, array(), '', 'Proxy refused Authorization: Bearer ' . self::ACCESS );
		$errors[] = $this->catchDrive(
			function () {
				$this->client->listBackups( 'folder', 'site' );
			}
		);
		$this->http->queue( 200, array(), 'not json but ' . self::ACCESS );
		$errors[] = $this->catchDrive(
			function () {
				$this->client->getFile( 'file-1' );
			}
		);
		$this->http->queue( 500, array( 'Content-Type' => 'text/plain' ), 'refresh=' . self::REFRESH );
		$errors[] = $this->catchDrive(
			function () {
				$this->client->startUpload( 'a.wpress', 1, 'folder', array() );
			}
		);

		// Upload session errors mentioning the session URI.
		$this->http->queue( 0, array(), '', 'cURL error 56: Recv failure for ' . self::SESSION );
		$errors[] = $this->catchDrive(
			function () {
				$this->client->uploadChunk( self::SESSION, 'abc', 0, 3 );
			}
		);
		$this->http->queue( 400, array( 'Content-Type' => 'text/plain' ), 'Bad session ' . self::SESSION );
		$errors[] = $this->catchDrive(
			function () {
				$this->client->uploadChunk( self::SESSION, 'abc', 0, 3 );
			}
		);
		$this->http->json( 503, self::apiError( 503, 'backendError', 'Session SESSION-secret-77aa is busy' ) );
		$errors[] = $this->catchDrive(
			function () {
				$this->client->queryUpload( self::SESSION, 3 );
			}
		);

		// Refresh failure echoing the refresh token; its message is also stored.
		$this->now += 7200;
		$this->http->json(
			400,
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Token ' . self::REFRESH . ' has been expired or revoked.',
			)
		);
		$errors[] = $this->catchDrive(
			function () {
				$this->client->about();
			}
		);

		$this->assertCount( 10, $errors );
		$texts = array( $this->connection->status()['error'], file_get_contents( $this->store->path( 'gdrive' ) ) );
		foreach ( $errors as $error ) {
			$texts[] = $error->getMessage();
			if ( PHP_VERSION_ID >= 80200 ) {
				// #[\SensitiveParameter] keeps them out of stack traces too.
				$texts[] = $error->getTraceAsString();
			}
		}
		foreach ( $texts as $text ) {
			foreach ( $secrets as $secret ) {
				$this->assertStringNotContainsString( $secret, $text );
				$this->assertStringNotContainsString( substr( $secret, 0, 12 ), $text );
				$this->assertStringNotContainsString( rawurlencode( $secret ), $text );
			}
		}
		// The messages stay useful.
		$this->assertStringContainsString( 'invalid_request', $errors[0]->getMessage() );
		$this->assertStringContainsString( '403 forbidden', $errors[2]->getMessage() );
		$this->assertStringContainsString( 'Recv failure', $errors[6]->getMessage() );
		$this->assertStringContainsString( 'is busy', $errors[8]->getMessage() );
		$this->assertStringContainsString( 'invalid_grant', $errors[9]->getMessage() );
	}

	public function testConnectionExposesSecretsForTheRedactor() {
		$seen = array();
		$this->connection->onSecret(
			function ( $secret ) use ( &$seen ) {
				$seen[] = $secret;
			}
		);
		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$this->connection->storeTokens( self::REFRESH, self::ACCESS, 3600, OAuth::SCOPE );
		$this->assertSame( array( self::CLIENT_SECRET, self::REFRESH, self::ACCESS ), $seen );

		// A fresh instance (next request) opens what is stored.
		$fresh   = $this->makeConnection( self::FINGERPRINT );
		$secrets = $fresh->secrets();
		sort( $secrets );
		$expected = array( self::CLIENT_SECRET, self::REFRESH, self::ACCESS );
		sort( $expected );
		$this->assertSame( $expected, $secrets );
	}

	// ---------------------------------------------------------------------
	// Connection state.
	// ---------------------------------------------------------------------

	public function testConnectionStatusTransitions() {
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_NOT_CONFIGURED, $status['state'] );
		$this->assertSame( '', $status['account'] );
		$this->assertFalse( $status['from_constants'] );
		$this->assertFalse( $this->connection->credentialsFromConstants() );
		$this->assertFalse( $this->connection->hasCredentials() );

		// A client id without a secret is still not configured.
		$this->connection->setCredentials( self::CLIENT_ID, '' );
		$this->assertSame( Connection::STATE_NOT_CONFIGURED, $this->connection->status()['state'] );

		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$this->assertSame( Connection::STATE_NOT_CONNECTED, $this->connection->status()['state'] );
		$this->assertSame( self::CLIENT_ID, $this->connection->clientId() );
		$this->assertSame( self::CLIENT_SECRET, $this->connection->clientSecret() );

		$site_id = $this->connection->siteId();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $site_id );
		$this->assertSame( $site_id, $this->connection->siteId() );

		$this->connection->storeTokens( self::REFRESH, self::ACCESS, 3600, OAuth::SCOPE );
		$this->connection->setAccount( 'owner@example.test', 'Site Owner' );
		$this->connection->setFolder( 'folder-1', 'Backups' );
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_CONNECTED, $status['state'] );
		$this->assertSame( 'owner@example.test', $status['account'] );
		$this->assertSame( 'Site Owner', $status['name'] );
		$this->assertSame( 'folder-1', $status['folder_id'] );
		$this->assertSame( 'Backups', $status['folder_name'] );
		$this->assertSame( $site_id, $status['site_id'] );
		$this->assertSame( '', $status['error'] );

		// The access token is not handed out during its last minute.
		$this->now += 3540;
		$this->assertNull( $this->connection->accessToken() );
		$this->now -= 1;
		$this->assertSame( self::ACCESS, $this->connection->accessToken() );

		$this->connection->markReconnect( 'Google Drive access was revoked.' );
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_RECONNECT, $status['state'] );
		$this->assertSame( 'Google Drive access was revoked.', $status['error'] );
		$this->assertFalse( $this->connection->isUsable() );
		$this->assertNull( $this->connection->accessToken() );
	}

	public function testConnectionOnAnotherInstallation() {
		$this->connect();
		$this->connection->setFolder( 'folder-1', 'Backups' );
		$site_id = $this->connection->siteId();

		// A host-level copy: same files and keys, different fingerprint.
		$copy   = $this->makeConnection( 'fingerprint-of-a-copy' );
		$status = $copy->status();
		$this->assertSame( Connection::STATE_OTHER_SITE, $status['state'] );
		$this->assertFalse( $copy->isUsable() );
		$this->assertNotSame( '', $status['error'] );

		$oauth = new OAuth( $copy, $this->http, Endpoints::defaults() );
		$e     = $this->catchDrive(
			function () use ( $oauth ) {
				$oauth->accessToken();
			}
		);
		$this->assertSame( DriveException::NOT_CONNECTED, $e->kind() );
		$this->assertCount( 0, $this->http->requests );

		// "This is the same site" keeps tokens, site id and folder.
		$copy->adoptThisSite();
		$status = $copy->status();
		$this->assertSame( Connection::STATE_CONNECTED, $status['state'] );
		$this->assertSame( $site_id, $status['site_id'] );
		$this->assertSame( 'folder-1', $status['folder_id'] );
		$this->assertSame( self::REFRESH, $copy->refreshToken() );

		// Now the original is the other site; connecting again there gives it a new identity.
		$this->assertSame( Connection::STATE_OTHER_SITE, $this->connection->status()['state'] );
		$this->connection->storeTokens( 'RT-original-again', 'AT-original-again', 3600, OAuth::SCOPE );
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_CONNECTED, $status['state'] );
		$this->assertNotSame( $site_id, $status['site_id'] );
		$this->assertSame( '', $status['folder_id'] );
	}

	public function testConnectionWithUnreadableSecrets() {
		$this->connect();

		// Other wp-config keys: nothing can be opened, so the client secret is missing too.
		$other_box = new SecretBox( str_repeat( 'z', 40 ) );
		$elsewhere = $this->makeConnection( self::FINGERPRINT, $other_box );
		$this->assertSame( Connection::STATE_NOT_CONFIGURED, $elsewhere->status()['state'] );
		$this->assertNull( $elsewhere->refreshToken() );
		$this->assertNull( $elsewhere->accessToken() );
		$this->assertSame( '', $elsewhere->clientSecret() );

		// A refresh token sealed with other keys: reconnect.
		$data                  = $this->store->read( 'gdrive' );
		$data['refresh_token'] = $other_box->seal( self::REFRESH, Connection::CONTEXT_REFRESH_TOKEN );
		$this->store->write( 'gdrive', $data );
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_RECONNECT, $status['state'] );
		$this->assertStringContainsString( 'Reconnect', $status['error'] );
		$this->assertFalse( $this->connection->isUsable() );

		// A value sealed under another context does not open either.
		$data['refresh_token'] = $this->box->seal( self::REFRESH, Connection::CONTEXT_ACCESS_TOKEN );
		$this->store->write( 'gdrive', $data );
		$this->assertSame( Connection::STATE_RECONNECT, $this->connection->status()['state'] );
	}

	public function testSetCredentialsAndDisconnect() {
		$this->connect();
		$this->connection->setAccount( 'owner@example.test', 'Site Owner' );
		$this->connection->setFolder( 'folder-1', 'Backups' );
		$site_id = $this->connection->siteId();

		// Same client id, secret left empty: everything stays.
		$this->connection->setCredentials( self::CLIENT_ID, '' );
		$this->assertSame( self::CLIENT_SECRET, $this->connection->clientSecret() );
		$this->assertSame( Connection::STATE_CONNECTED, $this->connection->status()['state'] );

		// Same client id, rotated secret: the connection stays.
		$this->connection->setCredentials( self::CLIENT_ID, 'CS-rotated-secret-value' );
		$this->assertSame( 'CS-rotated-secret-value', $this->connection->clientSecret() );
		$this->assertSame( self::REFRESH, $this->connection->refreshToken() );

		// disconnect() keeps the credentials and the site id.
		$this->connection->disconnect();
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_NOT_CONNECTED, $status['state'] );
		$this->assertSame( '', $status['account'] );
		$this->assertSame( '', $status['folder_id'] );
		$this->assertSame( 0, $status['connected_at'] );
		$this->assertSame( $site_id, $status['site_id'] );
		$this->assertNull( $this->connection->refreshToken() );
		$this->assertNull( $this->connection->accessToken() );
		$this->assertSame( self::CLIENT_ID, $this->connection->clientId() );
		$this->assertSame( 'CS-rotated-secret-value', $this->connection->clientSecret() );

		// A different client id clears tokens, account and folder (and needs its own secret).
		$this->connection->storeTokens( self::REFRESH, self::ACCESS, 3600, OAuth::SCOPE );
		$this->connection->setAccount( 'owner@example.test', 'Site Owner' );
		$this->connection->setFolder( 'folder-1', 'Backups' );
		$this->connection->setCredentials( 'other-client.apps.googleusercontent.com', 'CS-other-client-secret' );
		$status = $this->connection->status();
		$this->assertSame( Connection::STATE_NOT_CONNECTED, $status['state'] );
		$this->assertSame( '', $status['account'] );
		$this->assertSame( '', $status['folder_id'] );
		$this->assertSame( $site_id, $status['site_id'] );
		$this->assertNull( $this->connection->refreshToken() );
		$this->assertSame( 'other-client.apps.googleusercontent.com', $this->connection->clientId() );

		$this->connection->setCredentials( 'third-client.apps.googleusercontent.com', '' );
		$this->assertSame( '', $this->connection->clientSecret() );
		$this->assertSame( Connection::STATE_NOT_CONFIGURED, $this->connection->status()['state'] );
	}

	public function testDriveExceptionShape() {
		$e = new DriveException( DriveException::QUOTA, 'Full.', 403, 'storageQuotaExceeded' );
		$this->assertSame( DriveException::QUOTA, $e->kind() );
		$this->assertSame( 403, $e->httpStatus() );
		$this->assertSame( 403, $e->getCode() );
		$this->assertSame( 'storageQuotaExceeded', $e->reason() );
		$this->assertSame( 'Full.', $e->getMessage() );
		$this->assertFalse( $e->isRetryable() );
		$this->assertInstanceOf( \RuntimeException::class, $e );

		// The RuntimeException argument order is understood as well.
		$swapped = new DriveException( 'Checksum mismatch.', DriveException::INTEGRITY );
		$this->assertSame( DriveException::INTEGRITY, $swapped->kind() );
		$this->assertSame( 'Checksum mismatch.', $swapped->getMessage() );

		$this->assertTrue( ( new DriveException( DriveException::RATE_LIMITED, 'x' ) )->isRetryable() );
		$this->assertTrue( ( new DriveException( DriveException::SERVER, 'x' ) )->isRetryable() );
		$this->assertCount( 12, DriveException::kinds() );
	}

	public function testCredentialConstants() {
		if ( defined( 'SHCM_GDRIVE_CLIENT_ID' ) || defined( 'SHCM_GDRIVE_CLIENT_SECRET' ) ) {
			$this->markTestSkipped( 'Credential constants are defined in this process.' );
		}
		// Constants cannot be undefined again, so they are exercised in child processes
		// by testWordPressTransportAndConstantsInAChildProcess() and
		// testPartialCredentialConstantsInChildProcesses(); here only the default.
		$this->assertFalse( $this->connection->credentialsFromConstants() );
		$this->assertSame(
			array(
				'client_id'     => false,
				'client_secret' => false,
			),
			$this->connection->constantFields()
		);
		$status = $this->connection->status();
		$this->assertFalse( $status['id_from_constant'] );
		$this->assertFalse( $status['secret_from_constant'] );
	}

	/**
	 * Only one of the two credential constants: the other field stays usable
	 * in the settings form, and only the constant's field is locked.
	 */
	public function testPartialCredentialConstantsInChildProcesses() {
		// Only the secret in wp-config.php, the client ID from the form.
		$result = $this->runChild(
			'define( "SHCM_GDRIVE_CLIENT_SECRET", "GOCSPX-only-secret-in-config" );',
			'$out["before"] = $connection->status();
$out["fields"] = $connection->constantFields();
$out["from_constants"] = $connection->credentialsFromConstants();
$connection->setCredentials( "form-client-id", "" );
$out["after"] = $connection->status();
$out["client_secret"] = $connection->clientSecret();
$connection->setCredentials( "form-client-id", "form-secret-ignored-on-read" );
$out["stored"] = $store->read( "gdrive" );
$out["stored_secret"] = $box->open( $out["stored"]["client_secret"], "gdrive-client-secret" );
$out["client_secret_after_save"] = $connection->clientSecret();'
		);
		$this->assertSame( 'not_configured', $result['before']['state'] );
		$this->assertFalse( $result['before']['from_constants'] );
		$this->assertFalse( $result['before']['id_from_constant'] );
		$this->assertTrue( $result['before']['secret_from_constant'] );
		$this->assertSame(
			array(
				'client_id'     => false,
				'client_secret' => true,
			),
			$result['fields']
		);
		$this->assertFalse( $result['from_constants'] );
		$this->assertSame( 'not_connected', $result['after']['state'] );
		$this->assertSame( 'form-client-id', $result['after']['client_id'] );
		$this->assertSame( 'GOCSPX-only-secret-in-config', $result['client_secret'] );
		// What is passed is stored; the constant wins on read.
		$this->assertSame( 'form-client-id', $result['stored']['client_id'] );
		$this->assertSame( 'form-secret-ignored-on-read', $result['stored_secret'] );
		$this->assertSame( 'GOCSPX-only-secret-in-config', $result['client_secret_after_save'] );

		// Only the client ID in wp-config.php, the secret from the form.
		$result = $this->runChild(
			'define( "SHCM_GDRIVE_CLIENT_ID", "constant-client-id" );',
			'$out["before"] = $connection->status();
$connection->setCredentials( "", "form-client-secret" );
$out["after"] = $connection->status();
$connection->storeTokens( "RT-constant-id", "AT-constant-id", 3600, SHCM\Remote\GoogleDrive\OAuth::SCOPE );
// The locked client ID field sends nothing, or a stale value: the effective ID is unchanged.
$connection->setCredentials( "", "" );
$connection->setCredentials( "some-other-id", "" );
$out["connected"] = $connection->status();
$out["refresh"] = $connection->refreshToken();
$out["client_secret"] = $connection->clientSecret();
$out["stored_id"] = $store->read( "gdrive" )["client_id"];'
		);
		$this->assertSame( 'not_configured', $result['before']['state'] );
		$this->assertFalse( $result['before']['from_constants'] );
		$this->assertTrue( $result['before']['id_from_constant'] );
		$this->assertFalse( $result['before']['secret_from_constant'] );
		$this->assertSame( 'constant-client-id', $result['before']['client_id'] );
		$this->assertSame( 'not_connected', $result['after']['state'] );
		$this->assertSame( 'connected', $result['connected']['state'] );
		$this->assertSame( 'constant-client-id', $result['connected']['client_id'] );
		$this->assertSame( 'RT-constant-id', $result['refresh'] );
		$this->assertSame( 'form-client-secret', $result['client_secret'] );
		$this->assertSame( 'some-other-id', $result['stored_id'] );
	}

	/**
	 * Run PHP code in a child process with the plugin loaded and a Connection
	 * on a scratch store ($connection, $store, $box); the code fills $out.
	 *
	 * @param string $defines Code run before the plugin is loaded (constants).
	 * @param string $code    Code run afterwards.
	 * @return array The decoded $out.
	 */
	private function runChild( $defines, $code ) {
		$bootstrap = dirname( __DIR__, 2 ) . '/includes/bootstrap.php';
		return $this->runChildScript(
			'<?php
define( "SHCM_ALLOW_STANDALONE", true );
' . $defines . '
require ' . var_export( $bootstrap, true ) . ';
$dir = sys_get_temp_dir() . "/shcm-gdrive-child-" . bin2hex( random_bytes( 6 ) );
$store = new SHCM\Backup\ConfigStore( $dir );
$box = new SHCM\Security\SecretBox( str_repeat( "k", 40 ) );
$connection = new SHCM\Remote\GoogleDrive\Connection( $store, $box, "fp" );
$out = array();
try {
' . $code . '
} finally {
	SHCM\Filesystem\Storage::rmdirRecursive( $dir );
}
echo json_encode( $out, JSON_UNESCAPED_SLASHES );
'
		);
	}

	/**
	 * Run a PHP script in a child process.
	 *
	 * @param string $source Script source.
	 * @return array The script's JSON output, decoded.
	 */
	private function runChildScript( $source ) {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is not available.' );
		}

		@mkdir( $this->dir, 0755, true );
		$script = $this->dir . '/child-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents( $script, $source );

		$process = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $script ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );

		$this->assertSame( 0, $code, $stderr . $stdout );
		$this->assertSame( '', $stderr );
		$result = json_decode( $stdout, true );
		$this->assertIsArray( $result, $stdout );
		return $result;
	}

	/**
	 * WordPressTransport, endpoint constants and credential constants need
	 * global WordPress functions and constants that cannot be undone, so they
	 * run in a separate PHP process with minimal shims.
	 */
	public function testWordPressTransportAndConstantsInAChildProcess() {
		$result = $this->runChildScript( self::childScript( dirname( __DIR__, 2 ) . '/includes/bootstrap.php' ) );

		// Request arguments.
		$args = $result['calls'][0]['args'];
		$this->assertSame( 'https://example.test/x', $result['calls'][0]['url'] );
		$this->assertSame( 'PUT', $args['method'] );
		$this->assertSame( array( 'Content-Range' => 'bytes */10' ), $args['headers'] );
		$this->assertSame( 'body', $args['body'] );
		$this->assertEquals( 12.5, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 'SH-Clone-Migration/9.9.9', $args['user-agent'] );
		$this->assertSame( '1.1', $args['httpversion'] );
		$this->assertEquals( 30.0, $result['calls'][1]['args']['timeout'] );

		// Responses: dictionary with getAll(), iterable, plain array, WP_Error, exception.
		$this->assertSame( 308, $result['responses'][0]['status'] );
		$this->assertSame(
			array(
				'range'        => 'bytes=0-262143',
				'x-repeated'   => 'second',
				'content-type' => 'text/plain',
			),
			$result['responses'][0]['headers']
		);
		$this->assertSame( 'ok', $result['responses'][0]['body'] );
		$this->assertSame( array( 'location' => 'https://up.example.test/s' ), $result['responses'][1]['headers'] );
		$this->assertSame( array( 'content-length' => '0' ), $result['responses'][2]['headers'] );
		$this->assertSame( 0, $result['responses'][3]['status'] );
		$this->assertSame( 'cURL error 28: Operation timed out', $result['responses'][3]['error'] );
		$this->assertSame( 0, $result['responses'][4]['status'] );
		$this->assertSame( 'filter exploded', $result['responses'][4]['error'] );

		// Endpoint constants (valid ones win, invalid ones are ignored) and the filter.
		$this->assertSame( 'http://127.0.0.1:8091/token', $result['endpoints']['token'] );
		$this->assertSame( 'http://127.0.0.1:8091/drive/v3', $result['endpoints']['api'] );
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $result['endpoints']['revoke'] );
		$this->assertSame( 'http://127.0.0.1:8091/filtered-upload/drive/v3', $result['endpoints']['upload'] );

		// Credential constants override the stored values.
		$this->assertSame( 'constant-client-id', $result['client_id'] );
		$this->assertSame( 'constant-client-secret', $result['client_secret'] );
		$this->assertTrue( $result['from_constants'] );
		$this->assertTrue( $result['status']['from_constants'] );
		$this->assertTrue( $result['status']['id_from_constant'] );
		$this->assertTrue( $result['status']['secret_from_constant'] );
		$this->assertSame(
			array(
				'client_id'     => true,
				'client_secret' => true,
			),
			$result['fields']
		);
		$this->assertSame( 'not_connected', $result['state'] );
		$this->assertTrue( $result['secret_redacted'] );
	}

	/**
	 * Source of the child process script.
	 *
	 * @param string $bootstrap Plugin bootstrap path.
	 * @return string
	 */
	private static function childScript( $bootstrap ) {
		return '<?php
define( "SHCM_ALLOW_STANDALONE", true );
define( "SHCM_VERSION", "9.9.9" );
define( "SHCM_GDRIVE_TOKEN_URL", "http://127.0.0.1:8091/token" );
define( "SHCM_GDRIVE_API_URL", "http://127.0.0.1:8091/drive/v3/" );
define( "SHCM_GDRIVE_REVOKE_URL", "ftp://evil.example.test/revoke" );
define( "SHCM_GDRIVE_CLIENT_ID", "constant-client-id" );
define( "SHCM_GDRIVE_CLIENT_SECRET", "constant-client-secret" );
require ' . var_export( $bootstrap, true ) . ';

class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
class FakeDictionary {
	private $data;
	public function __construct( array $data ) { $this->data = $data; }
	public function getAll() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function apply_filters( $tag, $value ) {
	if ( "shcm_gdrive_endpoints" === $tag ) {
		$value["upload"] = "http://127.0.0.1:8091/filtered-upload/drive/v3";
		$value["auth"]   = 42;
	}
	return $value;
}
$GLOBALS["calls"]   = array();
$GLOBALS["answers"] = array(
	array( "headers" => new FakeDictionary( array( "range" => "bytes=0-262143", "x-repeated" => array( "first", "second" ), "content-type" => "text/plain" ) ), "body" => "ok", "response" => array( "code" => 308, "message" => "Resume Incomplete" ) ),
	array( "headers" => new ArrayIterator( array( "Location" => "https://up.example.test/s" ) ), "body" => "", "response" => array( "code" => 200, "message" => "OK" ) ),
	array( "headers" => array( "Content-Length" => "0" ), "body" => "", "response" => array( "code" => 204, "message" => "No Content" ) ),
	new WP_Error( "http_request_failed", "cURL error 28: Operation timed out" ),
	"throw",
);
function wp_remote_request( $url, $args ) {
	$GLOBALS["calls"][] = array( "url" => $url, "args" => $args );
	$answer = array_shift( $GLOBALS["answers"] );
	if ( "throw" === $answer ) {
		throw new RuntimeException( "filter exploded" );
	}
	return $answer;
}
function wp_remote_retrieve_response_code( $r ) { return $r["response"]["code"]; }
function wp_remote_retrieve_body( $r ) { return $r["body"]; }
function wp_remote_retrieve_headers( $r ) { return $r["headers"]; }

$transport = new SHCM\Remote\Http\WordPressTransport();
$responses = array();
$responses[] = $transport->request( "put", "https://example.test/x", array( "Content-Range" => "bytes */10" ), "body", array( "timeout" => 12.5 ) );
for ( $i = 0; $i < 4; $i++ ) {
	$responses[] = $transport->request( "GET", "https://example.test/y" );
}
$out = array( "calls" => $GLOBALS["calls"], "responses" => array() );
foreach ( $responses as $response ) {
	$out["responses"][] = array( "status" => $response->status, "headers" => $response->headers, "body" => $response->body, "error" => $response->error );
}
$out["endpoints"] = SHCM\Remote\GoogleDrive\Endpoints::resolve();

$dir = sys_get_temp_dir() . "/shcm-gdrive-child-" . bin2hex( random_bytes( 6 ) );
$connection = new SHCM\Remote\GoogleDrive\Connection( new SHCM\Backup\ConfigStore( $dir ), new SHCM\Security\SecretBox( str_repeat( "k", 40 ) ), "fp" );
$connection->setCredentials( "stored-client-id", "stored-secret" );
$out["client_id"]       = $connection->clientId();
$out["client_secret"]   = $connection->clientSecret();
$out["from_constants"]  = $connection->credentialsFromConstants();
$out["fields"]          = $connection->constantFields();
$out["status"]          = $connection->status();
$out["state"]           = $out["status"]["state"];
$out["secret_redacted"] = false === strpos( ( new SHCM\Logging\Redactor() )->scrub( "secret is constant-client-secret" ), "constant-client-secret" );
SHCM\Filesystem\Storage::rmdirRecursive( $dir );
echo json_encode( $out, JSON_UNESCAPED_SLASHES );
';
	}
}
