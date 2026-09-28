<?php
/**
 * Fake Google OAuth 2.0 + Drive API v3 server for end-to-end tests of the Google Drive backup storage.
 *
 * Router script for PHP's built-in web server (no WordPress, no Composer needed):
 *
 *     FAKE_GOOGLE_DIR=/tmp/fake-google php -S 127.0.0.1:8091 tests/fake-google/router.php
 *
 * It mirrors the parts of the real protocol the plugin talks to (status codes, headers, error bodies), because the
 * plugin's client is written against Google, not against this file. README.md lists the endpoints, the control API
 * (fault injection) and what is and is not emulated.
 *
 * State is one JSON file plus one blob per uploaded file / open upload session in $FAKE_GOOGLE_DIR. Every request
 * holds an exclusive flock() for its read-modify-write, so the server also works with PHP_CLI_SERVER_WORKERS > 1.
 *
 * PHP 7.4 compatible on purpose: it must run on the oldest PHP the plugin supports.
 *
 * @package SHCM\Tests
 */

namespace SHCM\Tests\FakeGoogle;

const FOLDER_MIME        = 'application/vnd.google-apps.folder';
const DRIVE_FILE_SCOPE   = 'https://www.googleapis.com/auth/drive.file';
const CHUNK_GRANULARITY  = 262144;
const SESSION_LIFETIME   = 604800;
const CODE_LIFETIME      = 600;
const LOG_LIMIT          = 200;
const MAX_REFRESH_TOKENS = 100;
const REFRESH_IDLE_LIMIT = 15811200;
const TESTING_GRANT_LIFE = 604800;

/**
 * Carries a finished HTTP response out of nested validation code.
 */
final class HttpError extends \Exception {

	/**
	 * The response to send.
	 *
	 * @var array
	 */
	private $response;

	/**
	 * Constructor.
	 *
	 * @param array $response Response array (see response()).
	 */
	public function __construct( array $response ) {
		parent::__construct( 'HTTP ' . $response['status'] );
		$this->response = $response;
	}

	/**
	 * The response to send.
	 *
	 * @return array
	 */
	public function response() {
		return $this->response;
	}
}

/**
 * Builds a response array.
 *
 * @param int         $status  HTTP status.
 * @param string      $body    Body.
 * @param array       $headers Header name => value.
 * @param string|null $reason  Custom reason phrase (e.g. "Resume Incomplete" for 308).
 * @return array
 */
function response( $status, $body = '', array $headers = array(), $reason = null ) {
	return array(
		'status'  => (int) $status,
		'reason'  => $reason,
		'headers' => $headers,
		'body'    => (string) $body,
		'stream'  => null,
		'drop'    => false,
		'delay'   => 0.0,
	);
}

/**
 * Encodes JSON the way Google's front ends do (pretty, unescaped slashes).
 *
 * @param mixed $data Data.
 * @return string
 */
function encode_json( $data ) {
	return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
}

/**
 * JSON response.
 *
 * @param int   $status  HTTP status.
 * @param mixed $data    Data to encode.
 * @param array $headers Extra headers.
 * @return array
 */
function json_response( $status, $data, array $headers = array() ) {
	return response( $status, encode_json( $data ) . "\n", array_merge( array( 'Content-Type' => 'application/json; charset=UTF-8' ), $headers ) );
}

/**
 * Plain-text response (Google's upload front end answers session errors in text/plain).
 *
 * @param int         $status  HTTP status.
 * @param string      $text    Body.
 * @param array       $headers Extra headers.
 * @param string|null $reason  Custom reason phrase.
 * @return array
 */
function text_response( $status, $text, array $headers = array(), $reason = null ) {
	return response( $status, $text, array_merge( array( 'Content-Type' => 'text/plain; charset=utf-8' ), $headers ), $reason );
}

/**
 * HTML response.
 *
 * @param int    $status  HTTP status.
 * @param string $html    Body.
 * @param array  $headers Extra headers.
 * @return array
 */
function html_response( $status, $html, array $headers = array() ) {
	return response( $status, $html, array_merge( array( 'Content-Type' => 'text/html; charset=utf-8' ), $headers ) );
}

/**
 * Headers the OAuth endpoints send on every JSON answer.
 *
 * @return array
 */
function no_cache_headers() {
	return array(
		'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
		'Pragma'        => 'no-cache',
		'Expires'       => 'Mon, 01 Jan 1990 00:00:00 GMT',
	);
}

/**
 * OAuth token/revoke endpoint error: {"error":"...","error_description":"..."}.
 *
 * @param int    $status      HTTP status.
 * @param string $error       Error code.
 * @param string $description Description.
 * @return array
 */
function oauth_error( $status, $error, $description ) {
	return json_response(
		$status,
		array(
			'error'             => $error,
			'error_description' => $description,
		),
		no_cache_headers()
	);
}

/**
 * The error domain Google uses for a reason.
 *
 * @param string $reason Reason.
 * @return string
 */
function error_domain( $reason ) {
	return in_array( $reason, array( 'userRateLimitExceeded', 'rateLimitExceeded', 'dailyLimitExceeded', 'storageQuotaExceeded' ), true ) ? 'usageLimits' : 'global';
}

/**
 * Drive API error: {"error":{"code":N,"message":"...","errors":[{...}]}}.
 *
 * @param int    $status  HTTP status.
 * @param string $message Message.
 * @param string $reason  errors[0].reason.
 * @param array  $opts    location, locationType, domain, status, details, item_message, headers.
 * @return array
 */
function drive_error( $status, $message, $reason, array $opts = array() ) {
	$item = array(
		'message' => isset( $opts['item_message'] ) ? $opts['item_message'] : $message,
		'domain'  => isset( $opts['domain'] ) ? $opts['domain'] : error_domain( $reason ),
		'reason'  => $reason,
	);
	if ( isset( $opts['location'] ) ) {
		$item['location']     = $opts['location'];
		$item['locationType'] = isset( $opts['locationType'] ) ? $opts['locationType'] : 'parameter';
	}
	$error = array(
		'code'    => (int) $status,
		'message' => $message,
		'errors'  => array( $item ),
	);
	if ( isset( $opts['status'] ) ) {
		$error['status'] = $opts['status'];
	}
	if ( isset( $opts['details'] ) ) {
		$error['details'] = $opts['details'];
	}
	return json_response( $status, array( 'error' => $error ), isset( $opts['headers'] ) ? $opts['headers'] : array() );
}

/**
 * RFC 3339 UTC timestamp with milliseconds, as Drive formats createdTime/modifiedTime.
 *
 * @param float $ts Unix timestamp.
 * @return string
 */
function rfc3339( $ts ) {
	$sec = (int) floor( $ts );
	$ms  = (int) floor( ( $ts - $sec ) * 1000 );
	return gmdate( 'Y-m-d\TH:i:s', $sec ) . sprintf( '.%03dZ', min( 999, max( 0, $ms ) ) );
}

/**
 * Parses an RFC 3339 date-time (zone optional = UTC, as Drive query terms allow).
 *
 * @param mixed $value Value.
 * @return float|null Unix timestamp, null when invalid.
 */
function parse_time( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/i', $value, $m ) ) {
		return null;
	}
	$zone = ( isset( $m[4] ) && '' !== $m[4] && 'Z' !== strtoupper( $m[4] ) ) ? $m[4] : '+00:00';
	$date = \DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $m[1] . 'T' . $m[2] . $zone );
	if ( false === $date ) {
		return null;
	}
	$frac = ( isset( $m[3] ) && '' !== $m[3] ) ? (float) ( '0' . $m[3] ) : 0.0;
	return $date->getTimestamp() + $frac;
}

/**
 * Random URL-safe token.
 *
 * @param int  $length Length.
 * @param bool $alnum  Letters and digits only.
 * @return string
 */
function random_token( $length, $alnum = false ) {
	$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' . ( $alnum ? '' : '-_' );
	$max   = strlen( $chars ) - 1;
	$out   = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, $max ) ];
	}
	return $out;
}

/**
 * Hides a secret except its last 6 characters.
 *
 * @param string $value Secret.
 * @return string
 */
function redact( $value ) {
	$value = (string) $value;
	return strlen( $value ) <= 6 ? '***' : '***' . substr( $value, -6 );
}

/**
 * Redacts credential-like parameters in a raw query string (for the request log).
 *
 * @param string $query Raw query string.
 * @return string
 */
function redact_query( $query ) {
	if ( '' === $query ) {
		return '';
	}
	$secret = array( 'access_token', 'token', 'refresh_token', 'code', 'upload_id', 'client_secret', 'id_token' );
	$parts  = array();
	foreach ( explode( '&', $query ) as $pair ) {
		$bits = explode( '=', $pair, 2 );
		$name = urldecode( $bits[0] );
		if ( isset( $bits[1] ) && in_array( $name, $secret, true ) ) {
			$pair = $bits[0] . '=' . redact( urldecode( $bits[1] ) );
		}
		$parts[] = $pair;
	}
	return implode( '&', $parts );
}

/**
 * Whether an array is a list (0..n-1 keys).
 *
 * @param array $value Array.
 * @return bool
 */
function is_list( array $value ) {
	return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
}

/**
 * base64url without padding.
 *
 * @param string $data Data.
 * @return string
 */
function b64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Configuration defaults, overridable by environment variables.
 *
 * @return array
 */
function default_config() {
	$env   = function ( $name, $fallback ) {
		$value = getenv( $name );
		return ( false === $value || '' === $value ) ? $fallback : $value;
	};
	$limit = (string) $env( 'FAKE_GOOGLE_QUOTA_LIMIT', '16106127360' );
	return array(
		'client_id'         => (string) $env( 'FAKE_GOOGLE_CLIENT_ID', 'test-client.apps.googleusercontent.com' ),
		'client_secret'     => (string) $env( 'FAKE_GOOGLE_CLIENT_SECRET', 'GOCSPX-fake-secret-0123456789' ),
		'access_token_ttl'  => (int) $env( 'FAKE_GOOGLE_ACCESS_TOKEN_TTL', '3600' ),
		'quota_limit'       => in_array( strtolower( $limit ), array( 'unlimited', 'none', 'null' ), true ) ? null : (int) $limit,
		'quota_usage'       => (int) $env( 'FAKE_GOOGLE_QUOTA_USAGE', '0' ),
		'email'             => (string) $env( 'FAKE_GOOGLE_EMAIL', 'owner@example.test' ),
		'name'              => (string) $env( 'FAKE_GOOGLE_NAME', 'Test Owner' ),
		'redirect_uris'     => array_values( array_filter( preg_split( '/[\s,]+/', (string) $env( 'FAKE_GOOGLE_REDIRECT_URIS', '' ) ) ) ),
		'max_upload_size'   => (int) $env( 'FAKE_GOOGLE_MAX_UPLOAD_SIZE', '5497558138880' ),
		'publishing_status' => (string) $env( 'FAKE_GOOGLE_PUBLISHING_STATUS', 'production' ),
	);
}

/**
 * Sends a response array to the client.
 *
 * @param array $r Response.
 * @return void
 */
function emit( array $r ) {
	if ( $r['delay'] > 0 ) {
		usleep( (int) ( $r['delay'] * 1000000 ) );
	}
	header_remove( 'X-Powered-By' );
	if ( ! empty( $r['drop'] ) ) {
		// The built-in server cannot close a socket without answering. Promising a large body and stopping after one
		// byte is the closest thing: curl (and so WordPress) reports error 18, a transport failure, and a lenient
		// client that ignores the short body still sees a 503.
		header( 'HTTP/1.1 503 Service Unavailable' );
		header( 'Content-Type: text/plain' );
		header( 'Content-Length: 1048576' );
		echo 'x';
		return;
	}
	foreach ( $r['headers'] as $name => $value ) {
		header( $name . ': ' . $value );
	}
	// The status goes last: PHP silently turns any response with a Location header into a 302, and the resumable
	// initiation must answer 200 + Location.
	if ( null !== $r['reason'] ) {
		header( sprintf( 'HTTP/1.1 %d %s', $r['status'], $r['reason'] ) );
	} else {
		http_response_code( $r['status'] );
	}
	if ( is_array( $r['stream'] ) ) {
		list( $handle, $offset, $length ) = $r['stream'];
		header( 'Content-Length: ' . $length );
		fseek( $handle, $offset );
		while ( $length > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 1048576, $length ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- raw file bytes.
			$length -= strlen( $chunk );
		}
		fclose( $handle );
		return;
	}
	if ( 204 !== $r['status'] ) {
		header( 'Content-Length: ' . strlen( $r['body'] ) );
		echo $r['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- protocol body.
	}
}

/**
 * State directory, JSON state file and blobs, all behind one lock file.
 */
final class Store {

	/**
	 * Directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Lock handle while locked.
	 *
	 * @var resource|null
	 */
	private $lock_handle;

	/**
	 * Constructor.
	 *
	 * @param string $dir State directory (created when missing).
	 */
	public function __construct( $dir ) {
		$this->dir = rtrim( $dir, '/' );
		foreach ( array( '', '/blobs', '/uploads', '/spool' ) as $sub ) {
			if ( ! is_dir( $this->dir . $sub ) && ! @mkdir( $this->dir . $sub, 0777, true ) && ! is_dir( $this->dir . $sub ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- racing workers.
				throw new \RuntimeException( 'Cannot create ' . $this->dir . $sub );
			}
		}
	}

	/**
	 * Takes the exclusive state lock (blocks).
	 *
	 * @return void
	 */
	public function lock() {
		$this->lock_handle = fopen( $this->dir . '/state.lock', 'c' );
		if ( false === $this->lock_handle || ! flock( $this->lock_handle, LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot lock the fake Google state' );
		}
	}

	/**
	 * Releases the lock.
	 *
	 * @return void
	 */
	public function unlock() {
		if ( is_resource( $this->lock_handle ) ) {
			flock( $this->lock_handle, LOCK_UN );
			fclose( $this->lock_handle );
		}
		$this->lock_handle = null;
	}

	/**
	 * Reads the state (caller holds the lock).
	 *
	 * @return array|null Null when there is none yet.
	 */
	public function load() {
		$file = $this->dir . '/state.json';
		$raw  = is_file( $file ) ? file_get_contents( $file ) : false;
		$data = false === $raw ? null : json_decode( $raw, true );
		return ( is_array( $data ) && isset( $data['version'] ) ) ? $data : null;
	}

	/**
	 * Writes the state atomically (caller holds the lock).
	 *
	 * @param array $state State.
	 * @return void
	 */
	public function save( array $state ) {
		$file = $this->dir . '/state.json';
		$tmp  = $file . '.' . getmypid() . '.tmp';
		$json = json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( false === file_put_contents( $tmp, $json ) || ! rename( $tmp, $file ) ) {
			throw new \RuntimeException( 'Cannot write the fake Google state' );
		}
	}

	/**
	 * Blob path of a finalized file.
	 *
	 * @param string $id File id.
	 * @return string
	 */
	public function blobPath( $id ) {
		return $this->dir . '/blobs/' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $id ) . '.bin';
	}

	/**
	 * Bytes committed so far to an upload session.
	 *
	 * @param string $upload_id Session id.
	 * @return string
	 */
	public function partPath( $upload_id ) {
		return $this->dir . '/uploads/' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $upload_id ) . '.part';
	}

	/**
	 * A fresh spool file name for a request body.
	 *
	 * @return string
	 */
	public function spoolPath() {
		return $this->dir . '/spool/' . bin2hex( random_bytes( 8 ) ) . '.tmp';
	}

	/**
	 * Deletes every blob and upload part (reset).
	 *
	 * @return void
	 */
	public function wipeBlobs() {
		foreach ( array_merge( (array) glob( $this->dir . '/blobs/*' ), (array) glob( $this->dir . '/uploads/*' ) ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				unlink( $file );
			}
		}
	}
}

/**
 * Drive `fields` parameter: parse, validate against the resource schema, apply.
 */
final class FieldMask {

	/**
	 * Parses "a,b/c,d(e,f),*" into a tree (name => true | subtree).
	 *
	 * @param string $spec Spec.
	 * @return array
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	public static function parse( $spec ) {
		$pos  = 0;
		$tree = self::parseList( $spec, $pos );
		if ( $pos !== strlen( $spec ) ) {
			throw new \InvalidArgumentException( 'Invalid field selection ' . $spec );
		}
		return $tree;
	}

	/**
	 * Parses a comma-separated selection list.
	 *
	 * @param string $s    Spec.
	 * @param int    $pos  Cursor.
	 * @return array
	 * @throws \InvalidArgumentException On a syntax error.
	 */
	private static function parseList( $s, &$pos ) {
		$tree = array();
		$n    = strlen( $s );
		while ( true ) {
			$path = array();
			while ( true ) {
				if ( ! preg_match( '/\G\s*([A-Za-z0-9_]+|\*)\s*/', $s, $m, 0, $pos ) ) {
					throw new \InvalidArgumentException( 'Invalid field selection ' . $s );
				}
				$path[] = $m[1];
				$pos   += strlen( $m[0] );
				if ( $pos < $n && '/' === $s[ $pos ] ) {
					++$pos;
					continue;
				}
				break;
			}
			$node = true;
			if ( $pos < $n && '(' === $s[ $pos ] ) {
				++$pos;
				$node = self::parseList( $s, $pos );
				if ( $pos >= $n || ')' !== $s[ $pos ] ) {
					throw new \InvalidArgumentException( 'Invalid field selection ' . $s );
				}
				++$pos;
				if ( preg_match( '/\G\s+/', $s, $m, 0, $pos ) ) {
					$pos += strlen( $m[0] );
				}
			}
			for ( $i = count( $path ) - 1; $i >= 1; $i-- ) {
				$node = array( $path[ $i ] => $node );
			}
			$tree = self::merge( $tree, array( $path[0] => $node ) );
			if ( $pos < $n && ',' === $s[ $pos ] ) {
				++$pos;
				continue;
			}
			return $tree;
		}
	}

	/**
	 * Merges two selection trees.
	 *
	 * @param array|bool $a Tree.
	 * @param array|bool $b Tree.
	 * @return array|bool
	 */
	private static function merge( $a, $b ) {
		if ( true === $a || true === $b ) {
			return true;
		}
		foreach ( $b as $key => $value ) {
			$a[ $key ] = isset( $a[ $key ] ) ? self::merge( $a[ $key ], $value ) : $value;
		}
		return $a;
	}

	/**
	 * Rejects unknown field names, like Google ("Invalid field selection x").
	 *
	 * @param array  $tree   Tree.
	 * @param array  $schema name => true (leaf / not validated below) | nested schema.
	 * @param string $prefix Path prefix for the message.
	 * @return void
	 * @throws \InvalidArgumentException On an unknown field.
	 */
	public static function validate( array $tree, array $schema, $prefix = '' ) {
		foreach ( $tree as $key => $sub ) {
			if ( '*' === $key ) {
				continue;
			}
			if ( ! array_key_exists( $key, $schema ) ) {
				throw new \InvalidArgumentException( 'Invalid field selection ' . $prefix . $key );
			}
			if ( is_array( $sub ) && is_array( $schema[ $key ] ) ) {
				self::validate( $sub, $schema[ $key ], $prefix . $key . '/' );
			}
		}
	}

	/**
	 * Keeps only the selected parts of a resource.
	 *
	 * @param array|bool $tree Tree (true = everything).
	 * @param mixed      $data Resource.
	 * @return mixed
	 */
	public static function apply( $tree, $data ) {
		if ( true === $tree ) {
			return $data;
		}
		if ( $data instanceof \stdClass ) {
			$result = self::apply( $tree, get_object_vars( $data ) );
			return is_array( $result ) ? (object) $result : $result;
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( is_list( $data ) ) {
			$out = array();
			foreach ( $data as $item ) {
				$out[] = self::apply( $tree, $item );
			}
			return $out;
		}
		$out = isset( $tree['*'] ) ? $data : array();
		foreach ( $tree as $key => $sub ) {
			if ( '*' !== $key && array_key_exists( $key, $data ) ) {
				$out[ $key ] = self::apply( $sub, $data[ $key ] );
			}
		}
		return array() === $out ? new \stdClass() : $out;
	}

	/**
	 * Schema of a Drive User resource.
	 *
	 * @return array
	 */
	public static function userSchema() {
		return array_fill_keys( array( 'kind', 'displayName', 'photoLink', 'me', 'permissionId', 'emailAddress' ), true );
	}

	/**
	 * Schema of a Drive File resource (every documented top-level field, so a valid selection never fails).
	 *
	 * @return array
	 */
	public static function fileSchema() {
		$names  = array(
			'kind', 'driveId', 'fileExtension', 'copyRequiresWriterPermission', 'md5Checksum', 'contentHints', 'writersCanShare',
			'viewedByMe', 'mimeType', 'exportLinks', 'parents', 'thumbnailLink', 'iconLink', 'shared', 'lastModifyingUser', 'owners',
			'headRevisionId', 'sharingUser', 'webViewLink', 'webContentLink', 'size', 'viewersCanCopyContent', 'permissions',
			'hasThumbnail', 'spaces', 'folderColorRgb', 'id', 'name', 'description', 'starred', 'trashed', 'explicitlyTrashed',
			'createdTime', 'modifiedTime', 'modifiedByMeTime', 'viewedByMeTime', 'sharedWithMeTime', 'quotaBytesUsed', 'version',
			'originalFilename', 'ownedByMe', 'fullFileExtension', 'properties', 'appProperties', 'isAppAuthorized', 'teamDriveId',
			'capabilities', 'hasAugmentedPermissions', 'trashingUser', 'thumbnailVersion', 'trashedTime', 'modifiedByMe',
			'permissionIds', 'imageMediaMetadata', 'videoMediaMetadata', 'shortcutDetails', 'contentRestrictions', 'resourceKey',
			'linkShareMetadata', 'labelInfo', 'sha1Checksum', 'sha256Checksum', 'inheritedPermissionsDisabled',
			'downloadRestrictions', 'clientEncryptionDetails',
		);
		$schema = array_fill_keys( $names, true );
		foreach ( array( 'lastModifyingUser', 'owners', 'sharingUser', 'trashingUser' ) as $user_field ) {
			$schema[ $user_field ] = self::userSchema();
		}
		return $schema;
	}

	/**
	 * Schema of files.list.
	 *
	 * @return array
	 */
	public static function listSchema() {
		return array(
			'kind'             => true,
			'nextPageToken'    => true,
			'incompleteSearch' => true,
			'files'            => self::fileSchema(),
		);
	}

	/**
	 * Schema of about.get.
	 *
	 * @return array
	 */
	public static function aboutSchema() {
		$schema                 = array_fill_keys(
			array( 'kind', 'importFormats', 'exportFormats', 'maxImportSizes', 'maxUploadSize', 'appInstalled', 'folderColorPalette', 'driveThemes', 'canCreateDrives', 'canCreateTeamDrives', 'teamDriveThemes' ),
			true
		);
		$schema['user']         = self::userSchema();
		$schema['storageQuota'] = array_fill_keys( array( 'limit', 'usage', 'usageInDrive', 'usageInDriveTrash' ), true );
		return $schema;
	}

	/**
	 * Default selection of a File when no fields parameter is given (Drive v3 returns only these).
	 *
	 * @return array
	 */
	public static function defaultFileTree() {
		return array(
			'kind'     => true,
			'id'       => true,
			'name'     => true,
			'mimeType' => true,
		);
	}
}

/**
 * Parser for the files.list `q` language (the subset a Drive app uses).
 *
 * AST nodes: ['and', a, b], ['or', a, b], ['not', a], ['in', field, value], ['has', field, key, value],
 * ['cmp', field, op, value].
 */
final class DriveQuery {

	/**
	 * Tokens.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Cursor.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * Parses a query.
	 *
	 * @param string $q Query.
	 * @return array|null AST, null for an empty query (match all).
	 * @throws \InvalidArgumentException On a syntax error or an unsupported term.
	 */
	public static function parse( $q ) {
		$parser         = new self();
		$parser->tokens = self::tokenize( $q );
		if ( array() === $parser->tokens ) {
			return null;
		}
		$ast = $parser->parseOr();
		if ( $parser->pos < count( $parser->tokens ) ) {
			throw new \InvalidArgumentException( 'Unexpected token' );
		}
		return $ast;
	}

	/**
	 * Splits a query into tokens; strings honour \' and \\ escapes.
	 *
	 * @param string $q Query.
	 * @return array
	 * @throws \InvalidArgumentException On an invalid character or an unterminated string.
	 */
	private static function tokenize( $q ) {
		$tokens = array();
		$n      = strlen( $q );
		$i      = 0;
		while ( $i < $n ) {
			$c = $q[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( "'" === $c ) {
				++$i;
				$buf    = '';
				$closed = false;
				while ( $i < $n ) {
					$c = $q[ $i ];
					if ( '\\' === $c && $i + 1 < $n ) {
						$buf .= $q[ $i + 1 ];
						$i   += 2;
						continue;
					}
					++$i;
					if ( "'" === $c ) {
						$closed = true;
						break;
					}
					$buf .= $c;
				}
				if ( ! $closed ) {
					throw new \InvalidArgumentException( 'Unterminated string' );
				}
				$tokens[] = array( 'str', $buf );
				continue;
			}
			if ( false !== strpos( '(){}', $c ) ) {
				$tokens[] = array( $c, $c );
				++$i;
				continue;
			}
			$next = $i + 1 < $n ? $q[ $i + 1 ] : '';
			if ( '!' === $c && '=' === $next ) {
				$tokens[] = array( 'op', '!=' );
				$i       += 2;
				continue;
			}
			if ( '<' === $c || '>' === $c ) {
				$op       = '=' === $next ? $c . '=' : $c;
				$tokens[] = array( 'op', $op );
				$i       += strlen( $op );
				continue;
			}
			if ( '=' === $c ) {
				$tokens[] = array( 'op', '=' );
				++$i;
				continue;
			}
			if ( preg_match( '/\G[A-Za-z_][A-Za-z0-9_]*/', $q, $m, 0, $i ) ) {
				$tokens[] = array( 'word', $m[0] );
				$i       += strlen( $m[0] );
				continue;
			}
			throw new \InvalidArgumentException( 'Unexpected character' );
		}
		return $tokens;
	}

	/**
	 * Current token.
	 *
	 * @return array|null
	 */
	private function peek() {
		return isset( $this->tokens[ $this->pos ] ) ? $this->tokens[ $this->pos ] : null;
	}

	/**
	 * Whether the current token is a given keyword (case-insensitive).
	 *
	 * @param string $word Keyword.
	 * @return bool
	 */
	private function peekWord( $word ) {
		$t = $this->peek();
		return null !== $t && 'word' === $t[0] && 0 === strcasecmp( $t[1], $word );
	}

	/**
	 * Consumes a token of a type (and value).
	 *
	 * @param string      $type  Token type.
	 * @param string|null $value Expected value (keywords compare case-insensitively).
	 * @return string Token value.
	 * @throws \InvalidArgumentException When the token does not match.
	 */
	private function expect( $type, $value = null ) {
		$t = $this->peek();
		if ( null === $t || $t[0] !== $type ) {
			throw new \InvalidArgumentException( 'Expected ' . $type );
		}
		if ( null !== $value && ( 'word' === $type ? 0 !== strcasecmp( $t[1], $value ) : $t[1] !== $value ) ) {
			throw new \InvalidArgumentException( 'Expected ' . $value );
		}
		++$this->pos;
		return $t[1];
	}

	/**
	 * Expression: a or b (lowest precedence).
	 *
	 * @return array
	 */
	private function parseOr() {
		$left = $this->parseAnd();
		while ( $this->peekWord( 'or' ) ) {
			++$this->pos;
			$left = array( 'or', $left, $this->parseAnd() );
		}
		return $left;
	}

	/**
	 * Expression: a and b.
	 *
	 * @return array
	 */
	private function parseAnd() {
		$left = $this->parseNot();
		while ( $this->peekWord( 'and' ) ) {
			++$this->pos;
			$left = array( 'and', $left, $this->parseNot() );
		}
		return $left;
	}

	/**
	 * Expression: not a.
	 *
	 * @return array
	 */
	private function parseNot() {
		if ( $this->peekWord( 'not' ) ) {
			++$this->pos;
			return array( 'not', $this->parseNot() );
		}
		return $this->parsePrimary();
	}

	/**
	 * A parenthesised expression or one search term.
	 *
	 * @return array
	 * @throws \InvalidArgumentException On an invalid term.
	 */
	private function parsePrimary() {
		$t = $this->peek();
		if ( null === $t ) {
			throw new \InvalidArgumentException( 'Unexpected end of query' );
		}
		if ( '(' === $t[0] ) {
			++$this->pos;
			$inner = $this->parseOr();
			$this->expect( ')' );
			return $inner;
		}
		if ( 'str' === $t[0] ) {
			++$this->pos;
			$this->expect( 'word', 'in' );
			$field = $this->expect( 'word' );
			if ( ! in_array( $field, array( 'parents', 'owners', 'writers', 'readers' ), true ) ) {
				throw new \InvalidArgumentException( 'Unsupported collection ' . $field );
			}
			return array( 'in', $field, $t[1] );
		}
		if ( 'word' !== $t[0] ) {
			throw new \InvalidArgumentException( 'Unexpected token' );
		}
		++$this->pos;
		$field = $t[1];
		if ( 'appProperties' === $field || 'properties' === $field ) {
			$this->expect( 'word', 'has' );
			$this->expect( '{' );
			$this->expect( 'word', 'key' );
			$this->expect( 'op', '=' );
			$key = $this->expect( 'str' );
			$this->expect( 'word', 'and' );
			$this->expect( 'word', 'value' );
			$this->expect( 'op', '=' );
			$value = $this->expect( 'str' );
			$this->expect( '}' );
			return array( 'has', $field, $key, $value );
		}
		$ops = array(
			'name'           => array( '=', '!=', 'contains' ),
			'mimeType'       => array( '=', '!=', 'contains' ),
			'trashed'        => array( '=', '!=' ),
			'starred'        => array( '=', '!=' ),
			'createdTime'    => array( '=', '!=', '<', '<=', '>', '>=' ),
			'modifiedTime'   => array( '=', '!=', '<', '<=', '>', '>=' ),
			'viewedByMeTime' => array( '=', '!=', '<', '<=', '>', '>=' ),
		);
		if ( ! isset( $ops[ $field ] ) ) {
			throw new \InvalidArgumentException( 'Unsupported query term ' . $field );
		}
		$op_token = $this->peek();
		if ( null !== $op_token && 'op' === $op_token[0] ) {
			$op = $op_token[1];
			++$this->pos;
		} elseif ( $this->peekWord( 'contains' ) ) {
			$op = 'contains';
			++$this->pos;
		} else {
			throw new \InvalidArgumentException( 'Expected an operator' );
		}
		if ( ! in_array( $op, $ops[ $field ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid operator for ' . $field );
		}
		$value_token = $this->peek();
		if ( null === $value_token ) {
			throw new \InvalidArgumentException( 'Expected a value' );
		}
		++$this->pos;
		$boolean = in_array( $field, array( 'trashed', 'starred' ), true );
		if ( $boolean ) {
			if ( 'word' !== $value_token[0] || ! in_array( strtolower( $value_token[1] ), array( 'true', 'false' ), true ) ) {
				throw new \InvalidArgumentException( $field . ' needs true or false' );
			}
			return array( 'cmp', $field, $op, 'true' === strtolower( $value_token[1] ) );
		}
		if ( 'str' !== $value_token[0] ) {
			throw new \InvalidArgumentException( $field . ' needs a quoted string' );
		}
		if ( substr( $field, -4 ) === 'Time' && null === parse_time( $value_token[1] ) ) {
			throw new \InvalidArgumentException( 'Invalid date-time' );
		}
		return array( 'cmp', $field, $op, $value_token[1] );
	}
}

/**
 * The fake server: routing, OAuth, Drive, resumable uploads, control API.
 */
final class Server {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Captured request (method, path, query, query_string, headers, body, body_length, spool, form, base_url).
	 *
	 * @var array
	 */
	private $req;

	/**
	 * State (loaded under the lock).
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Fake clock (wall clock + clock_offset).
	 *
	 * @var float
	 */
	private $now;

	/**
	 * Whether a queued fault shaped this response (for the log).
	 *
	 * @var bool
	 */
	private $fault_applied = false;

	/**
	 * Free-form note for the request log.
	 *
	 * @var string
	 */
	private $note = '';

	/**
	 * Constructor.
	 *
	 * @param Store $store Store.
	 * @param array $req   Captured request.
	 */
	public function __construct( Store $store, array $req ) {
		$this->store = $store;
		$this->req   = $req;
	}

	/**
	 * Handles the request: lock, load, dispatch, log, save, unlock, send.
	 *
	 * @return void
	 */
	public function run() {
		$this->store->lock();
		$loaded      = $this->store->load();
		$this->state = null === $loaded ? $this->freshState() : $loaded;
		$this->now   = microtime( true ) + (float) $this->state['clock_offset'];
		try {
			$resp = $this->handle();
		} catch ( \Throwable $e ) {
			error_log( 'fake-google internal error: ' . $e ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- test tool.
			$resp = drive_error( 500, 'fake-google internal error: ' . $e->getMessage(), 'backendError' );
		}
		$this->log( $resp );
		$this->collectGarbage();
		$this->store->save( $this->state );
		$this->store->unlock();
		emit( $resp );
	}

	/**
	 * A brand-new state.
	 *
	 * @return array
	 */
	private function freshState() {
		return array(
			'version'        => 1,
			'config'         => default_config(),
			'controls'       => array(
				'omit_refresh_token'            => false,
				'deny_next_consent'             => false,
				'deny_drive_scope_next_consent' => false,
				'rotate_refresh_tokens'         => false,
				'chunk_commit_limit'            => null,
				'chunk_commit_times'            => 0,
			),
			'clock_offset'   => 0,
			'root_id'        => '0AFake' . random_token( 13, true ),
			'faults'         => array(),
			'grants'         => array(),
			'codes'          => array(),
			'refresh_tokens' => array(),
			'access_tokens'  => array(),
			'files'          => array(),
			'sessions'       => array(),
			'seq'            => 0,
			'log'            => array(),
		);
	}

	/**
	 * Applies the fault queue, then routes.
	 *
	 * @return array
	 */
	private function handle() {
		$method = $this->req['method'];
		$path   = $this->req['path'];
		if ( 0 === strpos( $path, '/__' ) ) {
			return $this->guard(
				function () use ( $method, $path ) {
					return $this->internal( $method, $path );
				}
			);
		}
		$fault = $this->takeFault( $method, $path );
		if ( null !== $fault && empty( $fault['process'] ) ) {
			return $this->faultResponse( $fault );
		}
		$resp = $this->guard(
			function () use ( $method, $path ) {
				return $this->route( $method, $path );
			}
		);
		if ( null === $fault ) {
			return $resp;
		}
		// "process": the request took effect (e.g. a chunk was committed) but the client never sees the answer.
		if ( is_array( $resp['stream'] ) ) {
			fclose( $resp['stream'][0] );
		}
		$this->note = 'processed as ' . $resp['status'];
		return $this->faultResponse( $fault );
	}

	/**
	 * Runs a handler and turns HttpError into its response.
	 *
	 * @param callable $handler Handler.
	 * @return array
	 */
	private function guard( callable $handler ) {
		try {
			return $handler();
		} catch ( HttpError $e ) {
			return $e->response();
		}
	}

	/**
	 * Routes a public (Google-like) request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @return array
	 */
	private function route( $method, $path ) {
		if ( '/o/oauth2/v2/auth' === $path || '/o/oauth2/auth' === $path ) {
			return 'GET' === $method ? $this->authorize() : $this->notFoundPage();
		}
		if ( '/token' === $path || '/o/oauth2/token' === $path ) {
			return 'POST' === $method ? $this->token() : oauth_error( 405, 'invalid_request', 'Method Not Allowed' );
		}
		if ( '/revoke' === $path || '/o/oauth2/revoke' === $path ) {
			return ( 'POST' === $method || 'GET' === $method ) ? $this->revoke() : oauth_error( 405, 'invalid_request', 'Method Not Allowed' );
		}
		if ( '/drive/v3/about' === $path && 'GET' === $method ) {
			return $this->about();
		}
		if ( '/drive/v3/files' === $path ) {
			if ( 'GET' === $method ) {
				return $this->listFiles();
			}
			if ( 'POST' === $method ) {
				return $this->createFile();
			}
		}
		if ( '/drive/v3/files/generateIds' === $path && 'GET' === $method ) {
			return $this->generateIds();
		}
		if ( preg_match( '#^/drive/v3/files/([^/]+)$#', $path, $m ) ) {
			$id = rawurldecode( $m[1] );
			if ( 'GET' === $method ) {
				return $this->getFile( $id );
			}
			if ( 'PATCH' === $method ) {
				return $this->updateFile( $id );
			}
			if ( 'DELETE' === $method ) {
				return $this->deleteFile( $id );
			}
		}
		if ( '/upload/drive/v3/files' === $path ) {
			if ( 'POST' === $method ) {
				return $this->startUpload();
			}
			if ( 'PUT' === $method ) {
				return $this->uploadChunk();
			}
			if ( 'DELETE' === $method ) {
				return $this->cancelUpload();
			}
		}
		if ( '/' === $path && 'GET' === $method ) {
			return text_response( 200, "SH Clone Migration fake Google server. See tests/fake-google/README.md.\n" );
		}
		return $this->notFoundPage();
	}

	/**
	 * Google's generic HTML 404 page.
	 *
	 * @return array
	 */
	private function notFoundPage() {
		return html_response( 404, "<!DOCTYPE html>\n<html lang=en><meta charset=utf-8><title>Error 404 (Not Found)!!1</title><p><b>404.</b> <ins>That's an error.</ins><p>The requested URL <code>" . htmlspecialchars( $this->req['path'] ) . "</code> was not found on this server. <ins>That's all we know.</ins>\n" );
	}

	/**
	 * A request parameter as a string ('' when missing or not a string).
	 *
	 * @param array  $source Parameters.
	 * @param string $name   Name.
	 * @return string
	 */
	private function param( array $source, $name ) {
		return ( isset( $source[ $name ] ) && is_string( $source[ $name ] ) ) ? $source[ $name ] : '';
	}

	/**
	 * A request header ('' when missing).
	 *
	 * @param string $name Lower-case name.
	 * @return string
	 */
	private function header( $name ) {
		return isset( $this->req['headers'][ $name ] ) ? trim( (string) $this->req['headers'][ $name ] ) : '';
	}

	/* ------------------------------------------------------------------ OAuth */

	/**
	 * The consent screen error page (Google shows these instead of redirecting).
	 *
	 * @param int      $status     HTTP status.
	 * @param string   $error      Error code.
	 * @param string   $message    Message.
	 * @param int|null $shown_code Code shown as "Error N:" (defaults to $status).
	 * @return array
	 */
	private function authPage( $status, $error, $message, $shown_code = null ) {
		$shown = null === $shown_code ? $status : $shown_code;
		$title = 'invalid_client' === $error ? 'Access blocked: Authorization Error' : 'Access blocked: This app&#8217;s request is invalid';
		$html  = "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>Sign in - Google Accounts</title></head><body>\n"
			. '<h1>' . $title . "</h1>\n<p>" . htmlspecialchars( $message ) . "</p>\n"
			. '<p>Error ' . (int) $shown . ': ' . htmlspecialchars( $error ) . "</p>\n</body></html>\n";
		return html_response( $status, $html );
	}

	/**
	 * GET /o/oauth2/v2/auth: validates the request and "consents" immediately.
	 *
	 * @return array
	 */
	private function authorize() {
		$q         = $this->req['query'];
		$config    = $this->state['config'];
		$client_id = $this->param( $q, 'client_id' );
		if ( '' === $client_id ) {
			return $this->authPage( 400, 'invalid_request', 'Missing required parameter: client_id' );
		}
		if ( $client_id !== $config['client_id'] ) {
			return $this->authPage( 400, 'invalid_client', 'The OAuth client was not found.', 401 );
		}
		$redirect_uri = $this->param( $q, 'redirect_uri' );
		if ( '' === $redirect_uri ) {
			return $this->authPage( 400, 'invalid_request', 'Missing required parameter: redirect_uri' );
		}
		$parts = parse_url( $redirect_uri );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) ) {
			return $this->authPage( 400, 'invalid_request', 'Invalid parameter value for redirect_uri: ' . $redirect_uri );
		}
		if ( ! empty( $config['redirect_uris'] ) && ! in_array( $redirect_uri, $config['redirect_uris'], true ) ) {
			return $this->authPage( 400, 'redirect_uri_mismatch', "You can't sign in to this app because it doesn't comply with Google's OAuth 2.0 policy. Request details: redirect_uri=" . $redirect_uri );
		}
		$response_type = $this->param( $q, 'response_type' );
		if ( '' === $response_type ) {
			return $this->authPage( 400, 'invalid_request', 'Missing required parameter: response_type' );
		}
		if ( 'code' !== $response_type ) {
			return $this->authPage( 400, 'unsupported_response_type', 'Invalid response_type: ' . $response_type );
		}
		$scope = trim( $this->param( $q, 'scope' ) );
		if ( '' === $scope ) {
			return $this->authPage( 400, 'invalid_request', 'Missing required parameter: scope' );
		}
		$scopes  = array_values( array_unique( preg_split( '/\s+/', $scope ) ) );
		$known   = array( DRIVE_FILE_SCOPE, 'openid', 'email', 'profile', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile' );
		$unknown = array_diff( $scopes, $known );
		if ( array() !== $unknown ) {
			return $this->authPage( 400, 'invalid_scope', 'Some requested scopes were invalid. {invalid=[' . implode( ', ', $unknown ) . ']}' );
		}
		if ( ! in_array( DRIVE_FILE_SCOPE, $scopes, true ) ) {
			return $this->authPage( 400, 'invalid_scope', 'This fake server only grants ' . DRIVE_FILE_SCOPE . ' and the request did not ask for it.' );
		}
		$access_type = $this->param( $q, 'access_type' );
		if ( ! in_array( $access_type, array( '', 'online', 'offline' ), true ) ) {
			return $this->authPage( 400, 'invalid_request', 'Invalid access_type: ' . $access_type );
		}
		$prompts = array_filter( preg_split( '/\s+/', trim( $this->param( $q, 'prompt' ) ) ) );
		foreach ( $prompts as $prompt ) {
			if ( ! in_array( $prompt, array( 'none', 'consent', 'select_account' ), true ) ) {
				return $this->authPage( 400, 'invalid_request', 'Invalid prompt: ' . $prompt );
			}
		}
		if ( in_array( 'none', $prompts, true ) && count( $prompts ) > 1 ) {
			return $this->authPage( 400, 'invalid_request', 'Invalid prompt: none cannot be combined with other values' );
		}
		$state = isset( $q['state'] ) && is_string( $q['state'] ) ? $q['state'] : null;

		$controls = &$this->state['controls'];
		if ( ! empty( $controls['deny_next_consent'] ) ) {
			$controls['deny_next_consent'] = false;
			$this->note                    = 'consent denied (control)';
			return $this->redirectBack( $redirect_uri, array( 'error' => 'access_denied' ), $state );
		}
		$grant     = isset( $this->state['grants'][ $client_id ] ) ? $this->state['grants'][ $client_id ] : null;
		$has_grant = null !== $grant && ! empty( $grant['active'] );
		if ( in_array( 'none', $prompts, true ) && ! $has_grant ) {
			return $this->redirectBack( $redirect_uri, array( 'error' => 'consent_required' ), $state );
		}
		$granted = $scopes;
		if ( ! empty( $controls['deny_drive_scope_next_consent'] ) ) {
			// Granular consent: the user unticked the Drive checkbox.
			$controls['deny_drive_scope_next_consent'] = false;
			$granted                                   = array_values( array_diff( $granted, array( DRIVE_FILE_SCOPE ) ) );
		}
		if ( $has_grant && 'true' === $this->param( $q, 'include_granted_scopes' ) ) {
			$granted = array_values( array_unique( array_merge( $granted, array_filter( explode( ' ', $grant['scope'] ) ) ) ) );
		}
		// Google shows the consent screen (and so hands out a refresh token) on the first authorization or with prompt=consent.
		$consent_shown = in_array( 'consent', $prompts, true ) || ! $has_grant;

		$this->state['grants'][ $client_id ] = array(
			'active'  => true,
			'scope'   => implode( ' ', array_values( array_unique( array_merge( $has_grant ? array_filter( explode( ' ', $grant['scope'] ) ) : array(), $granted ) ) ) ),
			'created' => $has_grant ? $grant['created'] : $this->now,
			'updated' => $this->now,
		);
		$code                               = '4/0fake-' . random_token( 43 );
		$this->state['codes'][ $code ]      = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'scope'         => implode( ' ', $granted ),
			'access_type'   => $access_type,
			'consent_shown' => $consent_shown,
			'created'       => $this->now,
			'used'          => false,
		);
		return $this->redirectBack(
			$redirect_uri,
			array(
				'iss'      => 'https://accounts.google.com',
				'code'     => $code,
				'scope'    => implode( ' ', $granted ),
				'authuser' => '0',
				'prompt'   => $consent_shown ? 'consent' : 'none',
			),
			$state
		);
	}

	/**
	 * 302 back to the client's redirect URI.
	 *
	 * @param string      $uri    Redirect URI (may carry a query already).
	 * @param array       $params Parameters to add.
	 * @param string|null $state  State to echo first.
	 * @return array
	 */
	private function redirectBack( $uri, array $params, $state ) {
		if ( null !== $state ) {
			$params = array_merge( array( 'state' => $state ), $params );
		}
		$location = $uri . ( false === strpos( $uri, '?' ) ? '?' : '&' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$html     = '<HTML><HEAD><meta http-equiv="content-type" content="text/html;charset=utf-8"><TITLE>302 Moved</TITLE></HEAD><BODY><H1>302 Moved</H1>The document has moved <A HREF="' . htmlspecialchars( $location ) . "\">here</A>.</BODY></HTML>\n";
		return html_response( 302, $html, array_merge( array( 'Location' => $location ), no_cache_headers() ) );
	}

	/**
	 * Client credentials from client_secret_post or client_secret_basic.
	 *
	 * @param array $form Form parameters.
	 * @return array [ client_id, client_secret ]
	 */
	private function clientCredentials( array $form ) {
		$id     = $this->param( $form, 'client_id' );
		$secret = $this->param( $form, 'client_secret' );
		if ( preg_match( '/^Basic\s+(\S+)$/i', $this->header( 'authorization' ), $m ) ) {
			$decoded = base64_decode( $m[1], true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $basic_id, $basic_secret ) = explode( ':', $decoded, 2 );
				$id                              = '' === $id ? urldecode( $basic_id ) : $id;
				$secret                          = '' === $secret ? urldecode( $basic_secret ) : $secret;
			}
		}
		return array( $id, $secret );
	}

	/**
	 * POST /token.
	 *
	 * @return array
	 */
	private function token() {
		$form       = $this->req['form'];
		$grant_type = $this->param( $form, 'grant_type' );
		if ( ! in_array( $grant_type, array( 'authorization_code', 'refresh_token' ), true ) ) {
			return oauth_error( 400, 'unsupported_grant_type', 'Invalid grant_type: ' . $grant_type );
		}
		list( $client_id, $client_secret ) = $this->clientCredentials( $form );
		$config                            = $this->state['config'];
		if ( '' === $client_id ) {
			return oauth_error( 400, 'invalid_request', 'Could not determine client ID from request.' );
		}
		if ( $client_id !== $config['client_id'] ) {
			return oauth_error( 401, 'invalid_client', 'The OAuth client was not found.' );
		}
		if ( '' === $client_secret ) {
			return oauth_error( 400, 'invalid_request', 'client_secret is missing.' );
		}
		if ( ! hash_equals( (string) $config['client_secret'], $client_secret ) ) {
			return oauth_error( 401, 'invalid_client', 'Unauthorized' );
		}
		$this->note = $grant_type;
		return 'authorization_code' === $grant_type ? $this->exchangeCode( $form, $client_id ) : $this->refreshGrant( $form, $client_id );
	}

	/**
	 * grant_type=authorization_code.
	 *
	 * @param array  $form      Form parameters.
	 * @param string $client_id Authenticated client.
	 * @return array
	 */
	private function exchangeCode( array $form, $client_id ) {
		$code = $this->param( $form, 'code' );
		if ( '' === $code ) {
			return oauth_error( 400, 'invalid_request', 'Missing required parameter: code' );
		}
		if ( ! isset( $this->state['codes'][ $code ] ) ) {
			return oauth_error( 400, 'invalid_grant', 0 === strpos( $code, '4/' ) ? 'Bad Request' : 'Malformed auth code.' );
		}
		$record = $this->state['codes'][ $code ];
		if ( ! empty( $record['used'] ) || $this->now - $record['created'] > CODE_LIFETIME || $record['client_id'] !== $client_id ) {
			return oauth_error( 400, 'invalid_grant', 'Bad Request' );
		}
		$redirect_uri = $this->param( $form, 'redirect_uri' );
		if ( '' === $redirect_uri ) {
			return oauth_error( 400, 'invalid_request', 'Missing parameter: redirect_uri' );
		}
		if ( $redirect_uri !== $record['redirect_uri'] ) {
			return oauth_error( 400, 'redirect_uri_mismatch', 'Bad Request' );
		}
		$this->state['codes'][ $code ]['used'] = true;
		if ( empty( $this->state['grants'][ $client_id ]['active'] ) ) {
			return oauth_error( 400, 'invalid_grant', 'Bad Request' );
		}
		$token = $this->issueAccessToken( $client_id, $record['scope'] );
		$body  = array(
			'access_token' => $token,
			'expires_in'   => (int) $this->state['config']['access_token_ttl'],
		);
		if ( 'offline' === $record['access_type'] && ! empty( $record['consent_shown'] ) && empty( $this->state['controls']['omit_refresh_token'] ) ) {
			$body['refresh_token'] = $this->issueRefreshToken( $client_id, $record['scope'] );
		}
		$body['scope']      = $record['scope'];
		$body['token_type'] = 'Bearer';
		return json_response( 200, $body, no_cache_headers() );
	}

	/**
	 * grant_type=refresh_token.
	 *
	 * @param array  $form      Form parameters.
	 * @param string $client_id Authenticated client.
	 * @return array
	 */
	private function refreshGrant( array $form, $client_id ) {
		$refresh = $this->param( $form, 'refresh_token' );
		if ( '' === $refresh ) {
			return oauth_error( 400, 'invalid_request', 'Missing required parameter: refresh_token' );
		}
		$record = isset( $this->state['refresh_tokens'][ $refresh ] ) ? $this->state['refresh_tokens'][ $refresh ] : null;
		if ( null !== $record && empty( $record['revoked'] ) ) {
			$last = null === $record['last_used'] ? $record['created'] : $record['last_used'];
			// Unused for six months, or a "Testing" app's seven-day grant lifetime: Google answers exactly like a revocation.
			if ( $this->now - $last > REFRESH_IDLE_LIMIT || ( 'testing' === $this->state['config']['publishing_status'] && $this->now - $record['created'] > TESTING_GRANT_LIFE ) ) {
				$this->state['refresh_tokens'][ $refresh ]['revoked'] = true;
				$record['revoked']                                    = true;
			}
		}
		if ( null === $record || ! empty( $record['revoked'] ) ) {
			return oauth_error( 400, 'invalid_grant', 'Token has been expired or revoked.' );
		}
		if ( $record['client_id'] !== $client_id ) {
			return oauth_error( 401, 'unauthorized_client', 'Unauthorized' );
		}
		$this->state['refresh_tokens'][ $refresh ]['last_used'] = $this->now;
		$body = array(
			'access_token' => $this->issueAccessToken( $client_id, $record['scope'] ),
			'expires_in'   => (int) $this->state['config']['access_token_ttl'],
		);
		if ( ! empty( $this->state['controls']['rotate_refresh_tokens'] ) ) {
			$body['refresh_token']                                = $this->issueRefreshToken( $client_id, $record['scope'] );
			$this->state['refresh_tokens'][ $refresh ]['revoked'] = true;
		}
		$body['scope']      = $record['scope'];
		$body['token_type'] = 'Bearer';
		return json_response( 200, $body, no_cache_headers() );
	}

	/**
	 * Mints an access token.
	 *
	 * @param string $client_id Client.
	 * @param string $scope     Granted scopes.
	 * @return string
	 */
	private function issueAccessToken( $client_id, $scope ) {
		$token                                  = 'ya29.fake-' . random_token( 64 );
		$this->state['access_tokens'][ $token ] = array(
			'client_id'  => $client_id,
			'scope'      => $scope,
			'created'    => $this->now,
			'expires_at' => $this->now + (int) $this->state['config']['access_token_ttl'],
			'revoked'    => false,
		);
		return $token;
	}

	/**
	 * Mints a refresh token; like Google, keeps at most 100 live ones per client and drops the oldest silently.
	 *
	 * @param string $client_id Client.
	 * @param string $scope     Granted scopes.
	 * @return string
	 */
	private function issueRefreshToken( $client_id, $scope ) {
		$token                                   = '1//fake-' . random_token( 60 );
		$this->state['refresh_tokens'][ $token ] = array(
			'client_id' => $client_id,
			'scope'     => $scope,
			'created'   => $this->now,
			'last_used' => null,
			'revoked'   => false,
		);
		$live = array();
		foreach ( $this->state['refresh_tokens'] as $key => $record ) {
			if ( $record['client_id'] === $client_id && empty( $record['revoked'] ) ) {
				$live[] = $key;
			}
		}
		while ( count( $live ) > MAX_REFRESH_TOKENS ) {
			$this->state['refresh_tokens'][ array_shift( $live ) ]['revoked'] = true;
		}
		return $token;
	}

	/**
	 * Revokes the whole authorization of a client: all its refresh and access tokens. Google revokes the project's
	 * grant, not a single token, so another site connected with the same client loses access too.
	 *
	 * @param string $client_id Client.
	 * @return void
	 */
	private function revokeGrant( $client_id ) {
		if ( isset( $this->state['grants'][ $client_id ] ) ) {
			$this->state['grants'][ $client_id ]['active'] = false;
		}
		foreach ( array( 'refresh_tokens', 'access_tokens' ) as $kind ) {
			foreach ( $this->state[ $kind ] as $key => $record ) {
				if ( $record['client_id'] === $client_id ) {
					$this->state[ $kind ][ $key ]['revoked'] = true;
				}
			}
		}
	}

	/**
	 * POST /revoke (token= in the query or the form body).
	 *
	 * @return array
	 */
	private function revoke() {
		$token = $this->param( $this->req['query'], 'token' );
		if ( '' === $token ) {
			$token = $this->param( $this->req['form'], 'token' );
		}
		if ( '' === $token ) {
			return oauth_error( 400, 'invalid_request', 'Missing required parameter: token' );
		}
		$client = null;
		if ( isset( $this->state['refresh_tokens'][ $token ] ) && empty( $this->state['refresh_tokens'][ $token ]['revoked'] ) ) {
			$client = $this->state['refresh_tokens'][ $token ]['client_id'];
		} elseif ( isset( $this->state['access_tokens'][ $token ] ) && empty( $this->state['access_tokens'][ $token ]['revoked'] ) && $this->now < $this->state['access_tokens'][ $token ]['expires_at'] ) {
			$client = $this->state['access_tokens'][ $token ]['client_id'];
		}
		if ( null === $client ) {
			return oauth_error( 400, 'invalid_token', 'Token expired or revoked' );
		}
		$this->revokeGrant( $client );
		return json_response( 200, new \stdClass(), no_cache_headers() );
	}

	/* ------------------------------------------------------------------ Drive: auth and helpers */

	/**
	 * 401 exactly as googleapis.com shapes it.
	 *
	 * @param bool   $missing True when no credential was sent at all.
	 * @param string $rpc     RPC method name for the ErrorInfo metadata.
	 * @return array
	 */
	private function unauthenticated( $missing, $rpc ) {
		$see = ' Expected OAuth 2 access token, login cookie or other valid authentication credential. See https://developers.google.com/identity/sign-in/web/devconsole-project.';
		return drive_error(
			401,
			$missing ? 'Request is missing required authentication credential.' . $see : 'Request had invalid authentication credentials.' . $see,
			$missing ? 'required' : 'authError',
			array(
				'item_message' => $missing ? 'Login Required.' : 'Invalid Credentials',
				'location'     => 'Authorization',
				'locationType' => 'header',
				'status'       => 'UNAUTHENTICATED',
				'details'      => array(
					array(
						'@type'    => 'type.googleapis.com/google.rpc.ErrorInfo',
						'reason'   => $missing ? 'CREDENTIALS_MISSING' : 'ACCESS_TOKEN_EXPIRED',
						'domain'   => 'googleapis.com',
						'metadata' => array(
							'service' => 'drive.googleapis.com',
							'method'  => $rpc,
						),
					),
				),
				'headers'      => array( 'WWW-Authenticate' => $missing ? 'Bearer realm="https://accounts.google.com/"' : 'Bearer realm="https://accounts.google.com/", error="invalid_token"' ),
			)
		);
	}

	/**
	 * Validates the bearer token; returns the client id it belongs to.
	 *
	 * @param string $rpc RPC method name (for error details).
	 * @return string
	 * @throws HttpError 401/403.
	 */
	private function requireToken( $rpc ) {
		$header = $this->header( 'authorization' );
		if ( '' === $header ) {
			throw new HttpError( $this->unauthenticated( true, $rpc ) );
		}
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', $header, $m ) ) {
			throw new HttpError( $this->unauthenticated( false, $rpc ) );
		}
		$record = isset( $this->state['access_tokens'][ $m[1] ] ) ? $this->state['access_tokens'][ $m[1] ] : null;
		if ( null === $record || ! empty( $record['revoked'] ) || $this->now >= $record['expires_at'] || $record['client_id'] !== $this->state['config']['client_id'] ) {
			throw new HttpError( $this->unauthenticated( false, $rpc ) );
		}
		if ( ! in_array( DRIVE_FILE_SCOPE, explode( ' ', $record['scope'] ), true ) ) {
			throw new HttpError(
				drive_error(
					403,
					'Request had insufficient authentication scopes.',
					'insufficientPermissions',
					array(
						'item_message' => 'Insufficient Permission',
						'status'       => 'PERMISSION_DENIED',
						'details'      => array(
							array(
								'@type'    => 'type.googleapis.com/google.rpc.ErrorInfo',
								'reason'   => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
								'domain'   => 'googleapis.com',
								'metadata' => array(
									'service' => 'drive.googleapis.com',
									'method'  => $rpc,
								),
							),
						),
					)
				)
			);
		}
		return $record['client_id'];
	}

	/**
	 * Parses and validates a fields parameter, or returns null when absent.
	 *
	 * @param array $schema Resource schema.
	 * @param bool  $required Whether the parameter is mandatory (about.get).
	 * @return array|bool|null
	 * @throws HttpError 400.
	 */
	private function fieldMask( array $schema, $required = false ) {
		$spec = trim( $this->param( $this->req['query'], 'fields' ) );
		if ( '' === $spec ) {
			if ( $required ) {
				$message = "The 'fields' parameter is required for this method.";
				throw new HttpError( drive_error( 400, $message, 'required', array( 'location' => 'fields' ) ) );
			}
			return null;
		}
		try {
			$tree = FieldMask::parse( $spec );
			FieldMask::validate( $tree, $schema );
		} catch ( \InvalidArgumentException $e ) {
			throw new HttpError( drive_error( 400, $e->getMessage(), 'invalidParameter', array( 'location' => 'fields' ) ) );
		}
		return $tree;
	}

	/**
	 * 400 "Invalid Value" for a parameter.
	 *
	 * @param string $location Parameter name.
	 * @return HttpError
	 */
	private function invalidValue( $location ) {
		return new HttpError( drive_error( 400, 'Invalid Value', 'invalid', array( 'location' => $location ) ) );
	}

	/**
	 * Decodes a JSON metadata body (empty body = {}).
	 *
	 * @return array
	 * @throws HttpError 400.
	 */
	private function jsonBody() {
		$body = $this->req['body'];
		if ( '' === trim( $body ) ) {
			return array();
		}
		// Stricter than strictly necessary on purpose: a client that forgets the JSON content type (WordPress sends
		// application/x-www-form-urlencoded by default) must fail here rather than only against Google.
		if ( false === stripos( $this->header( 'content-type' ), 'json' ) ) {
			throw new HttpError( drive_error( 400, 'Metadata must be sent with Content-Type: application/json (fake-google is strict here).', 'badContent' ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ( array() !== $data && is_list( $data ) ) ) {
			throw new HttpError( drive_error( 400, 'Parse Error', 'parseError' ) );
		}
		return $data;
	}

	/**
	 * Whether a file is trashed (itself or through an ancestor folder).
	 *
	 * @param array $file File record.
	 * @return bool
	 */
	private function isTrashed( array $file ) {
		for ( $depth = 0; $depth < 100; $depth++ ) {
			if ( ! empty( $file['explicitlyTrashed'] ) ) {
				return true;
			}
			$parent = isset( $file['parents'][0] ) ? $file['parents'][0] : null;
			if ( null === $parent || ! isset( $this->state['files'][ $parent ] ) ) {
				return false;
			}
			$file = $this->state['files'][ $parent ];
		}
		return false;
	}

	/**
	 * Resolves the 'root' alias.
	 *
	 * @param string $id Id or alias.
	 * @return string
	 */
	private function resolveId( $id ) {
		return 'root' === $id ? $this->state['root_id'] : $id;
	}

	/**
	 * The virtual My Drive root folder record.
	 *
	 * @return array
	 */
	private function rootRecord() {
		return array(
			'id'                => $this->state['root_id'],
			'name'              => 'My Drive',
			'mimeType'          => FOLDER_MIME,
			'parents'           => array(),
			'appProperties'     => array(),
			'properties'        => array(),
			'description'       => null,
			'starred'           => false,
			'originalFilename'  => null,
			'created'           => 1262304000.0,
			'modified'          => 1262304000.0,
			'explicitlyTrashed' => false,
			'trashed_at'        => null,
			'app'               => $this->state['config']['client_id'],
			'seq'               => 0,
			'version'           => 1,
			'size'              => null,
			'md5'               => null,
			'sha1'              => null,
			'sha256'            => null,
			'head'              => null,
		);
	}

	/**
	 * Finds a file the app can see (drive.file: only files created through this client).
	 *
	 * @param string $id         File id.
	 * @param string $client_id  Client.
	 * @param bool   $allow_root Whether the root folder may be returned.
	 * @return array
	 * @throws HttpError 404/403.
	 */
	private function findFile( $id, $client_id, $allow_root = true ) {
		$id = $this->resolveId( $id );
		if ( $id === $this->state['root_id'] ) {
			if ( ! $allow_root ) {
				throw new HttpError( drive_error( 403, 'The user does not have sufficient permissions for this file.', 'insufficientFilePermissions' ) );
			}
			return $this->rootRecord();
		}
		if ( ! isset( $this->state['files'][ $id ] ) || $this->state['files'][ $id ]['app'] !== $client_id ) {
			$message = 'File not found: ' . $id . '.';
			throw new HttpError( drive_error( 404, $message, 'notFound', array( 'location' => 'fileId' ) ) );
		}
		return $this->state['files'][ $id ];
	}

	/**
	 * The fake account as a Drive User resource.
	 *
	 * @return array
	 */
	private function user() {
		return array(
			'kind'         => 'drive#user',
			'displayName'  => $this->state['config']['name'],
			'photoLink'    => 'https://lh3.googleusercontent.com/a/fake-photo=s64',
			'me'           => true,
			'permissionId' => '01234567890123456789',
			'emailAddress' => $this->state['config']['email'],
		);
	}

	/**
	 * Storage usage: configured base usage plus every stored file (trash counts, as in Drive).
	 *
	 * @return array [ 'total' => int, 'trash' => int ]
	 */
	private function usage() {
		$total = (int) $this->state['config']['quota_usage'];
		$trash = 0;
		foreach ( $this->state['files'] as $file ) {
			$size   = (int) $file['size'];
			$total += $size;
			if ( $this->isTrashed( $file ) ) {
				$trash += $size;
			}
		}
		return array(
			'total' => $total,
			'trash' => $trash,
		);
	}

	/**
	 * 403 storageQuotaExceeded.
	 *
	 * @return array
	 */
	private function quotaExceeded() {
		return drive_error( 403, "The user's Drive storage quota has been exceeded.", 'storageQuotaExceeded' );
	}

	/**
	 * Full File resource of a record.
	 *
	 * @param array $f Record.
	 * @return array
	 */
	private function fileResource( array $f ) {
		$folder  = FOLDER_MIME === $f['mimeType'];
		$trashed = $this->isTrashed( $f );
		$owner   = $this->user();
		$r       = array(
			'kind'                         => 'drive#file',
			'id'                           => $f['id'],
			'name'                         => $f['name'],
			'mimeType'                     => $f['mimeType'],
			'starred'                      => ! empty( $f['starred'] ),
			'trashed'                      => $trashed,
			'explicitlyTrashed'            => ! empty( $f['explicitlyTrashed'] ),
			'parents'                      => array_values( $f['parents'] ),
			'properties'                   => (object) $f['properties'],
			'appProperties'                => (object) $f['appProperties'],
			'spaces'                       => array( 'drive' ),
			'version'                      => (string) $f['version'],
			'webViewLink'                  => $folder ? 'https://drive.google.com/drive/folders/' . $f['id'] : 'https://drive.google.com/file/d/' . $f['id'] . '/view?usp=drivesdk',
			'iconLink'                     => 'https://drive-thirdparty.googleusercontent.com/16/type/' . $f['mimeType'],
			'hasThumbnail'                 => false,
			'thumbnailVersion'             => '0',
			'viewedByMe'                   => true,
			'viewedByMeTime'               => rfc3339( $f['modified'] ),
			'createdTime'                  => rfc3339( $f['created'] ),
			'modifiedTime'                 => rfc3339( $f['modified'] ),
			'modifiedByMeTime'             => rfc3339( $f['modified'] ),
			'modifiedByMe'                 => true,
			'owners'                       => array( $owner ),
			'lastModifyingUser'            => $owner,
			'shared'                       => false,
			'ownedByMe'                    => true,
			'capabilities'                 => array(
				'canAddChildren'      => $folder,
				'canComment'          => true,
				'canCopy'             => ! $folder,
				'canDelete'           => true,
				'canDeleteChildren'   => $folder,
				'canDownload'         => ! $folder,
				'canEdit'             => true,
				'canListChildren'     => $folder,
				'canModifyContent'    => true,
				'canMoveItemWithinDrive' => true,
				'canReadRevisions'    => ! $folder,
				'canRemoveChildren'   => $folder,
				'canRename'           => true,
				'canShare'            => true,
				'canTrash'            => true,
				'canTrashChildren'    => $folder,
				'canUntrash'          => true,
			),
			'viewersCanCopyContent'        => true,
			'copyRequiresWriterPermission' => false,
			'writersCanShare'              => true,
			'permissionIds'                => array( '01234567890123456789' ),
			'isAppAuthorized'              => true,
			'quotaBytesUsed'               => (string) (int) $f['size'],
			'inheritedPermissionsDisabled' => false,
		);
		if ( null !== $f['description'] ) {
			$r['description'] = $f['description'];
		}
		if ( ! empty( $f['explicitlyTrashed'] ) ) {
			$r['trashedTime']  = rfc3339( (float) $f['trashed_at'] );
			$r['trashingUser'] = $owner;
		}
		if ( ! $folder ) {
			$r['webContentLink']   = 'https://drive.google.com/uc?id=' . $f['id'] . '&export=download';
			$r['originalFilename'] = null !== $f['originalFilename'] ? $f['originalFilename'] : $f['name'];
			if ( preg_match( '/\.([^.]+)$/', $f['name'], $m ) ) {
				$r['fileExtension']     = $m[1];
				$r['fullFileExtension'] = substr( $f['name'], (int) strpos( $f['name'], '.' ) + 1 );
			}
			$r['md5Checksum']    = $f['md5'];
			$r['sha1Checksum']   = $f['sha1'];
			$r['sha256Checksum'] = $f['sha256'];
			$r['size']           = (string) (int) $f['size'];
			$r['headRevisionId'] = $f['head'];
		}
		return $r;
	}

	/**
	 * JSON File response shaped by a fields tree (default fields when null).
	 *
	 * @param int             $status Status.
	 * @param array           $record Record.
	 * @param array|bool|null $tree   Fields tree.
	 * @return array
	 */
	private function fileResponse( $status, array $record, $tree ) {
		return json_response( $status, FieldMask::apply( null === $tree ? FieldMask::defaultFileTree() : $tree, $this->fileResource( $record ) ) );
	}

	/**
	 * Validates a property map; returns string => string. Null values are deletions (update) or ignored (create).
	 *
	 * @param mixed  $props    Decoded value.
	 * @param string $field    'appProperties' or 'properties'.
	 * @param array  $existing Existing map (update merges into it).
	 * @return array
	 * @throws HttpError 400.
	 */
	private function propertyMap( $props, $field, array $existing = array() ) {
		if ( null === $props ) {
			return $existing;
		}
		if ( ! is_array( $props ) || ( array() !== $props && is_list( $props ) ) ) {
			throw $this->invalidValue( $field );
		}
		foreach ( $props as $key => $value ) {
			$key = (string) $key;
			if ( null === $value ) {
				unset( $existing[ $key ] );
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				throw $this->invalidValue( $field );
			}
			$value = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			if ( '' === $key || strlen( $key ) + strlen( $value ) > 124 ) {
				$message = 'The property ' . $key . ' exceeds the maximum size of 124 bytes for a key and value (UTF-8).';
				throw new HttpError( drive_error( 400, $message, 'badRequest' ) );
			}
			$existing[ $key ] = $value;
		}
		if ( count( $existing ) > 30 ) {
			throw new HttpError( drive_error( 400, 'The limit of 30 ' . $field . ' per file and app has been exceeded.', 'badRequest' ) );
		}
		return $existing;
	}

	/**
	 * Resolves and checks a parent folder id.
	 *
	 * @param mixed  $parent    Parent id.
	 * @param string $client_id Client.
	 * @return string
	 * @throws HttpError 400/404.
	 */
	private function parentId( $parent, $client_id ) {
		if ( ! is_string( $parent ) || '' === $parent ) {
			throw $this->invalidValue( 'parents' );
		}
		$parent = $this->resolveId( $parent );
		if ( $parent === $this->state['root_id'] ) {
			return $parent;
		}
		if ( ! isset( $this->state['files'][ $parent ] ) || $this->state['files'][ $parent ]['app'] !== $client_id ) {
			$message = 'File not found: ' . $parent . '.';
			throw new HttpError( drive_error( 404, $message, 'notFound', array( 'location' => 'fileId' ) ) );
		}
		if ( FOLDER_MIME !== $this->state['files'][ $parent ]['mimeType'] ) {
			throw new HttpError( drive_error( 400, 'The specified parent is not a folder.', 'badRequest' ) );
		}
		return $parent;
	}

	/**
	 * Fields the client may never set.
	 *
	 * @return array
	 */
	private function readOnlyFields() {
		return array(
			'size', 'md5Checksum', 'sha1Checksum', 'sha256Checksum', 'webViewLink', 'webContentLink', 'iconLink', 'quotaBytesUsed',
			'version', 'headRevisionId', 'ownedByMe', 'owners', 'capabilities', 'isAppAuthorized', 'trashedTime', 'explicitlyTrashed',
			'lastModifyingUser', 'fullFileExtension', 'fileExtension', 'spaces', 'permissions', 'permissionIds', 'hasThumbnail',
			'thumbnailLink', 'thumbnailVersion', 'modifiedByMe', 'shared', 'sharingUser', 'teamDriveId', 'driveId', 'trashingUser',
		);
	}

	/**
	 * 403 fieldNotWritable.
	 *
	 * @param string $message Message.
	 * @return HttpError
	 */
	private function notWritable( $message = 'The resource body includes fields which are not directly writable.' ) {
		return new HttpError( drive_error( 403, $message, 'fieldNotWritable' ) );
	}

	/**
	 * Validates create metadata and builds a new file record (not stored).
	 *
	 * @param array       $meta         Decoded metadata.
	 * @param string      $client_id    Creating client.
	 * @param string|null $content_type Content type of the upload (X-Upload-Content-Type), if any.
	 * @return array
	 * @throws HttpError On invalid metadata.
	 */
	private function newFileRecord( array $meta, $client_id, $content_type ) {
		foreach ( $this->readOnlyFields() as $field ) {
			if ( array_key_exists( $field, $meta ) ) {
				throw $this->notWritable();
			}
		}
		$id = null;
		if ( isset( $meta['id'] ) ) {
			if ( ! is_string( $meta['id'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,160}$/', $meta['id'] ) ) {
				throw $this->invalidValue( 'id' );
			}
			if ( isset( $this->state['files'][ $meta['id'] ] ) || $meta['id'] === $this->state['root_id'] ) {
				throw new HttpError( drive_error( 409, 'A file already exists with the provided ID.', 'duplicate' ) );
			}
			$id = $meta['id'];
		}
		foreach ( array( 'name', 'mimeType', 'description', 'originalFilename' ) as $field ) {
			if ( isset( $meta[ $field ] ) && ! is_string( $meta[ $field ] ) ) {
				throw $this->invalidValue( $field );
			}
		}
		$parents = array( $this->state['root_id'] );
		if ( isset( $meta['parents'] ) ) {
			if ( ! is_array( $meta['parents'] ) || ! is_list( $meta['parents'] ) ) {
				throw $this->invalidValue( 'parents' );
			}
			if ( count( $meta['parents'] ) > 1 ) {
				throw new HttpError( drive_error( 403, 'Increasing the number of parents is not allowed.', 'cannotAddParent' ) );
			}
			if ( 1 === count( $meta['parents'] ) ) {
				$parents = array( $this->parentId( $meta['parents'][0], $client_id ) );
			}
		}
		$times = array();
		foreach ( array( 'createdTime', 'modifiedTime' ) as $field ) {
			$times[ $field ] = $this->now;
			if ( isset( $meta[ $field ] ) ) {
				$parsed = parse_time( $meta[ $field ] );
				if ( null === $parsed ) {
					throw $this->invalidValue( $field );
				}
				$times[ $field ] = $parsed;
			}
		}
		$mime = isset( $meta['mimeType'] ) && '' !== $meta['mimeType'] ? $meta['mimeType'] : ( ( null !== $content_type && '' !== $content_type ) ? $content_type : 'application/octet-stream' );
		return array(
			'id'                => null !== $id ? $id : '1fake' . random_token( 28 ),
			'name'              => isset( $meta['name'] ) && '' !== $meta['name'] ? $meta['name'] : 'Untitled',
			'mimeType'          => $mime,
			'parents'           => $parents,
			'appProperties'     => $this->propertyMap( isset( $meta['appProperties'] ) ? $meta['appProperties'] : array(), 'appProperties' ),
			'properties'        => $this->propertyMap( isset( $meta['properties'] ) ? $meta['properties'] : array(), 'properties' ),
			'description'       => isset( $meta['description'] ) ? $meta['description'] : null,
			'starred'           => ! empty( $meta['starred'] ),
			'originalFilename'  => isset( $meta['originalFilename'] ) ? $meta['originalFilename'] : null,
			'created'           => $times['createdTime'],
			'modified'          => $times['modifiedTime'],
			'explicitlyTrashed' => false,
			'trashed_at'        => null,
			'app'               => $client_id,
			'seq'               => ++$this->state['seq'],
			'version'           => 1,
			'size'              => null,
			'md5'               => null,
			'sha1'              => null,
			'sha256'            => null,
			'head'              => null,
		);
	}

	/**
	 * Fills size and checksums from a content file.
	 *
	 * @param array  $record Record.
	 * @param string $path   Content file.
	 * @return array
	 */
	private function withContent( array $record, $path ) {
		clearstatcache( true, $path );
		$record['size']   = (int) filesize( $path );
		$record['md5']    = hash_file( 'md5', $path );
		$record['sha1']   = hash_file( 'sha1', $path );
		$record['sha256'] = hash_file( 'sha256', $path );
		$record['head']   = '0B' . random_token( 40, true );
		return $record;
	}

	/* ------------------------------------------------------------------ Drive: endpoints */

	/**
	 * GET /drive/v3/about.
	 *
	 * @return array
	 */
	private function about() {
		$this->requireToken( 'google.apps.drive.v3.DriveAbout.Get' );
		$tree   = $this->fieldMask( FieldMask::aboutSchema(), true );
		$config = $this->state['config'];
		$usage  = $this->usage();
		$quota  = array();
		if ( null !== $config['quota_limit'] ) {
			$quota['limit'] = (string) $config['quota_limit'];
		}
		$quota['usage']             = (string) $usage['total'];
		$quota['usageInDrive']      = (string) $usage['total'];
		$quota['usageInDriveTrash'] = (string) $usage['trash'];
		$about                      = array(
			'kind'                => 'drive#about',
			'user'                => $this->user(),
			'storageQuota'        => $quota,
			'maxUploadSize'       => (string) $config['max_upload_size'],
			'appInstalled'        => false,
			'canCreateDrives'     => false,
			'canCreateTeamDrives' => false,
			'importFormats'       => new \stdClass(),
			'exportFormats'       => new \stdClass(),
			'maxImportSizes'      => new \stdClass(),
			'folderColorPalette'  => array(),
			'driveThemes'         => array(),
			'teamDriveThemes'     => array(),
		);
		return json_response( 200, FieldMask::apply( $tree, $about ) );
	}

	/**
	 * GET /drive/v3/files.
	 *
	 * @return array
	 */
	private function listFiles() {
		$client = $this->requireToken( 'google.apps.drive.v3.DriveFiles.List' );
		$q      = $this->req['query'];
		$tree   = $this->fieldMask( FieldMask::listSchema() );
		$query  = $this->param( $q, 'q' );
		try {
			$ast = DriveQuery::parse( $query );
		} catch ( \InvalidArgumentException $e ) {
			throw $this->invalidValue( 'q' );
		}
		$order_spec = $this->param( $q, 'orderBy' );
		$order      = $this->parseOrderBy( $order_spec );
		$size_param = trim( $this->param( $q, 'pageSize' ) );
		$page_size  = 100;
		if ( '' !== $size_param ) {
			if ( ! ctype_digit( $size_param ) || (int) $size_param < 1 ) {
				throw $this->invalidValue( 'pageSize' );
			}
			$page_size = min( 1000, (int) $size_param );
		}
		$matches = array();
		foreach ( $this->state['files'] as $file ) {
			if ( $file['app'] === $client && ( null === $ast || $this->evaluate( $ast, $file ) ) ) {
				$matches[] = $file;
			}
		}
		usort(
			$matches,
			function ( $a, $b ) use ( $order ) {
				foreach ( $order as $key ) {
					$c = $this->compareBy( $key[0], $a, $b );
					if ( 0 !== $c ) {
						return $key[1] ? -$c : $c;
					}
				}
				return $a['seq'] <=> $b['seq'];
			}
		);
		$hash   = substr( md5( $query . '|' . $order_spec . '|' . $client ), 0, 16 );
		$offset = 0;
		$token  = $this->param( $q, 'pageToken' );
		if ( '' !== $token ) {
			$data = json_decode( (string) base64_decode( strtr( $token, '-_', '+/' ), true ), true );
			if ( ! is_array( $data ) || ! isset( $data['h'], $data['o'] ) || $data['h'] !== $hash || ! is_int( $data['o'] ) || $data['o'] < 0 ) {
				throw $this->invalidValue( 'pageToken' );
			}
			$offset = $data['o'];
		}
		$out = array(
			'kind'             => 'drive#fileList',
			'incompleteSearch' => false,
		);
		if ( $offset + $page_size < count( $matches ) ) {
			$out['nextPageToken'] = b64url(
				(string) json_encode(
					array(
						'o' => $offset + $page_size,
						'h' => $hash,
					)
				)
			);
		}
		$out['files'] = array();
		foreach ( array_slice( $matches, $offset, $page_size ) as $file ) {
			$out['files'][] = $this->fileResource( $file );
		}
		if ( null === $tree ) {
			$tree = array(
				'kind'             => true,
				'incompleteSearch' => true,
				'nextPageToken'    => true,
				'files'            => FieldMask::defaultFileTree(),
			);
		}
		$this->note = count( $matches ) . ' match(es)';
		return json_response( 200, FieldMask::apply( $tree, $out ) );
	}

	/**
	 * Parses orderBy ("createdTime desc,name").
	 *
	 * @param string $spec Spec.
	 * @return array List of [ key, desc ].
	 * @throws HttpError 400.
	 */
	private function parseOrderBy( $spec ) {
		if ( '' === trim( $spec ) ) {
			return array( array( 'folder', false ), array( 'modifiedTime', true ), array( 'name', false ) );
		}
		$valid = array( 'createdTime', 'folder', 'modifiedByMeTime', 'modifiedTime', 'name', 'name_natural', 'quotaBytesUsed', 'recency', 'sharedWithMeTime', 'starred', 'viewedByMeTime' );
		$keys  = array();
		foreach ( explode( ',', $spec ) as $part ) {
			$bits = preg_split( '/\s+/', trim( $part ) );
			if ( ! in_array( $bits[0], $valid, true ) || count( $bits ) > 2 || ( isset( $bits[1] ) && 'desc' !== $bits[1] ) ) {
				throw $this->invalidValue( 'orderBy' );
			}
			$keys[] = array( $bits[0], isset( $bits[1] ) );
		}
		return $keys;
	}

	/**
	 * Compares two records by one orderBy key (ascending).
	 *
	 * @param string $key Key.
	 * @param array  $a   Record.
	 * @param array  $b   Record.
	 * @return int
	 */
	private function compareBy( $key, array $a, array $b ) {
		switch ( $key ) {
			case 'createdTime':
				return $a['created'] <=> $b['created'];
			case 'name':
				return strcasecmp( $a['name'], $b['name'] ) <=> 0;
			case 'name_natural':
				return strnatcasecmp( $a['name'], $b['name'] ) <=> 0;
			case 'folder':
				return ( FOLDER_MIME === $b['mimeType'] ) <=> ( FOLDER_MIME === $a['mimeType'] );
			case 'quotaBytesUsed':
				return (int) $a['size'] <=> (int) $b['size'];
			case 'starred':
				return (int) ! empty( $b['starred'] ) <=> (int) ! empty( $a['starred'] );
			case 'sharedWithMeTime':
				return 0;
			default:
				return $a['modified'] <=> $b['modified'];
		}
	}

	/**
	 * Evaluates a query AST against a record.
	 *
	 * @param array $ast  AST.
	 * @param array $file Record.
	 * @return bool
	 */
	private function evaluate( array $ast, array $file ) {
		switch ( $ast[0] ) {
			case 'and':
				return $this->evaluate( $ast[1], $file ) && $this->evaluate( $ast[2], $file );
			case 'or':
				return $this->evaluate( $ast[1], $file ) || $this->evaluate( $ast[2], $file );
			case 'not':
				return ! $this->evaluate( $ast[1], $file );
			case 'in':
				if ( 'parents' === $ast[1] ) {
					return in_array( $this->resolveId( $ast[2] ), $file['parents'], true );
				}
				// owners/writers/readers: the single fake account owns everything the app can see.
				return 'me' === $ast[2] || 0 === strcasecmp( $ast[2], $this->state['config']['email'] );
			case 'has':
				$map = $file[ $ast[1] ];
				return array_key_exists( $ast[2], $map ) && (string) $map[ $ast[2] ] === $ast[3];
		}
		list( , $field, $op, $value ) = $ast;
		if ( 'trashed' === $field || 'starred' === $field ) {
			$actual = 'trashed' === $field ? $this->isTrashed( $file ) : ! empty( $file['starred'] );
			return '=' === $op ? $actual === $value : $actual !== $value;
		}
		if ( 'name' === $field || 'mimeType' === $field ) {
			$actual = (string) $file[ $field ];
			if ( '=' === $op ) {
				return $actual === $value;
			}
			if ( '!=' === $op ) {
				return $actual !== $value;
			}
			if ( 'mimeType' === $field ) {
				return false !== stripos( $actual, $value );
			}
			// Drive's "name contains" is a prefix match on the name or on one of its words.
			$words = preg_split( '/[^\p{L}\p{N}]+/u', $actual );
			foreach ( array_merge( array( $actual ), false === $words ? array() : $words ) as $word ) {
				if ( '' !== $value && 0 === stripos( $word, $value ) ) {
					return true;
				}
			}
			return false;
		}
		$actual = 'createdTime' === $field ? $file['created'] : $file['modified'];
		$value  = parse_time( $value );
		switch ( $op ) {
			case '=':
				return abs( $actual - $value ) < 0.0005;
			case '!=':
				return abs( $actual - $value ) >= 0.0005;
			case '<':
				return $actual < $value;
			case '<=':
				return $actual <= $value;
			case '>':
				return $actual > $value;
			default:
				return $actual >= $value;
		}
	}

	/**
	 * POST /drive/v3/files (metadata only: folders, or empty files).
	 *
	 * @return array
	 */
	private function createFile() {
		$client = $this->requireToken( 'google.apps.drive.v3.DriveFiles.Create' );
		$tree   = $this->fieldMask( FieldMask::fileSchema() );
		$record = $this->newFileRecord( $this->jsonBody(), $client, null );
		if ( FOLDER_MIME !== $record['mimeType'] ) {
			$blob = $this->store->blobPath( $record['id'] );
			file_put_contents( $blob, '' );
			$record = $this->withContent( $record, $blob );
		}
		$this->state['files'][ $record['id'] ] = $record;
		return $this->fileResponse( 200, $record, $tree );
	}

	/**
	 * GET /drive/v3/files/generateIds.
	 *
	 * @return array
	 */
	private function generateIds() {
		$this->requireToken( 'google.apps.drive.v3.DriveFiles.GenerateIds' );
		$count = trim( $this->param( $this->req['query'], 'count' ) );
		$count = '' === $count ? 10 : $count;
		if ( ! ctype_digit( (string) $count ) || (int) $count < 1 || (int) $count > 1000 ) {
			throw $this->invalidValue( 'count' );
		}
		$ids = array();
		for ( $i = 0; $i < (int) $count; $i++ ) {
			$ids[] = '1fake' . random_token( 28 );
		}
		$space = $this->param( $this->req['query'], 'space' );
		return json_response(
			200,
			array(
				'kind'  => 'drive#generatedIds',
				'space' => '' === $space ? 'drive' : $space,
				'ids'   => $ids,
			)
		);
	}

	/**
	 * GET /drive/v3/files/{id} (metadata, or content with alt=media).
	 *
	 * @param string $id File id.
	 * @return array
	 */
	private function getFile( $id ) {
		$client = $this->requireToken( 'google.apps.drive.v3.DriveFiles.Get' );
		$record = $this->findFile( $id, $client );
		$alt    = $this->param( $this->req['query'], 'alt' );
		if ( 'media' === $alt ) {
			return $this->download( $record );
		}
		if ( '' !== $alt && 'json' !== $alt ) {
			throw $this->invalidValue( 'alt' );
		}
		return $this->fileResponse( 200, $record, $this->fieldMask( FieldMask::fileSchema() ) );
	}

	/**
	 * alt=media download with single-range support.
	 *
	 * @param array $record Record.
	 * @return array
	 * @throws HttpError 403 for folders.
	 */
	private function download( array $record ) {
		if ( FOLDER_MIME === $record['mimeType'] ) {
			throw new HttpError( drive_error( 403, 'Only files with binary content can be downloaded. Use Export with Docs Editors files.', 'fileNotDownloadable', array( 'location' => 'alt' ) ) );
		}
		$size   = (int) $record['size'];
		$handle = fopen( $this->store->blobPath( $record['id'] ), 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Blob missing for ' . $record['id'] );
		}
		$start   = 0;
		$end     = $size - 1;
		$status  = 200;
		$headers = array( 'Content-Type' => $record['mimeType'] );
		$range   = $this->header( 'range' );
		if ( '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) && ( '' === $m[1] || '' === $m[2] || (int) $m[2] >= (int) $m[1] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
			}
			if ( $start >= $size || ( '' === $m[1] && 0 === (int) $m[2] ) ) {
				fclose( $handle );
				return text_response( 416, 'Request range not satisfiable', array( 'Content-Range' => 'bytes */' . $size ) );
			}
			$status                   = 206;
			$headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
		}
		$resp           = response( $status, '', $headers );
		$resp['stream'] = array( $handle, $start, $size > 0 ? $end - $start + 1 : 0 );
		return $resp;
	}

	/**
	 * PATCH /drive/v3/files/{id}.
	 *
	 * @param string $id File id.
	 * @return array
	 */
	private function updateFile( $id ) {
		$client = $this->requireToken( 'google.apps.drive.v3.DriveFiles.Update' );
		$tree   = $this->fieldMask( FieldMask::fileSchema() );
		$record = $this->findFile( $id, $client, false );
		$meta   = $this->jsonBody();
		if ( array_key_exists( 'parents', $meta ) ) {
			throw $this->notWritable( 'The parents field is not directly writable in update requests. Use the addParents and removeParents parameters instead.' );
		}
		foreach ( array_merge( $this->readOnlyFields(), array( 'id', 'createdTime' ) ) as $field ) {
			if ( array_key_exists( $field, $meta ) && ! ( 'id' === $field && $meta['id'] === $record['id'] ) ) {
				throw $this->notWritable();
			}
		}
		$touched = false;
		foreach ( array( 'name', 'description', 'originalFilename', 'mimeType' ) as $field ) {
			if ( array_key_exists( $field, $meta ) ) {
				if ( ! is_string( $meta[ $field ] ) && ! ( null === $meta[ $field ] && 'description' === $field ) ) {
					throw $this->invalidValue( $field );
				}
				if ( 'mimeType' === $field && ( FOLDER_MIME === $meta[ $field ] ) !== ( FOLDER_MIME === $record['mimeType'] ) ) {
					throw $this->notWritable( 'A file cannot be converted to a folder or back.' );
				}
				$record[ $field ] = $meta[ $field ];
				$touched          = true;
			}
		}
		if ( array_key_exists( 'starred', $meta ) ) {
			$record['starred'] = (bool) $meta['starred'];
		}
		if ( array_key_exists( 'trashed', $meta ) ) {
			if ( ! is_bool( $meta['trashed'] ) ) {
				throw $this->invalidValue( 'trashed' );
			}
			$record['explicitlyTrashed'] = $meta['trashed'];
			$record['trashed_at']        = $meta['trashed'] ? $this->now : null;
		}
		foreach ( array( 'appProperties', 'properties' ) as $field ) {
			if ( array_key_exists( $field, $meta ) ) {
				$record[ $field ] = $this->propertyMap( $meta[ $field ], $field, $record[ $field ] );
				$touched          = true;
			}
		}
		$add    = array_filter( explode( ',', $this->param( $this->req['query'], 'addParents' ) ) );
		$remove = array_filter( explode( ',', $this->param( $this->req['query'], 'removeParents' ) ) );
		if ( array() !== $add || array() !== $remove ) {
			$parents = array_values( array_diff( $record['parents'], array_map( array( $this, 'resolveId' ), $remove ) ) );
			foreach ( $add as $parent ) {
				$parent = $this->parentId( trim( $parent ), $client );
				if ( $parent === $record['id'] || $this->isDescendant( $parent, $record['id'] ) ) {
					throw new HttpError( drive_error( 403, 'A folder cannot be moved into itself or one of its descendants.', 'cannotMoveFolderIntoDescendant' ) );
				}
				$parents[] = $parent;
			}
			$parents = array_values( array_unique( $parents ) );
			if ( count( $parents ) > 1 ) {
				throw new HttpError( drive_error( 403, 'Increasing the number of parents is not allowed.', 'cannotAddParent' ) );
			}
			$record['parents'] = array() === $parents ? array( $this->state['root_id'] ) : $parents;
			$touched           = true;
		}
		if ( array_key_exists( 'modifiedTime', $meta ) ) {
			$parsed = parse_time( $meta['modifiedTime'] );
			if ( null === $parsed ) {
				throw $this->invalidValue( 'modifiedTime' );
			}
			$record['modified'] = $parsed;
		} elseif ( $touched ) {
			$record['modified'] = $this->now;
		}
		++$record['version'];
		$this->state['files'][ $record['id'] ] = $record;
		return $this->fileResponse( 200, $record, $tree );
	}

	/**
	 * Whether $id is inside folder $ancestor.
	 *
	 * @param string $id       Candidate.
	 * @param string $ancestor Folder.
	 * @return bool
	 */
	private function isDescendant( $id, $ancestor ) {
		for ( $depth = 0; $depth < 100 && isset( $this->state['files'][ $id ] ); $depth++ ) {
			$parent = isset( $this->state['files'][ $id ]['parents'][0] ) ? $this->state['files'][ $id ]['parents'][0] : null;
			if ( $parent === $ancestor ) {
				return true;
			}
			$id = $parent;
		}
		return false;
	}

	/**
	 * DELETE /drive/v3/files/{id}: permanent, recursive for folders, 204 with no body.
	 *
	 * @param string $id File id.
	 * @return array
	 */
	private function deleteFile( $id ) {
		$client = $this->requireToken( 'google.apps.drive.v3.DriveFiles.Delete' );
		$record = $this->findFile( $id, $client, false );
		$this->removeTree( $record['id'] );
		return response( 204 );
	}

	/**
	 * Deletes a file and everything below it.
	 *
	 * @param string $id File id.
	 * @return void
	 */
	private function removeTree( $id ) {
		foreach ( $this->state['files'] as $child ) {
			if ( in_array( $id, $child['parents'], true ) ) {
				$this->removeTree( $child['id'] );
			}
		}
		$blob = $this->store->blobPath( $id );
		if ( is_file( $blob ) ) {
			unlink( $blob );
		}
		unset( $this->state['files'][ $id ] );
	}

	/* ------------------------------------------------------------------ Resumable uploads */

	/**
	 * POST /upload/drive/v3/files?uploadType=resumable.
	 *
	 * @return array
	 */
	private function startUpload() {
		$client = $this->requireToken( 'google.apps.drive.v3.DriveFiles.Create' );
		$type   = $this->param( $this->req['query'], 'uploadType' );
		if ( 'resumable' !== $type ) {
			throw new HttpError( drive_error( 400, "fake-google only emulates uploadType=resumable (got '" . $type . "').", 'badRequest', array( 'location' => 'uploadType' ) ) );
		}
		$this->fieldMask( FieldMask::fileSchema() );
		$meta = $this->jsonBody();
		// Validate now (Google rejects bad metadata at initiation); the record is rebuilt at finalization.
		$this->newFileRecord( $meta, $client, null );
		--$this->state['seq'];
		$declared = $this->header( 'x-upload-content-length' );
		if ( '' !== $declared ) {
			if ( ! ctype_digit( $declared ) ) {
				throw new HttpError( drive_error( 400, 'Invalid X-Upload-Content-Length header.', 'badRequest' ) );
			}
			$declared = (int) $declared;
		} else {
			$declared = null;
		}
		$limit = $this->state['config']['quota_limit'];
		$used  = $this->usage()['total'];
		if ( null !== $limit && ( $used >= $limit || ( null !== $declared && $used + $declared > $limit ) ) ) {
			return $this->quotaExceeded();
		}
		$upload_id                             = 'AFake' . random_token( 72 );
		$this->state['sessions'][ $upload_id ] = array(
			'meta'         => $meta,
			'content_type' => '' === $this->header( 'x-upload-content-type' ) ? null : $this->header( 'x-upload-content-type' ),
			'name'         => isset( $meta['name'] ) && is_string( $meta['name'] ) ? $meta['name'] : 'Untitled',
			'total'        => $declared,
			'committed'    => 0,
			'created'      => $this->now,
			'updated'      => $this->now,
			'app'          => $client,
			'file_id'      => null,
			'expired'      => false,
		);
		file_put_contents( $this->store->partPath( $upload_id ), '' );
		$query    = $this->req['query_string'];
		$location = $this->req['base_url'] . '/upload/drive/v3/files?' . ( '' === $query ? '' : $query . '&' ) . 'upload_id=' . rawurlencode( $upload_id );
		return response(
			200,
			'',
			array(
				'Location'             => $location,
				'X-GUploader-UploadID' => $upload_id,
			)
		);
	}

	/**
	 * The session of the current PUT/DELETE, or null when unknown or expired.
	 *
	 * @return string|null Session id.
	 */
	private function sessionId() {
		$id = $this->param( $this->req['query'], 'upload_id' );
		if ( '' === $id || ! isset( $this->state['sessions'][ $id ] ) ) {
			return null;
		}
		$session = $this->state['sessions'][ $id ];
		if ( ! empty( $session['expired'] ) || $this->now - $session['created'] > SESSION_LIFETIME ) {
			return null;
		}
		return $id;
	}

	/**
	 * The plain-text 404 Google's upload front end sends for unknown or expired sessions.
	 *
	 * @return array
	 */
	private function sessionNotFound() {
		return text_response( 404, 'Not Found' );
	}

	/**
	 * 308 Resume Incomplete with the committed range (no Range header when nothing is committed).
	 *
	 * @param string $id Session id.
	 * @return array
	 */
	private function resumeIncomplete( $id ) {
		$committed = (int) $this->state['sessions'][ $id ]['committed'];
		$headers   = array( 'X-GUploader-UploadID' => $id );
		if ( $committed > 0 ) {
			$headers['Range'] = 'bytes=0-' . ( $committed - 1 );
		}
		return response( 308, '', $headers, 'Resume Incomplete' );
	}

	/**
	 * PUT <session URI>: chunk upload or status query. No Authorization needed: the session URI is the credential.
	 *
	 * @return array
	 */
	private function uploadChunk() {
		$id = $this->sessionId();
		if ( null === $id ) {
			return $this->sessionNotFound();
		}
		$session = &$this->state['sessions'][ $id ];
		if ( null !== $session['file_id'] ) {
			// Completed already (e.g. the client lost the final answer): Google repeats the file resource.
			if ( ! isset( $this->state['files'][ $session['file_id'] ] ) ) {
				return $this->sessionNotFound();
			}
			return $this->fileResponse( 200, $this->state['files'][ $session['file_id'] ], $this->fieldMask( FieldMask::fileSchema() ) );
		}
		$length = (int) $this->req['body_length'];
		$range  = $this->header( 'content-range' );
		$query  = false;
		if ( '' === $range ) {
			// No Content-Range: the body is the whole file.
			$start = 0;
			$end   = $length - 1;
			$total = $length;
		} elseif ( preg_match( '#^bytes\s+\*/(\d+|\*)$#i', $range, $m ) ) {
			$query = true;
			$total = '*' === $m[1] ? null : (int) $m[1];
		} elseif ( preg_match( '#^bytes\s+(\d+)-(\d+)/(\d+|\*)$#i', $range, $m ) ) {
			$start = (int) $m[1];
			$end   = (int) $m[2];
			$total = '*' === $m[3] ? null : (int) $m[3];
			if ( $end < $start || ( null !== $total && $end >= $total ) ) {
				return text_response( 400, 'Failed to parse Content-Range header.' );
			}
		} else {
			return text_response( 400, 'Failed to parse Content-Range header.' );
		}
		if ( null !== $total && null !== $session['total'] && $total !== (int) $session['total'] ) {
			return text_response( 400, 'Invalid request.  The total size in the Content-Range header (' . $total . ') does not match the size of this upload (' . $session['total'] . ').' );
		}
		// The total becomes fixed only once a request is accepted; a rejected chunk must not change the session.
		$known_total = null !== $session['total'] ? (int) $session['total'] : $total;
		if ( $query ) {
			$this->note = 'status query';
			if ( $length > 0 ) {
				return text_response( 400, 'Invalid request.  A status query (Content-Range: bytes */N) must have an empty body.' );
			}
			$session['total'] = $known_total;
			if ( null !== $known_total && (int) $session['committed'] === $known_total ) {
				return $this->finalizeUpload( $id );
			}
			return $this->resumeIncomplete( $id );
		}
		$chunk = $end - $start + 1;
		if ( $length !== $chunk ) {
			return text_response( 400, 'Invalid request.  The request body has ' . $length . ' byte(s) but the Content-Range header describes ' . $chunk . ' byte(s).' );
		}
		$final = null !== $known_total && $end + 1 === $known_total;
		if ( ! $final && 0 !== $chunk % CHUNK_GRANULARITY ) {
			return text_response( 400, 'Invalid request.  The chunk size (' . $chunk . ' bytes) must be a multiple of 262144 bytes (256 KiB); only the final chunk may be smaller.' );
		}
		$committed = (int) $session['committed'];
		if ( $start > $committed ) {
			// A gap: nothing can be appended; tell the client where to resume.
			$this->note = 'gap: chunk starts at ' . $start . ', committed ' . $committed;
			return $this->resumeIncomplete( $id );
		}
		$session['total'] = $known_total;
		// Bytes already persisted are ignored (Google/GCS semantics), only the new tail is appended.
		$skip = $committed - $start;
		$take = max( 0, $chunk - $skip );
		if ( $take > 0 ) {
			$take = $this->applyCommitLimit( $take );
		}
		if ( $take > 0 ) {
			$this->appendBytes( $this->req['spool'], $skip, $take, $this->store->partPath( $id ), $committed );
		}
		$session['committed'] = $committed + $take;
		$session['updated']   = $this->now;
		$this->note           = 'committed ' . $take . ' of ' . $chunk . ' byte(s)';
		if ( null !== $session['total'] && (int) $session['committed'] === (int) $session['total'] ) {
			return $this->finalizeUpload( $id );
		}
		return $this->resumeIncomplete( $id );
	}

	/**
	 * Applies the chunk_commit_limit control (simulated partial receipt).
	 *
	 * @param int $take Bytes the server would commit.
	 * @return int
	 */
	private function applyCommitLimit( $take ) {
		$controls = &$this->state['controls'];
		if ( null === $controls['chunk_commit_limit'] || 0 === (int) $controls['chunk_commit_times'] ) {
			return $take;
		}
		$take = min( $take, (int) $controls['chunk_commit_limit'] );
		if ( $controls['chunk_commit_times'] > 0 ) {
			--$controls['chunk_commit_times'];
			if ( 0 === (int) $controls['chunk_commit_times'] ) {
				$controls['chunk_commit_limit'] = null;
			}
		}
		return $take;
	}

	/**
	 * Appends part of the spooled body to the session file.
	 *
	 * @param string $spool  Spooled body.
	 * @param int    $skip   Bytes to skip in the body.
	 * @param int    $take   Bytes to append.
	 * @param string $part   Session file.
	 * @param int    $offset Committed size (the file is truncated to it first).
	 * @return void
	 */
	private function appendBytes( $spool, $skip, $take, $part, $offset ) {
		$in  = fopen( $spool, 'rb' );
		$out = fopen( $part, 'c+b' );
		if ( false === $in || false === $out ) {
			throw new \RuntimeException( 'Cannot open upload files' );
		}
		fseek( $in, $skip );
		ftruncate( $out, $offset );
		fseek( $out, $offset );
		$copied = stream_copy_to_stream( $in, $out, $take );
		fclose( $in );
		fclose( $out );
		if ( $copied !== $take ) {
			throw new \RuntimeException( 'Short write to upload session' );
		}
	}

	/**
	 * Turns a complete session into a file; 200 with the File resource.
	 *
	 * @param string $id Session id.
	 * @return array
	 */
	private function finalizeUpload( $id ) {
		$session = $this->state['sessions'][ $id ];
		$part    = $this->store->partPath( $id );
		try {
			$tree  = $this->fieldMask( FieldMask::fileSchema() );
			$limit = $this->state['config']['quota_limit'];
			if ( null !== $limit && $this->usage()['total'] + (int) $session['committed'] > $limit ) {
				throw new HttpError( $this->quotaExceeded() );
			}
			$record = $this->newFileRecord( $session['meta'], $session['app'], $session['content_type'] );
		} catch ( HttpError $e ) {
			// The session is dead after a failed finalization; the client must start over.
			unset( $this->state['sessions'][ $id ] );
			if ( is_file( $part ) ) {
				unlink( $part );
			}
			return $e->response();
		}
		$record = $this->withContent( $record, $part );
		if ( $record['size'] !== (int) $session['committed'] ) {
			throw new \RuntimeException( 'Upload part size mismatch' );
		}
		rename( $part, $this->store->blobPath( $record['id'] ) );
		$this->state['files'][ $record['id'] ]      = $record;
		$this->state['sessions'][ $id ]['file_id'] = $record['id'];
		$this->state['sessions'][ $id ]['updated'] = $this->now;
		$this->note                                = 'finalized ' . $record['id'];
		return $this->fileResponse( 200, $record, $tree );
	}

	/**
	 * DELETE <session URI>: cancels the upload (Google answers 499).
	 *
	 * @return array
	 */
	private function cancelUpload() {
		$id = $this->sessionId();
		if ( null === $id ) {
			return $this->sessionNotFound();
		}
		unset( $this->state['sessions'][ $id ] );
		$part = $this->store->partPath( $id );
		if ( is_file( $part ) ) {
			unlink( $part );
		}
		return response( 499, '', array(), 'Client Closed Request' );
	}

	/* ------------------------------------------------------------------ Faults and control API */

	/**
	 * Pops the first queued fault matching this request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @return array|null
	 */
	private function takeFault( $method, $path ) {
		$query = urldecode( $this->req['query_string'] );
		foreach ( $this->state['faults'] as $i => $fault ) {
			if ( '*' !== $fault['method'] && $fault['method'] !== $method ) {
				continue;
			}
			if ( 0 !== strpos( $path, $fault['path'] ) ) {
				continue;
			}
			if ( '' !== $fault['query'] && false === strpos( $query, $fault['query'] ) ) {
				continue;
			}
			foreach ( $fault['header'] as $name => $needle ) {
				if ( false === stripos( $this->header( strtolower( $name ) ), (string) $needle ) ) {
					continue 2;
				}
			}
			if ( $fault['times'] > 0 ) {
				--$fault['times'];
				if ( 0 === $fault['times'] ) {
					unset( $this->state['faults'][ $i ] );
				} else {
					$this->state['faults'][ $i ] = $fault;
				}
				$this->state['faults'] = array_values( $this->state['faults'] );
			}
			$this->fault_applied = true;
			return $fault;
		}
		return null;
	}

	/**
	 * The response a fault produces.
	 *
	 * @param array $fault Fault.
	 * @return array
	 */
	private function faultResponse( array $fault ) {
		$status = (int) $fault['status'];
		if ( 0 === $status ) {
			$resp         = response( 503 );
			$resp['drop'] = true;
		} elseif ( null !== $fault['body'] ) {
			$body = is_string( $fault['body'] ) ? $fault['body'] : encode_json( $fault['body'] );
			$type = null !== $fault['content_type'] ? $fault['content_type'] : ( is_string( $fault['body'] ) && ! is_array( json_decode( $fault['body'], true ) ) ? 'text/plain; charset=utf-8' : 'application/json; charset=UTF-8' );
			$resp = response( $status, $body, array( 'Content-Type' => $type ) );
		} else {
			$reasons = array(
				400 => array( 'badRequest', 'Bad Request' ),
				401 => array( 'authError', 'Invalid Credentials' ),
				403 => array( 'forbidden', 'Forbidden' ),
				404 => array( 'notFound', 'Not Found' ),
				409 => array( 'conflict', 'Conflict' ),
				429 => array( 'rateLimitExceeded', 'Rate Limit Exceeded' ),
				500 => array( 'backendError', 'Internal Error' ),
				502 => array( 'backendError', 'Bad Gateway' ),
				503 => array( 'backendError', 'Service Unavailable' ),
				504 => array( 'backendError', 'Gateway Timeout' ),
			);
			$default = isset( $reasons[ $status ] ) ? $reasons[ $status ] : array( 'backendError', 'Error' );
			$reason  = null !== $fault['reason'] ? $fault['reason'] : $default[0];
			$message = null !== $fault['message'] ? $fault['message'] : $default[1];
			$path    = $this->req['path'];
			if ( in_array( $path, array( '/token', '/o/oauth2/token', '/revoke', '/o/oauth2/revoke' ), true ) ) {
				$resp = oauth_error( $status, null !== $fault['reason'] ? $fault['reason'] : ( $status >= 500 ? 'internal_failure' : 'invalid_request' ), $message );
			} else {
				$resp = drive_error( $status, $message, $reason );
			}
		}
		foreach ( $fault['headers'] as $name => $value ) {
			$resp['headers'][ $name ] = (string) $value;
		}
		if ( 308 === $status ) {
			$resp['reason'] = 'Resume Incomplete';
		}
		$resp['delay'] = (float) $fault['delay'];
		return $resp;
	}

	/**
	 * Validates and normalises one fault definition.
	 *
	 * @param mixed $fault Decoded fault.
	 * @return array
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function normalizeFault( $fault ) {
		if ( ! is_array( $fault ) || ! isset( $fault['path'], $fault['status'] ) || ! is_string( $fault['path'] ) || 0 !== strpos( $fault['path'], '/' ) ) {
			throw new \InvalidArgumentException( 'each fault needs "path" (starting with /) and "status"' );
		}
		$status = $fault['status'];
		if ( ! is_int( $status ) || ( 0 !== $status && ( $status < 100 || $status > 599 ) ) ) {
			throw new \InvalidArgumentException( 'fault status must be 0 (drop the connection) or 100-599' );
		}
		$times = isset( $fault['times'] ) ? $fault['times'] : 1;
		if ( ! is_int( $times ) || 0 === $times || $times < -1 ) {
			throw new \InvalidArgumentException( 'fault times must be a positive integer or -1 (forever)' );
		}
		$known = array( 'method', 'path', 'status', 'times', 'body', 'content_type', 'headers', 'delay', 'process', 'query', 'header', 'reason', 'message' );
		$extra = array_diff( array_keys( $fault ), $known );
		if ( array() !== $extra ) {
			throw new \InvalidArgumentException( 'unknown fault key(s): ' . implode( ', ', $extra ) );
		}
		foreach ( array( 'headers', 'header' ) as $map ) {
			if ( isset( $fault[ $map ] ) && ( ! is_array( $fault[ $map ] ) || ( array() !== $fault[ $map ] && is_list( $fault[ $map ] ) ) ) ) {
				throw new \InvalidArgumentException( 'fault "' . $map . '" must be an object' );
			}
		}
		return array(
			'method'       => isset( $fault['method'] ) ? strtoupper( (string) $fault['method'] ) : '*',
			'path'         => $fault['path'],
			'status'       => $status,
			'times'        => $times,
			'body'         => array_key_exists( 'body', $fault ) ? $fault['body'] : null,
			'content_type' => isset( $fault['content_type'] ) ? (string) $fault['content_type'] : null,
			'headers'      => isset( $fault['headers'] ) ? $fault['headers'] : array(),
			'delay'        => isset( $fault['delay'] ) ? max( 0.0, (float) $fault['delay'] ) : 0.0,
			'process'      => ! empty( $fault['process'] ),
			'query'        => isset( $fault['query'] ) ? (string) $fault['query'] : '',
			'header'       => isset( $fault['header'] ) ? $fault['header'] : array(),
			'reason'       => isset( $fault['reason'] ) ? (string) $fault['reason'] : null,
			'message'      => isset( $fault['message'] ) ? (string) $fault['message'] : null,
		);
	}

	/**
	 * Test-only endpoints under /__.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @return array
	 */
	private function internal( $method, $path ) {
		if ( '/__health' === $path ) {
			return text_response( 200, "ok\n" );
		}
		if ( '/__control' === $path ) {
			return 'POST' === $method ? $this->control() : text_response( 405, "POST only\n" );
		}
		if ( '/__state' === $path && 'GET' === $method ) {
			return json_response( 200, $this->stateView() );
		}
		if ( preg_match( '#^/__blob/([^/]+)$#', $path, $m ) && 'GET' === $method ) {
			$id = rawurldecode( $m[1] );
			if ( ! isset( $this->state['files'][ $id ] ) || FOLDER_MIME === $this->state['files'][ $id ]['mimeType'] ) {
				return text_response( 404, 'Not Found' );
			}
			$handle = fopen( $this->store->blobPath( $id ), 'rb' );
			if ( false === $handle ) {
				return text_response( 404, 'Not Found' );
			}
			$resp           = response( 200, '', array( 'Content-Type' => 'application/octet-stream' ) );
			$resp['stream'] = array( $handle, 0, (int) $this->state['files'][ $id ]['size'] );
			return $resp;
		}
		return text_response( 404, "Not Found\n" );
	}

	/**
	 * POST /__control.
	 *
	 * @return array
	 */
	private function control() {
		$data = json_decode( $this->req['body'], true );
		if ( ! is_array( $data ) || ( array() !== $data && is_list( $data ) ) ) {
			return json_response(
				400,
				array(
					'ok'    => false,
					'error' => 'body must be a JSON object',
				)
			);
		}
		$config_keys = array( 'client_id', 'client_secret', 'email', 'name', 'redirect_uris', 'publishing_status', 'access_token_ttl', 'max_upload_size' );
		$flag_keys   = array( 'omit_refresh_token', 'deny_next_consent', 'deny_drive_scope_next_consent', 'rotate_refresh_tokens' );
		$action_keys = array( 'reset', 'clear_faults', 'expire_access_tokens', 'revoke_all', 'expire_sessions', 'clear_log' );
		$other_keys  = array( 'config', 'fail', 'quota', 'chunk_commit_limit', 'chunk_commit_times', 'advance_time' );
		$unknown     = array_diff( array_keys( $data ), array_merge( $config_keys, $flag_keys, $action_keys, $other_keys ) );
		if ( array() !== $unknown ) {
			return json_response(
				400,
				array(
					'ok'    => false,
					'error' => 'unknown control key(s): ' . implode( ', ', $unknown ),
				)
			);
		}
		$backup  = $this->state;
		$applied = array();
		try {
			if ( ! empty( $data['reset'] ) ) {
				$this->store->wipeBlobs();
				$this->state = $this->freshState();
				$this->now   = microtime( true );
				$applied[]   = 'reset';
			}
			foreach ( $data as $key => $value ) {
				if ( 'reset' === $key ) {
					continue;
				}
				if ( in_array( $key, $config_keys, true ) ) {
					$this->setConfig( $key, $value );
				} elseif ( 'config' === $key ) {
					if ( ! is_array( $value ) ) {
						throw new \InvalidArgumentException( 'config must be an object' );
					}
					foreach ( $value as $name => $item ) {
						$this->setConfig( $name, $item );
					}
				} elseif ( in_array( $key, $flag_keys, true ) ) {
					$this->state['controls'][ $key ] = (bool) $value;
				} elseif ( in_array( $key, $action_keys, true ) ) {
					if ( $value ) {
						$this->controlAction( $key );
					}
				} elseif ( 'fail' === $key ) {
					$faults = ( is_array( $value ) && is_list( $value ) ) ? $value : array( $value );
					foreach ( $faults as $fault ) {
						$this->state['faults'][] = $this->normalizeFault( $fault );
					}
				} elseif ( 'quota' === $key ) {
					if ( ! is_array( $value ) ) {
						throw new \InvalidArgumentException( 'quota must be an object {limit, usage}' );
					}
					if ( array_key_exists( 'limit', $value ) ) {
						$this->setConfig( 'quota_limit', $value['limit'] );
					}
					if ( array_key_exists( 'usage', $value ) ) {
						$this->setConfig( 'quota_usage', $value['usage'] );
					}
				} elseif ( 'chunk_commit_limit' === $key ) {
					if ( null !== $value && ( ! is_int( $value ) || $value < 0 ) ) {
						throw new \InvalidArgumentException( 'chunk_commit_limit must be a non-negative integer or null' );
					}
					$this->state['controls']['chunk_commit_limit'] = $value;
					$this->state['controls']['chunk_commit_times'] = null === $value ? 0 : ( isset( $data['chunk_commit_times'] ) ? (int) $data['chunk_commit_times'] : 1 );
				} elseif ( 'chunk_commit_times' === $key ) {
					if ( ! is_int( $value ) || $value < -1 ) {
						throw new \InvalidArgumentException( 'chunk_commit_times must be a positive integer, 0 or -1 (forever)' );
					}
					$this->state['controls']['chunk_commit_times'] = $value;
				} elseif ( 'advance_time' === $key ) {
					if ( ! is_int( $value ) && ! is_float( $value ) ) {
						throw new \InvalidArgumentException( 'advance_time must be a number of seconds' );
					}
					$this->state['clock_offset'] += $value;
					$this->now                   += $value;
				}
				$applied[] = $key;
			}
		} catch ( \InvalidArgumentException $e ) {
			$this->state = $backup;
			return json_response(
				400,
				array(
					'ok'    => false,
					'error' => $e->getMessage(),
				)
			);
		}
		return json_response(
			200,
			array(
				'ok'      => true,
				'applied' => $applied,
			)
		);
	}

	/**
	 * Sets one config value (validated).
	 *
	 * @param string $name  Key.
	 * @param mixed  $value Value.
	 * @return void
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function setConfig( $name, $value ) {
		switch ( $name ) {
			case 'client_id':
			case 'client_secret':
			case 'email':
			case 'name':
				if ( ! is_string( $value ) || '' === $value ) {
					throw new \InvalidArgumentException( $name . ' must be a non-empty string' );
				}
				break;
			case 'publishing_status':
				if ( ! in_array( $value, array( 'production', 'testing' ), true ) ) {
					throw new \InvalidArgumentException( 'publishing_status must be production or testing' );
				}
				break;
			case 'redirect_uris':
				if ( ! is_array( $value ) || ! is_list( $value ) ) {
					throw new \InvalidArgumentException( 'redirect_uris must be a list of URIs ([] accepts any)' );
				}
				$value = array_values( array_map( 'strval', $value ) );
				break;
			case 'access_token_ttl':
			case 'quota_usage':
			case 'max_upload_size':
				if ( ! is_int( $value ) || $value < 0 ) {
					throw new \InvalidArgumentException( $name . ' must be a non-negative integer' );
				}
				break;
			case 'quota_limit':
				if ( null !== $value && ( ! is_int( $value ) || $value < 0 ) ) {
					throw new \InvalidArgumentException( 'quota limit must be a non-negative integer or null (unlimited)' );
				}
				break;
			default:
				throw new \InvalidArgumentException( 'unknown config key: ' . $name );
		}
		$this->state['config'][ $name ] = $value;
	}

	/**
	 * One-shot control actions.
	 *
	 * @param string $key Action.
	 * @return void
	 */
	private function controlAction( $key ) {
		switch ( $key ) {
			case 'clear_faults':
				$this->state['faults'] = array();
				break;
			case 'clear_log':
				$this->state['log'] = array();
				break;
			case 'expire_access_tokens':
				foreach ( $this->state['access_tokens'] as $token => $record ) {
					$this->state['access_tokens'][ $token ]['expires_at'] = min( $record['expires_at'], $this->now - 1 );
				}
				break;
			case 'revoke_all':
				foreach ( array_keys( $this->state['grants'] ) as $client_id ) {
					$this->revokeGrant( $client_id );
				}
				foreach ( array( 'refresh_tokens', 'access_tokens' ) as $kind ) {
					foreach ( array_keys( $this->state[ $kind ] ) as $token ) {
						$this->state[ $kind ][ $token ]['revoked'] = true;
					}
				}
				break;
			case 'expire_sessions':
				foreach ( $this->state['sessions'] as $id => $session ) {
					$this->state['sessions'][ $id ]['expired'] = true;
					$part                                      = $this->store->partPath( $id );
					if ( is_file( $part ) ) {
						unlink( $part );
					}
				}
				break;
		}
	}

	/**
	 * GET /__state: everything a test may want to assert on, secrets redacted.
	 *
	 * @return array
	 */
	private function stateView() {
		$grants = array();
		foreach ( $this->state['grants'] as $client_id => $grant ) {
			$grants[ $client_id ] = array(
				'client_id'      => $client_id,
				'active'         => ! empty( $grant['active'] ),
				'scope'          => $grant['scope'],
				'created'        => rfc3339( $grant['created'] ),
				'refresh_tokens' => array(),
				'access_tokens'  => array(),
			);
		}
		foreach ( $this->state['refresh_tokens'] as $token => $record ) {
			if ( ! isset( $grants[ $record['client_id'] ] ) ) {
				continue;
			}
			$grants[ $record['client_id'] ]['refresh_tokens'][] = array(
				'token'     => redact( $token ),
				'created'   => rfc3339( $record['created'] ),
				'last_used' => null === $record['last_used'] ? null : rfc3339( $record['last_used'] ),
				'revoked'   => ! empty( $record['revoked'] ),
			);
		}
		foreach ( $this->state['access_tokens'] as $token => $record ) {
			if ( ! isset( $grants[ $record['client_id'] ] ) ) {
				continue;
			}
			$grants[ $record['client_id'] ]['access_tokens'][] = array(
				'token'      => redact( $token ),
				'expires_at' => rfc3339( $record['expires_at'] ),
				'expired'    => $this->now >= $record['expires_at'],
				'revoked'    => ! empty( $record['revoked'] ),
			);
		}
		$files = array();
		foreach ( $this->state['files'] as $record ) {
			$resource        = $this->fileResource( $record );
			$resource['app'] = $record['app'];
			$files[]         = $resource;
		}
		$sessions = array();
		foreach ( $this->state['sessions'] as $id => $session ) {
			$sessions[] = array(
				'upload_id' => redact( $id ),
				'name'      => $session['name'],
				'committed' => (int) $session['committed'],
				'total'     => null === $session['total'] ? null : (int) $session['total'],
				'created'   => rfc3339( $session['created'] ),
				'updated'   => rfc3339( $session['updated'] ),
				'expired'   => ! empty( $session['expired'] ) || $this->now - $session['created'] > SESSION_LIFETIME,
				'finalized' => null !== $session['file_id'],
				'file_id'   => $session['file_id'],
			);
		}
		$codes = array();
		foreach ( $this->state['codes'] as $code => $record ) {
			$codes[] = array(
				'code'         => redact( $code ),
				'redirect_uri' => $record['redirect_uri'],
				'scope'        => $record['scope'],
				'used'         => ! empty( $record['used'] ),
				'created'      => rfc3339( $record['created'] ),
			);
		}
		$usage = $this->usage();
		return array(
			'now'          => rfc3339( $this->now ),
			'clock_offset' => $this->state['clock_offset'],
			'config'       => $this->state['config'],
			'controls'     => $this->state['controls'],
			'faults'       => $this->state['faults'],
			'root_id'      => $this->state['root_id'],
			'quota'        => array(
				'limit' => $this->state['config']['quota_limit'],
				'usage' => $usage['total'],
				'trash' => $usage['trash'],
			),
			'grants'       => array_values( $grants ),
			'codes'        => $codes,
			'files'        => $files,
			'sessions'     => $sessions,
			'log'          => $this->state['log'],
		);
	}

	/* ------------------------------------------------------------------ Housekeeping */

	/**
	 * Appends the request to the log (last 200 kept), tokens redacted.
	 *
	 * @param array $resp Response.
	 * @return void
	 */
	private function log( array $resp ) {
		$entry = array(
			'time'   => rfc3339( microtime( true ) ),
			'method' => $this->req['method'],
			'path'   => $this->req['path'],
			'query'  => redact_query( $this->req['query_string'] ),
			'status' => ! empty( $resp['drop'] ) ? 0 : $resp['status'],
		);
		$range = $this->header( 'content-range' );
		if ( '' !== $range ) {
			$entry['content_range'] = $range;
		}
		if ( $this->fault_applied ) {
			$entry['fault'] = true;
		}
		if ( '' !== $this->note ) {
			$entry['note'] = $this->note;
		}
		$this->state['log'][] = $entry;
		if ( count( $this->state['log'] ) > LOG_LIMIT ) {
			$this->state['log'] = array_slice( $this->state['log'], -LOG_LIMIT );
		}
	}

	/**
	 * Drops stale codes, long-expired access tokens and dead sessions so the state stays small.
	 *
	 * @return void
	 */
	private function collectGarbage() {
		foreach ( $this->state['codes'] as $code => $record ) {
			if ( $this->now - $record['created'] > 3600 ) {
				unset( $this->state['codes'][ $code ] );
			}
		}
		foreach ( $this->state['access_tokens'] as $token => $record ) {
			if ( $this->now - $record['expires_at'] > 86400 ) {
				unset( $this->state['access_tokens'][ $token ] );
			}
		}
		foreach ( $this->state['sessions'] as $id => $session ) {
			if ( $this->now - $session['created'] > SESSION_LIFETIME + 86400 ) {
				unset( $this->state['sessions'][ $id ] );
				$part = $this->store->partPath( $id );
				if ( is_file( $part ) ) {
					unlink( $part );
				}
			}
		}
	}
}

/**
 * Captures the request. Upload chunk bodies are spooled to disk before the lock is taken, so a large chunk costs
 * neither memory nor lock time.
 *
 * @param Store $store Store.
 * @return array
 */
function capture_request( Store $store ) {
	$method  = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' );
	$uri     = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
	$path    = (string) parse_url( $uri, PHP_URL_PATH );
	$headers = array();
	$raw     = function_exists( 'getallheaders' ) ? getallheaders() : array();
	foreach ( $raw as $name => $value ) {
		$headers[ strtolower( $name ) ] = $value;
	}
	foreach ( $_SERVER as $name => $value ) {
		if ( 0 === strpos( $name, 'HTTP_' ) ) {
			$key = strtolower( str_replace( '_', '-', substr( $name, 5 ) ) );
			if ( ! isset( $headers[ $key ] ) ) {
				$headers[ $key ] = $value;
			}
		}
	}
	$spool  = null;
	$body   = '';
	$length = 0;
	if ( 'PUT' === $method && '/upload/drive/v3/files' === $path ) {
		$spool  = $store->spoolPath();
		$in     = fopen( 'php://input', 'rb' );
		$out    = fopen( $spool, 'wb' );
		$length = (int) stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		register_shutdown_function(
			function () use ( $spool ) {
				if ( is_file( $spool ) ) {
					unlink( $spool );
				}
			}
		);
	} else {
		$body   = (string) file_get_contents( 'php://input' );
		$length = strlen( $body );
	}
	$form = array();
	if ( false !== stripos( isset( $headers['content-type'] ) ? $headers['content-type'] : '', 'application/x-www-form-urlencoded' ) ) {
		parse_str( $body, $form );
		if ( array() === $form && ! empty( $_POST ) ) {
			$form = $_POST;
		}
	}
	$base = getenv( 'FAKE_GOOGLE_BASE_URL' );
	if ( false === $base || '' === $base ) {
		$base = 'http://' . ( isset( $headers['host'] ) ? $headers['host'] : '127.0.0.1' );
	}
	return array(
		'method'       => $method,
		'path'         => $path,
		'query'        => $_GET,
		'query_string' => isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '',
		'headers'      => $headers,
		'body'         => $body,
		'body_length'  => $length,
		'spool'        => $spool,
		'form'         => $form,
		'base_url'     => rtrim( $base, '/' ),
	);
}

// Bodiless answers (204, 308) must not get PHP's default "Content-Type: text/html"; notices must never leak into bodies.
ini_set( 'default_mimetype', '' );
ini_set( 'display_errors', '0' );
error_reporting( E_ALL );
set_error_handler(
	function ( $severity, $message, $file, $line ) {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new \ErrorException( $message, 0, $severity, $file, $line );
	}
);

$fake_google_dir = getenv( 'FAKE_GOOGLE_DIR' );
if ( false === $fake_google_dir || '' === $fake_google_dir ) {
	$fake_google_dir = rtrim( sys_get_temp_dir(), '/' ) . '/shcm-fake-google';
}
$fake_google_store = new Store( $fake_google_dir );
( new Server( $fake_google_store, capture_request( $fake_google_store ) ) )->run();
