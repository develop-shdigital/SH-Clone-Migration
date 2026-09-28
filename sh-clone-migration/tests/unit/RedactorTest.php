<?php
/**
 * Log redaction tests for OAuth and Google Drive credentials.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Logging\Redactor;

/**
 * Tests for the Redactor patterns added for Google Drive.
 */
class RedactorTest extends TestCase {

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	protected $redactor;

	/**
	 * Build the redactor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->redactor = new Redactor();
	}

	/**
	 * Assert that none of the secrets survive and that the kept parts do.
	 *
	 * @param string   $input   Input.
	 * @param string[] $secrets Parts that must disappear.
	 * @param string[] $kept    Parts that must stay.
	 * @return void
	 */
	private function assertScrubbed( $input, array $secrets, array $kept = array() ) {
		$output = $this->redactor->scrub( $input );
		foreach ( $secrets as $secret ) {
			$this->assertStringNotContainsString( $secret, $output, 'Input: ' . $input );
		}
		foreach ( $kept as $part ) {
			$this->assertStringContainsString( $part, $output, 'Input: ' . $input );
		}
		$this->assertStringContainsString( Redactor::MASK, $output, 'Input: ' . $input );
	}

	public function testBearerValuesIncludingSlashes() {
		$this->assertSame(
			'Authorization: Bearer [redacted]',
			$this->redactor->scrub( 'Authorization: Bearer 1//0gAbC-def_ghijklmnop' )
		);
		$this->assertSame(
			'Authorization: Bearer [redacted]',
			$this->redactor->scrub( 'Authorization: Bearer ya29.a0AfB_byC-xyz_123.AbC' )
		);
		$this->assertScrubbed( 'retrying with Bearer abc/def+ghi==', array( 'abc/def+ghi' ), array( 'retrying with Bearer ' ) );
	}

	public function testAuthorizationInJson() {
		$json = json_encode( array( 'headers' => array( 'Authorization' => 'Bearer secret-value-123' ) ), JSON_UNESCAPED_SLASHES );
		$this->assertSame( '{"headers":{"Authorization":"Bearer [redacted]"}}', $this->redactor->scrub( $json ) );
		$this->assertScrubbed( "authorization = 'Basic dXNlcjpwYXNz'", array( 'dXNlcjpwYXNz' ) );
	}

	public function testAuthorizationCodesAndVerifiers() {
		$this->assertSame(
			'code=[redacted]&scope=https://www.googleapis.com/auth/drive.file&state=abc123',
			$this->redactor->scrub( 'code=4/0AX4XfWh-abc_DEF&scope=https://www.googleapis.com/auth/drive.file&state=abc123' )
		);
		$this->assertSame( '{"code":"[redacted]"}', $this->redactor->scrub( '{"code":"4/0AX4XfWh-abc_DEF"}' ) );
		$this->assertScrubbed(
			'https://example.test/wp-admin/admin-post.php?action=shcm_gdrive_callback&state=xyz&code=plainauthcode123&scope=drive.file',
			array( 'plainauthcode123' ),
			array( 'action=shcm_gdrive_callback', 'scope=drive.file' )
		);
		$this->assertScrubbed( 'authorization_code=abcdefghijkl', array( 'abcdefghijkl' ) );
		$this->assertScrubbed(
			'code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk&grant_type=authorization_code',
			array( 'dBjftJeZ4CVP' ),
			array( 'grant_type=authorization_code' )
		);
		$this->assertScrubbed( 'id_token=eyJhbGciOiJSUzI1NiJ9.payload.sig', array( 'eyJhbGciOiJSUzI1NiJ9' ) );
		$this->assertScrubbed( 'assertion=eyJhbGciOiJSUzI1NiJ9.x.y', array( 'eyJhbGciOiJSUzI1NiJ9' ) );
		$this->assertScrubbed( 'client_assertion=eyJhbGciOiJSUzI1NiJ9.x.y', array( 'eyJhbGciOiJSUzI1NiJ9' ) );
		$this->assertScrubbed( '"session_uri":"https://upload.example.test/x?id=1"', array( 'upload.example.test' ) );
		$this->assertScrubbed( 'upload_url=https://upload.example.test/x', array( 'upload.example.test' ) );
	}

	public function testUploadSessionIds() {
		$this->assertSame(
			'Location: https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=[redacted]',
			$this->redactor->scrub( 'Location: https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=ADPycdvAbC_def-123' )
		);
		$this->assertScrubbed( 'PUT https://x.test/upload?upload_id=ABC-xyz_123 failed', array( 'ABC-xyz_123' ), array( ' failed' ) );
		$this->assertScrubbed( '{"upload_id":"ADPycdvAbC_def-123"}', array( 'ADPycdvAbC_def-123' ) );
	}

	public function testGoogleTokenShapesInFreeText() {
		$this->assertSame(
			'refresh token [redacted] stored, access [redacted]',
			$this->redactor->scrub( 'refresh token 1//0gAbC-def_ghijk stored, access ya29.a0AfB_x' )
		);
		$this->assertScrubbed( 'got 4/0AX4XfWh-abc_DEF from the callback', array( '4/0AX4XfWh' ), array( 'from the callback' ) );
		$this->assertScrubbed( 'client secret is GOCSPX-AbCdEf_1234567', array( 'GOCSPX-AbCdEf' ) );
		$this->assertSame(
			"#0 /x/Client.php(88): SHCM\\Remote\\Drive->refresh('[redacted]...')",
			$this->redactor->scrub( "#0 /x/Client.php(88): SHCM\\Remote\\Drive->refresh('1//0gAbC-def_g...')" )
		);
		$this->assertSame(
			"#0 /x/Client.php(88): SHCM\\Remote\\Drive->call('[redacted]')",
			$this->redactor->scrub( "#0 /x/Client.php(88): SHCM\\Remote\\Drive->call('ya29.a0AfB_byC-...')" )
		);
	}

	public function testTokenResponsesAndForms() {
		$json = json_encode(
			array(
				'access_token'  => 'ya29.a0AfB_byC-xyz',
				'refresh_token' => '1//0gAbC-def',
				'expires_in'    => 3599,
				'token_type'    => 'Bearer',
				'scope'         => 'https://www.googleapis.com/auth/drive.file',
			),
			JSON_UNESCAPED_SLASHES
		);
		$this->assertSame(
			'{"access_token":"[redacted]","refresh_token":"[redacted]","expires_in":3599,"token_type":"Bearer","scope":"https://www.googleapis.com/auth/drive.file"}',
			$this->redactor->scrub( $json )
		);
		$this->assertSame(
			'grant_type=refresh_token&refresh_token=[redacted]&client_id=123-abc.apps.googleusercontent.com&client_secret=[redacted]',
			$this->redactor->scrub( 'grant_type=refresh_token&refresh_token=1%2F%2F0gAbC-def&client_id=123-abc.apps.googleusercontent.com&client_secret=GOCSPX-AbCdEf_123' )
		);
		$this->assertSame(
			'{"web":{"client_id":"123.apps.googleusercontent.com","client_secret":"[redacted]"}}',
			$this->redactor->scrub( '{"web":{"client_id":"123.apps.googleusercontent.com","client_secret":"GOCSPX-AbCdEf_123456"}}' )
		);
	}

	public function testLiteralsAreAlsoRedactedUrlEncoded() {
		$secret = 'p@ss w0rd/+~x';
		$this->redactor->addLiteral( $secret );
		$this->assertSame( 'raw [redacted] end', $this->redactor->scrub( 'raw ' . $secret . ' end' ) );
		// rawurlencode(): %20 and a literal ~; urlencode(): + and %7E.
		$this->assertSame( 'next=[redacted]&x=1', $this->redactor->scrub( 'next=' . rawurlencode( $secret ) . '&x=1' ) );
		$this->assertSame( 'body [redacted] end', $this->redactor->scrub( 'body ' . urlencode( $secret ) . ' end' ) );
		// A URL inside a URL parameter, e.g. a redirect target carrying a refresh token.
		$refresh = '1//0gOpaqueRefresh-value_123';
		$this->redactor->addLiteral( $refresh );
		$nested  = 'https://example.test/cb?next=' . rawurlencode( 'https://x.test/?rt=' . $refresh );
		$this->assertStringNotContainsString( rawurlencode( $refresh ), $this->redactor->scrub( $nested ) );
		$this->assertStringNotContainsString( '0gOpaque', $this->redactor->scrub( $nested ) );

		// Too short to be a secret: ignored, also encoded.
		$this->redactor->addLiteral( 'a b' );
		$this->assertSame( 'a b a%20b a+b', $this->redactor->scrub( 'a b a%20b a+b' ) );
	}

	public function testGoogleTokenShapesInEncodedContexts() {
		$this->assertSame( 'client_secret%3D[redacted]', $this->redactor->scrub( 'client_secret%3DGOCSPX-abcdefghijklmn' ) );
		$this->assertSame( 'refresh_token%22%3A%22[redacted]%22', $this->redactor->scrub( 'refresh_token%22%3A%221%2F%2F0gAbCdefghijklmn%22' ) );
		$this->assertSame( 'x%3D[redacted]%26y', $this->redactor->scrub( 'x%3D1%2f%2f0gAbCdefghijklmn%26y' ) );
		$this->assertSame( 'code%3D[redacted]%26scope', $this->redactor->scrub( 'code%3D4%2F0AX4XfWh-abc_DEF%26scope' ) );
		$this->assertSame( 'access%3D[redacted]', $this->redactor->scrub( 'access%3Dya29.a0AfB_byC-xyz' ) );
		// A client secret glued to other text.
		$this->assertScrubbed( 'secretisGOCSPX-AbCdEf_1234567', array( 'GOCSPX-AbCdEf' ) );
		// Still no match inside ordinary words and paths.
		foreach ( array( 'Kenya29.5 km', 'page%201//comment', 'v1%2F%2F', 'wp-content/uploads/41//x' ) as $text ) {
			$this->assertSame( $text, $this->redactor->scrub( $text ) );
		}
	}

	public function testPatternsStayLinearOnAdversarialInput() {
		$inputs = array(
			str_repeat( '%3D', 300000 ),
			str_repeat( '%2F1%2F', 150000 ),
			str_repeat( '%3D1%2F%2F', 100000 ),
			str_repeat( 'GOCSPX-', 150000 ),
			str_repeat( '%3Dya29.', 110000 ),
		);
		$start = microtime( true );
		foreach ( $inputs as $input ) {
			$this->assertIsString( $this->redactor->scrub( $input ) );
			$this->assertSame( PREG_NO_ERROR, preg_last_error() );
		}
		// Linear patterns need a fraction of a second; backtracking would take minutes.
		$this->assertLessThan( 5.0, microtime( true ) - $start );
	}

	public function testNoFalsePositives() {
		$unchanged = array(
			'{"error":{"code":403,"message":"The user has exceeded their Drive storage quota","errors":[{"reason":"storageQuotaExceeded"}]}}',
			'"code":403',
			'"code": 404',
			'HTTP 200, status code 404',
			'upload chunk 262144 bytes; Range: bytes=0-262143',
			'Content-Range: bytes 0-262143/73400320',
			'{"id":"1AbCdEfGhIjKlMnOpQrStUvWxYz012345","name":"site-20260928.wpress","size":"123456"}',
			'Deleted Drive file 1AbCdEfGhIjKlMnOpQrStUvWxYz012345',
			'grant_type=authorization_code',
			'POST https://oauth2.googleapis.com/token grant_type=authorization_code',
			'wp-content/uploads/4/x.jpg',
			'wp-content/uploads/4/abcdefghijklmnop.jpg',
			'{"error":"invalid_grant","error_description":"Token has been expired or revoked."}',
			'"token_type":"Bearer"',
			'scope=https://www.googleapis.com/auth/drive.file',
			'Uploaded 8388608 of 73400320 bytes (11.4%)',
		);
		foreach ( $unchanged as $text ) {
			$this->assertSame( $text, $this->redactor->scrub( $text ) );
		}
	}

	public function testExistingPatternsStillWork() {
		$this->redactor->addLiteral( 'my-database-password' );
		$this->assertSame( 'connecting with [redacted] now', $this->redactor->scrub( 'connecting with my-database-password now' ) );
		$this->assertScrubbed( 'api_key=sk_live_1234567890', array( 'sk_live_1234567890' ) );
		$this->assertScrubbed( '"password": "hunter2"', array( 'hunter2' ) );
		$this->assertSame( 'Authorization: Bearer [redacted]', $this->redactor->scrub( 'Authorization: Bearer abcdef' ) );
		$this->assertScrubbed( 'mysql://user:topsecret@localhost/db', array( 'topsecret' ) );
		$this->assertSame( 'nothing sensitive here', $this->redactor->scrub( 'nothing sensitive here' ) );
	}
}
