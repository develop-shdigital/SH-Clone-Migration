<?php
/**
 * Admin notices.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Core\Plugin;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\Job;
use SHCM\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces the two states an administrator must not miss: an interrupted
 * migration, and a site left in maintenance mode.
 */
class Notices {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Print the notices.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Capabilities::currentUserCan() ) {
			return;
		}

		if ( MaintenanceMode::isEnabled() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'SH Clone Migration:', 'sh-clone-migration' ),
				esc_html__( 'this site is in maintenance mode because a migration is running. It is switched off automatically when the migration finishes or is abandoned.', 'sh-clone-migration' )
			);
		}

		foreach ( $this->plugin->jobs()->all( null, 5 ) as $job ) {
			if ( ! $job->isRunnable() || Job::STATUS_PENDING === $job->status() ) {
				continue;
			}
			if ( time() - (int) $job->get( 'updated_at' ) < 60 ) {
				continue;
			}

			$page = Job::TYPE_IMPORT === $job->type() ? 'shcm-import' : 'shcm';
			printf(
				'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a class="button button-small" href="%3$s">%4$s</a></p></div>',
				esc_html__( 'An interrupted migration was detected.', 'sh-clone-migration' ),
				esc_html(
					sprintf(
						/* translators: 1: job id, 2: progress */
						__( 'Job %1$s stopped at %2$s%%.', 'sh-clone-migration' ),
						$job->id(),
						number_format_i18n( (float) $job->get( 'progress' ), 1 )
					)
				),
				esc_url( admin_url( 'admin.php?page=' . $page . '&job=' . rawurlencode( $job->id() ) ) ),
				esc_html__( 'Resume', 'sh-clone-migration' )
			);
			break;
		}
	}
}
