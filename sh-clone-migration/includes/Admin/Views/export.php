<?php
/**
 * Export screen.
 *
 * @package SHCM
 * @var \SHCM\Core\Plugin      $plugin
 * @var \SHCM\Core\Settings    $settings
 * @var \SHCM\Core\Environment $environment
 * @var \SHCM\Archive\Catalog  $catalog
 */

defined( 'ABSPATH' ) || exit;

use SHCM\Admin\Menu;
use SHCM\Support\Bytes;

$shcm_warnings = $environment->warnings();
$shcm_archives = array_slice( $catalog->all(), 0, 5 );
$shcm_estimate = $environment->estimateSiteSize();
$shcm_can_encrypt = \SHCM\Crypto\Cipher::isAvailable();

Menu::header(
	__( 'SH Clone Migration', 'sh-clone-migration' ),
	__( 'Clone your entire WordPress site into a single portable .wpress archive.', 'sh-clone-migration' )
);
?>

<?php foreach ( $shcm_warnings as $shcm_warning ) : ?>
	<div class="shcm-alert shcm-alert--<?php echo esc_attr( $shcm_warning['level'] ); ?>">
		<?php echo esc_html( $shcm_warning['message'] ); ?>
		<?php if ( ! empty( $shcm_warning['code'] ) ) : ?>
			<pre class="shcm-code"><?php echo esc_html( $shcm_warning['code'] ); ?></pre>
		<?php endif; ?>
	</div>
<?php endforeach; ?>

<div class="shcm-hero" id="shcm-export-hero">
	<h2><?php esc_html_e( 'Create a migration archive', 'sh-clone-migration' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: estimated size */
			esc_html__( 'Everything is included by default: the whole database, all plugins, themes, uploads and must-use plugins. Estimated size before compression: %s.', 'sh-clone-migration' ),
			'<strong>' . esc_html( Bytes::format( $shcm_estimate ) ) . '</strong>'
		);
		?>
	</p>
	<button type="button" class="button button-primary button-hero" id="shcm-start-export">
		<?php esc_html_e( 'Create Migration', 'sh-clone-migration' ); ?>
	</button>

	<p class="shcm-hero__hint">
		<?php esc_html_e( 'Large sites are handled in resumable steps. Keep this tab open, or let WP-Cron continue in the background.', 'sh-clone-migration' ); ?>
	</p>
</div>

<details class="shcm-advanced" id="shcm-export-advanced">
	<summary><?php esc_html_e( 'Advanced settings', 'sh-clone-migration' ); ?></summary>
	<div class="shcm-grid">
		<p>
			<label for="shcm-export-name"><?php esc_html_e( 'Archive name', 'sh-clone-migration' ); ?></label>
			<input type="text" id="shcm-export-name" class="regular-text" placeholder="<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) . '-' . gmdate( 'Ymd-His' ) ); ?>">
		</p>
		<p>
			<label for="shcm-export-password"><?php esc_html_e( 'Migration password (optional)', 'sh-clone-migration' ); ?></label>
			<input type="password" id="shcm-export-password" class="regular-text" autocomplete="new-password" <?php disabled( ! $shcm_can_encrypt ); ?>>
			<span class="description">
				<?php
				echo $shcm_can_encrypt
					? esc_html__( 'Encrypts the archive contents. The password is never stored: you need it to resume, verify and restore the archive.', 'sh-clone-migration' )
					: esc_html__( 'Encryption is unavailable on this server (libsodium and OpenSSL are both missing).', 'sh-clone-migration' );
				?>
			</span>
		</p>
		<p>
			<label><input type="checkbox" id="shcm-export-include-core" <?php checked( $settings->getBool( 'include_core' ) ); ?>>
				<?php esc_html_e( 'Include WordPress core files (wp-admin, wp-includes and the root files)', 'sh-clone-migration' ); ?></label>
			<span class="description"><?php esc_html_e( 'Not needed for a normal clone: the destination already has WordPress. wp-config.php is never exported.', 'sh-clone-migration' ); ?></span>
		</p>
		<p>
			<label><input type="checkbox" id="shcm-export-include-foreign">
				<?php esc_html_e( 'Include tables that belong to another WordPress installation in the same database', 'sh-clone-migration' ); ?></label>
		</p>
		<p>
			<label for="shcm-export-exclusions"><?php esc_html_e( 'Additional exclusions (one pattern per line)', 'sh-clone-migration' ); ?></label>
			<textarea id="shcm-export-exclusions" rows="4" class="large-text code" placeholder="wp-content/uploads/large-videos&#10;*.log"></textarea>
		</p>
	</div>
</details>

<div class="shcm-panel shcm-hidden" id="shcm-progress-panel">
	<div class="shcm-panel__head">
		<h2 id="shcm-progress-title"><?php esc_html_e( 'Preparing migration...', 'sh-clone-migration' ); ?></h2>
		<div class="shcm-panel__actions">
			<button type="button" class="button" id="shcm-cancel-job"><?php esc_html_e( 'Cancel', 'sh-clone-migration' ); ?></button>
		</div>
	</div>

	<div class="shcm-overall">
		<div class="shcm-bar"><div class="shcm-bar__fill" data-role="overall-bar"></div></div>
		<div class="shcm-overall__meta">
			<span data-role="overall-label">0%</span>
			<span data-role="overall-message"></span>
		</div>
	</div>

	<ul class="shcm-stages" data-role="stages"></ul>

	<div class="shcm-facts" data-role="facts"></div>

	<details class="shcm-log">
		<summary><?php esc_html_e( 'Migration log', 'sh-clone-migration' ); ?></summary>
		<pre data-role="log"></pre>
	</details>
</div>

<div class="shcm-panel shcm-hidden" id="shcm-result-panel">
	<h2 data-role="result-title"></h2>
	<div data-role="result-body"></div>
</div>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Recent migrations', 'sh-clone-migration' ); ?></h2>
	<?php if ( empty( $shcm_archives ) ) : ?>
		<p class="shcm-empty"><?php esc_html_e( 'No migration archives yet.', 'sh-clone-migration' ); ?></p>
	<?php else : ?>
		<div class="shcm-table-scroll">
		<table class="widefat striped shcm-table">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Archive', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Size', 'sh-clone-migration' ); ?></th>
				<th><?php esc_html_e( 'Created', 'sh-clone-migration' ); ?></th>
				<th></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $shcm_archives as $shcm_archive ) : ?>
				<tr>
					<td>
						<code><?php echo esc_html( $shcm_archive['name'] ); ?></code>
						<?php if ( ! empty( $shcm_archive['encrypted'] ) ) : ?>
							<span class="shcm-tag shcm-tag--lock"><?php esc_html_e( 'encrypted', 'sh-clone-migration' ); ?></span>
						<?php endif; ?>
						<?php if ( empty( $shcm_archive['complete'] ) ) : ?>
							<span class="shcm-tag shcm-tag--warn"><?php esc_html_e( 'incomplete', 'sh-clone-migration' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( Bytes::format( $shcm_archive['size'] ) ); ?>
						<br><span class="description"><?php echo esc_html( \SHCM\Admin\ArchiveSummary::exactSize( $shcm_archive['size'] ) ); ?></span>
					</td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $shcm_archive['created'] ) ); ?></td>
					<td class="shcm-actions">
						<?php if ( ! empty( $shcm_archive['complete'] ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=shcm_download&archive=' . rawurlencode( $shcm_archive['name'] ) ), 'shcm_download' ) ); ?>">
								<?php esc_html_e( 'Download', 'sh-clone-migration' ); ?>
							</a>
						<?php endif; ?>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=shcm-backups' ) ); ?>">
							<?php esc_html_e( 'Manage', 'sh-clone-migration' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
</div>
