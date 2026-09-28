<?php
/**
 * Google OAuth callback.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Core\Plugin;
use SHCM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Receives the browser back from Google's consent screen.
 *
 * The redirect URI is admin-post.php without an action, so WordPress fires
 * the generic admin_post hook. Requests are recognised by the "shcm_" prefix
 * of the state parameter and ignored otherwise (other plugins may use the
 * same hook). The state is one-time, tied to the user who clicked Connect and
 * compared in constant time; the capability is checked as well.
 */
class DriveAuth {

	const NOTICE_TRANSIENT = 'shcm_gdrive_notice_';

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post', array( $this, 'callback' ) );
		add_action( 'admin_post_nopriv', array( $this, 'loggedOut' ) );
	}

	/**
	 * Whether this request is a reply to our consent request.
	 *
	 * @return string The state, or '' when the request is not ours.
	 */
	protected function state() {
		$state = isset( $_GET['state'] ) ? (string) wp_unslash( $_GET['state'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated against the stored one-time state.
		return preg_match( '/^shcm_[a-f0-9]{48}$/', $state ) ? $state : '';
	}

	/**
	 * Logged-in callback.
	 *
	 * @return void
	 */
	public function callback() {
		$state = $this->state();
		if ( '' === $state ) {
			return;
		}
		if ( ! Capabilities::currentUserCan() ) {
			wp_die( esc_html__( 'You are not allowed to connect Google Drive.', 'sh-clone-migration' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the state above is the CSRF check.
		$code  = isset( $_GET['code'] ) ? (string) wp_unslash( $_GET['code'] ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		if ( '' === $error && ! preg_match( '#^[A-Za-z0-9/_\-.~]{10,512}$#', $code ) ) {
			$error = 'invalid_request';
		}

		$controller = new BackupController( $this->plugin, new Controller( $this->plugin ) );
		$result     = $controller->completeConnection( $state, $code, $error );
		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $result, 300 );

		wp_safe_redirect( admin_url( 'admin.php?page=shcm-schedules' ) );
		exit;
	}

	/**
	 * The browser came back without a session: log in, then finish.
	 *
	 * @return void
	 */
	public function loggedOut() {
		if ( '' === $this->state() ) {
			return;
		}
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		wp_safe_redirect( wp_login_url( BackupController::redirectUri() . ( '' !== $query ? '?' . $query : '' ) ) );
		exit;
	}

	/**
	 * Take the message left for the current user by the callback.
	 *
	 * @return array|null array( ok, message )
	 */
	public static function takeNotice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( false === $notice ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $notice ) ? $notice : null;
	}
}
