<?php
/**
 * Admin action controller.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Archive\Catalog;
use SHCM\Archive\Reader;
use SHCM\Archive\Verifier;
use SHCM\Core\Plugin;
use SHCM\Core\Settings;
use SHCM\Filesystem\Paths;
use SHCM\Import\Uploader;
use SHCM\Jobs\Job;
use SHCM\Security\JobToken;
use SHCM\Security\Request;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * The logic behind every admin action.
 *
 * Kept transport agnostic so that admin-ajax, the REST API, the standalone
 * maintenance endpoint and WP-CLI all share exactly the same behaviour.
 */
class Controller {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Start an export job.
	 *
	 * @param array $input Request input.
	 * @return array
	 */
	public function startExport( array $input ) {
		$settings = $this->plugin->settings();
		$password = isset( $input['password'] ) ? (string) $input['password'] : '';

		$params = array(
			'name'                   => isset( $input['name'] ) ? sanitize_file_name( $input['name'] ) : '',
			'include_core'           => ! empty( $input['include_core'] ),
			'include_database'       => ! isset( $input['include_database'] ) || ! empty( $input['include_database'] ),
			'include_foreign_tables' => ! empty( $input['include_foreign_tables'] ),
			'exclude_tables'         => isset( $input['exclude_tables'] ) ? (array) $input['exclude_tables'] : array(),
			'exclusions'             => isset( $input['exclusions'] ) ? (array) $input['exclusions'] : array(),
			'encrypted'              => '' !== $password,
		);

		if ( '' !== $password && ! \SHCM\Crypto\Cipher::isAvailable() ) {
			throw new \RuntimeException( __( 'Archive encryption needs libsodium or OpenSSL, and neither is available on this server.', 'sh-clone-migration' ) );
		}

		$stages = $this->plugin->registry()->stagesFor( Job::TYPE_EXPORT, $params );
		$job    = Job::create( Job::TYPE_EXPORT, $params, $stages );
		$token  = JobToken::issue( $job );
		$job->setRuntime( 'password', $password );
		$this->plugin->jobs()->save( $job );

		$this->plugin->logger()->channel( $job->id() )->info(
			sprintf( 'Export requested by user %d.', get_current_user_id() )
		);

		$snapshot          = $this->tick( $job->id(), $password );
		$snapshot['token'] = $token;

		return $snapshot;
	}

	/**
	 * Start an import job.
	 *
	 * @param array $input Request input.
	 * @return array
	 * @throws \RuntimeException When the archive is unusable.
	 */
	public function startImport( array $input ) {
		$catalog = new Catalog( $this->plugin->storage() );
		$name    = isset( $input['archive'] ) ? (string) $input['archive'] : '';
		$path    = $catalog->resolve( $name );

		if ( null === $path ) {
			throw new \RuntimeException( __( 'The selected migration archive could not be found.', 'sh-clone-migration' ) );
		}
		if ( empty( $input['confirmed'] ) ) {
			throw new \RuntimeException( __( 'Confirm that the destination site may be replaced before starting the restore.', 'sh-clone-migration' ) );
		}

		$password = isset( $input['password'] ) ? (string) $input['password'] : '';
		$prologue = Reader::peek( $path );
		if ( ! empty( $prologue['encrypted'] ) && '' === $password ) {
			throw new \RuntimeException( __( 'This archive is encrypted. Enter its migration password to restore it.', 'sh-clone-migration' ) );
		}

		$settings = $this->plugin->settings();
		$current  = wp_get_current_user();

		$params = array(
			'archive_path'                => $path,
			'archive_name'                => basename( $path ),
			'confirmed'                   => true,
			'encrypted'                   => ! empty( $prologue['encrypted'] ),
			'destination_url'             => isset( $input['destination_url'] ) ? esc_url_raw( $input['destination_url'] ) : '',
			'import_mode'                 => isset( $input['import_mode'] ) && 'merge' === $input['import_mode'] ? 'merge' : 'replace',
			'include_database'            => ! isset( $input['include_database'] ) || ! empty( $input['include_database'] ),
			'include_files'               => ! isset( $input['include_files'] ) || ! empty( $input['include_files'] ),
			'replace_urls'                => ! isset( $input['replace_urls'] ) ? $settings->getBool( 'replace_urls', true ) : ! empty( $input['replace_urls'] ),
			'replace_bare_domain'         => ! empty( $input['replace_bare_domain'] ),
			'create_rollback_point'       => ! isset( $input['create_rollback_point'] )
				? $settings->getBool( 'create_rollback_point', true )
				: ! empty( $input['create_rollback_point'] ),
			'verify_archive'              => ! isset( $input['verify_archive'] ) || ! empty( $input['verify_archive'] ),
			'skip_core'                   => ! empty( $input['skip_core'] ),
			'delete_archive_after_import' => ! empty( $input['delete_archive_after_import'] ),
			'operator_login'              => $current ? $current->user_login : '',
		);

		$stages = $this->plugin->registry()->stagesFor( Job::TYPE_IMPORT, $params );
		$job    = Job::create( Job::TYPE_IMPORT, $params, $stages );
		$token  = JobToken::issue( $job );
		$job->setRuntime( 'password', $password );
		$this->plugin->jobs()->save( $job );

		$this->plugin->logger()->channel( $job->id() )->warning(
			sprintf(
				'Restore requested by user %1$d from archive %2$s.',
				get_current_user_id(),
				basename( $path )
			)
		);

		// The restore replaces the users table, so the operator's session will
		// stop being valid part way through. The token lets the browser tab
		// finish the job it legitimately started.
		$snapshot          = $this->tick( $job->id(), $password );
		$snapshot['token'] = $token;

		return $snapshot;
	}

	/**
	 * Restore the database snapshot a failed import left behind.
	 *
	 * The rollback point is a complete, self describing archive of the
	 * destination's database as it was before the restore, so undoing a
	 * restore is just an import with the files left alone.
	 *
	 * @param string $job_id The import job that created the rollback point.
	 * @return array
	 * @throws \RuntimeException When there is no rollback point to restore.
	 */
	public function startRollback( $job_id ) {
		$source = $this->plugin->jobs()->load( $job_id );
		if ( null === $source ) {
			throw new \RuntimeException( __( 'That migration job no longer exists.', 'sh-clone-migration' ) );
		}

		$path = (string) $source->shared( 'rollback_path', '' );
		if ( '' === $path || ! is_file( $path ) ) {
			throw new \RuntimeException( __( 'That migration has no rollback point. It may have been skipped, or already cleaned up.', 'sh-clone-migration' ) );
		}
		if ( ! Paths::isInside( Paths::normalize( $path ), $this->plugin->storage()->rollback() ) ) {
			throw new \RuntimeException( __( 'The rollback point is not in the expected location.', 'sh-clone-migration' ) );
		}

		$current = wp_get_current_user();
		$before  = (array) $source->shared( 'destination_before', array() );

		$params = array(
			'archive_path'          => $path,
			'archive_name'          => basename( $path ),
			'confirmed'             => true,
			'encrypted'             => false,
			'is_rollback'           => true,
			'rollback_of'           => $source->id(),
			// The snapshot already holds this installation's own URLs, so
			// nothing has to be rewritten, and there is nothing to roll back
			// to if we snapshot the broken state again.
			'destination_url'       => isset( $before['home'] ) ? untrailingslashit( $before['home'] ) : untrailingslashit( home_url() ),
			'import_mode'           => 'replace',
			'include_database'      => true,
			'include_files'         => false,
			'replace_urls'          => false,
			'create_rollback_point' => false,
			'verify_archive'        => true,
			'operator_login'        => $current ? $current->user_login : '',
		);

		$stages = $this->plugin->registry()->stagesFor( Job::TYPE_IMPORT, $params );
		$job    = Job::create( Job::TYPE_IMPORT, $params, $stages );
		$token  = JobToken::issue( $job );
		$this->plugin->jobs()->save( $job );

		$this->plugin->logger()->channel( $job->id() )->warning(
			sprintf( 'Rollback of job %1$s requested by user %2$d.', $source->id(), get_current_user_id() )
		);

		$snapshot          = $this->tick( $job->id(), '' );
		$snapshot['token'] = $token;

		return $snapshot;
	}

	/**
	 * Start a search and replace job.
	 *
	 * @param array $input Request input.
	 * @return array
	 */
	public function startReplace( array $input ) {
		$params = array(
			'search'  => isset( $input['search'] ) ? (string) $input['search'] : '',
			'replace' => isset( $input['replace'] ) ? (string) $input['replace'] : '',
			'dry_run' => ! empty( $input['dry_run'] ),
			'tables'  => isset( $input['tables'] ) ? array_map( 'sanitize_key', (array) $input['tables'] ) : array(),
		);

		$stages = $this->plugin->registry()->stagesFor( Job::TYPE_REPLACE, $params );
		$job    = Job::create( Job::TYPE_REPLACE, $params, $stages );
		$token  = JobToken::issue( $job );
		$this->plugin->jobs()->save( $job );

		$snapshot          = $this->tick( $job->id(), '' );
		$snapshot['token'] = $token;

		return $snapshot;
	}

	/**
	 * Advance a job by one request.
	 *
	 * @param string $job_id   Job id.
	 * @param string $password Migration password.
	 * @return array
	 * @throws \RuntimeException When the job is unknown.
	 */
	public function tick( $job_id, $password = '' ) {
		$job = $this->plugin->jobs()->load( $job_id );
		if ( null === $job ) {
			throw new \RuntimeException( __( 'That migration job no longer exists.', 'sh-clone-migration' ) );
		}
		if ( $job->isFinished() ) {
			return $this->snapshot( $job );
		}

		$job->setRuntime( 'password', $password );
		$job = $this->plugin->runner()->tick( $job );

		return $this->snapshot( $job );
	}

	/**
	 * Job status without advancing it.
	 *
	 * @param string $job_id Job id.
	 * @return array
	 * @throws \RuntimeException When the job is unknown.
	 */
	public function status( $job_id ) {
		$job = $this->plugin->jobs()->load( $job_id );
		if ( null === $job ) {
			throw new \RuntimeException( __( 'That migration job no longer exists.', 'sh-clone-migration' ) );
		}
		return $this->snapshot( $job );
	}

	/**
	 * Cancel a job.
	 *
	 * @param string $job_id Job id.
	 * @return array
	 * @throws \RuntimeException When the job is unknown.
	 */
	public function cancel( $job_id ) {
		$job = $this->plugin->jobs()->load( $job_id );
		if ( null === $job ) {
			throw new \RuntimeException( __( 'That migration job no longer exists.', 'sh-clone-migration' ) );
		}
		$job = $this->plugin->runner()->cancelJob( $job );
		return $this->snapshot( $job );
	}

	/**
	 * Delete a job record.
	 *
	 * @param string $job_id Job id.
	 * @return array
	 */
	public function deleteJob( $job_id ) {
		$this->plugin->jobs()->delete( $job_id );
		return array( 'deleted' => true );
	}

	/**
	 * Recent jobs.
	 *
	 * @param string|null $type Optional type filter.
	 * @return array
	 */
	public function jobs( $type = null ) {
		$jobs = array();
		foreach ( $this->plugin->jobs()->all( $type, 25 ) as $job ) {
			$jobs[] = $this->snapshot( $job, false );
		}
		return array( 'jobs' => $jobs );
	}

	/**
	 * A job that can be resumed, if there is one.
	 *
	 * @return array
	 */
	public function resumable() {
		foreach ( $this->plugin->jobs()->all( null, 10 ) as $job ) {
			if ( $job->isRunnable() && Job::STATUS_PENDING !== $job->status() ) {
				return array( 'job' => $this->snapshot( $job ) );
			}
		}
		return array( 'job' => null );
	}

	/**
	 * Public representation of a job.
	 *
	 * @param Job  $job     Job.
	 * @param bool $details Include the report payloads.
	 * @return array
	 */
	public function snapshot( Job $job, $details = true ) {
		$data = $job->toPublicArray();
		unset( $data['params']['token_hash'] );

		$stages = array();
		foreach ( (array) $job->get( 'stages' ) as $key ) {
			$stage = $this->plugin->registry()->resolve( $job->type(), $key );
			$stages[] = array(
				'key'    => $key,
				'label'  => $stage ? $stage->label() : $key,
				'weight' => $stage ? $stage->weight() : 10,
			);
		}
		$data['stage_list'] = $stages;

		if ( $details ) {
			$data['report'] = array(
				'archive'       => $job->param( 'archive_path' ) ? basename( (string) $job->param( 'archive_path' ) ) : '',
				'archive_size'  => (int) $job->shared( 'archive_size', 0 ),
				'file_totals'   => $job->shared( 'file_totals', array() ),
				'files_progress' => $job->shared( 'files_progress', array() ),
				'tables'        => count( (array) $job->shared( 'tables', array() ) ),
				'manifest'      => $job->shared( 'manifest', null ),
				'urls'          => $job->shared( 'url_report', null ),
				'verification'  => $job->shared( 'verification', null ),
				'summary'       => $job->shared( 'summary', null ),
				'replace'       => $job->shared( 'report', null ),
				'session'       => array(
					'restored' => $job->shared( 'session_restored', null ),
				),
				'rollback'      => array(
					'available' => '' !== (string) $job->shared( 'rollback_path', '' )
						&& is_file( (string) $job->shared( 'rollback_path', '' ) ),
					'name'      => basename( (string) $job->shared( 'rollback_path', '' ) ),
				),
			);
			$data['log'] = $this->plugin->logger()->tail( $job->id(), 40 );
		}

		return $data;
	}

	/**
	 * List stored archives.
	 *
	 * @return array
	 */
	public function archives() {
		$catalog  = new Catalog( $this->plugin->storage() );
		$archives = $catalog->all();

		return array(
			'archives'   => $archives,
			'total_size' => $catalog->totalSize(),
			'free_space' => $this->plugin->storage()->freeSpace(),
		);
	}

	/**
	 * Details of one archive.
	 *
	 * @param string $name     Archive name.
	 * @param string $password Migration password.
	 * @return array
	 * @throws \RuntimeException When the archive is unknown.
	 */
	public function archiveDetails( $name, $password = '' ) {
		$catalog = new Catalog( $this->plugin->storage() );
		$path    = $catalog->resolve( $name );
		if ( null === $path ) {
			throw new \RuntimeException( __( 'That archive could not be found.', 'sh-clone-migration' ) );
		}

		$info     = $catalog->describe( $path );
		$manifest = $catalog->manifest( $path, $password );

		return array(
			'archive'  => $info,
			'manifest' => $manifest,
		);
	}

	/**
	 * Delete an archive.
	 *
	 * @param string $name Archive name.
	 * @return array
	 * @throws \RuntimeException When the archive cannot be deleted.
	 */
	public function deleteArchive( $name ) {
		$catalog = new Catalog( $this->plugin->storage() );
		if ( ! $catalog->delete( $name ) ) {
			throw new \RuntimeException( __( 'That archive could not be deleted.', 'sh-clone-migration' ) );
		}
		$this->plugin->logger()->channel( 'plugin' )->warning(
			sprintf( 'Archive %1$s deleted by user %2$d.', $name, get_current_user_id() )
		);
		return array( 'deleted' => true );
	}

	/**
	 * Verify an archive, resuming from a client supplied position.
	 *
	 * @param string $name     Archive name.
	 * @param array  $state    Verification state.
	 * @param string $password Migration password.
	 * @return array
	 * @throws \RuntimeException When the archive is unknown.
	 */
	public function verifyArchive( $name, array $state = array(), $password = '' ) {
		$catalog = new Catalog( $this->plugin->storage() );
		$path    = $catalog->resolve( $name );
		if ( null === $path ) {
			throw new \RuntimeException( __( 'That archive could not be found.', 'sh-clone-migration' ) );
		}

		$reader   = new Reader( $path, $password );
		$verifier = new Verifier( $reader );
		$budget   = \SHCM\Jobs\Budget::create( $this->plugin->settings()->getInt( 'time_budget' ), $this->plugin->settings()->getInt( 'memory_guard', 80 ) );

		if ( empty( $state ) ) {
			$structure = $verifier->structure();
			if ( ! $structure['ok'] ) {
				return array(
					'done'   => true,
					'ok'     => false,
					'errors' => $structure['errors'],
				);
			}
			$state             = $verifier->initialState();
			$state['expected'] = $verifier->expectedEntries();
		}

		$state = $verifier->verifyEntries( $state, $budget );

		return array(
			'done'     => ! empty( $state['done'] ),
			'ok'       => empty( $state['errors'] ),
			'errors'   => $state['errors'],
			'checked'  => $state['checked'],
			'expected' => isset( $state['expected'] ) ? $state['expected'] : 0,
			'bytes'    => $state['bytes'],
			'state'    => $state,
		);
	}

	/**
	 * Save the settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function saveSettings( array $input ) {
		$values = $this->plugin->settings()->update( $input );
		return array( 'settings' => $values );
	}

	/**
	 * System status report.
	 *
	 * @return array
	 */
	public function systemStatus() {
		$report = $this->plugin->environment()->report();
		$report['storage'] = array(
			'base'       => $this->plugin->storage()->base(),
			'free_space' => $this->plugin->storage()->freeSpace(),
			'archives'   => ( new Catalog( $this->plugin->storage() ) )->totalSize(),
		);
		return $report;
	}

	/**
	 * Uploader for chunked archive uploads.
	 *
	 * @return Uploader
	 */
	public function uploader() {
		return new Uploader( $this->plugin->storage() );
	}

	/**
	 * Plugin container.
	 *
	 * @return Plugin
	 */
	public function plugin() {
		return $this->plugin;
	}
}
