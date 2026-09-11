<?php
/** Additional edge-case content: literal percent signs and encoded URLs. */
$home = home_url();
update_option( 'shcm_test_percent', '100% pure — /%postname%/ and %s %d %% tokens' );
update_option(
	'shcm_test_percent_serialized',
	array(
		'structure' => '/%category%/%postname%/',
		'encoded'   => $home . '/search/?q=hello%20world&x=100%25',
		'sprintf'   => 'Save %1$s on %2$s',
	)
);
$id = wp_insert_post(
	array(
		'post_title'   => 'Percent edge cases',
		'post_name'    => 'percent-edge-cases',
		'post_status'  => 'publish',
		'post_content' => "Literal 100% and a token %postname% plus an encoded link {$home}/a%20b/ and a raw %s.",
	)
);
update_post_meta( $id, 'percent_meta', array( 'v' => '%like% 50%', 'url' => $home . '/x%20y' ) );
WP_CLI::success( 'Percent edge cases added (post ' . $id . ').' );
