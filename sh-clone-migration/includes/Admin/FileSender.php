<?php
/**
 * Large file delivery.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Support\HttpRange;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a file to the browser so that browsers and download managers can
 * see its size, split it into parallel ranges and resume it.
 *
 * The details matter more than they look. A web server or proxy that
 * compresses the response replaces Content-Length with chunked encoding, and
 * the client can then neither show progress nor tell whether the download is
 * complete ("the file size is unknown"). So compression is switched off where
 * PHP can reach it, the body is never buffered, and every range branch is
 * exact.
 */
class FileSender {

	/**
	 * Send a file and end the request.
	 *
	 * @param string $path          Absolute path.
	 * @param string $content_type  MIME type.
	 * @param string $download_name File name offered to the browser.
	 * @param array  $extra_headers Additional headers, name => value.
	 * @return void
	 */
	public function send( $path, $content_type, $download_name, array $extra_headers = array() ) {
		// Open before sending a single header, so a failure is an error page
		// and never a zero byte "archive".
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			wp_die( esc_html__( 'The file could not be opened for reading.', 'sh-clone-migration' ), 500 );
		}

		$stat = fstat( $handle );
		$size = isset( $stat['size'] ) ? (int) $stat['size'] : -1;
		if ( $size < 0 ) {
			fclose( $handle );
			wp_die( esc_html__( 'This file is larger than this PHP build can address. Files over 2 GB need 64-bit PHP.', 'sh-clone-migration' ), 500 );
		}
		$mtime         = isset( $stat['mtime'] ) ? (int) $stat['mtime'] : time();
		$etag          = '"' . substr( sha1( basename( $path ) . '|' . $size . '|' . $mtime ), 0, 32 ) . '"';
		$last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

		$this->prepareEnvironment();

		if ( headers_sent( $file, $line ) ) {
			// Something printed output before this hook ran (a stray byte order
			// mark or whitespace in another plugin). The headers can no longer
			// be sent and the body would carry that output in front of the
			// archive, so refuse instead of delivering a corrupt file.
			fclose( $handle );
			$this->logFailure( sprintf( 'Download aborted: output had already started in %1$s on line %2$d.', $file, $line ) );
			echo "\n" . esc_html__( 'The download could not start because another plugin or theme printed output first. Check the migration log.', 'sh-clone-migration' );
			exit;
		}

		$range = HttpRange::resolve(
			$size,
			$this->server( 'HTTP_RANGE' ),
			$this->server( 'HTTP_IF_RANGE' ),
			$etag,
			$last_modified
		);

		if ( 416 === $range['status'] ) {
			fclose( $handle );
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			header( 'Accept-Ranges: bytes' );
			header( 'Content-Length: 0' );
			exit;
		}

		status_header( $range['status'] );
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: ' . $this->disposition( $download_name ) );
		header( 'Content-Length: ' . $range['length'] );
		header( 'Accept-Ranges: bytes' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . $last_modified );
		header( 'X-Content-Type-Options: nosniff' );
		// no-transform asks CDNs and proxies not to recompress the body;
		// X-Accel-Buffering stops nginx from spooling a large file to disk.
		header( 'Cache-Control: private, no-store, no-transform' );
		header( 'X-Accel-Buffering: no' );
		if ( 206 === $range['status'] ) {
			header( sprintf( 'Content-Range: bytes %1$d-%2$d/%3$d', $range['start'], $range['end'], $size ) );
		}
		foreach ( $extra_headers as $name => $value ) {
			header( $name . ': ' . preg_replace( '/[\r\n]+/', ' ', (string) $value ) );
		}

		if ( 'HEAD' === strtoupper( $this->server( 'REQUEST_METHOD' ) ) ) {
			fclose( $handle );
			exit;
		}

		if ( $range['start'] > 0 && 0 !== fseek( $handle, $range['start'] ) ) {
			fclose( $handle );
			exit; // Headers are out; all we can do is end short, which the client detects.
		}

		$remaining = $range['length'];
		while ( $remaining > 0 ) {
			$chunk = fread( $handle, (int) min( 1048576, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
			$remaining -= strlen( $chunk );

			// A download manager drops connections it no longer needs; stop
			// reading a multi-gigabyte file for nobody.
			if ( connection_aborted() ) {
				break;
			}
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Whether a request asks for the start of the file (as opposed to a
	 * later segment of a split or resumed download).
	 *
	 * @return bool
	 */
	public function isInitialRequest() {
		if ( 'HEAD' === strtoupper( $this->server( 'REQUEST_METHOD' ) ) ) {
			return false;
		}
		$range = $this->server( 'HTTP_RANGE' );
		return '' === $range || (bool) preg_match( '/^bytes\s*=\s*0\s*-/i', $range );
	}

	/**
	 * Switch off everything between PHP and the socket that would buffer or
	 * recompress the body.
	 *
	 * @return void
	 */
	protected function prepareEnvironment() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Apache (mod_php) and LiteSpeed: tell mod_deflate to leave this
		// response alone. This is what keeps Content-Length intact on hosts
		// that compress every response, octet-stream included.
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@apache_setenv( 'dont-vary', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet

		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		// Bounded: a buffer that refuses to close must not hang the request.
		$guard = 32;
		while ( ob_get_level() > 0 && $guard-- > 0 ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			}
		}
	}

	/**
	 * Content-Disposition with an ASCII fallback and an RFC 5987 UTF-8 name.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	protected function disposition( $name ) {
		$name  = str_replace( array( "\r", "\n", '"', '\\' ), '', basename( (string) $name ) );
		$ascii = preg_replace( '/[^A-Za-z0-9._\-]/', '_', $name );
		if ( '' === $ascii ) {
			$ascii = 'download';
		}
		return sprintf( 'attachment; filename="%1$s"; filename*=UTF-8\'\'%2$s', $ascii, rawurlencode( $name ) );
	}

	/**
	 * Read a server variable.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	protected function server( $key ) {
		return isset( $_SERVER[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Record a delivery failure in the plugin log.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected function logFailure( $message ) {
		if ( function_exists( 'shcm_bootstrap' ) ) {
			shcm_bootstrap()->logger()->channel( 'plugin' )->error( $message );
		}
	}
}
