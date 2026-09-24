<?php
/**
 * Web server rules for archive downloads.
 *
 * @package SHCM
 */

namespace SHCM\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lets Apache pass archive downloads through with their exact size, and
 * checks whether it really does.
 *
 * Two Apache behaviours take Content-Length off a download, and without it
 * browsers and download managers report "file size unknown", cannot resume,
 * and cannot tell a complete download from a truncated one:
 *
 * - Since Apache 2.4.59 (the CVE-2024-24795 fix, also backported to older
 *   distribution builds) mod_proxy_fcgi drops the Content-Length that PHP-FPM
 *   sends, unless the request carries the `ap_trust_cgilike_cl` variable.
 *   This affects every download from a PHP-FPM site, compressed or not.
 * - Hosts that compress every response (cPanel's "Compress all content"
 *   writes `SetOutputFilter DEFLATE`) recompress the archive and replace
 *   Content-Length with chunked encoding, unless `no-gzip` is set.
 *
 * Under mod_php the download handler sets `no-gzip` itself with
 * apache_setenv(), but PHP-FPM cannot reach Apache's environment at all. A
 * small marked block in .htaccess sets both variables for the download
 * actions only; the handler always sends an exact Content-Length.
 *
 * The block goes into the .htaccess files that govern wp-admin: the site's
 * root one and, when WordPress lives in its own subdirectory with its own
 * rewrite rules, that one too (mod_rewrite rules are not inherited by a
 * directory that has rules of its own). It is written only into a file that
 * already carries rewrite rules, because a directive the host does not allow
 * would take the whole site down with a 500 error.
 *
 * Whether any of this worked is not assumed: probe() downloads a small file
 * through the same admin-post.php path over a loopback request and looks at
 * what actually arrives (a rule can be present and ignored: AllowOverride
 * None, PHP proxied with ProxyPassMatch, and so on).
 */
class ServerRules {

	/**
	 * Marker around the block.
	 */
	const MARKER = 'SH Clone Migration';

	/**
	 * Transient holding the last probe result.
	 */
	const PROBE_TRANSIENT = 'shcm_delivery_probe';

	/**
	 * Transient throttling install attempts from wp-admin.
	 */
	const ATTEMPT_TRANSIENT = 'shcm_htaccess_attempt';

	/**
	 * Size of the probe file.
	 */
	const PROBE_SIZE = 131072;

	/**
	 * The lines inside the markers.
	 *
	 * @return string[]
	 */
	public static function lines() {
		return array(
			'# Serve migration archive downloads with their exact size, so browsers',
			'# and download managers can show progress, resume, and confirm that the',
			'# download is complete: keep the (already compressed) archive from being',
			'# compressed again, and let Apache pass on the Content-Length that the',
			'# plugin sends when PHP runs as PHP-FPM (Apache 2.4.59 and later).',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{QUERY_STRING} (^|&)action=shcm_download(_log|_probe)?(&|$)',
			'RewriteRule (^|/)admin-post\.php$ - [E=no-gzip:1,E=dont-vary:1,E=ap_trust_cgilike_cl:1]',
			'</IfModule>',
		);
	}

	/**
	 * The same effect for the server configuration (virtual host), for hosts
	 * where .htaccess rules are not applied.
	 *
	 * @return string
	 */
	public static function serverConfig() {
		return 'SetEnvIfExpr "%{QUERY_STRING} =~ /(^|&)action=shcm_download(_log|_probe)?(&|$)/" no-gzip=1 dont-vary=1 ap_trust_cgilike_cl=1' . "\n";
	}

	/**
	 * The site's root directory (where WordPress writes its .htaccess).
	 *
	 * Computed from ABSPATH and the home/siteurl difference rather than with
	 * get_home_path(), which relies on SCRIPT_FILENAME and is wrong under
	 * WP-CLI or on a front-end request of a site whose WordPress lives in a
	 * subdirectory.
	 *
	 * @return string With trailing slash.
	 */
	public static function homePath() {
		$abspath = str_replace( '\\', '/', ABSPATH );
		$home    = set_url_scheme( (string) get_option( 'home' ), 'http' );
		$siteurl = set_url_scheme( (string) get_option( 'siteurl' ), 'http' );
		if ( '' !== $home && 0 !== strcasecmp( $home, $siteurl ) ) {
			$relative = str_ireplace( $home, '', $siteurl ); // "/wp".
			if ( '' !== $relative && '/' !== $relative ) {
				$position = strripos( $abspath, trailingslashit( $relative ) );
				if ( false !== $position ) {
					return trailingslashit( substr( $abspath, 0, $position ) );
				}
			}
		}
		return trailingslashit( $abspath );
	}

	/**
	 * The .htaccess files that govern requests to wp-admin/admin-post.php.
	 *
	 * @return string[]
	 */
	public static function targets() {
		$targets = array( self::homePath() . '.htaccess' );
		$own     = trailingslashit( str_replace( '\\', '/', ABSPATH ) ) . '.htaccess';
		if ( ! in_array( $own, $targets, true ) && is_file( $own ) ) {
			$targets[] = $own;
		}
		return $targets;
	}

	/**
	 * Path of the root .htaccess (for messages).
	 *
	 * @return string
	 */
	public static function htaccessPath() {
		return self::homePath() . '.htaccess';
	}

	/**
	 * Whether the current block is present in every file that carries
	 * rewrite rules (and in at least one). A block written by an earlier
	 * version does not count, so it gets refreshed.
	 *
	 * @return bool
	 */
	public static function installed() {
		$found = false;
		$block = self::block();
		foreach ( self::targets() as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$contents = str_replace( "\r\n", "\n", (string) @file_get_contents( $path ) );
			$has      = false !== strpos( $contents, $block );
			if ( ! $has && ( self::hasRewriteRules( self::strip( $contents ) ) || false !== strpos( $contents, '# BEGIN ' . self::MARKER ) ) ) {
				return false;
			}
			$found = $found || $has;
		}
		return $found;
	}

	/**
	 * Add (or refresh) the block wherever it can safely go.
	 *
	 * @return bool Whether the block is in place afterwards.
	 */
	public static function install() {
		global $is_apache;

		if ( empty( $is_apache ) || ! apply_filters( 'shcm_manage_htaccess', true ) ) {
			return false;
		}

		$written = false;
		foreach ( self::targets() as $path ) {
			if ( ! is_file( $path ) || ! is_readable( $path ) || ! wp_is_writable( $path ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $path );
			$without  = self::strip( $contents );
			if ( ! self::hasRewriteRules( $without ) ) {
				continue;
			}
			if ( false !== strpos( $contents, self::block() ) ) {
				$written = true;
				continue;
			}
			// First in the file, so a rule further down that ends rewriting
			// with [L] (security plugins add those) cannot skip it.
			if ( self::write( $path, self::block() . "\n" . ltrim( $without, "\r\n" ) ) ) {
				$written = true;
			}
		}

		if ( $written ) {
			delete_transient( self::PROBE_TRANSIENT );
		}
		return $written;
	}

	/**
	 * Try install() again from wp-admin, at most once an hour, until it has
	 * worked: the attempt made on activation or update may have come from
	 * WP-CLI or cron, where $is_apache is not known.
	 *
	 * @return void
	 */
	public static function maybeInstall() {
		global $is_apache;
		if ( empty( $is_apache ) || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_transient( self::ATTEMPT_TRANSIENT ) || self::installed() ) {
			return;
		}
		set_transient( self::ATTEMPT_TRANSIENT, 1, HOUR_IN_SECONDS );
		self::install();
	}

	/**
	 * Remove the block from every file that has it.
	 *
	 * @return bool
	 */
	public static function remove() {
		$ok = true;
		foreach ( self::targets() as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $path );
			$without  = self::strip( $contents );
			if ( $without === $contents ) {
				continue;
			}
			if ( ! wp_is_writable( $path ) || ! self::write( $path, ltrim( $without, "\r\n" ) ) ) {
				$ok = false;
			}
		}
		delete_transient( self::PROBE_TRANSIENT );
		return $ok;
	}

	/**
	 * Path of the probe file (created on demand).
	 *
	 * @param string $directory Storage directory for temporary files.
	 * @return string
	 */
	public static function probeFile( $directory ) {
		$path = trailingslashit( $directory ) . 'delivery-probe.bin';
		if ( ! is_file( $path ) || self::PROBE_SIZE !== (int) @filesize( $path ) ) {
			// Plain, very compressible text: a compressing server would
			// certainly compress it.
			@file_put_contents( $path, substr( str_repeat( "SH Clone Migration download probe.\n", 4000 ), 0, self::PROBE_SIZE ), LOCK_EX );
		}
		return $path;
	}

	/**
	 * Download the probe file over a loopback request and report whether it
	 * arrived with its exact size and uncompressed.
	 *
	 * @param bool $force Ignore the cached result.
	 * @return array{status: string, detail: string, checked: int} status ok,
	 *         stripped (the size was removed or the body recompressed) or
	 *         unknown (the loopback request failed).
	 */
	public static function probe( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::PROBE_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			admin_url( 'admin-post.php?action=shcm_download_probe' ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'decompress'  => false,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Accept-Encoding' => 'gzip, deflate' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'status' => 'unknown',
				'detail' => $response->get_error_message(),
			);
		} else {
			$code     = (int) wp_remote_retrieve_response_code( $response );
			$length   = wp_remote_retrieve_header( $response, 'content-length' );
			$encoding = strtolower( (string) wp_remote_retrieve_header( $response, 'content-encoding' ) );
			if ( 200 !== $code ) {
				$result = array(
					'status' => 'unknown',
					'detail' => sprintf( 'HTTP %d', $code ),
				);
			} elseif ( (string) self::PROBE_SIZE === trim( (string) $length ) && ( '' === $encoding || 'identity' === $encoding ) ) {
				$result = array(
					'status' => 'ok',
					'detail' => sprintf( 'Content-Length %d', self::PROBE_SIZE ),
				);
			} else {
				$result = array(
					'status' => 'stripped',
					'detail' => sprintf(
						'Content-Length %1$s, Content-Encoding %2$s',
						'' === (string) $length ? 'missing' : (string) $length,
						'' === $encoding ? 'none' : $encoding
					),
				);
			}
		}

		$result['checked'] = time();
		set_transient( self::PROBE_TRANSIENT, $result, 'unknown' === $result['status'] ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Whether .htaccess text carries active rewrite rules.
	 *
	 * @param string $contents File contents.
	 * @return bool
	 */
	public static function hasRewriteRules( $contents ) {
		return (bool) preg_match( '/^\s*RewriteEngine\s+On\b/mi', (string) $contents );
	}

	/**
	 * The complete marked block.
	 *
	 * @return string
	 */
	public static function block() {
		return '# BEGIN ' . self::MARKER . "\n" . implode( "\n", self::lines() ) . "\n" . '# END ' . self::MARKER . "\n";
	}

	/**
	 * Contents without the marked block.
	 *
	 * @param string $contents File contents.
	 * @return string
	 */
	protected static function strip( $contents ) {
		$pattern = '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\R.*?^# END ' . preg_quote( self::MARKER, '/' ) . '[^\S\r\n]*\R?/ms';
		return (string) preg_replace( $pattern, '', $contents );
	}

	/**
	 * Replace the file contents under a lock.
	 *
	 * @param string $path     File.
	 * @param string $contents New contents.
	 * @return bool
	 */
	protected static function write( $path, $contents ) {
		$handle = @fopen( $path, 'r+' );
		if ( ! $handle ) {
			return false;
		}
		flock( $handle, LOCK_EX );
		ftruncate( $handle, 0 );
		rewind( $handle );
		$written = fwrite( $handle, $contents );
		fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle );
		return strlen( $contents ) === $written;
	}
}
