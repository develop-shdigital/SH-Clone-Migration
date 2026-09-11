<?php
/**
 * Import: preparation.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Filesystem\Paths;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that the destination can accept a restore and switches maintenance
 * mode on before anything destructive happens.
 */
class InitializeStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'initialize';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Preparing the restore', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 2;
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

		$path = (string) $job->param( 'archive_path' );
		if ( '' === $path || ! is_file( $path ) ) {
			throw new \RuntimeException( __( 'The migration archive could not be found.', 'sh-clone-migration' ) );
		}
		if ( ! Paths::isInside( Paths::normalize( $path ), $this->storage->base() ) ) {
			throw new \RuntimeException( __( 'Migration archives must live inside the plugin storage directory.', 'sh-clone-migration' ) );
		}
		if ( ! $job->param( 'confirmed' ) ) {
			throw new \RuntimeException( __( 'The restore was not confirmed.', 'sh-clone-migration' ) );
		}

		if ( ! $this->storage->prepare() ) {
			throw new \RuntimeException( __( 'The plugin storage directory is not writable.', 'sh-clone-migration' ) );
		}
		if ( ! is_writable( Paths::contentDir() ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: directory */
					__( '%s is not writable, so files cannot be restored.', 'sh-clone-migration' ),
					Paths::contentDir()
				)
			);
		}

		$job->setShared( 'destination', $this->destination( $job ) );
		$job->setShared( 'destination_prefix', $GLOBALS['wpdb']->prefix );
		$job->setShared(
			'destination_before',
			array(
				'home'       => get_option( 'home' ),
				'siteurl'    => get_option( 'siteurl' ),
				'stylesheet' => get_option( 'stylesheet' ),
				'template'   => get_option( 'template' ),
				'plugins'    => (array) get_option( 'active_plugins', array() ),
			)
		);

		if ( $this->settings->getBool( 'maintenance_mode', true ) ) {
			if ( MaintenanceMode::enable() ) {
				$job->setShared( 'maintenance', true );
				$this->logger->info( 'Maintenance mode enabled.' );
			} else {
				$job->addWarning( __( 'Maintenance mode could not be enabled: the WordPress root directory is not writable. The restore continues.', 'sh-clone-migration' ) );
			}
		}

		$this->logger->info(
			sprintf(
				'Restore starting. Archive: %1$s (%2$s). Destination: %3$s',
				basename( $path ),
				Bytes::format( (int) filesize( $path ) ),
				$job->shared( 'destination' )
			)
		);

		return $this->complete( __( 'Destination prepared', 'sh-clone-migration' ) );
	}

	/**
	 * The destination URL for this restore.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function destination( Job $job ) {
		$explicit = trim( (string) $job->param( 'destination_url', '' ) );
		if ( '' !== $explicit ) {
			return untrailingslashit( $explicit );
		}
		return untrailingslashit( home_url() );
	}

	/**
	 * Make sure maintenance mode never survives a failure here.
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
