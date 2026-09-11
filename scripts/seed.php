<?php
/**
 * Seed the source site with representative content.
 */

WP_CLI::line( 'Seeding content...' );

// --- Users -----------------------------------------------------------------
foreach ( array( 'editor' => 'editor', 'author' => 'author', 'shopper' => 'customer' ) as $login => $role ) {
	if ( ! get_user_by( 'login', $login ) ) {
		wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => 'password123',
				'user_email' => $login . '@source.test',
				'role'       => $role,
				'first_name' => ucfirst( $login ),
			)
		);
	}
}

// --- Taxonomies and custom post types (registered by a mu-plugin) ----------
$mu = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu ) ) {
	mkdir( $mu, 0755, true );
}
file_put_contents(
	$mu . '/shcm-test-types.php',
	'<?php
/**
 * Plugin Name: SHCM Test Types
 */
add_action( "init", function () {
	register_post_type( "portfolio", array(
		"label" => "Portfolio",
		"public" => true,
		"has_archive" => true,
		"supports" => array( "title", "editor", "custom-fields", "thumbnail" ),
		"show_in_rest" => true,
	) );
	register_taxonomy( "project_type", "portfolio", array(
		"label" => "Project Type",
		"public" => true,
		"hierarchical" => true,
		"show_in_rest" => true,
	) );
} );
'
);

// The mu-plugin is not loaded in this request; register inline so the seed works.
register_post_type( 'portfolio', array( 'label' => 'Portfolio', 'public' => true, 'has_archive' => true, 'supports' => array( 'title', 'editor', 'custom-fields', 'thumbnail' ) ) );
register_taxonomy( 'project_type', 'portfolio', array( 'label' => 'Project Type', 'public' => true, 'hierarchical' => true ) );

// --- Categories and tags ---------------------------------------------------
$cat_a = wp_insert_term( 'Architecture', 'category' );
$cat_b = wp_insert_term( 'Interiors', 'category' );
$tag_a = wp_insert_term( 'concrete', 'post_tag' );
$type  = wp_insert_term( 'Residential', 'project_type' );

// --- Media -----------------------------------------------------------------
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$upload_dir = wp_upload_dir();
$attachments = array();

// A real PNG (1x1 red pixel) and a small PDF, plus a unicode filename.
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

$files = array(
	'red-pixel.png'      => $png,
	'sample-document.pdf' => $pdf,
	'ünïcode-fïle.png'   => $png,
	'spaces and (parens).png' => $png,
);

foreach ( $files as $name => $contents ) {
	$path = trailingslashit( $upload_dir['path'] ) . $name;
	file_put_contents( $path, $contents );
	$type_info = wp_check_filetype( $name );
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $type_info['type'] ? $type_info['type'] : 'application/octet-stream',
			'post_title'     => $name,
			'post_content'   => 'Media hosted at ' . home_url( '/wp-content/uploads/' ),
			'post_status'    => 'inherit',
		),
		$path
	);
	if ( ! is_wp_error( $id ) ) {
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		$attachments[ $name ] = $id;
	}
}

// A larger binary file to exercise multi block entries (3 MB).
file_put_contents( trailingslashit( $upload_dir['path'] ) . 'large-binary.bin', random_bytes( 3 * 1024 * 1024 ) );

// Nested + empty directories.
mkdir( $upload_dir['basedir'] . '/deep/nested/tree', 0755, true );
file_put_contents( $upload_dir['basedir'] . '/deep/nested/tree/leaf.txt', "leaf content with url http://source.test:8081/leaf\n" );
mkdir( $upload_dir['basedir'] . '/empty-dir', 0755, true );

// --- Posts and pages -------------------------------------------------------
$home_url = home_url();

$page_home = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Home',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:paragraph --><p>Welcome to <a href="' . $home_url . '/about/">our site</a>.</p><!-- /wp:paragraph -->'
			. '<!-- wp:image {"id":' . reset( $attachments ) . ',"url":"' . $upload_dir['url'] . '/red-pixel.png"} --><figure class="wp-block-image"><img src="' . $upload_dir['url'] . '/red-pixel.png" alt=""/></figure><!-- /wp:image -->',
	)
);
$page_about = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'About',
		'post_name'    => 'about',
		'post_status'  => 'publish',
		'post_content' => 'Absolute link to ' . $home_url . '/contact/ and an external one to https://example.org/reference.',
	)
);
$page_contact = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_title'  => 'Contact',
		'post_name'   => 'contact',
		'post_status' => 'publish',
		'post_content' => 'Email us. Server path reference: ' . ABSPATH . 'wp-content/uploads/',
	)
);

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $page_home );

for ( $i = 1; $i <= 25; $i++ ) {
	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Blog post ' . $i . ' — ünïcode ✓',
			'post_status'  => 'publish',
			'post_content' => "Post {$i} links to {$home_url}/blog-post-{$i}/ and embeds {$upload_dir['url']}/red-pixel.png",
			'post_excerpt' => 'Excerpt ' . $i,
			'post_category' => array( $cat_a['term_id'] ),
		)
	);
	wp_set_post_tags( $post_id, array( 'concrete', 'tag-' . $i ) );
	update_post_meta( $post_id, 'custom_number', $i );
	update_post_meta( $post_id, 'custom_url', $home_url . '/ref/' . $i );
	update_post_meta(
		$post_id,
		'custom_serialized',
		array(
			'link'   => $home_url . '/deep/link',
			'nested' => array( 'image' => $upload_dir['url'] . '/red-pixel.png', 'count' => $i ),
			'emoji'  => 'ünïcode ✓ 🎉',
		)
	);
	if ( 1 === $i ) {
		wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Visitor',
				'comment_author_email' => 'visitor@example.com',
				'comment_content' => 'Nice post, see ' . $home_url . '/about/',
				'comment_approved' => 1,
			)
		);
	}
}

for ( $i = 1; $i <= 5; $i++ ) {
	$portfolio_id = wp_insert_post(
		array(
			'post_type'   => 'portfolio',
			'post_title'  => 'Project ' . $i,
			'post_status' => 'publish',
			'post_content' => 'Project content referencing ' . $home_url . '/portfolio/project-' . $i,
		)
	);
	wp_set_object_terms( $portfolio_id, 'Residential', 'project_type' );
	update_post_meta( $portfolio_id, 'project_meta', array( 'gallery' => array( $upload_dir['url'] . '/red-pixel.png' ) ) );
}

// --- Elementor page --------------------------------------------------------
$elementor_data = array(
	array(
		'id'       => 'abc123',
		'elType'   => 'section',
		'settings' => array(
			'background_image' => array(
				'url' => $upload_dir['url'] . '/red-pixel.png',
				'id'  => reset( $attachments ),
			),
		),
		'elements' => array(
			array(
				'id'       => 'def456',
				'elType'   => 'widget',
				'widgetType' => 'text-editor',
				'settings' => array(
					'editor' => '<p>Elementor content linking to <a href="' . $home_url . '/about/">About</a></p>',
					'link'   => array( 'url' => $home_url . '/contact/', 'is_external' => '' ),
				),
			),
		),
	),
);

$elementor_page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_title'  => 'Elementor Landing',
		'post_status' => 'publish',
		'post_content' => 'Built with Elementor',
	)
);
update_post_meta( $elementor_page, '_elementor_edit_mode', 'builder' );
update_post_meta( $elementor_page, '_elementor_template_type', 'wp-page' );
update_post_meta( $elementor_page, '_elementor_version', '3.0.0' );
update_post_meta( $elementor_page, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
update_option( 'elementor_cpt_support', array( 'page', 'post', 'portfolio' ) );
update_option( 'elementor_global_image_lightbox', 'yes' );

// Fake generated Elementor CSS referencing the old URL.
$css_dir = $upload_dir['basedir'] . '/elementor/css';
mkdir( $css_dir, 0755, true );
file_put_contents( $css_dir . '/post-' . $elementor_page . '.css', '.elementor{background:url(' . $upload_dir['url'] . '/red-pixel.png)}' );
update_post_meta( $elementor_page, '_elementor_css', array( 'time' => time(), 'fonts' => array(), 'status' => 'file' ) );

// --- ACF -------------------------------------------------------------------
$field_group = wp_insert_post(
	array(
		'post_type'    => 'acf-field-group',
		'post_title'   => 'Project details',
		'post_name'    => 'group_shcmtest',
		'post_status'  => 'publish',
		'post_excerpt' => 'group_shcmtest',
		'post_content' => serialize(
			array(
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'portfolio' ) ) ),
				'position' => 'normal',
			)
		),
	)
);
wp_insert_post(
	array(
		'post_type'    => 'acf-field',
		'post_parent'  => $field_group,
		'post_title'   => 'Hero image',
		'post_name'    => 'field_hero_image',
		'post_excerpt' => 'hero_image',
		'post_status'  => 'publish',
		'post_content' => serialize( array( 'type' => 'image', 'return_format' => 'url', 'default_value' => $upload_dir['url'] . '/red-pixel.png' ) ),
	)
);
update_option(
	'options_acf_site_settings',
	array(
		'logo' => $upload_dir['url'] . '/red-pixel.png',
		'repeater' => array(
			array( 'title' => 'Row one', 'link' => $home_url . '/row-one' ),
			array( 'title' => 'Row two', 'link' => $home_url . '/row-two' ),
		),
	)
);

// --- WooCommerce -----------------------------------------------------------
if ( class_exists( 'WC_Product_Simple' ) ) {
	$cat = wp_insert_term( 'Furniture', 'product_cat' );
	for ( $i = 1; $i <= 5; $i++ ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Product ' . $i );
		$product->set_regular_price( 10 * $i );
		$product->set_description( 'Product referencing ' . $home_url . '/product/product-' . $i );
		$product->set_sku( 'SKU-' . $i );
		$product->set_catalog_visibility( 'visible' );
		$product->set_status( 'publish' );
		$product->save();
		if ( ! is_wp_error( $cat ) ) {
			wp_set_object_terms( $product->get_id(), (int) $cat['term_id'], 'product_cat' );
		}
	}
	$order = wc_create_order();
	$order->set_billing_email( 'shopper@source.test' );
	$order->set_billing_first_name( 'Test' );
	$order->calculate_totals();
	$order->save();
	update_option( 'woocommerce_store_address', '1 Test Street' );
	update_option( 'woocommerce_email_header_image', $upload_dir['url'] . '/red-pixel.png' );
}

// --- Menus -----------------------------------------------------------------
$menu_id = wp_create_nav_menu( 'Primary Menu' );
foreach ( array( $page_home => 'Home', $page_about => 'About', $page_contact => 'Contact' ) as $page_id => $label ) {
	wp_update_nav_menu_item(
		$menu_id,
		0,
		array(
			'menu-item-title'     => $label,
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page_id,
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		)
	);
}
wp_update_nav_menu_item(
	$menu_id,
	0,
	array(
		'menu-item-title'  => 'External',
		'menu-item-url'    => 'https://example.org/external',
		'menu-item-type'   => 'custom',
		'menu-item-status' => 'publish',
	)
);
set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu_id, 'menu-1' => $menu_id ) );
set_theme_mod( 'custom_logo', reset( $attachments ) );
set_theme_mod( 'shcm_custom_url', $home_url . '/theme-mod-url' );

// --- Widgets ---------------------------------------------------------------
update_option(
	'widget_text',
	array(
		2 => array(
			'title' => 'Text widget',
			'text'  => 'Widget linking to ' . $home_url . '/widget-target/ and an image ' . $upload_dir['url'] . '/red-pixel.png',
			'filter' => true,
		),
		'_multiwidget' => 1,
	)
);
update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array(), 'sidebar-1' => array( 'text-2' ), 'array_version' => 3 ) );

// --- Custom options with tricky payloads -----------------------------------
update_option( 'shcm_test_plain', $home_url . '/plain-option' );
update_option(
	'shcm_test_serialized',
	array(
		'url'     => $home_url,
		'nested'  => array( 'deep' => array( 'deeper' => $home_url . '/deep/deeper' ) ),
		'json'    => wp_json_encode( array( 'url' => $home_url . '/inside-json' ) ),
		'unicode' => 'ünïcode ✓ 🎉 ' . $home_url,
		'path'    => ABSPATH,
	)
);
update_option( 'shcm_test_double_serialized', serialize( array( 'inner' => $home_url . '/double' ) ) );
update_option( 'shcm_test_object', (object) array( 'endpoint' => $home_url . '/api', 'version' => 2 ) );
update_option( 'shcm_test_external', 'https://example.org/keep-me' );

// A custom application table with its own data.
global $wpdb;
$table = $wpdb->prefix . 'shcm_custom_app';
$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	label VARCHAR(191) NOT NULL,
	payload LONGTEXT NULL,
	blob_data BLOB NULL,
	created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
for ( $i = 1; $i <= 50; $i++ ) {
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO `{$table}` (label, payload, blob_data) VALUES (%s, %s, %s)",
			'row-' . $i,
			serialize( array( 'url' => $home_url . '/app/' . $i, 'binary_safe' => "line1\nline2\t\"quoted\" 'single' \\backslash" ) ),
			random_bytes( 64 )
		)
	);
}

// A table without a primary key.
$nopk = $wpdb->prefix . 'shcm_nopk';
$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$nopk}` (a VARCHAR(100), b TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
$wpdb->query( $wpdb->prepare( "INSERT INTO `{$nopk}` (a, b) VALUES (%s, %s)", 'one', $home_url . '/nopk' ) );

update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blogdescription', 'Cloned from ' . $home_url );

WP_CLI::success( 'Seed complete.' );
