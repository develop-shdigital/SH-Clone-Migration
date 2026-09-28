<?php
/**
 * Google endpoint URLs.
 *
 * @package SHCM
 */

namespace SHCM\Remote\GoogleDrive;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Where the OAuth and Drive requests go.
 *
 * Keys: auth, token, revoke (OAuth 2.0), api (Drive v3 base, requests are
 * "{api}/files/..."), upload (Drive v3 upload base, "{upload}/files?...").
 *
 * The URLs can be changed only from code (wp-config.php constants or the
 * 'shcm_gdrive_endpoints' filter), which the end-to-end tests use to point
 * the plugin at a local fake Google server. They are deliberately not a
 * setting: whoever can change the token URL receives the client secret and
 * the refresh token, and the plugin's capability can be delegated.
 */
final class Endpoints {

	/**
	 * Constant overriding each endpoint.
	 */
	const CONSTANTS = array(
		'auth'   => 'SHCM_GDRIVE_AUTH_URL',
		'token'  => 'SHCM_GDRIVE_TOKEN_URL',
		'revoke' => 'SHCM_GDRIVE_REVOKE_URL',
		'api'    => 'SHCM_GDRIVE_API_URL',
		'upload' => 'SHCM_GDRIVE_UPLOAD_URL',
	);

	/**
	 * Google's production endpoints.
	 *
	 * @return array<string, string>
	 */
	public static function defaults() {
		return array(
			'auth'   => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token'  => 'https://oauth2.googleapis.com/token',
			'revoke' => 'https://oauth2.googleapis.com/revoke',
			'api'    => 'https://www.googleapis.com/drive/v3',
			'upload' => 'https://www.googleapis.com/upload/drive/v3',
		);
	}

	/**
	 * Endpoints in effect: defaults, then constants, then the filter.
	 *
	 * A value that is not an http(s) URL is ignored, so a broken override
	 * falls back to Google instead of sending tokens somewhere odd.
	 *
	 * @return array<string, string>
	 */
	public static function resolve() {
		$defaults  = self::defaults();
		$endpoints = $defaults;
		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) && self::isUrl( constant( $constant ) ) ) {
				$endpoints[ $key ] = (string) constant( $constant );
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'shcm_gdrive_endpoints', $endpoints );
			if ( is_array( $filtered ) ) {
				foreach ( $defaults as $key => $unused ) {
					if ( isset( $filtered[ $key ] ) && self::isUrl( $filtered[ $key ] ) ) {
						$endpoints[ $key ] = (string) $filtered[ $key ];
					}
				}
			}
		}

		return self::normalize( $endpoints );
	}

	/**
	 * Complete a (possibly partial) endpoint list with the defaults and strip
	 * trailing slashes.
	 *
	 * @param array $endpoints Endpoints.
	 * @return array<string, string>
	 */
	public static function normalize( array $endpoints ) {
		$result = self::defaults();
		foreach ( $result as $key => $default ) {
			if ( isset( $endpoints[ $key ] ) && self::isUrl( $endpoints[ $key ] ) ) {
				$result[ $key ] = (string) $endpoints[ $key ];
			}
			$result[ $key ] = rtrim( $result[ $key ], '/' );
		}
		return $result;
	}

	/**
	 * Whether a value is an absolute http(s) URL.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function isUrl( $value ) {
		return is_string( $value ) && 1 === preg_match( '#^https?://[^\s/?\#]+[^\s]*$#i', $value );
	}
}
