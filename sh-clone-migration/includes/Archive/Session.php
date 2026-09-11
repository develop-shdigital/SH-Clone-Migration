<?php
/**
 * Archive writer session handling.
 *
 * @package SHCM
 */

namespace SHCM\Archive;

use SHCM\Jobs\Job;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Opens, resumes and parks the archive writer that several export stages share.
 *
 * The writer state lives in the job so that the next request continues exactly
 * where the previous one stopped, and the migration password is taken from the
 * request rather than from disk.
 */
class Session {

	/**
	 * Open (or resume) the writer for a job.
	 *
	 * @param Job   $job     Job.
	 * @param array $options block_size, compress, level, prologue.
	 * @return Writer
	 * @throws \RuntimeException When an encrypted archive is resumed without a password.
	 */
	public static function open( Job $job, array $options = array() ) {
		$state = $job->shared( 'archive' );

		if ( is_array( $state ) && ! empty( $state['path'] ) ) {
			if ( ! empty( $state['encrypted'] ) && '' === $job->password() ) {
				throw new \RuntimeException(
					'This migration is encrypted. Supply the migration password to continue it.'
				);
			}
			return Writer::resume( $state, $job->password() );
		}

		$password = $job->isEncrypted() ? $job->password() : '';
		if ( $job->isEncrypted() && '' === $password ) {
			throw new \RuntimeException( 'A migration password is required to create an encrypted archive.' );
		}

		$options['password'] = $password;
		$writer              = Writer::create( $job->param( 'archive_path' ), $options );
		$job->setShared( 'archive', $writer->pause() );

		return $writer;
	}

	/**
	 * Park the writer: flush, remember the state, close the handle.
	 *
	 * @param Job    $job    Job.
	 * @param Writer $writer Writer.
	 * @return void
	 */
	public static function park( Job $job, Writer $writer ) {
		$job->setShared( 'archive', $writer->pause() );
		$writer->release();
	}

	/**
	 * Finalise the archive and remember that it is closed.
	 *
	 * @param Job    $job    Job.
	 * @param Writer $writer Writer.
	 * @param array  $footer Footer values.
	 * @return array Footer written.
	 */
	public static function close( Job $job, Writer $writer, array $footer = array() ) {
		$written = $writer->close( $footer );
		$job->setShared( 'archive_closed', true );
		$job->setShared( 'archive', null );
		$writer->release();
		return $written;
	}
}
