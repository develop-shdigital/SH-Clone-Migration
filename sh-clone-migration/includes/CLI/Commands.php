<?php
/**
 * WP-CLI commands.
 *
 * @package SHCM
 */

namespace SHCM\CLI;

use SHCM\Admin\Controller;
use SHCM\Archive\Catalog;
use SHCM\Archive\Reader;
use SHCM\Archive\Verifier;
use SHCM\Core\Plugin;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Clone and migrate a WordPress site from the command line.
 *
 * The CLI is the right tool for very large sites: there is no browser upload
 * to worry about and no request timeout to work around, so a job usually runs
 * to completion in a single invocation.
 */
class Commands {

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
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin     = shcm_bootstrap();
		$this->controller = new Controller( $this->plugin );
	}

	/**
	 * Export this site into a .wpress archive.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : File name for the archive, without the extension.
	 *
	 * [--password=<password>]
	 * : Encrypt the archive with this migration password.
	 *
	 * [--include-core]
	 * : Include wp-admin, wp-includes and the root files.
	 *
	 * [--include-foreign-tables]
	 * : Include tables belonging to another WordPress installation in the same database.
	 *
	 * [--exclude=<patterns>]
	 * : Comma separated exclusion patterns.
	 *
	 * [--porcelain]
	 * : Print only the resulting archive path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shcm export
	 *     wp shcm export --name=staging-snapshot --password=secret
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_export( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the export command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_export( $args, $assoc_args ) {
		unset( $args );

		$password = isset( $assoc_args['password'] ) ? (string) $assoc_args['password'] : '';
		$job      = $this->controller->startExport(
			array(
				'name'                   => isset( $assoc_args['name'] ) ? $assoc_args['name'] : '',
				'password'               => $password,
				'include_core'           => isset( $assoc_args['include-core'] ),
				'include_foreign_tables' => isset( $assoc_args['include-foreign-tables'] ),
				'exclusions'             => isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array(),
			)
		);

		$job = $this->drive( $job['id'], $password, ! isset( $assoc_args['porcelain'] ) );

		if ( 'completed' !== $job['status'] ) {
			\WP_CLI::error( isset( $job['error']['message'] ) ? $job['error']['message'] : 'The export failed.' );
		}

		$path = $this->plugin->jobs()->load( $job['id'] )->param( 'archive_path' );

		if ( isset( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( $path );
			return;
		}

		\WP_CLI::success(
			sprintf( 'Archive written to %1$s (%2$s).', $path, Bytes::format( (int) filesize( $path ) ) )
		);
	}

	/**
	 * Restore a .wpress archive onto this site.
	 *
	 * ## OPTIONS
	 *
	 * <archive>
	 * : Path to the archive, or the file name of an archive already in the storage directory.
	 *
	 * [--password=<password>]
	 * : Migration password for an encrypted archive.
	 *
	 * [--url-to=<url>]
	 * : Destination URL. Defaults to this installation's home URL.
	 *
	 * [--mode=<mode>]
	 * : replace (default) or merge.
	 *
	 * [--no-rollback]
	 * : Skip the database rollback point.
	 *
	 * [--no-verify]
	 * : Skip the full checksum verification before restoring.
	 *
	 * [--no-url-replace]
	 * : Do not rewrite the source URL.
	 *
	 * [--skip-core]
	 * : Do not restore WordPress core files contained in the archive.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shcm import backup.wpress --yes
	 *     wp shcm import /tmp/site.wpress --url-to=https://new.example.com --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_import( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the import command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_import( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Specify the archive to restore.' );
		}

		$catalog = new Catalog( $this->plugin->storage() );
		$name    = $catalog->resolve( basename( $args[0] ) );

		if ( null === $name ) {
			$adopted = $this->controller->uploader()->adopt( $args[0] );
			$name    = $adopted['path'];
		}

		\WP_CLI::confirm(
			'This will replace this site\'s database and files with the archive contents. Continue?',
			$assoc_args
		);

		$password = isset( $assoc_args['password'] ) ? (string) $assoc_args['password'] : '';

		$job = $this->controller->startImport(
			array(
				'archive'               => basename( $name ),
				'password'              => $password,
				'confirmed'             => true,
				'destination_url'       => isset( $assoc_args['url-to'] ) ? $assoc_args['url-to'] : '',
				'import_mode'           => isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : 'replace',
				'create_rollback_point' => ! isset( $assoc_args['no-rollback'] ),
				'verify_archive'        => ! isset( $assoc_args['no-verify'] ),
				'replace_urls'          => ! isset( $assoc_args['no-url-replace'] ),
				'skip_core'             => isset( $assoc_args['skip-core'] ),
			)
		);

		$job = $this->drive( $job['id'], $password, true );

		if ( 'completed' !== $job['status'] ) {
			\WP_CLI::error( isset( $job['error']['message'] ) ? $job['error']['message'] : 'The restore failed.' );
		}

		$this->printChecks( $job );
		\WP_CLI::success( 'Migration completed.' );
	}

	/**
	 * Verify an archive.
	 *
	 * ## OPTIONS
	 *
	 * <archive>
	 * : Archive file name or path.
	 *
	 * [--password=<password>]
	 * : Migration password for an encrypted archive.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_verify( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the verify command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_verify( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Specify the archive to verify.' );
		}

		$catalog = new Catalog( $this->plugin->storage() );
		$path    = $catalog->resolve( basename( $args[0] ) );
		if ( null === $path ) {
			$path = $args[0];
		}
		if ( ! is_file( $path ) ) {
			\WP_CLI::error( 'That archive could not be found.' );
		}

		$reader   = new Reader( $path, isset( $assoc_args['password'] ) ? $assoc_args['password'] : '' );
		$verifier = new Verifier( $reader );
		$result   = $verifier->verifyAll();

		foreach ( $result['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}

		if ( ! $result['ok'] ) {
			\WP_CLI::error( 'Migration archive validation failed. The archive appears to be incomplete or corrupted.' );
		}

		\WP_CLI::success(
			sprintf(
				'Archive verified: %1$d entries, %2$s of content.',
				$result['checked'],
				Bytes::format( $result['bytes'] )
			)
		);
	}

	/**
	 * List the archives stored on this server.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default), json, csv or yaml.
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_list_( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the list command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_list_( $args, $assoc_args ) {
		unset( $args );

		$catalog = new Catalog( $this->plugin->storage() );
		$rows    = array();
		foreach ( $catalog->all() as $archive ) {
			$rows[] = array(
				'name'      => $archive['name'],
				'size'      => Bytes::format( $archive['size'] ),
				'created'   => gmdate( 'Y-m-d H:i', $archive['created'] ),
				'source'    => $archive['source'],
				'files'     => $archive['files'],
				'tables'    => $archive['tables'],
				'encrypted' => $archive['encrypted'] ? 'yes' : 'no',
				'complete'  => $archive['complete'] ? 'yes' : 'no',
			);
		}

		if ( empty( $rows ) ) {
			\WP_CLI::line( 'No archives stored.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'name', 'size', 'created', 'source', 'files', 'tables', 'encrypted', 'complete' )
		);
	}

	/**
	 * Show migration job status.
	 *
	 * ## OPTIONS
	 *
	 * [<job>]
	 * : Job id. Defaults to the most recent job.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_status( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the status command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_status( $args, $assoc_args ) {
		unset( $assoc_args );

		$store = $this->plugin->jobs();
		if ( ! empty( $args[0] ) ) {
			$job = $store->load( $args[0] );
		} else {
			$jobs = $store->all( null, 1 );
			$job  = empty( $jobs ) ? null : $jobs[0];
		}

		if ( null === $job ) {
			\WP_CLI::line( 'No migration jobs found.' );
			return;
		}

		\WP_CLI::line( sprintf( 'Job:      %s', $job->id() ) );
		\WP_CLI::line( sprintf( 'Type:     %s', $job->type() ) );
		\WP_CLI::line( sprintf( 'Status:   %s', $job->status() ) );
		\WP_CLI::line( sprintf( 'Stage:    %s', $job->stage() ) );
		\WP_CLI::line( sprintf( 'Progress: %.1f%%', (float) $job->get( 'progress' ) ) );
		\WP_CLI::line( sprintf( 'Message:  %s', $job->get( 'message' ) ) );

		if ( $job->get( 'error' ) ) {
			\WP_CLI::warning( $job->get( 'error' )['message'] );
		}
		foreach ( array_slice( (array) $job->get( 'warnings' ), -10 ) as $warning ) {
			\WP_CLI::warning( $warning['message'] );
		}
	}

	/**
	 * Resume an interrupted migration job.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * [--password=<password>]
	 * : Migration password for an encrypted archive.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function resume( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_resume( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the resume command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_resume( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Specify the job to resume.' );
		}
		$job = $this->drive( $args[0], isset( $assoc_args['password'] ) ? $assoc_args['password'] : '', true );

		if ( 'completed' !== $job['status'] ) {
			\WP_CLI::error( isset( $job['error']['message'] ) ? $job['error']['message'] : 'The job did not complete.' );
		}
		\WP_CLI::success( 'Job completed.' );
	}

	/**
	 * Cancel a running migration job.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function cancel( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_cancel( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the cancel command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_cancel( $args, $assoc_args ) {
		unset( $assoc_args );
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Specify the job to cancel.' );
		}
		$this->controller->cancel( $args[0] );
		\WP_CLI::success( 'Job cancelled.' );
	}

	/**
	 * Restore the database snapshot taken before an import.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : The import job that created the rollback point.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shcm rollback 20260911-081302-a06f6bbd --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function rollback( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_rollback( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the rollback command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_rollback( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Specify the import job to roll back.' );
		}

		\WP_CLI::confirm(
			'This will replace the current database with the snapshot taken before that import. Continue?',
			$assoc_args
		);

		$job = $this->controller->startRollback( $args[0] );
		$job = $this->drive( $job['id'], '', true );

		if ( 'completed' !== $job['status'] ) {
			\WP_CLI::error( isset( $job['error']['message'] ) ? $job['error']['message'] : 'The rollback failed.' );
		}

		\WP_CLI::success( 'Database rolled back.' );
	}

	/**
	 * Run a serialization aware search and replace across the database.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : Value to look for.
	 *
	 * <replace>
	 * : Replacement value.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * @subcommand search-replace
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function search_replace( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_search_replace( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the search_replace command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_search_replace( $args, $assoc_args ) {
		if ( count( $args ) < 2 ) {
			\WP_CLI::error( 'Specify both the search and the replacement value.' );
		}

		$job = $this->controller->startReplace(
			array(
				'search'  => $args[0],
				'replace' => $args[1],
				'dry_run' => isset( $assoc_args['dry-run'] ),
			)
		);

		$job    = $this->drive( $job['id'], '', true );
		$report = isset( $job['report']['replace'] ) ? $job['report']['replace'] : null;

		if ( $report && isset( $report['stats'] ) ) {
			$stats = $report['stats'];
			\WP_CLI::line( sprintf( 'Tables scanned:              %d', $stats['tables_scanned'] ) );
			\WP_CLI::line( sprintf( 'Rows scanned:                %d', $stats['rows_scanned'] ) );
			\WP_CLI::line( sprintf( 'Values changed:              %d', $stats['values_changed'] ) );
			\WP_CLI::line( sprintf( 'Serialized values repaired:  %d', $stats['serialized_repaired'] ) );
			\WP_CLI::line( sprintf( 'Unparsable values skipped:   %d', $stats['serialized_failed'] ) );
			\WP_CLI::line( sprintf( 'Source references remaining: %d', $stats['remaining_refs'] ) );
		}

		if ( 'completed' !== $job['status'] ) {
			\WP_CLI::error( isset( $job['error']['message'] ) ? $job['error']['message'] : 'The replacement failed.' );
		}

		\WP_CLI::success( isset( $assoc_args['dry-run'] ) ? 'Preview complete, nothing was written.' : 'Replacement complete.' );
	}

	/**
	 * Show the system status report.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function doctor( $args, $assoc_args ) {
		$this->guard(
			function () use ( $args, $assoc_args ) {
				$this->run_doctor( $args, $assoc_args );
			}
		);
	}

	/**
	 * Implementation of the doctor command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	protected function run_doctor( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$report = $this->controller->systemStatus();
		foreach ( $report['rows'] as $row ) {
			\WP_CLI::line( sprintf( '%-32s %s', $row['label'] . ':', $row['value'] ) );
		}
		foreach ( $report['warnings'] as $warning ) {
			if ( 'error' === $warning['level'] ) {
				\WP_CLI::warning( $warning['message'] );
			} else {
				\WP_CLI::line( '! ' . $warning['message'] );
			}
		}
	}

	/**
	 * Run a job to completion, printing progress.
	 *
	 * @param string $job_id   Job id.
	 * @param string $password Migration password.
	 * @param bool   $progress Whether to print progress.
	 * @return array Final job snapshot.
	 */
	protected function drive( $job_id, $password, $progress = true ) {
		$store  = $this->plugin->jobs();
		$runner = $this->plugin->runner();
		$last   = '';

		while ( true ) {
			$job = $store->load( $job_id );
			if ( null === $job ) {
				\WP_CLI::error( 'That migration job no longer exists.' );
			}
			if ( $job->isFinished() ) {
				return $this->controller->snapshot( $job );
			}

			$job->setRuntime( 'password', $password );
			$job = $runner->tick( $job, $this->budget() );

			$line = sprintf( '[%5.1f%%] %s', (float) $job->get( 'progress' ), $job->get( 'message' ) );
			if ( $progress && $line !== $last ) {
				\WP_CLI::line( $line );
				$last = $line;
			}

			if ( $job->isFinished() ) {
				return $this->controller->snapshot( $job );
			}
		}
	}

	/**
	 * Run a command body, turning any exception into a clean CLI error.
	 *
	 * A stack trace is the wrong answer to "the password is wrong".
	 *
	 * @param callable $callback Command body.
	 * @return mixed
	 */
	protected function guard( callable $callback ) {
		try {
			return call_user_func( $callback );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Budget for one CLI tick.
	 *
	 * The command line has no request timeout, so the default slice is long;
	 * a configured time budget still wins, which keeps CLI and browser runs
	 * behaving identically when a host needs short slices.
	 *
	 * @return Budget
	 */
	protected function budget() {
		$configured = $this->plugin->settings()->getInt( 'time_budget' );
		return Budget::create( $configured > 0 ? $configured : 60, $this->plugin->settings()->getInt( 'memory_guard', 80 ) );
	}

	/**
	 * Print the post migration checks.
	 *
	 * @param array $job Job snapshot.
	 * @return void
	 */
	protected function printChecks( array $job ) {
		$checks = isset( $job['report']['verification'] ) ? $job['report']['verification'] : array();
		foreach ( (array) $checks as $check ) {
			\WP_CLI::line(
				sprintf(
					'%-28s %s %s',
					$check['label'] . ':',
					$check['pass'] ? 'PASS' : 'FAIL',
					$check['detail']
				)
			);
		}
		foreach ( array_slice( (array) $job['warnings'], -10 ) as $warning ) {
			\WP_CLI::warning( $warning['message'] );
		}
	}
}
