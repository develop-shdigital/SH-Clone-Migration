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
			'shcm-tools'          => __( 'Search &amp; Replace', 'sh-clone-migration' ),
			'shcm-settings'       => __( 'Settings', 'sh-clone-migration' ),
			'shcm-system-status'  => __( 'System Status', 'sh-clone-migration' ),
		);

		$callbacks = array(
			'shcm'               => 'renderExport',
			'shcm-import'        => 'renderImport',
			'shcm-backups'       => 'renderBackups',
			'shcm-tools'         => 'renderTools',
			'shcm-settings'      => 'renderSettings',
			'shcm-system-status' => 'renderSystemStatus',
		);

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
			'uploading'       => __( 'Uploading', 'sh-clone-migration' ),
			'uploadComplete'  => __( 'Upload complete', 'sh-clone-migration' ),
			'verifying'       => __( 'Verifying', 'sh-clone-migration' ),
			'verified'        => __( 'Archive verified', 'sh-clone-migration' ),
			'failed'          => __( 'Failed', 'sh-clone-migration' ),
			'retrying'        => __( 'Connection problem, retrying', 'sh-clone-migration' ),
			'passwordNeeded'  => __( 'This archive is encrypted. Enter the migration password.', 'sh-clone-migration' ),
			'genericError'    => __( 'Something went wrong. Check the migration log for details.', 'sh-clone-migration' ),
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
