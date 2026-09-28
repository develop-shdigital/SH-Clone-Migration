<?php
/**
 * Tests of the foundation classes of scheduled backups.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SHCM\Backup\ConfigStore;
use SHCM\Backup\History;
use SHCM\Backup\SiteIdentity;
use SHCM\Crypto\Cipher;
use SHCM\Filesystem\Storage;
use SHCM\Security\SecretBox;

/**
 * SecretBox (sealed secrets), ConfigStore (guarded JSON documents with
 * locked updates), History (backup run records) and SiteIdentity.
 */
class BackupFoundationTest extends TestCase {

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Create the scratch directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/shcm-foundation-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir, 0755, true );
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
	 * A store in a not yet existing sub directory.
	 *
	 * @return ConfigStore
	 */
	private function store() {
		return new ConfigStore( $this->dir . '/config' );
	}

	/**
	 * The per-context key SecretBox derives, spelled out here on purpose:
	 * changing the derivation would make every stored secret unreadable, so
	 * the test pins it.
	 *
	 * @param string $material Key material.
	 * @param string $context  Context.
	 * @return string
	 */
	private static function derivedKey( $material, $context ) {
		$master = hash_hkdf( 'sha256', $material, 32, 'shcm:secretbox:master:v1' );
		return hash_hkdf( 'sha256', $master, 32, 'shcm:secretbox:' . $context . ':v1' );
	}

	/* ------------------------------------------------------------------
	 * SecretBox
	 * ------------------------------------------------------------------ */

	public function testSealOpenRoundTrip() {
		$box = new SecretBox( str_repeat( 'k', 32 ) );
		foreach ( array( 'refresh-token', 'ümlaut ✓', random_bytes( 64 ), str_repeat( 'x', 100000 ), '0' ) as $plain ) {
			$sealed = $box->seal( $plain, 'gdrive-refresh-token' );
			$this->assertMatchesRegularExpression( '/^shcm1:(xchacha20poly1305-ietf|aes-256-gcm):[A-Za-z0-9+\/]+=*$/', $sealed );
			$this->assertStringNotContainsString( 'refresh-token', $sealed );
			$this->assertSame( $plain, $box->open( $sealed, 'gdrive-refresh-token' ) );
		}
		// Random nonces: the same value seals differently every time.
		$this->assertNotSame( $box->seal( 'same', 'c' ), $box->seal( 'same', 'c' ) );
		$this->assertFalse( $box->isDatabaseDerived() );
		$this->assertTrue( ( new SecretBox( str_repeat( 'k', 32 ), true ) )->isDatabaseDerived() );
	}

	public function testSealedValueIsBoundToItsContext() {
		$box    = new SecretBox( str_repeat( 'k', 40 ) );
		$sealed = $box->seal( 'client-secret', 'gdrive-client-secret' );
		$this->assertNull( $box->open( $sealed, 'gdrive-refresh-token' ) );
		$this->assertNull( $box->open( $sealed, '' ) );
		$this->assertNull( $box->open( $sealed, 'gdrive-client-secret ' ) );
		$this->assertSame( 'client-secret', $box->open( $sealed, 'gdrive-client-secret' ) );
	}

	public function testAnotherKeyCannotOpen() {
		$sealed = ( new SecretBox( str_repeat( 'a', 64 ) ) )->seal( 'secret', 'ctx' );
		$this->assertNull( ( new SecretBox( str_repeat( 'b', 64 ) ) )->open( $sealed, 'ctx' ) );
		$this->assertNull( ( new SecretBox( str_repeat( 'a', 63 ) . 'b' ) )->open( $sealed, 'ctx' ) );
		// The same material on another instance (another request) can.
		$this->assertSame( 'secret', ( new SecretBox( str_repeat( 'a', 64 ) ) )->open( $sealed, 'ctx' ) );
	}

	public function testTamperedValuesDoNotOpen() {
		$box    = new SecretBox( str_repeat( 'k', 32 ) );
		$sealed = $box->seal( 'a secret worth protecting', 'ctx' );
		$parts  = explode( ':', $sealed, 3 );
		$blob   = base64_decode( $parts[2], true );

		foreach ( array( 0, 10, (int) ( strlen( $blob ) / 2 ), strlen( $blob ) - 1 ) as $position ) {
			$changed              = $blob;
			$changed[ $position ] = chr( ord( $changed[ $position ] ) ^ 0x01 );
			$this->assertNull( $box->open( $parts[0] . ':' . $parts[1] . ':' . base64_encode( $changed ), 'ctx' ), 'bit flipped at ' . $position );
		}
		$this->assertNull( $box->open( $parts[0] . ':' . $parts[1] . ':' . base64_encode( substr( $blob, 0, -1 ) ), 'ctx' ) );
		$this->assertNull( $box->open( $parts[0] . ':' . $parts[1] . ':' . base64_encode( $blob . 'x' ), 'ctx' ) );

		// Relabelling the cipher does not help either.
		$other = Cipher::CIPHER_XCHACHA === $parts[1] ? Cipher::CIPHER_AESGCM : Cipher::CIPHER_XCHACHA;
		$this->assertNull( $box->open( $parts[0] . ':' . $other . ':' . $parts[2], 'ctx' ) );
	}

	/**
	 * Malformed input.
	 *
	 * @return array
	 */
	public static function garbage() {
		$b64 = base64_encode( str_repeat( 'z', 80 ) );
		return array(
			'null'             => array( null ),
			'int'              => array( 42 ),
			'array'            => array( array( 'shcm1' ) ),
			'empty'            => array( '' ),
			'prefix only'      => array( 'shcm1' ),
			'no payload'       => array( 'shcm1:xchacha20poly1305-ietf' ),
			'empty payload'    => array( 'shcm1:xchacha20poly1305-ietf:' ),
			'empty gcm'        => array( 'shcm1:aes-256-gcm:' ),
			'wrong prefix'     => array( 'shcm2:xchacha20poly1305-ietf:' . $b64 ),
			'no prefix'        => array( 'xchacha20poly1305-ietf:' . $b64 ),
			'unknown cipher'   => array( 'shcm1:rot13:' . $b64 ),
			'cipher case'      => array( 'shcm1:AES-256-GCM:' . $b64 ),
			'not base64'       => array( 'shcm1:xchacha20poly1305-ietf:!!!not base64!!!' ),
			'too short'        => array( 'shcm1:xchacha20poly1305-ietf:' . base64_encode( 'short' ) ),
			'too short gcm'    => array( 'shcm1:aes-256-gcm:' . base64_encode( str_repeat( 'g', 28 ) ) ),
			'random xchacha'   => array( 'shcm1:xchacha20poly1305-ietf:' . $b64 ),
			'random gcm'       => array( 'shcm1:aes-256-gcm:' . $b64 ),
			'plain text'       => array( 'my-client-secret' ),
		);
	}

	/**
	 * Open never throws and returns null for anything malformed.
	 *
	 * @param mixed $sealed Input.
	 */
	#[DataProvider( 'garbage' )]
	public function testGarbageOpensAsNull( $sealed ) {
		$this->assertNull( ( new SecretBox( str_repeat( 'k', 32 ) ) )->open( $sealed, 'ctx' ) );
	}

	public function testKeyDerivationIsStable() {
		$material = str_repeat( 'm', 48 );
		$box      = new SecretBox( $material );
		$sealed   = $box->seal( 'pinned', 'backup-password' );
		$parts    = explode( ':', $sealed, 3 );
		$cipher   = new Cipher( self::derivedKey( $material, 'backup-password' ), $parts[1] );
		$this->assertSame( 'pinned', $cipher->decryptOrNull( base64_decode( $parts[2], true ) ) );
	}

	/**
	 * Servers without libsodium seal with AES-256-GCM. preferredCipher() is
	 * static and cannot be switched off here, so the blob is produced with
	 * Cipher directly, using the key SecretBox derives.
	 */
	public function testOpensAesGcmValues() {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) ) {
			$this->markTestSkipped( 'OpenSSL with AES-256-GCM is not available.' );
		}
		$material = str_repeat( 'q', 40 );
		$cipher   = new Cipher( self::derivedKey( $material, 'gdrive-refresh-token' ), Cipher::CIPHER_AESGCM );
		$sealed   = 'shcm1:aes-256-gcm:' . base64_encode( $cipher->encrypt( '1//refresh-token' ) );
		$box      = new SecretBox( $material );

		$this->assertSame( '1//refresh-token', $box->open( $sealed, 'gdrive-refresh-token' ) );
		$this->assertNull( $box->open( $sealed, 'gdrive-access-token' ) );
		$this->assertNull( ( new SecretBox( str_repeat( 'r', 40 ) ) )->open( $sealed, 'gdrive-refresh-token' ) );
		$this->assertNull( $box->open( 'shcm1:xchacha20poly1305-ietf:' . explode( ':', $sealed, 3 )[2], 'gdrive-refresh-token' ) );
	}

	/**
	 * An empty value seals to a 28 byte AES-GCM block (IV and tag, no
	 * ciphertext), which Cipher used to reject as too short.
	 */
	public function testAesGcmEmptyPlaintextRoundTrips() {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) ) {
			$this->markTestSkipped( 'OpenSSL with AES-256-GCM is not available.' );
		}
		$cipher = new Cipher( str_repeat( "\x42", 32 ), Cipher::CIPHER_AESGCM );
		$blob   = $cipher->encrypt( '' );
		$this->assertSame( 28, strlen( $blob ) );
		$this->assertSame( '', $cipher->decryptOrNull( $blob ) );
		$this->assertSame( '', $cipher->decrypt( $blob ) );

		// Still authenticated: a flipped tag bit, another key, or a block
		// shorter than IV plus tag does not open.
		$changed     = $blob;
		$changed[20] = chr( ord( $changed[20] ) ^ 0x01 );
		$this->assertNull( $cipher->decryptOrNull( $changed ) );
		$this->assertNull( ( new Cipher( str_repeat( "\x43", 32 ), Cipher::CIPHER_AESGCM ) )->decryptOrNull( $blob ) );
		$this->assertNull( $cipher->decryptOrNull( substr( $blob, 0, 27 ) ) );
		$this->assertNull( $cipher->decryptOrNull( str_repeat( 'g', 28 ) ) );
		$this->assertNull( $cipher->decryptOrNull( '' ) );

		// One byte of plaintext is unaffected.
		$this->assertSame( 'x', $cipher->decryptOrNull( $cipher->encrypt( 'x' ) ) );

		// Through SecretBox, as on a server without libsodium.
		$material = str_repeat( 'e', 40 );
		$sealed   = 'shcm1:aes-256-gcm:' . base64_encode( ( new Cipher( self::derivedKey( $material, 'backup-password' ), Cipher::CIPHER_AESGCM ) )->encrypt( '' ) );
		$this->assertSame( '', ( new SecretBox( $material ) )->open( $sealed, 'backup-password' ) );
	}

	public function testEmptyValueRoundTripsWithThePreferredCipher() {
		$box = new SecretBox( str_repeat( 'k', 32 ) );
		$this->assertSame( '', $box->open( $box->seal( '', 'ctx' ), 'ctx' ) );
	}

	public function testMac() {
		$box = new SecretBox( str_repeat( 'k', 32 ) );
		$mac = $box->mac( 'tick|job-123' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $mac );
		$this->assertSame( $mac, $box->mac( 'tick|job-123' ) );
		$this->assertSame( $mac, ( new SecretBox( str_repeat( 'k', 32 ) ) )->mac( 'tick|job-123' ) );
		$this->assertNotSame( $mac, $box->mac( 'tick|job-124' ) );
		$this->assertNotSame( $mac, ( new SecretBox( str_repeat( 'j', 32 ) ) )->mac( 'tick|job-123' ) );
		// Not a plain HMAC with the key material.
		$this->assertNotSame( $mac, hash_hmac( 'sha256', 'tick|job-123', str_repeat( 'k', 32 ) ) );
		$this->assertSame( $box->mac( '123' ), $box->mac( 123 ) );
	}

	/**
	 * The MAC key has its own HKDF info string, outside the family used for
	 * seal() contexts, so no context can ever make an AEAD key double as the
	 * HMAC key. The derivation is pinned: loopback tokens are recomputed
	 * from it on every request.
	 */
	public function testMacKeyIsSeparateFromEverySealKey() {
		$material = str_repeat( 'k', 32 );
		$box      = new SecretBox( $material );
		$master   = hash_hkdf( 'sha256', $material, 32, 'shcm:secretbox:master:v1' );
		$expected = hash_hmac( 'sha256', 'tick|job-123', hash_hkdf( 'sha256', $master, 32, 'shcm:secretbox-mac:v1' ) );
		$this->assertSame( $expected, $box->mac( 'tick|job-123' ) );

		// Not the key of the seal() context "mac" (the old derivation), nor
		// of any other context.
		foreach ( array( 'mac', '', 'backup-password', 'gdrive-refresh-token' ) as $context ) {
			$this->assertNotSame( hash_hmac( 'sha256', 'tick|job-123', self::derivedKey( $material, $context ) ), $box->mac( 'tick|job-123' ), $context );
		}

		// seal() and open() are unchanged, the context "mac" included, so
		// values sealed before still open.
		foreach ( array( 'mac', 'backup-password' ) as $context ) {
			$sealed = $box->seal( 'still readable', $context );
			$parts  = explode( ':', $sealed, 3 );
			$cipher = new Cipher( self::derivedKey( $material, $context ), $parts[1] );
			$this->assertSame( 'still readable', $cipher->decryptOrNull( base64_decode( $parts[2], true ) ) );
			$this->assertSame( 'still readable', $box->open( $sealed, $context ) );
		}
	}

	public function testConstructorRejectsShortKeyMaterial() {
		foreach ( array( '', 'short', str_repeat( 'k', 31 ), null ) as $material ) {
			try {
				new SecretBox( $material );
				$this->fail( 'Accepted ' . strlen( (string) $material ) . ' bytes of key material.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( '32 bytes', $e->getMessage() );
			}
		}
		$this->assertInstanceOf( SecretBox::class, new SecretBox( str_repeat( 'k', 32 ) ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFromWordPressPrefersAnExplicitKey() {
		define( 'SHCM_SECRET_KEY', str_repeat( 's', 40 ) );
		define( 'AUTH_KEY', str_repeat( 'a', 64 ) );
		$box = SecretBox::fromWordPress();
		$this->assertFalse( $box->isDatabaseDerived() );
		$this->assertSame( 'x', ( new SecretBox( str_repeat( 's', 40 ) ) )->open( $box->seal( 'x', 'c' ), 'c' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFromWordPressCombinesTheFourSecretKeys() {
		define( 'SHCM_SECRET_KEY', 'too short to be used' );
		define( 'AUTH_KEY', str_repeat( 'a', 64 ) );
		define( 'SECURE_AUTH_KEY', str_repeat( 'b', 64 ) );
		define( 'LOGGED_IN_KEY', str_repeat( 'c', 64 ) );
		define( 'NONCE_KEY', str_repeat( 'd', 64 ) );
		define( 'AUTH_SALT', str_repeat( 'e', 64 ) );

		$box      = SecretBox::fromWordPress();
		$material = 'AUTH_KEY=' . AUTH_KEY . "\nSECURE_AUTH_KEY=" . SECURE_AUTH_KEY . "\nLOGGED_IN_KEY=" . LOGGED_IN_KEY . "\nNONCE_KEY=" . NONCE_KEY . "\n";
		$this->assertFalse( $box->isDatabaseDerived() );
		$this->assertSame( 'x', ( new SecretBox( $material ) )->open( $box->seal( 'x', 'c' ), 'c' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFromWordPressWithPlaceholderKeysIsDatabaseDerived() {
		define( 'AUTH_KEY', 'put your unique phrase here' );
		define( 'SECURE_AUTH_KEY', 'put your unique phrase here' );
		define( 'LOGGED_IN_KEY', str_repeat( 'c', 64 ) );
		define( 'NONCE_KEY', str_repeat( 'd', 64 ) );

		$box = SecretBox::fromWordPress();
		$this->assertTrue( $box->isDatabaseDerived() );
		$this->assertSame( 'x', $box->open( $box->seal( 'x', 'c' ), 'c' ) );
		$this->assertSame( 'x', SecretBox::fromWordPress()->open( $box->seal( 'x', 'c' ), 'c' ) );
	}

	/* ------------------------------------------------------------------
	 * ConfigStore
	 * ------------------------------------------------------------------ */

	public function testReadMissingDocument() {
		$this->assertSame( array(), $this->store()->read( 'schedule' ) );
		$this->assertFalse( is_dir( $this->dir . '/config' ), 'reading must not create the directory' );
	}

	public function testWriteReadRoundTrip() {
		$store = $this->store();
		$data  = array(
			'config' => array(
				'frequency'  => 'daily',
				'exclusions' => array( 'wp-content/cache', 'ümlaut/ördner' ),
				'keep_local' => 0,
				'gdrive'     => true,
			),
			'state'  => array(
				'next_run' => null,
				'password' => 'shcm1:xchacha20poly1305-ietf:abc+/=',
				'url'      => 'https://example.com/a/b',
			),
		);
		$this->assertTrue( $store->write( 'schedule', $data ) );
		$this->assertSame( $data, $store->read( 'schedule' ) );
		$this->assertSame( $data, ( new ConfigStore( $this->dir . '/config/' ) )->read( 'schedule' ) );

		$this->assertTrue( $store->write( 'schedule', array( 'replaced' => true ) ) );
		$this->assertSame( array( 'replaced' => true ), $store->read( 'schedule' ) );
		$this->assertSame( array(), glob( $this->dir . '/config/*.tmp' ) );
	}

	public function testDocumentsAreGuardedAgainstDirectDownload() {
		$store = $this->store();
		$store->write( 'gdrive', array( 'token' => 'secret' ) );
		$path = $this->dir . '/config/gdrive.php';

		$this->assertSame( $path, $store->path( 'gdrive' ) );
		$raw = file_get_contents( $path );
		$this->assertStringStartsWith( "<?php exit; ?>\n", $raw );
		$this->assertSame( ConfigStore::GUARD, substr( $raw, 0, strlen( ConfigStore::GUARD ) ) );
		$this->assertSame( array( 'token' => 'secret' ), json_decode( substr( $raw, strlen( ConfigStore::GUARD ) ), true ) );

		// Executed as PHP, the document prints nothing.
		if ( function_exists( 'shell_exec' ) && '' !== PHP_BINARY ) {
			$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $path ) . ' 2>&1' );
			$this->assertSame( '', (string) $output );
		}

		$this->assertFileExists( $this->dir . '/config/index.php' );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $this->dir . '/config/.htaccess' ) );
	}

	public function testCorruptDocumentsReadAsEmpty() {
		$store = $this->store();
		$store->write( 'history', array( 'entries' => array() ) );
		$path = $store->path( 'history' );

		foreach ( array( ConfigStore::GUARD . '{"entries": [', ConfigStore::GUARD, '', ConfigStore::GUARD . '"a string"', ConfigStore::GUARD . '42', "\x00\xff\xfe binary" ) as $content ) {
			file_put_contents( $path, $content );
			$this->assertSame( array(), $store->read( 'history' ), var_export( $content, true ) );
		}

		// A document written by hand without the guard line is still read.
		file_put_contents( $path, '{"a":1}' );
		$this->assertSame( array( 'a' => 1 ), $store->read( 'history' ) );
	}

	/**
	 * Document names that must be refused.
	 *
	 * @return array
	 */
	public static function invalidNames() {
		return array(
			'upper case'       => array( 'Schedule' ),
			'underscore'       => array( 'sched_ule' ),
			'dot'              => array( 'a.b' ),
			'traversal'        => array( '../history' ),
			'slash'            => array( 'a/b' ),
			'backslash'        => array( 'a\\b' ),
			'empty'            => array( '' ),
			'leading hyphen'   => array( '-history' ),
			'too long'         => array( str_repeat( 'a', 65 ) ),
			'trailing newline' => array( "history\n" ),
			'nul'              => array( "history\0" ),
			'space'            => array( ' history' ),
			'null'             => array( null ),
			'int'              => array( 123 ),
		);
	}

	/**
	 * Every operation refuses a name that is not a plain slug.
	 *
	 * @param mixed $name Name.
	 */
	#[DataProvider( 'invalidNames' )]
	public function testInvalidNamesAreRejected( $name ) {
		$store      = $this->store();
		$operations = array(
			'path'   => function () use ( $store, $name ) {
				$store->path( $name );
			},
			'read'   => function () use ( $store, $name ) {
				$store->read( $name );
			},
			'write'  => function () use ( $store, $name ) {
				$store->write( $name, array( 'x' => 1 ) );
			},
			'update' => function () use ( $store, $name ) {
				$store->update(
					$name,
					function ( array $data ) {
						return $data;
					}
				);
			},
			'delete' => function () use ( $store, $name ) {
				$store->delete( $name );
			},
		);
		foreach ( $operations as $operation => $callback ) {
			try {
				$callback();
				$this->fail( $operation . '() accepted ' . var_export( $name, true ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid configuration document name.', $e->getMessage() );
			}
		}
		$this->assertSame( array(), glob( $this->dir . '/config/*' ) ?: array() );
	}

	public function testValidNames() {
		$store = $this->store();
		foreach ( array( 'a', '0', 'schedule', 'gdrive', 'history', 'a-b-1', str_repeat( 'a', 64 ) ) as $name ) {
			$this->assertTrue( $store->write( $name, array( 'name' => $name ) ), $name );
			$this->assertSame( array( 'name' => $name ), $store->read( $name ) );
		}
	}

	public function testSequentialUpdates() {
		$store = $this->store();
		$seen  = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$result = $store->update(
				'counter',
				function ( array $data ) use ( &$seen ) {
					$seen[]    = $data;
					$data['n'] = ( isset( $data['n'] ) ? $data['n'] : 0 ) + 1;
					return $data;
				}
			);
			$this->assertSame( array( 'n' => $i ), $result );
		}
		$this->assertSame( array(), $seen[0], 'a missing document reaches the mutator as an empty array' );
		$this->assertSame( array( 'n' => 4 ), $seen[4] );
		$this->assertSame( array( 'n' => 5 ), $store->read( 'counter' ) );
	}

	public function testFailedMutatorLeavesTheDocumentAndReleasesTheLock() {
		$store = $this->store();
		$store->write( 'schedule', array( 'a' => 1 ) );

		try {
			$store->update(
				'schedule',
				function () {
					throw new \LogicException( 'boom' );
				}
			);
			$this->fail( 'The mutator exception was swallowed.' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( array( 'a' => 1 ), $store->read( 'schedule' ) );

		try {
			$store->update(
				'schedule',
				function () {
					return 'not an array';
				}
			);
			$this->fail( 'A non-array result was written.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'A configuration update must return an array.', $e->getMessage() );
		}
		$this->assertSame( array( 'a' => 1 ), $store->read( 'schedule' ) );

		// flock() locks belong to the open file, so a second handle in this
		// process only gets the lock if update() released it.
		$handle = fopen( $store->path( 'schedule' ) . '.lock', 'c' );
		$this->assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );
		flock( $handle, LOCK_UN );
		fclose( $handle );

		$this->assertSame(
			array( 'a' => 2 ),
			$store->update(
				'schedule',
				function ( array $data ) {
					$data['a']++;
					return $data;
				}
			)
		);
		$this->assertSame( array(), glob( $this->dir . '/config/*.tmp' ) );
	}

	/**
	 * Four processes increment one counter 50 times each; the lock must
	 * not lose a single update.
	 */
	public function testConcurrentUpdatesDoNotLoseChanges() {
		if ( ! function_exists( 'proc_open' ) || '' === PHP_BINARY || ! is_executable( PHP_BINARY ) ) {
			$this->markTestSkipped( 'Cannot start PHP child processes.' );
		}
		$script = $this->dir . '/worker.php';
		file_put_contents(
			$script,
			'<?php
			define( "SHCM_ALLOW_STANDALONE", true );
			require $argv[1];
			$store = new SHCM\Backup\ConfigStore( $argv[2] );
			for ( $i = 0; $i < (int) $argv[3]; $i++ ) {
				$store->update( "counter", function ( array $data ) use ( $argv ) {
					$data["n"]     = ( isset( $data["n"] ) ? $data["n"] : 0 ) + 1;
					$data["log"][] = $argv[4];
					usleep( 200 );
					return $data;
				} );
			}
			echo "done";'
		);

		$rounds    = 50;
		$bootstrap = dirname( __DIR__, 2 ) . '/includes/bootstrap.php';
		$processes = array();
		for ( $p = 0; $p < 4; $p++ ) {
			$pipes   = array();
			$process = proc_open(
				array( PHP_BINARY, $script, $bootstrap, $this->dir . '/config', (string) $rounds, 'p' . $p ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			$this->assertIsResource( $process );
			$processes[] = array( $process, $pipes );
		}
		foreach ( $processes as $index => $entry ) {
			list( $process, $pipes ) = $entry;
			$out = stream_get_contents( $pipes[1] );
			$err = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( 0, proc_close( $process ), 'worker ' . $index . ': ' . $err );
			$this->assertSame( 'done', $out, $err );
		}

		$data = $this->store()->read( 'counter' );
		$this->assertSame( 4 * $rounds, $data['n'] );
		$this->assertCount( 4 * $rounds, $data['log'] );
		$counts = array_count_values( $data['log'] );
		ksort( $counts );
		$this->assertSame(
			array(
				'p0' => $rounds,
				'p1' => $rounds,
				'p2' => $rounds,
				'p3' => $rounds,
			),
			$counts
		);
	}

	public function testDelete() {
		$store = $this->store();
		$store->write( 'gdrive', array( 'a' => 1 ) );
		$store->update(
			'gdrive',
			function ( array $data ) {
				return $data + array( 'b' => 2 );
			}
		);
		$store->delete( 'gdrive' );
		$this->assertFileDoesNotExist( $store->path( 'gdrive' ) );
		$this->assertSame( array(), $store->read( 'gdrive' ) );
		// The lock file stays, so that a concurrent update keeps locking the same file.
		$this->assertFileExists( $store->path( 'gdrive' ) . '.lock' );

		// Deleting again, or a document that never existed, is fine.
		$store->delete( 'gdrive' );
		$store->delete( 'never-written' );

		$this->assertSame(
			array( 'c' => 3 ),
			$store->update(
				'gdrive',
				function ( array $data ) {
					return $data + array( 'c' => 3 );
				}
			)
		);
	}

	public function testUnwritableDirectory() {
		file_put_contents( $this->dir . '/file', 'x' );
		$store = new ConfigStore( $this->dir . '/file/config' );
		$this->assertFalse( @$store->write( 'schedule', array( 'a' => 1 ) ) );
		$this->assertSame( array(), $store->read( 'schedule' ) );
		$this->expectException( \RuntimeException::class );
		@$store->update(
			'schedule',
			function ( array $data ) {
				return $data;
			}
		);
	}

	public function testDocumentsAreReadableByOtherSystemAccounts() {
		// WP-CLI and PHP-FPM often run as different users (root or a deploy
		// user, and www-data): each must be able to read what the other wrote.
		$store = $this->store();
		$store->write( 'schedule', array( 'a' => 1 ) );
		$store->update(
			'gdrive',
			function ( array $data ) {
				return array( 'b' => 2 );
			}
		);
		$this->assertSame( 0644, fileperms( $store->path( 'schedule' ) ) & 0777 );
		$this->assertSame( 0644, fileperms( $store->path( 'gdrive' ) ) & 0777 );
		$this->assertSame( 0644, fileperms( $store->path( 'gdrive' ) . '.lock' ) & 0777 );
		$this->assertTrue( $store->readable( 'schedule' ) );
		$this->assertTrue( $store->readable( 'history' ), 'a missing document is not a problem' );
		$this->assertSame( array(), $store->problems( array( 'schedule', 'gdrive', 'history' ) ) );
	}

	public function testTheConfigDirectoryTakesTheStorageDirectoryMode() {
		// Whoever creates it first (WP-CLI as root, PHP as www-data), the
		// other account must be able to write where the site owner allowed it.
		chmod( $this->dir, 0777 );
		$this->store()->write( 'schedule', array( 'a' => 1 ) );
		$this->assertSame( 0777, fileperms( $this->dir . '/config' ) & 0777 );

		$tight = $this->dir . '/tight';
		mkdir( $tight, 0750 );
		( new ConfigStore( $tight . '/config' ) )->write( 'schedule', array( 'a' => 1 ) );
		$this->assertSame( 0755, fileperms( $tight . '/config' ) & 0777, 'never less than 0755' );
	}

	public function testDamagedDocumentIsReportedNotTakenForEmpty() {
		$store = $this->store();
		$this->assertFalse( $store->damaged( 'schedule' ), 'missing is not damaged' );
		$store->write( 'schedule', array( 'config' => array( 'frequency' => 'daily' ) ) );
		$this->assertFalse( $store->damaged( 'schedule' ) );
		$this->assertSame( array(), $store->problems( array( 'schedule' ) ) );

		// Cut short, as by a partial copy of wp-content.
		$path = $store->path( 'schedule' );
		file_put_contents( $path, substr( file_get_contents( $path ), 0, -2 ) );
		$this->assertTrue( $store->damaged( 'schedule' ) );
		$this->assertSame( array(), $store->read( 'schedule' ) );
		$problems = $store->problems( array( 'schedule' ) );
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'damaged', $problems[0] );

		// Saving again replaces it.
		$store->update(
			'schedule',
			function ( array $data ) {
				return array( 'config' => array( 'frequency' => 'weekly' ) );
			}
		);
		$this->assertFalse( $store->damaged( 'schedule' ) );
	}

	public function testUnreadableDocumentIsNeverOverwritten() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root can read every file; covered by the www-data check in scripts/backup-e2e.sh.' );
		}
		$store = $this->store();
		$store->write( 'gdrive', array( 'token' => 'keep me' ) );
		chmod( $store->path( 'gdrive' ), 0200 );
		try {
			$this->assertFalse( $store->readable( 'gdrive' ) );
			$this->assertCount( 1, $store->problems( array( 'gdrive' ) ) );
			try {
				$store->update(
					'gdrive',
					function ( array $data ) {
						return array( 'token' => 'replaced' );
					}
				);
				$this->fail( 'update() must refuse to replace a document it cannot read' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'cannot be read', $e->getMessage() );
			}
		} finally {
			chmod( $store->path( 'gdrive' ), 0644 );
		}
		$this->assertSame( array( 'token' => 'keep me' ), $store->read( 'gdrive' ) );
	}

	/* ------------------------------------------------------------------
	 * History
	 * ------------------------------------------------------------------ */

	public function testRecordInsertsAnEntry() {
		$history = new History( $this->store() );
		$this->assertNull( $history->get( 'job-1' ) );
		$this->assertSame( array(), $history->all() );

		$before = time();
		$entry  = $history->record(
			'job-1',
			array(
				'kind'    => 'backup',
				'status'  => 'running',
				'trigger' => 'schedule',
			)
		);
		$this->assertSame( 'job-1', $entry['id'] );
		$this->assertGreaterThanOrEqual( $before, $entry['created'] );
		$this->assertLessThanOrEqual( time(), $entry['created'] );
		$this->assertSame( 'running', $entry['status'] );
		$this->assertSame( $entry, $history->get( 'job-1' ) );

		// Ids are compared as strings.
		$history->record( 123, array( 'status' => 'x' ) );
		$this->assertSame( '123', $history->get( '123' )['id'] );
		$this->assertSame( '123', $history->get( 123 )['id'] );
	}

	public function testRecordMergesNestedFields() {
		$history = new History( $this->store() );
		$first   = $history->record(
			'job-1',
			array(
				'status' => 'running',
				'remote' => array(
					'status' => 'pending',
					'job'    => 'upload-1',
				),
				'local'  => array( 'kept' => true ),
				'error'  => array( 'was' => 'an array' ),
			)
		);
		$entry   = $history->record(
			'job-1',
			array(
				'status'  => 'success',
				'archive' => 'site-backup-20260928-0300-abc.wpress',
				'remote'  => array(
					'status'  => 'uploaded',
					'file_id' => 'drive-file-1',
				),
				'error'   => '',
			)
		);

		$this->assertSame( 'success', $entry['status'] );
		$this->assertSame(
			array(
				'status'  => 'uploaded',
				'job'     => 'upload-1',
				'file_id' => 'drive-file-1',
			),
			$entry['remote']
		);
		$this->assertSame( array( 'kept' => true ), $entry['local'] );
		$this->assertSame( '', $entry['error'] );
		$this->assertSame( $first['created'], $entry['created'] );
		$this->assertSame( $entry, $history->get( 'job-1' ) );
		$this->assertCount( 1, $history->all() );
	}

	public function testIdCannotBeOverwrittenByAField() {
		$history = new History( $this->store() );
		$history->record( 'job-1', array( 'id' => 'evil' ) );
		$history->record( 'job-1', array( 'id' => 'evil', 'status' => 'success' ) );
		$this->assertNull( $history->get( 'evil' ) );
		$this->assertSame( 'job-1', $history->get( 'job-1' )['id'] );
		$this->assertSame( 'success', $history->get( 'job-1' )['status'] );
		$this->assertCount( 1, $history->all() );
	}

	public function testNewestFirstAndCapped() {
		$history = new History( $this->store() );
		foreach ( array( 'a', 'b', 'c' ) as $id ) {
			$history->record( $id, array( 'status' => 'success' ) );
		}
		$this->assertSame( array( 'c', 'b', 'a' ), array_column( $history->all(), 'id' ) );

		// Updating an old entry does not move it.
		$history->record( 'a', array( 'status' => 'failed' ) );
		$this->assertSame( array( 'c', 'b', 'a' ), array_column( $history->all(), 'id' ) );
		$this->assertSame( array( 'c', 'b' ), array_column( $history->all( 2 ), 'id' ) );

		for ( $i = 0; $i < History::MAX + 5; $i++ ) {
			$history->record( 'e' . $i, array( 'status' => 'success' ) );
		}
		$all = $history->all( 1000 );
		$this->assertSame( 100, History::MAX );
		$this->assertCount( History::MAX, $all );
		$this->assertSame( 'e104', $all[0]['id'] );
		$this->assertSame( 'e5', $all[ History::MAX - 1 ]['id'] );
		$this->assertNull( $history->get( 'e4' ) );
		$this->assertNull( $history->get( 'a' ) );
		$this->assertCount( 50, $history->all() );
	}

	public function testArchiveLookups() {
		$history = new History( $this->store() );
		$history->record(
			'b1',
			array(
				'kind'    => 'backup',
				'archive' => 'one.wpress',
			)
		);
		$history->record(
			'm1',
			array(
				'kind'    => 'manual',
				'archive' => 'export.wpress',
			)
		);
		$history->record(
			'b2',
			array(
				'kind'   => 'backup',
				'status' => 'failed',
			)
		);
		$history->record(
			'b3',
			array(
				'kind'    => 'backup',
				'archive' => 'three.wpress',
			)
		);
		$history->record(
			'b4',
			array(
				'kind'    => 'backup',
				'archive' => 'one.wpress',
			)
		);

		$this->assertSame( array( 'one.wpress', 'three.wpress' ), $history->archiveNames() );
		$this->assertNotContains( 'export.wpress', $history->archiveNames() );

		$this->assertSame( 'm1', $history->forArchive( 'export.wpress' )['id'] );
		$this->assertSame( 'b3', $history->forArchive( 'three.wpress' )['id'] );
		// The newest entry wins when two name the same archive.
		$this->assertSame( 'b4', $history->forArchive( 'one.wpress' )['id'] );
		$this->assertNull( $history->forArchive( 'missing.wpress' ) );
		$this->assertNull( $history->forArchive( '' ) );
	}

	/**
	 * Retention deletes the archives archiveNames() returns, so only entries
	 * whose kind is exactly "backup" count: an entry without a kind (such as
	 * one recreated by a late update after it fell out of the capped list)
	 * or with any other kind must never make its archive prunable.
	 */
	public function testArchiveNamesOnlyCountsKindBackup() {
		$history = new History( $this->store() );
		$history->record(
			'b1',
			array(
				'kind'    => 'backup',
				'archive' => 'backup.wpress',
			)
		);
		$history->record( 'x1', array( 'archive' => 'no-kind.wpress' ) );
		$history->record(
			'x2',
			array(
				'kind'    => null,
				'archive' => 'null-kind.wpress',
			)
		);
		$history->record(
			'x3',
			array(
				'kind'    => 'Backup',
				'archive' => 'upper-case.wpress',
			)
		);
		$history->record(
			'x4',
			array(
				'kind'    => true,
				'archive' => 'true-kind.wpress',
			)
		);
		$history->record(
			'x5',
			array(
				'kind'    => 'rollback',
				'archive' => 'rollback.wpress',
			)
		);
		$history->record(
			'x6',
			array(
				'kind'    => '',
				'archive' => 'empty-kind.wpress',
			)
		);
		$this->assertSame( array( 'backup.wpress' ), $history->archiveNames() );

		// The other entries are still found by archive, they are just not prunable.
		$this->assertSame( 'x1', $history->forArchive( 'no-kind.wpress' )['id'] );

		// An entry recreated without its kind: a late update of a backup
		// whose entry was pushed out by History::MAX newer ones.
		$history->record( 'b1', array( 'status' => 'success' ) );
		$this->assertSame( array( 'backup.wpress' ), $history->archiveNames() );
		for ( $i = 0; $i < History::MAX; $i++ ) {
			$history->record( 'e' . $i, array( 'status' => 'success' ) );
		}
		$this->assertNull( $history->get( 'b1' ) );
		$history->record(
			'b1',
			array(
				'archive' => 'backup.wpress',
				'remote'  => array( 'status' => 'uploaded' ),
			)
		);
		$this->assertSame( array(), $history->archiveNames() );
	}

	public function testRecordedArchivesListsEveryKind() {
		$history = new History( $this->store() );
		$history->record( 'a', array( 'kind' => 'backup', 'archive' => 'backup.wpress' ) );
		$history->record( 'b', array( 'archive' => 'kindless.wpress' ) );
		$history->record( 'c', array( 'kind' => 'failed' ) );
		$this->assertSame( array( 'kindless.wpress', 'backup.wpress' ), $history->recordedArchives() );
		$this->assertSame( array( 'backup.wpress' ), $history->archiveNames() );
	}

	public function testLatestByStatus() {
		$history = new History( $this->store() );
		$this->assertNull( $history->latest() );

		$history->record( 'j1', array( 'status' => 'success' ) );
		$history->record( 'j2', array( 'status' => 'failed' ) );
		$history->record( 'j3', array( 'status' => 'running' ) );
		$history->record( 'j4', array() );

		$this->assertSame( 'j4', $history->latest()['id'] );
		$this->assertSame( 'j3', $history->latest( array( 'running' ) )['id'] );
		$this->assertSame( 'j2', $history->latest( array( 'success', 'failed' ) )['id'] );
		$this->assertSame( 'j1', $history->latest( array( 'success' ) )['id'] );
		$this->assertNull( $history->latest( array( 'skipped' ) ) );
	}

	public function testClear() {
		$store   = $this->store();
		$history = new History( $store );
		$history->record( 'j1', array( 'status' => 'success' ) );
		$history->clear();
		$this->assertSame( array(), $history->all() );
		$this->assertNull( $history->get( 'j1' ) );
		$this->assertFileDoesNotExist( $store->path( History::DOCUMENT ) );

		$history->record( 'j2', array( 'status' => 'success' ) );
		$this->assertSame( array( 'j2' ), array_column( $history->all(), 'id' ) );
	}

	public function testHistoryToleratesADamagedDocument() {
		$store = $this->store();
		$store->write( History::DOCUMENT, array( 'entries' => 'not a list' ) );
		$history = new History( $store );
		$this->assertSame( array(), $history->all() );
		$history->record( 'j1', array( 'status' => 'success' ) );
		$this->assertSame( array( 'j1' ), array_column( $history->all(), 'id' ) );
	}

	/* ------------------------------------------------------------------
	 * SiteIdentity
	 * ------------------------------------------------------------------ */

	public function testFingerprintIsStableHex() {
		$fingerprint = SiteIdentity::fingerprint();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $fingerprint );
		$this->assertSame( $fingerprint, SiteIdentity::fingerprint() );
		if ( ! function_exists( 'home_url' ) ) {
			$this->assertSame( '', SiteIdentity::label() );
		}
	}
}
