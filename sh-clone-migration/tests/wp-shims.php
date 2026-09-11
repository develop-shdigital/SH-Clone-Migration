<?php
/**
 * Minimal WordPress function shims for the unit test-suite.
 *
 * Only the handful of helpers the engine touches outside of a real WordPress
 * request are defined, and each one mirrors core behaviour closely enough for
 * the serialisation and replacement tests to be meaningful.
 *
 * @package SHCM
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'is_serialized' ) ) {
	/**
	 * Port of WordPress is_serialized().
	 *
	 * @param mixed $data   Data.
	 * @param bool  $strict Strict mode.
	 * @return bool
	 */
	function is_serialized( $data, $strict = true ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 ) {
			return false;
		}
		if ( ':' !== $data[1] ) {
			return false;
		}
		if ( $strict ) {
			$lastc = substr( $data, -1 );
			if ( ';' !== $lastc && '}' !== $lastc ) {
				return false;
			}
		} else {
			$semicolon = strpos( $data, ';' );
			$brace     = strpos( $data, '}' );
			if ( false === $semicolon && false === $brace ) {
				return false;
			}
			if ( false !== $semicolon && $semicolon < 3 ) {
				return false;
			}
			if ( false !== $brace && $brace < 4 ) {
				return false;
			}
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
				if ( $strict ) {
					if ( '"' !== substr( $data, -2, 1 ) ) {
						return false;
					}
				} elseif ( false === strpos( $data, '"' ) ) {
					return false;
				}
				// Fall through.
			case 'a':
			case 'O':
			case 'E':
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				$end = $strict ? '$' : '';
				return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$end/", $data );
		}
		return false;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * Port of WordPress maybe_unserialize().
	 *
	 * @param string $data Data.
	 * @return mixed
	 */
	function maybe_unserialize( $data ) {
		if ( is_serialized( $data ) ) {
			return @unserialize( trim( $data ) ); // phpcs:ignore
		}
		return $data;
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * Port of WordPress maybe_serialize().
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data ); // phpcs:ignore
		}
		if ( is_serialized( $data, false ) ) {
			return serialize( $data ); // phpcs:ignore
		}
		return $data;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode shim.
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags Flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Very small sanitiser shim.
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Path normaliser shim.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		return preg_replace( '|(?<=.)/+|', '/', $path );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Trailing slash shim.
	 *
	 * @param string $string Path.
	 * @return string
	 */
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Untrailing slash shim.
	 *
	 * @param string $string Path.
	 * @return string
	 */
	function untrailingslashit( $string ) {
		return rtrim( $string, '/\\' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escaping shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter shim: returns the value unchanged.
	 *
	 * @param string $tag   Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * URL parser shim.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
