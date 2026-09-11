<?php
/**
 * Database wide search and replace.
 *
 * @package SHCM
 */

namespace SHCM\URL;

use SHCM\Database\Inspector;
use SHCM\Jobs\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Walks every table and text column in batches, applying a Replacer to each
 * value and writing back only what actually changed.
 *
 * The walk is resumable: its whole position is a table index plus a cursor, so
 * a replacement across a 20 million row table survives any number of request
 * timeouts.
 */
class DatabaseReplacer {

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
	 * Replacer.
	 *
	 * @var Replacer
	 */
	protected $replacer;

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
	 * @param Replacer  $replacer  Replacer.
	 * @param array     $options   rows_per_batch, dry_run, max_samples, include_foreign.
	 */
	public function __construct( $db, Inspector $inspector, Replacer $replacer, array $options = array() ) {
		$this->db        = $db;
		$this->inspector = $inspector;
		$this->replacer  = $replacer;
		$this->options   = array_merge(
			array(
				'rows_per_batch'  => 500,
				'dry_run'         => false,
				'max_samples'     => 40,
				'include_foreign' => false,
				'tables'          => array(),
			),
			$options
		);
	}

	/**
	 * Fresh state for a new run.
	 *
	 * @return array
	 */
	public function initialState() {
		$tables = $this->options['tables'];
		if ( empty( $tables ) ) {
			// Read the schema fresh: a restore has usually just created tables
			// that did not exist when this process started.
			$this->inspector->inventory( true );
			$tables = array();
			foreach ( $this->inspector->exportableTables( array( 'include_foreign' => $this->options['include_foreign'] ) ) as $name => $info ) {
				if ( 'view' === $info['type'] ) {
					continue; // Views are projections of their base tables.
				}
				$tables[] = $name;
			}
		}

		return array(
			'tables' => array_values( $tables ),
			'index'  => 0,
			'cursor' => null,
			'offset' => 0,
			'done'   => false,
			'stats'  => array(
				'tables_scanned'      => 0,
				'rows_scanned'        => 0,
				'values_changed'      => 0,
				'serialized_repaired' => 0,
				'serialized_failed'   => 0,
				'rows_updated'        => 0,
				'remaining_refs'      => 0,
			),
			'columns' => array(),
			'samples' => array(),
			'failures' => array(),
		);
	}

	/**
	 * Advance the replacement.
	 *
	 * @param array  $state  State from initialState() or a previous call.
	 * @param Budget $budget Budget.
	 * @return array Updated state.
	 */
	public function run( array $state, Budget $budget ) {
		$dry_run   = ! empty( $this->options['dry_run'] );
		$processed = 0;

		while ( $budget->shouldContinue( $processed ) ) {
			++$processed;
			if ( $state['index'] >= count( $state['tables'] ) ) {
				$state['done'] = true;
				break;
			}

			$table = $state['tables'][ $state['index'] ];

			if ( empty( $state['columns'] ) ) {
				$prepared = $this->prepareTable( $table );
				if ( null === $prepared ) {
					$state = $this->advanceTable( $state );
					continue;
				}
				$state['columns'] = $prepared;
				$state['cursor']  = null;
				$state['offset']  = 0;
			}

			$finished = $this->processBatch( $table, $state, $dry_run );
			if ( $finished ) {
				$state = $this->advanceTable( $state );
			}
		}

		return $state;
	}

	/**
	 * Move to the next table.
	 *
	 * @param array $state State.
	 * @return array
	 */
	protected function advanceTable( array $state ) {
		$state['stats']['tables_scanned']++;
		$state['index']  = (int) $state['index'] + 1;
		$state['columns'] = array();
		$state['cursor']  = null;
		$state['offset']  = 0;
		return $state;
	}

	/**
	 * Collect the columns worth scanning for a table.
	 *
	 * @param string $table Table name.
	 * @return array|null null when the table has nothing to scan.
	 */
	protected function prepareTable( $table ) {
		$columns = $this->inspector->columns( $table );
		if ( empty( $columns ) ) {
			return null;
		}

		$scan = array();
		foreach ( $columns as $name => $column ) {
			// Text and blob columns are the only places a URL can hide.
			if ( $column['is_text'] || $column['is_binary'] ) {
				$scan[] = $name;
			}
		}
		if ( empty( $scan ) ) {
			return null;
		}

		$keys   = $this->inspector->keyColumns( $table );
		$keyset = $this->inspector->keysetColumn( $table, $columns );

		return array(
			'scan'    => $scan,
			'keys'    => $keys,
			'keyset'  => $keyset,
			'all'     => array_keys( $columns ),
			'numeric' => array_keys(
				array_filter(
					$columns,
					static function ( $column ) {
						return $column['is_numeric'];
					}
				)
			),
		);
	}

	/**
	 * Process one batch of rows.
	 *
	 * @param string $table   Table name.
	 * @param array  $state   State (by reference).
	 * @param bool   $dry_run Whether to skip writes.
	 * @return bool True when the table is finished.
	 */
	protected function processBatch( $table, array &$state, $dry_run ) {
		$meta   = $state['columns'];
		$limit  = max( 1, (int) $this->options['rows_per_batch'] );
		$quoted = $this->quote( $table );

		// Selecting only the key and scannable columns keeps a batch small
		// even on tables with dozens of columns.
		$select_columns = array_values( array_unique( array_merge( $meta['keys'], $meta['scan'] ) ) );
		$select_list    = empty( $meta['keys'] ) ? '*' : implode( ', ', array_map( array( $this, 'quote' ), $select_columns ) );

		if ( ! empty( $meta['keyset'] ) ) {
			$key = $this->quote( $meta['keyset'] );
			if ( null === $state['cursor'] ) {
				$sql = "SELECT {$select_list} FROM {$quoted} ORDER BY {$key} ASC LIMIT {$limit}";
			} else {
				$sql = $this->db->prepare(
					"SELECT {$select_list} FROM {$quoted} WHERE {$key} > %s ORDER BY {$key} ASC LIMIT {$limit}", // phpcs:ignore WordPress.DB.PreparedSQL
					$state['cursor']
				);
			}
		} else {
			$offset = (int) $state['offset'];
			$sql    = "SELECT {$select_list} FROM {$quoted} LIMIT {$limit} OFFSET {$offset}";
		}

		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( null === $rows ) {
			throw new \RuntimeException(
				sprintf( 'Search and replace failed reading %1$s: %2$s', $table, $this->db->last_error )
			);
		}
		if ( empty( $rows ) ) {
			return true;
		}

		foreach ( $rows as $row ) {
			$state['stats']['rows_scanned']++;
			$updates = array();

			foreach ( $meta['scan'] as $column ) {
				if ( ! array_key_exists( $column, $row ) ) {
					continue;
				}
				$value = $row[ $column ];
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				if ( ! $this->replacer->mightMatch( $value ) ) {
					continue;
				}

				$result = $this->replacer->apply( $value );

				if ( $result['failed'] ) {
					$state['stats']['serialized_failed']++;
					if ( count( $state['failures'] ) < (int) $this->options['max_samples'] ) {
						$state['failures'][] = array(
							'table'  => $table,
							'column' => $column,
							'key'    => $this->rowKeyDescription( $meta, $row ),
						);
					}
					continue;
				}

				if ( $result['changed'] ) {
					$updates[ $column ] = $result['value'];
					$state['stats']['values_changed']++;
					if ( $result['serialized'] ) {
						$state['stats']['serialized_repaired']++;
					}
				}

				$remaining = $this->replacer->countRemaining( $result['changed'] ? $result['value'] : $value );
				if ( $remaining > 0 ) {
					$state['stats']['remaining_refs'] += $remaining;
					if ( count( $state['samples'] ) < (int) $this->options['max_samples'] ) {
						$state['samples'][] = array(
							'table'  => $table,
							'column' => $column,
							'key'    => $this->rowKeyDescription( $meta, $row ),
							'excerpt' => $this->excerpt( $result['changed'] ? $result['value'] : $value ),
						);
					}
				}
			}

			if ( ! empty( $updates ) && ! $dry_run ) {
				$this->updateRow( $table, $meta, $row, $updates );
				$state['stats']['rows_updated']++;
			} elseif ( ! empty( $updates ) ) {
				$state['stats']['rows_updated']++;
			}

			if ( ! empty( $meta['keyset'] ) ) {
				$state['cursor'] = $row[ $meta['keyset'] ];
			}
		}

		if ( empty( $meta['keyset'] ) ) {
			$state['offset'] = (int) $state['offset'] + count( $rows );
		}

		$this->db->flush();

		return count( $rows ) < $limit;
	}

	/**
	 * Write the changed columns of a row back.
	 *
	 * @param string $table   Table name.
	 * @param array  $meta    Column metadata.
	 * @param array  $row     Original row.
	 * @param array  $updates Changed columns.
	 * @return void
	 */
	protected function updateRow( $table, array $meta, array $row, array $updates ) {
		$where = array();
		if ( ! empty( $meta['keys'] ) ) {
			foreach ( $meta['keys'] as $key ) {
				if ( ! array_key_exists( $key, $row ) ) {
					$where = array();
					break;
				}
				$where[ $key ] = $row[ $key ];
			}
		}

		if ( empty( $where ) ) {
			// No usable key: match on the original values of the columns we
			// selected and limit the statement to a single row.
			$this->updateRowWithoutKey( $table, $row, $updates );
			return;
		}

		$result = $this->db->update( $table, $updates, $where );
		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf( 'Search and replace could not update %1$s: %2$s', $table, $this->db->last_error )
			);
		}
	}

	/**
	 * Fallback update for tables without a primary or unique key.
	 *
	 * @param string $table   Table name.
	 * @param array  $row     Original row.
	 * @param array  $updates Changed columns.
	 * @return void
	 */
	protected function updateRowWithoutKey( $table, array $row, array $updates ) {
		$set    = array();
		$params = array();
		foreach ( $updates as $column => $value ) {
			$set[]    = $this->quote( $column ) . ' = %s';
			$params[] = $value;
		}

		$where = array();
		foreach ( $row as $column => $value ) {
			if ( null === $value ) {
				$where[] = $this->quote( $column ) . ' IS NULL';
				continue;
			}
			$where[]  = $this->quote( $column ) . ' = %s';
			$params[] = $value;
		}

		$sql = 'UPDATE ' . $this->quote( $table ) . ' SET ' . implode( ', ', $set )
			. ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1';

		$result = $this->db->query( $this->db->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf( 'Search and replace could not update %1$s: %2$s', $table, $this->db->last_error )
			);
		}
	}

	/**
	 * Describe a row for the report.
	 *
	 * @param array $meta Column metadata.
	 * @param array $row  Row.
	 * @return string
	 */
	protected function rowKeyDescription( array $meta, array $row ) {
		$parts = array();
		foreach ( $meta['keys'] as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$parts[] = $key . '=' . substr( (string) $row[ $key ], 0, 40 );
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Short, safe excerpt of a value for the report.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected function excerpt( $value ) {
		$hosts = $this->replacer->reportHosts();
		$value = (string) $value;
		foreach ( $hosts as $host ) {
			$position = stripos( $value, $host );
			if ( false !== $position ) {
				$start = max( 0, $position - 40 );
				return ( $start > 0 ? '...' : '' ) . substr( $value, $start, 140 ) . '...';
			}
		}
		return substr( $value, 0, 140 );
	}

	/**
	 * Quote an identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	protected function quote( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Progress estimate for the current state.
	 *
	 * @param array $state State.
	 * @return float 0..1
	 */
	public function progress( array $state ) {
		$total = count( $state['tables'] );
		if ( $total < 1 ) {
			return 1.0;
		}
		return min( 1.0, $state['index'] / $total );
	}
}
