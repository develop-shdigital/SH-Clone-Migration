<?php
/**
 * Archive encryption.
 *
 * @package SHCM
 */

namespace SHCM\Crypto;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Authenticated per-block encryption for archive payloads.
 *
 * Nothing here is home grown: XChaCha20-Poly1305 from libsodium when it is
 * available, AES-256-GCM through OpenSSL otherwise, with the key derived from
 * the migration password using Argon2id (libsodium) or PBKDF2-HMAC-SHA256.
 * The password itself is never written anywhere.
 */
class Cipher {

	const CIPHER_XCHACHA = 'xchacha20poly1305-ietf';
	const CIPHER_AESGCM  = 'aes-256-gcm';
	const KDF_ARGON2ID   = 'argon2id';
	const KDF_PBKDF2     = 'pbkdf2-sha256';
	const PBKDF2_ROUNDS  = 210000;
	const CHECK_PLAIN    = 'shcm-archive-key-check-v1';

	/**
	 * Derived binary key.
	 *
	 * @var string
	 */
	protected $key;

	/**
	 * Cipher identifier.
	 *
	 * @var string
	 */
	protected $cipher;

	/**
	 * Constructor.
	 *
	 * @param string $key    Raw 32 byte key.
	 * @param string $cipher Cipher identifier.
	 */
	public function __construct( $key, $cipher ) {
		$this->key    = $key;
		$this->cipher = $cipher;
	}

	/**
	 * Whether any cipher is available on this server.
	 *
	 * @return bool
	 */
	public static function isAvailable() {
		return function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			|| ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) );
	}

	/**
	 * Preferred cipher identifier for this server.
	 *
	 * @return string
	 */
	public static function preferredCipher() {
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			return self::CIPHER_XCHACHA;
		}
		return self::CIPHER_AESGCM;
	}

	/**
	 * Preferred key derivation function for this server.
	 *
	 * @return string
	 */
	public static function preferredKdf() {
		if ( function_exists( 'sodium_crypto_pwhash' ) && defined( 'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13' ) ) {
			return self::KDF_ARGON2ID;
		}
		return self::KDF_PBKDF2;
	}

	/**
	 * Build the encryption descriptor stored in an archive prologue.
	 *
	 * @param string $password Migration password.
	 * @return array{params:array,cipher:Cipher}
	 * @throws \RuntimeException When no cipher is available.
	 */
	public static function initialise( $password ) {
		if ( ! self::isAvailable() ) {
			throw new \RuntimeException( 'No supported cipher is available on this server.' );
		}

		$params = array(
			'cipher' => self::preferredCipher(),
			'kdf'    => self::preferredKdf(),
			'salt'   => bin2hex( random_bytes( 16 ) ),
		);

		if ( self::KDF_ARGON2ID === $params['kdf'] ) {
			$params['ops'] = defined( 'SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE' ) ? SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE : 2;
			$params['mem'] = defined( 'SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE' ) ? SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE : 67108864;
		} else {
			$params['rounds'] = self::PBKDF2_ROUNDS;
		}

		$key    = self::deriveKey( $password, $params );
		$cipher = new self( $key, $params['cipher'] );

		// Password verification token: lets the importer reject a wrong
		// password immediately instead of producing garbage output.
		$params['check'] = base64_encode( $cipher->encrypt( self::CHECK_PLAIN ) );

		return array(
			'params' => $params,
			'cipher' => $cipher,
		);
	}

	/**
	 * Rebuild a cipher from stored parameters and a password.
	 *
	 * @param string $password Migration password.
	 * @param array  $params   Parameters from the archive prologue.
	 * @return self
	 * @throws \RuntimeException When the password is wrong or the cipher is unsupported.
	 */
	public static function fromParams( $password, array $params ) {
		$cipher_id = isset( $params['cipher'] ) ? $params['cipher'] : self::CIPHER_XCHACHA;
		if ( self::CIPHER_XCHACHA === $cipher_id && ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			throw new \RuntimeException( 'This archive is encrypted with XChaCha20-Poly1305 but libsodium is not available on this server.' );
		}
		if ( self::CIPHER_AESGCM === $cipher_id && ! function_exists( 'openssl_decrypt' ) ) {
			throw new \RuntimeException( 'This archive is encrypted with AES-256-GCM but OpenSSL is not available on this server.' );
		}

		$key    = self::deriveKey( $password, $params );
		$cipher = new self( $key, $cipher_id );

		if ( ! empty( $params['check'] ) ) {
			$decoded = base64_decode( $params['check'], true );
			if ( false === $decoded ) {
				throw new \RuntimeException( 'The archive encryption header is malformed.' );
			}
			$plain = $cipher->decryptOrNull( $decoded );
			if ( self::CHECK_PLAIN !== $plain ) {
				throw new \RuntimeException( 'The migration password is incorrect.' );
			}
		}

		return $cipher;
	}

	/**
	 * Derive the binary key.
	 *
	 * @param string $password Password.
	 * @param array  $params   KDF parameters.
	 * @return string 32 raw bytes.
	 * @throws \RuntimeException When the KDF is unsupported.
	 */
	public static function deriveKey( $password, array $params ) {
		$salt = isset( $params['salt'] ) ? hex2bin( $params['salt'] ) : '';
		if ( false === $salt || '' === $salt ) {
			throw new \RuntimeException( 'The archive encryption salt is missing.' );
		}
		$kdf = isset( $params['kdf'] ) ? $params['kdf'] : self::KDF_PBKDF2;

		if ( self::KDF_ARGON2ID === $kdf ) {
			if ( ! function_exists( 'sodium_crypto_pwhash' ) ) {
				throw new \RuntimeException( 'This archive needs Argon2id (libsodium), which is not available on this server.' );
			}
			$salt = substr( str_pad( $salt, SODIUM_CRYPTO_PWHASH_SALTBYTES, "\0" ), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES );
			return sodium_crypto_pwhash(
				32,
				$password,
				$salt,
				isset( $params['ops'] ) ? (int) $params['ops'] : 2,
				isset( $params['mem'] ) ? (int) $params['mem'] : 67108864,
				SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
			);
		}

		$rounds = isset( $params['rounds'] ) ? (int) $params['rounds'] : self::PBKDF2_ROUNDS;
		return hash_pbkdf2( 'sha256', $password, $salt, $rounds, 32, true );
	}

	/**
	 * Encrypt one block.
	 *
	 * @param string $plaintext Plain data.
	 * @return string nonce || ciphertext
	 */
	public function encrypt( $plaintext ) {
		if ( self::CIPHER_XCHACHA === $this->cipher ) {
			$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, '', $nonce, $this->key );
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $ct ) {
			throw new \RuntimeException( 'Block encryption failed.' );
		}
		return $iv . $tag . $ct;
	}

	/**
	 * Decrypt one block.
	 *
	 * @param string $blob Encrypted block.
	 * @return string
	 * @throws \RuntimeException When authentication fails.
	 */
	public function decrypt( $blob ) {
		$plain = $this->decryptOrNull( $blob );
		if ( null === $plain ) {
			throw new \RuntimeException( 'Archive block failed authentication: wrong password or corrupted archive.' );
		}
		return $plain;
	}

	/**
	 * Decrypt one block, returning null instead of throwing.
	 *
	 * @param string $blob Encrypted block.
	 * @return string|null
	 */
	public function decryptOrNull( $blob ) {
		if ( self::CIPHER_XCHACHA === $this->cipher ) {
			$nlen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
			if ( strlen( $blob ) <= $nlen ) {
				return null;
			}
			$nonce = substr( $blob, 0, $nlen );
			$ct    = substr( $blob, $nlen );
			$plain = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ct, '', $nonce, $this->key );
			return false === $plain ? null : $plain;
		}
		if ( strlen( $blob ) <= 28 ) {
			return null;
		}
		$iv    = substr( $blob, 0, 12 );
		$tag   = substr( $blob, 12, 16 );
		$ct    = substr( $blob, 28 );
		$plain = openssl_decrypt( $ct, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? null : $plain;
	}

	/**
	 * Wipe the key from memory when possible.
	 */
	public function __destruct() {
		if ( function_exists( 'sodium_memzero' ) && is_string( $this->key ) ) {
			try {
				sodium_memzero( $this->key );
			} catch ( \Exception $e ) {
				$this->key = '';
			}
		}
	}
}
