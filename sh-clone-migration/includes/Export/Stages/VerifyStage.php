<?php
/**
 * Export: archive verification.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Archive\Reader;
use SHCM\Archive\Verifier;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the finished archive back and verifies every checksum before the
 * download is offered.
 */
class VerifyStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'verify';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Verifying the archive', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 18;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		$path = $job->param( 'archive_path' );
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( __( 'The archive file is missing.', 'sh-clone-migration' ) );
		}

		$reader   = new Reader( $path, $job->password() );
		$verifier = new Verifier( $reader );

		$state = $job->stageState( $this->key(), array() );
		if ( empty( $state ) ) {
			$structure = $verifier->structure();
			if ( ! $structure['ok'] ) {
				throw new \RuntimeException(
					__( 'Migration archive validation failed. The archive appears to be incomplete or corrupted.', 'sh-clone-migration' )
					. ' ' . implode( ' ', $structure['errors'] )
				);
			}
			$state = $verifier->initialState();
			$state['expected'] = $verifier->expectedEntries();

			if ( 'quick' === $this->settings->get( 'verify_mode', 'full' ) ) {
				$this->logger->info( 'Quick verification: structure and manifest are valid.' );
				return $this->complete( __( 'Archive verified (quick check)', 'sh-clone-migration' ) );
			}
		}

		$state = $verifier->verifyEntries( $state, $budget );

		if ( ! empty( $state['errors'] ) ) {
			foreach ( $state['errors'] as $error ) {
				$this->logger->error( 'Verification: ' . $error );
			}
			throw new \RuntimeException(
				__( 'Migration archive validation failed. The archive appears to be incomplete or corrupted.', 'sh-clone-migration' )
				. ' ' . implode( ' ', array_slice( $state['errors'], 0, 5 ) )
			);
		}

		$job->setStageState( $this->key(), $state );

		if ( empty( $state['done'] ) ) {
			$expected = max( 1, (int) $state['expected'] );
			return $this->progress(
				sprintf(
					/* translators: 1: entries checked, 2: total entries */
					__( 'Verifying: %1$s of %2$s entries', 'sh-clone-migration' ),
					number_format_i18n( $state['checked'] ),
					number_format_i18n( $expected )
				),
				min( 0.99, $state['checked'] / $expected )
			);
		}

		$this->logger->info(
			sprintf( 'Archive verified: %1$d entries, %2$s of content.', $state['checked'], Bytes::format( $state['bytes'] ) )
		);

		$job->setShared( 'verified', true );

		return $this->complete(
			sprintf(
				/* translators: %s: number of entries */
				__( 'Archive verified (%s entries)', 'sh-clone-migration' ),
				number_format_i18n( $state['checked'] )
			)
		);
	}
}
