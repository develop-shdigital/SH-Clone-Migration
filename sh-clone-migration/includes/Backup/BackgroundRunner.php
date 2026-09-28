<?php
/**
 * Driving backup jobs without a browser.
 *
 * @package SHCM
 */

namespace SHCM\Backup;

use SHCM\Core\Plugin;
use SHCM\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a background job moving from one request to the next.
 *
 * A job advances in slices of a few seconds (the time budget). Nobody is
 * watching a scheduled backup, so after each slice this class asks the site
 * itself for the next one with a non-blocking loopback request, the same
 * technique WordPress uses to spawn WP-Cron. The request carries a MAC of the
 * job id, so it can only advance that job and nothing else. When loopbacks
 * are blocked (HTTP authentication on staging, a firewall) the minute worker
 * picks the job up instead, only more slowly, and System Status says so.
 */
class BackgroundRunner {

	const ACTION            = 'shcm_background_tick';
	const RESUME_HOOK       = 'shcm_background_resume';
	const BLOCKED_TRANSIENT = 'shcm_loopback_blocked';
	const SHORT_SLICE       = 2.0;

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Backups.
	 *
	 * @var BackupManager
	 */
	protected $backups;

	/**
	 * Constructor.
	 *
	 * @param Plugin        $plugin  Container.
	 * @param BackupManager $backups Backups.
	 */
	public function __construct( Plugin $plugin, BackupManager $backups ) {
		$this->plugin  = $plugin;
		$this->backups = $backups;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( self::RESUME_HOOK, array( $this, 'resume' ) );
		add_action( 'shcm_worker_ticked', array( $this, 'afterWorkerTick' ) );
	}

	/**
	 * Token a loopback request must carry for a job.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public function token( $job_id ) {
		return $this->backups->box()->mac( 'tick|' . (string) $job_id );
	}

	/**
	 * Advance a background job by one slice and arrange the next one.
	 *
	 * @param Job      $job     Job.
	 * @param int|null $seconds Length of this slice; null for the usual
	 *                          request budget. A request that a person is
	 *                          waiting on (Back up now) keeps it short.
	 * @return Job
	 */
	public function drive( Job $job, $seconds = null ) {
		if ( ! $job->isRunnable() || ! $job->param( 'background' ) ) {
			return $job;
		}
		$budget = null;
		if ( null !== $seconds ) {
			$budget = \SHCM\Jobs\Budget::create( max( 1, (int) $seconds ), $this->plugin->settings()->getInt( 'memory_guard', 80 ) );
		}
		$started = microtime( true );
		$job     = $this->plugin->runner()->tick( $job, $budget );
		if ( $job->runtime( 'busy' ) ) {
			// Another request holds the job; it arranges the next slice.
			return $job;
		}
		$this->continueLater( $job, microtime( true ) - $started );
		return $job;
	}

	/**
	 * Arrange the next slice of a job that is still runnable.
	 *
	 * @param Job   $job     Job.
	 * @param float $elapsed Seconds the last slice took (null if unknown).
	 * @return void
	 */
	public function continueLater( Job $job, $elapsed = null ) {
		if ( ! $job->isRunnable() || ! $job->param( 'background' ) ) {
			return;
		}
		$resume_at = (int) $job->shared( 'resume_at', 0 );
		// A slice that ended almost at once without asking to wait means the
		// job is idling (waiting on a retry, say); spinning loopback requests
		// would only burn the server, so let WP-Cron come back later.
		if ( $resume_at <= time() && null !== $elapsed && $elapsed < self::SHORT_SLICE ) {
			$resume_at = time() + 30;
		}
		if ( $resume_at > time() + 1 ) {
			$args = array( $job->id() );
			if ( false === wp_next_scheduled( self::RESUME_HOOK, $args ) ) {
				wp_schedule_single_event( $resume_at, self::RESUME_HOOK, $args );
			}
			return;
		}
		$this->spawn( $job->id() );
	}

	/**
	 * Fire the non-blocking loopback request for the next slice.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function spawn( $job_id ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return; // The CLI drives its jobs itself.
		}
		/**
		 * Whether to chain background slices through loopback requests.
		 *
		 * @param bool   $enabled Enabled.
		 * @param string $job_id  Job id.
		 */
		if ( ! apply_filters( 'shcm_background_loopback', true, $job_id ) ) {
			return;
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => self::ACTION,
					'job_id' => $job_id,
					'token'  => $this->token( $job_id ),
				),
			)
		);
	}

	/**
	 * Loopback endpoint.
	 *
	 * @return void
	 */
	public function handle() {
		$job_id = isset( $_POST['job_id'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $_POST['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authenticated by the MAC below.
		$token  = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $job_id || ! preg_match( '/^[a-f0-9]{64}$/', $token ) || ! hash_equals( $this->token( $job_id ), $token ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}
		$job = $this->plugin->jobs()->load( $job_id );
		if ( null === $job || ! $job->param( 'background' ) ) {
			wp_send_json_error( array( 'message' => 'Unknown job.' ), 404 );
		}
		delete_transient( self::BLOCKED_TRANSIENT );
		$this->plugin->logger()->channel( $job->id() );
		$job = $this->drive( $job );
		wp_send_json_success( array( 'status' => $job->status() ) );
	}

	/**
	 * WP-Cron callback: a job that asked to continue later.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function resume( $job_id ) {
		$job = $this->plugin->jobs()->load( (string) $job_id );
		if ( null !== $job ) {
			$this->drive( $job );
		}
	}

	/**
	 * The minute worker advanced a job: keep a background job moving, and
	 * notice when loopback requests are not getting through.
	 *
	 * @param Job $job Job after the tick.
	 * @return void
	 */
	public function afterWorkerTick( $job ) {
		if ( ! $job instanceof Job || ! $job->param( 'background' ) ) {
			return;
		}
		// The worker only takes background jobs that sat idle for 45 s or
		// more; with working loopbacks that does not happen.
		set_transient( self::BLOCKED_TRANSIENT, time(), DAY_IN_SECONDS );
		$this->continueLater( $job );
	}
}
