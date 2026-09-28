<?php
/**
 * Google Drive connection state.
 *
 * @package SHCM
 */

namespace SHCM\Remote\GoogleDrive;

use SHCM\Backup\ConfigStore;
use SHCM\Backup\SiteIdentity;
use SHCM\Security\SecretBox;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * The site owner's Google Drive connection, kept in config/gdrive.php.
 *
 * Stored fields: client_id, client_secret (sealed), refresh_token (sealed),
 * access_token (sealed), access_expires, scope, account_email, account_name,
 * folder_id, folder_name, connected_at, status ('connected', 'reconnect' or
 * ''), error (user-safe text), fingerprint, site_label, site_id.
 *
 * The document is not in wp_options because options travel inside archives:
 * a staging copy must never inherit production's Drive access. For copies
 * made by other means (a host's clone tool) the stored installation
 * fingerprint differs, and the connection is reported as "other_site" and not
 * used until the admin confirms (adoptThisSite()) or connects again, which
 * then also gives the copy its own site id and therefore its own folder.
 *
 * Logging: this class cannot reach the plugin's Logger. It remembers every
 * plaintext secret it opens or receives; the integration registers them with
 * the redactor, either once per request via
 *     foreach ( $connection->secrets() as $secret ) { $redactor->addLiteral( $secret ); }
 * or, better, as they appear via
 *     $connection->onSecret( array( $redactor, 'addLiteral' ) );
 */
final class Connection {

	const DOCUMENT = 'gdrive';

	const CONTEXT_CLIENT_SECRET = 'gdrive-client-secret';
	const CONTEXT_REFRESH_TOKEN = 'gdrive-refresh-token';
	const CONTEXT_ACCESS_TOKEN  = 'gdrive-access-token';

	const STATE_NOT_CONFIGURED = 'not_configured';
	const STATE_NOT_CONNECTED  = 'not_connected';
	const STATE_CONNECTED      = 'connected';
	const STATE_RECONNECT      = 'reconnect';
	const STATE_OTHER_SITE     = 'other_site';

	/**
	 * An access token is not handed out during its last minute, so that it
	 * cannot expire between the check and Google receiving the request.
	 */
	const EXPIRY_MARGIN = 60;

	/**
	 * Fields removed by disconnect() and when the client changes.
	 */
	const CONNECTION_FIELDS = array(
		'refresh_token',
		'access_token',
		'access_expires',
		'scope',
		'account_email',
		'account_name',
		'folder_id',
		'folder_name',
		'connected_at',
		'status',
		'error',
	);

	/**
	 * Config store.
	 *
	 * @var ConfigStore
	 */
	private $store;

	/**
	 * Secret box.
	 *
	 * @var SecretBox
	 */
	private $box;

	/**
	 * Fingerprint of this installation.
	 *
	 * @var string
	 */
	private $fingerprint;

	/**
	 * Clock.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Plaintext secrets seen by this instance.
	 *
	 * @var string[]
	 */
	private $secrets = array();

	/**
	 * Callbacks told about each new plaintext secret.
	 *
	 * @var callable[]
	 */
	private $listeners = array();

	/**
	 * Constructor.
	 *
	 * @param ConfigStore   $store       Config store (Storage::config()).
	 * @param SecretBox     $box         Secret box.
	 * @param string|null   $fingerprint Installation fingerprint; null = SiteIdentity::fingerprint().
	 * @param callable|null $clock       Returns the current unix time; null = time().
	 */
	public function __construct( ConfigStore $store, SecretBox $box, $fingerprint = null, $clock = null ) {
		$this->store       = $store;
		$this->box         = $box;
		$this->fingerprint = null === $fingerprint ? SiteIdentity::fingerprint() : (string) $fingerprint;
		$this->clock       = is_callable( $clock ) ? $clock : 'time';
	}

	/**
	 * OAuth client ID. The SHCM_GDRIVE_CLIENT_ID constant wins over the stored value.
	 *
	 * @return string '' when not set.
	 */
	public function clientId() {
		if ( defined( 'SHCM_GDRIVE_CLIENT_ID' ) && '' !== trim( (string) SHCM_GDRIVE_CLIENT_ID ) ) {
			return trim( (string) SHCM_GDRIVE_CLIENT_ID );
		}
		$data = $this->data();
		return isset( $data['client_id'] ) && is_string( $data['client_id'] ) ? $data['client_id'] : '';
	}

	/**
	 * OAuth client secret. The SHCM_GDRIVE_CLIENT_SECRET constant wins over the stored value.
	 *
	 * @return string '' when not set or when the stored value cannot be opened on this site.
	 */
	public function clientSecret() {
		if ( defined( 'SHCM_GDRIVE_CLIENT_SECRET' ) && '' !== trim( (string) SHCM_GDRIVE_CLIENT_SECRET ) ) {
			$secret = trim( (string) SHCM_GDRIVE_CLIENT_SECRET );
			$this->noteSecret( $secret );
			return $secret;
		}
		$data   = $this->data();
		$secret = $this->open( $data, 'client_secret', self::CONTEXT_CLIENT_SECRET );
		return null === $secret ? '' : $secret;
	}

	/**
	 * Whether the credentials come from wp-config.php constants (the settings
	 * form cannot change them then). True when at least one of
	 * SHCM_GDRIVE_CLIENT_ID and SHCM_GDRIVE_CLIENT_SECRET is set.
	 *
	 * @return bool
	 */
	public function credentialsFromConstants() {
		foreach ( array( 'SHCM_GDRIVE_CLIENT_ID', 'SHCM_GDRIVE_CLIENT_SECRET' ) as $constant ) {
			if ( defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a client ID and a usable client secret are available.
	 *
	 * @return bool
	 */
	public function hasCredentials() {
		return '' !== $this->clientId() && '' !== $this->clientSecret();
	}

	/**
	 * Save the OAuth client credentials.
	 *
	 * A different client ID ends the current connection: tokens, account and
	 * folder belong to the old client. An empty secret keeps the stored one
	 * (the form never shows it again), unless the client ID changed.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret ('' = keep the stored one).
	 * @return void
	 */
	public function setCredentials(
		$client_id,
		#[\SensitiveParameter]
		$client_secret
	) {
		$client_id     = trim( (string) $client_id );
		$client_secret = trim( (string) $client_secret );
		$sealed        = null;
		if ( '' !== $client_secret ) {
			$this->noteSecret( $client_secret );
			$sealed = $this->box->seal( $client_secret, self::CONTEXT_CLIENT_SECRET );
		}

		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $client_id, $sealed ) {
				$previous = isset( $data['client_id'] ) ? (string) $data['client_id'] : '';
				if ( $previous !== $client_id ) {
					$data = self::withoutConnection( $data );
					unset( $data['client_secret'] );
				}
				$data['client_id'] = $client_id;
				if ( null !== $sealed ) {
					$data['client_secret'] = $sealed;
				}
				return $data;
			}
		);
	}

	/**
	 * This site's backup identity: 16 random hex characters, created once and
	 * stored in the Drive files' appProperties (shcm_site) to find them again.
	 *
	 * @return string
	 */
	public function siteId() {
		$data = $this->data();
		if ( isset( $data['site_id'] ) && self::isSiteId( $data['site_id'] ) ) {
			return $data['site_id'];
		}
		$data = $this->store->update(
			self::DOCUMENT,
			function ( array $data ) {
				if ( ! isset( $data['site_id'] ) || ! self::isSiteId( $data['site_id'] ) ) {
					$data['site_id'] = self::newSiteId();
				}
				return $data;
			}
		);
		return $data['site_id'];
	}

	/**
	 * Connection status for screens, CLI and System Status.
	 *
	 * States, in order of precedence: not_configured (no client ID or no
	 * usable secret), other_site (connected from another installation),
	 * reconnect (Google revoked access, or the stored token cannot be opened
	 * with this site's keys), connected, not_connected.
	 *
	 * @return array{state: string, account: string, name: string, folder_id: string, folder_name: string, connected_at: int, error: string, site_id: string, client_id: string, from_constants: bool}
	 */
	public function status() {
		$data  = $this->data();
		$error = self::text( $data, 'error' );
		$state = self::STATE_NOT_CONNECTED;

		if ( ! $this->hasCredentials() ) {
			$state = self::STATE_NOT_CONFIGURED;
		} elseif ( $this->isOtherSite( $data ) ) {
			$state = self::STATE_OTHER_SITE;
			$label = self::text( $data, 'site_label' );
			$error = sprintf(
				'Google Drive was connected by another installation%s. If this is the same site (moved or restored), confirm it. If it is a copy, connect Google Drive again so that the copy gets its own backup folder.',
				'' !== $label ? ' (' . $label . ')' : ''
			);
		} elseif ( self::STATE_RECONNECT === self::text( $data, 'status' ) ) {
			$state = self::STATE_RECONNECT;
			if ( '' === $error ) {
				$error = 'Google Drive access has expired or was revoked. Reconnect Google Drive.';
			}
		} elseif ( '' !== self::text( $data, 'refresh_token' ) ) {
			if ( null === $this->open( $data, 'refresh_token', self::CONTEXT_REFRESH_TOKEN ) ) {
				$state = self::STATE_RECONNECT;
				if ( '' === $error ) {
					$error = 'The stored Google Drive authorization cannot be read on this server (the WordPress security keys have changed). Reconnect Google Drive.';
				}
			} else {
				$state = self::STATE_CONNECTED;
			}
		}

		return array(
			'state'          => $state,
			'account'        => self::text( $data, 'account_email' ),
			'name'           => self::text( $data, 'account_name' ),
			'folder_id'      => self::text( $data, 'folder_id' ),
			'folder_name'    => self::text( $data, 'folder_name' ),
			'connected_at'   => isset( $data['connected_at'] ) ? (int) $data['connected_at'] : 0,
			'error'          => $error,
			'site_id'        => isset( $data['site_id'] ) && self::isSiteId( $data['site_id'] ) ? $data['site_id'] : '',
			'client_id'      => $this->clientId(),
			'from_constants' => $this->credentialsFromConstants(),
		);
	}

	/**
	 * Whether Drive may be used: connected, from this installation.
	 *
	 * @return bool
	 */
	public function isUsable() {
		$status = $this->status();
		return self::STATE_CONNECTED === $status['state'];
	}

	/**
	 * The refresh token.
	 *
	 * @return string|null Null when absent or not readable on this site.
	 */
	public function refreshToken() {
		return $this->open( $this->data(), 'refresh_token', self::CONTEXT_REFRESH_TOKEN );
	}

	/**
	 * The access token, if it is still valid for more than a minute.
	 *
	 * @return string|null
	 */
	public function accessToken() {
		$data    = $this->data();
		$expires = isset( $data['access_expires'] ) ? (int) $data['access_expires'] : 0;
		if ( $expires - self::EXPIRY_MARGIN <= $this->now() ) {
			return null;
		}
		return $this->open( $data, 'access_token', self::CONTEXT_ACCESS_TOKEN );
	}

	/**
	 * Store the tokens of a completed authorization.
	 *
	 * Stamps this installation's fingerprint and marks the connection as
	 * connected. When the stored fingerprint belongs to another installation
	 * (a copy connecting on its own) the site id is renewed and the folder
	 * forgotten, so the copy never lists or prunes the original's backups.
	 *
	 * @param string $refresh_token Refresh token ('' keeps the stored one).
	 * @param string $access_token  Access token.
	 * @param int    $expires_in    Access token lifetime in seconds.
	 * @param string $scope         Granted scopes, space separated.
	 * @return void
	 */
	public function storeTokens(
		#[\SensitiveParameter]
		$refresh_token,
		#[\SensitiveParameter]
		$access_token,
		$expires_in,
		$scope
	) {
		$now            = $this->now();
		$fingerprint    = $this->fingerprint;
		$label          = SiteIdentity::label();
		$sealed_refresh = $this->sealNew( $refresh_token, self::CONTEXT_REFRESH_TOKEN );
		$sealed_access  = $this->sealNew( $access_token, self::CONTEXT_ACCESS_TOKEN );
		$expires        = $now + max( 0, (int) $expires_in );

		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $now, $fingerprint, $label, $sealed_refresh, $sealed_access, $expires, $scope ) {
				$stored = isset( $data['fingerprint'] ) ? (string) $data['fingerprint'] : '';
				if ( '' !== $stored && ! hash_equals( $stored, $fingerprint ) ) {
					$data['site_id'] = self::newSiteId();
					unset( $data['folder_id'], $data['folder_name'] );
				}
				if ( ! isset( $data['site_id'] ) || ! self::isSiteId( $data['site_id'] ) ) {
					$data['site_id'] = self::newSiteId();
				}
				if ( null !== $sealed_refresh ) {
					$data['refresh_token'] = $sealed_refresh;
				}
				if ( null !== $sealed_access ) {
					$data['access_token']   = $sealed_access;
					$data['access_expires'] = $expires;
				} else {
					unset( $data['access_token'], $data['access_expires'] );
				}
				$data['scope']        = (string) $scope;
				$data['fingerprint']  = $fingerprint;
				$data['site_label']   = $label;
				$data['connected_at'] = $now;
				$data['status']       = self::STATE_CONNECTED;
				$data['error']        = '';
				return $data;
			}
		);
	}

	/**
	 * Store a refreshed access token.
	 *
	 * @param string $access_token  Access token.
	 * @param int    $expires_in    Lifetime in seconds.
	 * @param string $refresh_token A new refresh token, when Google rotated it ('' = keep).
	 * @return void
	 */
	public function storeAccessToken(
		#[\SensitiveParameter]
		$access_token,
		$expires_in,
		#[\SensitiveParameter]
		$refresh_token = ''
	) {
		$sealed_access  = $this->sealNew( $access_token, self::CONTEXT_ACCESS_TOKEN );
		$sealed_refresh = $this->sealNew( $refresh_token, self::CONTEXT_REFRESH_TOKEN );
		$expires        = $this->now() + max( 0, (int) $expires_in );

		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $sealed_access, $sealed_refresh, $expires ) {
				if ( null !== $sealed_access ) {
					$data['access_token']   = $sealed_access;
					$data['access_expires'] = $expires;
				} else {
					unset( $data['access_token'], $data['access_expires'] );
				}
				if ( null !== $sealed_refresh ) {
					$data['refresh_token'] = $sealed_refresh;
				}
				return $data;
			}
		);
	}

	/**
	 * Mark the connection as needing a new authorization.
	 *
	 * @param string $reason User-safe explanation shown until the next connect.
	 * @return void
	 */
	public function markReconnect( $reason ) {
		$reason = trim( (string) $reason );
		if ( strlen( $reason ) > 500 ) {
			$reason = substr( $reason, 0, 500 );
		}
		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $reason ) {
				$data['status'] = self::STATE_RECONNECT;
				$data['error']  = $reason;
				unset( $data['access_token'], $data['access_expires'] );
				return $data;
			}
		);
	}

	/**
	 * Forget the connection: tokens, account, folder and status. The client
	 * credentials, the site id and the fingerprint stay.
	 *
	 * Revoking the grant at Google is OAuth::revoke(), called before this.
	 *
	 * @return void
	 */
	public function disconnect() {
		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) {
				return self::withoutConnection( $data );
			}
		);
	}

	/**
	 * Remember the Google account.
	 *
	 * @param string $email Email address.
	 * @param string $name  Display name.
	 * @return void
	 */
	public function setAccount( $email, $name ) {
		$email = trim( (string) $email );
		$name  = trim( (string) $name );
		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $email, $name ) {
				$data['account_email'] = $email;
				$data['account_name']  = $name;
				return $data;
			}
		);
	}

	/**
	 * Remember the backup folder.
	 *
	 * @param string $id   Drive folder id.
	 * @param string $name Folder name.
	 * @return void
	 */
	public function setFolder( $id, $name ) {
		$id   = trim( (string) $id );
		$name = trim( (string) $name );
		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $id, $name ) {
				$data['folder_id']   = $id;
				$data['folder_name'] = $name;
				return $data;
			}
		);
	}

	/**
	 * "This is the same site": accept this installation's fingerprint and keep
	 * the tokens, site id and folder.
	 *
	 * @return void
	 */
	public function adoptThisSite() {
		$fingerprint = $this->fingerprint;
		$label       = SiteIdentity::label();
		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $fingerprint, $label ) {
				$data['fingerprint'] = $fingerprint;
				$data['site_label']  = $label;
				return $data;
			}
		);
	}

	/**
	 * Every plaintext secret this instance knows: the client secret and the
	 * tokens currently stored (opened now), plus secrets seen earlier in this
	 * request (replaced tokens, the authorization code, upload session URIs
	 * noted by the client). Register them with the log redactor.
	 *
	 * @return string[]
	 */
	public function secrets() {
		$this->clientSecret();
		$data = $this->data();
		$this->open( $data, 'refresh_token', self::CONTEXT_REFRESH_TOKEN );
		$this->open( $data, 'access_token', self::CONTEXT_ACCESS_TOKEN );
		return $this->secrets;
	}

	/**
	 * Call a function with every secret from now on (and those already seen),
	 * e.g. array( $redactor, 'addLiteral' ).
	 *
	 * @param callable $listener Receives one plaintext secret.
	 * @return void
	 */
	public function onSecret( callable $listener ) {
		$this->listeners[] = $listener;
		foreach ( $this->secrets as $secret ) {
			$listener( $secret );
		}
	}

	/**
	 * Remember a plaintext secret handled in this request (used by OAuth and
	 * Client for authorization codes and upload session URIs).
	 *
	 * @param string $value Secret.
	 * @return void
	 */
	public function noteSecret(
		#[\SensitiveParameter]
		$value
	) {
		if ( ! is_string( $value ) || strlen( $value ) < 4 || in_array( $value, $this->secrets, true ) ) {
			return;
		}
		$this->secrets[] = $value;
		foreach ( $this->listeners as $listener ) {
			$listener( $value );
		}
	}

	/**
	 * Current time.
	 *
	 * @return int
	 */
	public function now() {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * The stored document.
	 *
	 * @return array
	 */
	private function data() {
		return $this->store->read( self::DOCUMENT );
	}

	/**
	 * Whether the document was stamped by another installation.
	 *
	 * @param array $data Document.
	 * @return bool
	 */
	private function isOtherSite( array $data ) {
		$stored = self::text( $data, 'fingerprint' );
		return '' !== $stored && ! hash_equals( $stored, $this->fingerprint );
	}

	/**
	 * Open a sealed field and remember the plaintext.
	 *
	 * @param array  $data    Document.
	 * @param string $field   Field.
	 * @param string $context SecretBox context.
	 * @return string|null
	 */
	private function open( array $data, $field, $context ) {
		if ( empty( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
			return null;
		}
		$plain = $this->box->open( $data[ $field ], $context );
		if ( null === $plain || '' === $plain ) {
			return null;
		}
		$this->noteSecret( $plain );
		return $plain;
	}

	/**
	 * Seal a new value, remembering the plaintext.
	 *
	 * @param string $plain   Plaintext ('' = nothing to seal).
	 * @param string $context SecretBox context.
	 * @return string|null Null when $plain is empty.
	 */
	private function sealNew(
		#[\SensitiveParameter]
		$plain,
		$context
	) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return null;
		}
		$this->noteSecret( $plain );
		return $this->box->seal( $plain, $context );
	}

	/**
	 * A document without the connection fields.
	 *
	 * @param array $data Document.
	 * @return array
	 */
	private static function withoutConnection( array $data ) {
		foreach ( self::CONNECTION_FIELDS as $field ) {
			unset( $data[ $field ] );
		}
		return $data;
	}

	/**
	 * A string field.
	 *
	 * @param array  $data  Document.
	 * @param string $field Field.
	 * @return string
	 */
	private static function text( array $data, $field ) {
		return isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) ? (string) $data[ $field ] : '';
	}

	/**
	 * Whether a value is a site id.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function isSiteId( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $value );
	}

	/**
	 * A new random site id.
	 *
	 * @return string
	 */
	private static function newSiteId() {
		return bin2hex( random_bytes( 8 ) );
	}
}
