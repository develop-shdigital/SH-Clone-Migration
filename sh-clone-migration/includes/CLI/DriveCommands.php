<?php
/**
 * WP-CLI commands for the Google Drive connection.
 *
 * @package SHCM
 */

namespace SHCM\CLI;

use SHCM\Admin\BackupController;
use SHCM\Admin\Controller;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and manage the Google Drive connection used for backups.
 *
 * Connecting needs a browser (Google's consent screen): use the Scheduled
 * Backups screen for that.
 */
class DriveCommands {

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
	 * Show the connection.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$this->guard(
			function () {
				$status = $this->screen()->driveStatus();
				$labels = array(
					'not_configured' => 'not set up (no OAuth client ID/secret)',
					'not_connected'  => 'not connected',
					'connected'      => 'connected',
					'reconnect'      => 'needs to be reconnected',
					'other_site'     => 'belongs to another copy of this site',
				);
				$lines = array(
					'State'        => isset( $labels[ $status['state'] ] ) ? $labels[ $status['state'] ] : $status['state'],
					'Account'      => '' !== $status['account'] ? $status['account'] : '—',
					'Folder'       => '' !== $status['folder'] ? $status['folder'] : '—',
					'Client ID'    => '' !== $status['client_id'] ? $status['client_id'] : '—',
					'Redirect URI' => $status['redirect_uri'],
				);
				if ( '' !== $status['error'] ) {
					$lines['Error'] = $status['error'];
				}
				foreach ( $lines as $label => $value ) {
					\WP_CLI::line( sprintf( '%-14s %s', $label . ':', $value ) );
				}
			}
		);
	}

	/**
	 * Check the connection: token, account, free space and backup folder.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$this->guard(
			function () {
				$result = $this->screen()->test();
				\WP_CLI::success( $result['message'] );
			}
		);
	}

	/**
	 * List this site's backups on Google Drive.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default), json, csv or yaml.
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		unset( $args );
		$this->guard(
			function () use ( $assoc_args ) {
				$files = $this->screen()->listDrive();
				$rows  = array();
				foreach ( $files['files'] as $file ) {
					$rows[] = array(
						'name'    => $file['name'],
						'created' => gmdate( 'Y-m-d H:i', (int) $file['created'] ),
						'size'    => Bytes::format( (int) $file['size'] ),
						'kind'    => $file['kind'],
						'id'      => $file['id'],
					);
				}
				if ( empty( $rows ) ) {
					\WP_CLI::line( 'No backups of this site on Google Drive.' );
					return;
				}
				\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'name', 'created', 'size', 'kind', 'id' ) );
			}
		);
	}

	/**
	 * Disconnect Google Drive and revoke the plugin's access.
	 *
	 * Backups already on Google Drive stay there.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function disconnect( $args, $assoc_args ) {
		unset( $args );
		\WP_CLI::confirm( 'Disconnect Google Drive? New backups will be kept on this server only.', $assoc_args );
		$this->guard(
			function () {
				$status = $this->screen()->disconnect();
				if ( ! empty( $status['notice'] ) ) {
					\WP_CLI::warning( $status['notice'] );
				}
				\WP_CLI::success( 'Google Drive disconnected.' );
			}
		);
	}

	/**
	 * Screen logic, shared with the admin UI.
	 *
	 * @return BackupController
	 */
	protected function screen() {
		return new BackupController( $this->plugin, $this->controller );
	}
}
