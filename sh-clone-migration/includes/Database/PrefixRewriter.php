<?php
/**
 * Table prefix translation.
 *
 * @package SHCM
 */

namespace SHCM\Database;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Rewrites the source installation's table prefix to the destination's.
 *
 * The destination keeps its own wp-config.php, so its $table_prefix is
 * authoritative; the dump is adapted to it instead of the other way round.
 *
 * Only identifier positions are rewritten. For INSERT statements that means
 * strictly the table name, never the row data, so a post that happens to
 * contain the text `wp_posts` is left untouched.
 */
class PrefixRewriter {

	/**
	 * Source prefix.
	 *
	 * @var string
	 */
	protected $source;

	/**
	 * Destination prefix.
	 *
	 * @var string
	 */
	protected $target;

	/**
	 * Constructor.
	 *
	 * @param string $source Source prefix.
	 * @param string $target Destination prefix.
	 */
	public function __construct( $source, $target ) {
		$this->source = (string) $source;
		$this->target = (string) $target;
	}

	/**
	 * Whether any rewriting is needed at all.
	 *
	 * @return bool
	 */
	public function isNoop() {
		return '' === $this->source || $this->source === $this->target;
	}

	/**
	 * Source prefix.
	 *
	 * @return string
	 */
	public function source() {
		return $this->source;
	}

	/**
	 * Destination prefix.
	 *
	 * @return string
	 */
	public function target() {
		return $this->target;
	}

	/**
	 * Translate a table name.
	 *
	 * @param string $name Table name.
	 * @return string
	 */
	public function table( $name ) {
		if ( $this->isNoop() ) {
			return $name;
		}
		if ( 0 === strncmp( $name, $this->source, strlen( $this->source ) ) ) {
			return $this->target . substr( $name, strlen( $this->source ) );
		}
		return $name;
	}

	/**
	 * Rewrite the identifiers of a single SQL statement.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	public function statement( $sql ) {
		if ( $this->isNoop() ) {
			return $sql;
		}

		$head = strtoupper( substr( ltrim( $sql ), 0, 24 ) );

		// Data carrying statements: only the table name may be touched.
		$data_statements = array( 'INSERT ', 'REPLACE ', 'UPDATE ', 'DELETE ', 'LOCK ', 'TRUNCATE ', 'ALTER ', 'DROP ' );
		foreach ( $data_statements as $keyword ) {
			if ( 0 === strpos( $head, $keyword ) ) {
				return $this->replaceIdentifiers( $sql, 1 );
			}
		}

		// DDL: rewrite every prefixed identifier so that foreign keys and view
		// bodies point at the renamed tables too.
		if ( 0 === strpos( $head, 'CREATE ' ) ) {
			return $this->replaceIdentifiers( $sql, -1 );
		}

		return $this->replaceIdentifiers( $sql, 1 );
	}

	/**
	 * Replace backtick quoted identifiers that start with the source prefix.
	 *
	 * @param string $sql   Statement.
	 * @param int    $limit Maximum replacements, -1 for all.
	 * @return string
	 */
	protected function replaceIdentifiers( $sql, $limit ) {
		$source = $this->source;
		$target = $this->target;

		$replaced = preg_replace_callback(
			'/`((?:[^`]|``)*)`/',
			static function ( $matches ) use ( $source, $target ) {
				$name = $matches[1];
				if ( 0 === strncmp( $name, $source, strlen( $source ) ) ) {
					return '`' . $target . substr( $name, strlen( $source ) ) . '`';
				}
				return $matches[0];
			},
			$sql,
			$limit
		);

		return null === $replaced ? $sql : $replaced;
	}

	/**
	 * Meta keys and option names that embed the table prefix.
	 *
	 * These are the only prefixed values in WordPress data. Anything else that
	 * merely starts with "wp_" (plugin options such as wp_mail_smtp) must not
	 * be touched, which is why this is a whitelist rather than a LIKE query.
	 *
	 * @return string[] Regular expressions matched against the part after the prefix.
	 */
	public static function prefixedUserMetaKeys() {
		return array(
			'capabilities',
			'user_level',
			'user-settings',
			'user-settings-time',
			'dashboard_quick_press_last_post_id',
			'media_library_mode',
			'persisted_preferences',
			'autosave_draft_ids',
			'metaboxhidden_.*',
			'meta-box-order_.*',
			'closedpostboxes_.*',
			'screen_layout_.*',
			'manage.*columnshidden',
		);
	}

	/**
	 * Option names that embed the table prefix.
	 *
	 * @return string[]
	 */
	public static function prefixedOptionNames() {
		return array( 'user_roles' );
	}

	/**
	 * Rewrite prefixed option names and user meta keys after an import.
	 *
	 * @param \wpdb $db Database handle.
	 * @return array Report: option_rows, usermeta_rows.
	 */
	public function rewriteStoredKeys( $db ) {
		$report = array(
			'options'  => 0,
			'usermeta' => 0,
		);
		if ( $this->isNoop() ) {
			return $report;
		}

		$options_table  = $this->target . 'options';
		$usermeta_table = $this->target . 'usermeta';

		foreach ( self::prefixedOptionNames() as $name ) {
			$old = $this->source . $name;
			$new = $this->target . $name;
			$updated = $db->query(
				$db->prepare(
					"UPDATE `{$options_table}` SET option_name = %s WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$new,
					$old
				)
			);
			$report['options'] += max( 0, (int) $updated );
		}

		// Multisite stores per-site capabilities as {base_prefix}{blog_id}_capabilities.
		$pattern = '^' . preg_quote( $this->source, '/' ) . '([0-9]+_)?(' . implode( '|', self::prefixedUserMetaKeys() ) . ')$';
		$rows    = $db->get_col(
			$db->prepare(
				"SELECT DISTINCT meta_key FROM `{$usermeta_table}` WHERE meta_key REGEXP %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$pattern
			)
		);
		foreach ( (array) $rows as $key ) {
			$new     = $this->target . substr( $key, strlen( $this->source ) );
			$updated = $db->query(
				$db->prepare(
					"UPDATE `{$usermeta_table}` SET meta_key = %s WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$new,
					$key
				)
			);
			$report['usermeta'] += max( 0, (int) $updated );
		}

		return $report;
	}
}
