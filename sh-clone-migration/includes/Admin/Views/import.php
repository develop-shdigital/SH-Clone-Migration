<?php
/**
 * Import screen.
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

global $wpdb;

$shcm_archives = $catalog->all();
$shcm_posts    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status != 'auto-draft'" );
$shcm_users    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
$shcm_has_data = $shcm_posts > 3 || $shcm_users > 1;

Menu::header(
	__( 'Import a migration', 'sh-clone-migration' ),
	__( 'Restore a .wpress archive onto this installation.', 'sh-clone-migration' )
);
?>

<?php if ( $shcm_has_data ) : ?>
	<div class="shcm-alert shcm-alert--warning">
		<strong><?php esc_html_e( 'This destination already contains a live WordPress site.', 'sh-clone-migration' ); ?></strong>
		<?php
		printf(
			/* translators: 1: number of posts, 2: number of users */
			esc_html__( 'It has %1$s posts and pages and %2$s users. Restoring an archive replaces them.', 'sh-clone-migration' ),
			esc_html( number_format_i18n( $shcm_posts ) ),
			esc_html( number_format_i18n( $shcm_users ) )
		);
		?>
		<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=shcm' ) ); ?>">
			<?php esc_html_e( 'Create a safety backup first', 'sh-clone-migration' ); ?>
		</a>
	</div>
<?php endif; ?>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Upload an archive', 'sh-clone-migration' ); ?></h2>

	<div class="shcm-dropzone" id="shcm-dropzone" tabindex="0" role="button"
		aria-label="<?php esc_attr_e( 'Drop a .wpress file here or choose a file', 'sh-clone-migration' ); ?>">
		<span class="dashicons dashicons-upload"></span>
		<p><strong><?php esc_html_e( 'Drag &amp; drop your .wpress file here', 'sh-clone-migration' ); ?></strong></p>
		<p class="shcm-dropzone__or"><?php esc_html_e( 'or', 'sh-clone-migration' ); ?></p>
		<button type="button" class="button" id="shcm-choose-file"><?php esc_html_e( 'Choose File', 'sh-clone-migration' ); ?></button>
		<input type="file" id="shcm-file-input" accept=".wpress" hidden>
		<p class="shcm-dropzone__hint">
			<?php
			printf(
				/* translators: %s: chunk size */
				esc_html__( 'Uploaded in resumable chunks of %s, so the PHP upload limit does not cap the archive size.', 'sh-clone-migration' ),
				esc_html( Bytes::format( $settings->getInt( 'upload_chunk_size', 5242880 ) ) )
			);
			?>
		</p>
	</div>

	<div class="shcm-upload shcm-hidden" id="shcm-upload-progress">
		<div class="shcm-bar"><div class="shcm-bar__fill" data-role="upload-bar"></div></div>
		<div class="shcm-upload__meta">
			<span data-role="upload-label"></span>
			<button type="button" class="button button-small" id="shcm-upload-abort"><?php esc_html_e( 'Abort', 'sh-clone-migration' ); ?></button>
		</div>
	</div>

	<details class="shcm-advanced">
		<summary><?php esc_html_e( 'Use a file already on the server', 'sh-clone-migration' ); ?></summary>
		<p>
			<?php
			printf(
				/* translators: %s: directory path */
				esc_html__( 'Upload very large archives over SFTP into %s and register them here. This avoids the browser entirely.', 'sh-clone-migration' ),
				'<code>' . esc_html( $plugin->storage()->archives() ) . '</code>'
			);
			?>
		</p>
		<p>
			<input type="text" id="shcm-adopt-path" class="large-text code" placeholder="<?php echo esc_attr( $plugin->storage()->archives() . '/site.wpress' ); ?>">
			<button type="button" class="button" id="shcm-adopt-archive"><?php esc_html_e( 'Register archive', 'sh-clone-migration' ); ?></button>
		</p>
	</details>
</div>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Available archives', 'sh-clone-migration' ); ?></h2>
	<?php if ( empty( $shcm_archives ) ) : ?>
		<p class="shcm-empty" id="shcm-no-archives"><?php esc_html_e( 'No archives are available yet. Upload one above.', 'sh-clone-migration' ); ?></p>
	<?php endif; ?>

	<div id="shcm-archive-list" class="shcm-archive-list">
		<?php foreach ( $shcm_archives as $shcm_archive ) : ?>
			<label class="shcm-archive" data-archive="<?php echo esc_attr( $shcm_archive['name'] ); ?>"
				data-encrypted="<?php echo esc_attr( $shcm_archive['encrypted'] ? '1' : '0' ); ?>">
				<input type="radio" name="shcm_archive" value="<?php echo esc_attr( $shcm_archive['name'] ); ?>">
				<span class="shcm-archive__body">
					<span class="shcm-archive__name"><?php echo esc_html( $shcm_archive['name'] ); ?></span>
					<span class="shcm-archive__meta">
						<?php echo esc_html( Bytes::format( $shcm_archive['size'] ) ); ?>
						&middot; <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $shcm_archive['created'] ) ); ?>
						<?php if ( ! empty( $shcm_archive['source'] ) ) : ?>
							&middot; <?php echo esc_html( $shcm_archive['source'] ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $shcm_archive['encrypted'] ) ) : ?>
							<span class="shcm-tag shcm-tag--lock"><?php esc_html_e( 'encrypted', 'sh-clone-migration' ); ?></span>
						<?php endif; ?>
						<?php if ( empty( $shcm_archive['complete'] ) ) : ?>
							<span class="shcm-tag shcm-tag--warn"><?php esc_html_e( 'incomplete', 'sh-clone-migration' ); ?></span>
						<?php endif; ?>
					</span>
				</span>
			</label>
		<?php endforeach; ?>
	</div>

	<div class="shcm-details shcm-hidden" id="shcm-archive-details"></div>
</div>

<div class="shcm-panel shcm-hidden" id="shcm-import-options">
	<h2><?php esc_html_e( 'Restore options', 'sh-clone-migration' ); ?></h2>

	<div class="shcm-grid">
		<p>
			<label for="shcm-destination-url"><?php esc_html_e( 'Destination URL', 'sh-clone-migration' ); ?></label>
			<input type="url" id="shcm-destination-url" class="regular-text" value="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>">
			<span class="description"><?php esc_html_e( 'Detected from this installation. Every reference to the source URL is rewritten to this one.', 'sh-clone-migration' ); ?></span>
		</p>
		<p>
			<label for="shcm-import-password"><?php esc_html_e( 'Migration password', 'sh-clone-migration' ); ?></label>
			<input type="password" id="shcm-import-password" class="regular-text" autocomplete="off">
			<span class="description"><?php esc_html_e( 'Only needed for encrypted archives.', 'sh-clone-migration' ); ?></span>
		</p>
		<p>
			<label for="shcm-import-mode"><?php esc_html_e( 'Database mode', 'sh-clone-migration' ); ?></label>
			<select id="shcm-import-mode">
				<option value="replace"><?php esc_html_e( 'Full replacement (recommended for cloning)', 'sh-clone-migration' ); ?></option>
				<option value="merge"><?php esc_html_e( 'Controlled import: keep tables that are not in the archive', 'sh-clone-migration' ); ?></option>
			</select>
		</p>
	</div>

	<fieldset class="shcm-checks">
		<label><input type="checkbox" id="shcm-verify-archive" checked>
			<?php esc_html_e( 'Verify every checksum before touching this site', 'sh-clone-migration' ); ?></label>
		<label><input type="checkbox" id="shcm-rollback" <?php checked( $settings->getBool( 'create_rollback_point', true ) ); ?>>
			<?php esc_html_e( 'Create a rollback point of the current database first', 'sh-clone-migration' ); ?></label>
		<label><input type="checkbox" id="shcm-replace-urls" <?php checked( $settings->getBool( 'replace_urls', true ) ); ?>>
			<?php esc_html_e( 'Rewrite the source URL to the destination URL', 'sh-clone-migration' ); ?></label>
		<label><input type="checkbox" id="shcm-replace-bare">
			<?php esc_html_e( 'Also replace bare occurrences of the source domain (advanced)', 'sh-clone-migration' ); ?></label>
		<label><input type="checkbox" id="shcm-skip-core">
			<?php esc_html_e( 'Skip WordPress core files contained in the archive', 'sh-clone-migration' ); ?></label>
		<label><input type="checkbox" id="shcm-delete-after">
			<?php esc_html_e( 'Delete the archive after a successful restore', 'sh-clone-migration' ); ?></label>
	</fieldset>

	<div class="shcm-alert shcm-alert--danger">
		<strong><?php esc_html_e( 'WARNING', 'sh-clone-migration' ); ?></strong>
		<p><?php esc_html_e( 'This migration will replace the destination website. Existing files and database data may be overwritten. Create a backup before continuing.', 'sh-clone-migration' ); ?></p>
		<label class="shcm-confirm">
			<input type="checkbox" id="shcm-confirm">
			<?php esc_html_e( 'I understand that this website will be replaced.', 'sh-clone-migration' ); ?>
		</label>
	</div>

	<p>
		<button type="button" class="button button-primary button-hero" id="shcm-start-import" disabled>
			<?php esc_html_e( 'Restore This Website', 'sh-clone-migration' ); ?>
		</button>
	</p>
</div>

<div class="shcm-panel shcm-hidden" id="shcm-progress-panel">
	<div class="shcm-panel__head">
		<h2 id="shcm-progress-title"><?php esc_html_e( 'Restoring...', 'sh-clone-migration' ); ?></h2>
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
