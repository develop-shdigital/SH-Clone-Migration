<?php
/**
 * System status screen.
 *
 * @package SHCM
 * @var \SHCM\Core\Plugin      $plugin
 * @var \SHCM\Core\Environment $environment
 */

defined( 'ABSPATH' ) || exit;

use SHCM\Admin\Menu;

$shcm_report = $environment->report();
$shcm_caps   = $shcm_report['capabilities'];

Menu::header(
	__( 'System status', 'sh-clone-migration' ),
	__( 'What this server can do, and how the migration engine adapts to it.', 'sh-clone-migration' )
);
?>

<?php foreach ( $shcm_report['warnings'] as $shcm_warning ) : ?>
	<div class="shcm-alert shcm-alert--<?php echo esc_attr( $shcm_warning['level'] ); ?>">
		<?php echo esc_html( $shcm_warning['message'] ); ?>
		<?php if ( ! empty( $shcm_warning['code'] ) ) : ?>
			<pre class="shcm-code"><?php echo esc_html( $shcm_warning['code'] ); ?></pre>
		<?php endif; ?>
	</div>
<?php endforeach; ?>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Environment', 'sh-clone-migration' ); ?></h2>
	<div class="shcm-table-scroll">
	<table class="widefat striped shcm-table">
		<tbody>
		<?php foreach ( $shcm_report['rows'] as $shcm_key => $shcm_row ) : ?>
			<tr>
				<th scope="row" style="width:280px"><?php echo esc_html( $shcm_row['label'] ); ?></th>
				<td><code><?php echo esc_html( '' === $shcm_row['value'] ? '—' : $shcm_row['value'] ); ?></code></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</div>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Selected migration engine', 'sh-clone-migration' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: engine description */
			esc_html__( 'Archive engine: %s', 'sh-clone-migration' ),
			'<strong>' . esc_html(
				'gzip' === $environment->compressionEngine( $plugin->settings()->get( 'compression', 'auto' ) )
					? __( 'streaming block writer with deflate compression', 'sh-clone-migration' )
					: __( 'streaming block writer, uncompressed (zlib unavailable)', 'sh-clone-migration' )
			) . '</strong>'
		);
		?>
	</p>
	<ul class="shcm-capabilities">
		<?php
		$shcm_checks = array(
			'zlib'            => __( 'zlib compression', 'sh-clone-migration' ),
			'zlib_filters'    => __( 'zlib stream filters', 'sh-clone-migration' ),
			'zip_archive'     => __( 'ZipArchive (not required)', 'sh-clone-migration' ),
			'phar'            => __( 'Phar (not required)', 'sh-clone-migration' ),
			'sodium'          => __( 'libsodium (XChaCha20-Poly1305 encryption)', 'sh-clone-migration' ),
			'openssl'         => __( 'OpenSSL (AES-256-GCM fallback)', 'sh-clone-migration' ),
			'shell'           => __( 'Shell commands (not required)', 'sh-clone-migration' ),
			'mysqldump'       => __( 'mysqldump binary (not required)', 'sh-clone-migration' ),
			'iterators'       => __( 'SPL filesystem iterators', 'sh-clone-migration' ),
			'set_time_limit'  => __( 'set_time_limit()', 'sh-clone-migration' ),
			'writable_storage' => __( 'Writable storage directory', 'sh-clone-migration' ),
			'writable_content' => __( 'Writable wp-content', 'sh-clone-migration' ),
		);
		foreach ( $shcm_checks as $shcm_key => $shcm_label ) :
			$shcm_ok = ! empty( $shcm_caps[ $shcm_key ] );
			?>
			<li class="<?php echo $shcm_ok ? 'is-yes' : 'is-no'; ?>">
				<span class="dashicons <?php echo $shcm_ok ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>"></span>
				<?php echo esc_html( $shcm_label ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="description">
		<?php esc_html_e( 'Nothing in this list is mandatory. The engine falls back to pure PHP streaming, which works on standard shared hosting without shell access.', 'sh-clone-migration' ); ?>
	</p>
</div>

<div class="shcm-panel">
	<h2><?php esc_html_e( 'Storage', 'sh-clone-migration' ); ?></h2>
	<div class="shcm-table-scroll">
	<table class="widefat striped shcm-table">
		<tbody>
		<tr>
			<th scope="row" style="width:280px"><?php esc_html_e( 'Directory', 'sh-clone-migration' ); ?></th>
			<td><code><?php echo esc_html( $plugin->storage()->base() ); ?></code></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Direct web access', 'sh-clone-migration' ); ?></th>
			<td>
				<?php
				$shcm_exposure = $environment->storageExposure();
				if ( ! $shcm_exposure['checked'] ) {
					esc_html_e( 'Denied by .htaccess, web.config and an index.php in every directory. The live check could not run on this server.', 'sh-clone-migration' );
				} elseif ( $shcm_exposure['exposed'] ) {
					echo '<strong style="color:#d63638">' . esc_html__( 'Reachable over HTTP.', 'sh-clone-migration' ) . '</strong> ';
					esc_html_e( 'This web server ignores .htaccess. Archive names are random, but block the directory in your server configuration as well.', 'sh-clone-migration' );
					echo '<br><code>location ~* /wp-content/shcm-storage/ { deny all; return 404; }</code>';
				} else {
					echo '<strong style="color:#00a32a">' . esc_html__( 'Blocked.', 'sh-clone-migration' ) . '</strong> ';
					esc_html_e( 'A live request for a file in the storage directory was refused. Downloads are served through an authenticated endpoint.', 'sh-clone-migration' );
				}
				?>
			</td>
		</tr>
		</tbody>
	</table>
	</div>
</div>
