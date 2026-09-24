<?php
/**
 * Path exclusion rules.
 *
 * @package SHCM
 */

namespace SHCM\Filesystem;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Glob style exclusion matching against paths relative to a logical root.
 *
 * Supported syntax:
 *   wp-content/cache      a directory or file, and everything below it
 *   /wp-content/cache     the same, anchored at the root
 *   *.log                 any file with that extension, at any depth
 *   * /node_modules       any directory with that name, at any depth
 *   uploads/2019/ *       everything below a directory
 *
 * A pattern without a slash ("cache", "*.log") matches that name at any
 * depth. Patterns added as anchored (the "Excluded directories" setting,
 * documented as paths relative to the WordPress root) never do: "cache"
 * there means the top-level cache directory only, not every directory called
 * cache inside every plugin.
 */
class ExclusionMatcher {

	/**
	 * Compiled patterns.
	 *
	 * @var array[]
	 */
	protected $patterns = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $patterns Raw patterns.
	 */
	public function __construct( array $patterns = array() ) {
		foreach ( $patterns as $pattern ) {
			$this->add( $pattern );
		}
	}

	/**
	 * Add patterns that only ever match from the root.
	 *
	 * @param string[] $patterns Raw patterns.
	 * @return self
	 */
	public function addAnchored( array $patterns ) {
		foreach ( $patterns as $pattern ) {
			$this->add( $pattern, true );
		}
		return $this;
	}

	/**
	 * Add a pattern.
	 *
	 * @param string $pattern  Raw pattern.
	 * @param bool   $anchored Match from the root only, never by name at any depth.
	 * @return self
	 */
	public function add( $pattern, $anchored = false ) {
		$pattern = trim( str_replace( '\\', '/', (string) $pattern ) );
		$pattern = ltrim( $pattern, '/' );
		$pattern = rtrim( $pattern, '/' );
		if ( '' === $pattern ) {
			return $this;
		}

		$has_wildcard = ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) );
		$has_slash    = ( false !== strpos( $pattern, '/' ) );

		$this->patterns[] = array(
			'raw'      => $pattern,
			'wildcard' => $has_wildcard,
			'slash'    => $has_slash || (bool) $anchored,
			'regex'    => $has_wildcard ? $this->toRegex( $pattern ) : '',
		);
		return $this;
	}

	/**
	 * Configured patterns.
	 *
	 * @return string[]
	 */
	public function patterns() {
		$out = array();
		foreach ( $this->patterns as $pattern ) {
			$out[] = $pattern['raw'];
		}
		return $out;
	}

	/**
	 * Whether any of several spellings of a path is excluded.
	 *
	 * @param string[] $paths Candidate relative paths.
	 * @return bool
	 */
	public function matchesAny( array $paths ) {
		foreach ( $paths as $path ) {
			if ( $this->matches( $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a relative path is excluded.
	 *
	 * @param string $relative Path relative to a logical root, forward slashes.
	 * @return bool
	 */
	public function matches( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		if ( '' === $relative ) {
			return false;
		}
		$basename = basename( $relative );

		foreach ( $this->patterns as $pattern ) {
			if ( ! $pattern['wildcard'] ) {
				// Literal: the path itself or anything below it.
				if ( $relative === $pattern['raw'] ) {
					return true;
				}
				if ( 0 === strncmp( $relative, $pattern['raw'] . '/', strlen( $pattern['raw'] ) + 1 ) ) {
					return true;
				}
				if ( ! $pattern['slash'] && $basename === $pattern['raw'] ) {
					return true;
				}
				continue;
			}

			if ( preg_match( $pattern['regex'], $relative ) ) {
				return true;
			}
			if ( ! $pattern['slash'] && preg_match( $pattern['regex'], $basename ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a glob pattern into a regular expression.
	 *
	 * @param string $pattern Glob pattern.
	 * @return string
	 */
	protected function toRegex( $pattern ) {
		$regex  = '';
		$length = strlen( $pattern );
		$start  = 0;

		// A leading "*/" (or "**/") means "at any depth", which is how these
		// patterns are written everywhere else: */node_modules has to match
		// wp-content/themes/x/node_modules, not just top-level directories.
		if ( 0 === strpos( $pattern, '**/' ) ) {
			$regex = '(?:.*/)?';
			$start = 3;
		} elseif ( 0 === strpos( $pattern, '*/' ) ) {
			$regex = '(?:.*/)?';
			$start = 2;
		}

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '*' === $char ) {
				if ( $i + 1 < $length && '*' === $pattern[ $i + 1 ] ) {
					$regex .= '.*';
					++$i;
					continue;
				}
				$regex .= '[^/]*';
				continue;
			}
			if ( '?' === $char ) {
				$regex .= '[^/]';
				continue;
			}
			$regex .= preg_quote( $char, '#' );
		}

		// A pattern matches the path itself and everything below it.
		return '#^' . $regex . '(/.*)?$#';
	}

	/**
	 * Whether any pattern is configured.
	 *
	 * @return bool
	 */
	public function isEmpty() {
		return empty( $this->patterns );
	}
}
