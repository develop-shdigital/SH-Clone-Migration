<?php
/**
 * Settings screen.
 *
 * @package SHCM
 * @var \SHCM\Core\Plugin   $plugin
 * @var \SHCM\Core\Settings $settings
 */

defined( 'ABSPATH' ) || exit;

use SHCM\Admin\Menu;
use SHCM\Core\Settings;

$shcm_values = $settings->all();

Menu::header(
	__( 'Settings', 'sh-clone-migration' ),
	__( 'Defaults for new migrations. Nothing here caps how large a migration may be.', 'sh-clone-migration' )
);
?>

<form id="shcm-settings-form" class="shcm-panel">
	<h2><?php esc_html_e( 'What to migrate', 'sh-clone-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'WordPress core files', 'sh-clone-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="include_core" <?php checked( $shcm_values['include_core'] ); ?>>
					<?php esc_html_e( 'Include wp-admin, wp-includes and the root files in new archives', 'sh-clone-migration' ); ?></label>
				<p class="description"><?php esc_html_e( 'wp-config.php is never exported and never overwritten.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Default exclusions', 'sh-clone-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="use_default_exclusions" <?php checked( $shcm_values['use_default_exclusions'] ); ?>>
					<?php esc_html_e( 'Skip caches and other regenerable directories', 'sh-clone-migration' ); ?></label>
				<p class="description"><code><?php echo esc_html( implode( ', ', array_slice( Settings::defaultExclusions(), 0, 8 ) ) ); ?>, …</code></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-exclude-directories"><?php esc_html_e( 'Excluded directories', 'sh-clone-migration' ); ?></label></th>
			<td>
				<textarea id="shcm-exclude-directories" name="exclude_directories" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $shcm_values['exclude_directories'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One per line, relative to the WordPress root, for example wp-content/uploads/backups.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-exclude-patterns"><?php esc_html_e( 'Excluded file patterns', 'sh-clone-migration' ); ?></label></th>
			<td>
				<textarea id="shcm-exclude-patterns" name="exclude_patterns" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $shcm_values['exclude_patterns'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Globs such as *.log or */node_modules.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Engine', 'sh-clone-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="shcm-compression"><?php esc_html_e( 'Compression', 'sh-clone-migration' ); ?></label></th>
			<td>
				<select id="shcm-compression" name="compression">
					<option value="auto" <?php selected( $shcm_values['compression'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'sh-clone-migration' ); ?></option>
					<option value="gzip" <?php selected( $shcm_values['compression'], 'gzip' ); ?>><?php esc_html_e( 'Always compress', 'sh-clone-migration' ); ?></option>
					<option value="none" <?php selected( $shcm_values['compression'], 'none' ); ?>><?php esc_html_e( 'Store uncompressed (fastest)', 'sh-clone-migration' ); ?></option>
				</select>
				<label class="shcm-inline"><?php esc_html_e( 'Level', 'sh-clone-migration' ); ?>
					<input type="number" name="compression_level" min="1" max="9" value="<?php echo esc_attr( $shcm_values['compression_level'] ); ?>" class="small-text"></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-block-size"><?php esc_html_e( 'Block size', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-block-size" name="block_size" min="65536" max="33554432" step="65536" value="<?php echo esc_attr( $shcm_values['block_size'] ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'Bytes held in memory at a time while reading or writing an archive.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-time-budget"><?php esc_html_e( 'Time budget per request', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-time-budget" name="time_budget" min="0" max="3600" value="<?php echo esc_attr( $shcm_values['time_budget'] ); ?>" class="small-text">
				<span class="description"><?php esc_html_e( 'Seconds. 0 detects a safe value from max_execution_time.', 'sh-clone-migration' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-memory-guard"><?php esc_html_e( 'Memory guard', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-memory-guard" name="memory_guard" min="40" max="95" value="<?php echo esc_attr( $shcm_values['memory_guard'] ); ?>" class="small-text">
				<span class="description"><?php esc_html_e( 'Percent of the PHP memory limit at which a request hands over to the next one.', 'sh-clone-migration' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-rows"><?php esc_html_e( 'Database rows per query', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-rows" name="db_rows_per_query" min="50" max="50000" value="<?php echo esc_attr( $shcm_values['db_rows_per_query'] ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'Reduced automatically for tables with very large rows.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-chunk"><?php esc_html_e( 'Upload chunk size', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-chunk" name="upload_chunk_size" min="262144" step="262144" value="<?php echo esc_attr( $shcm_values['upload_chunk_size'] ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'Clamped to whatever upload_max_filesize and post_max_size allow.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Background worker', 'sh-clone-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="enable_cron_worker" <?php checked( $shcm_values['enable_cron_worker'] ); ?>>
					<?php esc_html_e( 'Let WP-Cron continue a migration when the browser tab is closed', 'sh-clone-migration' ); ?></label>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Restore behaviour', 'sh-clone-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Safety', 'sh-clone-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="create_rollback_point" <?php checked( $shcm_values['create_rollback_point'] ); ?>>
					<?php esc_html_e( 'Snapshot the current database before replacing it', 'sh-clone-migration' ); ?></label><br>
				<label><input type="checkbox" name="maintenance_mode" <?php checked( $shcm_values['maintenance_mode'] ); ?>>
					<?php esc_html_e( 'Put the site in maintenance mode during a restore', 'sh-clone-migration' ); ?></label><br>
				<label><input type="checkbox" name="replace_urls" <?php checked( $shcm_values['replace_urls'] ); ?>>
					<?php esc_html_e( 'Rewrite the source URL to the destination URL', 'sh-clone-migration' ); ?></label><br>
				<label><input type="checkbox" name="replace_paths" <?php checked( $shcm_values['replace_paths'] ); ?>>
					<?php esc_html_e( 'Rewrite absolute server paths as well', 'sh-clone-migration' ); ?></label><br>
				<label><input type="checkbox" name="restore_active_plugins" <?php checked( $shcm_values['restore_active_plugins'] ); ?>>
					<?php esc_html_e( 'Reactivate the plugins that were active on the source', 'sh-clone-migration' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-verify-mode"><?php esc_html_e( 'Archive verification', 'sh-clone-migration' ); ?></label></th>
			<td>
				<select id="shcm-verify-mode" name="verify_mode">
					<option value="full" <?php selected( $shcm_values['verify_mode'], 'full' ); ?>><?php esc_html_e( 'Full: recompute every checksum', 'sh-clone-migration' ); ?></option>
					<option value="quick" <?php selected( $shcm_values['verify_mode'], 'quick' ); ?>><?php esc_html_e( 'Quick: structure and manifest only', 'sh-clone-migration' ); ?></option>
				</select>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Housekeeping', 'sh-clone-migration' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="shcm-retention"><?php esc_html_e( 'Keep jobs and logs for', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-retention" name="retention_days" min="0" max="365" value="<?php echo esc_attr( $shcm_values['retention_days'] ); ?>" class="small-text">
				<span class="description"><?php esc_html_e( 'days. 0 keeps them forever. Archives are never deleted by this setting.', 'sh-clone-migration' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-max-archives"><?php esc_html_e( 'Maximum stored archives', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-max-archives" name="max_archives" min="0" max="500" value="<?php echo esc_attr( $shcm_values['max_archives'] ); ?>" class="small-text">
				<span class="description"><?php esc_html_e( '0 keeps every archive.', 'sh-clone-migration' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-temp-hours"><?php esc_html_e( 'Delete temporary files after', 'sh-clone-migration' ); ?></label></th>
			<td>
				<input type="number" id="shcm-temp-hours" name="cleanup_temp_hours" min="1" max="720" value="<?php echo esc_attr( $shcm_values['cleanup_temp_hours'] ); ?>" class="small-text">
				<span class="description"><?php esc_html_e( 'hours', 'sh-clone-migration' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="shcm-log-level"><?php esc_html_e( 'Log level', 'sh-clone-migration' ); ?></label></th>
			<td>
				<select id="shcm-log-level" name="log_level">
					<?php foreach ( array( 'debug', 'info', 'warning', 'error' ) as $shcm_level ) : ?>
						<option value="<?php echo esc_attr( $shcm_level ); ?>" <?php selected( $shcm_values['log_level'], $shcm_level ); ?>><?php echo esc_html( $shcm_level ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Uninstall', 'sh-clone-migration' ); ?></th>
			<td>
				<label><input type="checkbox" name="delete_data_on_uninstall" <?php checked( $shcm_values['delete_data_on_uninstall'] ); ?>>
					<?php esc_html_e( 'Delete this plugin\'s settings, jobs, logs and archives when it is uninstalled', 'sh-clone-migration' ); ?></label>
				<p class="description"><?php esc_html_e( 'Website content is never touched by the uninstaller, whatever this is set to.', 'sh-clone-migration' ); ?></p>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'sh-clone-migration' ); ?></button>
		<span class="shcm-save-feedback" id="shcm-settings-feedback"></span>
	</p>
</form>
