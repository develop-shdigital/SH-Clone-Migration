<?php
/**
 * Server capability detection.
 *
 * @package SHCM
 */

namespace SHCM\Core;

use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Detects what the host can actually do, so the engine can pick the best
 * available strategy instead of assuming one.
 */
class Environment {

	/**
	 * Storage helper.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Cached capability map.
	 *
	 * @var array|null
	 */
	protected $capabilities = null;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage Storage helper.
	 */
	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Capability map.
	 *
	 * @return array
	 */
	public function capabilities() {
		if ( null !== $this->capabilities ) {
			return $this->capabilities;
		}

		$caps = array(
			'zlib'              => function_exists( 'gzencode' ) && function_exists( 'gzdecode' ),
			'zlib_filters'      => in_array( 'zlib.deflate', stream_get_filters(), true ),
			'zip_archive'       => class_exists( 'ZipArchive' ),
			'phar'              => class_exists( 'Phar' ),
			'sodium'            => function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ),
			'openssl'           => function_exists( 'openssl_encrypt' ),
			'shell'             => $this->shellAvailable(),
			'mysqldump'         => false,
			'iterators'         => class_exists( 'RecursiveIteratorIterator' ),
			'set_time_limit'    => $this->functionEnabled( 'set_time_limit' ),
			'ignore_user_abort' => $this->functionEnabled( 'ignore_user_abort' ),
			'writable_storage'  => is_dir( $this->storage->base() ) && is_writable( $this->storage->base() ),
			'writable_content'  => is_writable( Paths::contentDir() ),
			'writable_abspath'  => is_writable( Paths::abspath() ),
			'curl'              => function_exists( 'curl_init' ),
			'memory_limit'      => Bytes::parseIni( ini_get( 'memory_limit' ) ),
			'max_execution'     => (int) ini_get( 'max_execution_time' ),
			'upload_max'        => Bytes::parseIni( ini_get( 'upload_max_filesize' ) ),
			'post_max'          => Bytes::parseIni( ini_get( 'post_max_size' ) ),
			'free_space'        => $this->storage->freeSpace(),
			'total_space'       => $this->storage->totalSpace(),
			'open_basedir'      => (string) ini_get( 'open_basedir' ),
			'safe_mode_dirs'    => '' !== (string) ini_get( 'open_basedir' ),
		);

		if ( $caps['shell'] ) {
			$caps['mysqldump'] = $this->binaryAvailable( 'mysqldump' ) || $this->binaryAvailable( 'mariadb-dump' );
		}

		$this->capabilities = $caps;
		return $caps;
	}

	/**
	 * Whether a single capability is available.
	 *
	 * @param string $key Capability key.
	 * @return mixed
	 */
	public function can( $key ) {
		$caps = $this->capabilities();
		return isset( $caps[ $key ] ) ? $caps[ $key ] : false;
	}

	/**
	 * Chosen compression engine.
	 *
	 * @param string $preference auto|gzip|none.
	 * @return string gzip|none
	 */
	public function compressionEngine( $preference = 'auto' ) {
		if ( 'none' === $preference ) {
			return 'none';
		}
		if ( ! $this->can( 'zlib' ) ) {
			return 'none';
		}
		return 'gzip';
	}

	/**
	 * Whether shell_exec/proc_open are usable.
	 *
	 * @return bool
	 */
	protected function shellAvailable() {
		if ( ! $this->functionEnabled( 'shell_exec' ) && ! $this->functionEnabled( 'proc_open' ) ) {
			return false;
		}
		if ( ! $this->functionEnabled( 'escapeshellarg' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a PHP function exists and is not disabled.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public function functionEnabled( $name ) {
		if ( ! function_exists( $name ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( $name, $disabled, true );
	}

	/**
	 * Whether an external binary is on the PATH.
	 *
	 * @param string $binary Binary name.
	 * @return bool
	 */
	protected function binaryAvailable( $binary ) {
		if ( ! $this->functionEnabled( 'shell_exec' ) ) {
			return false;
		}
		$output = @shell_exec( 'command -v ' . escapeshellarg( $binary ) . ' 2>/dev/null' );
		return is_string( $output ) && '' !== trim( $output );
	}

	/**
	 * Database server description.
	 *
	 * @return array
	 */
	public function databaseInfo() {
		global $wpdb;
		$version = '';
		$server  = 'MySQL';
		if ( isset( $wpdb ) ) {
			$version = $wpdb->db_version();
			$raw     = $wpdb->get_var( 'SELECT VERSION()' );
			if ( is_string( $raw ) && false !== stripos( $raw, 'mariadb' ) ) {
				$server = 'MariaDB';
			}
			if ( is_string( $raw ) && '' !== $raw ) {
				$version = $raw;
			}
		}
		return array(
			'server'  => $server,
			'version' => $version,
			'charset' => isset( $wpdb ) ? $wpdb->charset : '',
			'collate' => isset( $wpdb ) ? $wpdb->collate : '',
			'prefix'  => isset( $wpdb ) ? $wpdb->prefix : '',
		);
	}

	/**
	 * Full system status report used by the diagnostics screen.
	 *
	 * @return array
	 */
	public function report() {
		global $wp_version, $wpdb;
		$caps = $this->capabilities();
		$db   = $this->databaseInfo();

		$rows = array(
			'wordpress_version'  => array(
				'label' => __( 'WordPress version', 'sh-clone-migration' ),
				'value' => isset( $wp_version ) ? $wp_version : get_bloginfo( 'version' ),
			),
			'php_version'        => array(
				'label' => __( 'PHP version', 'sh-clone-migration' ),
				'value' => PHP_VERSION,
			),
			'database'           => array(
				'label' => __( 'Database server', 'sh-clone-migration' ),
				'value' => $db['server'] . ' ' . $db['version'],
			),
			'db_prefix'          => array(
				'label' => __( 'Table prefix', 'sh-clone-migration' ),
				'value' => $db['prefix'],
			),
			'db_charset'         => array(
				'label' => __( 'Database charset / collation', 'sh-clone-migration' ),
				'value' => trim( $db['charset'] . ' / ' . $db['collate'] ),
			),
			'memory_limit'       => array(
				'label' => __( 'PHP memory limit', 'sh-clone-migration' ),
				'value' => Bytes::format( $caps['memory_limit'] ),
			),
			'wp_memory_limit'    => array(
				'label' => __( 'WordPress memory limit', 'sh-clone-migration' ),
				'value' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '—',
			),
			'max_execution_time' => array(
				'label' => __( 'Max execution time', 'sh-clone-migration' ),
				'value' => 0 === $caps['max_execution'] ? __( 'unlimited', 'sh-clone-migration' ) : $caps['max_execution'] . 's',
			),
			'upload_max'         => array(
				'label' => __( 'Upload max filesize', 'sh-clone-migration' ),
				'value' => Bytes::format( $caps['upload_max'] ),
			),
			'post_max'           => array(
				'label' => __( 'POST max size', 'sh-clone-migration' ),
				'value' => Bytes::format( $caps['post_max'] ),
			),
			'disk_free'          => array(
				'label' => __( 'Free disk space', 'sh-clone-migration' ),
				'value' => $caps['free_space'] < 0 ? __( 'unknown', 'sh-clone-migration' ) : Bytes::format( $caps['free_space'] ),
			),
			'disk_total'         => array(
				'label' => __( 'Total disk space', 'sh-clone-migration' ),
				'value' => $caps['total_space'] < 0 ? __( 'unknown', 'sh-clone-migration' ) : Bytes::format( $caps['total_space'] ),
			),
			'storage_dir'        => array(
				'label' => __( 'Storage directory', 'sh-clone-migration' ),
				'value' => $this->storage->base(),
			),
			'storage_writable'   => array(
				'label' => __( 'Storage writable', 'sh-clone-migration' ),
				'value' => $caps['writable_storage'] ? __( 'yes', 'sh-clone-migration' ) : __( 'no', 'sh-clone-migration' ),
			),
			'content_writable'   => array(
				'label' => __( 'wp-content writable', 'sh-clone-migration' ),
				'value' => $caps['writable_content'] ? __( 'yes', 'sh-clone-migration' ) : __( 'no', 'sh-clone-migration' ),
			),
			'zlib'               => array(
				'label' => __( 'zlib compression', 'sh-clone-migration' ),
				'value' => $caps['zlib'] ? __( 'available', 'sh-clone-migration' ) : __( 'missing', 'sh-clone-migration' ),
			),
			'ziparchive'         => array(
				'label' => __( 'ZipArchive', 'sh-clone-migration' ),
				'value' => $caps['zip_archive'] ? __( 'available', 'sh-clone-migration' ) : __( 'missing', 'sh-clone-migration' ),
			),
			'sodium'             => array(
				'label' => __( 'libsodium (archive encryption)', 'sh-clone-migration' ),
				'value' => $caps['sodium'] ? __( 'available', 'sh-clone-migration' ) : ( $caps['openssl'] ? __( 'OpenSSL fallback', 'sh-clone-migration' ) : __( 'missing', 'sh-clone-migration' ) ),
			),
			'shell'              => array(
				'label' => __( 'Shell commands', 'sh-clone-migration' ),
				'value' => $caps['shell'] ? __( 'available', 'sh-clone-migration' ) : __( 'unavailable (not required)', 'sh-clone-migration' ),
			),
			'multisite'          => array(
				'label' => __( 'Multisite', 'sh-clone-migration' ),
				'value' => is_multisite() ? __( 'yes', 'sh-clone-migration' ) : __( 'no', 'sh-clone-migration' ),
			),
			'db_connection'      => array(
				'label' => __( 'Database connection', 'sh-clone-migration' ),
				'value' => ( isset( $wpdb ) && $wpdb->check_connection( false ) ) ? __( 'OK', 'sh-clone-migration' ) : __( 'FAILED', 'sh-clone-migration' ),
			),
			'download_delivery'  => array(
				'label' => __( 'Archive downloads', 'sh-clone-migration' ),
				'value' => $this->downloadDelivery( true )['value'],
			),
		);

		return array(
			'rows'         => $rows,
			'capabilities' => $caps,
			'warnings'     => $this->warnings(),
		);
	}

	/**
	 * Whether archive downloads reach the browser with their exact size.
	 *
	 * Measured, not assumed: ServerRules::probe() downloads a small file
	 * through the download path over a loopback request. The result is
	 * cached; $probe allows running the check when nothing is cached (page
	 * loads in wp-admin, never an AJAX tick or WP-CLI, where no web server
	 * is involved).
	 *
	 * @param bool $probe Run the loopback check when no result is cached.
	 * @return array{ok: bool|null, value: string, status: string} ok is null
	 *         when it could not be determined.
	 */
	public function downloadDelivery( $probe = false ) {
		global $is_apache;

		$cli    = ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI;
		$result = get_transient( ServerRules::PROBE_TRANSIENT );
		if ( ! is_array( $result ) && $probe && ! $cli ) {
			$result = ServerRules::probe( true );
		}
		$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : 'unchecked';

		if ( 'ok' === $status ) {
			return array(
				'ok'     => true,
				'status' => $status,
				'value'  => __( 'exact size sent (checked with a test download)', 'sh-clone-migration' ),
			);
		}
		if ( 'stripped' === $status ) {
			return array(
				'ok'     => false,
				'status' => $status,
				/* translators: %s: what the test download received */
				'value'  => sprintf( __( 'the server removes the size (test download: %s)', 'sh-clone-migration' ), $result['detail'] ),
			);
		}
		if ( $cli ) {
			return array(
				'ok'     => null,
				'status' => $status,
				'value'  => __( 'not checked from the command line (open System Status in the browser)', 'sh-clone-migration' ),
			);
		}
		if ( ! empty( $is_apache ) && 'apache2handler' !== PHP_SAPI && ! ServerRules::installed() ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'value'  => __( 'the server probably removes the size (.htaccess rule missing, test download not possible)', 'sh-clone-migration' ),
			);
		}
		return array(
			'ok'     => null,
			'status' => $status,
			'value'  => 'unknown' === $status
				/* translators: %s: error */
				? sprintf( __( 'not checked: the test download failed (%s)', 'sh-clone-migration' ), $result['detail'] )
				: __( 'not checked yet', 'sh-clone-migration' ),
		);
	}

	/**
	 * The warning, with the fix that fits this server, for downloads that
	 * lose their size.
	 *
	 * @param array $delivery downloadDelivery() result.
	 * @return array
	 */
	protected function deliveryWarning( array $delivery ) {
		global $is_apache;

		$intro = __( 'Archive downloads lose their size on this server, so download managers report "file size unknown" and cannot resume. The archive itself is still complete: compare its size in bytes and its SHA-256.', 'sh-clone-migration' );

		if ( empty( $is_apache ) ) {
			return array(
				'level'   => 'warning',
				'message' => $intro . ' ' . __( 'This is not Apache. On nginx, make sure gzip_types does not include application/octet-stream; behind a proxy or CDN, make sure it does not compress downloads.', 'sh-clone-migration' ),
			);
		}

		$root_rules = is_file( ServerRules::htaccessPath() ) && ServerRules::hasRewriteRules( (string) @file_get_contents( ServerRules::htaccessPath() ) );
		if ( ServerRules::installed() ) {
			$message = __( 'The rule the plugin added to .htaccess is not applied by this server (for example AllowOverride None, or PHP proxied with ProxyPassMatch). Ask your host to add this line to the server configuration (virtual host):', 'sh-clone-migration' );
			$code    = ServerRules::serverConfig();
		} elseif ( $root_rules ) {
			$message = sprintf(
				/* translators: %s: .htaccess path */
				__( 'The plugin could not add its rule to %s. Add these lines at the top of that file (or ask your host to add the single line after them to the server configuration):', 'sh-clone-migration' ),
				ServerRules::htaccessPath()
			);
			$code = ServerRules::block() . "\n" . ServerRules::serverConfig();
		} else {
			// Without rewrite rules there, the host may not allow them in
			// .htaccess at all, and pasting some would cause a 500 error.
			$message = __( 'This site does not use .htaccess rewrite rules, so the plugin does not add any. Ask your host to add this line to the server configuration (virtual host):', 'sh-clone-migration' );
			$code    = ServerRules::serverConfig();
		}

		return array(
			'level'   => 'warning',
			'message' => $intro . ' ' . $message,
			'code'    => $code,
		);
	}

	/**
	 * Environment warnings. These are advisory: the engine works around each of
	 * them rather than refusing to run.
	 *
	 * @return array[] Each entry has level and message keys.
	 */
	public function warnings() {
		$caps     = $this->capabilities();
		$warnings = array();

		if ( $caps['memory_limit'] > 0 && $caps['memory_limit'] < 128 * 1024 * 1024 ) {
			$warnings[] = array(
				'level'   => 'warning',
				'message' => sprintf(
					/* translators: %s: memory limit */
					__( 'PHP memory limit is low (%s). The migration engine streams data and stays well below it, but raising it to 256M gives more headroom.', 'sh-clone-migration' ),
					Bytes::format( $caps['memory_limit'] )
				),
			);
		}
		if ( ! $caps['writable_storage'] ) {
			$warnings[] = array(
				'level'   => 'error',
				'message' => sprintf(
					/* translators: %s: directory path */
					__( 'The storage directory is not writable: %s', 'sh-clone-migration' ),
					$this->storage->base()
				),
			);
		}
		$delivery = $this->downloadDelivery( true );
		if ( false === $delivery['ok'] ) {
			$warnings[] = $this->deliveryWarning( $delivery );
		}
		if ( ! $caps['writable_content'] ) {
			$warnings[] = array(
				'level'   => 'error',
				'message' => __( 'wp-content is not writable, so files cannot be restored on this server.', 'sh-clone-migration' ),
			);
		}
		if ( ! $caps['zlib'] ) {
			$warnings[] = array(
				'level'   => 'warning',
				'message' => __( 'zlib is unavailable, archives will be stored uncompressed. Migration still works.', 'sh-clone-migration' ),
			);
		}
		if ( ! $caps['sodium'] && ! $caps['openssl'] ) {
			$warnings[] = array(
				'level'   => 'warning',
				'message' => __( 'Neither libsodium nor OpenSSL is available, so archive encryption is disabled on this server.', 'sh-clone-migration' ),
			);
		}
		if ( $caps['upload_max'] > 0 && $caps['upload_max'] < 64 * 1024 * 1024 ) {
			$warnings[] = array(
				'level'   => 'info',
				'message' => sprintf(
					/* translators: %s: upload limit */
					__( 'Browser upload limit is %s. Large archives are uploaded in chunks below that limit, so this does not cap the archive size.', 'sh-clone-migration' ),
					Bytes::format( $caps['upload_max'] )
				),
			);
		}
		if ( $caps['free_space'] >= 0 ) {
			$estimate = $this->estimateSiteSize();
			if ( $estimate > 0 && $caps['free_space'] < $estimate ) {
				$warnings[] = array(
					'level'   => 'warning',
					'message' => sprintf(
						/* translators: 1: free space, 2: estimated archive size */
						__( 'Available disk space (%1$s) may not be sufficient for an archive of this site (rough estimate %2$s).', 'sh-clone-migration' ),
						Bytes::format( $caps['free_space'] ),
						Bytes::format( $estimate )
					),
				);
			}
		}
		$exposure = $this->storageExposure();
		if ( ! empty( $exposure['exposed'] ) ) {
			$warnings[] = array(
				'level'   => 'error',
				'message' => sprintf(
					/* translators: %s: directory path */
					__( 'The storage directory is readable over HTTP on this server, which means a migration archive could be downloaded by anyone who knows its file name. Archive names are random, but you should still block %s in your web server configuration. The documentation contains an nginx snippet.', 'sh-clone-migration' ),
					$this->storage->base()
				),
			);
		}

		if ( '' !== $caps['open_basedir'] ) {
			$warnings[] = array(
				'level'   => 'info',
				'message' => __( 'open_basedir is active. Only paths inside the allowed list can be migrated.', 'sh-clone-migration' ),
			);
		}

		return $warnings;
	}

	/**
	 * Test whether the storage directory can be read over HTTP.
	 *
	 * The directory ships with .htaccess and web.config rules, but nginx and
	 * some managed platforms ignore both. Rather than assume the archives are
	 * protected, a short lived canary file is written and fetched back over
	 * the site's own URL; anything that comes back means the archives are
	 * reachable by anyone who can guess a file name.
	 *
	 * @param bool $refresh Bypass the cached result.
	 * @return array{checked:bool,exposed:bool,url:string}
	 */
	public function storageExposure( $refresh = false ) {
		$cached = $refresh ? false : get_transient( 'shcm_storage_exposure' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = array(
			'checked' => false,
			'exposed' => false,
			'url'     => '',
		);

		$base = $this->storage->base();
		$relative = Paths::relativeTo( $base, Paths::contentDir() );
		if ( null === $relative || ! is_dir( $base ) || ! is_writable( $base ) ) {
			return $result;
		}

		$token = bin2hex( random_bytes( 16 ) );
		$name  = 'probe-' . $token . '.txt';
		$path  = Paths::trailingslash( $base ) . $name;
		if ( false === @file_put_contents( $path, $token ) ) {
			return $result;
		}

		$url      = content_url( $relative . '/' . $name );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'sslverify'   => false,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		@unlink( $path );

		if ( ! is_wp_error( $response ) ) {
			$result['checked'] = true;
			$result['url']     = $url;
			$result['exposed'] = 200 === (int) wp_remote_retrieve_response_code( $response )
				&& false !== strpos( (string) wp_remote_retrieve_body( $response ), $token );
		}

		set_transient( 'shcm_storage_exposure', $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Very rough site size estimate used only for disk space warnings.
	 *
	 * @return int Bytes, 0 when unknown.
	 */
	public function estimateSiteSize() {
		global $wpdb;
		$size = 0;
		if ( isset( $wpdb ) ) {
			$db_size = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
					DB_NAME
				)
			);
			$size   += (int) $db_size;
		}
		$uploads = Paths::uploadsDir();
		$cached  = get_transient( 'shcm_uploads_size' );
		if ( false === $cached ) {
			$cached = $this->quickDirSize( $uploads, 4000 );
			set_transient( 'shcm_uploads_size', $cached, HOUR_IN_SECONDS );
		}
		$size += (int) $cached;
		return $size;
	}

	/**
	 * Sample based directory size, capped so it never becomes the slow part of
	 * loading an admin screen.
	 *
	 * @param string $dir       Directory.
	 * @param int    $max_files Stop after this many files and extrapolate.
	 * @return int
	 */
	protected function quickDirSize( $dir, $max_files = 4000 ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$total = 0;
		$count = 0;
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$total += $file->getSize();
					++$count;
					if ( $count >= $max_files ) {
						// Extrapolate: assume the sampled average holds.
						return (int) ( $total * 1.5 );
					}
				}
			}
		} catch ( \Exception $e ) {
			return $total;
		}
		return $total;
	}
}
