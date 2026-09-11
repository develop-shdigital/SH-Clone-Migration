<?php
/**
 * Path normalisation and logical root mapping.
 *
 * @package SHCM
 */

namespace SHCM\Filesystem;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Translates between absolute filesystem paths and the logical paths stored in
 * an archive.
 *
 * A WordPress install does not necessarily keep wp-content (or uploads, or the
 * plugin directory) inside ABSPATH, and the destination may lay them out
 * differently again. Every archived file therefore carries a logical root name
 * plus a path relative to that root, and the importer resolves the root against
 * whatever the destination happens to use.
 */
class Paths {

	const ROOT_CONTENT    = 'wp-content';
	const ROOT_PLUGINS    = 'plugins';
	const ROOT_MU_PLUGINS = 'mu-plugins';
	const ROOT_UPLOADS    = 'uploads';
	const ROOT_CORE       = 'wp-root';

	/**
	 * Normalise a filesystem path: forward slashes, no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		if ( '/' === $path ) {
			return '/';
		}
		return rtrim( $path, '/' );
	}

	/**
	 * Normalise and add a trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function trailingslash( $path ) {
		$path = self::normalize( $path );
		return '/' === $path ? '/' : $path . '/';
	}

	/**
	 * The WordPress installation root.
	 *
	 * @return string
	 */
	public static function abspath() {
		return self::normalize( ABSPATH );
	}

	/**
	 * The wp-content directory.
	 *
	 * @return string
	 */
	public static function contentDir() {
		return self::normalize( WP_CONTENT_DIR );
	}

	/**
	 * The plugin directory.
	 *
	 * @return string
	 */
	public static function pluginDir() {
		return self::normalize( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' );
	}

	/**
	 * The must-use plugin directory.
	 *
	 * @return string
	 */
	public static function muPluginDir() {
		return self::normalize( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
	}

	/**
	 * The uploads base directory.
	 *
	 * @return string
	 */
	public static function uploadsDir() {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			// For multisite subsites basedir points at .../uploads/sites/N; the
			// whole uploads tree is what we want to move.
			$basedir = self::normalize( $uploads['basedir'] );
			if ( is_multisite() ) {
				$basedir = preg_replace( '#/sites/\d+$#', '', $basedir );
			}
			return $basedir;
		}
		return self::contentDir() . '/uploads';
	}

	/**
	 * Whether $child sits inside $parent.
	 *
	 * @param string $child  Candidate child path.
	 * @param string $parent Candidate parent path.
	 * @return bool
	 */
	public static function isInside( $child, $parent ) {
		$child  = self::normalize( $child );
		$parent = self::normalize( $parent );
		if ( $child === $parent ) {
			return true;
		}
		return 0 === strncmp( $child . '/', $parent . '/', strlen( $parent ) + 1 );
	}

	/**
	 * Path of $path relative to $base, or null when it is not inside $base.
	 *
	 * @param string $path Path.
	 * @param string $base Base directory.
	 * @return string|null
	 */
	public static function relativeTo( $path, $base ) {
		$path = self::normalize( $path );
		$base = self::normalize( $base );
		if ( $path === $base ) {
			return '';
		}
		if ( 0 !== strncmp( $path, $base . '/', strlen( $base ) + 1 ) ) {
			return null;
		}
		return substr( $path, strlen( $base ) + 1 );
	}

	/**
	 * Build the map of logical roots for this installation.
	 *
	 * Roots that live inside another root are skipped: their files are already
	 * covered by the parent walk.
	 *
	 * @param bool $include_core Include the WordPress core/root files.
	 * @return array<string,string> Logical root name => absolute path.
	 */
	public static function roots( $include_core = false ) {
		$roots = array();

		if ( $include_core ) {
			$roots[ self::ROOT_CORE ] = self::abspath();
		}

		$content = self::contentDir();
		if ( ! isset( $roots[ self::ROOT_CORE ] ) || ! self::isInside( $content, $roots[ self::ROOT_CORE ] ) ) {
			$roots[ self::ROOT_CONTENT ] = $content;
		}

		$extra = array(
			self::ROOT_PLUGINS    => self::pluginDir(),
			self::ROOT_MU_PLUGINS => self::muPluginDir(),
			self::ROOT_UPLOADS    => self::uploadsDir(),
		);
		foreach ( $extra as $name => $path ) {
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$covered = false;
			foreach ( $roots as $existing ) {
				if ( self::isInside( $path, $existing ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				$roots[ $name ] = $path;
			}
		}

		return $roots;
	}

	/**
	 * Resolve the destination directory for a logical root name.
	 *
	 * @param string $root Logical root name.
	 * @return string|null Absolute path, or null when unknown.
	 */
	public static function resolveRoot( $root ) {
		switch ( $root ) {
			case self::ROOT_CONTENT:
				return self::contentDir();
			case self::ROOT_PLUGINS:
				return self::pluginDir();
			case self::ROOT_MU_PLUGINS:
				return self::muPluginDir();
			case self::ROOT_UPLOADS:
				return self::uploadsDir();
			case self::ROOT_CORE:
				return self::abspath();
		}
		return null;
	}

	/**
	 * Progress/reporting group for a logical path.
	 *
	 * @param string $root     Logical root name.
	 * @param string $relative Path relative to the root.
	 * @return string
	 */
	public static function group( $root, $relative ) {
		if ( self::ROOT_CONTENT === $root ) {
			$first = strtok( $relative, '/' );
			switch ( $first ) {
				case 'plugins':
					return 'plugins';
				case 'themes':
					return 'themes';
				case 'uploads':
					return 'uploads';
				case 'mu-plugins':
					return 'mu-plugins';
				case 'languages':
					return 'languages';
			}
			return 'other';
		}
		if ( self::ROOT_CORE === $root ) {
			return 'core';
		}
		return $root;
	}

	/**
	 * Ordered list of the groups the UI reports on.
	 *
	 * @return string[]
	 */
	public static function groups() {
		return array( 'plugins', 'themes', 'mu-plugins', 'uploads', 'languages', 'other', 'core' );
	}
}
