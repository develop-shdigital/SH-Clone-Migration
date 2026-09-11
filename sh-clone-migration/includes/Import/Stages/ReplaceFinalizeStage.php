<?php
/**
 * Search and replace: finalisation.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Clears caches after a replacement that actually wrote something.
 */
class ReplaceFinalizeStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'finalize';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Finishing up', 'sh-clone-migration' );
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

		if ( ! $job->param( 'dry_run', false ) ) {
			$post = new \SHCM\Compatibility\PostMigration();
			$post->clearTransients();
			$post->clearObjectCache();
			delete_option( 'rewrite_rules' );
		}

		return $this->complete(
			$job->param( 'dry_run', false )
				? __( 'Preview complete, nothing was written', 'sh-clone-migration' )
				: __( 'Replacement complete', 'sh-clone-migration' )
		);
	}
}
