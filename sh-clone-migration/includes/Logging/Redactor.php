<?php
/**
 * Log redaction.
 *
 * @package SHCM
 */

namespace SHCM\Logging;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Strips credentials and secrets out of anything on its way into a log file.
 *
 * Migration logs are downloadable by administrators and are frequently pasted
 * into support tickets, so this runs on every single line.
 */
class Redactor {

	const MASK = '[redacted]';

	/**
	 * Literal secrets registered at runtime (database password, salts, ...).
	 *
	 * @var string[]
	 */
	protected $literals = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->collectRuntimeSecrets();
	}

	/**
	 * Register a literal string that must never appear in a log.
	 *
	 * The URL-encoded forms are registered too: secrets travel in form bodies,
	 * query strings and URLs nested inside other URLs, where the token-shape
	 * patterns below cannot recognise every value.
	 *
	 * @param string $value Secret value.
	 * @return void
	 */
	public function addLiteral(
		#[\SensitiveParameter]
		$value
	) {
		$value = (string) $value;
		if ( strlen( $value ) < 4 ) {
			return;
		}
		foreach ( array( $value, rawurlencode( $value ), urlencode( $value ) ) as $variant ) {
			if ( ! in_array( $variant, $this->literals, true ) ) {
				$this->literals[] = $variant;
			}
		}
	}

	/**
	 * Collect secrets that are known from the WordPress configuration.
	 *
	 * @return void
	 */
	protected function collectRuntimeSecrets() {
		$constants = array(
			'DB_PASSWORD',
			'DB_USER',
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
			// Master key of the SecretBox and the Google OAuth client secret, when set in wp-config.php.
			'SHCM_SECRET_KEY',
			'SHCM_GDRIVE_CLIENT_SECRET',
		);
		foreach ( $constants as $constant ) {
			if ( defined( $constant ) ) {
				$this->addLiteral( (string) constant( $constant ) );
			}
		}
	}

	/**
	 * Redact a message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public function scrub(
		#[\SensitiveParameter]
		$message
	) {
		$message = (string) $message;

		foreach ( $this->literals as $literal ) {
			if ( '' !== $literal ) {
				$message = str_replace( $literal, self::MASK, $message );
			}
		}

		$patterns = array(
			// key=value / "key": "value" pairs for sensitive names.
			'#((?:pass|password|passwd|pwd|secret|token|api[_-]?key|apikey|private[_-]?key|auth|salt|nonce|credential|bearer)[\'"]?\s*[:=]\s*[\'"]?)([^\s,;\'"&]{3,})#i',
			// Authorization headers.
			'#(Authorization:\s*\w+\s+)([A-Za-z0-9\.\-_=]+)#i',
			// Anything that looks like a PEM block.
			'#-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----#s',
			// mysql://user:pass@host style URIs.
			'#(mysqli?://[^:/\s]+:)([^@\s]+)(@)#i',
			// Any Bearer credential (RFC 6750 b64token, which may contain "/" as in "1//...").
			'#(\bBearer\s+)([A-Za-z0-9\-._~+/]+=*)#i',
			// Authorization in any form, including JSON {"Authorization":"Bearer ..."}.
			'#(\bAuthorization[\'"]?\s*[:=]\s*[\'"]?(?:[A-Za-z]+\s+)?)([^\s,;\'"&]+)#i',
			// OAuth codes, assertions and upload session references. The 8 character
			// minimum keeps numeric values such as "code":403 readable.
			'#(\b(?:code|authorization_code|code_verifier|upload_id|id_token|assertion|client_assertion|session_uri|upload_url)[\'"]?\s*[:=]\s*[\'"]?)([^\s,;\'"&]{8,})#i',
			// Google token shapes, also in free text and stack traces: access token,
			// refresh token, authorization code, client secret. They may also start
			// right after a percent-encoded character ("%3DGOCSPX-...", where \b does
			// not match) and carry encoded slashes ("1%2F%2F0...").
			'#(?:\b|(?<=%[0-9A-Fa-f]{2}))ya29\.[A-Za-z0-9\-_.]+#',
			'#(?:\b|(?<=%[0-9A-Fa-f]{2}))1(?:/|%2[Ff]){2}[A-Za-z0-9\-_]{10,}#',
			'#(?:\b|(?<=%[0-9A-Fa-f]{2}))4(?:/|%2[Ff])0[A-Za-z0-9\-_]{10,}#',
			'#(?:\b|(?<=%[0-9A-Fa-f]{2}))GOCSPX-[A-Za-z0-9\-_]{10,}#',
			// A resumable upload session URI is itself a credential.
			'#([?&]upload_id=)[^\s&\'"]+#i',
		);
		$replacements = array(
			'$1' . self::MASK,
			'$1' . self::MASK,
			self::MASK,
			'$1' . self::MASK . '$3',
			'$1' . self::MASK,
			'$1' . self::MASK,
			'$1' . self::MASK,
			self::MASK,
			self::MASK,
			self::MASK,
			self::MASK,
			'$1' . self::MASK,
		);

		$scrubbed = preg_replace( $patterns, $replacements, $message );

		return null === $scrubbed ? $message : $scrubbed;
	}
}
