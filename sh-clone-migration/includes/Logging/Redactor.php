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
	 * @param string $value Secret value.
	 * @return void
	 */
	public function addLiteral( $value ) {
		$value = (string) $value;
		if ( strlen( $value ) >= 4 ) {
			$this->literals[] = $value;
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
	public function scrub( $message ) {
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
		);
		$replacements = array( '$1' . self::MASK, '$1' . self::MASK, self::MASK, '$1' . self::MASK . '$3' );

		$scrubbed = preg_replace( $patterns, $replacements, $message );

		return null === $scrubbed ? $message : $scrubbed;
	}
}
