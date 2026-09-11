<?php
/**
 * Search and replace: execution.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Core\Settings;
use SHCM\Database\Inspector;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\URL\DatabaseReplacer;
use SHCM\URL\Replacer;
use SHCM\URL\RuleBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a database wide search and replace, in preview or in earnest.
 */
class ReplaceStage extends AbstractStage {

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
		return 'replace';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Replacing', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 20;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		$from = (string) $job->param( 'search', '' );
		$to   = (string) $job->param( 'replace', '' );

		$replacer = RuleBuilder::forUrls( $from, $to );
		if ( $replacer->isEmpty() ) {
			$replacer = new Replacer( array( $from => $to ) );
			$replacer->addProbe( $from );
		}

		$engine = new DatabaseReplacer(
			$this->db,
			$this->inspector,
			$replacer,
			array(
				'rows_per_batch' => 400,
				'dry_run'        => (bool) $job->param( 'dry_run', false ),
				'tables'         => (array) $job->param( 'tables', array() ),
			)
		);

		$state = $job->stageState( $this->key(), array() );
		if ( empty( $state ) ) {
			$state = $engine->initialState();
		}

		$state = $engine->run( $state, $budget );
		$job->setStageState( $this->key(), $state );
		$job->setShared(
			'report',
			array(
				'stats'    => $state['stats'],
				'samples'  => array_slice( $state['samples'], 0, 40 ),
				'failures' => array_slice( $state['failures'], 0, 40 ),
				'dry_run'  => (bool) $job->param( 'dry_run', false ),
			)
		);

		if ( empty( $state['done'] ) ) {
			return $this->progress(
				sprintf(
					/* translators: 1: tables done, 2: total tables */
					__( 'Scanning table %1$d of %2$d', 'sh-clone-migration' ),
					$state['index'] + 1,
					count( $state['tables'] )
				),
				$engine->progress( $state )
			);
		}

		$stats = $state['stats'];
		$this->logger->info(
			sprintf(
				'Replacement finished: %1$d tables, %2$d rows, %3$d values changed, %4$d serialized values rewritten.',
				$stats['tables_scanned'],
				$stats['rows_scanned'],
				$stats['values_changed'],
				$stats['serialized_repaired']
			)
		);

		return $this->complete(
			sprintf(
				/* translators: 1: values changed, 2: rows scanned */
				__( '%1$s values changed in %2$s rows', 'sh-clone-migration' ),
				number_format_i18n( $stats['values_changed'] ),
				number_format_i18n( $stats['rows_scanned'] )
			)
		);
	}
}
