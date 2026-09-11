<?php
/**
 * Import: rollback point.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Archive\Format;
use SHCM\Archive\Writer;
use SHCM\Core\Settings;
use SHCM\Database\Exporter;
use SHCM\Database\Inspector;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\Support\Bytes;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots the destination database before it is replaced.
 *
 * Files are restored in place and are not covered here; the admin screen
 * offers a full export as a safety backup for that. A database snapshot is
 * bounded, fast, and is what a failed restore actually needs to undo.
 */
class RollbackPointStage extends AbstractStage {

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
		return 'rollback';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Creating a rollback point', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 8;
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
				'index'   => 0,
				'tables'  => null,
				'table'   => array(),
				'archive' => null,
				'path'    => '',
			)
		);

		if ( null === $state['tables'] ) {
			$free     = $this->storage->freeSpace();
			$estimate = $this->inspector->totalSize( $this->inspector->exportableTables() );
			if ( $free >= 0 && $estimate > 0 && $free < $estimate ) {
				$job->addWarning(
					sprintf(
						/* translators: 1: free space, 2: database size */
						__( 'Not enough free disk space (%1$s) for a rollback point of the current database (%2$s). The restore continues without one.', 'sh-clone-migration' ),
						Bytes::format( $free ),
						Bytes::format( $estimate )
					)
				);
				$this->logger->warning( 'Rollback point skipped: not enough disk space.' );
				return $this->complete( __( 'Rollback point skipped (insufficient disk space)', 'sh-clone-migration' ) );
			}

			$tables = array();
			foreach ( $this->inspector->exportableTables() as $name => $info ) {
				$tables[] = $info;
			}
			$state['tables'] = $tables;
			$state['path']   = Paths::trailingslash( $this->storage->rollback() ) . 'rollback-' . $job->id() . '.wpress';
			$this->logger->info( sprintf( 'Creating a rollback point of %d tables.', count( $tables ) ) );
		}

		$writer = $this->writer( $state );

		try {
			$exporter = new Exporter(
				$this->db,
				$this->inspector,
				array(
					'rows_per_query'   => $this->settings->getInt( 'db_rows_per_query', 2000 ),
					'max_insert_bytes' => $this->settings->getInt( 'db_max_insert_bytes', 524288 ),
				)
			);

			if ( empty( $state['archive'] ) ) {
				// A rollback point is a first class archive: it carries the
				// same metadata a normal export does, so it can simply be
				// imported again if the restore has to be undone.
				$this->writeMetadata( $job, $writer, $state['tables'] );
			}

			$processed = 0;
			while ( $budget->shouldContinue( $processed ) ) {
				++$processed;

				if ( $state['index'] >= count( $state['tables'] ) ) {
					break;
				}
				$info  = $state['tables'][ $state['index'] ];
				$table = $info['name'];

				if ( empty( $state['table'] ) ) {
					$writer->beginEntry(
						Format::ENTRY_DB_TABLES . preg_replace( '/[^A-Za-z0-9_\-]/', '_', $table ) . '.sql',
						array(
							'group' => 'database',
							'mtime' => time(),
						)
					);
					$writer->append( $exporter->tableHeader( $table, $info ) );
					$state['table'] = array( 'rows' => array() );
				}

				if ( 'view' === $info['type'] ) {
					$state['table']['done'] = true;
				} else {
					$rows = $exporter->dumpRows(
						$table,
						$state['table']['rows'],
						$budget,
						static function ( $sql ) use ( $writer ) {
							$writer->append( $sql );
						}
					);
					$state['table']['rows'] = $rows;
					$state['table']['done'] = ! empty( $rows['done'] );
				}

				if ( ! empty( $state['table']['done'] ) ) {
					$writer->finishEntry();
					$state['index']++;
					$state['table'] = array();
				}
			}

			$finished = $state['index'] >= count( $state['tables'] );

			if ( $finished ) {
				$writer->close( array( 'kind' => 'rollback' ) );
				$writer->release();
				$state['archive'] = null;
				$job->setShared( 'rollback_path', $state['path'] );
				$job->setShared( 'rollback_prefix', $this->db->prefix );
			} else {
				$state['archive'] = $writer->pause();
				$writer->release();
			}
		} catch ( \Throwable $e ) {
			$state['archive'] = $writer->pause();
			$writer->release();
			$job->setStageState( $this->key(), $state );
			throw $e;
		}

		$job->setStageState( $this->key(), $state );

		$total = max( 1, count( $state['tables'] ) );
		if ( $state['index'] >= count( $state['tables'] ) ) {
			$this->logger->info(
				sprintf(
					'Rollback point written: %1$s (%2$s).',
					basename( $state['path'] ),
					Bytes::format( (int) @filesize( $state['path'] ) )
				)
			);
			return $this->complete( __( 'Rollback point created', 'sh-clone-migration' ) );
		}

		return $this->progress(
			sprintf(
				/* translators: 1: current table, 2: total tables */
				__( 'Backing up the current database: %1$d of %2$d tables', 'sh-clone-migration' ),
				$state['index'],
				$total
			),
			$state['index'] / $total
		);
	}

	/**
	 * Write the manifest and database metadata of a rollback archive.
	 *
	 * @param Job    $job    Job.
	 * @param Writer $writer Writer.
	 * @param array  $tables Inventory entries.
	 * @return void
	 */
	protected function writeMetadata( Job $job, Writer $writer, array $tables ) {
		global $wp_version;

		$charset    = $this->inspector->charset();
		$table_list = array();
		foreach ( $tables as $info ) {
			$table_list[] = array(
				'name' => $info['name'],
				'type' => $info['type'],
				'rows' => $info['rows'],
				'size' => $info['data_length'] + $info['index_length'],
			);
		}

		$writer->addString(
			Format::ENTRY_MANIFEST,
			Json::encode(
				array(
					'format'      => Format::VERSION,
					'generator'   => 'SH Clone Migration rollback point',
					'version'     => defined( 'SHCM_VERSION' ) ? SHCM_VERSION : 'dev',
					'kind'        => 'rollback',
					'created'     => time(),
					'created_utc' => gmdate( 'c' ),
					'job'         => $job->id(),
					'site'        => array(
						'home'      => untrailingslashit( home_url() ),
						'siteurl'   => untrailingslashit( site_url() ),
						'name'      => get_bloginfo( 'name' ),
						'multisite' => is_multisite(),
					),
					'wordpress'   => array(
						'version'      => isset( $wp_version ) ? $wp_version : get_bloginfo( 'version' ),
						'abspath'      => Paths::abspath(),
						'content_dir'  => Paths::contentDir(),
						'uploads_dir'  => Paths::uploadsDir(),
						'table_prefix' => $this->db->prefix,
						'multisite'    => is_multisite(),
					),
					'php'         => array( 'version' => PHP_VERSION ),
					'database'    => array(
						'server'  => $this->db->get_var( 'SELECT VERSION()' ),
						'charset' => $charset,
						'prefix'  => $this->db->prefix,
						'tables'  => count( $table_list ),
					),
					'files'       => array(
						'count' => 0,
						'size'  => 0,
					),
					'archive'     => array(
						'block_size'  => $this->settings->getInt( 'block_size', 1048576 ),
						'compression' => 'gzip',
						'encrypted'   => false,
						'database_only' => true,
					),
				),
				true
			),
			array( 'group' => 'meta' )
		);

		$writer->addString(
			Format::ENTRY_DB_META,
			Json::encode(
				array(
					'prefix'      => $this->db->prefix,
					'charset'     => $charset['charset'],
					'collate'     => $charset['collate'],
					'server'      => $this->db->get_var( 'SELECT VERSION()' ),
					'tables'      => $table_list,
					'skipped'     => array(),
					'exported_at' => time(),
					'kind'        => 'rollback',
				),
				true
			),
			array( 'group' => 'meta' )
		);
	}

	/**
	 * Create or resume the rollback writer.
	 *
	 * @param array $state Stage state (by reference).
	 * @return Writer
	 */
	protected function writer( array &$state ) {
		if ( ! empty( $state['archive'] ) ) {
			return Writer::resume( $state['archive'] );
		}
		return Writer::create(
			$state['path'],
			array(
				'block_size' => $this->settings->getInt( 'block_size', 1048576 ),
				'compress'   => function_exists( 'gzdeflate' ),
				'level'      => $this->settings->getInt( 'compression_level', 6 ),
				'prologue'   => array( 'kind' => 'rollback' ),
			)
		);
	}

	/**
	 * Keep maintenance mode tidy on failure.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		unset( $error );
		if ( $job->shared( 'maintenance' ) ) {
			MaintenanceMode::disable();
			$job->setShared( 'maintenance', false );
		}
	}
}
