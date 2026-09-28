<?php
/**
 * Scheduled and on-demand backups.
 *
 * @package SHCM
 */

namespace SHCM\Backup;

use SHCM\Core\Plugin;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\Job;
use SHCM\Remote\GoogleDrive\Client;
use SHCM\Remote\GoogleDrive\Connection;
use SHCM\Remote\GoogleDrive\Endpoints;
use SHCM\Remote\GoogleDrive\OAuth;
use SHCM\Remote\Http\WordPressTransport;
use SHCM\Security\SecretBox;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the backup schedule and starts backup jobs.
 *
 * A backup is an ordinary export job with a few extra parameters, so it gets
 * the same archive format, verification and SHA-256 as a manual export. What
 * this class adds: when to run (a single WP-Cron event at the computed next
 * run, re-armed by reconcile()), what to include, refusing to overlap with an
 * import or another backup, the history, the notification e-mail, and the
 * password of encrypted backups.
 */
class BackupManager {

	const HOOK           = 'shcm_scheduled_backup';
	const DOCUMENT       = 'schedule';
	const PASSWORD_CTX   = 'backup-password';
	const DEFER_SECONDS  = 900;
	const MAX_DEFERRALS  = 8;

	/**
	 * Transient holding the earliest time to try a due run again after the
	 * bookkeeping for it failed (a full disk): without it the overdue run
	 * would be re-armed, and fail, every minute.
	 */
	const RETRY_TRANSIENT = 'shcm_backup_retry_at';

	/**
	 * Seconds an import or search & replace may sit untouched and unlocked
	 * before it no longer holds backups back (the rule maintenance mode uses
	 * for an abandoned restore).
	 */
	const STALE_JOB_SECONDS = 900;

	/**
	 * What the last startScheduled() call did, for WP-CLI.
	 *
	 * @var array{result: string, message: string}
	 */
	protected $outcome = array(
		'result'  => 'not_due',
		'message' => '',
	);

	/**
	 * Container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Lazily built services.
	 *
	 * @var array
	 */
	protected $services = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'handleScheduledEvent' ) );
		add_action( 'shcm_worker', array( $this, 'reconcile' ), 5 );
		add_action( 'shcm_cleanup', array( $this, 'reconcile' ), 5 );
		add_action( 'admin_init', array( $this, 'reconcile' ) );
		$this->registerBookkeeping();
		add_filter( 'shcm_job_password', array( $this, 'jobPassword' ), 10, 2 );
		add_filter( 'shcm_worker_may_tick', array( $this, 'workerMayTick' ), 10, 2 );
		$this->runner()->register();
	}

	/**
	 * Listen to job outcomes, to keep the history and send the e-mails. Also
	 * registered while backups are switched off, so that a backup cancelled
	 * then is recorded.
	 *
	 * @return void
	 */
	public function registerBookkeeping() {
		// The job itself is finished and saved by now; a failure to record it
		// (history file unwritable, mail error) must not escape into the
		// request that finished it.
		add_action(
			'shcm_job_completed',
			function ( $job ) {
				$this->safely( 'onJobCompleted', $job );
			}
		);
		add_action(
			'shcm_job_failed',
			function ( $job, $error = array() ) {
				$this->safely( 'onJobFailed', $job, $error );
			},
			10,
			2
		);
		add_action(
			'shcm_job_cancelled',
			function ( $job ) {
				$this->safely( 'onJobCancelled', $job );
			}
		);
	}

	/**
	 * Cancel the backups still running (backups were switched off with
	 * SHCM_DISABLE_BACKUPS): the worker would otherwise finish them, uploads
	 * and retention included.
	 *
	 * @return void
	 */
	public function cancelRunning() {
		foreach ( $this->plugin->jobs()->all( Job::TYPE_EXPORT, 50 ) as $job ) {
			if ( $job->isRunnable() && $this->isBackupJob( $job ) ) {
				$this->plugin->logger()->channel( $job->id() )->warning( 'Backups are switched off (SHCM_DISABLE_BACKUPS): the running backup is cancelled.' );
				$this->plugin->runner()->cancelJob( $job );
			}
		}
	}

	/**
	 * Call a job event handler, logging instead of throwing.
	 *
	 * @param string $method Handler.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	protected function safely( $method, ...$args ) {
		try {
			$this->$method( ...$args );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->channel( 'plugin' )->error( sprintf( 'Backup bookkeeping (%s) failed: %s', $method, $e->getMessage() ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Services
	 * ------------------------------------------------------------------ */

	/**
	 * Configuration store.
	 *
	 * @return ConfigStore
	 */
	public function store() {
		if ( ! isset( $this->services['store'] ) ) {
			$this->services['store'] = new ConfigStore( $this->plugin->storage()->config() );
		}
		return $this->services['store'];
	}

	/**
	 * Secret box.
	 *
	 * @return SecretBox
	 */
	public function box() {
		if ( ! isset( $this->services['box'] ) ) {
			$this->services['box'] = SecretBox::fromWordPress();
		}
		return $this->services['box'];
	}

	/**
	 * Backup history.
	 *
	 * @return History
	 */
	public function history() {
		if ( ! isset( $this->services['history'] ) ) {
			$this->services['history'] = new History( $this->store() );
		}
		return $this->services['history'];
	}

	/**
	 * Google Drive connection.
	 *
	 * @return Connection
	 */
	public function connection() {
		if ( ! isset( $this->services['connection'] ) ) {
			$connection = new Connection( $this->store(), $this->box() );
			// Every token or secret the connection opens or receives becomes
			// unprintable in logs from that moment on.
			$connection->onSecret( array( $this->plugin->logger()->redactor(), 'addLiteral' ) );
			$this->services['connection'] = $connection;
		}
		return $this->services['connection'];
	}

	/**
	 * Google OAuth.
	 *
	 * @return OAuth
	 */
	public function oauth() {
		if ( ! isset( $this->services['oauth'] ) ) {
			$this->services['oauth'] = new OAuth( $this->connection(), $this->transport(), Endpoints::resolve() );
		}
		return $this->services['oauth'];
	}

	/**
	 * Google Drive client.
	 *
	 * @return Client
	 */
	public function drive() {
		if ( ! isset( $this->services['drive'] ) ) {
			$this->services['drive'] = new Client( $this->oauth(), $this->transport(), Endpoints::resolve() );
		}
		return $this->services['drive'];
	}

	/**
	 * HTTP transport.
	 *
	 * @return \SHCM\Remote\Http\HttpTransport
	 */
	public function transport() {
		if ( ! isset( $this->services['transport'] ) ) {
			$this->services['transport'] = new WordPressTransport();
		}
		return $this->services['transport'];
	}

	/**
	 * Background driver.
	 *
	 * @return BackgroundRunner
	 */
	public function runner() {
		if ( ! isset( $this->services['runner'] ) ) {
			$this->services['runner'] = new BackgroundRunner( $this->plugin, $this );
		}
		return $this->services['runner'];
	}

	/**
	 * Replace a service (test-suite).
	 *
	 * @param string $key     Service key.
	 * @param object $service Service.
	 * @return void
	 */
	public function setService( $key, $service ) {
		$this->services[ $key ] = $service;
	}

	/**
	 * Make every secret currently in memory unprintable in logs.
	 *
	 * @return void
	 */
	public function protectSecrets() {
		$redactor = $this->plugin->logger()->redactor();
		foreach ( $this->connection()->secrets() as $secret ) {
			$redactor->addLiteral( $secret );
		}
	}

	/* ------------------------------------------------------------------
	 * Schedule
	 * ------------------------------------------------------------------ */

	/**
	 * Stored schedule configuration, normalised.
	 *
	 * @return array
	 */
	public function config() {
		$data = $this->store()->read( self::DOCUMENT );
		$raw  = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();
		try {
			// Stored values are the "current" side: a damaged field falls back
			// to its default instead of discarding the whole schedule.
			return Schedule::sanitize( array(), $raw );
		} catch ( \InvalidArgumentException $e ) {
			return Schedule::defaults();
		}
	}

	/**
	 * Runtime state of the schedule.
	 *
	 * @return array
	 */
	public function state() {
		$data  = $this->store()->read( self::DOCUMENT );
		$state = isset( $data['state'] ) && is_array( $data['state'] ) ? $data['state'] : array();
		return array_merge(
			array(
				'next_run'    => null,
				'fingerprint' => '',
				'last_run'    => 0,
				'last_job'    => '',
				'deferrals'   => 0,
				'password'    => null,
				'updated_at'  => 0,
			),
			$state
		);
	}

	/**
	 * Whether the schedule was set up on this installation.
	 *
	 * A schedule that has never been saved has no fingerprint and belongs to
	 * nobody; one saved on another installation (a host-level copy of the
	 * site) does not run here until an administrator confirms.
	 *
	 * @return bool
	 */
	public function identityMatches() {
		$state = $this->state();
		return '' === (string) $state['fingerprint'] || hash_equals( (string) $state['fingerprint'], SiteIdentity::fingerprint() );
	}

	/**
	 * Save the schedule.
	 *
	 * @param array       $input    Submitted configuration.
	 * @param string|null $password New backup password (null = unchanged).
	 * @return array array( config, state )
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public function saveSchedule( array $input, $password = null ) {
		$this->requireMainSite();
		$config = Schedule::sanitize( $input, $this->config() );
		$state  = $this->state();

		if ( null !== $password ) {
			$password          = (string) $password;
			$state['password'] = '' === $password ? null : $this->box()->seal( $password, self::PASSWORD_CTX );
		}
		if ( empty( $config['encrypt'] ) ) {
			// Encryption off: the password is not kept (a running encrypted
			// backup has its own sealed copy in its job).
			$state['password'] = null;
		}
		if ( ! empty( $config['encrypt'] ) && null === $this->backupPassword( $state ) ) {
			throw new \InvalidArgumentException( __( 'Enter the password for encrypted backups (and keep it safe: without it the backups cannot be restored).', 'sh-clone-migration' ) );
		}
		if ( ! empty( $config['gdrive'] ) && ! $this->connection()->isUsable() ) {
			throw new \InvalidArgumentException( __( 'Connect Google Drive first, or switch off "Store backups on Google Drive".', 'sh-clone-migration' ) );
		}

		$state['fingerprint'] = SiteIdentity::fingerprint();
		$state['next_run']    = Schedule::nextRun( $config, $this->timezone(), time() );
		$state['tz']          = $this->timezone()->getName();
		$state['deferrals']   = 0;
		$state['updated_at']  = time();

		$this->store()->update(
			self::DOCUMENT,
			function () use ( $config, $state ) {
				return array(
					'config' => $config,
					'state'  => $state,
				);
			}
		);
		$this->reconcile();

		return array(
			'config' => $config,
			'state'  => $this->state(),
		);
	}

	/**
	 * Confirm that this installation owns the schedule and the Drive
	 * connection (the site was moved rather than copied).
	 *
	 * @return void
	 */
	public function adoptThisSite() {
		$this->requireMainSite();
		$this->updateState(
			function ( array $state ) {
				$state['fingerprint'] = SiteIdentity::fingerprint();
				$state['next_run']    = null;
				return $state;
			}
		);
		$this->connection()->adoptThisSite();
		$this->reconcile();
	}

	/**
	 * Backups cover the whole network and run on the main site only; its
	 * settings are changed there too.
	 *
	 * @return void
	 * @throws \InvalidArgumentException On a subsite of a network.
	 */
	protected function requireMainSite() {
		if ( ! $this->isMainSite() ) {
			throw new \InvalidArgumentException( __( 'Backups cover the whole network and are managed on the main site only. Open this screen on the main site.', 'sh-clone-migration' ) );
		}
	}

	/**
	 * Make the WP-Cron event match the schedule.
	 *
	 * Runs every minute (worker), daily, on admin pages and on activation, so
	 * it also repairs what an import did to the cron option: the imported
	 * site's events are replaced by this site's own schedule.
	 *
	 * @return void
	 */
	public function reconcile() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		if ( ! $this->configReadable() ) {
			// The schedule exists but this process cannot read it: leave the
			// event alone rather than treat the site as unscheduled.
			return;
		}
		$config = $this->config();
		$active = 'manual' !== $config['frequency'] && $this->identityMatches() && $this->isMainSite();

		if ( ! $active ) {
			if ( false !== wp_next_scheduled( self::HOOK ) ) {
				wp_unschedule_hook( self::HOOK );
			}
			if ( 'manual' !== $config['frequency'] && $this->isMainSite() && ! $this->identityMatches() ) {
				$this->reportPausedRun( $config );
			}
			return;
		}

		$state = $this->state();
		$next  = null === $state['next_run'] ? null : (int) $state['next_run'];
		$tz    = $this->timezone()->getName();
		$moved = isset( $state['tz'] ) && '' !== (string) $state['tz'] && $tz !== (string) $state['tz'];
		if ( null === $next || ( $moved && 0 === (int) $state['deferrals'] && $next > time() ) ) {
			// Never computed, or computed in a timezone the site no longer
			// uses (the owner set the site's timezone after the schedule).
			$next = Schedule::nextRun( $config, $this->timezone(), time() );
			$this->updateState(
				function ( array $s ) use ( $next, $tz ) {
					$s['next_run'] = $next;
					$s['tz']       = $tz;
					return $s;
				}
			);
		}

		// An overdue run (WP-Cron did not fire, or the site was offline) runs
		// once, as soon as possible, not once per missed period. After a run
		// whose bookkeeping failed, not before the retry time.
		$now       = time();
		$retry     = function_exists( 'get_transient' ) ? (int) get_transient( self::RETRY_TRANSIENT ) : 0;
		$when      = $next <= $now ? max( $now + 5, $retry ) : $next;
		$scheduled = wp_next_scheduled( self::HOOK );
		if ( false !== $scheduled ) {
			if ( (int) $scheduled === $next || (int) $scheduled === $when ) {
				return;
			}
			if ( $next <= $now && (int) $scheduled <= $when + 60 && (int) $scheduled >= $retry ) {
				return;
			}
		}
		wp_unschedule_hook( self::HOOK );
		wp_schedule_single_event( $when, self::HOOK );
	}

	/**
	 * The schedule is paused because this installation is not the one that
	 * set it up. When a run falls due meanwhile, say so once per missed run
	 * (history and e-mail): a legitimate change (the site's address, its
	 * directory) would otherwise stop the backups without anyone noticing.
	 *
	 * @param array $config Configuration.
	 * @return void
	 */
	protected function reportPausedRun( array $config ) {
		$state = $this->state();
		if ( null === $state['next_run'] || (int) $state['next_run'] > time() ) {
			return;
		}
		$message = __( 'Scheduled backups are paused: this installation is not the one that set them up (the site was moved, copied or its address changed). Confirm on the Scheduled Backups screen that this is the same site.', 'sh-clone-migration' );
		$this->plugin->logger()->channel( 'plugin' )->warning( 'Scheduled backup skipped: ' . $message );
		try {
			$this->advanceSchedule( $config );
		} catch ( \Throwable $e ) {
			return; // Cannot record that it was handled: better no e-mail than one per minute.
		}
		$entry = $this->recordSafely(
			'skipped-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 2 ) ),
			array(
				'kind'     => 'skipped',
				'trigger'  => 'schedule',
				'status'   => 'skipped',
				'started'  => time(),
				'finished' => time(),
				'error'    => $message,
			)
		);
		$this->notify( $entry, $config );
	}

	/**
	 * WP-Cron callback: start the scheduled backup.
	 *
	 * @return void
	 */
	public function handleScheduledEvent() {
		try {
			$job = $this->startScheduled( true );
			if ( null !== $job ) {
				// First slice in this cron request; the rest continues through
				// loopback requests (or the minute worker if loopbacks are blocked).
				$this->runner()->drive( $job );
			}
		} catch ( \Throwable $e ) {
			// Never take wp-cron.php down with us: the other events of this
			// request still have to run.
			$this->plugin->logger()->channel( 'plugin' )->error( 'Scheduled backup: ' . $e->getMessage() );
			$this->retryLater();
		}
	}

	/**
	 * What the last startScheduled() call did.
	 *
	 * @return array{result: string, message: string} result: started, not_due,
	 *               postponed, skipped, paused or failed.
	 */
	public function lastOutcome() {
		return $this->outcome;
	}

	/**
	 * Do not try the due run again before DEFER_SECONDS (its bookkeeping
	 * failed, so next_run could not move forward).
	 *
	 * @return void
	 */
	protected function retryLater() {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::RETRY_TRANSIENT, time() + self::DEFER_SECONDS, 2 * self::DEFER_SECONDS );
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_unschedule_hook( self::HOOK );
			wp_schedule_single_event( time() + self::DEFER_SECONDS, self::HOOK );
		}
	}

	/**
	 * Write a history entry if possible; the entry is returned either way, so
	 * the notification e-mail does not depend on a disk that may be full.
	 *
	 * @param string $id     Entry id.
	 * @param array  $fields Fields.
	 * @return array
	 */
	protected function recordSafely( $id, array $fields ) {
		try {
			return $this->history()->record( $id, $fields );
		} catch ( \Throwable $e ) {
			$this->plugin->logger()->channel( 'plugin' )->error( 'The backup history could not be updated: ' . $e->getMessage() );
			$entry       = array_merge( array( 'created' => time() ), $fields );
			$entry['id'] = (string) $id;
			return $entry;
		}
	}

	/**
	 * Start the scheduled backup if it is due.
	 *
	 * Shared by the WP-Cron event and `wp shcm backup run` (system cron), so
	 * the two cannot both start the same run: whichever comes first moves
	 * next_run forward and the other finds nothing due.
	 *
	 * @param bool $from_event Called by the WP-Cron event (which may fire a
	 *                         little early, or be a leftover from an import).
	 * @return Job|null The job started, or null.
	 */
	public function startScheduled( $from_event = false ) {
		$logger        = $this->plugin->logger()->channel( 'plugin' );
		$this->outcome = array(
			'result'  => 'not_due',
			'message' => '',
		);
		if ( ! $this->configReadable() ) {
			$problems = implode( ' ', $this->storageProblems() );
			$logger->error( 'Scheduled backup not started: ' . $problems );
			$this->outcome = array(
				'result'  => 'paused',
				'message' => $problems,
			);
			if ( $from_event && function_exists( 'wp_schedule_single_event' ) && false === wp_next_scheduled( self::HOOK ) ) {
				// Try again later instead of losing the run.
				wp_schedule_single_event( time() + self::DEFER_SECONDS, self::HOOK );
			}
			return null;
		}
		$config = $this->config();
		$state  = $this->state();

		if ( 'manual' === $config['frequency'] || ! $this->isMainSite() ) {
			// An event left behind by an import or by an older schedule.
			$this->reconcile();
			return null;
		}
		if ( ! $this->identityMatches() ) {
			$this->outcome = array(
				'result'  => 'paused',
				'message' => __( 'Scheduled backups are paused: this installation is not the one that set them up. Confirm on the Scheduled Backups screen that this is the same site.', 'sh-clone-migration' ),
			);
			$this->reconcile();
			return null;
		}
		$next = null === $state['next_run'] ? null : (int) $state['next_run'];
		if ( null === $next || time() < $next - ( $from_event ? 60 : 0 ) ) {
			// Not due yet (for the event: an event that came with an imported
			// cron option, or a clock that is slightly ahead).
			$this->reconcile();
			return null;
		}

		$conflict = $this->conflict();
		if ( null !== $conflict ) {
			$deferrals = (int) $state['deferrals'] + 1;
			if ( $conflict['defer'] && $deferrals <= self::MAX_DEFERRALS ) {
				$logger->warning( sprintf( 'Scheduled backup postponed by %d minutes: %s', self::DEFER_SECONDS / 60, $conflict['message'] ) );
				$this->outcome = array(
					'result'  => 'postponed',
					'message' => $conflict['message'],
				);
				try {
					$this->updateState(
						function ( array $s ) use ( $deferrals ) {
							$s['deferrals'] = $deferrals;
							$s['next_run']  = time() + self::DEFER_SECONDS;
							return $s;
						}
					);
				} catch ( \Throwable $e ) {
					$logger->error( 'The backup schedule could not be updated: ' . $e->getMessage() );
					$this->retryLater();
					return null;
				}
			} else {
				$logger->warning( 'Scheduled backup skipped: ' . $conflict['message'] );
				$this->outcome = array(
					'result'  => 'skipped',
					'message' => $conflict['message'],
				);
				$entry = $this->recordSafely(
					'skipped-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 2 ) ),
					array(
						'kind'     => 'skipped',
						'trigger'  => 'schedule',
						'status'   => 'skipped',
						'started'  => time(),
						'finished' => time(),
						'error'    => $conflict['message'],
					)
				);
				// Worth an e-mail either way: skipped after repeated
				// postponements, or because the previous backup is still
				// running when the next one is due (slow, or stuck).
				$this->notify( $entry, $config );
				try {
					$this->advanceSchedule( $config );
				} catch ( \Throwable $e ) {
					$logger->error( 'The backup schedule could not be updated: ' . $e->getMessage() );
					$this->retryLater();
					return null;
				}
			}
			$this->reconcile();
			return null;
		}

		// Claim the run under the schedule's lock: WP-Cron and a system cron
		// job running `wp shcm backup run` may get here at the same moment,
		// and only the one that moves next_run on may start the backup.
		try {
			$claimed = $this->claimRun( $next, $config );
		} catch ( \Throwable $e ) {
			$message = __( 'The scheduled backup could not start: its settings could not be saved (is the disk full?).', 'sh-clone-migration' ) . ' ' . $e->getMessage();
			$logger->error( $message );
			$this->outcome = array(
				'result'  => 'failed',
				'message' => $message,
			);
			$entry = $this->recordSafely(
				'failed-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 2 ) ),
				array(
					'kind'     => 'failed',
					'trigger'  => 'schedule',
					'status'   => 'failed',
					'started'  => time(),
					'finished' => time(),
					'error'    => $message,
				)
			);
			$this->notify( $entry, $config );
			$this->retryLater();
			return null;
		}
		if ( ! $claimed ) {
			$this->reconcile();
			return null;
		}

		try {
			$job = $this->startBackup( 'schedule' );
		} catch ( \Throwable $e ) {
			$logger->error( 'Scheduled backup could not start: ' . $e->getMessage() );
			$this->outcome = array(
				'result'  => 'failed',
				'message' => $e->getMessage(),
			);
			$entry = $this->recordSafely(
				'failed-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 2 ) ),
				array(
					'kind'     => 'failed',
					'trigger'  => 'schedule',
					'status'   => 'failed',
					'started'  => time(),
					'finished' => time(),
					'error'    => $e->getMessage(),
				)
			);
			$this->notify( $entry, $config );
			$this->reconcile();
			return null;
		}

		$this->outcome = array(
			'result'  => 'started',
			'message' => $job->id(),
		);
		try {
			$this->updateState(
				function ( array $s ) use ( $job ) {
					$s['last_job'] = $job->id();
					return $s;
				}
			);
		} catch ( \Throwable $e ) {
			$logger->error( 'The backup schedule could not be updated: ' . $e->getMessage() );
		}
		$this->reconcile();
		return $job;
	}

	/**
	 * Move a due run on to the next slot, if nobody else did meanwhile.
	 *
	 * @param int   $due    next_run as read when the run was found due.
	 * @param array $config Configuration.
	 * @return bool Whether this request claimed the run.
	 */
	protected function claimRun( $due, array $config ) {
		$claimed   = false;
		$following = Schedule::nextRun( $config, $this->timezone(), time() );
		$tz        = $this->timezone()->getName();
		$this->updateState(
			function ( array $s ) use ( $due, $following, $tz, &$claimed ) {
				if ( ! isset( $s['next_run'] ) || (int) $s['next_run'] !== (int) $due ) {
					return $s;
				}
				$claimed        = true;
				$s['next_run']  = $following;
				$s['tz']        = $tz;
				$s['last_run']  = time();
				$s['deferrals'] = 0;
				return $s;
			}
		);
		if ( $claimed && function_exists( 'delete_transient' ) ) {
			delete_transient( self::RETRY_TRANSIENT );
		}
		return $claimed;
	}

	/**
	 * Record a run and compute the next one.
	 *
	 * @param array  $config Configuration.
	 * @param string $job_id Job started, if any.
	 * @return void
	 */
	protected function advanceSchedule( array $config, $job_id = '' ) {
		$next = Schedule::nextRun( $config, $this->timezone(), time() );
		$tz   = $this->timezone()->getName();
		$this->updateState(
			function ( array $s ) use ( $next, $job_id, $tz ) {
				$s['next_run']  = $next;
				$s['tz']        = $tz;
				$s['last_run']  = time();
				$s['deferrals'] = 0;
				if ( '' !== $job_id ) {
					$s['last_job'] = $job_id;
				}
				return $s;
			}
		);
	}

	/**
	 * Change the stored state under the document lock.
	 *
	 * @param callable $mutator Receives and returns the state.
	 * @return void
	 */
	protected function updateState( callable $mutator ) {
		$this->store()->update(
			self::DOCUMENT,
			function ( array $data ) use ( $mutator ) {
				$state         = isset( $data['state'] ) && is_array( $data['state'] ) ? $data['state'] : array();
				$data['state'] = $mutator( $state );
				if ( ! isset( $data['config'] ) ) {
					$data['config'] = Schedule::defaults();
				}
				return $data;
			}
		);
	}

	/* ------------------------------------------------------------------
	 * Jobs
	 * ------------------------------------------------------------------ */

	/**
	 * Why a backup cannot start right now.
	 *
	 * @return array|null array( message, defer ) or null when nothing is in the way.
	 */
	public function conflict() {
		if ( class_exists( MaintenanceMode::class ) && MaintenanceMode::isEnabled() ) {
			return array(
				'message' => __( 'The site is in maintenance mode (a restore is running).', 'sh-clone-migration' ),
				'defer'   => true,
			);
		}
		foreach ( $this->plugin->jobs()->all( null, 50 ) as $job ) {
			if ( ! $job->isRunnable() ) {
				continue;
			}
			if ( Job::TYPE_IMPORT === $job->type() || Job::TYPE_REPLACE === $job->type() ) {
				$idle = time() - (int) $job->get( 'updated_at' );
				if ( $idle > self::STALE_JOB_SECONDS && ! \SHCM\Jobs\JobLock::isLocked( $this->plugin->runner()->jobsDirectory(), $job->id() ) ) {
					// Abandoned (an encrypted restore whose tab was closed can
					// never be resumed without its password): it must not hold
					// every future backup back.
					continue;
				}
				return array(
					'message' => sprintf(
						/* translators: 1: job id, 2: human time difference */
						__( 'A restore or search & replace is in progress (job %1$s, last active %2$s ago).', 'sh-clone-migration' ),
						$job->id(),
						function_exists( 'human_time_diff' ) ? human_time_diff( time() - max( 0, $idle ), time() ) : max( 0, $idle ) . 's'
					),
					'defer'   => true,
				);
			}
			if ( $this->isBackupJob( $job ) ) {
				return array(
					'message' => __( 'The previous backup is still running.', 'sh-clone-migration' ),
					'defer'   => false,
					'job'     => $job->id(),
				);
			}
		}
		return null;
	}

	/**
	 * A restore or search & replace changing the site right now (not an
	 * abandoned one): a running backup waits for it rather than archive half
	 * of the old site and half of the new one.
	 *
	 * @return string|null Why, or null.
	 */
	public function restoreInProgress() {
		if ( class_exists( MaintenanceMode::class ) && MaintenanceMode::isEnabled() ) {
			return __( 'The site is in maintenance mode (a restore is running).', 'sh-clone-migration' );
		}
		foreach ( $this->plugin->jobs()->all( null, 50 ) as $job ) {
			if ( ! $job->isRunnable() || ! in_array( $job->type(), array( Job::TYPE_IMPORT, Job::TYPE_REPLACE ), true ) ) {
				continue;
			}
			$idle = time() - (int) $job->get( 'updated_at' );
			if ( $idle <= self::STALE_JOB_SECONDS || \SHCM\Jobs\JobLock::isLocked( $this->plugin->runner()->jobsDirectory(), $job->id() ) ) {
				return __( 'A restore or search & replace is in progress.', 'sh-clone-migration' );
			}
		}
		return null;
	}

	/**
	 * Filter callback: the worker leaves a backup alone while a restore runs.
	 *
	 * @param bool $may Whether the worker may tick the job.
	 * @param Job  $job Job.
	 * @return bool
	 */
	public function workerMayTick( $may, $job ) {
		if ( $may && $job instanceof Job && $this->isBackupJob( $job ) && null !== $this->restoreInProgress() ) {
			return false;
		}
		return $may;
	}

	/**
	 * Start a backup job (not yet ticked).
	 *
	 * @param string $trigger   schedule, manual or cli.
	 * @param array  $overrides Configuration overrides for this run (contents, gdrive).
	 * @return Job
	 * @throws \RuntimeException When a backup cannot start now.
	 */
	public function startBackup( $trigger, array $overrides = array() ) {
		$conflict = $this->conflict();
		if ( null !== $conflict ) {
			throw new \RuntimeException( $conflict['message'] );
		}

		$config = $this->config();
		if ( ! empty( $overrides ) ) {
			$config = Schedule::sanitize( array_intersect_key( $overrides, array_flip( array( 'contents', 'gdrive', 'include_core' ) ) ), $config );
		}
		$gdrive = ! empty( $config['gdrive'] );
		if ( $gdrive && ! $this->connection()->isUsable() ) {
			if ( 'schedule' !== $trigger && isset( $overrides['gdrive'] ) && $overrides['gdrive'] ) {
				throw new \RuntimeException( __( 'Google Drive is not connected. Connect it on the Scheduled Backups screen, or back up to this server only.', 'sh-clone-migration' ) );
			}
			// Scheduled runs still make the local backup; the upload stage
			// records why the Drive copy is missing.
		}

		$exclusions = (array) $config['exclusions'];
		$include_db = 'files' !== $config['contents'];
		$core       = ! empty( $config['include_core'] );
		if ( 'database' === $config['contents'] ) {
			// Every file root is wp-content or lives below it: excluding it
			// leaves the files stage with nothing to copy.
			$exclusions[] = 'wp-content';
			$core         = false;
		}

		$encrypted = ! empty( $config['encrypt'] );
		if ( $encrypted && null === $this->backupPassword() ) {
			throw new \RuntimeException( __( 'Encrypted backups are switched on but no password is stored (the site keys in wp-config.php may have changed). Enter the password again on the Scheduled Backups screen.', 'sh-clone-migration' ) );
		}
		if ( $encrypted && ! \SHCM\Crypto\Cipher::isAvailable() ) {
			throw new \RuntimeException( __( 'Encryption needs the sodium or OpenSSL PHP extension.', 'sh-clone-migration' ) );
		}

		$params = array(
			'name'                   => $this->archiveBaseName(),
			'include_core'           => $core,
			'include_database'       => $include_db,
			'include_foreign_tables' => false,
			'exclude_tables'         => array(),
			'exclusions'             => array_values( array_unique( $exclusions ) ),
			'encrypted'              => $encrypted,
			'password_source'        => $encrypted ? 'backup' : '',
			// The job keeps the password it started with (sealed): a new one
			// saved while it runs must not break the archive half way.
			'password_sealed'        => $encrypted ? (string) $this->state()['password'] : '',
			'background'             => true,
			'backup'                 => array(
				'kind'        => 'backup',
				'trigger'     => (string) $trigger,
				'contents'    => $config['contents'],
				'gdrive'      => $gdrive,
				'keep_local'  => (int) $config['keep_local'],
				'keep_remote' => (int) $config['keep_remote'],
			),
		);

		$job = $this->createJob( $params );
		$this->history()->record(
			$job->id(),
			array(
				'kind'      => 'backup',
				'trigger'   => (string) $trigger,
				'status'    => 'running',
				'started'   => time(),
				'contents'  => $config['contents'],
				'encrypted' => $encrypted,
				'remote'    => array( 'status' => $gdrive ? 'pending' : 'off' ),
			)
		);
		$this->plugin->logger()->channel( $job->id() )->info(
			sprintf( 'Backup started (%s, contents: %s%s%s).', $trigger, $config['contents'], $gdrive ? ', Google Drive' : '', $encrypted ? ', encrypted' : '' )
		);
		return $job;
	}

	/**
	 * Start a job that only uploads an existing archive to Google Drive.
	 *
	 * @param string $archive Archive base name.
	 * @param string $kind    backup (counts towards Drive retention) or manual.
	 * @param string $history History entry to update, if any.
	 * @return Job
	 * @throws \RuntimeException When the archive cannot be uploaded.
	 */
	public function startUpload( $archive, $kind = 'manual', $history = '' ) {
		$catalog = new \SHCM\Archive\Catalog( $this->plugin->storage() );
		$path    = $catalog->resolve( $archive );
		if ( null === $path ) {
			throw new \RuntimeException( __( 'Archive not found.', 'sh-clone-migration' ) );
		}
		if ( null === \SHCM\Archive\Reader::readFooter( $path ) ) {
			throw new \RuntimeException( __( 'This archive is incomplete and cannot be uploaded.', 'sh-clone-migration' ) );
		}
		if ( ! $this->connection()->isUsable() ) {
			throw new \RuntimeException( __( 'Google Drive is not connected.', 'sh-clone-migration' ) );
		}
		foreach ( $this->plugin->jobs()->all( null, 50 ) as $other ) {
			if ( $other->isRunnable() && $other->param( 'upload_only' ) && $other->param( 'archive_path' ) === $path ) {
				throw new \RuntimeException( __( 'This archive is already being uploaded.', 'sh-clone-migration' ) );
			}
		}

		$entry = '' !== $history ? $this->history()->get( $history ) : $this->history()->forArchive( basename( $path ) );
		$kind  = null !== $entry && isset( $entry['kind'] ) ? (string) $entry['kind'] : ( 'backup' === $kind ? 'backup' : 'manual' );
		$config = $this->config();

		$params = array(
			'upload_only'    => true,
			'archive_path'   => $path,
			'archive_sha256' => $catalog->sha256( $path ),
			'background'     => true,
			'history_id'     => null !== $entry ? (string) $entry['id'] : '',
			'backup'         => array(
				'kind'        => $kind,
				'trigger'     => 'manual',
				'gdrive'      => true,
				'keep_local'  => (int) $config['keep_local'],
				'keep_remote' => (int) $config['keep_remote'],
			),
		);
		$job = $this->createJob( $params );
		if ( null !== $entry ) {
			$this->history()->record( (string) $entry['id'], array( 'remote' => array( 'status' => 'pending', 'error' => '', 'job' => $job->id() ) ) );
		}
		return $job;
	}

	/**
	 * Create and save a job.
	 *
	 * @param array $params Parameters.
	 * @return Job
	 */
	protected function createJob( array $params ) {
		$stages = $this->plugin->registry()->stagesFor( Job::TYPE_EXPORT, $params );
		$job    = Job::create( Job::TYPE_EXPORT, $params, $stages );
		// The browser that starts a run drives it with this token; only its
		// hash is stored.
		$job->setRuntime( 'token', \SHCM\Security\JobToken::issue( $job ) );
		$this->plugin->jobs()->save( $job );
		return $job;
	}

	/**
	 * Archive name for a new backup (a random suffix is appended later).
	 *
	 * @return string
	 */
	protected function archiveBaseName() {
		$host = function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
		$host = '' === $host ? 'wordpress' : $host;
		return $host . '-backup-' . wp_date( 'Ymd-Hi', null, $this->timezone() );
	}

	/**
	 * Whether a job is a backup run (or a Drive upload of one).
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function isBackupJob( Job $job ) {
		$backup = $job->param( 'backup' );
		return Job::TYPE_EXPORT === $job->type() && is_array( $backup ) && ! empty( $backup['kind'] );
	}

	/**
	 * Filter callback: the password of an encrypted backup job.
	 *
	 * @param string $password Password so far.
	 * @param Job    $job      Job.
	 * @return string
	 */
	public function jobPassword( $password, $job ) {
		if ( '' !== (string) $password || ! $job instanceof Job || 'backup' !== $job->param( 'password_source' ) ) {
			return (string) $password;
		}
		$own    = (string) $job->param( 'password_sealed', '' );
		$stored = '' !== $own ? $this->box()->open( $own, self::PASSWORD_CTX ) : $this->backupPassword();
		if ( null === $stored || '' === $stored ) {
			return '';
		}
		$this->plugin->logger()->redactor()->addLiteral( $stored );
		return $stored;
	}

	/**
	 * The stored password for encrypted backups.
	 *
	 * @param array|null $state State to read from.
	 * @return string|null
	 */
	protected function backupPassword( $state = null ) {
		$state = null === $state ? $this->state() : $state;
		if ( empty( $state['password'] ) ) {
			return null;
		}
		$plain = $this->box()->open( (string) $state['password'], self::PASSWORD_CTX );
		return null === $plain || '' === $plain ? null : $plain;
	}

	/**
	 * Whether a backup password is stored and readable.
	 *
	 * @return bool
	 */
	public function hasPassword() {
		return null !== $this->backupPassword();
	}

	/* ------------------------------------------------------------------
	 * Job outcome
	 * ------------------------------------------------------------------ */

	/**
	 * A backup job finished.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function onJobCompleted( $job ) {
		if ( ! $job instanceof Job || ! $this->isBackupJob( $job ) ) {
			return;
		}
		$remote = (array) $job->shared( 'remote_upload', array() );
		$id     = $this->historyId( $job );

		if ( $job->param( 'upload_only' ) ) {
			if ( '' !== $id ) {
				$this->history()->record( $id, array( 'remote' => $this->remoteFields( $remote ) ) );
			}
			return;
		}

		$backup = (array) $job->param( 'backup', array() );
		$path   = (string) $job->param( 'archive_path' );
		$status = 'success';
		$error  = '';
		if ( ! empty( $backup['gdrive'] ) && ( empty( $remote['status'] ) || 'uploaded' !== $remote['status'] ) ) {
			$status = 'partial';
			$error  = isset( $remote['error'] ) ? (string) $remote['error'] : __( 'The backup was not uploaded to Google Drive.', 'sh-clone-migration' );
		}
		$entry = $this->recordSafely(
			$id,
			array(
				'status'    => $status,
				'finished'  => time(),
				'archive'   => basename( $path ),
				'size'      => (int) $job->shared( 'archive_size', 0 ),
				'sha256'    => (string) $job->shared( 'archive_sha256', '' ),
				'database'  => (array) $job->shared( 'database_totals', array() ),
				'files'     => (array) $job->shared( 'files_exported', array() ),
				'warnings'  => (int) $job->get( 'warnings_total', 0 ),
				'error'     => $error,
				'local'     => array( 'kept' => is_file( $path ) ),
				'remote'    => ! empty( $backup['gdrive'] ) ? $this->remoteFields( $remote ) : array( 'status' => 'off' ),
			)
		);
		$this->notify( $entry, $this->config() );
	}

	/**
	 * A backup job failed.
	 *
	 * @param Job   $job   Job.
	 * @param array $error Error.
	 * @return void
	 */
	public function onJobFailed( $job, $error = array() ) {
		if ( ! $job instanceof Job || ! $this->isBackupJob( $job ) ) {
			return;
		}
		// JobRunner passes the exception; the job's saved error has the same
		// message.
		if ( $error instanceof \Throwable ) {
			$message = $error->getMessage();
		} elseif ( is_array( $error ) && isset( $error['message'] ) ) {
			$message = (string) $error['message'];
		} else {
			$saved   = $job->get( 'error' );
			$message = is_array( $saved ) && isset( $saved['message'] ) ? (string) $saved['message'] : '';
		}
		if ( '' === trim( $message ) ) {
			$message = __( 'The backup failed.', 'sh-clone-migration' );
		}
		$id = $this->historyId( $job );

		if ( $job->param( 'upload_only' ) ) {
			if ( '' !== $id ) {
				$this->history()->record(
					$id,
					array(
						'remote' => array(
							'status' => 'failed',
							'error'  => $message,
						),
					)
				);
			}
			return;
		}

		// Reported already (a job whose failed state could not be saved is
		// failed again when it is picked up): keep the first cause, and do not
		// e-mail twice.
		$existing = $this->history()->get( $id );
		$noted    = function_exists( 'get_transient' ) && get_transient( 'shcm_backup_failed_' . md5( $id ) );
		if ( $noted || ( is_array( $existing ) && isset( $existing['status'] ) && 'failed' === $existing['status'] ) ) {
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			// The database, unlike a full disk, can usually still take this.
			set_transient( 'shcm_backup_failed_' . md5( $id ), time(), DAY_IN_SECONDS );
		}
		$path = (string) $job->param( 'archive_path' );
		// A failed export leaves a partial archive behind: remove it, it is
		// not a backup and it only uses space. A finished archive (the job
		// failed after it, while verifying or uploading) is a backup: it stays,
		// is recorded as kept, and its upload can be retried.
		if ( '' !== $path && is_file( $path ) && null === \SHCM\Archive\Reader::readFooter( $path ) ) {
			@unlink( $path );
		}
		$fields = array(
			'kind'     => 'backup',
			'status'   => 'failed',
			'finished' => time(),
			'archive'  => '' !== $path ? basename( $path ) : '',
			'error'    => $message,
			'local'    => array( 'kept' => '' !== $path && is_file( $path ) ),
		);
		$backup = (array) $job->param( 'backup', array() );
		if ( ! empty( $backup['gdrive'] ) ) {
			$remote           = (array) $job->shared( 'remote_upload', array() );
			$fields['remote'] = isset( $remote['status'] ) && 'uploaded' === $remote['status']
				? $this->remoteFields( $remote )
				: array(
					'status' => 'failed',
					'error'  => $message,
				);
		}
		$entry = $this->recordSafely( $id, $fields );
		$this->notify( $entry, $this->config() );
	}

	/**
	 * A backup job was cancelled.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	public function onJobCancelled( $job ) {
		if ( ! $job instanceof Job || ! $this->isBackupJob( $job ) ) {
			return;
		}
		$id = $this->historyId( $job );
		if ( '' === $id ) {
			return;
		}
		if ( $job->param( 'upload_only' ) ) {
			$this->history()->record( $id, array( 'remote' => array( 'status' => 'failed', 'error' => __( 'Upload cancelled.', 'sh-clone-migration' ) ) ) );
			return;
		}
		$this->history()->record(
			$id,
			array(
				'status'   => 'cancelled',
				'finished' => time(),
				'error'    => __( 'Cancelled.', 'sh-clone-migration' ),
			)
		);
		$path = (string) $job->param( 'archive_path' );
		if ( '' === $path || ! is_file( $path ) ) {
			return;
		}
		if ( null === \SHCM\Archive\Reader::readFooter( $path ) ) {
			@unlink( $path );
			return;
		}
		// Cancelled after the archive was finished (while uploading it): it is
		// a complete backup. Record it, so retention manages it and the
		// upload can be retried, instead of leaving an unlisted file behind.
		$backup = (array) $job->param( 'backup', array() );
		$fields = array(
			'archive' => basename( $path ),
			'size'    => (int) @filesize( $path ),
			'sha256'  => (string) $job->shared( 'archive_sha256', '' ),
			'local'   => array( 'kept' => true ),
		);
		if ( ! empty( $backup['gdrive'] ) ) {
			$remote = (array) $job->shared( 'remote_upload', array() );
			$fields['remote'] = isset( $remote['status'] ) && 'uploaded' === $remote['status']
				? $this->remoteFields( $remote )
				: array(
					'status' => 'failed',
					'error'  => __( 'Upload cancelled.', 'sh-clone-migration' ),
				);
		}
		$this->history()->record( $id, $fields );
	}

	/**
	 * History entry a job reports to.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function historyId( Job $job ) {
		if ( $job->param( 'upload_only' ) ) {
			return (string) $job->param( 'history_id', '' );
		}
		return $job->id();
	}

	/**
	 * History fields describing the Drive copy.
	 *
	 * @param array $remote Shared remote_upload.
	 * @return array
	 */
	protected function remoteFields( array $remote ) {
		if ( empty( $remote ) ) {
			return array(
				'status' => 'failed',
				'error'  => __( 'The upload did not run.', 'sh-clone-migration' ),
			);
		}
		return array(
			'status'   => isset( $remote['status'] ) ? (string) $remote['status'] : 'failed',
			'file_id'  => isset( $remote['file_id'] ) ? (string) $remote['file_id'] : '',
			'name'     => isset( $remote['name'] ) ? (string) $remote['name'] : '',
			'link'     => isset( $remote['link'] ) ? (string) $remote['link'] : '',
			'verified' => isset( $remote['verified'] ) ? (string) $remote['verified'] : '',
			'error'    => isset( $remote['error'] ) ? (string) $remote['error'] : '',
			'uploaded' => isset( $remote['uploaded_at'] ) ? (int) $remote['uploaded_at'] : 0,
		);
	}

	/**
	 * Send the notification e-mail for a finished run, if wanted.
	 *
	 * @param array|null $entry  History entry.
	 * @param array      $config Configuration.
	 * @return bool Whether a message was sent.
	 */
	public function notify( $entry, array $config ) {
		if ( ! is_array( $entry ) || ! function_exists( 'wp_mail' ) ) {
			return false;
		}
		$status = isset( $entry['status'] ) ? (string) $entry['status'] : 'failed';
		$failed = in_array( $status, array( 'failed', 'partial', 'skipped' ), true );
		if ( 'never' === $config['notify_on'] || ( 'failure' === $config['notify_on'] && ! $failed ) ) {
			return false;
		}
		$to = '' !== (string) $config['notify_email'] ? (string) $config['notify_email'] : (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return false;
		}
		$message = Notifier::compose( $entry, SiteIdentity::label(), admin_url( 'admin.php?page=shcm-schedules' ) );
		return (bool) wp_mail( $to, $message['subject'], $message['body'] );
	}

	/* ------------------------------------------------------------------
	 * Summary for screens and reports
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the Scheduled Backups screen and System Status show.
	 *
	 * @return array
	 */
	public function summary() {
		$config = $this->config();
		$state  = $this->state();
		$last   = $this->history()->latest( array( 'success', 'partial', 'failed', 'skipped', 'cancelled' ) );
		$next   = 'manual' === $config['frequency'] ? null : wp_next_scheduled( self::HOOK );

		return array(
			'config'       => $config,
			'describe'     => Schedule::describe( $config ),
			'next_run'     => false === $next ? null : $next,
			'overdue'      => null !== $state['next_run'] && 'manual' !== $config['frequency'] && (int) $state['next_run'] < time() - 3600 && $this->identityMatches(),
			'last'         => $last,
			'identity_ok'  => $this->identityMatches(),
			'has_password' => $this->hasPassword(),
			'running'      => $this->runningJob(),
			'cron'         => $this->cronHealth(),
			'timezone'     => $this->timezone()->getName(),
			'drive'        => $this->connection()->status(),
			'main_site'    => $this->isMainSite(),
			'problems'     => $this->storageProblems(),
			'weak_keys'    => $this->box()->isDatabaseDerived(),
		);
	}

	/**
	 * Whether this process can read the stored schedule and connection.
	 *
	 * @return bool
	 */
	public function configReadable() {
		$store = $this->store();
		// A damaged history only costs the record of past runs; a damaged
		// schedule or connection would read as "nothing set up".
		return $store->readable( self::DOCUMENT ) && ! $store->damaged( self::DOCUMENT )
			&& $store->readable( Connection::DOCUMENT ) && ! $store->damaged( Connection::DOCUMENT )
			&& $store->readable( History::DOCUMENT );
	}

	/**
	 * What stops this process from using the stored configuration (files
	 * written by WP-CLI under another system account, a directory it cannot
	 * write to), as sentences for the admin.
	 *
	 * @return string[]
	 */
	public function storageProblems() {
		return $this->store()->problems( array( self::DOCUMENT, Connection::DOCUMENT, History::DOCUMENT ) );
	}

	/**
	 * The backup job currently running, if any.
	 *
	 * @return string|null Job id.
	 */
	public function runningJob() {
		foreach ( $this->plugin->jobs()->all( Job::TYPE_EXPORT, 20 ) as $job ) {
			if ( $job->isRunnable() && $this->isBackupJob( $job ) ) {
				return $job->id();
			}
		}
		return null;
	}

	/**
	 * How WP-Cron is doing on this site.
	 *
	 * @return array array( disabled, alternate, loopback_blocked )
	 */
	public function cronHealth() {
		return array(
			'disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate'        => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'loopback_blocked' => (bool) get_transient( BackgroundRunner::BLOCKED_TRANSIENT ),
		);
	}

	/**
	 * Site timezone.
	 *
	 * @return \DateTimeZone
	 */
	public function timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * Only the main site of a network runs backups (a backup covers the
	 * whole network; every site running it would multiply the work).
	 *
	 * @return bool
	 */
	public function isMainSite() {
		return ! function_exists( 'is_multisite' ) || ! is_multisite() || is_main_site();
	}

	/**
	 * Remove every trace of the schedule from WP-Cron (deactivation).
	 *
	 * @return void
	 */
	public static function unscheduleAll() {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::HOOK );
			wp_unschedule_hook( BackgroundRunner::RESUME_HOOK );
		}
	}
}
