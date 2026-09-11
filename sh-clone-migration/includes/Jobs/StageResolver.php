<?php
/**
 * Stage lookup.
 *
 * @package SHCM
 */

namespace SHCM\Jobs;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Maps a job type plus stage key onto a stage instance.
 */
interface StageResolver {

	/**
	 * Resolve a stage.
	 *
	 * @param string $type      Job type.
	 * @param string $stage_key Stage key.
	 * @return StageInterface|null
	 */
	public function resolve( $type, $stage_key );

	/**
	 * Ordered stage keys for a job type.
	 *
	 * @param string $type   Job type.
	 * @param array  $params Job parameters (some stages are conditional).
	 * @return string[]
	 */
	public function stagesFor( $type, array $params = array() );
}
