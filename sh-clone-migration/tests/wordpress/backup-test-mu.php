<?php
/**
 * Test-only helpers for scripts/backup-e2e.sh (installed as a mu-plugin on
 * the test site, never shipped).
 *
 * @package SHCM
 */

// Short retry delays, so fault-injection runs finish in seconds.
add_filter(
	'shcm_gdrive_backoff',
	function ( $delays ) {
		$value = (string) get_option( 'shcm_test_backoff', '' );
		return '' === $value ? $delays : array_map( 'intval', explode( ',', $value ) );
	}
);

// Smaller upload chunks, so a small archive still needs several of them.
add_filter(
	'shcm_gdrive_chunk_size',
	function ( $size ) {
		$value = (int) get_option( 'shcm_test_chunk', 0 );
		return $value > 0 ? $value : $size;
	}
);

// Simulate a host that blocks loopback requests.
add_filter(
	'shcm_background_loopback',
	function ( $enabled ) {
		return get_option( 'shcm_test_no_loopback' ) ? false : $enabled;
	}
);

// Capture e-mails instead of sending them.
add_filter(
	'pre_wp_mail',
	function ( $result, $atts ) {
		file_put_contents(
			WP_CONTENT_DIR . '/shcm-test-mail.log',
			wp_json_encode(
				array(
					'to'      => $atts['to'],
					'subject' => $atts['subject'],
					'message' => $atts['message'],
					'time'    => time(),
				)
			) . "\n",
			FILE_APPEND
		);
		return true;
	},
	10,
	2
);
