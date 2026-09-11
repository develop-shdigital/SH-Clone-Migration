<?php
/**
 * Database discovery.
 *
 * @package SHCM
 */

namespace SHCM\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers what is actually in the database instead of assuming a standard
 * WordPress schema.
 *
 * Table prefixes are read from $wpdb, never hard coded, and every table in the
 * schema is classified so that a shared database holding a second WordPress
 * installation is not swept into the archive.
 */
class Inspector {

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Cached table list.
	 *
	 * @var array|null
	 */
	protected $cache = null;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Database handle.
	 */
	public function __construct( $db = null ) {
		global $wpdb;
		$this->db = $db ? $db : $wpdb;
	}

	/**
	 * The prefix that identifies this installation's tables.
	 *
	 * For multisite this is the network wide base prefix, so that per-site
	 * tables (wp_2_posts, ...) are included too.
	 *
	 * @return string
	 */
	public function prefix() {
		return is_multisite() ? $this->db->base_prefix : $this->db->prefix;
	}

	/**
	 * Database name.
	 *
	 * @return string
	 */
	public function databaseName() {
		return defined( 'DB_NAME' ) ? DB_NAME : (string) $this->db->get_var( 'SELECT DATABASE()' );
	}

	/**
	 * Every table and view in the current schema, with classification.
	 *
	 * @param bool $refresh Bypass the cache.
	 * @return array[] Each entry: name, type, engine, rows, data_length, index_length, collation, owned.
	 */
	public function inventory( $refresh = false ) {
		if ( null !== $this->cache && ! $refresh ) {
			return $this->cache;
		}

		$prefix  = $this->prefix();
		$schema  = $this->databaseName();
		$results = $this->db->get_results(
			$this->db->prepare(
				'SELECT TABLE_NAME AS name, TABLE_TYPE AS table_type, ENGINE AS engine, TABLE_ROWS AS row_estimate,
				        DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, TABLE_COLLATION AS collation,
				        AUTO_INCREMENT AS auto_increment
				 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
				$schema
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			// Hosts occasionally restrict information_schema; fall back to SHOW.
			$results = array();
			$rows    = $this->db->get_results( 'SHOW FULL TABLES', ARRAY_N );
			foreach ( (array) $rows as $row ) {
				$results[] = array(
					'name'         => $row[0],
					'table_type'   => isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) ? 'VIEW' : 'BASE TABLE',
					'engine'       => '',
					'row_estimate' => 0,
					'data_length'  => 0,
					'index_length' => 0,
					'collation'    => '',
					'auto_increment' => null,
				);
			}
		}

		$foreign_prefixes = $this->foreignPrefixes( $results, $prefix );

		$inventory = array();
		foreach ( (array) $results as $row ) {
			$name  = $row['name'];
			$owned = true;
			if ( 0 !== strncmp( $name, $prefix, strlen( $prefix ) ) ) {
				// Not one of ours by prefix: keep it only when it does not
				// belong to another WordPress installation sharing this schema.
				$owned = true;
				foreach ( $foreign_prefixes as $foreign ) {
					if ( 0 === strncmp( $name, $foreign, strlen( $foreign ) ) ) {
						$owned = false;
						break;
					}
				}
			}

			$inventory[ $name ] = array(
				'name'           => $name,
				'type'           => 'VIEW' === strtoupper( (string) $row['table_type'] ) ? 'view' : 'table',
				'engine'         => (string) $row['engine'],
				'rows'           => (int) $row['row_estimate'],
				'data_length'    => (int) $row['data_length'],
				'index_length'   => (int) $row['index_length'],
				'collation'      => (string) $row['collation'],
				'auto_increment' => null === $row['auto_increment'] ? null : (int) $row['auto_increment'],
				'prefixed'       => 0 === strncmp( $name, $prefix, strlen( $prefix ) ),
				'owned'          => $owned,
			);
		}

		ksort( $inventory );
		$this->cache = $inventory;
		return $inventory;
	}

	/**
	 * Detect prefixes belonging to other WordPress installations in the same
	 * database, so their tables are never cloned by accident.
	 *
	 * @param array  $rows   information_schema rows.
	 * @param string $prefix Our own prefix.
	 * @return string[]
	 */
	protected function foreignPrefixes( array $rows, $prefix ) {
		$found = array();
		foreach ( $rows as $row ) {
			$name = $row['name'];
			if ( ! preg_match( '/^(.*)options$/', $name, $matches ) ) {
				continue;
			}
			$candidate = $matches[1];
			if ( '' === $candidate ) {
				continue;
			}
			if ( 0 === strncmp( $candidate, $prefix, strlen( $prefix ) ) ) {
				continue; // Ours (including multisite per-site prefixes).
			}
			if ( 0 === strncmp( $prefix, $candidate, strlen( $candidate ) ) ) {
				continue; // We are a longer prefix of this one; keep it.
			}
			$found[] = $candidate;
		}
		return array_unique( $found );
	}

	/**
	 * Forget the cached inventory.
	 *
	 * Anything that changes the schema underneath a long running job (an
	 * import replacing every table, for example) must call this, or the next
	 * consumer works from a snapshot of a database that no longer exists.
	 *
	 * @return void
	 */
	public function flush() {
		$this->cache = null;
	}

	/**
	 * Tables selected for export.
	 *
	 * @param array $options include_foreign, exclude (names).
	 * @return array[] Inventory entries.
	 */
	public function exportableTables( array $options = array() ) {
		$include_foreign = ! empty( $options['include_foreign'] );
		$exclude         = isset( $options['exclude'] ) ? (array) $options['exclude'] : array();
		$selected        = array();

		foreach ( $this->inventory() as $name => $info ) {
			if ( in_array( $name, $exclude, true ) ) {
				continue;
			}
			if ( ! $info['owned'] && ! $include_foreign ) {
				continue;
			}
			$selected[ $name ] = $info;
		}
		return $selected;
	}

	/**
	 * Column metadata for a table.
	 *
	 * @param string $table Table name.
	 * @return array[] name => array(type, base_type, is_binary, is_numeric, nullable, key).
	 */
	public function columns( $table ) {
		$rows    = $this->db->get_results( 'SHOW FULL COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`', ARRAY_A );
		$columns = array();
		foreach ( (array) $rows as $row ) {
			$type = strtolower( (string) $row['Type'] );
			$base = preg_replace( '/\(.*$/', '', $type );
			$base = trim( preg_replace( '/\s+(unsigned|zerofill)/', '', $base ) );

			$columns[ $row['Field'] ] = array(
				'name'       => $row['Field'],
				'type'       => $type,
				'base_type'  => $base,
				'is_binary'  => in_array( $base, array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit' ), true ),
				'is_numeric' => in_array( $base, array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'float', 'double', 'decimal', 'numeric', 'real', 'year' ), true ),
				'is_text'    => in_array( $base, array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'enum', 'set' ), true ),
				'nullable'   => 'YES' === $row['Null'],
				'key'        => $row['Key'],
				'extra'      => $row['Extra'],
			);
		}
		return $columns;
	}

	/**
	 * Primary (or best unique) key columns for a table.
	 *
	 * @param string $table Table name.
	 * @return string[] Column names, empty when the table has no usable key.
	 */
	public function keyColumns( $table ) {
		$rows    = $this->db->get_results( 'SHOW INDEX FROM `' . str_replace( '`', '``', $table ) . '`', ARRAY_A );
		$indexes = array();
		foreach ( (array) $rows as $row ) {
			if ( '0' !== (string) $row['Non_unique'] ) {
				continue;
			}
			$indexes[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		if ( isset( $indexes['PRIMARY'] ) ) {
			ksort( $indexes['PRIMARY'] );
			return array_values( $indexes['PRIMARY'] );
		}
		foreach ( $indexes as $columns ) {
			ksort( $columns );
			return array_values( $columns );
		}
		return array();
	}

	/**
	 * Whether a table can be walked with keyset pagination on a single numeric
	 * column, which is dramatically cheaper than OFFSET on large tables.
	 *
	 * @param string $table   Table name.
	 * @param array  $columns Column metadata.
	 * @return string|null Column name, or null.
	 */
	public function keysetColumn( $table, array $columns ) {
		$keys = $this->keyColumns( $table );
		if ( 1 !== count( $keys ) ) {
			return null;
		}
		$name = $keys[0];
		if ( isset( $columns[ $name ] ) && $columns[ $name ]['is_numeric'] ) {
			return $name;
		}
		return null;
	}

	/**
	 * CREATE statement for a table or view.
	 *
	 * @param string $table Table name.
	 * @param string $type  table|view.
	 * @return string
	 */
	public function createStatement( $table, $type = 'table' ) {
		$quoted = '`' . str_replace( '`', '``', $table ) . '`';
		$row    = $this->db->get_row( 'SHOW CREATE ' . ( 'view' === $type ? 'VIEW' : 'TABLE' ) . ' ' . $quoted, ARRAY_N );
		if ( empty( $row ) ) {
			return '';
		}
		return isset( $row[1] ) ? (string) $row[1] : '';
	}

	/**
	 * Exact row count.
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	public function countRows( $table ) {
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $table ) . '`' );
	}

	/**
	 * Trigger definitions for the current schema.
	 *
	 * @return array[]
	 */
	public function triggers() {
		$schema = $this->databaseName();
		$rows   = $this->db->get_results(
			$this->db->prepare(
				'SELECT TRIGGER_NAME AS name, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS target,
				        ACTION_TIMING AS timing, ACTION_STATEMENT AS statement
				 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s',
				$schema
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total size of the selected tables.
	 *
	 * @param array $tables Inventory entries.
	 * @return int Bytes.
	 */
	public function totalSize( array $tables ) {
		$size = 0;
		foreach ( $tables as $info ) {
			$size += (int) $info['data_length'] + (int) $info['index_length'];
		}
		return $size;
	}

	/**
	 * Server character set and collation defaults.
	 *
	 * @return array
	 */
	public function charset() {
		return array(
			'charset' => $this->db->charset ? $this->db->charset : 'utf8mb4',
			'collate' => $this->db->collate,
		);
	}
}
