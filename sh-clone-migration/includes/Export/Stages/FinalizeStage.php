<?php
/**
 * Export: finalisation.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

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

		$manifest = (array) $job->shared( 'manifest', array() );
		$footer   = Session::close(
			$job,
			$writer,
			array(
				'manifest_entry'  => 'manifest.json',
				'checksum_entry'  => 'checksums/checksums.json',
				'checksum_digest' => $job->shared( 'checksum_digest', '' ),
				'source'          => isset( $manifest['site']['home'] ) ? $manifest['site']['home'] : '',
				'files'           => isset( $manifest['files']['count'] ) ? $manifest['files']['count'] : 0,
				'tables'          => isset( $manifest['database']['tables'] ) ? $manifest['database']['tables'] : 0,
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
