<?php
/**
 * Export: archive verification.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Archive\Catalog;
use SHCM\Archive\FileDigest;
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

		$state = $job->stageState( $this->key(), array() );

		if ( empty( $state['entries_done'] ) ) {
			$reader   = new Reader( $path, $job->password() );
			$verifier = new Verifier( $reader );

			if ( empty( $state ) ) {
				$structure = $verifier->structure();
				if ( ! $structure['ok'] ) {
					throw new \RuntimeException(
						__( 'Migration archive validation failed. The archive appears to be incomplete or corrupted.', 'sh-clone-migration' )
						. ' ' . implode( ' ', $structure['errors'] )
					);
				}
				$state             = $verifier->initialState();
				$state['expected'] = $verifier->expectedEntries();

				if ( 'quick' === $this->settings->get( 'verify_mode', 'full' ) ) {
					$this->logger->info( 'Quick verification: structure and manifest are valid; entry checksums were not read back.' );
					$job->setShared( 'verify_mode', 'quick' );
					$job->setShared( 'verified', false );
					$state['entries_done'] = true;
					$job->setStageState( $this->key(), $state );
					return $this->progress( __( 'Archive structure verified (quick check)', 'sh-clone-migration' ), 0.5 );
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

			if ( empty( $state['done'] ) ) {
				$job->setStageState( $this->key(), $state );
				$expected = max( 1, (int) $state['expected'] );
				return $this->progress(
					sprintf(
						/* translators: 1: entries checked, 2: total entries */
						__( 'Verifying: %1$s of %2$s entries', 'sh-clone-migration' ),
						number_format_i18n( $state['checked'] ),
						number_format_i18n( $expected )
					),
					min( 0.8, 0.8 * $state['checked'] / $expected )
				);
			}

			$this->logger->info(
				sprintf( 'Archive verified: every checksum of %1$d entries matches (%2$s of uncompressed content).', $state['checked'], Bytes::format( $state['bytes'] ) )
			);
			$job->setShared( 'verify_mode', 'full' );
			$job->setShared( 'verified', true );
			$job->setShared( 'verified_entries', (int) $state['checked'] );
			$state['entries_done'] = true;
		}

		// A SHA-256 of the whole file, so a downloaded copy can be checked
		// with sha256sum or Get-FileHash before it is uploaded anywhere.
		if ( empty( $state['sha256_done'] ) ) {
			$digest = FileDigest::advance( $path, isset( $state['sha256'] ) ? (array) $state['sha256'] : array(), $budget );
			$state['sha256'] = $digest;

			if ( ! empty( $digest['unavailable'] ) ) {
				$job->addWarning( __( 'The SHA-256 checksum of the archive could not be computed on this server within the time limit. The archive itself is verified.', 'sh-clone-migration' ) );
				$state['sha256_done'] = true;
			} elseif ( empty( $digest['digest'] ) ) {
				$job->setStageState( $this->key(), $state );
				$size = max( 1, (int) $digest['size'] );
				return $this->progress(
					sprintf(
						/* translators: 1: bytes hashed, 2: archive size */
						__( 'Computing the SHA-256 checksum: %1$s of %2$s', 'sh-clone-migration' ),
						Bytes::format( (int) $digest['offset'] ),
						Bytes::format( $size )
					),
					0.8 + 0.19 * ( (int) $digest['offset'] / $size )
				);
			} else {
				$job->setShared( 'archive_sha256', $digest['digest'] );
				if ( ! Catalog::writeChecksum( $path, $digest['digest'] ) ) {
					$this->logger->warning( 'The SHA-256 checksum file could not be written next to the archive.' );
				}
				$this->logger->info( sprintf( 'SHA-256 of %1$s (%2$s bytes): %3$s', basename( $path ), number_format( (int) $digest['size'] ), $digest['digest'] ) );
				$state['sha256_done'] = true;
			}
		}

		$job->setStageState( $this->key(), $state );

		if ( 'quick' === $job->shared( 'verify_mode' ) ) {
			return $this->complete( __( 'Archive verified (quick check)', 'sh-clone-migration' ) );
		}
		return $this->complete(
			sprintf(
				/* translators: %s: number of entries */
				__( 'Archive verified (%s entries)', 'sh-clone-migration' ),
				number_format_i18n( (int) $job->shared( 'verified_entries', 0 ) )
			)
		);
	}
}
