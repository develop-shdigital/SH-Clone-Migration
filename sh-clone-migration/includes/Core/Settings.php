<?php
/**
 * Plugin settings.
 *
 * @package SHCM
 */

namespace SHCM\Core;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Typed access to the single settings option.
 */
class Settings {

	const OPTION = 'shcm_settings';

	/**
	 * Cached values.
	 *
	 * @var array|null
	 */
	protected $values = null;

	/**
	 * Default settings.
	 *
	 * Every default is chosen so that an out-of-the-box migration is a complete
	 * clone: nothing important is excluded, nothing is capped.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// What goes into the archive.
			'include_core'          => false,
			'include_database'      => true,
			'use_default_exclusions' => true,
			'exclude_directories'   => array(),
			'exclude_patterns'      => array(),
			'exclude_large_files'   => 0, // Bytes; 0 = no limit.
			'include_dropins'       => true,

			// Engine tuning. These are performance knobs, not migration limits.
			'block_size'            => 1048576,
			'compression'           => 'auto', // auto|gzip|none.
			'compression_level'     => 6,
			'time_budget'           => 0, // Seconds per request; 0 = auto detect.
			'memory_guard'          => 80, // Percent of the PHP memory limit.
			'db_rows_per_query'     => 2000,
			'db_max_insert_bytes'   => 524288,
			'upload_chunk_size'     => 5242880,

			// Verification and retention.
			'verify_mode'           => 'full', // full|quick.
			'retention_days'        => 30,
			'max_archives'          => 0, // 0 = unlimited.
			'cleanup_temp_hours'    => 24,

			// Import behaviour.
			'import_mode'           => 'replace', // replace|merge.
			'create_rollback_point' => true,
			'maintenance_mode'      => true,
			'replace_urls'          => true,
			'replace_paths'         => true,
			'keep_destination_prefix' => true,
			'restore_active_plugins' => true,
			'reactivate_self'       => true,

			// Housekeeping.
			'log_level'             => 'info', // debug|info|warning|error.
			'delete_data_on_uninstall' => false,
			'enable_cron_worker'    => true,
		);
	}

	/**
	 * Directory fragments excluded by default because restoring them is either
	 * harmful or pointless. Matched against the path relative to a logical root.
	 *
	 * @return string[]
	 */
	public static function defaultExclusions() {
		return array(
			'wp-content/' . \SHCM\Filesystem\Storage::DIR_NAME,
			'wp-content/cache',
			'wp-content/wflogs',
			'wp-content/updraft',
			'wp-content/ai1wm-backups',
			'wp-content/backups-dup-pro',
			'wp-content/backupwordpress',
			'wp-content/uploads/backwpup*',
			'wp-content/uploads/wp-clone',
			'wp-content/uploads/cache',
			'wp-content/uploads/wp-file-manager-pro/fm_backup',
			'wp-content/et-cache',
			'wp-content/debug.log',
			'wp-content/w3tc-config',
			'wp-content/advanced-cache.php',
			'*/node_modules',
			'*/.git',
			'*/.svn',
			'*/.DS_Store',
			'*.wpress',
		);
	}

	/**
	 * Read all settings.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->values ) {
			$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$this->values = array_merge( self::defaults(), $stored );
		}
		return $this->values;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Read a setting as a positive integer.
	 *
	 * @param string $key     Setting key.
	 * @param int    $default Fallback.
	 * @return int
	 */
	public function getInt( $key, $default = 0 ) {
		$value = $this->get( $key, $default );
		return is_numeric( $value ) ? (int) $value : (int) $default;
	}

	/**
	 * Read a boolean setting.
	 *
	 * @param string $key     Setting key.
	 * @param bool   $default Fallback.
	 * @return bool
	 */
	public function getBool( $key, $default = false ) {
		$value = $this->get( $key, $default );
		return (bool) $value;
	}

	/**
	 * Persist a set of settings after sanitising them.
	 *
	 * @param array $input Raw input.
	 * @return array Stored values.
	 */
	public function update( array $input ) {
		$current = $this->all();
		$clean   = $this->sanitize( $input, $current );
		$this->values = array_merge( $current, $clean );
		update_option( self::OPTION, $this->values, false );
		return $this->values;
	}

	/**
	 * Sanitise raw settings input.
	 *
	 * @param array $input   Raw input.
	 * @param array $current Current values (used for type hints).
	 * @return array
	 */
	public function sanitize( array $input, array $current ) {
		$clean    = array();
		$defaults = self::defaults();

		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$default = $defaults[ $key ];
			if ( is_bool( $default ) ) {
				$clean[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = max( 0, (int) $value );
			} elseif ( is_array( $default ) ) {
				$clean[ $key ] = $this->sanitizeList( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		// Constrain the enumerated values.
		if ( isset( $clean['compression'] ) && ! in_array( $clean['compression'], array( 'auto', 'gzip', 'none' ), true ) ) {
			$clean['compression'] = 'auto';
		}
		if ( isset( $clean['verify_mode'] ) && ! in_array( $clean['verify_mode'], array( 'full', 'quick' ), true ) ) {
			$clean['verify_mode'] = 'full';
		}
		if ( isset( $clean['import_mode'] ) && ! in_array( $clean['import_mode'], array( 'replace', 'merge' ), true ) ) {
			$clean['import_mode'] = 'replace';
		}
		if ( isset( $clean['log_level'] ) && ! in_array( $clean['log_level'], array( 'debug', 'info', 'warning', 'error' ), true ) ) {
			$clean['log_level'] = 'info';
		}
		if ( isset( $clean['block_size'] ) ) {
			$clean['block_size'] = min( 33554432, max( 65536, $clean['block_size'] ) );
		}
		if ( isset( $clean['compression_level'] ) ) {
			$clean['compression_level'] = min( 9, max( 1, $clean['compression_level'] ) );
		}
		if ( isset( $clean['memory_guard'] ) ) {
			$clean['memory_guard'] = min( 95, max( 40, $clean['memory_guard'] ) );
		}
		if ( isset( $clean['db_rows_per_query'] ) ) {
			$clean['db_rows_per_query'] = min( 50000, max( 50, $clean['db_rows_per_query'] ) );
		}
		if ( isset( $clean['upload_chunk_size'] ) ) {
			$clean['upload_chunk_size'] = min( 104857600, max( 262144, $clean['upload_chunk_size'] ) );
		}

		return $clean;
	}

	/**
	 * Turn a textarea/array into a clean list of trimmed strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	protected function sanitizeList( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $line ) {
			$line = trim( (string) $line );
			// Reject anything that tries to escape the installation.
			$line = str_replace( array( "\0", '..' ), '', $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Reset the in-memory cache (used after a database replacement).
	 *
	 * @return void
	 */
	public function flush() {
		$this->values = null;
	}
}
