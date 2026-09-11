<?php
/**
 * Stage registry.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

use SHCM\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Maps job types to their ordered stages and builds stage instances on demand.
 */
class Registry implements StageResolver {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Instantiated stages, keyed by "type:stage".
	 *
	 * @var array
	 */
	protected $cache = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Stage class map.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function map() {
		return array(
			Job::TYPE_EXPORT  => array(
				'initialize' => \SHCM\Export\Stages\InitializeStage::class,
				'scan'       => \SHCM\Export\Stages\ScanStage::class,
				'database'   => \SHCM\Export\Stages\DatabaseStage::class,
				'files'      => \SHCM\Export\Stages\FilesStage::class,
				'finalize'   => \SHCM\Export\Stages\FinalizeStage::class,
				'verify'     => \SHCM\Export\Stages\VerifyStage::class,
			),
			Job::TYPE_IMPORT  => array(
				'initialize'    => \SHCM\Import\Stages\InitializeStage::class,
				'validate'      => \SHCM\Import\Stages\ValidateStage::class,
				'rollback'      => \SHCM\Import\Stages\RollbackPointStage::class,
				'database'      => \SHCM\Import\Stages\DatabaseStage::class,
				'files'         => \SHCM\Import\Stages\FilesStage::class,
				'urls'          => \SHCM\Import\Stages\UrlStage::class,
				'compatibility' => \SHCM\Import\Stages\CompatibilityStage::class,
				'verify'        => \SHCM\Import\Stages\VerifyStage::class,
				'finalize'      => \SHCM\Import\Stages\FinalizeStage::class,
			),
			Job::TYPE_REPLACE => array(
				'initialize' => \SHCM\Import\Stages\ReplaceInitializeStage::class,
				'replace'    => \SHCM\Import\Stages\ReplaceStage::class,
				'finalize'   => \SHCM\Import\Stages\ReplaceFinalizeStage::class,
			),
		);
	}

	/**
	 * Ordered stage keys for a job type.
	 *
	 * @param string $type   Job type.
	 * @param array  $params Job parameters.
	 * @return string[]
	 */
	public function stagesFor( $type, array $params = array() ) {
		$map    = $this->map();
		$stages = isset( $map[ $type ] ) ? array_keys( $map[ $type ] ) : array();

		if ( Job::TYPE_IMPORT === $type && empty( $params['create_rollback_point'] ) ) {
			$stages = array_values( array_diff( $stages, array( 'rollback' ) ) );
		}
		if ( Job::TYPE_IMPORT === $type && empty( $params['replace_urls'] ) ) {
			$stages = array_values( array_diff( $stages, array( 'urls' ) ) );
		}

		/**
		 * Filter the stages of a job type.
		 *
		 * @param string[] $stages Stage keys.
		 * @param string   $type   Job type.
		 * @param array    $params Job parameters.
		 */
		return (array) apply_filters( 'shcm_job_stages', $stages, $type, $params );
	}

	/**
	 * Resolve a stage instance.
	 *
	 * @param string $type      Job type.
	 * @param string $stage_key Stage key.
	 * @return StageInterface|null
	 */
	public function resolve( $type, $stage_key ) {
		$cache_key = $type . ':' . $stage_key;
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		$map = $this->map();
		if ( ! isset( $map[ $type ][ $stage_key ] ) ) {
			return null;
		}

		$class = $map[ $type ][ $stage_key ];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		$this->cache[ $cache_key ] = $this->build( $class );
		return $this->cache[ $cache_key ];
	}

	/**
	 * Instantiate a stage with the dependencies it declares.
	 *
	 * @param string $class Stage class.
	 * @return StageInterface
	 */
	protected function build( $class ) {
		global $wpdb;

		$settings = $this->plugin->settings();
		$storage  = $this->plugin->storage();
		$logger   = $this->plugin->logger();

		switch ( $class ) {
			case \SHCM\Export\Stages\InitializeStage::class:
				return new $class( $settings, $storage, $logger, $this->plugin->environment() );

			case \SHCM\Export\Stages\ScanStage::class:
				return new $class( $settings, $storage, $logger, $this->plugin->inspector() );

			case \SHCM\Export\Stages\DatabaseStage::class:
				return new $class( $settings, $storage, $logger, $this->plugin->inspector(), $wpdb );

			case \SHCM\Import\Stages\DatabaseStage::class:
			case \SHCM\Import\Stages\UrlStage::class:
			case \SHCM\Import\Stages\VerifyStage::class:
			case \SHCM\Import\Stages\CompatibilityStage::class:
			case \SHCM\Import\Stages\RollbackPointStage::class:
			case \SHCM\Import\Stages\ReplaceStage::class:
			case \SHCM\Import\Stages\ReplaceInitializeStage::class:
				return new $class( $settings, $storage, $logger, $this->plugin->inspector(), $wpdb );
		}

		return new $class( $settings, $storage, $logger );
	}
}
