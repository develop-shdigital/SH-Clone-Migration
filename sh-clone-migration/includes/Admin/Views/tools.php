<?php
/**
 * Search and replace screen.
 *
 * @package SHCM
 * @var \SHCM\Core\Plugin $plugin
 */

defined( 'ABSPATH' ) || exit;

use SHCM\Admin\Menu;

Menu::header(
	__( 'Search &amp; Replace', 'sh-clone-migration' ),
	__( 'Serialization aware replacement across every table in this database.', 'sh-clone-migration' )
);
?>

<div class="shcm-panel">
	<div class="shcm-grid">
		<p>
			<label for="shcm-search"><?php esc_html_e( 'Old URL or text', 'sh-clone-migration' ); ?></label>
			<input type="text" id="shcm-search" class="large-text code" placeholder="https://oldsite.com">
		</p>
		<p>
			<label for="shcm-replace"><?php esc_html_e( 'New URL or text', 'sh-clone-migration' ); ?></label>
			<input type="text" id="shcm-replace" class="large-text code" placeholder="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>">
		</p>
	</div>

	<p class="description">
		<?php esc_html_e( 'When both values are URLs, the escaped, percent encoded and protocol relative variants are replaced as well. Serialized values are parsed and their string lengths recalculated; anything that cannot be parsed is reported instead of being rewritten.', 'sh-clone-migration' ); ?>
	</p>

	<p>
		<button type="button" class="button" id="shcm-preview-replace"><?php esc_html_e( 'Preview Changes', 'sh-clone-migration' ); ?></button>
		<button type="button" class="button button-primary" id="shcm-run-replace"><?php esc_html_e( 'Run Replacement', 'sh-clone-migration' ); ?></button>
	</p>
</div>

<div class="shcm-panel shcm-hidden" id="shcm-progress-panel">
	<div class="shcm-panel__head">
		<h2 id="shcm-progress-title"><?php esc_html_e( 'Scanning...', 'sh-clone-migration' ); ?></h2>
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
</div>

<div class="shcm-panel shcm-hidden" id="shcm-result-panel">
	<h2 data-role="result-title"></h2>
	<div data-role="result-body"></div>
</div>
