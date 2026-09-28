<?php
/**
 * Encryption of small secrets at rest.
 *
 * @package SHCM
 */

namespace SHCM\Security;

use SHCM\Crypto\Cipher;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Seals the few secrets the plugin has to keep between requests: the Google
 * Drive client secret and tokens, an upload session URI, the password of
 * encrypted scheduled backups.
 *
 * The key is derived with HKDF-SHA256 from the salts in wp-config.php, which
 * this plugin never exports and an import never overwrites. A sealed value
 * copied to another site (or read from a backup of the database) cannot be
 * opened there: it simply reads as "not set". Each value is sealed under its
 * own context, so one sealed field cannot be swapped into another.
 *
 * The salts are high-entropy random strings, so a fast KDF is the right tool
 * here; the slow password KDFs in Cipher are for human passwords.
 */
final class SecretBox {

	const PREFIX = 'shcm1';

	/**
	 * Values wp-config-sample.php ships with.
	 */
	const PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Master key material.
	 *
	 * @var string
	 */
	private $master;

	/**
	 * Whether the key had to come from salts stored in the database.
	 *
	 * @var bool
	 */
	private $database_derived;

	/**
	 * Constructor.
	 *
	 * @param string $key_material     High-entropy key material.
	 * @param bool   $database_derived Whether it came from the database.
	 * @throws \InvalidArgumentException When there is no usable key material.
	 */
	public function __construct( $key_material, $database_derived = false ) {
		$key_material = (string) $key_material;
		if ( strlen( $key_material ) < 32 ) {
			throw new \InvalidArgumentException( 'SecretBox needs at least 32 bytes of key material.' );
		}
		$this->master           = hash_hkdf( 'sha256', $key_material, 32, 'shcm:secretbox:master:v1' );
		$this->database_derived = (bool) $database_derived;
	}

	/**
	 * Build the box from the site's wp-config.php.
	 *
	 * An explicit SHCM_SECRET_KEY constant wins. Otherwise the four secret
	 * keys (not the salts, which WordPress also uses for cookies and nonces
	 * that other code can observe) are combined. Missing or placeholder
	 * values fall back to wp_salt(), whose values then live in the database.
	 *
	 * @return self
	 */
	public static function fromWordPress() {
		if ( defined( 'SHCM_SECRET_KEY' ) && strlen( (string) SHCM_SECRET_KEY ) >= 32 ) {
			return new self( (string) SHCM_SECRET_KEY );
		}

		$material = '';
		$complete = true;
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $name ) {
			$value = defined( $name ) ? (string) constant( $name ) : '';
			if ( strlen( $value ) < 32 || self::PLACEHOLDER === $value ) {
				$complete = false;
				continue;
			}
			$material .= $name . '=' . $value . "\n";
		}
		if ( $complete ) {
			return new self( $material );
		}

		// Salts missing from wp-config.php: WordPress generates them and keeps
		// them in the options table. That still keeps sealed values away from
		// anyone reading only the config files, but not from a database copy.
		$fallback = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) : '';
		if ( strlen( $fallback ) < 32 ) {
			$fallback = $material . ( defined( 'ABSPATH' ) ? ABSPATH : __DIR__ ) . str_repeat( '#', 32 );
		}
		return new self( $material . $fallback, true );
	}

	/**
	 * Whether the key depends on salts stored in the database.
	 *
	 * @return bool
	 */
	public function isDatabaseDerived() {
		return $this->database_derived;
	}

	/**
	 * Seal a value.
	 *
	 * @param string $plain   Secret.
	 * @param string $context What the value is (binds the ciphertext to it).
	 * @return string Printable sealed value.
	 * @throws \RuntimeException When no cipher is available.
	 */
	public function seal( $plain, $context ) {
		if ( ! Cipher::isAvailable() ) {
			throw new \RuntimeException( 'Neither libsodium nor OpenSSL is available to protect secrets.' );
		}
		$cipher_id = Cipher::preferredCipher();
		$cipher    = new Cipher( $this->key( $context ), $cipher_id );
		return self::PREFIX . ':' . $cipher_id . ':' . base64_encode( $cipher->encrypt( (string) $plain ) );
	}

	/**
	 * Open a sealed value.
	 *
	 * @param string $sealed  Sealed value.
	 * @param string $context Context it was sealed under.
	 * @return string|null Null when absent, damaged, sealed elsewhere or under another context.
	 */
	public function open( $sealed, $context ) {
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return null;
		}
		$parts = explode( ':', $sealed, 3 );
		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
			return null;
		}
		if ( ! in_array( $parts[1], array( Cipher::CIPHER_XCHACHA, Cipher::CIPHER_AESGCM ), true ) ) {
			return null;
		}
		if ( Cipher::CIPHER_XCHACHA === $parts[1] && ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			return null;
		}
		if ( Cipher::CIPHER_AESGCM === $parts[1] && ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$blob = base64_decode( $parts[2], true );
		if ( false === $blob ) {
			return null;
		}
		$cipher = new Cipher( $this->key( $context ), $parts[1] );
		return $cipher->decryptOrNull( $blob );
	}

	/**
	 * Keyed MAC, used for tokens the server has to recompute (loopback requests).
	 *
	 * @param string $data Data.
	 * @return string 64 hex characters.
	 */
	public function mac( $data ) {
		// Not key( 'mac' ): an info string outside the "shcm:secretbox:<context>:v1"
		// family keeps the HMAC key distinct from every seal() key, whatever
		// context a caller picks.
		return hash_hmac( 'sha256', (string) $data, hash_hkdf( 'sha256', $this->master, 32, 'shcm:secretbox-mac:v1' ) );
	}

	/**
	 * Per-context key.
	 *
	 * @param string $context Context.
	 * @return string 32 raw bytes.
	 */
	private function key( $context ) {
		return hash_hkdf( 'sha256', $this->master, 32, 'shcm:secretbox:' . (string) $context . ':v1' );
	}
}
