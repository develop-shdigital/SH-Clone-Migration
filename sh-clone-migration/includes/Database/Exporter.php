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
 * batches (keyset paginated when the table has a single numeric key) and
 * handed straight to a sink that writes them into the archive.
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
	 * @param string   $table  Table name.
	 * @param array    $state  Resume state.
	 * @param Budget   $budget Budget.
	 * @param callable $sink   Receives SQL text.
	 * @return array Updated state.
	 */
	public function dumpRows( $table, array $state, Budget $budget, callable $sink ) {
		$columns = isset( $state['columns'] ) ? $state['columns'] : null;
		if ( null === $columns ) {
			$columns          = $this->inspector->columns( $table );
			$state['columns'] = $columns;
			$state['keyset']  = $this->inspector->keysetColumn( $table, $columns );
			$state['cursor']  = null;
			$state['offset']  = 0;
			$state['rows']    = 0;
			$state['batch']   = $this->batchSize( $table );
		}

		if ( empty( $columns ) ) {
			$state['done'] = true;
			return $state;
		}

		$names       = array_keys( $columns );
		$column_list = implode( ', ', array_map( array( $this, 'quoteName' ), $names ) );
		$quoted      = $this->quoteName( $table );
		$insert_head = 'INSERT INTO ' . $quoted . ' (' . $column_list . ') VALUES ';
		$max_bytes   = (int) $this->options['max_insert_bytes'];

		$buffer      = '';
		$buffer_rows = 0;
		$batches     = 0;

		while ( $budget->shouldContinue( $batches ) ) {
			++$batches;
			$rows = $this->fetchBatch( $table, $state );
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
					call_user_func( $sink, $insert_head . $buffer . ";\n" );
					$buffer      = '';
					$buffer_rows = 0;
				}
				$buffer .= ( '' === $buffer ? '' : ',' ) . $tuple;
				++$buffer_rows;
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
			call_user_func( $sink, $insert_head . $buffer . ";\n" );
		}

		return $state;
	}

	/**
	 * Fetch one batch of rows, advancing the cursor.
	 *
	 * @param string $table Table name.
	 * @param array  $state Resume state (by reference).
	 * @return array
	 */
	protected function fetchBatch( $table, array &$state ) {
		$quoted = $this->quoteName( $table );
		$limit  = max( 1, (int) $state['batch'] );

		if ( ! empty( $state['keyset'] ) ) {
			$key = $this->quoteName( $state['keyset'] );
			if ( null === $state['cursor'] ) {
				$sql = "SELECT * FROM {$quoted} ORDER BY {$key} ASC LIMIT {$limit}";
			} elseif ( is_int( $state['cursor'] ) || ctype_digit( (string) $state['cursor'] ) ) {
				$sql = $this->db->prepare(
					"SELECT * FROM {$quoted} WHERE {$key} > %d ORDER BY {$key} ASC LIMIT {$limit}", // phpcs:ignore WordPress.DB.PreparedSQL
					(int) $state['cursor']
				);
			} else {
				$sql = $this->db->prepare(
					"SELECT * FROM {$quoted} WHERE {$key} > %s ORDER BY {$key} ASC LIMIT {$limit}", // phpcs:ignore WordPress.DB.PreparedSQL
					$state['cursor']
				);
			}
		} else {
			$offset = (int) $state['offset'];
			$sql    = "SELECT * FROM {$quoted} LIMIT {$limit} OFFSET {$offset}";
		}

		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( null === $rows ) {
			throw new \RuntimeException(
				sprintf( 'Reading table %1$s failed: %2$s', $table, $this->db->last_error )
			);
		}
		if ( empty( $rows ) ) {
			return array();
		}

		if ( ! empty( $state['keyset'] ) ) {
			$last            = $rows[ count( $rows ) - 1 ];
			$state['cursor'] = $last[ $state['keyset'] ];
		} else {
			$state['offset'] = (int) $state['offset'] + count( $rows );
		}

		return $rows;
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
	 * @return array[] Each entry: name, sql.
	 */
	public function triggerStatements() {
		$statements = array();
		foreach ( $this->inspector->triggers() as $trigger ) {
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
