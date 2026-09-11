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

			if ( 'tables' === $state['phase'] ) {
				if ( ! $job->param( 'include_database', true ) ) {
					$state['phase'] = 'triggers';
				}
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
				}
			}

			if ( 'triggers' === $state['phase'] ) {
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
			$this->logger->info( sprintf( 'Database export finished: %d tables.', count( $tables ) ) );
			return $this->complete(
				sprintf(
					/* translators: %s: number of tables */
					__( '%s database tables exported', 'sh-clone-migration' ),
					number_format_i18n( count( $tables ) )
				)
			);
		}

		$current = isset( $tables[ $state['index'] ] ) ? $tables[ $state['index'] ]['name'] : '';
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
		$ledger->reset();
		$job->setShared( 'checksum_digest', bin2hex( Format::initialHashState() ) );
		$job->setShared( 'checksum_entries', 0 );

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
			'prefix'        => $this->inspector->prefix(),
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
	 * @return bool True when the table is finished.
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

		$entry_path = Format::ENTRY_DB_TABLES . $this->safeTableFileName( $table ) . '.sql';

		if ( empty( $state['table'] ) ) {
			$writer->beginEntry(
				$entry_path,
				array(
					'type'  => Format::TYPE_FILE,
					'group' => 'database',
					'mtime' => time(),
				)
			);
			$writer->append( $exporter->tableHeader( $table, $info ) );
			$state['table'] = array( 'started' => true );
			$this->logger->info( sprintf( 'Exporting table %s (%s rows estimated).', $table, number_format_i18n( $info['rows'] ) ) );
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
			$state['rows']          = (int) $state['rows'] + 0;
		}

		if ( empty( $state['table']['done'] ) ) {
			return false;
		}

		$summary = $writer->finishEntry();
		$ledger->record( $job, $summary );
		$this->logger->info(
			sprintf(
				'Table %1$s exported (%2$s in the archive).',
				$table,
				Bytes::format( $summary['stored'] )
			)
		);

		return true;
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
			$statements = $exporter->triggerStatements();
		} catch ( \Exception $e ) {
			$job->addWarning( 'Trigger definitions could not be read: ' . $e->getMessage() );
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
	 * Make a table name safe to use as an archive entry file name.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	protected function safeTableFileName( $table ) {
		$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $table );
		return '' === $safe ? 'table_' . md5( $table ) : $safe;
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
