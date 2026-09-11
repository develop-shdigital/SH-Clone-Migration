<?php
/**
 * Import: archive validation.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Archive\Reader;
use SHCM\Archive\Verifier;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses to restore anything until the archive proves it is intact.
 */
class ValidateStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'validate';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Validating the archive', 'sh-clone-migration' );
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
		$reader   = new Reader( $job->param( 'archive_path' ), $job->password() );
		$verifier = new Verifier( $reader );
		$state    = $job->stageState( $this->key(), array() );

		if ( empty( $state ) ) {
			$structure = $verifier->structure();
			if ( ! $structure['ok'] ) {
				throw new \RuntimeException(
					__( 'Migration archive validation failed. The archive appears to be incomplete or corrupted.', 'sh-clone-migration' )
					. ' ' . implode( ' ', $structure['errors'] )
				);
			}

			$manifest = $structure['manifest'];
			$job->setShared( 'manifest', $manifest );
			if ( isset( $manifest['files'] ) && is_array( $manifest['files'] ) ) {
				// The same shape the export scan produces, so the progress
				// view can draw per-group bars for a restore too.
				$job->setShared(
					'file_totals',
					array(
						'files'  => isset( $manifest['files']['count'] ) ? (int) $manifest['files']['count'] : 0,
						'bytes'  => isset( $manifest['files']['size'] ) ? (int) $manifest['files']['size'] : 0,
						'groups' => isset( $manifest['files']['groups'] ) ? (array) $manifest['files']['groups'] : array(),
					)
				);
			}
			$job->setShared( 'source', isset( $manifest['site']['home'] ) ? untrailingslashit( $manifest['site']['home'] ) : '' );
			$job->setShared( 'source_prefix', isset( $manifest['wordpress']['table_prefix'] ) ? $manifest['wordpress']['table_prefix'] : '' );

			$this->logger->info(
				sprintf(
					'Archive manifest: source %1$s, WordPress %2$s, PHP %3$s, %4$d tables, %5$s files.',
					$job->shared( 'source' ),
					isset( $manifest['wordpress']['version'] ) ? $manifest['wordpress']['version'] : '?',
					isset( $manifest['php']['version'] ) ? $manifest['php']['version'] : '?',
					isset( $manifest['database']['tables'] ) ? $manifest['database']['tables'] : 0,
					isset( $manifest['files']['count'] ) ? $manifest['files']['count'] : 0
				)
			);

			$this->checkCompatibility( $job, $manifest );

			if ( ! $job->param( 'verify_archive', true ) ) {
				return $this->complete( __( 'Archive structure verified', 'sh-clone-migration' ) );
			}

			$state             = $verifier->initialState();
			$state['expected'] = $verifier->expectedEntries();
		}

		$state = $verifier->verifyEntries( $state, $budget );

		if ( ! empty( $state['errors'] ) ) {
			foreach ( $state['errors'] as $error ) {
				$this->logger->error( 'Validation: ' . $error );
			}
			throw new \RuntimeException(
				__( 'Migration archive validation failed. The archive appears to be incomplete or corrupted. Nothing has been changed on this site.', 'sh-clone-migration' )
				. ' ' . implode( ' ', array_slice( $state['errors'], 0, 5 ) )
			);
		}

		$job->setStageState( $this->key(), $state );

		if ( empty( $state['done'] ) ) {
			$expected = max( 1, (int) $state['expected'] );
			return $this->progress(
				sprintf(
					/* translators: 1: entries checked, 2: total */
					__( 'Verifying archive: %1$s of %2$s entries', 'sh-clone-migration' ),
					number_format_i18n( $state['checked'] ),
					number_format_i18n( $expected )
				),
				min( 0.99, $state['checked'] / $expected )
			);
		}

		$this->logger->info(
			sprintf( 'Archive verified: %1$d entries, %2$s.', $state['checked'], Bytes::format( $state['bytes'] ) )
		);

		return $this->complete(
			sprintf(
				/* translators: %s: number of entries */
				__( 'Archive verified (%s entries)', 'sh-clone-migration' ),
				number_format_i18n( $state['checked'] )
			)
		);
	}

	/**
	 * Warn about environment differences that matter after the restore.
	 *
	 * @param Job   $job      Job.
	 * @param array $manifest Manifest.
	 * @return void
	 */
	protected function checkCompatibility( Job $job, array $manifest ) {
		$source_php = isset( $manifest['php']['version'] ) ? $manifest['php']['version'] : '';
		if ( $source_php && version_compare( PHP_VERSION, $source_php, '<' ) ) {
			$job->addWarning(
				sprintf(
					/* translators: 1: source PHP version, 2: destination PHP version */
					__( 'The source site ran PHP %1$s and this server runs PHP %2$s. Some plugins may require the newer version.', 'sh-clone-migration' ),
					$source_php,
					PHP_VERSION
				)
			);
		}

		$source_multisite = ! empty( $manifest['wordpress']['multisite'] );

		if ( $source_multisite && ! is_multisite() ) {
			throw new \RuntimeException(
				__( 'This archive was created from a WordPress multisite network. Restoring it onto a single site installation is not supported, because the network tables and per-site content cannot be represented safely.', 'sh-clone-migration' )
			);
		}
		if ( ! $source_multisite && is_multisite() ) {
			throw new \RuntimeException(
				__( 'This archive was created from a single site installation and this destination is a multisite network. Restoring it here would break the network tables.', 'sh-clone-migration' )
			);
		}

		$config = isset( $manifest['config'] ) ? $manifest['config'] : array();
		unset( $config );
	}

	/**
	 * Nothing destructive happened yet, but maintenance mode must not linger.
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
