<?php
/**
 * Export: finalisation.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Archive\Format;
use SHCM\Archive\Session;
use SHCM\Export\ChecksumLedger;
use SHCM\Filesystem\Paths;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the checksum entry and closes the archive.
 */
class FinalizeStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'finalize';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Finalising the archive', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 3;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		unset( $budget );

		if ( $job->shared( 'archive_closed' ) ) {
			return $this->complete( __( 'Archive finalised', 'sh-clone-migration' ) );
		}

		$writer = Session::open( $job );
		$ledger = new ChecksumLedger( Paths::trailingslash( $this->storage->tmp() ) . $job->id() . '-checksums.ndjson' );

		$summary = $ledger->writeTo( $writer, $job );
		$ledger->record( $job, $summary );

		// The footer is never encrypted, so it is what the Backups screen and
		// the import screen can show before the password is known. It records
		// what was actually written, not what the scan planned.
		$manifest = (array) $job->shared( 'manifest', array() );
		$database = (array) $job->shared( 'database_totals', array() );
		$files    = (array) $job->shared( 'files_exported', array() );
		$scanned  = (array) $job->shared( 'file_totals', array() );
		// Files the scan passed over (unreadable, over the size limit) never
		// reached the copy, so they are counted there and added here.
		$skipped_copy = isset( $files['skipped'] ) ? (int) $files['skipped'] : 0;
		$skipped_scan = isset( $scanned['skipped'] ) ? (int) $scanned['skipped'] : 0;
		$footer   = Session::close(
			$job,
			$writer,
			array(
				'manifest_entry'  => 'manifest.json',
				'checksum_entry'  => 'checksums/checksums.json',
				'checksum_digest' => $job->shared( 'checksum_digest', '' ),
				'checksum_scheme' => Format::LEDGER_SCHEME,
				'source'          => isset( $manifest['site']['home'] ) ? $manifest['site']['home'] : '',
				'files'           => isset( $files['entries'] ) ? (int) $files['entries'] : ( isset( $manifest['files']['count'] ) ? $manifest['files']['count'] : 0 ),
				'files_skipped'   => $skipped_copy + $skipped_scan,
				'skipped'         => array(
					'scan' => $skipped_scan,
					'copy' => $skipped_copy,
				),
				'tables'          => isset( $database['tables'] ) ? (int) $database['tables'] : 0,
				'database'        => array(
					'included'  => ! empty( $database['included'] ),
					'prefix'    => isset( $database['prefix'] ) ? (string) $database['prefix'] : '',
					'tables'    => isset( $database['tables'] ) ? (int) $database['tables'] : 0,
					'rows'      => isset( $database['rows'] ) ? (int) $database['rows'] : 0,
					'sql_bytes' => isset( $database['sql_bytes'] ) ? (int) $database['sql_bytes'] : 0,
				),
				'groups'          => (array) $job->shared( 'entry_groups', array() ),
				'warnings'        => (int) $job->get( 'warnings_total', 0 ),
				'generator'       => 'SH Clone Migration ' . ( defined( 'SHCM_VERSION' ) ? SHCM_VERSION : '' ),
			)
		);

		$path = $job->param( 'archive_path' );
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;
		$job->setShared( 'archive_size', $size );
		$job->setShared( 'footer', $footer );

		$this->logger->info(
			sprintf(
				'Archive finalised: %1$s (%2$s, %3$d entries).',
				basename( $path ),
				Bytes::format( $size ),
				(int) $job->shared( 'checksum_entries', 0 )
			)
		);

		$this->cleanupWorkFiles( $job );

		return $this->complete(
			sprintf(
				/* translators: %s: archive size */
				__( 'Archive written (%s)', 'sh-clone-migration' ),
				Bytes::format( $size )
			)
		);
	}

	/**
	 * Remove the queues and the ledger once they are inside the archive.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	protected function cleanupWorkFiles( Job $job ) {
		$base = Paths::trailingslash( $this->storage->tmp() ) . $job->id();
		foreach ( array( '-files.ndjson', '-dirs.ndjson', '-checksums.ndjson' ) as $suffix ) {
			if ( is_file( $base . $suffix ) ) {
				@unlink( $base . $suffix );
			}
		}
	}
}
