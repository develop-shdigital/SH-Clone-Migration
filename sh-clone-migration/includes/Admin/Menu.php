<?php
/**
 * Admin menu and screens.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Archive\Catalog;
use SHCM\Core\Plugin;
use SHCM\Security\Capabilities;
use SHCM\Security\Request;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin screens and their assets.
 */
class Menu {

	const SLUG = 'shcm';

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Hook suffixes of our screens.
	 *
	 * @var string[]
	 */
	protected $screens = array();

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
		add_action( 'admin_menu', array( $this, 'addPages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Add the menu pages.
	 *
	 * @return void
	 */
	public function addPages() {
		// On multisite only network administrators may migrate, so the menu is
		// registered against that capability rather than the custom one.
		$capability = is_multisite() ? 'manage_network_options' : Capabilities::required();
		if ( ! is_multisite() && ! Capabilities::currentUserCan() ) {
			$capability = 'manage_options';
		}

		$this->screens[] = add_menu_page(
			__( 'SH Clone Migration', 'sh-clone-migration' ),
			__( 'Clone Migration', 'sh-clone-migration' ),
			$capability,
			self::SLUG,
			array( $this, 'renderExport' ),
			'dashicons-migrate',
			76
		);

		$pages = array(
			'shcm'                => __( 'Export', 'sh-clone-migration' ),
			'shcm-import'         => __( 'Import', 'sh-clone-migration' ),
			'shcm-backups'        => __( 'Backups', 'sh-clone-migration' ),
			'shcm-schedules'      => __( 'Scheduled Backups', 'sh-clone-migration' ),
			'shcm-tools'          => __( 'Search &amp; Replace', 'sh-clone-migration' ),
			'shcm-settings'       => __( 'Settings', 'sh-clone-migration' ),
			'shcm-system-status'  => __( 'System Status', 'sh-clone-migration' ),
		);

		$callbacks = array(
			'shcm'               => 'renderExport',
			'shcm-import'        => 'renderImport',
			'shcm-backups'       => 'renderBackups',
			'shcm-schedules'     => 'renderSchedules',
			'shcm-tools'         => 'renderTools',
			'shcm-settings'      => 'renderSettings',
			'shcm-system-status' => 'renderSystemStatus',
		);

		if ( ! \SHCM\Core\Plugin::backupsAvailable() ) {
			unset( $pages['shcm-schedules'] );
		}

		foreach ( $pages as $slug => $title ) {
			$this->screens[] = add_submenu_page(
				self::SLUG,
				$title,
				$title,
				$capability,
				$slug,
				array( $this, $callbacks[ $slug ] )
			);
		}
	}

	/**
	 * Enqueue the admin assets on our screens only.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, $this->screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'shcm-admin',
			SHCM_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SHCM_VERSION
		);
		wp_enqueue_script(
			'shcm-admin',
			SHCM_PLUGIN_URL . 'admin/js/admin.js',
			array(),
			SHCM_VERSION,
			true
		);

		$settings = $this->plugin->settings();
		$caps     = $this->plugin->environment()->capabilities();

		wp_localize_script(
			'shcm-admin',
			'shcmData',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'endpointUrl'  => SHCM_PLUGIN_URL . 'endpoint.php',
				'nonce'        => Request::nonce(),
				'downloadUrl'  => wp_nonce_url( admin_url( 'admin-post.php?action=shcm_download' ), 'shcm_download' ),
				'logUrl'       => wp_nonce_url( admin_url( 'admin-post.php?action=shcm_download_log' ), 'shcm_download_log' ),
				'homeUrl'      => untrailingslashit( home_url() ),
				'chunkSize'    => $this->chunkSize( $settings, $caps ),
				'pollInterval' => 1000,
				'strings'      => $this->strings(),
			)
		);
	}

	/**
	 * Chunk size that stays below whatever the server accepts.
	 *
	 * @param \SHCM\Core\Settings $settings Settings.
	 * @param array               $caps     Capabilities.
	 * @return int
	 */
	protected function chunkSize( $settings, array $caps ) {
		$configured = $settings->getInt( 'upload_chunk_size', 5242880 );
		$limits     = array_filter(
			array(
				$caps['upload_max'] > 0 ? (int) ( $caps['upload_max'] * 0.8 ) : 0,
				$caps['post_max'] > 0 ? (int) ( $caps['post_max'] * 0.8 ) : 0,
			)
		);
		if ( ! empty( $limits ) ) {
			$configured = min( $configured, min( $limits ) );
		}
		return max( 262144, (int) $configured );
	}

	/**
	 * Strings handed to the JavaScript.
	 *
	 * @return array
	 */
	protected function strings() {
		return array(
			'confirmImport'   => __( "This will replace this website's database and files with the contents of the archive. Existing data may be overwritten. Continue?", 'sh-clone-migration' ),
			'confirmDelete'   => __( 'Delete this archive permanently?', 'sh-clone-migration' ),
			'confirmCancel'   => __( 'Cancel this migration?', 'sh-clone-migration' ),
			'cancelling'      => __( 'Cancelling… (stopping after the current step)', 'sh-clone-migration' ),
			'uploading'       => __( 'Uploading', 'sh-clone-migration' ),
			'uploadComplete'  => __( 'Upload complete', 'sh-clone-migration' ),
			'verifying'       => __( 'Verifying', 'sh-clone-migration' ),
			'verified'        => __( 'Archive verified', 'sh-clone-migration' ),
			'failed'          => __( 'Failed', 'sh-clone-migration' ),
			'retrying'        => __( 'Connection problem, retrying', 'sh-clone-migration' ),
			'passwordNeeded'  => __( 'This archive is encrypted. Enter the migration password.', 'sh-clone-migration' ),
			'genericError'    => __( 'Something went wrong. Check the migration log for details.', 'sh-clone-migration' ),
			'saved'           => __( 'Saved.', 'sh-clone-migration' ),
			'confirmDisconnect' => __( 'Disconnect Google Drive? Backups already on Google Drive stay there; new backups are kept on this server only.', 'sh-clone-migration' ),
			'backupRunning'   => __( 'Backing up', 'sh-clone-migration' ),
			'backupDone'      => __( 'Backup completed', 'sh-clone-migration' ),
			'backupPartial'   => __( 'Backup kept on this server, but not uploaded to Google Drive', 'sh-clone-migration' ),
			'backupFailed'    => __( 'Backup failed', 'sh-clone-migration' ),
			'uploadDone'      => __( 'Uploaded to Google Drive', 'sh-clone-migration' ),
			'copied'          => __( 'Copied', 'sh-clone-migration' ),
			'passwordMismatch' => __( 'The two passwords do not match.', 'sh-clone-migration' ),
			'confirmSendDrive' => __( 'Upload a copy of this archive to Google Drive? The upload runs in the background; you can follow it on the Scheduled Backups screen.', 'sh-clone-migration' ),
			'schedulesUrl'    => admin_url( 'admin.php?page=shcm-schedules' ),
			'ui'              => array(
				'tables'           => __( 'Tables', 'sh-clone-migration' ),
				'notIncluded'      => __( 'not included', 'sh-clone-migration' ),
				'rows'             => __( 'Rows', 'sh-clone-migration' ),
				'files'            => __( 'Files', 'sh-clone-migration' ),
				'sourceSize'       => __( 'Source size', 'sh-clone-migration' ),
				'skipped'          => __( 'Skipped', 'sh-clone-migration' ),
				'archive'          => __( 'Archive', 'sh-clone-migration' ),
				/* translators: 1: size, 2: number of bytes */
				'sizeWithBytes'    => __( '%1$s (%2$s bytes)', 'sh-clone-migration' ),
				'valuesUpdated'    => __( 'Values updated', 'sh-clone-migration' ),
				/* translators: 1: warnings shown, 2: total warnings */
				'warningsShown'    => __( 'Showing the last %1$s of %2$s warnings. The migration log lists every one of them.', 'sh-clone-migration' ),
				/* translators: number of warnings */
				'warningsTitle'    => __( 'Warnings (%s)', 'sh-clone-migration' ),
				'archiveSize'      => __( 'Archive size', 'sh-clone-migration' ),
				/* translators: number of bytes */
				'exactlyBytes'     => __( 'exactly %s bytes', 'sh-clone-migration' ),
				'database'         => __( 'Database', 'sh-clone-migration' ),
				'dbNotIncluded'    => __( 'NOT included in this archive', 'sh-clone-migration' ),
				'dbNoTables'       => __( 'No tables were exported', 'sh-clone-migration' ),
				'dbIncluded'       => __( 'Included', 'sh-clone-migration' ),
				/* translators: 1: tables, 2: rows */
				'dbCounts'         => __( '%1$s tables, %2$s rows', 'sh-clone-migration' ),
				/* translators: size */
				'dbSql'            => __( '%s of SQL', 'sh-clone-migration' ),
				'tablePrefix'      => __( 'table prefix', 'sh-clone-migration' ),
				/* translators: number of files */
				'skippedSeeLog'    => __( '%s skipped (see the warnings and the log)', 'sh-clone-migration' ),
				'verification'     => __( 'Verification', 'sh-clone-migration' ),
				/* translators: number of entries */
				'verifiedFull'     => __( 'The archive was read back and every one of its %s entries matched its checksum.', 'sh-clone-migration' ),
				'verifiedQuick'    => __( 'Structure check only (quick mode in the settings); entry checksums were not read back.', 'sh-clone-migration' ),
				'howToCheck'       => __( 'How to check the downloaded file', 'sh-clone-migration' ),
				/* translators: number of bytes */
				'checkSize'        => __( 'The downloaded file must be exactly %s bytes. To check it:', 'sh-clone-migration' ),
				/* translators: number of bytes */
				'checkSizeAndHash' => __( 'The downloaded file must be exactly %s bytes and its SHA-256 must be the one shown above. To compute it:', 'sh-clone-migration' ),
				'importChecks'     => __( 'The import checks every entry again before it changes anything, so a damaged copy is always refused.', 'sh-clone-migration' ),
				'sizeHidden'       => __( 'This server may hide the file size from browsers and download managers (they then say the size is unknown and cannot resume). The download is still complete when its size and SHA-256 match. See System Status for the one-time server rule that fixes this.', 'sh-clone-migration' ),
			),
			'sched'           => array(
				'paused'             => __( 'Paused', 'sh-clone-migration' ),
				/* translators: %s: time until the next backup, e.g. "12 hours" */
				'nextIn'             => __( 'in %s', 'sh-clone-migration' ),
				'anyMoment'          => __( 'Any moment now', 'sh-clone-migration' ),
				'confirmCancel'      => __( 'Cancel this backup?', 'sh-clone-migration' ),
				'uploadFailed'       => __( 'Upload to Google Drive failed (the archive is still on this server)', 'sh-clone-migration' ),
				'weakKeys'           => __( 'The security keys in wp-config.php are missing or still the sample values, so the Google Drive tokens and the backup password are sealed with keys kept in the database, which is inside every backup. Add real keys to wp-config.php (or define SHCM_SECRET_KEY), then reconnect and enter the password again.', 'sh-clone-migration' ),
				/* translators: 1: schedule, e.g. "Daily at 03:15", 2: date and time of the next backup */
				'nextLabel'          => __( '%1$s · next %2$s', 'sh-clone-migration' ),
				'onDemand'           => __( 'On demand', 'sh-clone-migration' ),
				'statusOk'           => __( 'OK', 'sh-clone-migration' ),
				'statusPartial'      => __( 'Not uploaded', 'sh-clone-migration' ),
				'statusFailed'       => __( 'Failed', 'sh-clone-migration' ),
				'statusSkipped'      => __( 'Skipped', 'sh-clone-migration' ),
				'statusCancelled'    => __( 'Cancelled', 'sh-clone-migration' ),
				'statusRunning'      => __( 'Running', 'sh-clone-migration' ),
				'lastBackup'         => __( 'Last backup', 'sh-clone-migration' ),
				'noBackupYet'        => __( 'No backup yet', 'sh-clone-migration' ),
				'keptHere'           => __( 'Kept on this server', 'sh-clone-migration' ),
				/* translators: number of backups */
				'onDrive'            => __( '%s on Drive', 'sh-clone-migration' ),
				'reconnectShort'     => __( 'Reconnect', 'sh-clone-migration' ),
				'notConnected'       => __( 'Not connected', 'sh-clone-migration' ),
				'googleDrive'        => __( 'Google Drive', 'sh-clone-migration' ),
				'networkOnly'        => __( 'Backups cover the whole network and are managed on the main site only.', 'sh-clone-migration' ),
				'identityTitle'      => __( 'This schedule was set up on another copy of this site.', 'sh-clone-migration' ),
				'identityBody'       => __( 'The site was moved or cloned. Backups are paused here so that a copy never backs up into, or deletes from, the original site\'s storage.', 'sh-clone-migration' ),
				'adoptResume'        => __( 'This is the same site: resume backups', 'sh-clone-migration' ),
				'reconnectTitle'     => __( 'Google Drive needs to be reconnected.', 'sh-clone-migration' ),
				'driveOtherSite'     => __( 'The Google Drive connection belongs to another copy of this site. Confirm this is the same site, or disconnect and connect this site\'s own account.', 'sh-clone-migration' ),
				'adoptShort'         => __( 'This is the same site', 'sh-clone-migration' ),
				'driveNotConnected'  => __( 'Backups are set to go to Google Drive, but Google Drive is not connected. They are kept on this server until it is.', 'sh-clone-migration' ),
				'noPassword'         => __( 'Encrypted backups are on, but no readable password is stored (wp-config.php may have changed). Enter the password again and save.', 'sh-clone-migration' ),
				'overdue'            => __( 'The last scheduled backup is overdue. WP-Cron only runs when the site has visitors; see the note about a system cron job below.', 'sh-clone-migration' ),
				/* translators: a command */
				'cronDisabled'       => __( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Scheduled backups run only if a system cron job runs %s (or wp-cron.php) regularly.', 'sh-clone-migration' ),
				'loopbackBlocked'    => __( 'This site cannot call itself (loopback requests are blocked, for example by HTTP authentication). Backups still run, but only one step per minute through WP-Cron, so they take much longer.', 'sh-clone-migration' ),
				'account'            => __( 'Account', 'sh-clone-migration' ),
				'folder'             => __( 'Folder', 'sh-clone-migration' ),
				'folderLater'        => __( 'Created at the first upload', 'sh-clone-migration' ),
				'storage'            => __( 'Storage', 'sh-clone-migration' ),
				/* translators: 1: used space, 2: total space */
				'usedOf'             => __( '%1$s used of %2$s', 'sh-clone-migration' ),
				/* translators: used space */
				'used'               => __( '%s used', 'sh-clone-migration' ),
				'testConnection'     => __( 'Test connection', 'sh-clone-migration' ),
				'showOnDrive'        => __( 'Show backups on Drive', 'sh-clone-migration' ),
				'disconnect'         => __( 'Disconnect', 'sh-clone-migration' ),
				'connectionStopped'  => __( 'The connection stopped working.', 'sh-clone-migration' ),
				'connectedElsewhere' => __( 'Connected on another copy of this site.', 'sh-clone-migration' ),
				'notConnectedLong'   => __( 'Not connected. Follow the steps below once; afterwards backups upload automatically.', 'sh-clone-migration' ),
				'reconnectDrive'     => __( 'Reconnect Google Drive', 'sh-clone-migration' ),
				'connectDrive'       => __( 'Connect Google Drive', 'sh-clone-migration' ),
				'uploaded'           => __( 'Uploaded', 'sh-clone-migration' ),
				'open'               => __( 'Open', 'sh-clone-migration' ),
				'uploadingTag'       => __( 'Uploading', 'sh-clone-migration' ),
				'removedRetention'   => __( 'Removed (retention)', 'sh-clone-migration' ),
				'retryUpload'        => __( 'Retry upload', 'sh-clone-migration' ),
				'noHistory'          => __( 'No backups yet. Click "Back Up Now" or set a schedule.', 'sh-clone-migration' ),
				'triggerSchedule'    => __( 'Schedule', 'sh-clone-migration' ),
				'triggerManual'      => __( 'Back up now', 'sh-clone-migration' ),
				'triggerCli'         => __( 'WP-CLI', 'sh-clone-migration' ),
				'contentsFull'       => __( 'Database + files', 'sh-clone-migration' ),
				'contentsDatabase'   => __( 'Database', 'sh-clone-migration' ),
				'contentsFiles'      => __( 'Files', 'sh-clone-migration' ),
				'colDate'            => __( 'Date', 'sh-clone-migration' ),
				'colStartedBy'       => __( 'Started by', 'sh-clone-migration' ),
				'colStatus'          => __( 'Status', 'sh-clone-migration' ),
				'colContents'        => __( 'Contents', 'sh-clone-migration' ),
				'colSize'            => __( 'Size', 'sh-clone-migration' ),
				'colServer'          => __( 'This server', 'sh-clone-migration' ),
				'colDrive'           => __( 'Google Drive', 'sh-clone-migration' ),
				'kept'               => __( 'Kept', 'sh-clone-migration' ),
				'download'           => __( 'Download', 'sh-clone-migration' ),
				/* translators: number of warnings */
				'warningOne'         => __( '%s warning', 'sh-clone-migration' ),
				/* translators: number of warnings */
				'warningMany'        => __( '%s warnings', 'sh-clone-migration' ),
				'encrypted'          => __( 'encrypted', 'sh-clone-migration' ),
				'log'                => __( 'Log', 'sh-clone-migration' ),
				'uploadedVerified'   => __( 'Uploaded to Google Drive and verified.', 'sh-clone-migration' ),
				'cancelled'          => __( 'Cancelled.', 'sh-clone-migration' ),
				/* translators: date and time */
				'nextBackup'         => __( 'Next backup: %s.', 'sh-clone-migration' ),
				'noDriveFiles'       => __( 'No backups of this site on Google Drive yet.', 'sh-clone-migration' ),
				'colName'            => __( 'Name', 'sh-clone-migration' ),
				'sentByHand'         => __( 'sent by hand', 'sh-clone-migration' ),
				'openInDrive'        => __( 'Open in Drive', 'sh-clone-migration' ),
				'restoreHint'        => __( 'To restore one of these, download it from Google Drive and upload it on the Import screen.', 'sh-clone-migration' ),
			),
		);
	}

	/**
	 * Render the export screen.
	 *
	 * @return void
	 */
	public function renderExport() {
		$this->render( 'export' );
	}

	/**
	 * Render the import screen.
	 *
	 * @return void
	 */
	public function renderImport() {
		$this->render( 'import' );
	}

	/**
	 * Render the backups screen.
	 *
	 * @return void
	 */
	public function renderBackups() {
		$this->render( 'backups' );
	}

	/**
	 * Render the scheduled backups screen.
	 *
	 * @return void
	 */
	public function renderSchedules() {
		$this->render( 'schedules' );
	}

	/**
	 * Render the tools screen.
	 *
	 * @return void
	 */
	public function renderTools() {
		$this->render( 'tools' );
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function renderSettings() {
		$this->render( 'settings' );
	}

	/**
	 * Render the system status screen.
	 *
	 * @return void
	 */
	public function renderSystemStatus() {
		$this->render( 'system-status' );
	}

	/**
	 * Include a view.
	 *
	 * @param string $view View name.
	 * @return void
	 */
	protected function render( $view ) {
		if ( ! Capabilities::currentUserCan() ) {
			wp_die( esc_html__( 'You do not have permission to manage migrations on this site.', 'sh-clone-migration' ), 403 );
		}

		$plugin      = $this->plugin;
		$settings    = $this->plugin->settings();
		$storage     = $this->plugin->storage();
		$environment = $this->plugin->environment();
		$catalog     = new Catalog( $storage );
		$controller  = new Controller( $this->plugin );

		$file = SHCM_PLUGIN_DIR . 'includes/Admin/Views/' . $view . '.php';
		if ( ! is_file( $file ) ) {
			return;
		}

		echo '<div class="wrap shcm-wrap">';
		include $file;
		echo '</div>';
	}

	/**
	 * Shared page header.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Subtitle.
	 * @return void
	 */
	public static function header( $title, $subtitle = '' ) {
		echo '<div class="shcm-header">';
		echo '<div class="shcm-header__brand"><span class="shcm-logo dashicons dashicons-migrate"></span>';
		echo '<div><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="shcm-header__subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div></div>';
		echo '<div class="shcm-header__meta">' . esc_html(
			sprintf(
				/* translators: %s: free disk space */
				__( 'Free disk space: %s', 'sh-clone-migration' ),
				Bytes::format( ( new \SHCM\Filesystem\Storage() )->freeSpace() )
			)
		) . '</div>';
		echo '</div>';
	}
}
