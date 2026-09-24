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
	 * Whether table names compare case-insensitively (null until asked).
	 *
	 * @var bool|null
	 */
	protected $case_insensitive = null;

	/**
	 * Prefixes of the WordPress installations found in the schema.
	 *
	 * @var string[]
	 */
	protected $installations = array();

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
	 * The prefix as the table names actually spell it.
	 *
	 * With case-insensitive table names the server may report "wp_options"
	 * for a prefix configured as "WP_". The dump uses the names the server
	 * reports, so the archive must record the prefix in that spelling too, or
	 * the importer would not recognise (or rewrite) its own tables.
	 *
	 * @return string
	 */
	public function storedPrefix() {
		$prefix = $this->prefix();
		if ( ! $this->caseInsensitiveNames() ) {
			return $prefix;
		}
		foreach ( array_keys( $this->inventory() ) as $name ) {
			if ( 0 === strcasecmp( $name, $prefix . 'options' ) ) {
				return substr( $name, 0, strlen( $prefix ) );
			}
		}
		return $prefix;
	}

	/**
	 * Name of the schema the connection actually uses.
	 *
	 * Every dump query uses unqualified table names, so the table list must
	 * come from the selected schema, which a db.php drop-in or a call to
	 * $wpdb->select() can make differ from DB_NAME.
	 *
	 * @return string
	 */
	public function databaseName() {
		$selected = (string) $this->db->get_var( 'SELECT DATABASE()' );
		if ( '' !== $selected ) {
			return $selected;
		}
		if ( ! empty( $this->db->dbname ) ) {
			return (string) $this->db->dbname;
		}
		return defined( 'DB_NAME' ) ? DB_NAME : '';
	}

	/**
	 * Whether the server compares table names without regard to case.
	 *
	 * With lower_case_table_names 1 (Windows, Azure) or 2 (macOS) the server
	 * may report "wp_options" for a prefix configured as "WP_".
	 *
	 * @return bool
	 */
	public function caseInsensitiveNames() {
		if ( null === $this->case_insensitive ) {
			$value                  = $this->db->get_var( 'SELECT @@lower_case_table_names' );
			$this->case_insensitive = null !== $value && '0' !== (string) $value;
		}
		return $this->case_insensitive;
	}

	/**
	 * Whether a table name starts with a prefix, honouring the server's
	 * case rules.
	 *
	 * @param string $name   Table name.
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	public function hasPrefix( $name, $prefix ) {
		$length = strlen( (string) $prefix );
		if ( 0 === $length ) {
			return true;
		}
		return $this->caseInsensitiveNames()
			? 0 === strncasecmp( (string) $name, (string) $prefix, $length )
			: 0 === strncmp( (string) $name, (string) $prefix, $length );
	}

	/**
	 * Run a read query and fail loudly when the server reports an error.
	 *
	 * wpdb::get_results() returns an empty array, never null, when a query
	 * fails. Taking that at face value turns a lock timeout or a dropped
	 * connection into "the table is empty", so every read the dump depends
	 * on goes through here.
	 *
	 * @param string $sql    Query.
	 * @param string $output ARRAY_A or ARRAY_N.
	 * @param string $what   What was being read, for the message.
	 * @return array
	 * @throws \RuntimeException When the query fails.
	 */
	public function read( $sql, $output, $what ) {
		if ( ! is_string( $sql ) || '' === $sql ) {
			// wpdb::get_results() without a query returns the previous result.
			throw new DatabaseReadException( sprintf( 'Reading %s failed: the query could not be prepared.', $what ) );
		}

		// Straight on the connection when possible. wpdb::query() refuses
		// (or strips characters from) a query whose text does not fit the
		// narrowest character set among a table's columns, and a keyset
		// cursor carries a key value, "straße" say, into the query text.
		$dbh = isset( $this->db->dbh ) ? $this->db->dbh : null;
		if ( $dbh instanceof \mysqli ) {
			try {
				$result = @mysqli_query( $dbh, $sql );
			} catch ( \Exception $e ) {
				throw new DatabaseReadException( sprintf( 'Reading %1$s failed: %2$s', $what, $e->getMessage() ) );
			}
			if ( false === $result ) {
				throw new DatabaseReadException( sprintf( 'Reading %1$s failed: %2$s', $what, mysqli_error( $dbh ) ) );
			}
			$rows = array();
			if ( $result instanceof \mysqli_result ) {
				$mode = ARRAY_N === $output ? MYSQLI_NUM : MYSQLI_ASSOC;
				while ( null !== ( $row = mysqli_fetch_array( $result, $mode ) ) && false !== $row ) {
					$rows[] = $row;
				}
				mysqli_free_result( $result );
			}
			return $rows;
		}

		$this->db->last_error = '';
		$rows                 = $this->db->get_results( $sql, $output ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '' !== (string) $this->db->last_error || ! is_array( $rows ) ) {
			throw new DatabaseReadException(
				sprintf( 'Reading %1$s failed: %2$s', $what, '' !== (string) $this->db->last_error ? $this->db->last_error : 'no result' )
			);
		}
		return $rows;
	}

	/**
	 * Every table and view in the current schema, with classification.
	 *
	 * A table is "owned" unless it belongs to another WordPress installation
	 * sharing this database. Ownership goes to the longest installation
	 * prefix a table name starts with, so a site on "wp_shop_" never claims
	 * the tables of a site on "wp_" and vice versa.
	 *
	 * @param bool $refresh Bypass the cache.
	 * @return array[] Each entry: name, type, engine, rows, data_length, index_length, collation, owned.
	 * @throws \RuntimeException When the table list cannot be read at all.
	 */
	public function inventory( $refresh = false ) {
		if ( null !== $this->cache && ! $refresh ) {
			return $this->cache;
		}

		$prefix  = $this->prefix();
		$schema  = $this->databaseName();
		$results = array();

		try {
			$results = $this->read(
				$this->db->prepare(
					'SELECT TABLE_NAME AS name, TABLE_TYPE AS table_type, ENGINE AS engine, TABLE_ROWS AS row_estimate,
					        DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, TABLE_COLLATION AS collation,
					        AUTO_INCREMENT AS auto_increment
					 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
					$schema
				),
				ARRAY_A,
				'the table list'
			);
		} catch ( DatabaseReadException $e ) {
			$results = array();
		}

		if ( empty( $results ) ) {
			// Hosts occasionally restrict information_schema; fall back to SHOW.
			$rows = $this->read( 'SHOW FULL TABLES', ARRAY_N, 'the table list' );
			foreach ( $rows as $row ) {
				$results[] = array(
					'name'           => $row[0],
					'table_type'     => isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) ? 'VIEW' : 'BASE TABLE',
					'engine'         => '',
					'row_estimate'   => 0,
					'data_length'    => 0,
					'index_length'   => 0,
					'collation'      => '',
					'auto_increment' => null,
				);
			}
		}

		$this->installations = $this->installationPrefixes( $results, $prefix );

		$inventory = array();
		foreach ( $results as $row ) {
			$name  = (string) $row['name'];
			$owner = $this->ownerPrefix( $name );

			$inventory[ $name ] = array(
				'name'           => $name,
				'type'           => 'VIEW' === strtoupper( (string) $row['table_type'] ) ? 'view' : 'table',
				'engine'         => (string) $row['engine'],
				'rows'           => (int) $row['row_estimate'],
				'data_length'    => (int) $row['data_length'],
				'index_length'   => (int) $row['index_length'],
				'collation'      => (string) $row['collation'],
				'auto_increment' => null === $row['auto_increment'] ? null : (int) $row['auto_increment'],
				'prefixed'       => $this->hasPrefix( $name, $prefix ),
				'owned'          => null === $owner || $this->isOwnPrefix( $owner, $prefix ),
				'installation'   => null === $owner ? '' : $owner,
			);
		}

		ksort( $inventory );
		$this->cache = $inventory;
		return $inventory;
	}

	/**
	 * Tables every WordPress site has, a multisite network's sub-sites
	 * included. A prefix only counts as a separate installation when all of
	 * them exist: plugins create look-alikes such as "wp_sfoptions" and
	 * "wp_sfposts" (the Simple:Press forum), and treating "wp_sf" as another
	 * site would silently drop that plugin's data.
	 *
	 * @return string[]
	 */
	public static function siteTables() {
		return array( 'options', 'posts', 'postmeta', 'comments', 'terms', 'term_taxonomy', 'term_relationships' );
	}

	/**
	 * Prefixes of every WordPress installation in the schema, ours included.
	 *
	 * A prefix counts as an installation when every table of siteTables()
	 * exists for it, whether or not it has a users table of its own (sites
	 * can share one through CUSTOM_USER_TABLE). Only the numbered sites of
	 * this multisite network ({prefix}2_, ...) are ours; see isOwnPrefix().
	 *
	 * @param array  $rows   Table rows (name key).
	 * @param string $prefix Our own prefix.
	 * @return string[] Longest first.
	 */
	protected function installationPrefixes( array $rows, $prefix ) {
		$names = array();
		foreach ( $rows as $row ) {
			$key           = $this->caseInsensitiveNames() ? strtolower( (string) $row['name'] ) : (string) $row['name'];
			$names[ $key ] = (string) $row['name'];
		}
		$has = function ( $name ) use ( $names ) {
			return isset( $names[ $this->caseInsensitiveNames() ? strtolower( $name ) : $name ] );
		};

		$found = array( (string) $prefix );
		foreach ( $names as $key => $name ) {
			if ( ! preg_match( '/^(.+)options$/i', $name, $matches ) ) {
				continue;
			}
			$candidate = $matches[1];
			$complete  = true;
			foreach ( self::siteTables() as $table ) {
				if ( ! $has( $candidate . $table ) ) {
					$complete = false;
					break;
				}
			}
			if ( ! $complete ) {
				continue;
			}
			$found[] = $candidate;
		}

		$unique = array();
		foreach ( $found as $candidate ) {
			$unique[ $this->caseInsensitiveNames() ? strtolower( $candidate ) : $candidate ] = $candidate;
		}
		$found = array_values( $unique );
		usort(
			$found,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return $found;
	}

	/**
	 * The installation prefix a table belongs to: the longest one it starts
	 * with, or null for a table no installation claims.
	 *
	 * @param string $name Table name.
	 * @return string|null
	 */
	protected function ownerPrefix( $name ) {
		foreach ( $this->installations as $candidate ) {
			if ( $this->hasPrefix( $name, $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Whether an installation prefix is this site's (on multisite, the
	 * network's base prefix or one of its per-site prefixes).
	 *
	 * @param string $candidate Installation prefix.
	 * @param string $prefix    Our prefix.
	 * @return bool
	 */
	protected function isOwnPrefix( $candidate, $prefix ) {
		$equal = $this->caseInsensitiveNames() ? 0 === strcasecmp( $candidate, $prefix ) : $candidate === $prefix;
		if ( $equal ) {
			return true;
		}
		if ( is_multisite() && $this->hasPrefix( $candidate, $prefix ) ) {
			return (bool) preg_match( '/^\d+_$/', substr( $candidate, strlen( $prefix ) ) );
		}
		return false;
	}

	/**
	 * Whether a table name (existing or not) would belong to this site rather
	 * than to another installation sharing the database.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public function isOwnTableName( $name ) {
		$this->inventory();
		$owner = $this->ownerPrefix( $name );
		return null === $owner || $this->isOwnPrefix( $owner, $this->prefix() );
	}

	/**
	 * Prefixes of other WordPress installations sharing this database.
	 *
	 * @return string[]
	 */
	public function foreignInstallations() {
		$this->inventory();
		$prefix  = $this->prefix();
		$foreign = array();
		foreach ( $this->installations as $candidate ) {
			if ( ! $this->isOwnPrefix( $candidate, $prefix ) ) {
				$foreign[] = $candidate;
			}
		}
		return $foreign;
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
		$rows    = $this->read( 'SHOW FULL COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`', ARRAY_A, 'the columns of table ' . $table );
		$columns = array();
		foreach ( $rows as $row ) {
			$type = strtolower( (string) $row['Type'] );
			$base = preg_replace( '/\(.*$/', '', $type );
			$base = trim( preg_replace( '/\s+(unsigned|zerofill)/', '', $base ) );

			$columns[ $row['Field'] ] = array(
				'name'       => $row['Field'],
				'type'       => $type,
				'base_type'  => $base,
				'is_binary'  => in_array( $base, array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' ), true ),
				'is_bit'     => 'bit' === $base,
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
	 * Only keys that identify every row qualify: the primary key, or a unique
	 * index whose columns are all NOT NULL. A unique index on a nullable
	 * column admits any number of NULL rows, and paging on it would loop over
	 * them forever.
	 *
	 * @param string $table Table name.
	 * @return string[] Column names, empty when the table has no usable key.
	 */
	public function keyColumns( $table ) {
		$rows     = $this->read( 'SHOW INDEX FROM `' . str_replace( '`', '``', $table ) . '`', ARRAY_A, 'the indexes of table ' . $table );
		$indexes  = array();
		$nullable = array();
		foreach ( $rows as $row ) {
			if ( '0' !== (string) $row['Non_unique'] ) {
				continue;
			}
			$key = (string) $row['Key_name'];
			// MariaDB's "long unique" HASH indexes cannot order rows, so
			// ORDER BY on them falls back to a sort that compares only the
			// first max_sort_length bytes.
			if ( isset( $row['Index_type'] ) && in_array( strtoupper( (string) $row['Index_type'] ), array( 'HASH', 'FULLTEXT', 'SPATIAL' ), true ) ) {
				$nullable[ $key ] = true;
			}
			if ( isset( $row['Sub_part'] ) && '' !== (string) $row['Sub_part'] ) {
				// A prefix index does not order or identify whole values.
				$nullable[ $key ] = true;
			}
			if ( isset( $row['Null'] ) && 'YES' === strtoupper( (string) $row['Null'] ) ) {
				$nullable[ $key ] = true;
			}
			$indexes[ $key ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}

		if ( isset( $indexes['PRIMARY'] ) && empty( $nullable['PRIMARY'] ) ) {
			ksort( $indexes['PRIMARY'] );
			return array_values( $indexes['PRIMARY'] );
		}

		$best = array();
		foreach ( $indexes as $name => $columns ) {
			if ( ! empty( $nullable[ $name ] ) ) {
				continue;
			}
			ksort( $columns );
			if ( empty( $best ) || count( $columns ) < count( $best ) ) {
				$best = array_values( $columns );
			}
		}
		return $best;
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
		$rows   = $this->read( 'SHOW CREATE ' . ( 'view' === $type ? 'VIEW' : 'TABLE' ) . ' ' . $quoted, ARRAY_N, 'the definition of table ' . $table );
		return isset( $rows[0][1] ) ? (string) $rows[0][1] : '';
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
	 * @param string[]|null $tables Keep only triggers on these tables.
	 * @return array[]
	 * @throws \RuntimeException When the trigger list cannot be read.
	 */
	public function triggers( $tables = null ) {
		$schema = $this->databaseName();
		$rows   = $this->read(
			$this->db->prepare(
				'SELECT TRIGGER_NAME AS name, EVENT_MANIPULATION AS event, EVENT_OBJECT_TABLE AS target,
				        ACTION_TIMING AS timing, ACTION_STATEMENT AS statement
				 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s',
				$schema
			),
			ARRAY_A,
			'the trigger definitions'
		);
		if ( null === $tables ) {
			return $rows;
		}
		$keep = array_flip( (array) $tables );
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $keep ) {
					return isset( $keep[ $row['target'] ] );
				}
			)
		);
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
