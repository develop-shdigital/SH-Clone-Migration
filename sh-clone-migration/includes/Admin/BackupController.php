<?php
/**
 * Scheduled Backups screen logic.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Backup\BackupManager;
use SHCM\Backup\Schedule;
use SHCM\Core\Plugin;
use SHCM\Remote\GoogleDrive\DriveException;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * What the Scheduled Backups screen asks for, independent of transport
 * (AJAX today). Authorisation happens before these methods are called.
 */
class BackupController {

	const STATE_TRANSIENT = 'shcm_gdrive_oauth_';
	const STATE_TTL       = 900;

	/**
	 * Seconds of work done in the request that starts a backup or an upload:
	 * enough to get going, short enough that the page answers at once. The
	 * rest runs in the background.
	 */
	const FIRST_SLICE = 3;

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Job controller (snapshots).
	 *
	 * @var Controller
	 */
	protected $controller;

	/**
	 * Constructor.
	 *
	 * @param Plugin     $plugin     Container.
	 * @param Controller $controller Job controller.
	 */
	public function __construct( Plugin $plugin, Controller $controller ) {
		$this->plugin     = $plugin;
		$this->controller = $controller;
	}

	/**
	 * Backups.
	 *
	 * @return BackupManager
	 */
	protected function backups() {
		return $this->plugin->backups();
	}

	/**
	 * Everything the screen shows.
	 *
	 * @return array
	 */
	public function status() {
		$summary = $this->backups()->summary();
		return array(
			'schedule'  => $summary,
			'next_text' => $this->nextText( $summary['next_run'] ),
			'next_soon' => $summary['next_run'] && (int) $summary['next_run'] - time() <= 60,
			'next_in'   => $summary['next_run'] ? human_time_diff( time(), (int) $summary['next_run'] ) : '',
			'next_when' => $summary['next_run'] ? wp_date( get_option( 'date_format' ) . ' H:i', (int) $summary['next_run'] ) : '',
			'history'   => $this->history(),
			'drive'     => $this->driveStatus(),
		);
	}

	/**
	 * Save the schedule.
	 *
	 * @param array       $input    Submitted configuration.
	 * @param string|null $password New password, null to keep the stored one.
	 * @return array
	 */
	public function saveSchedule( array $input, $password ) {
		$this->backups()->saveSchedule( $input, $password );
		return $this->status();
	}

	/**
	 * Start a backup from the browser: create it, run the first slice and
	 * hand back the token the page drives it with. The background chain
	 * keeps it going if the page is closed.
	 *
	 * @param array $input Options for this run (gdrive, contents).
	 * @return array Job snapshot plus token.
	 */
	public function startBackup( array $input ) {
		$overrides = array();
		if ( isset( $input['gdrive'] ) ) {
			$overrides['gdrive'] = (bool) $input['gdrive'];
		}
		if ( isset( $input['contents'] ) && '' !== (string) $input['contents'] ) {
			$overrides['contents'] = (string) $input['contents'];
		}
		$job   = $this->backups()->startBackup( 'manual', $overrides );
		$token = (string) $job->runtime( 'token', '' );
		$this->plugin->logger()->channel( $job->id() )->info( sprintf( 'Backup requested by user %d.', get_current_user_id() ) );

		$job = $this->backups()->runner()->drive( $job, self::FIRST_SLICE );

		$snapshot          = $this->controller->snapshot( $job );
		$snapshot['token'] = $token;
		return $snapshot;
	}

	/**
	 * Upload an existing archive to Google Drive (retry a failed upload, or
	 * send a manual export).
	 *
	 * @param string $archive Archive base name.
	 * @param string $history History entry id, if retrying.
	 * @return array Job snapshot plus token.
	 */
	public function upload( $archive, $history = '' ) {
		if ( '' === $archive && '' !== $history ) {
			$entry   = $this->backups()->history()->get( $history );
			$archive = null !== $entry && isset( $entry['archive'] ) ? (string) $entry['archive'] : '';
		}
		$job   = $this->backups()->startUpload( $archive, '' !== $history ? 'backup' : 'manual', $history );
		$token = (string) $job->runtime( 'token', '' );
		$job   = $this->backups()->runner()->drive( $job, self::FIRST_SLICE );

		$snapshot          = $this->controller->snapshot( $job );
		$snapshot['token'] = $token;
		return $snapshot;
	}

	/**
	 * Save the Google OAuth client credentials.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret ('' keeps the stored one).
	 * @return array
	 * @throws \InvalidArgumentException When the values are not plausible.
	 */
	public function saveCredentials( $client_id, $client_secret ) {
		$connection = $this->backups()->connection();
		if ( $connection->credentialsFromConstants() ) {
			throw new \InvalidArgumentException( __( 'The client ID and secret are set in wp-config.php and cannot be changed here.', 'sh-clone-migration' ) );
		}
		// A field set by a wp-config.php constant is locked in the form (it
		// posts nothing); the constant's value is the one that counts, and a
		// constant's secret is never copied into storage.
		$locked        = $connection->constantFields();
		$client_id     = $locked['client_id'] ? (string) $connection->clientId() : trim( (string) $client_id );
		$client_secret = $locked['client_secret'] ? '' : trim( (string) $client_secret );
		if ( ! preg_match( '/^[A-Za-z0-9._\-]{8,200}$/', $client_id ) ) {
			throw new \InvalidArgumentException( __( 'That does not look like a Google OAuth client ID (it ends in .apps.googleusercontent.com).', 'sh-clone-migration' ) );
		}
		// '' keeps the stored secret (the form never shows it again), except
		// for a new client: its secret is a different one.
		if ( '' === $client_secret && ! $locked['client_secret'] && $client_id !== (string) $connection->clientId() ) {
			throw new \InvalidArgumentException( __( 'Enter the client secret of the new OAuth client.', 'sh-clone-migration' ) );
		}
		$effective = '' !== $client_secret ? $client_secret : (string) $connection->clientSecret();
		if ( ! preg_match( '/^[A-Za-z0-9._\-]{8,200}$/', $effective ) ) {
			throw new \InvalidArgumentException( __( 'Enter the client secret shown when the OAuth client was created.', 'sh-clone-migration' ) );
		}
		$connection->setCredentials( $client_id, $client_secret );
		return $this->driveStatus();
	}

	/**
	 * URL of Google's consent screen, with a one-time state bound to the
	 * current user.
	 *
	 * @return array array( url )
	 * @throws \RuntimeException When no client is configured.
	 */
	public function connectUrl() {
		$connection = $this->backups()->connection();
		if ( ! $connection->hasCredentials() ) {
			throw new \RuntimeException( __( 'Save the client ID and secret first.', 'sh-clone-migration' ) );
		}
		$state = 'shcm_' . bin2hex( random_bytes( 24 ) );
		set_transient(
			self::STATE_TRANSIENT . get_current_user_id(),
			array(
				'hash'    => hash( 'sha256', $state ),
				'created' => time(),
			),
			self::STATE_TTL
		);
		$status = $connection->status();
		return array(
			'url' => $this->backups()->oauth()->authorizationUrl( self::redirectUri(), $state, isset( $status['account'] ) ? (string) $status['account'] : '' ),
		);
	}

	/**
	 * Complete the consent round trip (called from the admin-post callback).
	 *
	 * @param string $state State returned by Google.
	 * @param string $code  Authorization code.
	 * @param string $error Error returned by Google.
	 * @return array array( ok, message )
	 */
	public function completeConnection( $state, $code, $error ) {
		$key    = self::STATE_TRANSIENT . get_current_user_id();
		$stored = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || ! hash_equals( (string) $stored['hash'], hash( 'sha256', (string) $state ) ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The Google sign-in could not be matched to this session (it expired or was started elsewhere). Click Connect again.', 'sh-clone-migration' ),
			);
		}
		if ( '' !== $error ) {
			return array(
				'ok'      => false,
				'message' => 'access_denied' === $error
					? __( 'Access to Google Drive was not granted.', 'sh-clone-migration' )
					/* translators: %s: error code from Google */
					: sprintf( __( 'Google reported an error: %s', 'sh-clone-migration' ), $error ),
			);
		}
		try {
			$this->backups()->oauth()->exchangeCode( $code, self::redirectUri() );
			$this->backups()->protectSecrets();
			$about = $this->backups()->drive()->about();
			$this->backups()->connection()->setAccount( (string) $about['email'], (string) $about['name'] );
			return array(
				'ok'      => true,
				/* translators: %s: Google account */
				'message' => sprintf( __( 'Google Drive is connected (%s).', 'sh-clone-migration' ), '' !== (string) $about['email'] ? $about['email'] : __( 'account name not reported', 'sh-clone-migration' ) ),
			);
		} catch ( DriveException $e ) {
			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Disconnect Google Drive (revoking access at Google).
	 *
	 * @return array
	 */
	public function disconnect() {
		$this->backups()->oauth()->revoke();
		$this->backups()->connection()->disconnect();
		$config = $this->backups()->config();
		if ( ! empty( $config['gdrive'] ) ) {
			// Keep the schedule running, to this server only.
			$config['gdrive']     = false;
			$config['keep_local'] = max( 1, (int) $config['keep_local'] );
			$this->backups()->saveSchedule( $config );
		}
		return $this->status();
	}

	/**
	 * Check the connection end to end: token, account, quota, folder.
	 *
	 * @return array
	 */
	public function test() {
		$backups    = $this->backups();
		$connection = $backups->connection();
		if ( ! $connection->isUsable() ) {
			throw new \RuntimeException( __( 'Google Drive is not connected.', 'sh-clone-migration' ) );
		}
		$backups->protectSecrets();
		$about  = $backups->drive()->about();
		$status = $connection->status();
		$folder = $backups->drive()->ensureFolder( $connection->siteId(), sprintf( 'SH Clone Migration backups (%s)', \SHCM\Backup\SiteIdentity::label() ), (string) $status['folder_id'] );
		$connection->setAccount( (string) $about['email'], (string) $about['name'] );
		$connection->setFolder( (string) $folder['id'], (string) $folder['name'] );
		return array(
			'message' => sprintf(
				/* translators: 1: account, 2: folder, 3: free space */
				__( 'Connected as %1$s. Backups go to the folder "%2$s". Free space: %3$s.', 'sh-clone-migration' ),
				'' !== (string) $about['email'] ? $about['email'] : '?',
				$folder['name'],
				null === $about['limit'] ? __( 'unlimited', 'sh-clone-migration' ) : Bytes::format( max( 0, (int) $about['limit'] - (int) $about['usage'] ) )
			),
			'drive'   => $this->driveStatus( $about ),
		);
	}

	/**
	 * Backups this site stored on Google Drive.
	 *
	 * @return array
	 */
	public function listDrive() {
		$backups    = $this->backups();
		$connection = $backups->connection();
		$status     = $connection->status();
		if ( ! $connection->isUsable() || '' === (string) $status['folder_id'] ) {
			return array( 'files' => array() );
		}
		$backups->protectSecrets();
		$files = array();
		foreach ( $backups->drive()->listBackups( (string) $status['folder_id'], $connection->siteId() ) as $file ) {
			$files[] = array(
				'id'      => (string) $file['id'],
				'name'    => (string) $file['name'],
				'size'    => (int) $file['size'],
				'created' => (int) $file['created'],
				'date'    => wp_date( get_option( 'date_format' ) . ' H:i', (int) $file['created'] ),
				'kind'    => isset( $file['app']['shcm_kind'] ) ? (string) $file['app']['shcm_kind'] : '',
				'sha256'  => isset( $file['app']['shcm_sha256'] ) ? (string) $file['app']['shcm_sha256'] : '',
				'link'    => (string) $file['link'],
			);
		}
		return array( 'files' => $files );
	}

	/**
	 * Confirm this installation owns the schedule and the connection.
	 *
	 * @return array
	 */
	public function adopt() {
		$this->backups()->adoptThisSite();
		return $this->status();
	}

	/**
	 * The OAuth redirect URI to register at Google.
	 *
	 * admin-post.php without a query string: Google compares redirect URIs
	 * exactly, and a bare URL is the least likely to be altered by
	 * a proxy or a security plugin. The request is recognised by its state.
	 *
	 * @return string
	 */
	public static function redirectUri() {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Drive connection for the screen (never secrets).
	 *
	 * @param array|null $about Fresh about() data, if just fetched.
	 * @return array
	 */
	public function driveStatus( $about = null ) {
		$connection = $this->backups()->connection();
		$status     = $connection->status();
		$constants  = $connection->constantFields();
		$redirect   = self::redirectUri();
		return array(
			'state'           => $status['state'],
			'account'         => (string) $status['account'],
			'folder'          => (string) $status['folder_name'],
			'folder_id'       => (string) $status['folder_id'],
			'connected_at'    => (int) $status['connected_at'],
			'error'           => (string) $status['error'],
			'client_id'       => (string) $connection->clientId(),
			'has_secret'      => '' !== (string) $connection->clientSecret(),
			'from_constants'  => $connection->credentialsFromConstants(),
			'id_constant'     => $constants['client_id'],
			'secret_constant' => $constants['client_secret'],
			'redirect_uri'    => $redirect,
			'https'           => 0 === strpos( $redirect, 'https://' ) || (bool) preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $redirect ),
			'quota'           => is_array( $about ) ? array(
				'limit' => $about['limit'],
				'usage' => (int) $about['usage'],
			) : null,
		);
	}

	/**
	 * History rows for the screen.
	 *
	 * @return array[]
	 */
	protected function history() {
		$catalog = new \SHCM\Archive\Catalog( $this->plugin->storage() );
		$rows    = array();
		foreach ( $this->backups()->history()->all( 30 ) as $entry ) {
			$archive = isset( $entry['archive'] ) ? (string) $entry['archive'] : '';
			$present = '' !== $archive && null !== $catalog->resolve( $archive );
			$started = isset( $entry['started'] ) ? (int) $entry['started'] : (int) $entry['created'];
			$rows[]  = array(
				'id'        => (string) $entry['id'],
				'date'      => wp_date( get_option( 'date_format' ) . ' H:i', $started ),
				'trigger'   => isset( $entry['trigger'] ) ? (string) $entry['trigger'] : '',
				'status'    => isset( $entry['status'] ) ? (string) $entry['status'] : '',
				'contents'  => isset( $entry['contents'] ) ? (string) $entry['contents'] : '',
				'encrypted' => ! empty( $entry['encrypted'] ),
				'archive'   => $archive,
				'present'   => $present,
				'size'      => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
				'sha256'    => isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '',
				'error'     => isset( $entry['error'] ) ? (string) $entry['error'] : '',
				'warnings'  => isset( $entry['warnings'] ) ? (int) $entry['warnings'] : 0,
				'remote'    => isset( $entry['remote'] ) && is_array( $entry['remote'] ) ? $entry['remote'] : array(),
				'job'       => 0 === strpos( (string) $entry['id'], 'skipped-' ) || 0 === strpos( (string) $entry['id'], 'failed-' ) ? '' : (string) $entry['id'],
				'download'  => $present ? wp_nonce_url( admin_url( 'admin-post.php?action=shcm_download&archive=' . rawurlencode( $archive ) ), 'shcm_download' ) : '',
			);
		}
		return $rows;
	}

	/**
	 * Next run as text.
	 *
	 * @param int|null $timestamp Next run.
	 * @return string
	 */
	protected function nextText( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return '';
		}
		$when = wp_date( get_option( 'date_format' ) . ' H:i', (int) $timestamp );
		$in   = (int) $timestamp - time();
		if ( $in <= 60 ) {
			return sprintf( __( '%s (any moment now)', 'sh-clone-migration' ), $when );
		}
		/* translators: 1: date, 2: human time difference */
		return sprintf( __( '%1$s (in %2$s)', 'sh-clone-migration' ), $when, human_time_diff( time(), (int) $timestamp ) );
	}
}
