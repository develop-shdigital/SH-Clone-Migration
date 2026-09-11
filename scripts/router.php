<?php
// Minimal front controller so PHP's built-in server can serve pretty permalinks.
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = realpath( $root . $path );
if ( $file && strpos( $file, realpath( $root ) ) === 0 && is_file( $file ) ) {
	return false;
}
if ( preg_match( '#^/wp-admin/?$#', $path ) ) {
	$_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
	require $root . '/wp-admin/index.php';
	return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
