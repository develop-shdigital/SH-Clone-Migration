<?php
/**
 * Replacement rule generation.
 *
 * @package SHCM
 */

namespace SHCM\URL;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Turns a source/destination pair into the concrete literal replacements a
 * WordPress database actually needs.
 */
class RuleBuilder {

	/**
	 * Build a replacer for a URL migration.
	 *
	 * @param string $source  Source site URL.
	 * @param string $target  Destination site URL.
	 * @param array  $options include_bare_domain, paths (array of from => to).
	 * @return Replacer
	 */
	public static function forUrls( $source, $target, array $options = array() ) {
		$replacer = new Replacer();

		$source = self::normalizeUrl( $source );
		$target = self::normalizeUrl( $target );

		if ( '' !== $source && $source !== $target ) {
			$source_parts = wp_parse_url( $source );
			$target_parts = wp_parse_url( $target );

			$source_host = isset( $source_parts['host'] ) ? $source_parts['host'] : '';
			$source_auth = self::authority( $source_parts );
			$target_auth = self::authority( $target_parts );
			$source_path = isset( $source_parts['path'] ) ? rtrim( $source_parts['path'], '/' ) : '';
			$target_path = isset( $target_parts['path'] ) ? rtrim( $target_parts['path'], '/' ) : '';

			$source_base = $source_auth . $source_path;
			$target_base = $target_auth . $target_path;

			// Absolute URLs, both schemes: a site moved from https to http (or
			// the other way) still has the old scheme all over its content.
			foreach ( array( 'https', 'http' ) as $scheme ) {
				$from = $scheme . '://' . $source_base;
				$to   = $target;
				self::addVariants( $replacer, $from, $to );
			}

			// Protocol relative references.
			self::addVariants( $replacer, '//' . $source_base, '//' . $target_base );

			if ( '' !== $source_host ) {
				$replacer->addProbe( $source_host );
				$replacer->addReportHost( $source_host );
			}

			if ( ! empty( $options['include_bare_domain'] ) && '' !== $source_host ) {
				$target_host = isset( $target_parts['host'] ) ? $target_parts['host'] : '';
				if ( '' !== $target_host && $source_host !== $target_host ) {
					self::addVariants( $replacer, $source_host, $target_host );
				}
			}
		}

		// Filesystem paths: Elementor CSS, caching plugins and a few builders
		// store absolute server paths.
		if ( ! empty( $options['paths'] ) && is_array( $options['paths'] ) ) {
			foreach ( $options['paths'] as $from => $to ) {
				$from = rtrim( (string) $from, '/' );
				$to   = rtrim( (string) $to, '/' );
				if ( '' === $from || $from === $to || strlen( $from ) < 4 ) {
					continue;
				}
				self::addVariants( $replacer, $from, $to );
				$replacer->addProbe( $from );
			}
		}

		return $replacer;
	}

	/**
	 * Add a rule together with its encoded variants.
	 *
	 * @param Replacer $replacer Replacer.
	 * @param string   $from     Source literal.
	 * @param string   $to       Replacement.
	 * @return void
	 */
	public static function addVariants( Replacer $replacer, $from, $to ) {
		if ( '' === $from || $from === $to ) {
			return;
		}

		$replacer->addRule( $from, $to );

		// JSON and Gutenberg block attributes escape forward slashes.
		$escaped_from = str_replace( '/', '\\/', $from );
		$escaped_to   = str_replace( '/', '\\/', $to );
		if ( $escaped_from !== $from ) {
			$replacer->addRule( $escaped_from, $escaped_to );
		}

		// Percent encoded URLs appear in redirects, oEmbed caches and REST
		// arguments stored in the database.
		$encoded_from = rawurlencode( $from );
		$encoded_to   = rawurlencode( $to );
		if ( $encoded_from !== $from ) {
			$replacer->addRule( $encoded_from, $encoded_to );
			$replacer->addRule( strtolower( $encoded_from ), $encoded_to );
		}

		// Unicode escaped slashes, produced by some JavaScript based builders.
		$unicode_from = str_replace( '/', '\\u002F', $from );
		if ( $unicode_from !== $from ) {
			$replacer->addRule( $unicode_from, str_replace( '/', '\\u002F', $to ) );
			$replacer->addRule( str_replace( '/', '\\u002f', $from ), str_replace( '/', '\\u002f', $to ) );
		}
	}

	/**
	 * Normalise a URL: no trailing slash, lower case scheme and host.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalizeUrl( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$out    = $scheme . '://' . self::authority( $parts );
		if ( ! empty( $parts['path'] ) ) {
			$out .= rtrim( $parts['path'], '/' );
		}
		return $out;
	}

	/**
	 * Host plus optional port and credentials.
	 *
	 * @param array $parts Parsed URL.
	 * @return string
	 */
	protected static function authority( array $parts ) {
		$authority = '';
		if ( ! empty( $parts['user'] ) ) {
			$authority .= $parts['user'];
			if ( ! empty( $parts['pass'] ) ) {
				$authority .= ':' . $parts['pass'];
			}
			$authority .= '@';
		}
		$authority .= isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( ! empty( $parts['port'] ) ) {
			$authority .= ':' . $parts['port'];
		}
		return $authority;
	}
}
