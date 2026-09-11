<?php
/**
 * AJAX endpoints.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Core\Plugin;
use SHCM\Security\Capabilities;
use SHCM\Security\JobToken;
use SHCM\Security\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the controller to admin-ajax.
 *
 * Every action authenticates, checks the migration capability and verifies a
 * nonce before it does anything at all.
 */
class Ajax {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Controller.
	 *
	 * @var Controller
	 */
	protected $controller;

	/**
	 * Actions handled here.
	 *
	 * @var string[]
	 */
	protected $actions = array(
		'start_export',
		'start_import',
		'start_replace',
		'start_rollback',
		'tick',
		'status',
		'cancel',
		'delete_job',
		'jobs',
		'resumable',
		'archives',
		'archive_details',
		'delete_archive',
		'verify_archive',
		'save_settings',
		'system_status',
		'upload_begin',
		'upload_chunk',
		'upload_status',
		'upload_finish',
		'upload_abort',
		'adopt_archive',
	);

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin     = $plugin;
		$this->controller = new Controller( $plugin );
	}

	/**
	 * Register the AJAX actions.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( $this->actions as $action ) {
			add_action( 'wp_ajax_shcm_' . $action, array( $this, 'handle' ) );
		}
		add_action( 'admin_post_shcm_download', array( $this, 'download' ) );
		add_action( 'admin_post_shcm_download_log', array( $this, 'downloadLog' ) );
	}

	/**
	 * Dispatch an AJAX action.
	 *
	 * @return void
	 */
	public function handle() {
		$action = str_replace( 'shcm_', '', Request::text( 'action' ) );
		if ( ! in_array( $action, $this->actions, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'sh-clone-migration' ) ), 400 );
		}

		$this->authorize( $action );

		try {
			$result = $this->dispatch( $action );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->error( 'AJAX ' . $action . ' failed: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message'   => $e->getMessage(),
					'action'    => $action,
					'technical' => WP_DEBUG ? sprintf( '%s:%d', $e->getFile(), $e->getLine() ) : '',
				),
				500
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Authorise the request, by WordPress session or by job token.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	public function authorize( $action ) {
		if ( $this->authorizedByToken( $action ) ) {
			return;
		}
		Request::guardAjax();
	}

	/**
	 * Whether a job token authorises this request.
	 *
	 * @param string $action Action name.
	 * @return bool
	 */
	protected function authorizedByToken( $action ) {
		if ( ! in_array( $action, JobToken::actions(), true ) ) {
			return false;
		}
		$token = Request::text( JobToken::PARAM );
		if ( '' === $token ) {
			return false;
		}
		$job = $this->plugin->jobs()->load( Request::text( 'job_id' ) );

		return JobToken::authorises( $job, $token, $action );
	}

	/**
	 * Route an action to the controller.
	 *
	 * @param string $action Action name.
	 * @return array
	 */
	public function dispatch( $action ) {
		switch ( $action ) {
			case 'start_export':
				return $this->controller->startExport(
					array(
						'name'                   => Request::text( 'name' ),
						'password'               => Request::raw( 'password' ),
						'include_core'           => Request::boolean( 'include_core' ),
						'include_database'       => Request::boolean( 'include_database', true ),
						'include_foreign_tables' => Request::boolean( 'include_foreign_tables' ),
						'exclude_tables'         => Request::stringList( 'exclude_tables' ),
						'exclusions'             => Request::stringList( 'exclusions' ),
					)
				);

			case 'start_import':
				return $this->controller->startImport(
					array(
						'archive'                     => Request::text( 'archive' ),
						'password'                    => Request::raw( 'password' ),
						'confirmed'                   => Request::boolean( 'confirmed' ),
						'destination_url'             => Request::text( 'destination_url' ),
						'import_mode'                 => Request::text( 'import_mode', 'replace' ),
						'include_database'            => Request::boolean( 'include_database', true ),
						'include_files'               => Request::boolean( 'include_files', true ),
						'replace_urls'                => Request::boolean( 'replace_urls', true ),
						'replace_bare_domain'         => Request::boolean( 'replace_bare_domain' ),
						'create_rollback_point'       => Request::boolean( 'create_rollback_point', true ),
						'verify_archive'              => Request::boolean( 'verify_archive', true ),
						'skip_core'                   => Request::boolean( 'skip_core' ),
						'delete_archive_after_import' => Request::boolean( 'delete_archive_after_import' ),
					)
				);

			case 'start_rollback':
				return $this->controller->startRollback( Request::text( 'job_id' ) );

			case 'start_replace':
				return $this->controller->startReplace(
					array(
						'search'  => Request::raw( 'search' ),
						'replace' => Request::raw( 'replace' ),
						'dry_run' => Request::boolean( 'dry_run' ),
						'tables'  => Request::stringList( 'tables' ),
					)
				);

			case 'tick':
				return $this->controller->tick( Request::text( 'job_id' ), Request::raw( 'password' ) );

			case 'status':
				return $this->controller->status( Request::text( 'job_id' ) );

			case 'cancel':
				return $this->controller->cancel( Request::text( 'job_id' ) );

			case 'delete_job':
				return $this->controller->deleteJob( Request::text( 'job_id' ) );

			case 'jobs':
				$type = Request::text( 'type' );
				return $this->controller->jobs( '' === $type ? null : $type );

			case 'resumable':
				return $this->controller->resumable();

			case 'archives':
				return $this->controller->archives();

			case 'archive_details':
				return $this->controller->archiveDetails( Request::text( 'archive' ), Request::raw( 'password' ) );

			case 'delete_archive':
				return $this->controller->deleteArchive( Request::text( 'archive' ) );

			case 'verify_archive':
				$state = isset( $_POST['state'] ) ? json_decode( wp_unslash( $_POST['state'] ), true ) : array(); // phpcs:ignore WordPress.Security
				return $this->controller->verifyArchive(
					Request::text( 'archive' ),
					is_array( $state ) ? $state : array(),
					Request::raw( 'password' )
				);

			case 'save_settings':
				$settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security
				if ( is_string( $settings ) ) {
					$settings = json_decode( $settings, true );
				}
				return $this->controller->saveSettings( is_array( $settings ) ? $settings : array() );

			case 'system_status':
				return $this->controller->systemStatus();

			case 'upload_begin':
				return $this->controller->uploader()->begin( Request::text( 'filename' ), Request::integer( 'size' ) );

			case 'upload_chunk':
				return $this->uploadChunk();

			case 'upload_status':
				return $this->controller->uploader()->status( Request::text( 'upload_id' ) );

			case 'upload_finish':
				return $this->controller->uploader()->finish( Request::text( 'upload_id' ) );

			case 'upload_abort':
				$this->controller->uploader()->abort( Request::text( 'upload_id' ) );
				return array( 'aborted' => true );

			case 'adopt_archive':
				return $this->adoptArchive();
		}

		throw new \RuntimeException( __( 'Unknown action.', 'sh-clone-migration' ) );
	}

	/**
	 * Handle one uploaded chunk.
	 *
	 * @return array
	 * @throws \RuntimeException When the chunk is missing or failed to upload.
	 */
	protected function uploadChunk() {
		if ( empty( $_FILES['chunk'] ) || ! isset( $_FILES['chunk']['tmp_name'] ) ) {
			throw new \RuntimeException( __( 'No chunk was received. The server may have rejected the request size.', 'sh-clone-migration' ) );
		}

		$error = isset( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : UPLOAD_ERR_OK;
		if ( UPLOAD_ERR_OK !== $error ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: PHP upload error code */
					__( 'The chunk upload failed with PHP error %d. Try a smaller chunk size in the settings.', 'sh-clone-migration' ),
					$error
				)
			);
		}

		$tmp = sanitize_text_field( $_FILES['chunk']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_uploaded_file( $tmp ) ) {
			throw new \RuntimeException( __( 'The uploaded chunk was rejected.', 'sh-clone-migration' ) );
		}

		return $this->controller->uploader()->appendChunk(
			Request::text( 'upload_id' ),
			Request::integer( 'offset' ),
			$tmp
		);
	}

	/**
	 * Adopt an archive that was placed on the server manually.
	 *
	 * @return array
	 * @throws \RuntimeException When the path is not acceptable.
	 */
	protected function adoptArchive() {
		$path = Request::text( 'path' );
		$path = \SHCM\Filesystem\Paths::normalize( $path );

		$allowed = array(
			$this->plugin->storage()->archives(),
			$this->plugin->storage()->incoming(),
			\SHCM\Filesystem\Paths::uploadsDir(),
		);
		$inside  = false;
		foreach ( $allowed as $directory ) {
			if ( \SHCM\Filesystem\Paths::isInside( $path, $directory ) ) {
				$inside = true;
				break;
			}
		}
		if ( ! $inside ) {
			throw new \RuntimeException(
				__( 'Server side archives must be placed in the plugin storage directory or in the uploads directory.', 'sh-clone-migration' )
			);
		}

		return $this->controller->uploader()->adopt( $path );
	}

	/**
	 * Stream an archive to the browser, with range support so that a large
	 * download can be resumed.
	 *
	 * @return void
	 */
	public function download() {
		if ( ! Capabilities::currentUserCan() ) {
			wp_die( esc_html__( 'You do not have permission to download migration archives.', 'sh-clone-migration' ), 403 );
		}
		check_admin_referer( 'shcm_download' );

		$catalog = new \SHCM\Archive\Catalog( $this->plugin->storage() );
		$path    = $catalog->resolve( Request::text( 'archive' ) );
		if ( null === $path ) {
			wp_die( esc_html__( 'That archive could not be found.', 'sh-clone-migration' ), 404 );
		}

		$this->plugin->logger()->channel( 'plugin' )->info(
			sprintf( 'Archive %1$s downloaded by user %2$d.', basename( $path ), get_current_user_id() )
		);

		$this->streamFile( $path, 'application/octet-stream' );
	}

	/**
	 * Download a migration log.
	 *
	 * @return void
	 */
	public function downloadLog() {
		if ( ! Capabilities::currentUserCan() ) {
			wp_die( esc_html__( 'You do not have permission to download migration logs.', 'sh-clone-migration' ), 403 );
		}
		check_admin_referer( 'shcm_download_log' );

		$job_id = preg_replace( '/[^A-Za-z0-9\-]/', '', Request::text( 'job_id' ) );
		$path   = $this->plugin->logger()->path( $job_id );
		if ( '' === $job_id || ! is_file( $path ) ) {
			wp_die( esc_html__( 'That migration log could not be found.', 'sh-clone-migration' ), 404 );
		}

		$this->streamFile( $path, 'text/plain; charset=utf-8', 'shcm-' . $job_id . '.log' );
	}

	/**
	 * Send a file with range support.
	 *
	 * @param string $path         Absolute path.
	 * @param string $content_type MIME type.
	 * @param string $filename     Optional download name.
	 * @return void
	 */
	protected function streamFile( $path, $content_type, $filename = '' ) {
		$size   = (int) filesize( $path );
		$start  = 0;
		$end    = $size - 1;
		$status = 200;

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( '' !== $range && preg_match( '/bytes=(\d*)-(\d*)/', $range, $matches ) ) {
			if ( '' !== $matches[1] ) {
				$start = (int) $matches[1];
			}
			if ( '' !== $matches[2] ) {
				$end = (int) $matches[2];
			}
			if ( $start > $end || $start >= $size ) {
				header( 'Content-Range: bytes */' . $size );
				status_header( 416 );
				exit;
			}
			$status = 206;
		}

		$length = $end - $start + 1;

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		status_header( $status );
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . ( '' !== $filename ? $filename : basename( $path ) ) . '"' );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );
		if ( 206 === $status ) {
			header( sprintf( 'Content-Range: bytes %1$d-%2$d/%3$d', $start, $end, $size ) );
		}

		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			wp_die( esc_html__( 'The archive could not be opened for reading.', 'sh-clone-migration' ), 500 );
		}
		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 1048576, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
			$remaining -= strlen( $chunk );
		}
		fclose( $handle );
		exit;
	}
}
