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
			if ( ! $this->plan( $job, $reader ) ) {
				$state['phase'] = 'done';
				$job->setStageState( $this->key(), $state );
				return $this->complete( __( 'The archive holds no database; the database was left unchanged', 'sh-clone-migration' ) );
			}
			$state['phase']        = 'replace' === $job->param( 'import_mode', 'replace' ) ? 'drop' : 'restore';
			$state['entry_offset'] = $reader->firstEntryOffset();
			$job->setStageState( $this->key(), $state );
			return $this->progress( __( 'Database plan prepared', 'sh-clone-migration' ), 0.02 );
		}

		if ( 'done' === $state['phase'] ) {
			return $this->complete( __( 'Database restore finished', 'sh-clone-migration' ) );
		}

		$rewriter = new PrefixRewriter( (string) $job->shared( 'source_prefix' ), $this->db->prefix );
		$importer = new Importer( $this->db, $rewriter );

		if ( 'drop' === $state['phase'] ) {
			$dropped = $importer->dropTables( $this->ownTables() );
			$this->inspector->flush();
			$this->logger->warning( sprintf( '%d existing tables of this site dropped from the destination database.', $dropped ) );
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

			if ( 'post' === $state['phase'] ) {
				// Keep what the restored database says about the source's
				// plugins and theme, and save it, before afterRestore()
				// overwrites those options: a request killed in between must
				// not lose them.
				$this->captureSourceState( $job );
				$this->reportDuplicates( $job, $state );
				$state['phase'] = 'finalize';
				$job->setStageState( $this->key(), $state );
				return $this->progress( __( 'Database restored, pointing it at this site', 'sh-clone-migration' ), 0.97 );
			}
			if ( 'finalize' !== $state['phase'] ) {
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

		if ( 'post' === $state['phase'] ) {
			$this->captureSourceState( $job );
			$this->reportDuplicates( $job, $state );
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
	 * Runs before anything in the destination database is touched, so every
	 * reason to refuse the restore is checked here.
	 *
	 * @param Job    $job    Job.
	 * @param Reader $reader Reader.
	 * @return bool False when the archive holds no database at all.
	 * @throws \RuntimeException When the archive's database cannot rebuild a site.
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

		$tables        = isset( $meta['tables'] ) && is_array( $meta['tables'] ) ? $meta['tables'] : array();
		$source_prefix = isset( $meta['prefix'] ) ? (string) $meta['prefix'] : '';

		if ( ( isset( $meta['included'] ) && ! $meta['included'] ) || empty( $tables ) ) {
			// Dropping the destination's tables to restore nothing would wipe
			// the site; leave the database exactly as it is.
			$job->addWarning( __( 'This archive does not contain a database, so the destination database was left unchanged. Only files were restored.', 'sh-clone-migration' ) );
			$this->logger->warning( 'The archive holds no database; the database restore was skipped and nothing was dropped.' );
			$job->setShared( 'database_absent', true );
			return false;
		}

		$names = array();
		foreach ( $tables as $table ) {
			if ( isset( $table['name'] ) ) {
				$names[] = (string) $table['name'];
			}
		}
		$options_table = '';
		foreach ( $names as $name ) {
			if ( 0 === strcasecmp( $name, $source_prefix . 'options' ) ) {
				$options_table = $name;
				break;
			}
		}

		// What the archive actually holds, not what its metadata claims:
		// version 1.0.0 listed the planned tables even when the database was
		// left out of the export.
		$entries = $this->databaseEntries( $reader );
		if ( 0 === count( $entries ) ) {
			$job->addWarning( __( 'This archive does not contain a database, so the destination database was left unchanged. Only files were restored.', 'sh-clone-migration' ) );
			$this->logger->warning( 'The archive lists tables but contains no table dumps; the database restore was skipped and nothing was dropped.' );
			$job->setShared( 'database_absent', true );
			return false;
		}
		if ( '' !== $options_table && ! isset( $entries[ Format::tableEntryPath( $options_table ) ] ) && ! isset( $entries[ Format::legacyTableEntryPath( $options_table ) ] ) ) {
			$options_table = '';
		}
		if ( '' === $options_table ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: table name */
					__( 'The archive\'s database has no %s table, so restoring it would leave a site that cannot start. The import was stopped before anything was changed.', 'sh-clone-migration' ),
					$source_prefix . 'options'
				)
			);
		}

		$job->setShared( 'database_meta', $meta );
		$job->setShared( 'source_prefix', $source_prefix );
		$job->setShared( 'source_charset', isset( $meta['charset'] ) ? $meta['charset'] : '' );

		$manifest = (array) $job->shared( 'manifest', array() );
		$skip     = $this->tablesToSkip( $names, $source_prefix, ! empty( $manifest['wordpress']['multisite'] ) );
		$job->setShared( 'source_table_count', count( $names ) - count( $skip ) );
		$paths = array();
		foreach ( $skip as $table ) {
			$paths[ Format::tableEntryPath( $table ) ]       = $table;
			$paths[ Format::legacyTableEntryPath( $table ) ] = $table;
		}
		$job->setShared( 'skipped_table_entries', $paths );
		if ( ! empty( $skip ) ) {
			$job->addWarning(
				sprintf(
					/* translators: %s: table names */
					__( 'These tables in the archive belong to another WordPress installation and were not restored, so they cannot overwrite this site\'s tables: %s', 'sh-clone-migration' ),
					implode( ', ', $skip )
				)
			);
		}

		$this->logger->info(
			sprintf(
				'Restoring %1$d tables. Source prefix "%2$s" becomes "%3$s".',
				(int) $job->shared( 'source_table_count' ),
				$source_prefix,
				$this->db->prefix
			)
		);
		return true;
	}

	/**
	 * Paths of the table dumps in the archive (the database section only).
	 *
	 * @param Reader $reader Reader.
	 * @return array<string, true>
	 */
	protected function databaseEntries( Reader $reader ) {
		$paths = array();
		$reader->seek( $reader->firstEntryOffset() );
		while ( null !== ( $entry = $reader->nextEntry() ) ) {
			$path = $entry['path'];
			if ( 0 === strpos( $path, Format::ENTRY_FILES ) || 0 === strpos( $path, 'checksums/' ) ) {
				break;
			}
			if ( 0 === strpos( $path, Format::ENTRY_DB_TABLES ) ) {
				$paths[ $path ] = true;
			}
			$reader->skipEntry( $entry );
		}
		return $paths;
	}

	/**
	 * Tables in the archive that must not be restored.
	 *
	 * Archives made by version 1.0.0 could include the tables of a second
	 * installation sharing the source database (wp_ next to wp_shop_). Such a
	 * table is recognised the way the exporter now recognises it: the archive
	 * holds the full set of site tables (Inspector::siteTables()) for another
	 * prefix X (the numbered sites of a multisite network excepted), and X is
	 * the longest installation prefix the table starts with. Independently,
	 * no table is restored onto a name that belongs to another installation
	 * in the destination database, and when two archive tables would land on
	 * the same name only the source-prefixed one is restored.
	 *
	 * @param string[] $names         Table names in the archive.
	 * @param string   $source_prefix Source prefix.
	 * @param bool     $multisite     Whether the archive is of a multisite network.
	 * @return string[]
	 */
	protected function tablesToSkip( array $names, $source_prefix, $multisite = false ) {
		$lookup = array();
		foreach ( $names as $name ) {
			$lookup[ strtolower( $name ) ] = true;
		}
		$installations = array( (string) $source_prefix );
		foreach ( $names as $name ) {
			if ( ! preg_match( '/^(.+)options$/i', $name, $matches ) ) {
				continue;
			}
			$candidate = $matches[1];
			$complete  = true;
			foreach ( Inspector::siteTables() as $table ) {
				if ( ! isset( $lookup[ strtolower( $candidate . $table ) ] ) ) {
					$complete = false;
					break;
				}
			}
			if ( ! $complete ) {
				continue; // Plugin tables that merely look like a site.
			}
			if ( $multisite && '' !== $source_prefix && preg_match( '/^' . preg_quote( $source_prefix, '/' ) . '\d+_$/i', $candidate ) ) {
				continue; // A site of the network being restored.
			}
			$installations[] = $candidate;
		}
		usort(
			$installations,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$rewriter = new PrefixRewriter( $source_prefix, $this->db->prefix );
		$skip     = array();
		$targets  = array();
		foreach ( $names as $name ) {
			$owner = null;
			foreach ( $installations as $candidate ) {
				if ( 0 === strncasecmp( $name, $candidate, strlen( $candidate ) ) ) {
					$owner = $candidate;
					break;
				}
			}
			if ( null !== $owner && 0 !== strcasecmp( $owner, (string) $source_prefix ) ) {
				$skip[] = $name; // Another installation's table.
				continue;
			}
			// Where the table will land, and whether that name is this site's.
			$target = $rewriter->table( $name );
			if ( ! $this->inspector->isOwnTableName( $target ) ) {
				$skip[] = $name;
				continue;
			}
			$targets[ strtolower( $target ) ][] = $name;
		}

		foreach ( $targets as $candidates ) {
			if ( count( $candidates ) < 2 ) {
				continue;
			}
			foreach ( $candidates as $name ) {
				if ( '' !== $source_prefix && 0 !== strncmp( $name, $source_prefix, strlen( $source_prefix ) ) ) {
					$skip[] = $name; // Would overwrite one of this site's restored tables.
				}
			}
		}
		return array_values( array_unique( $skip ) );
	}

	/**
	 * This site's tables in the destination database (never those of another
	 * installation that shares it, even when its prefix starts with ours).
	 *
	 * @return array[] Each: name, table_type.
	 */
	protected function ownTables() {
		$tables = array();
		foreach ( $this->inspector->inventory( true ) as $name => $info ) {
			if ( ! empty( $info['prefixed'] ) && ! empty( $info['owned'] ) ) {
				$tables[] = array(
					'name'       => $name,
					'table_type' => 'view' === $info['type'] ? 'VIEW' : 'BASE TABLE',
				);
			}
		}
		return $tables;
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

		// A request that dies part way through a table leaves this marker
		// behind, and the job state still holds the position that request
		// started from: the statements it ran would run a second time, and
		// a table without a unique key would get those rows twice. Restoring
		// that table again from its start is idempotent, because its dump
		// begins with DROP TABLE and CREATE TABLE.
		$marker = Paths::trailingslash( $this->storage->tmp() ) . $job->id() . '-restore.marker';
		if ( is_file( $marker ) ) {
			$previous = Json::decode( (string) @file_get_contents( $marker ) );
			if ( is_array( $previous ) && ! empty( $state['entry'] ) && (int) $state['raw_offset'] > 0
				&& isset( $previous['entry_offset'], $previous['raw_offset'] )
				&& (int) $previous['entry_offset'] === (int) $state['entry_offset']
				&& (int) $previous['raw_offset'] === (int) $state['raw_offset'] ) {
				$this->logger->warning( sprintf( 'The previous request stopped part way through %s; restoring that table again from its start.', $state['entry']['path'] ) );
				$state['raw_offset'] = 0;
			}
		}
		@file_put_contents(
			$marker,
			Json::encode(
				array(
					'entry_offset' => (int) $state['entry_offset'],
					'raw_offset'   => (int) $state['raw_offset'],
				)
			)
		);

		try {
			$this->restoreEntries( $job, $reader, $importer, $state, $budget );
		} finally {
			@unlink( $marker );
		}
	}

	/**
	 * The body of restore().
	 *
	 * @param Job      $job      Job.
	 * @param Reader   $reader   Reader.
	 * @param Importer $importer Importer.
	 * @param array    $state    State (by reference).
	 * @param Budget   $budget   Budget.
	 * @return void
	 */
	protected function restoreEntries( Job $job, Reader $reader, Importer $importer, array &$state, Budget $budget ) {
		$reader->seek( $state['entry_offset'] );
		$processed = 0;
		$units     = 0;

		while ( $budget->shouldContinue( $units ) && ( $processed < self::CHECKPOINT_BYTES || 0 === $units ) ) {
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

				$skipped = (array) $job->shared( 'skipped_table_entries', array() );
				if ( isset( $skipped[ $path ] ) ) {
					$this->logger->warning( sprintf( 'Table %s skipped: it belongs to another installation.', $skipped[ $path ] ) );
					$reader->skipEntry( $entry );
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

			// No read limit here: the callback decides when to stop, and it
			// only stops once the position has moved, so a single statement
			// larger than the checkpoint (a 12 MB option value) is read to
			// its end instead of being re-read forever.
			$result = $reader->streamFrom(
				$entry,
				$offset,
				0,
				function ( $chunk, $position ) use ( $sql, $importer, &$state, $budget, &$stop, &$processed, $offset ) {
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

					// Stop only once this request has moved the position: a
					// statement larger than one block must be read to its end,
					// or a request that starts out of budget would never pass it.
					if ( ( $budget->expired() || $processed >= self::CHECKPOINT_BYTES ) && $state['raw_offset'] > $offset ) {
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
	 * Execute one statement, tolerating the replay a resumed tick produces.
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
			// position replays it. Some or all of its rows are then already
			// in the table; skipping the whole statement would lose the rest
			// (a multi-row INSERT holds thousands of rows), so it runs again
			// keeping the rows that are there and adding the ones that are not.
			if ( false === strpos( $e->getMessage(), 'Database error 1062' ) ) {
				throw $e;
			}
			$ignore = preg_replace( '/^(\s*)INSERT\s+INTO\b/i', '$1INSERT IGNORE INTO', $statement, 1, $count );
			if ( 1 !== $count ) {
				throw $e;
			}
			$importer->execute( $ignore );
			$state['statements']++;
			$state['duplicates']++;

			// A replayed request restarts its table, so a duplicate here is a
			// real one (keys the source considered distinct but that come out
			// equal, such as FLOAT values printed with six digits). The rows
			// that collided cannot be stored; count them and say so.
			$rows  = $importer->lastDuplicates();
			$table = $importer->targetOf( $ignore );
			if ( ! isset( $state['duplicate_rows'] ) || ! is_array( $state['duplicate_rows'] ) ) {
				$state['duplicate_rows'] = array();
			}
			$state['duplicate_rows'][ $table ] = ( isset( $state['duplicate_rows'][ $table ] ) ? (int) $state['duplicate_rows'][ $table ] : 0 ) + ( null === $rows ? 1 : $rows );
			$this->logger->warning( sprintf( 'Duplicate keys while restoring %1$s: %2$s row(s) could not be stored.', $table, null === $rows ? 'some' : (string) $rows ) );
		}
	}

	/**
	 * Turn the duplicate rows counted during the restore into a warning.
	 *
	 * @param Job   $job   Job.
	 * @param array $state Stage state.
	 * @return void
	 */
	protected function reportDuplicates( Job $job, array $state ) {
		if ( empty( $state['duplicate_rows'] ) ) {
			return;
		}
		$parts = array();
		foreach ( (array) $state['duplicate_rows'] as $table => $rows ) {
			$parts[] = $table . ' (' . (int) $rows . ')';
		}
		$job->addWarning(
			sprintf(
				/* translators: %s: tables with row counts */
				__( 'Some rows could not be restored because their unique keys came out equal to other rows (for example FLOAT keys that differ only beyond six digits): %s', 'sh-clone-migration' ),
				implode( ', ', $parts )
			)
		);
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
		$skipped  = array_flip( array_map( 'strtolower', array_values( (array) $job->shared( 'skipped_table_entries', array() ) ) ) );
		$rewriter = new PrefixRewriter( (string) $job->shared( 'source_prefix' ), $this->db->prefix );
		foreach ( $data['triggers'] as $trigger ) {
			$table = isset( $trigger['table'] ) ? (string) $trigger['table'] : '';
			if ( '' === $table && isset( $trigger['sql'] ) && preg_match( '/\bON\s+`((?:[^`]|``)*)`/i', (string) $trigger['sql'], $matches ) ) {
				$table = str_replace( '``', '`', $matches[1] );
			}
			if ( '' !== $table && ( isset( $skipped[ strtolower( $table ) ] ) || ! $this->inspector->isOwnTableName( $rewriter->table( $table ) ) ) ) {
				$this->logger->warning( sprintf( 'Trigger %1$s skipped: its table %2$s belongs to another installation.', $trigger['name'], $table ) );
				continue;
			}
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
	 * Remember what the restored database says about the source's active
	 * plugins and theme, before afterRestore() changes those options.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	protected function captureSourceState( Job $job ) {
		$options = $this->db->prefix . 'options';
		$active  = $this->db->get_var(
			$this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'active_plugins' ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$job->setShared( 'source_active_plugins', is_string( $active ) ? $active : '' );

		$theme = array(
			'stylesheet' => $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'stylesheet' ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'template'   => $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'template' ) ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$job->setShared( 'source_theme', $theme );
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

		$source = (string) $job->shared( 'source' );

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
