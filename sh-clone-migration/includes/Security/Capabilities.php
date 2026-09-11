<?php
/**
 * Capability handling.
 *
 * @package SHCM
 */

namespace SHCM\Security;

defined( 'ABSPATH' ) || exit;

/**
 * A single dedicated capability guards every migration operation, so access
 * can be delegated without handing out manage_options.
 */
class Capabilities {

	const CAP = 'shcm_manage_migrations';

	/**
	 * The capability required for migration operations.
	 *
	 * @return string
	 */
	public static function required() {
		/**
		 * Filter the capability required to run migrations.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'shcm_required_capability', self::CAP );
	}

	/**
	 * Whether the current user may run migrations.
	 *
	 * @return bool
	 */
	public static function currentUserCan() {
		if ( is_multisite() ) {
			// A migration moves the whole network, including every other
			// site's content, so only a network administrator may run one.
			// A single site administrator holding the custom capability is
			// deliberately not enough.
			return current_user_can( 'manage_network_options' );
		}

		if ( current_user_can( self::required() ) ) {
			return true;
		}

		// Fall back to the underlying core capability so a site that never ran
		// the activation hook (or a role editor that dropped the custom cap)
		// still lets real administrators in.
		return current_user_can( 'manage_options' );
	}

	/**
	 * Grant the capability to the roles that should have it.
	 *
	 * @return void
	 */
	public static function grant() {
		if ( is_multisite() ) {
			// Network administrators already pass every capability check, and
			// nobody else should be able to migrate a network.
			return;
		}

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
	}

	/**
	 * Remove the capability (used by the uninstaller).
	 *
	 * @return void
	 */
	public static function revoke() {
		foreach ( array( 'administrator' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}
}
