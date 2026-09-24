<?php
/**
 * Export: database dump.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Archive\Format;
use SHCM\Archive\Session;
use SHCM\Core\Settings;
use SHCM\Database\DatabaseReadException;
use SHCM\Database\Exporter;
use SHCM\Database\Inspector;
use SHCM\Export\ChecksumLedger;
use SHCM\Export\Manifest;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\Support\Bytes;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the archive and streams the database into it.
 *
 * The manifest is written first so that an importer can read it without
 * walking the whole archive.
 */
class DatabaseStage extends AbstractStage {

	/**
	 * Consecutive failed reads of one table before the export gives up.
	 */
	const MAX_READ_RETRIES = 5;

	/**
	 * Inspector.
	 *
	 * @var Inspector
	 */
	protected $inspector;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Storage   $storage   Storage.
	 * @param Logger    $logger    Logger.
	 * @param Inspector $inspector Inspector.
	 * @param \wpdb     $db        Database handle.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger, Inspector $inspector, $db ) {
		parent::__construct( $settings, $storage, $logger );
		$this->inspector = $inspector;
		$this->db        = $db;
	}

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'database';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Exporting the database', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 22;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		$state = $job->stageState(
			$this->key(),
			array(
				'phase' => 'meta',
				'index' => 0,
				'table' => array(),
				'rows'  => 0,
			)
		);

		$writer = Session::open(
			$job,
			array(
				'block_size' => (int) $job->param( 'block_size', Format::DEFAULT_BLOCK_SIZE ),
				'compress'   => 'gzip' === $job->param( 'compression', 'gzip' ),
				'level'      => $this->settings->getInt( 'compression_level', 6 ),
				'prologue'   => array(
					'source'     => home_url(),
					'job'        => $job->id(),
				),
			)
		);
		$ledger = new ChecksumLedger( $this->ledgerPath( $job ) );

		try {
			if ( 'meta' === $state['phase'] ) {
				$this->writeMetadata( $job, $writer, $ledger );
				$state['phase'] = 'tables';
				$state['index'] = 0;
			}

			$tables = (array) $job->shared( 'tables', array() );

			if ( 'tables' === $state['phase'] && ! $job->param( 'include_database', true ) ) {
				$state['phase'] = 'triggers';
			}

			$processed = 0;
			while ( 'tables' === $state['phase'] && $budget->shouldContinue( $processed ) ) {
				++$processed;
				if ( $state['index'] >= count( $tables ) ) {
					$state['phase'] = 'triggers';
					break;
				}

				$info = $tables[ $state['index'] ];
				$done = $this->dumpTable( $job, $writer, $ledger, $info, $state, $budget );
				if ( $done ) {
					$state['index']++;
					$state['table'] = array();
				} elseif ( ! empty( $state['retry'] ) ) {
					// One attempt per request after a failed read.
					break;
				}
			}

			if ( 'triggers' === $state['phase'] ) {
				$this->assertAllTablesExported( $job, $tables );
				$this->writeTriggers( $job, $writer, $ledger );
				$state['phase'] = 'done';
			}
		} finally {
			$ledger->close();
			Session::park( $job, $writer );
			$job->setStageState( $this->key(), $state );
		}

		$tables = (array) $job->shared( 'tables', array() );
		$total  = max( 1, count( $tables ) );

		if ( 'done' === $state['phase'] ) {
			if ( ! $job->param( 'include_database', true ) ) {
				$this->logger->warning( 'The database was left out of this archive (include_database is off).' );
				return $this->complete( __( 'Database not included', 'sh-clone-migration' ) );
			}
			$totals = (array) $job->shared( 'database_totals', array() );
			$this->logger->info(
				sprintf(
					'Database export finished: %1$d tables, %2$s rows, %3$s of SQL.',
					isset( $totals['tables'] ) ? (int) $totals['tables'] : 0,
					number_format( isset( $totals['rows'] ) ? (int) $totals['rows'] : 0 ),
					Bytes::format( isset( $totals['sql_bytes'] ) ? (int) $totals['sql_bytes'] : 0 )
				)
			);
			return $this->complete(
				sprintf(
					/* translators: 1: number of tables, 2: number of rows */
					__( '%1$s database tables exported (%2$s rows)', 'sh-clone-migration' ),
					number_format_i18n( isset( $totals['tables'] ) ? (int) $totals['tables'] : 0 ),
					number_format_i18n( isset( $totals['rows'] ) ? (int) $totals['rows'] : 0 )
				)
			);
		}

		$current = isset( $tables[ $state['index'] ] ) ? $tables[ $state['index'] ]['name'] : '';
		if ( ! empty( $state['waiting'] ) ) {
			unset( $state['waiting'] );
			$job->setStageState( $this->key(), $state );
			return $this->waiting(
				sprintf(
					/* translators: 1: table name, 2: attempt */
					__( 'Waiting to read %1$s again (attempt %2$d)', 'sh-clone-migration' ),
					$current,
					isset( $state['retry']['count'] ) ? (int) $state['retry']['count'] + 1 : 2
				),
				$state['index'] / $total
			);
		}
		return $this->progress(
			sprintf(
				/* translators: 1: table name, 2: current index, 3: total */
				__( 'Exporting %1$s (%2$d/%3$d)', 'sh-clone-migration' ),
				$current,
				$state['index'] + 1,
				$total
			),
			$state['index'] / $total
		);
	}

	/**
	 * Write the manifest and the metadata entries.
	 *
	 * @param Job            $job    Job.
	 * @param \SHCM\Archive\Writer $writer Writer.
	 * @param ChecksumLedger $ledger Ledger.
	 * @return void
	 */
	protected function writeMetadata( Job $job, $writer, ChecksumLedger $ledger ) {
		$ledger->reset( $job );
		$job->setShared( 'checksum_digest', bin2hex( Format::initialHashState() ) );
		$job->setShared( 'checksum_entries', 0 );
		$job->setShared( 'entry_groups', array() );
		$job->setShared( 'tables_exported', array() );
		$job->setShared( 'tables_vanished', array() );
		$job->setShared(
			'database_totals',
			array(
				'included'  => (bool) $job->param( 'include_database', true ),
				'prefix'    => $this->inspector->storedPrefix(),
				'tables'    => 0,
				'rows'      => 0,
				'sql_bytes' => 0,
			)
		);

		$manifest = Manifest::build( $job, $this->inspector );
		$job->setShared( 'manifest', $manifest );

		$ledger->record(
			$job,
			$writer->addString(
				Format::ENTRY_MANIFEST,
				Json::encode( $manifest, true ),
				array(
					'group' => 'meta',
					'mtime' => time(),
				)
			)
		);

		$ledger->record(
			$job,
			$writer->addString(
				Format::ENTRY_CONFIG,
				Json::encode( Manifest::configMetadata(), true ),
				array(
					'group' => 'meta',
					'mtime' => time(),
				)
			)
		);

		$ledger->record(
			$job,
			$writer->addString(
				Format::ENTRY_DB_META,
				Json::encode( $this->databaseMetadata( $job ), true ),
				array(
					'group' => 'meta',
					'mtime' => time(),
				)
			)
		);

		$this->logger->info( 'Manifest written.' );
	}

	/**
	 * Metadata describing the dump itself.
	 *
	 * @param Job $job Job.
	 * @return array
	 */
	protected function databaseMetadata( Job $job ) {
		$charset = $this->inspector->charset();
		return array(
			'included'      => (bool) $job->param( 'include_database', true ),
			'prefix'        => $this->inspector->storedPrefix(),
			'charset'       => $charset['charset'],
			'collate'       => $charset['collate'],
			'server'        => $this->db->get_var( 'SELECT VERSION()' ),
			'tables'        => (array) $job->shared( 'tables', array() ),
			'skipped'       => (array) $job->shared( 'tables_skipped', array() ),
			'size'          => (int) $job->shared( 'database_size', 0 ),
			'exported_at'   => time(),
			'entry_pattern' => Format::ENTRY_DB_TABLES . '{table}.sql',
		);
	}

	/**
	 * Dump one table, resuming where the previous request stopped.
	 *
	 * @param Job                  $job    Job.
	 * @param \SHCM\Archive\Writer $writer Writer.
	 * @param ChecksumLedger       $ledger Ledger.
	 * @param array                $info   Table info.
	 * @param array                $state  Stage state (by reference).
	 * @param Budget               $budget Budget.
	 * @return bool True when the table is finished (or skipped because it no longer exists).
	 */
	protected function dumpTable( Job $job, $writer, ChecksumLedger $ledger, array $info, array &$state, Budget $budget ) {
		$table    = $info['name'];
		$exporter = new Exporter(
			$this->db,
			$this->inspector,
			array(
				'rows_per_query'   => $this->settings->getInt( 'db_rows_per_query', 2000 ),
				'max_insert_bytes' => $this->maxInsertBytes(),
			)
		);

		$entry_path = Format::tableEntryPath( $table );

		// Give a transient condition (a lock held by another process, a
		// restarting server) a moment before trying the same table again:
		// wait here when the request has time for it, otherwise end the
		// request and try again in the next one.
		if ( ! empty( $state['retry'] ) && $state['retry']['table'] === $table ) {
			$wait = 2 * (int) $state['retry']['count'] - ( time() - (int) $state['retry']['at'] );
			if ( $wait > 0 ) {
				if ( $wait >= $budget->remaining() - 1 ) {
					$state['waiting'] = true;
					return false;
				}
				sleep( $wait );
			}
		}

		if ( empty( $state['table'] ) ) {
			// Build the header before opening the entry, so a failed read
			// leaves nothing half written behind.
			try {
				$header = $exporter->tableHeader( $table, $info );
			} catch ( DatabaseReadException $e ) {
				if ( $this->tableVanished( $table, $e->getMessage() ) ) {
					return $this->skipVanishedTable( $job, $table, $state );
				}
				return $this->readFailed( $state, $table, $e->getMessage() );
			}

			$writer->beginEntry(
				$entry_path,
				array(
					'type'  => Format::TYPE_FILE,
					'group' => 'database',
					'mtime' => time(),
				)
			);
			$writer->append( $header );
			$state['table'] = array(
				'started' => true,
				'bytes'   => strlen( $header ),
			);
			unset( $state['retry'] );
			$this->logger->info( sprintf( 'Exporting table %s (%s rows estimated).', $table, number_format( (int) $info['rows'] ) ) );
		} elseif ( $writer->openEntryPath() !== $entry_path ) {
			// Resumed in a new request: reopen the same entry is impossible, so
			// the writer state must already point at it.
			throw new \RuntimeException(
				sprintf( 'Archive state lost track of table %s.', $table )
			);
		}

		if ( 'view' === $info['type'] ) {
			$state['table']['done'] = true;
		} else {
			$row_state = isset( $state['table']['rows'] ) ? $state['table']['rows'] : array();
			$row_state = $exporter->dumpRows(
				$table,
				$row_state,
				$budget,
				static function ( $sql ) use ( $writer ) {
					$writer->append( $sql );
				}
			);
			$state['table']['rows'] = $row_state;
			$state['table']['done'] = ! empty( $row_state['done'] );

			if ( ! empty( $row_state['error'] ) ) {
				// Everything read so far is in the archive and the cursor sits
				// on the last row written: retrying continues from there.
				return $this->readFailed( $state, $table, $row_state['error'] );
			}
			unset( $state['retry'] );
		}

		if ( empty( $state['table']['done'] ) ) {
			return false;
		}

		$summary = $writer->finishEntry();
		$ledger->record( $job, $summary );

		$rows = isset( $state['table']['rows']['rows'] ) ? (int) $state['table']['rows']['rows'] : 0;
		$this->recordTable( $job, $table, $info['type'], $rows, (int) $summary['size'] );

		$this->logger->info(
			sprintf(
				'Table %1$s exported: %2$s rows, %3$s of SQL (%4$s in the archive).',
				$table,
				number_format( $rows ),
				Bytes::format( (int) $summary['size'] ),
				Bytes::format( (int) $summary['stored'] )
			)
		);

		return true;
	}

	/**
	 * Remember what was written for a table.
	 *
	 * @param Job    $job   Job.
	 * @param string $table Table name.
	 * @param string $type  table|view.
	 * @param int    $rows  Rows written.
	 * @param int    $bytes SQL bytes written.
	 * @return void
	 */
	protected function recordTable( Job $job, $table, $type, $rows, $bytes ) {
		$exported           = (array) $job->shared( 'tables_exported', array() );
		$exported[ $table ] = array(
			'type'  => $type,
			'rows'  => $rows,
			'bytes' => $bytes,
		);
		$job->setShared( 'tables_exported', $exported );

		$totals              = (array) $job->shared( 'database_totals', array() );
		$totals['tables']    = ( isset( $totals['tables'] ) ? (int) $totals['tables'] : 0 ) + 1;
		$totals['rows']      = ( isset( $totals['rows'] ) ? (int) $totals['rows'] : 0 ) + $rows;
		$totals['sql_bytes'] = ( isset( $totals['sql_bytes'] ) ? (int) $totals['sql_bytes'] : 0 ) + $bytes;
		$job->setShared( 'database_totals', $totals );
	}

	/**
	 * Count a failed read and decide whether to retry on the next request.
	 *
	 * @param array  $state Stage state (by reference).
	 * @param string $table Table name.
	 * @param string $error Error message.
	 * @return bool Always false (the table is not finished).
	 * @throws \RuntimeException After too many consecutive failures.
	 */
	protected function readFailed( array &$state, $table, $error ) {
		$retry = isset( $state['retry'] ) && $state['retry']['table'] === $table ? $state['retry'] : array(
			'table' => $table,
			'count' => 0,
		);
		++$retry['count'];
		$retry['at']    = time();
		$state['retry'] = $retry;

		$this->logger->warning( sprintf( 'Reading table %1$s failed (attempt %2$d of %3$d): %4$s', $table, $retry['count'], self::MAX_READ_RETRIES, $error ) );

		if ( $retry['count'] >= self::MAX_READ_RETRIES ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: table name, 2: attempts, 3: database error */
					__( 'The database table %1$s could not be read after %2$d attempts, so the export was stopped rather than produce an archive with an incomplete table. Database error: %3$s', 'sh-clone-migration' ),
					$table,
					self::MAX_READ_RETRIES,
					$error
				)
			);
		}
		return false;
	}

	/**
	 * Whether a table disappeared after the scan.
	 *
	 * @param string $table Table name.
	 * @param string $error The read error.
	 * @return bool
	 */
	protected function tableVanished( $table, $error ) {
		if ( false === stripos( $error, "doesn't exist" ) && false === stripos( $error, 'does not exist' ) ) {
			return false;
		}
		$this->inspector->flush();
		return ! isset( $this->inspector->inventory( true )[ $table ] );
	}

	/**
	 * Skip a table that was dropped after the scan (a plugin's temporary
	 * table, typically) and say so.
	 *
	 * @param Job    $job   Job.
	 * @param string $table Table name.
	 * @param array  $state Stage state (by reference).
	 * @return bool True: move on to the next table.
	 */
	protected function skipVanishedTable( Job $job, $table, array &$state ) {
		$vanished   = (array) $job->shared( 'tables_vanished', array() );
		$vanished[] = $table;
		$job->setShared( 'tables_vanished', array_values( array_unique( $vanished ) ) );
		$job->addWarning(
			sprintf(
				/* translators: %s: table name */
				__( 'Table %s was deleted from the database while the export was running, so it is not in the archive.', 'sh-clone-migration' ),
				$table
			)
		);
		unset( $state['retry'] );
		return true;
	}

	/**
	 * Refuse to continue unless every planned table has an entry.
	 *
	 * @param Job   $job    Job.
	 * @param array $tables Planned tables.
	 * @return void
	 * @throws \RuntimeException When a table is missing.
	 */
	protected function assertAllTablesExported( Job $job, array $tables ) {
		if ( ! $job->param( 'include_database', true ) ) {
			return;
		}
		$exported = (array) $job->shared( 'tables_exported', array() );
		$vanished = array_flip( (array) $job->shared( 'tables_vanished', array() ) );
		$missing  = array();
		foreach ( $tables as $info ) {
			if ( ! isset( $exported[ $info['name'] ] ) && ! isset( $vanished[ $info['name'] ] ) ) {
				$missing[] = $info['name'];
			}
		}
		if ( ! empty( $missing ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: table names */
					__( 'These database tables were planned but not written to the archive: %s', 'sh-clone-migration' ),
					implode( ', ', array_slice( $missing, 0, 20 ) )
				)
			);
		}
	}

	/**
	 * Write trigger definitions, when the host exposes them.
	 *
	 * @param Job                  $job    Job.
	 * @param \SHCM\Archive\Writer $writer Writer.
	 * @param ChecksumLedger       $ledger Ledger.
	 * @return void
	 */
	protected function writeTriggers( Job $job, $writer, ChecksumLedger $ledger ) {
		if ( ! $job->param( 'include_database', true ) ) {
			return;
		}
		try {
			$exporter   = new Exporter( $this->db, $this->inspector );
			$statements = $exporter->triggerStatements( array_keys( (array) $job->shared( 'tables_exported', array() ) ) );
		} catch ( \Exception $e ) {
			$job->addWarning( 'Trigger definitions could not be read, so database triggers are not in the archive: ' . $e->getMessage() );
			return;
		}

		if ( empty( $statements ) ) {
			return;
		}

		$ledger->record(
			$job,
			$writer->addString(
				Format::ENTRY_DB_ROUTINES,
				Json::encode( array( 'triggers' => $statements ), true ),
				array(
					'group' => 'database',
					'mtime' => time(),
				)
			)
		);
		$this->logger->info( sprintf( '%d database triggers recorded.', count( $statements ) ) );
	}

	/**
	 * Largest INSERT statement to generate.
	 *
	 * Kept below this server's max_allowed_packet, because a statement the
	 * source cannot send is one the destination probably cannot accept either.
	 * A single row larger than the limit is still emitted on its own; there is
	 * nothing else to do with it, and the importer explains the failure.
	 *
	 * @return int
	 */
	protected function maxInsertBytes() {
		$configured = $this->settings->getInt( 'db_max_insert_bytes', 524288 );

		$row = $this->db->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_N );
		if ( ! empty( $row[1] ) ) {
			$packet = (int) $row[1];
			if ( $packet > 0 ) {
				$configured = (int) min( $configured, max( 65536, $packet * 0.8 ) );
			}
		}

		return $configured;
	}

	/**
	 * Ledger path for this job.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function ledgerPath( Job $job ) {
		return Paths::trailingslash( $this->storage->tmp() ) . $job->id() . '-checksums.ndjson';
	}
}
