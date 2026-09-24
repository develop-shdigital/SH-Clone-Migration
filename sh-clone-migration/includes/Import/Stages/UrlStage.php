<?php
/**
 * Import: URL replacement.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Core\Settings;
use SHCM\Database\Inspector;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\URL\DatabaseReplacer;
use SHCM\URL\RuleBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the source URL (and the source server paths) to the destination
 * across the whole database, without breaking serialized data.
 */
class UrlStage extends AbstractStage {

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
		return 'urls';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Updating URLs', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 12;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		$source      = (string) $job->shared( 'source' );
		$destination = (string) $job->shared( 'destination' );

		if ( ! $job->param( 'include_database', true ) || $job->shared( 'database_absent' ) ) {
			return $this->complete( __( 'No database was restored, so there are no URLs to replace', 'sh-clone-migration' ) );
		}

		if ( '' === $source ) {
			return $this->complete( __( 'No source URL recorded, nothing to replace', 'sh-clone-migration' ) );
		}

		$replacer = $this->buildReplacer( $job, $source, $destination );
		if ( $replacer->isEmpty() ) {
			$this->ensureSiteUrls( $destination );
			return $this->complete( __( 'Source and destination are identical, no replacement needed', 'sh-clone-migration' ) );
		}

		$engine = new DatabaseReplacer(
			$this->db,
			$this->inspector,
			$replacer,
			array(
				'rows_per_batch' => 400,
				'dry_run'        => false,
			)
		);

		$state = $job->stageState( $this->key(), array() );
		if ( empty( $state ) ) {
			$state = $engine->initialState();
			$this->logger->info(
				sprintf( 'Replacing %1$s with %2$s across %3$d tables.', $source, $destination, count( $state['tables'] ) )
			);
		}

		$state = $engine->run( $state, $budget );
		$job->setStageState( $this->key(), $state );
		$job->setShared( 'url_report', $this->report( $state ) );

		if ( empty( $state['done'] ) ) {
			return $this->progress(
				sprintf(
					/* translators: 1: tables done, 2: total tables, 3: values changed */
					__( 'Replacing URLs: %1$d of %2$d tables, %3$s values updated', 'sh-clone-migration' ),
					$state['index'],
					count( $state['tables'] ),
					number_format_i18n( $state['stats']['values_changed'] )
				),
				$engine->progress( $state )
			);
		}

		$this->ensureSiteUrls( $destination );

		$stats = $state['stats'];
		$this->logger->info(
			sprintf(
				'URL replacement finished: %1$d tables, %2$d rows scanned, %3$d values changed, %4$d serialized values rewritten, %5$d unparsable, %6$d references to the source domain left.',
				$stats['tables_scanned'],
				$stats['rows_scanned'],
				$stats['values_changed'],
				$stats['serialized_repaired'],
				$stats['serialized_failed'],
				$stats['remaining_refs']
			)
		);

		if ( $stats['serialized_failed'] > 0 ) {
			$job->addWarning(
				sprintf(
					/* translators: %d: number of values */
					__( '%d serialized values could not be parsed and were left untouched. They are listed in the migration report.', 'sh-clone-migration' ),
					$stats['serialized_failed']
				)
			);
		}
		if ( $stats['remaining_refs'] > 0 ) {
			$job->addWarning(
				sprintf(
					/* translators: %d: number of references */
					__( '%d references to the source domain remain. They were left alone on purpose: review them in the migration report and replace them only if they are meant to point at this site.', 'sh-clone-migration' ),
					$stats['remaining_refs']
				)
			);
		}

		return $this->complete(
			sprintf(
				/* translators: 1: values changed, 2: rows scanned */
				__( '%1$s values updated across %2$s rows', 'sh-clone-migration' ),
				number_format_i18n( $stats['values_changed'] ),
				number_format_i18n( $stats['rows_scanned'] )
			)
		);
	}

	/**
	 * Build the replacement rules for this restore.
	 *
	 * @param Job    $job         Job.
	 * @param string $source      Source URL.
	 * @param string $destination Destination URL.
	 * @return \SHCM\URL\Replacer
	 */
	protected function buildReplacer( Job $job, $source, $destination ) {
		$manifest = (array) $job->shared( 'manifest', array() );
		$paths    = array();

		if ( $this->settings->getBool( 'replace_paths', true ) ) {
			$source_paths = array(
				'abspath'     => isset( $manifest['wordpress']['abspath'] ) ? $manifest['wordpress']['abspath'] : '',
				'content_dir' => isset( $manifest['wordpress']['content_dir'] ) ? $manifest['wordpress']['content_dir'] : '',
				'uploads_dir' => isset( $manifest['wordpress']['uploads_dir'] ) ? $manifest['wordpress']['uploads_dir'] : '',
			);
			$targets      = array(
				'abspath'     => Paths::abspath(),
				'content_dir' => Paths::contentDir(),
				'uploads_dir' => Paths::uploadsDir(),
			);
			// Longest first so that the uploads path is rewritten before the
			// ABSPATH prefix it sits inside.
			uasort(
				$source_paths,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			foreach ( $source_paths as $key => $from ) {
				if ( '' !== $from && $from !== $targets[ $key ] ) {
					$paths[ $from ] = $targets[ $key ];
				}
			}
		}

		return RuleBuilder::forUrls(
			$source,
			$destination,
			array(
				'paths'               => $paths,
				'include_bare_domain' => (bool) $job->param( 'replace_bare_domain', false ),
			)
		);
	}

	/**
	 * Make absolutely sure the site URL options point at the destination.
	 *
	 * @param string $destination Destination URL.
	 * @return void
	 */
	protected function ensureSiteUrls( $destination ) {
		if ( '' === $destination ) {
			return;
		}
		$table = $this->db->prefix . 'options';
		foreach ( array( 'home', 'siteurl' ) as $name ) {
			$this->db->query(
				$this->db->prepare(
					"UPDATE `{$table}` SET option_value = %s WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$destination,
					$name
				)
			);
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Build the report shown after the migration.
	 *
	 * @param array $state Replacer state.
	 * @return array
	 */
	protected function report( array $state ) {
		return array(
			'stats'    => $state['stats'],
			'samples'  => array_slice( $state['samples'], 0, 40 ),
			'failures' => array_slice( $state['failures'], 0, 40 ),
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
