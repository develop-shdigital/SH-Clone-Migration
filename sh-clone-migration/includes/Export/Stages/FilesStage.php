<?php
/**
 * Export: filesystem.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Archive\Format;
use SHCM\Archive\Session;
use SHCM\Export\ChecksumLedger;
use SHCM\Filesystem\FileQueue;
use SHCM\Filesystem\Paths;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Streams every queued file into the archive.
 *
 * A file larger than the remaining request budget is not a problem: the entry
 * stays open across requests and continues from the exact byte it stopped at.
 */
class FilesStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'files';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Exporting files', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 50;
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
				'queue_offset' => 0,
				'current'      => null,
				'done_files'   => 0,
				'done_bytes'   => 0,
				'groups'       => array(),
			)
		);

		$totals = (array) $job->shared( 'file_totals', array() );
		$queue  = new FileQueue( $this->queuePath( $job ) );
		if ( ! $queue->exists() ) {
			return $this->complete( __( 'No files to export', 'sh-clone-migration' ) );
		}

		$writer = Session::open( $job );
		$ledger = new ChecksumLedger( $this->ledgerPath( $job ) );
		$roots  = (array) $job->shared( 'roots', array() );

		try {
			$queue->openReader( $state['queue_offset'] );

			// Finish a file that a previous request left half written.
			if ( ! empty( $state['current'] ) ) {
				$this->continueFile( $job, $writer, $ledger, $state, $budget, $roots );
			}

			$item      = false;
			$processed = 0;
			while ( $budget->shouldContinue( $processed ) ) {
				++$processed;
				$item = $queue->next();
				if ( null === $item ) {
					$state['queue_offset'] = $queue->tell();
					$state['current']      = null;
					break;
				}
				$state['queue_offset'] = $queue->tell();

				$this->startItem( $job, $writer, $ledger, $item, $state, $budget, $roots );
			}

			$finished = ( null === $item ) && empty( $state['current'] );
		} finally {
			$ledger->close();
			$queue->closeReader();
			Session::park( $job, $writer );
			$job->setStageState( $this->key(), $state );
			$job->setShared( 'files_progress', $state['groups'] );
		}

		$total_bytes = isset( $totals['bytes'] ) ? max( 1, (int) $totals['bytes'] ) : 1;
		$total_files = isset( $totals['files'] ) ? max( 1, (int) $totals['files'] ) : 1;

		if ( ! empty( $finished ) ) {
			$this->logger->info(
				sprintf(
					'Files exported: %1$d files, %2$s.',
					$state['done_files'],
					Bytes::format( $state['done_bytes'] )
				)
			);
			return $this->complete(
				sprintf(
					/* translators: 1: file count, 2: size */
					__( '%1$s files exported (%2$s)', 'sh-clone-migration' ),
					number_format_i18n( $state['done_files'] ),
					Bytes::format( $state['done_bytes'] )
				)
			);
		}

		$progress = max(
			$state['done_bytes'] / $total_bytes,
			$state['done_files'] / $total_files * 0.5
		);

		return $this->progress(
			sprintf(
				/* translators: 1: files done, 2: total files, 3: size done */
				__( 'Exporting files: %1$s of %2$s (%3$s)', 'sh-clone-migration' ),
				number_format_i18n( $state['done_files'] ),
				number_format_i18n( $total_files ),
				Bytes::format( $state['done_bytes'] )
			),
			min( 0.999, $progress )
		);
	}

	/**
	 * Begin archiving a queue item.
	 *
	 * @param Job                  $job    Job.
	 * @param \SHCM\Archive\Writer $writer Writer.
	 * @param ChecksumLedger       $ledger Ledger.
	 * @param array                $item   Queue item.
	 * @param array                $state  State (by reference).
	 * @param Budget               $budget Budget.
	 * @param array                $roots  Logical roots.
	 * @return void
	 */
	protected function startItem( Job $job, $writer, ChecksumLedger $ledger, array $item, array &$state, Budget $budget, array $roots ) {
		$root = isset( $item['root'] ) ? $item['root'] : Paths::ROOT_CONTENT;
		$base = isset( $roots[ $root ] ) ? $roots[ $root ] : Paths::resolveRoot( $root );
		if ( ! $base ) {
			$job->addWarning( sprintf( 'Unknown archive root "%s", entry skipped.', $root ) );
			return;
		}

		$absolute     = $base . '/' . $item['rel'];
		$logical_path = Format::ENTRY_FILES . $root . '/' . $item['rel'];
		$type         = isset( $item['type'] ) ? $item['type'] : 'f';

		if ( 'd' === $type ) {
			$ledger->record(
				$job,
				$writer->addDirectory(
					$logical_path,
					array(
						'mtime' => (int) $item['mtime'],
						'mode'  => (int) $item['mode'],
						'group' => $item['group'],
						'root'  => $root,
					)
				)
			);
			$state['done_files']++;
			return;
		}

		if ( 'l' === $type ) {
			$ledger->record(
				$job,
				$writer->addSymlink(
					$logical_path,
					isset( $item['target'] ) ? $item['target'] : '',
					array(
						'mtime' => (int) $item['mtime'],
						'mode'  => (int) $item['mode'],
						'group' => $item['group'],
						'root'  => $root,
					)
				)
			);
			$state['done_files']++;
			return;
		}

		if ( ! is_readable( $absolute ) ) {
			$job->addWarning( sprintf( 'File disappeared or became unreadable during the export: %s', $item['rel'] ) );
			return;
		}

		$writer->beginEntry(
			$logical_path,
			array(
				'type'  => Format::TYPE_FILE,
				'mtime' => (int) $item['mtime'],
				'mode'  => (int) $item['mode'],
				'group' => $item['group'],
				'root'  => $root,
			)
		);

		$state['current'] = array(
			'root'     => $root,
			'rel'      => $item['rel'],
			'absolute' => $absolute,
			'offset'   => 0,
			'size'     => (int) $item['size'],
			'group'    => $item['group'],
			'path'     => $logical_path,
		);

		$this->continueFile( $job, $writer, $ledger, $state, $budget, $roots );
	}

	/**
	 * Copy (more of) the current file into the archive.
	 *
	 * @param Job                  $job    Job.
	 * @param \SHCM\Archive\Writer $writer Writer.
	 * @param ChecksumLedger       $ledger Ledger.
	 * @param array                $state  State (by reference).
	 * @param Budget               $budget Budget.
	 * @param array                $roots  Logical roots.
	 * @return void
	 */
	protected function continueFile( Job $job, $writer, ChecksumLedger $ledger, array &$state, Budget $budget, array $roots ) {
		$current = $state['current'];
		if ( empty( $current ) ) {
			return;
		}
		if ( $writer->openEntryPath() !== $current['path'] ) {
			throw new \RuntimeException(
				sprintf( 'Archive state lost track of %s.', $current['rel'] )
			);
		}

		$handle = @fopen( $current['absolute'], 'rb' );
		if ( ! $handle ) {
			$job->addWarning( sprintf( 'File became unreadable during the export: %s', $current['rel'] ) );
			$summary = $writer->finishEntry();
			$ledger->record( $job, $summary );
			$state['current'] = null;
			return;
		}

		if ( $current['offset'] > 0 ) {
			fseek( $handle, (int) $current['offset'] );
		}

		// Copy in slices so the budget is honoured even for a 10 GB file.
		$slice = 8 * 1024 * 1024;
		while ( ! feof( $handle ) ) {
			$read = $writer->appendFromHandle( $handle, $slice );
			$current['offset']    += $read;
			$state['done_bytes']  += $read;
			$this->trackGroup( $state, $current['group'], 0, $read );

			if ( 0 === $read ) {
				break;
			}
			if ( $budget->expired() ) {
				break;
			}
		}

		$complete = feof( $handle );
		fclose( $handle );

		if ( ! $complete ) {
			$state['current'] = $current;
			return;
		}

		$summary = $writer->finishEntry();
		$ledger->record( $job, $summary );
		$state['done_files']++;
		$this->trackGroup( $state, $current['group'], 1, 0 );
		$state['current'] = null;
	}

	/**
	 * Track per-group progress for the UI.
	 *
	 * @param array  $state State (by reference).
	 * @param string $group Group.
	 * @param int    $files Files to add.
	 * @param int    $bytes Bytes to add.
	 * @return void
	 */
	protected function trackGroup( array &$state, $group, $files, $bytes ) {
		if ( '' === $group ) {
			$group = 'other';
		}
		if ( ! isset( $state['groups'][ $group ] ) ) {
			$state['groups'][ $group ] = array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		$state['groups'][ $group ]['files'] += $files;
		$state['groups'][ $group ]['bytes'] += $bytes;
	}

	/**
	 * Queue path.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function queuePath( Job $job ) {
		return Paths::trailingslash( $this->storage->tmp() ) . $job->id() . '-files.ndjson';
	}

	/**
	 * Ledger path.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function ledgerPath( Job $job ) {
		return Paths::trailingslash( $this->storage->tmp() ) . $job->id() . '-checksums.ndjson';
	}
}
