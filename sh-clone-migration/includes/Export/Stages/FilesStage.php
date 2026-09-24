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
use SHCM\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Streams every queued file into the archive.
 *
 * A file larger than the remaining request budget is not a problem: the entry
 * stays open across requests and continues from the exact byte it stopped at.
 * No other entry is started while one is open.
 *
 * A file that changes, shrinks or becomes unreadable while it is being copied
 * is never archived as a torn or truncated copy (which would still pass
 * verification, because the checksum covers what was written): the partial
 * entry is discarded and the file copied again from the start, or skipped
 * with a warning when it will not hold still.
 */
class FilesStage extends AbstractStage {

	/**
	 * Times a file that keeps changing is copied again before giving up.
	 */
	const MAX_RESTARTS = 2;

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
				'skipped'      => 0,
				'groups'       => array(),
			)
		);
		$state = array_merge( array( 'skipped' => 0 ), $state );

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
			// Never begin another entry while a file is still open: the writer
			// holds one entry at a time.
			while ( empty( $state['current'] ) && empty( $state['yield'] ) && $budget->shouldContinue( $processed ) ) {
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
					'Files exported: %1$d entries, %2$s, %3$d skipped during the export.',
					$state['done_files'],
					Bytes::format( $state['done_bytes'] ),
					$state['skipped']
				)
			);
			$this->checkCompleteness( $job, $state, $totals );
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

		if ( ! empty( $state['yield'] ) ) {
			unset( $state['yield'] );
			$job->setStageState( $this->key(), $state );
			return $this->waiting(
				sprintf(
					/* translators: %s: file path */
					__( 'Copying a changed file again: %s', 'sh-clone-migration' ),
					Json::printable( isset( $state['current']['rel'] ) ? $state['current']['rel'] : '' )
				),
				min( 0.999, $progress )
			);
		}

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
	 * @throws \RuntimeException When the queue item is corrupt.
	 */
	protected function startItem( Job $job, $writer, ChecksumLedger $ledger, array $item, array &$state, Budget $budget, array $roots ) {
		if ( ! isset( $item['rel'] ) || ! is_string( $item['rel'] ) || '' === $item['rel'] ) {
			throw new \RuntimeException( 'The file queue holds an entry without a path; the export cannot continue safely.' );
		}
		$root  = isset( $item['root'] ) ? $item['root'] : Paths::ROOT_CONTENT;
		$base  = isset( $roots[ $root ] ) ? $roots[ $root ] : Paths::resolveRoot( $root );
		$group = isset( $item['group'] ) ? (string) $item['group'] : '';
		if ( ! $base ) {
			$this->skip( $job, $state, sprintf( 'Unknown archive root "%s", entry skipped.', $root ) );
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
						'group' => $group,
						'root'  => $root,
					)
				)
			);
			$state['done_files']++;
			$this->trackGroup( $state, $group, 1, 0 );
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
						'group' => $group,
						'root'  => $root,
					)
				)
			);
			$state['done_files']++;
			$this->trackGroup( $state, $group, 1, 0 );
			return;
		}

		clearstatcache( true, $absolute );
		if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			$this->skip( $job, $state, sprintf( 'File disappeared or became unreadable during the export and is not in the archive: %s', $item['rel'] ) );
			return;
		}

		$writer->beginEntry(
			$logical_path,
			array(
				'type'  => Format::TYPE_FILE,
				'mtime' => (int) $item['mtime'],
				'mode'  => (int) $item['mode'],
				'group' => $group,
				'root'  => $root,
			)
		);

		$state['current'] = array(
			'root'     => $root,
			'rel'      => $item['rel'],
			'absolute' => $absolute,
			'offset'   => 0,
			'size'     => (int) @filesize( $absolute ),
			'mtime'    => (int) @filemtime( $absolute ),
			'group'    => $group,
			// The writer's own spelling of the path, which is what
			// openEntryPath() reports on resume.
			'path'     => $writer->openEntryPath(),
			'restarts' => 0,
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
	 * @throws \RuntimeException When the writer is not on the expected entry.
	 */
	protected function continueFile( Job $job, $writer, ChecksumLedger $ledger, array &$state, Budget $budget, array $roots ) {
		$current = $state['current'];
		if ( empty( $current ) ) {
			return;
		}
		if ( $writer->openEntryPath() !== $current['path'] ) {
			throw new \RuntimeException(
				sprintf( 'Archive state lost track of %s.', Json::printable( $current['rel'] ) )
			);
		}
		if ( ! isset( $current['mtime'], $current['restarts'] ) ) {
			// State written by an earlier version: nothing to compare against.
			$current += array(
				'mtime'    => (int) @filemtime( $current['absolute'] ),
				'restarts' => 0,
			);
			$current['size']  = (int) @filesize( $current['absolute'] );
			$state['current'] = $current;
		}

		// Resuming in a later request: the file must still be the one whose
		// first part is already in the archive.
		if ( $current['offset'] > 0 && ! $this->unchanged( $current ) ) {
			$this->restart( $job, $writer, $state, 'changed or became unreadable between two requests' );
			return;
		}

		$handle = @fopen( $current['absolute'], 'rb' );
		if ( ! $handle ) {
			$this->restart( $job, $writer, $state, 'could not be opened' );
			return;
		}
		if ( $current['offset'] > 0 && 0 !== fseek( $handle, (int) $current['offset'] ) ) {
			fclose( $handle );
			$this->restart( $job, $writer, $state, 'could not be read from where the previous request stopped' );
			return;
		}

		// Copy in slices so the budget is honoured even for a 10 GB file.
		$slice   = 8 * 1024 * 1024;
		$failed  = false;
		while ( ! feof( $handle ) ) {
			$read                  = $writer->appendFromHandle( $handle, $slice );
			$current['offset']    += $read;
			$state['done_bytes']  += $read;
			$this->trackGroup( $state, $current['group'], 0, $read );

			if ( 0 === $read ) {
				// Nothing read but not at the end: an I/O error.
				$failed = ! feof( $handle );
				break;
			}
			if ( $budget->expired() ) {
				break;
			}
		}

		$complete = ! $failed && feof( $handle );
		fclose( $handle );

		if ( $failed ) {
			$state['current'] = $current;
			$this->restart( $job, $writer, $state, 'could not be read' );
			return;
		}

		if ( ! $complete ) {
			$state['current'] = $current;
			return;
		}

		if ( ! $this->unchanged( $current, true ) ) {
			if ( (int) $current['restarts'] < self::MAX_RESTARTS ) {
				$state['current'] = $current;
				$this->restart( $job, $writer, $state, 'changed while it was being copied' );
				return;
			}
			// It keeps changing (an active log): keep the last complete copy.
			$message = sprintf( 'File kept changing while it was archived; the archived copy may not match any single moment: %s', $current['rel'] );
			$job->addWarning( $message );
		}

		$summary = $writer->finishEntry();
		$ledger->record( $job, $summary );
		$state['done_files']++;
		$this->trackGroup( $state, $current['group'], 1, 0 );
		$state['current'] = null;
	}

	/**
	 * Whether the current file still has the size and mtime it had when its
	 * copy started.
	 *
	 * @param array $current Current file state.
	 * @param bool  $at_end  Also require that exactly its size was copied.
	 * @return bool
	 */
	protected function unchanged( array $current, $at_end = false ) {
		clearstatcache( true, $current['absolute'] );
		if ( ! is_file( $current['absolute'] ) || ! is_readable( $current['absolute'] ) ) {
			return false;
		}
		$size  = (int) @filesize( $current['absolute'] );
		$mtime = (int) @filemtime( $current['absolute'] );
		if ( $size !== (int) $current['size'] || $mtime !== (int) $current['mtime'] ) {
			return false;
		}
		return ! $at_end || (int) $current['offset'] === $size;
	}

	/**
	 * Discard the partial entry and copy the file again from the start, or
	 * skip it once it has failed too often.
	 *
	 * @param Job                  $job    Job.
	 * @param \SHCM\Archive\Writer $writer Writer.
	 * @param array                $state  State (by reference).
	 * @param string               $reason Why.
	 * @return void
	 */
	protected function restart( Job $job, $writer, array &$state, $reason ) {
		$current = $state['current'];
		$writer->abortEntry();

		// The discarded bytes no longer count as exported.
		$state['done_bytes'] -= (int) $current['offset'];
		$this->trackGroup( $state, $current['group'], 0, -(int) $current['offset'] );

		$restarts = (int) $current['restarts'] + 1;
		clearstatcache( true, $current['absolute'] );
		$readable = is_file( $current['absolute'] ) && is_readable( $current['absolute'] );

		if ( ! $readable || $restarts > self::MAX_RESTARTS ) {
			$state['current'] = null;
			$this->skip(
				$job,
				$state,
				sprintf( 'File %1$s (%2$s) and is not in the archive: %3$s', $reason, $readable ? 'too many attempts' : 'no longer readable', $current['rel'] )
			);
			return;
		}

		$this->logger->info( Json::printable( sprintf( 'File %1$s, copying it again from the start: %2$s', $reason, $current['rel'] ) ) );

		// The archive was just cut back, possibly below the size its saved
		// state records. End this request so that state is saved at once.
		$state['yield'] = true;

		$writer->beginEntry(
			Format::ENTRY_FILES . $current['root'] . '/' . $current['rel'],
			array(
				'type'  => Format::TYPE_FILE,
				'mtime' => (int) @filemtime( $current['absolute'] ),
				'mode'  => (int) @fileperms( $current['absolute'] ),
				'group' => $current['group'],
				'root'  => $current['root'],
			)
		);
		$state['current'] = array_merge(
			$current,
			array(
				'offset'   => 0,
				'size'     => (int) @filesize( $current['absolute'] ),
				'mtime'    => (int) @filemtime( $current['absolute'] ),
				'path'     => $writer->openEntryPath(),
				'restarts' => $restarts,
			)
		);
	}

	/**
	 * Skip an item and say so, in the job and in the log.
	 *
	 * @param Job    $job     Job.
	 * @param array  $state   State (by reference).
	 * @param string $message Message.
	 * @return void
	 */
	protected function skip( Job $job, array &$state, $message ) {
		$state['skipped']++;
		$job->addWarning( $message );
	}

	/**
	 * Compare what was archived with what the scan found.
	 *
	 * @param Job   $job    Job.
	 * @param array $state  State.
	 * @param array $totals Scan totals.
	 * @return void
	 */
	protected function checkCompleteness( Job $job, array $state, array $totals ) {
		$expected = isset( $totals['files'] ) ? (int) $totals['files'] : 0;
		$archived = (int) $state['done_files'];
		$skipped  = (int) $state['skipped'];
		if ( $archived + $skipped !== $expected ) {
			$message = sprintf(
				'The scan found %1$d entries but %2$d were archived and %3$d skipped. Check the migration log.',
				$expected,
				$archived,
				$skipped
			);
			$job->addWarning( $message );
		}
		$job->setShared(
			'files_exported',
			array(
				'entries' => $archived,
				'bytes'   => (int) $state['done_bytes'],
				'skipped' => $skipped,
			)
		);
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
