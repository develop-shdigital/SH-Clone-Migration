<?php
/**
 * Job scoped authorisation tokens.
 *
 * @package SHCM
 */

namespace SHCM\Security;

use SHCM\Jobs\Job;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * A restore replaces the users table half way through its own run, which
 * invalidates the operator's authentication cookie: the account the cookie
 * points at is simply not there any more. Without something else to prove the
 * caller is allowed to continue, the migration would stall at exactly the
 * worst possible moment.
 *
 * When a job is created (by a request that was fully authenticated) it is
 * issued a random 256 bit token. Only its SHA-256 hash is stored with the job;
 * the token itself is returned once and kept in the browser tab. It authorises
 * exactly three things on exactly one job: advance it, read its status, cancel
 * it. Everything else still requires a normal WordPress session.
 */
class JobToken {

	const PARAM = 'job_token';

	/**
	 * Actions a token may authorise.
	 *
	 * @var string[]
	 */
	protected static $actions = array( 'tick', 'status', 'cancel' );

	/**
	 * Issue a token for a job and store its hash.
	 *
	 * @param Job $job Job.
	 * @return string The token, which is never stored in plain text.
	 */
	public static function issue( Job $job ) {
		$token = bin2hex( random_bytes( 32 ) );
		$job->setParam( 'token_hash', hash( 'sha256', $token ) );
		$job->setParam( 'token_issued', time() );
		return $token;
	}

	/**
	 * Whether a token authorises an action on a job.
	 *
	 * @param Job|null $job    Job.
	 * @param string   $token  Supplied token.
	 * @param string   $action Action name.
	 * @return bool
	 */
	public static function authorises( $job, $token, $action ) {
		if ( ! $job instanceof Job ) {
			return false;
		}
		if ( ! in_array( $action, self::$actions, true ) ) {
			return false;
		}

		$token = (string) $token;
		$hash  = (string) $job->param( 'token_hash', '' );
		if ( '' === $hash || 64 !== strlen( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return false;
		}

		return hash_equals( $hash, hash( 'sha256', $token ) );
	}

	/**
	 * Actions a token may authorise.
	 *
	 * @return string[]
	 */
	public static function actions() {
		return self::$actions;
	}
}
