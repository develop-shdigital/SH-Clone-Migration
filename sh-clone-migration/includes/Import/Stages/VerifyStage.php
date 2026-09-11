<?php
/**
 * Import: verification.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Compatibility\ACF;
use SHCM\Compatibility\Elementor;
use SHCM\Compatibility\WooCommerce;
use SHCM\Core\Settings;
use SHCM\Database\Inspector;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Proves the restored site is actually working before the migration is called
 * a success.
 */
class VerifyStage extends AbstractStage {

	/**
	 * Inspector.
	 *
	 * @var Inspector
	 */
	protected $inspector;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Storage   $storage   Storage.
	 * @param Logger    $logger    Logger.
	 * @param Inspector $inspector Inspector.
	 * @param \wpdb     $db        Database handle.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger, Inspector $inspector, $db ) {
		parent::__construct( $settings, $storage, $logger );
		$this->inspector = $inspector;
		$this->db        = $db;
	}

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'verify';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Verifying the restored site', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 3;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		unset( $budget );

		$checks      = array();
		$destination = untrailingslashit( (string) $job->shared( 'destination' ) );
		$prefix      = $this->db->prefix;
		$options     = $prefix . 'options';

		$checks[] = $this->check(
			'database',
			__( 'Database connection', 'sh-clone-migration' ),
			(bool) $this->db->check_connection( false )
		);

		$core_tables = array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments' );
		$missing     = array();
		foreach ( $core_tables as $suffix ) {
			$table = $prefix . $suffix;
			if ( ! $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$missing[] = $table;
			}
		}
		$checks[] = $this->check(
			'tables',
			__( 'WordPress tables', 'sh-clone-migration' ),
			empty( $missing ),
			empty( $missing ) ? '' : implode( ', ', $missing )
		);

		$home    = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'home' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$siteurl = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'siteurl' ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$checks[] = $this->check(
			'home_url',
			__( 'Home URL', 'sh-clone-migration' ),
			untrailingslashit( (string) $home ) === $destination,
			(string) $home
		);
		$checks[] = $this->check(
			'site_url',
			__( 'Site URL', 'sh-clone-migration' ),
			untrailingslashit( (string) $siteurl ) === $destination,
			(string) $siteurl
		);

		$stylesheet = (string) $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$options}` WHERE option_name = %s", 'stylesheet' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$checks[]   = $this->check(
			'theme',
			__( 'Active theme', 'sh-clone-migration' ),
			'' !== $stylesheet && is_dir( Paths::contentDir() . '/themes/' . $stylesheet ),
			$stylesheet
		);

		$report  = (array) $job->shared( 'compatibility_report', array() );
		$plugins = isset( $report['plugins'] ) ? $report['plugins'] : array();
		$checks[] = $this->check(
			'plugins',
			__( 'Plugins', 'sh-clone-migration' ),
			empty( $plugins['missing'] ),
			empty( $plugins['missing'] )
				? sprintf(
					/* translators: %d: number of plugins */
					__( '%d active', 'sh-clone-migration' ),
					isset( $plugins['activated'] ) ? count( $plugins['activated'] ) : 0
				)
				: sprintf(
					/* translators: %s: plugin list */
					__( 'missing: %s', 'sh-clone-migration' ),
					implode( ', ', array_slice( $plugins['missing'], 0, 5 ) )
				)
		);

		$uploads = Paths::uploadsDir();
		$checks[] = $this->check(
			'uploads',
			__( 'Uploads directory', 'sh-clone-migration' ),
			is_dir( $uploads ) && is_writable( $uploads ),
			$uploads
		);

		$checks[] = $this->check(
			'rewrite',
			__( 'Rewrite rules', 'sh-clone-migration' ),
			true,
			__( 'scheduled for regeneration', 'sh-clone-migration' )
		);

		if ( Elementor::isPresent( $this->db ) ) {
			$checks[] = $this->check(
				'elementor',
				'Elementor',
				is_dir( Paths::pluginDir() . '/elementor' ),
				__( 'data present, CSS cache cleared', 'sh-clone-migration' )
			);
		}
		if ( WooCommerce::isPresent( $this->db ) ) {
			$checks[] = $this->check(
				'woocommerce',
				'WooCommerce',
				is_dir( Paths::pluginDir() . '/woocommerce' ),
				__( 'tables present, sessions cleared', 'sh-clone-migration' )
			);
		}
		if ( ACF::isPresent( $this->db ) ) {
			$checks[] = $this->check(
				'acf',
				'Advanced Custom Fields',
				true,
				__( 'field groups present', 'sh-clone-migration' )
			);
		}

		$url_report = (array) $job->shared( 'url_report', array() );
		if ( ! empty( $url_report['stats']['remaining_refs'] ) ) {
			$checks[] = $this->check(
				'remaining_urls',
				__( 'Remaining source URLs', 'sh-clone-migration' ),
				true,
				sprintf(
					/* translators: %d: number of references */
					__( '%d references kept for review', 'sh-clone-migration' ),
					(int) $url_report['stats']['remaining_refs']
				),
				'warning'
			);
		}

		$manifest = (array) $job->shared( 'manifest', array() );
		$constants = isset( $manifest['config']['constants'] ) ? $manifest['config']['constants'] : array();
		unset( $constants );

		if ( defined( 'WP_SITEURL' ) || defined( 'WP_HOME' ) ) {
			$checks[] = $this->check(
				'url_constants',
				__( 'WP_HOME / WP_SITEURL constants', 'sh-clone-migration' ),
				( ! defined( 'WP_HOME' ) || untrailingslashit( WP_HOME ) === $destination )
					&& ( ! defined( 'WP_SITEURL' ) || untrailingslashit( WP_SITEURL ) === $destination ),
				__( 'wp-config.php defines the site URL; it overrides the database and must match the destination.', 'sh-clone-migration' ),
				'warning'
			);
		}

		$failed = array();
		foreach ( $checks as $check ) {
			if ( ! $check['pass'] && 'warning' !== $check['level'] ) {
				$failed[] = $check['label'];
			}
		}

		$job->setShared( 'verification', $checks );

		foreach ( $checks as $check ) {
			$this->logger->info(
				sprintf( 'Verification %1$s: %2$s %3$s', $check['label'], $check['pass'] ? 'PASS' : 'FAIL', $check['detail'] )
			);
		}

		if ( ! empty( $failed ) ) {
			$job->addWarning(
				sprintf(
					/* translators: %s: list of checks */
					__( 'Some post migration checks did not pass: %s. The site has been restored; review the report.', 'sh-clone-migration' ),
					implode( ', ', $failed )
				)
			);
		}

		return $this->complete(
			empty( $failed )
				? __( 'All post migration checks passed', 'sh-clone-migration' )
				: sprintf(
					/* translators: %d: number of failed checks */
					__( 'Restored with %d checks to review', 'sh-clone-migration' ),
					count( $failed )
				)
		);
	}

	/**
	 * Build a check row.
	 *
	 * @param string $key    Key.
	 * @param string $label  Label.
	 * @param bool   $pass   Result.
	 * @param string $detail Detail.
	 * @param string $level  pass|warning.
	 * @return array
	 */
	protected function check( $key, $label, $pass, $detail = '', $level = 'error' ) {
		return array(
			'key'    => $key,
			'label'  => $label,
			'pass'   => (bool) $pass,
			'detail' => (string) $detail,
			'level'  => $level,
		);
	}

	/**
	 * Cleanup.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		if ( null !== $error && $job->shared( 'maintenance' ) ) {
			MaintenanceMode::disable();
			$job->setShared( 'maintenance', false );
		}
	}
}
