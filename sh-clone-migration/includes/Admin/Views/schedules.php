<?php
/**
 * Scheduled backups screen.
 *
 * @package SHCM
 * @var \SHCM\Core\Plugin      $plugin
 * @var \SHCM\Admin\Controller $controller
 */

defined( 'ABSPATH' ) || exit;

use SHCM\Admin\BackupController;
use SHCM\Admin\DriveAuth;
use SHCM\Admin\Menu;

$shcm_screen  = new BackupController( $plugin, $controller );
$shcm_status  = $shcm_screen->status();
$shcm_config  = $shcm_status['schedule']['config'];
$shcm_drive   = $shcm_status['drive'];
$shcm_notice  = DriveAuth::takeNotice();
$shcm_weekday = array(
	0 => __( 'Sunday', 'sh-clone-migration' ),
	1 => __( 'Monday', 'sh-clone-migration' ),
	2 => __( 'Tuesday', 'sh-clone-migration' ),
	3 => __( 'Wednesday', 'sh-clone-migration' ),
	4 => __( 'Thursday', 'sh-clone-migration' ),
	5 => __( 'Friday', 'sh-clone-migration' ),
	6 => __( 'Saturday', 'sh-clone-migration' ),
);
$shcm_can_encrypt = \SHCM\Crypto\Cipher::isAvailable();

Menu::header(
	__( 'Scheduled backups', 'sh-clone-migration' ),
	__( 'Automatic backups on a schedule, kept on this server and, if you like, on your Google Drive.', 'sh-clone-migration' )
);
?>

<script type="application/json" id="shcm-schedule-data"><?php echo wp_json_encode( $shcm_status, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

<?php if ( null !== $shcm_notice ) : ?>
	<div class="shcm-alert shcm-alert--<?php echo ! empty( $shcm_notice['ok'] ) ? 'success' : 'error'; ?>" role="status">
		<?php echo esc_html( (string) $shcm_notice['message'] ); ?>
	</div>
<?php endif; ?>

<div id="shcm-schedule-alerts" aria-live="polite"></div>

<div class="shcm-stats" id="shcm-schedule-stats"></div>

<div class="shcm-panel" id="shcm-backup-now-panel">
	<div class="shcm-panel__head">
		<h2><?php esc_html_e( 'Back up now', 'sh-clone-migration' ); ?></h2>
	</div>
	<p class="description"><?php esc_html_e( 'Makes a backup straight away with the settings below. It keeps running in the background if you leave this page.', 'sh-clone-migration' ); ?></p>
	<p class="shcm-backup-now">
		<button type="button" class="button button-primary" id="shcm-backup-now"><?php esc_html_e( 'Back Up Now', 'sh-clone-migration' ); ?></button>
		<label class="shcm-inline" id="shcm-backup-now-drive-label">
			<input type="checkbox" id="shcm-backup-now-drive" <?php checked( ! empty( $shcm_config['gdrive'] ) ); ?>>
			<?php esc_html_e( 'Also upload to Google Drive', 'sh-clone-migration' ); ?>
		</label>
	</p>
</div>

<div class="shcm-panel shcm-hidden" id="shcm-progress-panel">
	<div class="shcm-panel__head">
		<h2 id="shcm-progress-title"><?php esc_html_e( 'Backing up...', 'sh-clone-migration' ); ?></h2>
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
		<summary><?php esc_html_e( 'Backup log', 'sh-clone-migration' ); ?></summary>
		<pre data-role="log"></pre>
	</details>
</div>

<div class="shcm-panel shcm-hidden" id="shcm-result-panel">
	<h2 data-role="result-title"></h2>
	<div data-role="result-body"></div>
</div>

<form id="shcm-schedule-form" class="shcm-panel" novalidate>
	<h2><?php esc_html_e( 'Schedule', 'sh-clone-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="shcm-frequency"><?php esc_html_e( 'Frequency', 'sh-clone-migration' ); ?></label></th>
			<td>
				<select id="shcm-frequency" name="frequency">
					<option value="manual" <?php selected( $shcm_config['frequency'], 'manual' ); ?>><?php esc_html_e( 'Only on demand', 'sh-clone-migration' ); ?></option>
					<option value="daily" <?php selected( $shcm_config['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'sh-clone-migration' ); ?></option>
					<option value="weekly" <?php selected( $shcm_config['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'sh-clone-migration' ); ?></option>
					<option value="monthly" <?php selected( $shcm_config['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'sh-clone-migration' ); ?></option>
				</select>
				<span class="shcm-when" data-show-for="weekly">
					<label for="shcm-weekday"><?php esc_html_e( 'on', 'sh-clone-migration' ); ?></label>
					<select id="shcm-weekday" name="weekday">
						<?php foreach ( $shcm_weekday as $shcm_day => $shcm_label ) : ?>
							<option value="<?php echo esc_attr( $shcm_day ); ?>" <?php selected( (int) $shcm_config['weekday'], $shcm_day ); ?>><?php echo esc_html( $shcm_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
				<span class="shcm-when" data-show-for="monthly">
					<label for="shcm-monthday"><?php esc_html_e( 'on day', 'sh-clone-migration' ); ?></label>
					<select id="shcm-monthday" name="monthday">
						<?php for ( $shcm_day = 1; $shcm_day <= 28; $shcm_day++ ) : ?>
							<option value="<?php echo esc_attr( $shcm_day ); ?>" <?php selected( (int) $shcm_config['monthday'], $shcm_day ); ?>><?php echo esc_html( $shcm_day ); ?></option>
						<?php endfor; ?>
						<option value="-1" <?php selected( (int) $shcm_config['monthday'], -1 ); ?>><?php esc_html_e( 'last day', 'sh-clone-migration' ); ?></option>
					</select>
				</span>
				<span class="shcm-when" data-show-for="daily weekly monthly">
					<label for="shcm-time"><?php esc_html_e( 'at', 'sh-clone-migration' ); ?></label>
					<input type="time" id="shcm-time" name="time" value="<?php echo esc_attr( $shcm_config['time'] ); ?>" required>
				</span>
				<p class="description">
					<?php
					printf(
						/* translators: %s: timezone name */
						esc_html__( 'Times are in the site timezone (%s), set under Settings → General.', 'sh-clone-migration' ),
						esc_html( $shcm_status['schedule']['timezone'] )
					);
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-contents"><?php esc_html_e( 'What to back up', 'sh-clone-migration' ); ?></label></th>
			<td>
				<select id="shcm-contents" name="contents">
					<option value="full" <?php selected( $shcm_config['contents'], 'full' ); ?>><?php esc_html_e( 'Database and files', 'sh-clone-migration' ); ?></option>
					<option value="database" <?php selected( $shcm_config['contents'], 'database' ); ?>><?php esc_html_e( 'Database only', 'sh-clone-migration' ); ?></option>
					<option value="files" <?php selected( $shcm_config['contents'], 'files' ); ?>><?php esc_html_e( 'Files only', 'sh-clone-migration' ); ?></option>
				</select>
				<p><label><input type="checkbox" name="include_core" <?php checked( ! empty( $shcm_config['include_core'] ) ); ?>> <?php esc_html_e( 'Include WordPress core files (wp-admin, wp-includes)', 'sh-clone-migration' ); ?></label></p>
				<p>
					<label for="shcm-schedule-exclusions"><?php esc_html_e( 'Also exclude (one pattern per line, e.g. wp-content/uploads/videos or *.log)', 'sh-clone-migration' ); ?></label><br>
					<textarea id="shcm-schedule-exclusions" name="exclusions" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $shcm_config['exclusions'] ) ); ?></textarea>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Where to keep backups', 'sh-clone-migration' ); ?></th>
			<td>
				<p>
					<label for="shcm-keep-local"><?php esc_html_e( 'Keep the newest', 'sh-clone-migration' ); ?></label>
					<input type="number" id="shcm-keep-local" name="keep_local" min="0" max="100" class="small-text" value="<?php echo esc_attr( (int) $shcm_config['keep_local'] ); ?>">
					<?php esc_html_e( 'backups on this server', 'sh-clone-migration' ); ?>
				</p>
				<p>
					<label><input type="checkbox" name="gdrive" id="shcm-gdrive-toggle" <?php checked( ! empty( $shcm_config['gdrive'] ) ); ?>> <?php esc_html_e( 'Store backups on Google Drive', 'sh-clone-migration' ); ?></label>
				</p>
				<p class="shcm-gdrive-only">
					<label for="shcm-keep-remote"><?php esc_html_e( 'Keep the newest', 'sh-clone-migration' ); ?></label>
					<input type="number" id="shcm-keep-remote" name="keep_remote" min="1" max="1000" class="small-text" value="<?php echo esc_attr( (int) $shcm_config['keep_remote'] ); ?>">
					<?php esc_html_e( 'backups on Google Drive', 'sh-clone-migration' ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Only backups count: manual exports are never deleted. With Google Drive, "0 on this server" deletes each backup here once its copy on Google Drive has been verified; a backup whose upload failed is always kept here.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Encryption', 'sh-clone-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="encrypt" id="shcm-encrypt-toggle" <?php checked( ! empty( $shcm_config['encrypt'] ) ); ?> <?php disabled( ! $shcm_can_encrypt ); ?>> <?php esc_html_e( 'Encrypt backups with a password', 'sh-clone-migration' ); ?></label>
				<div class="shcm-encrypt-only">
					<p>
						<input type="password" id="shcm-backup-password" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $shcm_status['schedule']['has_password'] ? __( 'Password stored (leave empty to keep it)', 'sh-clone-migration' ) : __( 'Password', 'sh-clone-migration' ) ); ?>">
						<input type="password" id="shcm-backup-password-confirm" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Repeat the password', 'sh-clone-migration' ); ?>">
					</p>
					<p class="description"><?php esc_html_e( 'Recommended when backups leave the server. The password is stored encrypted with the keys in wp-config.php so that backups can run unattended. Write it down: without it a backup cannot be restored, and if wp-config.php changes you will be asked for it again.', 'sh-clone-migration' ); ?></p>
				</div>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-notify-on"><?php esc_html_e( 'E-mail me', 'sh-clone-migration' ); ?></label></th>
			<td>
				<select id="shcm-notify-on" name="notify_on">
					<option value="failure" <?php selected( $shcm_config['notify_on'], 'failure' ); ?>><?php esc_html_e( 'When a backup fails or is not uploaded', 'sh-clone-migration' ); ?></option>
					<option value="always" <?php selected( $shcm_config['notify_on'], 'always' ); ?>><?php esc_html_e( 'After every backup', 'sh-clone-migration' ); ?></option>
					<option value="never" <?php selected( $shcm_config['notify_on'], 'never' ); ?>><?php esc_html_e( 'Never', 'sh-clone-migration' ); ?></option>
				</select>
				<label class="shcm-inline" for="shcm-notify-email"><?php esc_html_e( 'at', 'sh-clone-migration' ); ?></label>
				<input type="email" id="shcm-notify-email" name="notify_email" class="regular-text" value="<?php echo esc_attr( $shcm_config['notify_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
			</td>
		</tr>
	</table>
	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'sh-clone-migration' ); ?></button>
		<span class="shcm-save-feedback" id="shcm-schedule-feedback" role="status"></span>
	</p>
</form>

<div class="shcm-panel" id="shcm-gdrive-panel">
	<div class="shcm-panel__head">
		<h2><?php esc_html_e( 'Google Drive', 'sh-clone-migration' ); ?></h2>
		<div class="shcm-panel__actions" id="shcm-gdrive-actions"></div>
	</div>
	<div id="shcm-gdrive-status"></div>

	<div id="shcm-gdrive-setup">
		<details class="shcm-advanced shcm-setup-guide" <?php echo 'not_configured' === $shcm_drive['state'] ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'How to connect your Google Drive (about 5 minutes, once)', 'sh-clone-migration' ); ?></summary>
			<p><?php esc_html_e( 'Backups go to your own Google account through your own Google Cloud project, so no third party ever sees them. The plugin only gets access to the files it creates itself.', 'sh-clone-migration' ); ?></p>
			<ol class="shcm-steps">
				<li><?php echo wp_kses( __( 'Open the <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener noreferrer">Google Cloud console</a> and create a project (any name).', 'sh-clone-migration' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
				<li><?php echo wp_kses( __( 'Enable the <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener noreferrer">Google Drive API</a> for the project.', 'sh-clone-migration' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
				<li><?php esc_html_e( 'Under "Google Auth Platform", fill in Branding (an app name and your e-mail; do not upload a logo), choose the audience "External", and add the scope .../auth/drive.file under Data Access.', 'sh-clone-migration' ); ?></li>
				<li><strong><?php esc_html_e( 'Under Audience, click "Publish app" (status "In production").', 'sh-clone-migration' ); ?></strong> <?php esc_html_e( 'In "Testing" status Google stops the access after 7 days and backups would stop uploading. The drive.file scope needs no verification by Google.', 'sh-clone-migration' ); ?></li>
				<li>
					<?php esc_html_e( 'Under Clients, create an OAuth client of type "Web application" with this authorized redirect URI:', 'sh-clone-migration' ); ?>
					<span class="shcm-copy">
						<code id="shcm-redirect-uri"><?php echo esc_html( $shcm_drive['redirect_uri'] ); ?></code>
						<button type="button" class="button button-small" data-copy="#shcm-redirect-uri"><?php esc_html_e( 'Copy', 'sh-clone-migration' ); ?></button>
					</span>
				</li>
				<li><?php esc_html_e( 'Copy the client ID and the client secret (Google shows the secret in full only once), paste them below and click Connect.', 'sh-clone-migration' ); ?></li>
			</ol>
			<?php if ( empty( $shcm_drive['https'] ) ) : ?>
				<div class="shcm-alert shcm-alert--warning"><?php esc_html_e( 'Google only accepts HTTPS redirect URIs (except for localhost). This site is not using HTTPS, so connecting will fail until it does.', 'sh-clone-migration' ); ?></div>
			<?php endif; ?>
		</details>

		<form id="shcm-gdrive-credentials" class="shcm-grid" novalidate>
			<p>
				<label for="shcm-gdrive-client-id"><?php esc_html_e( 'Client ID', 'sh-clone-migration' ); ?></label>
				<input type="text" id="shcm-gdrive-client-id" name="client_id" class="large-text code" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $shcm_drive['client_id'] ); ?>" <?php disabled( $shcm_drive['id_constant'] ); ?>>
			</p>
			<p>
				<label for="shcm-gdrive-client-secret"><?php esc_html_e( 'Client secret', 'sh-clone-migration' ); ?></label>
				<input type="password" id="shcm-gdrive-client-secret" name="client_secret" class="large-text code" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( $shcm_drive['secret_constant'] ? __( 'Set in wp-config.php', 'sh-clone-migration' ) : ( $shcm_drive['has_secret'] ? __( 'Stored (leave empty to keep it)', 'sh-clone-migration' ) : '' ) ); ?>" <?php disabled( $shcm_drive['secret_constant'] ); ?>>
			</p>
			<p class="shcm-grid__full">
				<button type="submit" class="button button-primary" id="shcm-gdrive-connect"><?php esc_html_e( 'Connect Google Drive', 'sh-clone-migration' ); ?></button>
				<?php if ( $shcm_drive['from_constants'] ) : ?>
					<span class="description"><?php esc_html_e( 'The client ID and secret come from wp-config.php (SHCM_GDRIVE_CLIENT_ID / SHCM_GDRIVE_CLIENT_SECRET).', 'sh-clone-migration' ); ?></span>
				<?php elseif ( $shcm_drive['id_constant'] ) : ?>
					<span class="description"><?php esc_html_e( 'The client ID comes from wp-config.php (SHCM_GDRIVE_CLIENT_ID).', 'sh-clone-migration' ); ?></span>
				<?php elseif ( $shcm_drive['secret_constant'] ) : ?>
					<span class="description"><?php esc_html_e( 'The client secret comes from wp-config.php (SHCM_GDRIVE_CLIENT_SECRET).', 'sh-clone-migration' ); ?></span>
				<?php endif; ?>
				<span class="shcm-save-feedback" id="shcm-gdrive-feedback" role="status"></span>
			</p>
		</form>
	</div>

	<div id="shcm-gdrive-files" class="shcm-hidden">
		<h3><?php esc_html_e( 'Backups on Google Drive', 'sh-clone-migration' ); ?></h3>
		<div data-role="files"></div>
	</div>
</div>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'History', 'sh-clone-migration' ); ?></h2>
	<div id="shcm-schedule-history"></div>
	<p class="description">
		<?php
		printf(
			/* translators: %s: WP-CLI command */
			esc_html__( 'Backups rely on WP-Cron, which runs when someone visits the site. For exact timing on quiet sites, add a system cron job that runs %s every 5 minutes.', 'sh-clone-migration' ),
			'<code>wp shcm backup run</code>'
		);
		?>
	</p>
</div>
