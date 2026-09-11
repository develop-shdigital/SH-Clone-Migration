<?php
/**
 * Export: site analysis.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Core\Settings;
use SHCM\Database\Inspector;
use SHCM\Filesystem\ExclusionMatcher;
use SHCM\Filesystem\FileQueue;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Scanner;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Inventories the database and the filesystem.
 *
 * Everything the manifest and the progress bars need is decided here, so the
 * later stages never have to guess how much work is left.
 */
class ScanStage extends AbstractStage {

	/**
	 * Inspector.
	 *
	 * @var Inspector
	 */
	protected $inspector;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Storage   $storage   Storage.
	 * @param Logger    $logger    Logger.
	 * @param Inspector $inspector Inspector.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger, Inspector $inspector ) {
		parent::__construct( $settings, $storage, $logger );
		$this->inspector = $inspector;
	}

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'scan';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Analysing the installation', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 5;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		$state = $job->stageState(
			$this->key(),
			array(
				'phase'      => 'analyze',
				'dir_offset' => 0,
				'totals'     => array(),
			)
		);

		if ( 'analyze' === $state['phase'] ) {
			$this->analyze( $job );
			$state['phase'] = 'walk';
			$this->seedScanner( $job );
			$job->setStageState( $this->key(), $state );
			return $this->progress( __( 'Database analysed', 'sh-clone-migration' ), 0.35 );
		}

		if ( 'walk' === $state['phase'] ) {
			$scanner = $this->scanner( $job );
			$result  = $scanner->scan(
				array(
					'dir_offset' => $state['dir_offset'],
					'totals'     => empty( $state['totals'] ) ? array(
						'files'   => 0,
						'bytes'   => 0,
						'dirs'    => 0,
						'skipped' => 0,
						'groups'  => array(),
					) : $state['totals'],
				),
				$budget
			);

			$state['dir_offset'] = $result['dir_offset'];
			$state['totals']     = $result['totals'];

			foreach ( $scanner->warnings() as $warning ) {
				$job->addWarning( $warning );
			}

			if ( empty( $result['done'] ) ) {
				$job->setStageState( $this->key(), $state );
				$job->setShared( 'file_totals', $state['totals'] );
				return $this->progress(
					sprintf(
						/* translators: 1: file count, 2: size */
						__( 'Scanning files: %1$s found (%2$s)', 'sh-clone-migration' ),
						number_format_i18n( $state['totals']['files'] ),
						Bytes::format( $state['totals']['bytes'] )
					),
					0.35 + min( 0.6, $state['totals']['dirs'] / 20000 )
				);
			}

			$state['phase'] = 'done';
		}

		$job->setShared( 'file_totals', $state['totals'] );
		$job->setStageState( $this->key(), $state );

		$this->logger->info(
			sprintf(
				'Scan complete: %1$d files, %2$s, %3$d directories, %4$d skipped.',
				$state['totals']['files'],
				Bytes::format( $state['totals']['bytes'] ),
				$state['totals']['dirs'],
				$state['totals']['skipped']
			)
		);

		return $this->complete(
			sprintf(
				/* translators: 1: file count, 2: size */
				__( '%1$s files found (%2$s)', 'sh-clone-migration' ),
				number_format_i18n( $state['totals']['files'] ),
				Bytes::format( $state['totals']['bytes'] )
			)
		);
	}

	/**
	 * Collect the database inventory and everything the manifest needs.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	protected function analyze( Job $job ) {
		global $wp_version;

		$include_foreign = (bool) $job->param( 'include_foreign_tables', false );
		$exclude_tables  = (array) $job->param( 'exclude_tables', array() );

		$tables = $this->inspector->exportableTables(
			array(
				'include_foreign' => $include_foreign,
				'exclude'         => $exclude_tables,
			)
		);

		$table_list = array();
		foreach ( $tables as $name => $info ) {
			$table_list[] = array(
				'name'   => $name,
				'type'   => $info['type'],
				'rows'   => $info['rows'],
				'size'   => $info['data_length'] + $info['index_length'],
				'engine' => $info['engine'],
			);
		}

		$job->setShared( 'tables', $table_list );
		$job->setShared( 'database_size', $this->inspector->totalSize( $tables ) );
		$job->setShared( 'prefix', $this->inspector->prefix() );

		$skipped = array();
		foreach ( $this->inspector->inventory() as $name => $info ) {
			if ( ! isset( $tables[ $name ] ) ) {
				$skipped[] = $name;
			}
		}
		if ( ! empty( $skipped ) ) {
			$job->setShared( 'tables_skipped', $skipped );
			$this->logger->info( 'Tables not included (they belong to another installation or were excluded): ' . implode( ', ', $skipped ) );
		}

		$job->setShared( 'site', $this->siteInfo() );
		$job->setShared( 'plugins', $this->pluginInfo() );
		$job->setShared( 'themes', $this->themeInfo() );
		$job->setShared( 'components', $this->componentInfo() );
		$job->setShared( 'wordpress_version', isset( $wp_version ) ? $wp_version : get_bloginfo( 'version' ) );

		$this->logger->info(
			sprintf(
				'Database analysed: %1$d tables, %2$s.',
				count( $table_list ),
				Bytes::format( $job->shared( 'database_size', 0 ) )
			)
		);
	}

	/**
	 * Site level metadata.
	 *
	 * @return array
	 */
	protected function siteInfo() {
		$uploads = wp_get_upload_dir();
		return array(
			'home'         => home_url(),
			'siteurl'      => site_url(),
			'name'         => get_bloginfo( 'name' ),
			'description'  => get_bloginfo( 'description' ),
			'language'     => get_locale(),
			'timezone'     => get_option( 'timezone_string' ),
			'charset'      => get_bloginfo( 'charset' ),
			'permalink'    => get_option( 'permalink_structure' ),
			'multisite'    => is_multisite(),
			'blog_id'      => get_current_blog_id(),
			'abspath'      => Paths::abspath(),
			'content_dir'  => Paths::contentDir(),
			'content_url'  => content_url(),
			'plugin_dir'   => Paths::pluginDir(),
			'uploads_dir'  => Paths::uploadsDir(),
			'uploads_url'  => isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '',
			'upload_path'  => get_option( 'upload_path' ),
			'upload_url_path' => get_option( 'upload_url_path' ),
		);
	}

	/**
	 * Installed plugins.
	 *
	 * @return array
	 */
	protected function pluginInfo() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version' => isset( $data['Version'] ) ? $data['Version'] : '',
				'active'  => in_array( $file, $active, true ),
				'network' => in_array( $file, $network_active, true ),
			);
		}

		$mu = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			$mu[] = array(
				'file'    => $file,
				'name'    => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version' => isset( $data['Version'] ) ? $data['Version'] : '',
			);
		}

		$dropins = array();
		foreach ( get_dropins() as $file => $data ) {
			$dropins[] = array(
				'file' => $file,
				'name' => isset( $data['Name'] ) ? $data['Name'] : $file,
			);
		}

		return array(
			'installed'      => $plugins,
			'active'         => array_values( $active ),
			'network_active' => array_values( $network_active ),
			'mu'             => $mu,
			'dropins'        => $dropins,
		);
	}

	/**
	 * Installed themes.
	 *
	 * @return array
	 */
	protected function themeInfo() {
		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'template'   => $theme->get_template(),
				'is_child'   => $theme->parent() ? true : false,
			);
		}

		return array(
			'installed'  => $themes,
			'stylesheet' => get_option( 'stylesheet' ),
			'template'   => get_option( 'template' ),
		);
	}

	/**
	 * Detected components the importer runs compatibility work for.
	 *
	 * @return array
	 */
	protected function componentInfo() {
		return array(
			'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : ( get_option( 'woocommerce_db_version' ) ? get_option( 'woocommerce_db_version' ) : false ),
			'elementor'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : ( get_option( 'elementor_version' ) ? get_option( 'elementor_version' ) : false ),
			'acf'         => defined( 'ACF_VERSION' ) ? ACF_VERSION : false,
			'wpml'        => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : false,
			'polylang'    => defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : false,
			'yoast'       => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : false,
		);
	}

	/**
	 * Build the scanner for this job.
	 *
	 * @param Job $job Job.
	 * @return Scanner
	 */
	protected function scanner( Job $job ) {
		$exclusions = new ExclusionMatcher( $this->exclusionPatterns( $job ) );

		$scanner = new Scanner(
			new FileQueue( $this->queuePath( $job, 'files' ) ),
			new FileQueue( $this->queuePath( $job, 'dirs' ) ),
			$exclusions
		);
		$scanner->block( array( $this->storage->base() ) );
		$scanner->maxFileSize( $this->settings->getInt( 'exclude_large_files', 0 ) );

		return $scanner;
	}

	/**
	 * Seed the directory queue with the roots to walk.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	protected function seedScanner( Job $job ) {
		$roots = Paths::roots( (bool) $job->param( 'include_core', $this->settings->getBool( 'include_core' ) ) );
		$job->setShared( 'roots', $roots );

		$queue = new FileQueue( $this->queuePath( $job, 'dirs' ) );
		$queue->delete();

		$files = new FileQueue( $this->queuePath( $job, 'files' ) );
		$files->delete();

		$scanner = $this->scanner( $job );
		$scanner->seed( $roots );

		$this->logger->info( 'Scanning roots: ' . implode( ', ', array_keys( $roots ) ) );
	}

	/**
	 * Exclusion patterns for this job.
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	protected function exclusionPatterns( Job $job ) {
		$patterns = array();
		if ( $this->settings->getBool( 'use_default_exclusions', true ) ) {
			$patterns = Settings::defaultExclusions();
		}
		$patterns = array_merge(
			$patterns,
			(array) $this->settings->get( 'exclude_directories', array() ),
			(array) $this->settings->get( 'exclude_patterns', array() ),
			(array) $job->param( 'exclusions', array() )
		);

		/**
		 * Filter the exclusion patterns used for an export.
		 *
		 * @param string[] $patterns Patterns.
		 * @param Job      $job      Job.
		 */
		return (array) apply_filters( 'shcm_export_exclusions', $patterns, $job );
	}

	/**
	 * Path of a work queue for this job.
	 *
	 * @param Job    $job  Job.
	 * @param string $name Queue name.
	 * @return string
	 */
	protected function queuePath( Job $job, $name ) {
		return Paths::trailingslash( $this->storage->tmp() ) . $job->id() . '-' . $name . '.ndjson';
	}
}
