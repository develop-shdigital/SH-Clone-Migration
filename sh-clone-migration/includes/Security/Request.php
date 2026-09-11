<?php
/**
 * Request validation helpers.
 *
 * @package SHCM
 */

namespace SHCM\Security;

defined( 'ABSPATH' ) || exit;

/**
 * One place where every AJAX and REST entry point is authenticated,
 * authorised and sanitised.
 */
class Request {

	const NONCE_ACTION = 'shcm_migration';

	/**
	 * Create a nonce for the migration endpoints.
	 *
	 * @return string
	 */
	public static function nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * Verify capability and nonce, or die with a JSON error.
	 *
	 * @param string $nonce_field Request field holding the nonce.
	 * @return void
	 */
	public static function guardAjax( $nonce_field = 'nonce' ) {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => __( 'You must be signed in to run a migration.', 'sh-clone-migration' ) ),
				401
			);
		}
		if ( ! Capabilities::currentUserCan() ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run migrations on this site.', 'sh-clone-migration' ) ),
				403
			);
		}

		$nonce = isset( $_REQUEST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_field ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session expired. Reload the page and try again.', 'sh-clone-migration' ) ),
				403
			);
		}
	}

	/**
	 * Permission callback for the REST routes.
	 *
	 * @return bool|\WP_Error
	 */
	public static function restPermission() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'shcm_unauthenticated',
				__( 'Authentication required.', 'sh-clone-migration' ),
				array( 'status' => 401 )
			);
		}
		if ( ! Capabilities::currentUserCan() ) {
			return new \WP_Error(
				'shcm_forbidden',
				__( 'You do not have permission to run migrations on this site.', 'sh-clone-migration' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Read a sanitised string from the request.
	 *
	 * @param string $key     Field name.
	 * @param string $default Default value.
	 * @return string
	 */
	public static function text( $key, $default = '' ) {
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Read a raw string (passwords must not be mangled by sanitisation).
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	public static function raw( $key ) {
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return '';
		}
		return (string) wp_unslash( $_REQUEST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Read a boolean from the request.
	 *
	 * @param string $key     Field name.
	 * @param bool   $default Default.
	 * @return bool
	 */
	public static function boolean( $key, $default = false ) {
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return $default;
		}
		return filter_var( wp_unslash( $_REQUEST[ $key ] ), FILTER_VALIDATE_BOOLEAN ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Read an integer from the request.
	 *
	 * @param string $key     Field name.
	 * @param int    $default Default.
	 * @return int
	 */
	public static function integer( $key, $default = 0 ) {
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return $default;
		}
		return (int) $_REQUEST[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Read a list of sanitised strings.
	 *
	 * @param string $key Field name.
	 * @return string[]
	 */
	public static function stringList( $key ) {
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return array();
		}
		$value = wp_unslash( $_REQUEST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
