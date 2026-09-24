<?php
/**
 * Streaming database dump.
 *
 * @package SHCM
 */

namespace SHCM\Database;

use SHCM\Jobs\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a MySQL compatible dump one batch of rows at a time.
 *
 * No table is ever loaded into memory as a whole: rows are read in adaptive
 * batches and handed straight to a sink that writes them into the archive.
 * Tables with a primary key (or a unique key over NOT NULL columns) are
 * walked in key order with keyset pagination, so a row written or deleted
 * elsewhere while the table is being paged can neither shift other rows out
 * of the dump nor duplicate them. Only tables without such a key fall back to
 * OFFSET paging, still in a fully determined order.
 */
class Exporter {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Inspector.
	 *
	 * @var Inspector
	 */
	protected $inspector;

	/**
	 * Options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Constructor.
	 *
	 * @param \wpdb     $db        Database handle.
	 * @param Inspector $inspector Inspector.
	 * @param array     $options   rows_per_query, max_insert_bytes.
	 */
	public function __construct( $db, Inspector $inspector, array $options = array() ) {
		$this->db        = $db;
		$this->inspector = $inspector;
		$this->options   = array_merge(
			array(
				'rows_per_query'   => 2000,
				'max_insert_bytes' => 524288,
				'target_batch_bytes' => 4194304,
			),
			$options
		);
	}

	/**
	 * Preamble written once at the top of every table dump.
	 *
	 * @param string $table Table name.
	 * @param array  $info  Inventory entry.
	 * @return string
	 */
	public function tableHeader( $table, array $info ) {
		$quoted = $this->quoteName( $table );
		$sql    = "-- SH Clone Migration dump\n";
		$sql   .= '-- Table: ' . $table . "\n";
		$sql   .= "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n";
		$sql   .= "SET FOREIGN_KEY_CHECKS = 0;\n";
		$sql   .= 'DROP ' . ( 'view' === $info['type'] ? 'VIEW' : 'TABLE' ) . ' IF EXISTS ' . $quoted . ";\n";

		$create = $this->inspector->createStatement( $table, $info['type'] );
		if ( '' === $create ) {
			throw new \RuntimeException( sprintf( 'Cannot read the definition of table %s.', $table ) );
		}
		if ( 'view' === $info['type'] ) {
			// Views frequently carry a DEFINER that does not exist on the
			// destination; dropping it makes the view portable.
			$create = preg_replace( '/DEFINER\s*=\s*[^\s]+\s*/i', '', $create );
			$create = preg_replace( '/SQL SECURITY DEFINER/i', 'SQL SECURITY INVOKER', $create );
		}
		$sql .= $create . ";\n";

		return $sql;
	}

	/**
	 * Dump a batch of rows.
	 *
	 * A read error never ends the table: it is reported in $state['error']
	 * with the cursor left on the last row that was written, so the caller
	 * can retry from exactly that point.
	 *
	 * @param string   $table  Table name.
	 * @param array    $state  Resume state.
	 * @param Budget   $budget Budget.
	 * @param callable $sink   Receives SQL text.
	 * @return array Updated state.
	 */
	public function dumpRows( $table, array $state, Budget $budget, callable $sink ) {
		unset( $state['error'] );

		if ( ! isset( $state['columns'] ) ) {
			try {
				$columns = $this->inspector->columns( $table );
				if ( empty( $columns ) ) {
					throw new DatabaseReadException( sprintf( 'Reading the columns of table %s failed: no columns were returned.', $table ) );
				}
				$keys = $this->inspector->keyColumns( $table );
			} catch ( DatabaseReadException $e ) {
				$state['error'] = $e->getMessage();
				return $state;
			}

			$state['columns'] = $columns;
			$state['keys']    = $this->pageableKeys( $keys, $columns );
			$state['order']   = empty( $state['keys'] ) ? $this->fallbackOrder( $columns ) : array();
			$state['cursor']  = null;
			$state['offset']  = 0;
			$state['rows']    = 0;
			$state['bytes']   = 0;
			$state['batch']   = $this->batchSize( $table );
		}

		$columns     = $state['columns'];
		$names       = array_keys( $columns );
		$column_list = implode( ', ', array_map( array( $this, 'quoteName' ), $names ) );
		$quoted      = $this->quoteName( $table );
		$insert_head = 'INSERT INTO ' . $quoted . ' (' . $column_list . ') VALUES ';
		$max_bytes   = (int) $this->options['max_insert_bytes'];

		$buffer  = '';
		$batches = 0;
		$emit    = static function ( $sql ) use ( $sink, &$state ) {
			$state['bytes'] = (int) $state['bytes'] + strlen( $sql );
			call_user_func( $sink, $sql );
		};

		while ( $budget->shouldContinue( $batches ) ) {
			++$batches;
			try {
				$rows = $this->fetchBatch( $table, $state );
			} catch ( DatabaseReadException $e ) {
				$state['error'] = $e->getMessage();
				break;
			}
			if ( empty( $rows ) ) {
				$state['done'] = true;
				break;
			}

			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $names as $name ) {
					$values[] = $this->formatValue( $row[ $name ], $columns[ $name ] );
				}
				$tuple = '(' . implode( ',', $values ) . ')';

				if ( '' !== $buffer && strlen( $buffer ) + strlen( $tuple ) + 2 > $max_bytes ) {
					$emit( $insert_head . $buffer . ";\n" );
					$buffer = '';
				}
				$buffer .= ( '' === $buffer ? '' : ',' ) . $tuple;
				++$state['rows'];
			}

			// Free the result set before the next batch.
			$this->db->flush();

			if ( count( $rows ) < $state['batch'] ) {
				$state['done'] = true;
				break;
			}
		}

		if ( '' !== $buffer ) {
			$emit( $insert_head . $buffer . ";\n" );
		}

		return $state;
	}

	/**
	 * Key columns that keyset pagination can safely walk.
	 *
	 * The comparison "key > last value" must order rows exactly like
	 * ORDER BY does. That fails for approximate numbers (a FLOAT read back as
	 * "0.1" is not equal to the stored value, so the same row would be read
	 * forever), for ENUM/SET (sorted by position, compared as text), and for
	 * TEXT/BLOB keys (sorted on their first max_sort_length bytes only).
	 *
	 * @param string[] $keys    Key columns.
	 * @param array    $columns Column metadata.
	 * @return string[]
	 */
	protected function pageableKeys( array $keys, array $columns ) {
		foreach ( $keys as $key ) {
			if ( ! isset( $columns[ $key ] ) ) {
				return array();
			}
			if ( in_array( $columns[ $key ]['base_type'], array( 'float', 'double', 'real', 'enum', 'set', 'json', 'geometry', 'bit', 'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob' ), true ) ) {
				return array();
			}
		}
		return array_values( $keys );
	}

	/**
	 * ORDER BY columns for a table without a usable key: every column, so
	 * that consecutive OFFSET pages see the rows in one fixed order.
	 *
	 * @param array $columns Column metadata.
	 * @return string[]
	 */
	protected function fallbackOrder( array $columns ) {
		$order = array();
		foreach ( $columns as $name => $column ) {
			if ( in_array( $column['base_type'], array( 'json', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' ), true ) ) {
				continue;
			}
			$order[] = $name;
		}
		return $order;
	}

	/**
	 * Fetch one batch of rows, advancing the cursor.
	 *
	 * @param string $table Table name.
	 * @param array  $state Resume state (by reference).
	 * @return array
	 * @throws DatabaseReadException When the server reports an error.
	 */
	protected function fetchBatch( $table, array &$state ) {
		$quoted = $this->quoteName( $table );
		$limit  = max( 1, (int) $state['batch'] );
		$keys   = isset( $state['keys'] ) ? (array) $state['keys'] : array();

		if ( ! empty( $keys ) ) {
			$order = implode( ', ', array_map( array( $this, 'quoteName' ), $keys ) );
			$where = null === $state['cursor'] ? '' : ' WHERE ' . $this->keysetCondition( $keys, (array) $state['cursor'] );
			$sql   = "SELECT * FROM {$quoted}{$where} ORDER BY {$order} LIMIT {$limit}";
		} else {
			$offset = (int) $state['offset'];
			$order  = empty( $state['order'] ) ? '' : ' ORDER BY ' . implode( ', ', array_map( array( $this, 'quoteName' ), $state['order'] ) );
			$sql    = "SELECT * FROM {$quoted}{$order} LIMIT {$limit} OFFSET {$offset}";
		}

		$rows = $this->inspector->read( $sql, ARRAY_A, 'table ' . $table );
		if ( empty( $rows ) ) {
			return array();
		}

		if ( ! empty( $keys ) ) {
			$last   = $rows[ count( $rows ) - 1 ];
			$cursor = array();
			foreach ( $keys as $key ) {
				// Stored as SQL literals: binary keys become hex, so the state
				// stays plain ASCII whatever the key holds.
				$cursor[] = $this->formatValue( $last[ $key ], $state['columns'][ $key ] );
			}
			$state['cursor'] = $cursor;
		} else {
			$state['offset'] = (int) $state['offset'] + count( $rows );
		}

		return $rows;
	}

	/**
	 * WHERE clause selecting the rows after a key tuple.
	 *
	 * (k1, k2) > (v1, v2) is written out as k1 >= v1 AND (k1 > v1 OR
	 * (k1 = v1 AND k2 > v2)) because older MySQL versions cannot use an index
	 * for a row constructor comparison.
	 *
	 * @param string[] $keys   Key columns.
	 * @param string[] $values SQL literals of the last row's key.
	 * @return string
	 */
	public function keysetCondition( array $keys, array $values ) {
		$quoted = array_map( array( $this, 'quoteName' ), $keys );
		$terms  = array();
		$count  = count( $quoted );
		for ( $i = 0; $i < $count; $i++ ) {
			$parts = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$parts[] = $quoted[ $j ] . ' = ' . $values[ $j ];
			}
			$parts[] = $quoted[ $i ] . ' > ' . $values[ $i ];
			$terms[] = '(' . implode( ' AND ', $parts ) . ')';
		}
		if ( 1 === $count ) {
			return $terms[0];
		}
		return $quoted[0] . ' >= ' . $values[0] . ' AND (' . implode( ' OR ', $terms ) . ')';
	}

	/**
	 * Choose a batch size that keeps one batch around a few megabytes even when
	 * rows are very large.
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	protected function batchSize( $table ) {
		$configured = max( 1, (int) $this->options['rows_per_query'] );
		$inventory  = $this->inspector->inventory();
		if ( ! isset( $inventory[ $table ] ) ) {
			return $configured;
		}
		$rows = (int) $inventory[ $table ]['rows'];
		$data = (int) $inventory[ $table ]['data_length'];
		if ( $rows < 1 || $data < 1 ) {
			return $configured;
		}
		$avg = max( 1, (int) ( $data / $rows ) );
		$by_size = (int) floor( $this->options['target_batch_bytes'] / $avg );
		return max( 1, min( $configured, max( 10, $by_size ) ) );
	}

	/**
	 * Render one value as SQL.
	 *
	 * @param mixed $value  Value.
	 * @param array $column Column metadata.
	 * @return string
	 */
	public function formatValue( $value, array $column ) {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( ! empty( $column['is_bit'] ) || 'bit' === $column['base_type'] ) {
			// mysqlnd returns BIT values as decimal text ("0", "5"); a client
			// library that returns the raw bytes gets a hex literal.
			return ctype_digit( (string) $value ) ? (string) $value : '0x' . bin2hex( (string) $value );
		}
		if ( $column['is_binary'] ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		if ( $column['is_numeric'] && is_numeric( $value ) ) {
			return (string) $value;
		}
		return "'" . $this->escapeString( (string) $value ) . "'";
	}

	/**
	 * Escape a string literal for the dump.
	 *
	 * wpdb::_real_escape() deliberately wraps every literal "%" in its
	 * placeholder token, because it expects wpdb::query() to strip that token
	 * again on the way out. A dump never goes through wpdb::query(), so using
	 * it directly would write "{hash}postname{hash}" into the archive instead
	 * of "%postname%". The connection's own escaping is used instead.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected function escapeString( $value ) {
		$dbh = isset( $this->db->dbh ) ? $this->db->dbh : null;
		if ( $dbh instanceof \mysqli ) {
			return mysqli_real_escape_string( $dbh, $value );
		}

		$escaped = $this->db->_real_escape( $value );
		if ( method_exists( $this->db, 'remove_placeholder_escape' ) ) {
			$escaped = $this->db->remove_placeholder_escape( $escaped );
		}
		return $escaped;
	}

	/**
	 * Quote an identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public function quoteName( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Trigger definitions rendered as executable statements.
	 *
	 * @param string[]|null $tables Keep only triggers on these tables.
	 * @return array[] Each entry: name, sql.
	 */
	public function triggerStatements( $tables = null ) {
		$statements = array();
		foreach ( $this->inspector->triggers( $tables ) as $trigger ) {
			$statements[] = array(
				'name' => $trigger['name'],
				'sql'  => sprintf(
					'CREATE TRIGGER %s %s %s ON %s FOR EACH ROW %s',
					$this->quoteName( $trigger['name'] ),
					$trigger['timing'],
					$trigger['event'],
					$this->quoteName( $trigger['target'] ),
					$trigger['statement']
				),
				'table' => $trigger['target'],
			);
		}
		return $statements;
	}
}
