<?php
/**
 * Extraction path safety.
 *
 * @package SHCM
 */

namespace SHCM\Filesystem;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Validates every path an archive asks to write.
 *
 * An archive is untrusted input: it can be uploaded by anyone with import
 * rights and may have been produced anywhere. Nothing is written until the
 * resolved destination is proven to sit inside the target directory.
 */
class SafePath {

	/**
	 * Reject a relative path that tries to escape its root.
	 *
	 * @param string $relative Relative path from an archive entry.
	 * @return string|null Normalised relative path, or null when unsafe.
	 */
	public static function sanitizeRelative( $relative ) {
		$relative = (string) $relative;

		if ( '' === $relative ) {
			return null;
		}
		if ( false !== strpos( $relative, "\0" ) ) {
			return null;
		}

		// Where the backslash is a directory separator (Windows) it must be
		// treated as one before the ".." check. Elsewhere it is an ordinary
		// character in a file name and is kept as such.
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$relative = str_replace( '\\', '/', $relative );
		}

		// Absolute paths and Windows drive letters.
		if ( '/' === $relative[0] ) {
			return null;
		}
		if ( preg_match( '#^[a-zA-Z]:#', $relative ) ) {
			return null;
		}
		// Stream wrappers such as phar:// or http://.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $relative ) ) {
			return null;
		}

		$parts  = explode( '/', $relative );
		$output = array();
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return null; // Never resolve upwards: reject outright.
			}
			$output[] = $part;
		}

		if ( empty( $output ) ) {
			return null;
		}

		return implode( '/', $output );
	}

	/**
	 * Resolve a relative path inside a base directory.
	 *
	 * @param string $base     Base directory (must exist).
	 * @param string $relative Relative path.
	 * @return string|null Absolute path, or null when unsafe.
	 */
	public static function resolve( $base, $relative ) {
		$clean = self::sanitizeRelative( $relative );
		if ( null === $clean ) {
			return null;
		}
		$base = Paths::normalize( $base );
		$full = $base . '/' . $clean;

		// Belt and braces: the lexical check above already rejects traversal,
		// this catches a base directory that is itself a symlink target.
		if ( ! Paths::isInside( Paths::normalize( $full ), $base ) ) {
			return null;
		}

		return $full;
	}

	/**
	 * Whether writing to a path would follow a symlink out of the base.
	 *
	 * @param string $base Base directory.
	 * @param string $full Absolute target path.
	 * @return bool
	 */
	public static function escapesViaSymlink( $base, $full ) {
		$base = Paths::normalize( $base );
		$real_base = realpath( $base );
		if ( false === $real_base ) {
			return false;
		}
		$real_base = Paths::normalize( $real_base );

		// Walk up to the closest existing ancestor and resolve that. A link
		// counts as existing even when it dangles (file_exists() says no),
		// because writing to it would create whatever it points at.
		$candidate = $full;
		while ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
			$parent = dirname( $candidate );
			if ( $parent === $candidate ) {
				return false;
			}
			$candidate = $parent;
		}

		$real = realpath( $candidate );
		if ( false === $real ) {
			// A dangling link: where it leads cannot be proven safe.
			return is_link( $candidate );
		}
		return ! Paths::isInside( Paths::normalize( $real ), $real_base );
	}

	/**
	 * Whether a file name is acceptable to write.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public static function isAcceptableName( $relative ) {
		$basename = basename( $relative );
		if ( '' === $basename ) {
			return false;
		}
		// Control characters have no business in a file name.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $relative ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Create a directory tree inside a base directory, safely.
	 *
	 * @param string $base     Base directory.
	 * @param string $relative Relative directory path.
	 * @param int    $mode     Directory mode.
	 * @return string|null Absolute path, or null on failure.
	 */
	public static function makeDirectory( $base, $relative, $mode = 0755 ) {
		$full = self::resolve( $base, $relative );
		if ( null === $full ) {
			return null;
		}
		if ( is_dir( $full ) ) {
			return $full;
		}
		if ( ! @mkdir( $full, $mode, true ) && ! is_dir( $full ) ) {
			return null;
		}
		return $full;
	}
}
