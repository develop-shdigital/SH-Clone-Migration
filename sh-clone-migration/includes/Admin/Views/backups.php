<?php
/**
 * Backups screen.
 *
 * @package SHCM
 * @var \SHCM\Core\Plugin     $plugin
 * @var \SHCM\Archive\Catalog $catalog
 */

defined( 'ABSPATH' ) || exit;

use SHCM\Admin\Menu;
use SHCM\Support\Bytes;

$shcm_archives = $catalog->all();
$shcm_free     = $plugin->storage()->freeSpace();
$shcm_total    = $catalog->totalSize();
$shcm_jobs     = $plugin->jobs()->all( null, 15 );

Menu::header(
	__( 'Migration backups', 'sh-clone-migration' ),
	__( 'Every archive this installation has produced or received.', 'sh-clone-migration' )
);
?>

<div class="shcm-stats">
	<div class="shcm-stat">
		<span class="shcm-stat__value"><?php echo esc_html( count( $shcm_archives ) ); ?></span>
		<span class="shcm-stat__label"><?php esc_html_e( 'Archives', 'sh-clone-migration' ); ?></span>
	</div>
	<div class="shcm-stat">
		<span class="shcm-stat__value"><?php echo esc_html( Bytes::format( $shcm_total ) ); ?></span>
		<span class="shcm-stat__label"><?php esc_html_e( 'Space used', 'sh-clone-migration' ); ?></span>
	</div>
	<div class="shcm-stat <?php echo $shcm_free >= 0 && $shcm_free < 1073741824 ? 'shcm-stat--warn' : ''; ?>">
		<span class="shcm-stat__value"><?php echo esc_html( $shcm_free < 0 ? '—' : Bytes::format( $shcm_free ) ); ?></span>
		<span class="shcm-stat__label"><?php esc_html_e( 'Free disk space', 'sh-clone-migration' ); ?></span>
	</div>
</div>

<?php if ( $shcm_free >= 0 && $shcm_free < 1073741824 ) : ?>
	<div class="shcm-alert shcm-alert--warning">
		<?php esc_html_e( 'Less than 1 GB of disk space is left. Delete old archives before creating another one.', 'sh-clone-migration' ); ?>
	</div>
<?php endif; ?>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Archives', 'sh-clone-migration' ); ?></h2>
	<?php if ( empty( $shcm_archives ) ) : ?>
		<p class="shcm-empty"><?php esc_html_e( 'No archives stored on this server.', 'sh-clone-migration' ); ?></p>
	<?php else : ?>
		<table class="widefat striped shcm-table" id="shcm-backups-table">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Archive', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Source', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Contents', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Size', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Created', 'sh-clone-migration' ); ?></th>
				<th></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $shcm_archives as $shcm_archive ) : ?>
				<tr data-archive="<?php echo esc_attr( $shcm_archive['name'] ); ?>">
					<td>
						<code><?php echo esc_html( $shcm_archive['name'] ); ?></code>
						<?php if ( ! empty( $shcm_archive['encrypted'] ) ) : ?>
							<span class="shcm-tag shcm-tag--lock"><?php esc_html_e( 'encrypted', 'sh-clone-migration' ); ?></span>
						<?php endif; ?>
						<?php if ( empty( $shcm_archive['complete'] ) ) : ?>
							<span class="shcm-tag shcm-tag--warn"><?php esc_html_e( 'incomplete', 'sh-clone-migration' ); ?></span>
						<?php endif; ?>
						<div class="shcm-verify-result" data-role="verify-result"></div>
					</td>
					<td><?php echo esc_html( $shcm_archive['source'] ? $shcm_archive['source'] : '—' ); ?></td>
					<td>
						<?php
						printf(
							/* translators: 1: number of files, 2: number of tables */
							esc_html__( '%1$s files, %2$s tables', 'sh-clone-migration' ),
							esc_html( number_format_i18n( $shcm_archive['files'] ) ),
							esc_html( number_format_i18n( $shcm_archive['tables'] ) )
						);
						?>
						<?php if ( ! empty( $shcm_archive['wordpress'] ) ) : ?>
							<br><span class="description"><?php echo esc_html( 'WordPress ' . $shcm_archive['wordpress'] . ' / PHP ' . $shcm_archive['php'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( Bytes::format( $shcm_archive['size'] ) ); ?></td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $shcm_archive['created'] ) ); ?></td>
					<td class="shcm-actions">
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=shcm_download&archive=' . rawurlencode( $shcm_archive['name'] ) ), 'shcm_download' ) ); ?>">
							<?php esc_html_e( 'Download', 'sh-clone-migration' ); ?>
						</a>
						<button type="button" class="button button-small" data-action="verify"><?php esc_html_e( 'Verify', 'sh-clone-migration' ); ?></button>
						<button type="button" class="button button-small button-link-delete" data-action="delete"><?php esc_html_e( 'Delete', 'sh-clone-migration' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Migration jobs', 'sh-clone-migration' ); ?></h2>
	<?php if ( empty( $shcm_jobs ) ) : ?>
		<p class="shcm-empty"><?php esc_html_e( 'No migration jobs recorded.', 'sh-clone-migration' ); ?></p>
	<?php else : ?>
		<table class="widefat striped shcm-table">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Job', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Type', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Status', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Progress', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Updated', 'sh-clone-migration' ); ?></th>
				<th></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $shcm_jobs as $shcm_job ) : ?>
				<tr data-job="<?php echo esc_attr( $shcm_job->id() ); ?>">
					<td><code><?php echo esc_html( $shcm_job->id() ); ?></code></td>
					<td><?php echo esc_html( $shcm_job->type() ); ?></td>
					<td><span class="shcm-status shcm-status--<?php echo esc_attr( $shcm_job->status() ); ?>"><?php echo esc_html( $shcm_job->status() ); ?></span></td>
					<td><?php echo esc_html( number_format_i18n( (float) $shcm_job->get( 'progress' ), 1 ) ); ?>%</td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $shcm_job->get( 'updated_at' ) ) ); ?></td>
					<td class="shcm-actions">
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=shcm_download_log&job_id=' . rawurlencode( $shcm_job->id() ) ), 'shcm_download_log' ) ); ?>">
							<?php esc_html_e( 'Log', 'sh-clone-migration' ); ?>
						</a>
						<?php if ( $shcm_job->isRunnable() ) : ?>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ( 'import' === $shcm_job->type() ? 'shcm-import' : 'shcm' ) . '&job=' . rawurlencode( $shcm_job->id() ) ) ); ?>">
								<?php esc_html_e( 'Resume', 'sh-clone-migration' ); ?>
							</a>
						<?php endif; ?>
						<button type="button" class="button button-small button-link-delete" data-action="delete-job"><?php esc_html_e( 'Remove', 'sh-clone-migration' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
