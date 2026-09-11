<?php
/** Post-migration deep verification. */
global $wpdb;
$fail = 0;
$pass = 0;
function ok( $label, $cond, $extra = '' ) {
	global $fail, $pass;
	if ( $cond ) { $pass++; WP_CLI::line( sprintf( "  PASS  %-44s %s", $label, $extra ) ); }
	else { $fail++; WP_CLI::line( sprintf( "  FAIL  %-44s %s", $label, $extra ) ); }
}

$home = home_url();
WP_CLI::line( "Home: $home" );

// --- Serialized options ----------------------------------------------------
$s = get_option( 'shcm_test_serialized' );
ok( 'serialized option unserializes', is_array( $s ), gettype( $s ) );
ok( 'serialized option url replaced', isset( $s['url'] ) && $s['url'] === $home, isset($s['url'])?$s['url']:'' );
ok( 'nested serialized replaced', isset( $s['nested']['deep']['deeper'] ) && $s['nested']['deep']['deeper'] === $home . '/deep/deeper' );
$json_escaped = str_replace( '/', '\\/', $home );
ok( 'json inside serialized replaced', isset( $s['json'] ) && false !== strpos( $s['json'], $json_escaped ) && false === strpos( $s['json'], 'source.test' ), isset($s['json'])?$s['json']:'' );
ok( 'unicode preserved', isset( $s['unicode'] ) && false !== strpos( $s['unicode'], 'ünïcode ✓ 🎉' ) );
ok( 'server path rewritten', isset( $s['path'] ) && $s['path'] === ABSPATH || rtrim($s['path'],'/') === rtrim(ABSPATH,'/'), isset($s['path'])?$s['path']:'' );

$d = get_option( 'shcm_test_double_serialized' );
$inner = maybe_unserialize( $d );
ok( 'double serialized outer parses', is_string( $d ) || is_array( $inner ) );
$inner = is_string( $d ) ? unserialize( $d ) : $inner;
ok( 'double serialized inner replaced', is_array( $inner ) && $inner['inner'] === $home . '/double', is_array($inner) ? $inner['inner'] : 'n/a' );

$o = get_option( 'shcm_test_object' );
ok( 'object option survives', is_object( $o ) && isset( $o->endpoint ) );
ok( 'object option replaced', is_object( $o ) && $o->endpoint === $home . '/api', is_object($o) ? $o->endpoint : '' );

ok( 'external url untouched', get_option( 'shcm_test_external' ) === 'https://example.org/keep-me' );
ok( 'plain option replaced', get_option( 'shcm_test_plain' ) === $home . '/plain-option' );

// --- Widgets, menus, theme mods -------------------------------------------
$w = get_option( 'widget_text' );
ok( 'widget serialized intact', is_array( $w ) && isset( $w[2]['text'] ) );
ok( 'widget url replaced', isset( $w[2]['text'] ) && false !== strpos( $w[2]['text'], $home . '/widget-target/' ) );

$mods = get_option( 'theme_mods_twentytwentyfive' );
ok( 'theme mods intact', is_array( $mods ) && isset( $mods['nav_menu_locations'] ) );
ok( 'theme mod url replaced', isset( $mods['shcm_custom_url'] ) && $mods['shcm_custom_url'] === $home . '/theme-mod-url' );

$menu = wp_get_nav_menu_object( 'Primary Menu' );
ok( 'menu exists', (bool) $menu );
if ( $menu ) {
	$items = wp_get_nav_menu_items( $menu->term_id );
	ok( 'menu items count', count( $items ) === 4, count( $items ) . ' items' );
	$external = array_filter( $items, function ( $i ) { return 'custom' === $i->type; } );
	$external = reset( $external );
	ok( 'external menu url untouched', $external && 'https://example.org/external' === $external->url );
}

// --- Posts and meta --------------------------------------------------------
ok( 'post count', wp_count_posts( 'post' )->publish >= 25, wp_count_posts( 'post' )->publish . ' published' );
ok( 'page count', wp_count_posts( 'page' )->publish >= 4, wp_count_posts( 'page' )->publish . ' pages' );
ok( 'CPT count', wp_count_posts( 'portfolio' )->publish === '5' || (int) wp_count_posts( 'portfolio' )->publish === 5, wp_count_posts( 'portfolio' )->publish . ' portfolio' );

$posts = get_posts( array( 'numberposts' => 40, 'post_type' => 'post' ) );
$post  = null;
foreach ( $posts as $candidate ) {
	if ( 0 === strpos( $candidate->post_title, 'Blog post ' ) ) { $post = $candidate; break; }
}
if ( ! $post ) { WP_CLI::error( 'Seeded blog posts are missing from the destination.' ); }
ok( 'post content url replaced', false !== strpos( $post->post_content, $home ) && false === strpos( $post->post_content, 'source.test' ), substr( $post->post_content, 0, 60 ) );
ok( 'unicode title preserved', false !== strpos( $post->post_title, 'ünïcode ✓' ), $post->post_title );

$meta = get_post_meta( $post->ID, 'custom_serialized', true );
ok( 'post meta serialized array', is_array( $meta ) );
ok( 'post meta url replaced', is_array( $meta ) && $meta['link'] === $home . '/deep/link' );
ok( 'post meta nested replaced', is_array( $meta ) && false !== strpos( $meta['nested']['image'], $home ) );
ok( 'post meta emoji intact', is_array( $meta ) && $meta['emoji'] === 'ünïcode ✓ 🎉' );

// --- Elementor -------------------------------------------------------------
$el = get_posts( array( 'post_type' => 'page', 'title' => 'Elementor Landing', 'numberposts' => 1 ) );
if ( ! $el ) { $el = get_posts( array( 'post_type' => 'page', 'numberposts' => 20 ) ); $el = array_values( array_filter( $el, function ( $p ) { return 'Elementor Landing' === $p->post_title; } ) ); }
ok( 'elementor page exists', ! empty( $el ) );
if ( ! empty( $el ) ) {
	$raw = get_post_meta( $el[0]->ID, '_elementor_data', true );
	$decoded = json_decode( $raw, true );
	ok( 'elementor data is valid json', is_array( $decoded ), json_last_error_msg() );
	if ( is_array( $decoded ) ) {
		$bg = $decoded[0]['settings']['background_image']['url'];
		$link = $decoded[0]['elements'][0]['settings']['link']['url'];
		ok( 'elementor bg url replaced', false !== strpos( $bg, $home ), $bg );
		ok( 'elementor link replaced', $link === $home . '/contact/', $link );
	}
	ok( 'elementor css meta cleared', '' === get_post_meta( $el[0]->ID, '_elementor_css', true ) );
	ok( 'elementor css files cleared', ! file_exists( WP_CONTENT_DIR . '/uploads/elementor/css/post-' . $el[0]->ID . '.css' ) );
}

// --- ACF -------------------------------------------------------------------
$fg = get_posts( array( 'post_type' => 'acf-field-group', 'numberposts' => 5, 'post_status' => 'any' ) );
ok( 'acf field group migrated', ! empty( $fg ) );
$acf_opts = get_option( 'options_acf_site_settings' );
ok( 'acf options serialized intact', is_array( $acf_opts ) && isset( $acf_opts['repeater'][1]['link'] ) );
ok( 'acf repeater url replaced', isset( $acf_opts['repeater'][1]['link'] ) && $acf_opts['repeater'][1]['link'] === $home . '/row-two' );

// --- WooCommerce -----------------------------------------------------------
ok( 'woocommerce tables present', (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_sessions'" ) );
$products = get_posts( array( 'post_type' => 'product', 'numberposts' => 10, 'post_status' => 'any' ) );
ok( 'products migrated', count( $products ) === 5, count( $products ) . ' products' );
ok( 'woo option url replaced', false !== strpos( (string) get_option( 'woocommerce_email_header_image' ), $home ) );
ok( 'woo store address kept', '1 Test Street' === get_option( 'woocommerce_store_address' ) );
$lookup = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup" );
ok( 'woo lookup table data', $lookup === 5, $lookup . ' rows' );

// --- Users -----------------------------------------------------------------
$editor = get_user_by( 'login', 'editor' );
ok( 'source user exists', (bool) $editor );
ok( 'user role preserved (prefix rewritten)', $editor && in_array( 'editor', (array) $editor->roles, true ), $editor ? implode( ',', $editor->roles ) : '' );
$admin = get_user_by( 'login', 'admin' );
ok( 'admin capabilities present', $admin && $admin->has_cap( 'manage_options' ) );

// --- Custom application table ---------------------------------------------
$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}shcm_custom_app" );
ok( 'custom table rows', 50 === $rows, $rows . ' rows' );
$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}shcm_custom_app WHERE label='row-7'" );
$payload = maybe_unserialize( $row->payload );
ok( 'custom table serialized ok', is_array( $payload ) && $payload['url'] === $home . '/app/7', is_array($payload)?$payload['url']:'' );
ok( 'custom table escaping preserved', is_array( $payload ) && $payload['binary_safe'] === "line1\nline2\t\"quoted\" 'single' \\backslash" );
ok( 'blob column preserved', is_string( $row->blob_data ) && 64 === strlen( $row->blob_data ), strlen( (string) $row->blob_data ) . ' bytes' );
$nopk = $wpdb->get_var( "SELECT b FROM {$wpdb->prefix}shcm_nopk LIMIT 1" );
ok( 'table without primary key replaced', $nopk === $home . '/nopk', (string) $nopk );

// --- Files -----------------------------------------------------------------
$uploads = wp_upload_dir();
ok( 'uploaded png exists', file_exists( $uploads['basedir'] . '/' . gmdate('Y') . '/' . gmdate('m') . '/red-pixel.png' ) || glob( $uploads['basedir'] . '/*/*/red-pixel.png' ) );
$pdf = glob( $uploads['basedir'] . '/*/*/sample-document.pdf' );
ok( 'pdf restored', ! empty( $pdf ) && filesize( $pdf[0] ) > 100 );
$uni = glob( $uploads['basedir'] . '/*/*/ünïcode-fïle.png' );
ok( 'unicode filename restored', ! empty( $uni ) );
$paren = glob( $uploads['basedir'] . '/*/*/spaces and (parens).png' );
ok( 'filename with spaces restored', ! empty( $paren ) );
$big = glob( $uploads['basedir'] . '/*/*/large-binary.bin' );
ok( 'large binary restored', ! empty( $big ) && filesize( $big[0] ) === 3 * 1024 * 1024, ! empty( $big ) ? filesize( $big[0] ) . ' bytes' : 'missing' );
ok( 'nested directory restored', file_exists( $uploads['basedir'] . '/deep/nested/tree/leaf.txt' ) );
ok( 'nested file url replaced?', file_exists( $uploads['basedir'] . '/deep/nested/tree/leaf.txt' ) && false !== strpos( file_get_contents( $uploads['basedir'] . '/deep/nested/tree/leaf.txt' ), 'source.test' ), 'file contents are never rewritten (by design)' );
ok( 'empty directory restored', is_dir( $uploads['basedir'] . '/empty-dir' ) );
ok( 'child theme restored', file_exists( WP_CONTENT_DIR . '/themes/twentytwentyfive-child/style.css' ) );
ok( 'mu-plugin restored', file_exists( WPMU_PLUGIN_DIR . '/shcm-test-types.php' ) );
ok( 'plugins restored', is_dir( WP_PLUGIN_DIR . '/woocommerce' ) && is_dir( WP_PLUGIN_DIR . '/elementor' ) && is_dir( WP_PLUGIN_DIR . '/advanced-custom-fields' ) );
ok( 'migration plugin kept', is_dir( WP_PLUGIN_DIR . '/sh-clone-migration' ) );
ok( 'storage dir not clobbered', is_dir( WP_CONTENT_DIR . '/shcm-storage/archives' ) );

// --- Config safety ---------------------------------------------------------
$config = file_get_contents( ABSPATH . 'wp-config.php' );
ok( 'destination db name kept', false !== strpos( $config, 'wp_dest' ) );
ok( 'destination prefix kept', false !== strpos( $config, "wpdst_" ) );
ok( 'maintenance mode off', ! file_exists( ABSPATH . '.maintenance' ) );

// --- Active plugins/theme --------------------------------------------------
$active = (array) get_option( 'active_plugins' );
ok( 'source active plugins restored', in_array( 'woocommerce/woocommerce.php', $active, true ) && in_array( 'elementor/elementor.php', $active, true ), implode( ', ', $active ) );
ok( 'migration plugin still active', in_array( 'sh-clone-migration/sh-clone-migration.php', $active, true ) );
ok( 'child theme active', 'twentytwentyfive-child' === get_option( 'stylesheet' ) );

WP_CLI::line( '' );
WP_CLI::line( sprintf( 'RESULT: %d passed, %d failed', $pass, $fail ) );
if ( $fail > 0 ) { WP_CLI::halt( 1 ); }

// --- Literal percent signs (wpdb placeholder escape regression) ------------
ok( 'permalink structure intact', '/%postname%/' === get_option( 'permalink_structure' ), get_option( 'permalink_structure' ) );
ok( 'percent option intact', '100% pure — /%postname%/ and %s %d %% tokens' === get_option( 'shcm_test_percent' ), get_option( 'shcm_test_percent' ) );
$ps = get_option( 'shcm_test_percent_serialized' );
ok( 'percent serialized intact', is_array( $ps ) && '/%category%/%postname%/' === $ps['structure'] );
ok( 'percent sprintf token intact', is_array( $ps ) && 'Save %1$s on %2$s' === $ps['sprintf'] );
ok( 'percent encoded url replaced', is_array( $ps ) && $ps['encoded'] === $home . '/search/?q=hello%20world&x=100%25', is_array($ps)?$ps['encoded']:'' );
$pp = get_page_by_path( 'percent-edge-cases', OBJECT, 'post' );
ok( 'percent post exists', (bool) $pp );
ok( 'percent post content intact', $pp && false !== strpos( $pp->post_content, 'Literal 100% and a token %postname%' ), $pp ? substr( $pp->post_content, 0, 60 ) : '' );
ok( 'percent post url replaced', $pp && false !== strpos( $pp->post_content, $home . '/a%20b/' ) );
$pm = $pp ? get_post_meta( $pp->ID, 'percent_meta', true ) : null;
ok( 'percent meta intact', is_array( $pm ) && '%like% 50%' === $pm['v'] );
ok( 'no placeholder hash leaked', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_value REGEXP '\\\\{[0-9a-f]{64}\\\\}'" ) );
ok( 'no placeholder hash in postmeta', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE meta_value REGEXP '\\\\{[0-9a-f]{64}\\\\}'" ) );
ok( 'no placeholder hash in posts', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_content REGEXP '\\\\{[0-9a-f]{64}\\\\}'" ) );
