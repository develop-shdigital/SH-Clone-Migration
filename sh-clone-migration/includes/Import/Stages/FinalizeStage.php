<?php
/**
 * Import: finalisation.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Filesystem\Paths;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Turns maintenance mode off, tidies up and records the summary.
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
		return __( 'Finishing up', 'sh-clone-migration' );
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

		MaintenanceMode::disable();
		$job->setShared( 'maintenance', false );

		if ( $job->param( 'delete_archive_after_import' ) ) {
			$path = (string) $job->param( 'archive_path' );
			if ( is_file( $path ) && Paths::isInside( Paths::normalize( $path ), $this->storage->base() ) ) {
				@unlink( $path );
				$this->logger->info( 'Imported archive deleted as requested.' );
			}
		}

		$rollback = (string) $job->shared( 'rollback_path', '' );
		if ( '' !== $rollback && is_file( $rollback ) ) {
			$this->logger->info( sprintf( 'Rollback point kept at %s', basename( $rollback ) ) );
		}

		$this->rememberSession( $job );

		$summary = array(
			'source'      => $job->shared( 'source' ),
			'destination' => $job->shared( 'destination' ),
			'files'       => $job->shared( 'files_restored', 0 ),
			'urls'        => $job->shared( 'url_report', array() ),
			'checks'      => $job->shared( 'verification', array() ),
			'rollback'    => $rollback,
		);
		$job->setShared( 'summary', $summary );

		$this->logger->info( 'Migration completed.' );

		return $this->complete( __( 'Migration completed', 'sh-clone-migration' ) );
	}

	/**
	 * Keep the administrator signed in when the restored database contains a
	 * user with the same login.
	 *
	 * The authentication cookie is signed with this installation's salts, which
	 * the restore never touches, but the user row it points at has just been
	 * replaced. Re-issuing the cookie for the matching account avoids locking
	 * the operator out of the site they just migrated.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	protected function rememberSession( Job $job ) {
		$login = (string) $job->param( 'operator_login', '' );
		if ( '' === $login || ! function_exists( 'wp_set_auth_cookie' ) ) {
			return;
		}

		global $wpdb;
		$users = $wpdb->prefix . 'users';
		$id    = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM `{$users}` WHERE user_login = %s", $login ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( ! $id ) {
			$job->addWarning(
				sprintf(
					/* translators: %s: user login */
					__( 'The account "%s" does not exist in the restored database. Sign in with the credentials from the source site.', 'sh-clone-migration' ),
					$login
				)
			);
			$job->setShared( 'session_restored', false );
			return;
		}

		if ( ! headers_sent() ) {
			wp_set_auth_cookie( (int) $id, false );
		}
		$job->setShared( 'session_restored', true );
		$job->setShared( 'session_user', (int) $id );
	}

	/**
	 * Maintenance mode must be off whatever happens here.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		unset( $error );
		MaintenanceMode::disable();
		$job->setShared( 'maintenance', false );
	}
}
