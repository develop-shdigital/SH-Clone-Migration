<?php
/**
 * Import: database restore.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Archive\Format;
use SHCM\Archive\Reader;
use SHCM\Core\Settings;
use SHCM\Database\Importer;
use SHCM\Database\Inspector;
use SHCM\Database\PrefixRewriter;
use SHCM\Database\SqlStreamReader;
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
 * Replaces the destination database with the one inside the archive.
 *
 * The destination keeps its own wp-config.php, so its table prefix wins and
 * every statement is rewritten to it on the way in. As soon as the data is in
 * place the site URL and the active plugin list are pointed at the destination,
 * so that a page load between two ticks cannot bounce a visitor to the source
 * site or fatal on a plugin that has not been restored yet.
 */
class DatabaseStage extends AbstractStage {

	/**
	 * Bytes of SQL to process before returning so the job state is saved.
	 */
	const CHECKPOINT_BYTES = 8388608;

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
		return __( 'Restoring the database', 'sh-clone-migration' );
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
		if ( ! $job->param( 'include_database', true ) ) {
			return $this->complete( __( 'Database restore skipped', 'sh-clone-migration' ) );
		}

		$reader = new Reader( $job->param( 'archive_path' ), $job->password() );
		$state  = $job->stageState(
			$this->key(),
			array(
				'phase'        => 'plan',
				'entry_offset' => 0,
				'raw_offset'   => 0,
				'entry'        => null,
				'tables_done'  => 0,
				'statements'   => 0,
				'duplicates'   => 0,
			)
		);

		if ( 'plan' === $state['phase'] ) {
			$this->plan( $job, $reader );
			$state['phase']        = 'replace' === $job->param( 'import_mode', 'replace' ) ? 'drop' : 'restore';
			$state['entry_offset'] = $reader->firstEntryOffset();
			$job->setStageState( $this->key(), $state );
			return $this->progress( __( 'Database plan prepared', 'sh-clone-migration' ), 0.02 );
		}

		$rewriter = new PrefixRewriter( (string) $job->shared( 'source_prefix' ), $this->db->prefix );
		$importer = new Importer( $this->db, $rewriter );

		if ( 'drop' === $state['phase'] ) {
			$dropped = $importer->dropTablesWithPrefix( $this->db->prefix );
			$this->logger->warning( sprintf( '%d existing tables dropped from the destination database.', $dropped ) );
			$state['phase'] = 'restore';
			$job->setStageState( $this->key(), $state );
			return $this->progress(
				sprintf(
					/* translators: %d: number of tables */
					__( '%d existing tables removed', 'sh-clone-migration' ),
					$dropped
				),
				0.05
			);
		}

		if ( 'restore' === $state['phase'] ) {
			$this->restore( $job, $reader, $importer, $state, $budget );
			$job->setStageState( $this->key(), $state );

			if ( 'post' !== $state['phase'] ) {
				$total = max( 1, (int) $job->shared( 'source_table_count', 1 ) );
				return $this->progress(
					sprintf(
						/* translators: 1: tables restored, 2: total tables */
						__( 'Restoring database: %1$d of %2$d tables', 'sh-clone-migration' ),
						$state['tables_done'],
						$total
					),
					min( 0.95, 0.05 + ( $state['tables_done'] / $total ) * 0.9 )
				);
			}
		}

		$this->afterRestore( $job, $rewriter );
		$state['phase'] = 'done';
		$job->setStageState( $this->key(), $state );

		$this->logger->info(
			sprintf(
				'Database restored: %1$d tables, %2$d statements executed.',
				$state['tables_done'],
				$state['statements']
			)
		);

		return $this->complete(
			sprintf(
				/* translators: %d: number of tables */
				__( '%d database tables restored', 'sh-clone-migration' ),
				$state['tables_done']
			)
		);
	}

	/**
	 * Read the dump metadata and work out what has to happen.
	 *
	 * @param Job    $job    Job.
	 * @param Reader $reader Reader.
	 * @return void
	 */
	protected function plan( Job $job, Reader $reader ) {
		$entry = $reader->findEntry( Format::ENTRY_DB_META );
		if ( null === $entry ) {
			throw new \RuntimeException( __( 'The archive contains no database metadata.', 'sh-clone-migration' ) );
		}
		$meta = Json::decode( $reader->readString( $entry ) );
		if ( null === $meta ) {
			throw new \RuntimeException( __( 'The database metadata in the archive could not be parsed.', 'sh-clone-migration' ) );
		}

		$job->setShared( 'database_meta', $meta );
		$job->setShared( 'source_prefix', isset( $meta['prefix'] ) ? $meta['prefix'] : '' );
		$job->setShared( 'source_table_count', isset( $meta['tables'] ) ? count( $meta['tables'] ) : 0 );
		$job->setShared( 'source_charset', isset( $meta['charset'] ) ? $meta['charset'] : '' );

		$this->logger->info(
			sprintf(
				'Restoring %1$d tables. Source prefix "%2$s" becomes "%3$s".',
				(int) $job->shared( 'source_table_count' ),
				$job->shared( 'source_prefix' ),
				$this->db->prefix
			)
		);
	}

	/**
	 * Execute the dump statements.
	 *
	 * @param Job      $job      Job.
	 * @param Reader   $reader   Reader.
	 * @param Importer $importer Importer.
	 * @param array    $state    State (by reference).
	 * @param Budget   $budget   Budget.
	 * @return void
	 */
	protected function restore( Job $job, Reader $reader, Importer $importer, array &$state, Budget $budget ) {
		$importer->prepareSession( (string) $job->shared( 'source_charset', '' ) );

		$reader->seek( $state['entry_offset'] );
		$processed = 0;
		$units     = 0;

		while ( $budget->shouldContinue( $units ) && $processed < self::CHECKPOINT_BYTES ) {
			++$units;
			if ( null === $state['entry'] ) {
				$entry = $reader->nextEntry();
				if ( null === $entry ) {
					$state['phase'] = 'post';
					return;
				}

				$path = $entry['path'];
				if ( 0 === strpos( $path, Format::ENTRY_FILES ) || 0 === strpos( $path, 'checksums/' ) ) {
					// The database section is over.
					$job->setShared( 'files_start_offset', $entry['header_offset'] );
					$state['phase'] = 'post';
					return;
				}

				if ( 0 !== strpos( $path, 'database/' ) ) {
					$reader->skipEntry( $entry );
					$state['entry_offset'] = $reader->entryEndOffset( $entry );
					continue;
				}

				if ( Format::ENTRY_DB_META === $path ) {
					$reader->skipEntry( $entry );
					$state['entry_offset'] = $reader->entryEndOffset( $entry );
					continue;
				}

				if ( Format::ENTRY_DB_ROUTINES === $path ) {
					$this->restoreTriggers( $job, $reader, $importer, $entry );
					$state['entry_offset'] = $reader->entryEndOffset( $entry );
					continue;
				}

				$state['entry']      = $entry;
				$state['raw_offset'] = 0;
				$this->logger->debug( sprintf( 'Restoring %s', $path ) );
			}

			$entry  = $state['entry'];
			$sql    = new SqlStreamReader();
			$offset = (int) $state['raw_offset'];
			$stop   = false;

			$result = $reader->streamFrom(
				$entry,
				$offset,
				self::CHECKPOINT_BYTES,
				function ( $chunk, $position ) use ( $sql, $importer, &$state, $budget, &$stop, &$processed ) {
					$sql->feed( $chunk );
					$processed += strlen( $chunk );

					while ( true ) {
						$statement = $sql->next();
						if ( null === $statement ) {
							break;
						}
						if ( '' === $statement ) {
							continue;
						}
						$this->executeStatement( $importer, $statement, $state );
					}

					// The safe resume point is everything the splitter has
					// actually consumed, not everything we have read.
					$state['raw_offset'] = $position - $sql->bufferedBytes();

					if ( $budget->expired() ) {
						$stop = true;
						return false;
					}
					return true;
				}
			);

			if ( $result['done'] ) {
				$leftover = $sql->flush();
				if ( null !== $leftover ) {
					$this->executeStatement( $importer, $leftover, $state );
				}
				$state['raw_offset']   = 0;
				$state['entry_offset'] = $reader->entryEndOffset( $entry );
				$state['entry']        = null;
				$state['tables_done']++;
			} elseif ( $stop ) {
				return;
			}
		}
	}

	/**
	 * Execute one statement, tolerating the duplicates a resumed tick can
	 * produce.
	 *
	 * @param Importer $importer Importer.
	 * @param string   $statement Statement.
	 * @param array    $state     State (by reference).
	 * @return void
	 */
	protected function executeStatement( Importer $importer, $statement, array &$state ) {
		try {
			$importer->execute( $statement );
			$state['statements']++;
		} catch ( \RuntimeException $e ) {
			// A request that died between executing a batch and saving its
			// position replays those rows; they are byte identical, so a
			// duplicate key here means "already restored", not "corrupt".
			if ( false !== strpos( $e->getMessage(), 'Database error 1062' ) ) {
				$state['duplicates']++;
				return;
			}
			throw $e;
		}
	}

	/**
	 * Restore trigger definitions.
	 *
	 * @param Job      $job      Job.
	 * @param Reader   $reader   Reader.
	 * @param Importer $importer Importer.
	 * @param array    $entry    Entry descriptor.
	 * @return void
	 */
	protected function restoreTriggers( Job $job, Reader $reader, Importer $importer, array $entry ) {
		$data = Json::decode( $reader->readString( $entry ) );
		if ( null === $data || empty( $data['triggers'] ) ) {
			return;
		}
		foreach ( $data['triggers'] as $trigger ) {
			try {
				$importer->execute( 'DROP TRIGGER IF EXISTS `' . str_replace( '`', '``', $trigger['name'] ) . '`' );
				$importer->execute( $trigger['sql'] );
			} catch ( \Exception $e ) {
				$job->addWarning(
					sprintf(
						/* translators: 1: trigger name, 2: error */
						__( 'Trigger %1$s could not be restored: %2$s', 'sh-clone-migration' ),
						$trigger['name'],
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Point the freshly restored database at this installation.
	 *
	 * @param Job            $job      Job.
	 * @param PrefixRewriter $rewriter Prefix rewriter.
	 * @return void
	 */
	protected function afterRestore( Job $job, PrefixRewriter $rewriter ) {
		// The schema is completely different from the one this process saw at
		// startup; every cached view of it is now wrong.
		$this->inspector->flush();

		$report = $rewriter->rewriteStoredKeys( $this->db );
		if ( $report['options'] || $report['usermeta'] ) {
			$this->logger->info(
				sprintf(
					'Prefixed keys rewritten: %1$d option rows, %2$d user meta rows.',
					$report['options'],
					$report['usermeta']
				)
			);
		}

		$options = $this->db->prefix . 'options';
		$source  = (string) $job->shared( 'source' );

		// Read what the source considered active before overwriting it.
		$active = $this->db->get_var(
			$this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'active_plugins' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$job->setShared( 'source_active_plugins', is_string( $active ) ? $active : '' );

		$theme = array(
			'stylesheet' => $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'stylesheet' ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'template'   => $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'template' ) ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$job->setShared( 'source_theme', $theme );

		// Only this plugin stays active until the files are back, so that a
		// page load in the middle of the restore cannot fatal on a plugin
		// whose files have not been written yet.
		$self = defined( 'SHCM_PLUGIN_BASENAME' ) ? SHCM_PLUGIN_BASENAME : 'sh-clone-migration/sh-clone-migration.php';
		$this->writeOption( 'active_plugins', serialize( array( $self ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		// The destination URL must win immediately: never leave the source URL
		// in place, not even between two ticks of the same job.
		$destination = (string) $job->shared( 'destination' );
		if ( '' !== $destination ) {
			$this->writeOption( 'home', $destination );
			$this->writeOption( 'siteurl', $destination );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		$this->db->flush();

		$this->logger->info(
			sprintf( 'Site URL pointed at the destination (%1$s). Source was %2$s.', $destination, $source )
		);
	}

	/**
	 * Write an option straight to the table, bypassing every cache.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 * @return void
	 */
	protected function writeOption( $name, $value ) {
		$table  = $this->db->prefix . 'options';
		$exists = $this->db->get_var(
			$this->db->prepare( "SELECT option_id FROM `{$table}` WHERE option_name = %s", $name ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( null === $exists ) {
			$this->db->query(
				$this->db->prepare(
					"INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'yes')", // phpcs:ignore WordPress.DB.PreparedSQL
					$name,
					$value
				)
			);
			return;
		}

		$this->db->query(
			$this->db->prepare(
				"UPDATE `{$table}` SET option_value = %s WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$value,
				$name
			)
		);
	}

	/**
	 * Leave maintenance mode alone here: the restore is mid-flight and the
	 * finalize stage owns turning it off.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		if ( null !== $error && $job->shared( 'maintenance' ) ) {
			MaintenanceMode::disable();
			$job->setShared( 'maintenance', false );
		}
	}
}
