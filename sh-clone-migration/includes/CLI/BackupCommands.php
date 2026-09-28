<?php
/**
 * WP-CLI commands for backups.
 *
 * @package SHCM
 */

namespace SHCM\CLI;

use SHCM\Admin\Controller;
use SHCM\Backup\Schedule;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Make, schedule and inspect backups.
 *
 * ## EXAMPLES
 *
 *     # Back up now (to Google Drive too, if that is configured)
 *     wp shcm backup now
 *
 *     # System cron (every 5 minutes): run the scheduled backup when due
 *     wp shcm backup run --quiet
 */
class BackupCommands {

	use DrivesJobs;

	/**
	 * Plugin container.
	 *
	 * @var \SHCM\Core\Plugin
	 */
	protected $plugin;

	/**
	 * Job controller.
	 *
	 * @var Controller
	 */
	protected $controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin     = shcm_bootstrap();
		$this->controller = new Controller( $this->plugin );
	}

	/**
	 * Make a backup now, with the scheduled backup's settings.
	 *
	 * ## OPTIONS
	 *
	 * [--contents=<contents>]
	 * : What to back up: full, database or files. Default: as scheduled.
	 *
	 * [--upload]
	 * : Also upload to Google Drive (it must be connected).
	 *
	 * [--no-upload]
	 * : Keep this backup on the server only.
	 *
	 * [--porcelain]
	 * : Print only the archive path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shcm backup now
	 *     wp shcm backup now --contents=database --no-upload
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function now( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_now( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the now command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_now( $args, $assoc_args ) {
		unset( $args );
		$overrides = array();
		if ( isset( $assoc_args['contents'] ) ) {
			$overrides['contents'] = (string) $assoc_args['contents'];
		}
		if ( isset( $assoc_args['upload'] ) ) {
			$overrides['gdrive'] = (bool) $assoc_args['upload'];
		}
		$job = $this->plugin->backups()->startBackup( 'cli', $overrides );
		$this->finish( $job->id(), isset( $assoc_args['porcelain'] ) );
	}

	/**
	 * Run the scheduled backup if it is due, and finish any backup that is
	 * still running. Meant for a system cron job.
	 *
	 * ## OPTIONS
	 *
	 * [--quiet]
	 * : Print nothing when there is nothing to do.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shcm backup run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_run( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the run command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_run( $args, $assoc_args ) {
		unset( $args );
		$backups = $this->plugin->backups();
		$backups->reconcile();

		$running = $backups->runningJob();
		if ( null !== $running ) {
			\WP_CLI::line( sprintf( 'Continuing backup %s.', $running ) );
			$this->finish( $running, false );
			return;
		}

		$job = $backups->startScheduled();
		if ( null === $job ) {
			$outcome = $backups->lastOutcome();
			switch ( $outcome['result'] ) {
				case 'postponed':
					\WP_CLI::warning( 'Scheduled backup postponed by 15 minutes: ' . $outcome['message'] );
					return;
				case 'skipped':
				case 'paused':
				case 'failed':
					// Cron mails the output of a failing job: this needs a look.
					\WP_CLI::error( 'Scheduled backup ' . $outcome['result'] . ': ' . $outcome['message'] );
					return;
			}
			if ( ! isset( $assoc_args['quiet'] ) ) {
				$summary = $backups->summary();
				\WP_CLI::line( 'Nothing is due. ' . $summary['describe'] . ( $summary['next_run'] ? ', next run ' . wp_date( 'Y-m-d H:i', (int) $summary['next_run'] ) : '' ) . '.' );
			}
			return;
		}
		\WP_CLI::line( sprintf( 'Scheduled backup %s started.', $job->id() ) );
		$this->finish( $job->id(), false );
	}

	/**
	 * Show the backup schedule.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shcm backup schedule
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function schedule( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$this->guard(
			function () {
				$summary = $this->plugin->backups()->summary();
				$config  = $summary['config'];
				$lines   = array(
					'Schedule'          => $summary['describe'] . ' (' . $summary['timezone'] . ')',
					'Next run'          => $summary['next_run'] ? wp_date( 'Y-m-d H:i', (int) $summary['next_run'] ) : '—',
					'Contents'          => $config['contents'] . ( $config['include_core'] ? ' + WordPress core' : '' ),
					'Extra exclusions'  => empty( $config['exclusions'] ) ? '—' : implode( ', ', $config['exclusions'] ),
					'Keep on server'    => (string) $config['keep_local'],
					'Google Drive'      => $config['gdrive'] ? 'yes, keep ' . $config['keep_remote'] : 'no',
					'Drive connection'  => $summary['drive']['state'] . ( '' !== (string) $summary['drive']['account'] ? ' (' . $summary['drive']['account'] . ')' : '' ),
					'Encrypted'         => $config['encrypt'] ? ( $summary['has_password'] ? 'yes' : 'yes, but no readable password is stored' ) : 'no',
					'E-mail'            => $config['notify_on'] . ( '' !== $config['notify_email'] ? ' to ' . $config['notify_email'] : '' ),
					'Last run'          => $summary['last'] ? $summary['last']['status'] . ' ' . wp_date( 'Y-m-d H:i', (int) ( isset( $summary['last']['started'] ) ? $summary['last']['started'] : $summary['last']['created'] ) ) : '—',
					'WP-Cron'           => $summary['cron']['disabled'] ? 'DISABLE_WP_CRON is set: run `wp shcm backup run` from a system cron job' : 'enabled',
				);
				if ( ! $summary['identity_ok'] ) {
					$lines['Attention'] = 'The schedule was set up on another copy of this site and is paused here. Confirm it on the Scheduled Backups screen.';
				}
				foreach ( $lines as $label => $value ) {
					\WP_CLI::line( sprintf( '%-18s %s', $label . ':', $value ) );
				}
			}
		);
	}

	/**
	 * List recent backup runs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many runs to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table (default), json, csv or yaml.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function history( $args, $assoc_args ) {
		unset( $args );
		$this->guard(
			function () use ( $assoc_args ) {
				$rows = array();
				foreach ( $this->plugin->backups()->history()->all( isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20 ) as $entry ) {
					$remote = isset( $entry['remote'] ) && is_array( $entry['remote'] ) ? $entry['remote'] : array();
					$rows[] = array(
						'id'      => $entry['id'],
						'started' => gmdate( 'Y-m-d H:i', (int) ( isset( $entry['started'] ) ? $entry['started'] : $entry['created'] ) ),
						'trigger' => isset( $entry['trigger'] ) ? $entry['trigger'] : '',
						'status'  => isset( $entry['status'] ) ? $entry['status'] : '',
						'archive' => isset( $entry['archive'] ) ? $entry['archive'] : '',
						'size'    => isset( $entry['size'] ) ? Bytes::format( (int) $entry['size'] ) : '',
						'drive'   => isset( $remote['status'] ) ? $remote['status'] : '',
						'error'   => isset( $entry['error'] ) ? $entry['error'] : '',
					);
				}
				if ( empty( $rows ) ) {
					\WP_CLI::line( 'No backups have run yet.' );
					return;
				}
				\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'id', 'started', 'trigger', 'status', 'archive', 'size', 'drive', 'error' ) );
			}
		);
	}

	/**
	 * Upload an archive from this server to Google Drive.
	 *
	 * ## OPTIONS
	 *
	 * <archive>
	 * : Archive file name (see `wp shcm list`).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function upload( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->guard(
			function () use ( $args ) {
				$archive = isset( $args[0] ) ? basename( (string) $args[0] ) : '';
				$entry   = $this->plugin->backups()->history()->forArchive( $archive );
				$job     = $this->plugin->backups()->startUpload( $archive, null !== $entry ? 'backup' : 'manual', null !== $entry ? (string) $entry['id'] : '' );
				$final   = $this->drive( $job->id(), '' );
				if ( 'completed' !== $final['status'] ) {
					\WP_CLI::error( isset( $final['error']['message'] ) ? $final['error']['message'] : 'The upload failed.' );
				}
				$remote = isset( $final['report']['remote_upload'] ) ? (array) $final['report']['remote_upload'] : array();
				\WP_CLI::success( sprintf( 'Uploaded to Google Drive%s.', ! empty( $remote['link'] ) ? ': ' . $remote['link'] : '' ) );
			}
		);
	}

	/**
	 * Drive a backup job to the end and report.
	 *
	 * @param string $job_id    Job id.
	 * @param bool   $porcelain Print only the archive path.
	 * @return void
	 */
	protected function finish( $job_id, $porcelain ) {
		$final = $this->drive( $job_id, '', ! $porcelain );
		if ( 'completed' !== $final['status'] ) {
			\WP_CLI::error( isset( $final['error']['message'] ) ? $final['error']['message'] : 'The backup failed.' );
		}
		$stored = $this->plugin->jobs()->load( $job_id );
		$path   = null !== $stored ? (string) $stored->param( 'archive_path' ) : '';
		if ( $porcelain ) {
			\WP_CLI::line( $path );
			return;
		}

		$report = $final['report'];
		$remote = isset( $report['remote_upload'] ) ? (array) $report['remote_upload'] : array();
		$backup = isset( $report['backup'] ) ? (array) $report['backup'] : array();
		$kept   = '' !== $path && is_file( $path );
		\WP_CLI::line( sprintf( 'Archive:  %s%s', basename( $path ), $kept ? '' : ' (removed from this server by retention: it is on Google Drive)' ) );
		\WP_CLI::line( sprintf( 'Size:     %1$s bytes (%2$s)', number_format( (int) $report['archive_size'] ), Bytes::format( (int) $report['archive_size'] ) ) );
		if ( '' !== (string) $report['sha256'] ) {
			\WP_CLI::line( 'SHA-256:  ' . $report['sha256'] );
		}
		$db = is_array( $report['database'] ) ? $report['database'] : array();
		\WP_CLI::line( ! empty( $db['included'] ) ? sprintf( 'Database: %1$d tables, %2$s rows', (int) $db['tables'], number_format( (int) $db['rows'] ) ) : 'Database: not included' );
		if ( ! empty( $backup['gdrive'] ) ) {
			if ( isset( $remote['status'] ) && 'uploaded' === $remote['status'] ) {
				\WP_CLI::line( sprintf( 'Drive:    uploaded and verified (%1$s)%2$s', $remote['verified'], ! empty( $remote['link'] ) ? ' ' . $remote['link'] : '' ) );
			} else {
				\WP_CLI::warning( 'Not uploaded to Google Drive: ' . ( isset( $remote['error'] ) ? $remote['error'] : 'unknown reason' ) . ' Retry with: wp shcm backup upload ' . basename( $path ) );
			}
		}
		foreach ( array_slice( (array) $final['warnings'], -10 ) as $warning ) {
			\WP_CLI::warning( $warning['message'] );
		}
		\WP_CLI::success( 'Backup completed.' );
	}
}
