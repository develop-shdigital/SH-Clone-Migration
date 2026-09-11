<?php
/**
 * Search and replace: preparation.
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
use SHCM\URL\RuleBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a standalone search and replace request.
 */
class ReplaceInitializeStage extends AbstractStage {

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
		return 'initialize';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Preparing the replacement', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 1;
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

		$from = trim( (string) $job->param( 'search', '' ) );
		$to   = (string) $job->param( 'replace', '' );

		if ( '' === $from ) {
			throw new \RuntimeException( __( 'Nothing to search for.', 'sh-clone-migration' ) );
		}
		if ( strlen( $from ) < 4 ) {
			throw new \RuntimeException( __( 'The search term is too short. Use at least four characters so the replacement cannot run away with your content.', 'sh-clone-migration' ) );
		}
		if ( $from === $to ) {
			throw new \RuntimeException( __( 'The search and replace values are identical.', 'sh-clone-migration' ) );
		}

		$replacer = RuleBuilder::forUrls( $from, $to );
		if ( $replacer->isEmpty() ) {
			// Not a URL pair: fall back to a literal replacement.
			$replacer = new \SHCM\URL\Replacer( array( $from => $to ) );
			$replacer->addProbe( $from );
		}
		$job->setShared( 'rule_count', count( $replacer->rules() ) );

		$this->logger->info(
			sprintf(
				'Search and replace prepared: "%1$s" to "%2$s"%3$s.',
				$from,
				$to,
				$job->param( 'dry_run' ) ? ' (dry run)' : ''
			)
		);

		return $this->complete( __( 'Replacement prepared', 'sh-clone-migration' ) );
	}
}
