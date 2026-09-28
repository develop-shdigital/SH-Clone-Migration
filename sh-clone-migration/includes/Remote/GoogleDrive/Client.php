<?php
/**
 * Google Drive API v3 client.
 *
 * @package SHCM
 */

namespace SHCM\Remote\GoogleDrive;

use SHCM\Remote\Http\HttpResponse;
use SHCM\Remote\Http\HttpTransport;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * The few Drive calls backups need: account and quota, the backup folder,
 * listing, resumable uploads, ranged downloads and deletion.
 *
 * Every authenticated call sends "Authorization: Bearer"; a 401 refreshes the
 * token once and retries once. Nothing else is retried here: rate limits
 * and server errors come back immediately as RATE_LIMITED / SERVER and the
 * caller (the upload stage) decides when to try again, so a request never
 * sleeps inside a time-boxed job tick.
 *
 * Requests to an upload session URI carry no Authorization header (the URI
 * itself is the credential) and rely on the transport not following the
 * "308 Resume Incomplete" answers.
 *
 * Files are returned as array( id, name, size (int), created (unix time),
 * md5, sha256, app (appProperties), link (webViewLink) ).
 */
final class Client {

	const FOLDER_MIME = 'application/vnd.google-apps.folder';

	const UPLOAD_MIME = 'application/octet-stream';

	/**
	 * Every chunk except the last must be a multiple of 256 KiB.
	 */
	const CHUNK_MULTIPLE = 262144;

	/**
	 * Default timeout of API calls, in seconds.
	 */
	const TIMEOUT = 30.0;

	/**
	 * Google's limit for one appProperties entry: key plus value, UTF-8 bytes.
	 */
	const MAX_PROPERTY_BYTES = 124;

	/**
	 * Google's limit of private properties per file and app.
	 */
	const MAX_PROPERTIES = 30;

	/**
	 * File fields requested for listings and single files.
	 */
	const FILE_FIELDS = 'id,name,size,createdTime,md5Checksum,sha256Checksum,appProperties,webViewLink';

	/**
	 * Safety stop for listings (100 files per page).
	 */
	const MAX_PAGES = 200;

	/**
	 * OAuth.
	 *
	 * @var OAuth
	 */
	private $oauth;

	/**
	 * Transport.
	 *
	 * @var HttpTransport
	 */
	private $http;

	/**
	 * Endpoints.
	 *
	 * @var array<string, string>
	 */
	private $endpoints;

	/**
	 * Constructor.
	 *
	 * @param OAuth         $oauth     OAuth (provides access tokens).
	 * @param HttpTransport $http      Transport.
	 * @param array         $endpoints Endpoints (Endpoints::resolve()); missing keys use Google's.
	 */
	public function __construct( OAuth $oauth, HttpTransport $http, array $endpoints ) {
		$this->oauth     = $oauth;
		$this->http      = $http;
		$this->endpoints = Endpoints::normalize( $endpoints );
	}

	/**
	 * The connected account and its storage.
	 *
	 * @return array{email: string, name: string, limit: int|null, usage: int, max_upload: int|null} limit is null for unlimited storage.
	 * @throws DriveException On failure.
	 */
	public function about() {
		$response = $this->authorized(
			'GET',
			$this->apiUrl( '/about', array( 'fields' => 'user(displayName,emailAddress),storageQuota(limit,usage),maxUploadSize' ) )
		);
		$json  = $this->expectJson( $response );
		$user  = isset( $json['user'] ) && is_array( $json['user'] ) ? $json['user'] : array();
		$quota = isset( $json['storageQuota'] ) && is_array( $json['storageQuota'] ) ? $json['storageQuota'] : array();

		return array(
			'email'      => isset( $user['emailAddress'] ) ? (string) $user['emailAddress'] : '',
			'name'       => isset( $user['displayName'] ) ? (string) $user['displayName'] : '',
			'limit'      => isset( $quota['limit'] ) && is_numeric( $quota['limit'] ) ? (int) $quota['limit'] : null,
			'usage'      => isset( $quota['usage'] ) && is_numeric( $quota['usage'] ) ? (int) $quota['usage'] : 0,
			'max_upload' => isset( $json['maxUploadSize'] ) && is_numeric( $json['maxUploadSize'] ) ? (int) $json['maxUploadSize'] : null,
		);
	}

	/**
	 * Find or create this site's backup folder.
	 *
	 * With drive.file the app only sees files it created, and names are not
	 * unique, so the folder is recognised by its appProperties
	 * (shcm_role=backup_root, shcm_site=<site id>), not by its name.
	 *
	 * @param string $site_id  Site id (Connection::siteId()).
	 * @param string $name     Name for a new folder.
	 * @param string $known_id Folder id remembered from last time, if any.
	 * @return array{id: string, name: string}
	 * @throws DriveException On failure.
	 * @throws \InvalidArgumentException When the site id is empty.
	 */
	public function ensureFolder( $site_id, $name, $known_id = '' ) {
		$site_id  = (string) $site_id;
		$name     = trim( (string) $name );
		$known_id = trim( (string) $known_id );
		if ( '' === $site_id ) {
			throw new \InvalidArgumentException( 'A site id is required to find the backup folder.' );
		}
		if ( '' === $name ) {
			$name = 'SH Clone Migration Backups';
		}

		if ( '' !== $known_id ) {
			$response = $this->authorized( 'GET', $this->apiUrl( '/files/' . rawurlencode( $known_id ), array( 'fields' => 'id,name,mimeType,trashed' ) ) );
			if ( $response->isSuccess() ) {
				$json = $this->expectJson( $response );
				if ( isset( $json['id'] ) && self::FOLDER_MIME === ( isset( $json['mimeType'] ) ? $json['mimeType'] : '' ) && empty( $json['trashed'] ) ) {
					return array(
						'id'   => (string) $json['id'],
						'name' => isset( $json['name'] ) ? (string) $json['name'] : '',
					);
				}
			} else {
				$error = DriveException::fromResponse( $response, $this->secrets() );
				// Deleted, or no longer visible to this OAuth client: look for another.
				if ( ! in_array( $error->kind(), array( DriveException::NOT_FOUND, DriveException::FORBIDDEN ), true ) ) {
					throw $error;
				}
			}
		}

		$query    = "mimeType = '" . self::FOLDER_MIME . "' and trashed = false"
			. " and appProperties has { key='shcm_role' and value='backup_root' }"
			. " and appProperties has { key='shcm_site' and value=" . self::quote( $site_id ) . ' }';
		$response = $this->authorized(
			'GET',
			$this->apiUrl(
				'/files',
				array(
					'q'        => $query,
					'spaces'   => 'drive',
					'orderBy'  => 'createdTime',
					'pageSize' => 10,
					'fields'   => 'files(id,name,createdTime)',
				)
			)
		);
		$json = $this->expectJson( $response );
		if ( ! empty( $json['files'][0]['id'] ) ) {
			return array(
				'id'   => (string) $json['files'][0]['id'],
				'name' => isset( $json['files'][0]['name'] ) ? (string) $json['files'][0]['name'] : '',
			);
		}

		$response = $this->authorized(
			'POST',
			$this->apiUrl( '/files', array( 'fields' => 'id,name' ) ),
			array( 'Content-Type' => 'application/json; charset=UTF-8' ),
			self::encode(
				array(
					'name'          => $name,
					'mimeType'      => self::FOLDER_MIME,
					'appProperties' => array(
						'shcm_role' => 'backup_root',
						'shcm_site' => $site_id,
					),
				)
			)
		);
		$json = $this->expectJson( $response );
		if ( empty( $json['id'] ) ) {
			throw new DriveException( DriveException::SERVER, 'Google Drive did not return the id of the new backup folder.', $response->status );
		}
		return array(
			'id'   => (string) $json['id'],
			'name' => isset( $json['name'] ) ? (string) $json['name'] : $name,
		);
	}

	/**
	 * All backups of this site in a folder, newest first (every page).
	 *
	 * @param string $folder_id Folder id.
	 * @param string $site_id   Site id.
	 * @param string $kind      Optional appProperties shcm_kind filter ('backup', 'manual', ...).
	 * @return array[] Files.
	 * @throws DriveException On failure.
	 */
	public function listBackups( $folder_id, $site_id, $kind = '' ) {
		$query = self::quote( (string) $folder_id ) . ' in parents and trashed = false'
			. " and appProperties has { key='shcm_site' and value=" . self::quote( (string) $site_id ) . ' }';
		if ( '' !== (string) $kind ) {
			$query .= " and appProperties has { key='shcm_kind' and value=" . self::quote( (string) $kind ) . ' }';
		}

		$files = array();
		$token = '';
		$seen  = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$params = array(
				'q'        => $query,
				'orderBy'  => 'createdTime desc',
				'pageSize' => 100,
				'fields'   => 'nextPageToken,files(' . self::FILE_FIELDS . ')',
			);
			if ( '' !== $token ) {
				$params['pageToken'] = $token;
			}
			$json = $this->expectJson( $this->authorized( 'GET', $this->apiUrl( '/files', $params ) ) );
			if ( isset( $json['files'] ) && is_array( $json['files'] ) ) {
				foreach ( $json['files'] as $file ) {
					if ( is_array( $file ) && ! empty( $file['id'] ) ) {
						$files[] = self::normalizeFile( $file );
					}
				}
			}
			$token = isset( $json['nextPageToken'] ) && is_string( $json['nextPageToken'] ) ? $json['nextPageToken'] : '';
			// A repeated token would loop forever.
			if ( '' === $token || isset( $seen[ $token ] ) ) {
				break;
			}
			$seen[ $token ] = true;
		}
		return $files;
	}

	/**
	 * One file.
	 *
	 * @param string $id File id.
	 * @return array File.
	 * @throws DriveException NOT_FOUND when it does not exist (or is not visible to the app).
	 */
	public function getFile( $id ) {
		$response = $this->authorized( 'GET', $this->apiUrl( '/files/' . rawurlencode( (string) $id ), array( 'fields' => self::FILE_FIELDS ) ) );
		$json     = $this->expectJson( $response );
		if ( empty( $json['id'] ) ) {
			throw new DriveException( DriveException::SERVER, 'Google Drive returned file details without an id.', $response->status );
		}
		return self::normalizeFile( $json );
	}

	/**
	 * Delete a file permanently (not to the trash, which would still use storage).
	 *
	 * @param string $id File id.
	 * @return bool True when deleted, false when it was already gone.
	 * @throws DriveException On failure.
	 */
	public function deleteFile( $id ) {
		$response = $this->authorized( 'DELETE', $this->apiUrl( '/files/' . rawurlencode( (string) $id ) ) );
		if ( $response->isSuccess() ) {
			return true;
		}
		if ( 404 === $response->status ) {
			return false;
		}
		throw DriveException::fromResponse( $response, $this->secrets() );
	}

	/**
	 * Start a resumable upload.
	 *
	 * @param string $name           File name.
	 * @param int    $size           Total size in bytes.
	 * @param string $folder_id      Parent folder id.
	 * @param array  $app_properties Private properties (each key + value at most 124 bytes).
	 * @param string $description    Description.
	 * @return string Upload session URI. It is a credential: store it sealed, never log it.
	 * @throws DriveException On failure; BAD_REQUEST when a property is too long.
	 * @throws \InvalidArgumentException When name or size are invalid.
	 */
	public function startUpload( $name, $size, $folder_id, array $app_properties, $description = '' ) {
		$name = (string) $name;
		$size = (int) $size;
		if ( '' === trim( $name ) ) {
			throw new \InvalidArgumentException( 'An upload needs a file name.' );
		}
		if ( $size < 1 ) {
			throw new \InvalidArgumentException( 'An upload needs at least one byte.' );
		}
		$properties = self::checkProperties( $app_properties );

		$response = $this->authorized(
			'POST',
			$this->endpoints['upload'] . '/files?uploadType=resumable&fields=' . rawurlencode( 'id,name,size,md5Checksum,sha256Checksum,createdTime,webViewLink,appProperties' ),
			array(
				'Content-Type'            => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type'   => self::UPLOAD_MIME,
				'X-Upload-Content-Length' => (string) $size,
			),
			self::encode(
				array(
					'name'          => $name,
					'parents'       => array( (string) $folder_id ),
					'mimeType'      => self::UPLOAD_MIME,
					'description'   => (string) $description,
					'appProperties' => (object) $properties,
				)
			)
		);

		if ( ! $response->isSuccess() ) {
			throw DriveException::fromResponse( $response, $this->secrets() );
		}
		$location = (string) $response->header( 'location' );
		if ( '' !== $location && '/' === $location[0] ) {
			$location = $this->origin( $this->endpoints['upload'] ) . $location;
		}
		if ( ! preg_match( '#^https?://#i', $location ) ) {
			throw new DriveException( DriveException::SERVER, 'Google Drive did not return an upload session. Try again later.', $response->status, 'no_location' );
		}
		$this->oauth->connection()->noteSecret( $location );
		return $location;
	}

	/**
	 * Send one chunk of a resumable upload.
	 *
	 * Results: 308 with "Range: bytes=0-K" continues at K+1, 308 without a
	 * Range at 0 (nothing arrived); 200/201 is done. Errors: 429 and 403 rate
	 * limit reasons are RATE_LIMITED, 403 storageQuotaExceeded is QUOTA, 5xx
	 * and network failures are SERVER (query the status, then resume), 404,
	 * 410 and any other 4xx are SESSION_EXPIRED (start a new upload).
	 *
	 * @param string $session_uri Upload session URI.
	 * @param string $data        Chunk bytes.
	 * @param int    $offset      Position of the first byte.
	 * @param int    $total       Total file size.
	 * @param float  $timeout     Request timeout in seconds.
	 * @return array{done: bool, offset: int, file: array|null} offset = next byte Google expects; file when done.
	 * @throws DriveException On failure.
	 * @throws \InvalidArgumentException When the chunk breaks the protocol rules.
	 */
	public function uploadChunk(
		#[\SensitiveParameter]
		$session_uri,
		$data,
		$offset,
		$total,
		$timeout = 120.0
	) {
		$data   = (string) $data;
		$offset = (int) $offset;
		$total  = (int) $total;
		$length = strlen( $data );
		self::checkSessionUri( $session_uri );
		if ( $offset < 0 || 0 === $length || $offset + $length > $total ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid upload chunk: %d bytes at offset %d of %d.', $length, $offset, $total ) );
		}
		if ( $offset + $length !== $total && 0 !== $length % self::CHUNK_MULTIPLE ) {
			throw new \InvalidArgumentException( sprintf( 'Upload chunks must be multiples of %d bytes except the last one (got %d).', self::CHUNK_MULTIPLE, $length ) );
		}

		$response = $this->http->request(
			'PUT',
			$session_uri,
			array(
				'Content-Length' => (string) $length,
				'Content-Range'  => sprintf( 'bytes %d-%d/%d', $offset, $offset + $length - 1, $total ),
				// Without it cURL labels the body application/x-www-form-urlencoded.
				'Content-Type'   => self::UPLOAD_MIME,
			),
			$data,
			array( 'timeout' => $timeout > 0 ? (float) $timeout : 120.0 )
		);
		return $this->sessionResult( $response, $total, $session_uri );
	}

	/**
	 * Ask how much of a resumable upload arrived (after an interruption or a
	 * SERVER / RATE_LIMITED error). Same results as uploadChunk().
	 *
	 * @param string $session_uri Upload session URI.
	 * @param int    $total       Total file size.
	 * @return array{done: bool, offset: int, file: array|null}
	 * @throws DriveException On failure.
	 */
	public function queryUpload(
		#[\SensitiveParameter]
		$session_uri,
		$total
	) {
		self::checkSessionUri( $session_uri );
		$total    = (int) $total;
		$response = $this->http->request(
			'PUT',
			$session_uri,
			array(
				'Content-Length' => '0',
				'Content-Range'  => sprintf( 'bytes */%d', $total ),
			),
			'',
			array( 'timeout' => self::TIMEOUT )
		);
		return $this->sessionResult( $response, $total, $session_uri );
	}

	/**
	 * Download part of a file.
	 *
	 * @param string $id      File id.
	 * @param int    $start   First byte.
	 * @param int    $end     Last byte (inclusive).
	 * @param float  $timeout Request timeout in seconds.
	 * @return string The bytes (fewer than requested only at the end of the file).
	 * @throws DriveException On failure; INTEGRITY when the answer does not match the range.
	 * @throws \InvalidArgumentException When the range is invalid.
	 */
	public function downloadRange( $id, $start, $end, $timeout = 120.0 ) {
		$start = (int) $start;
		$end   = (int) $end;
		if ( $start < 0 || $end < $start ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid byte range %d-%d.', $start, $end ) );
		}
		$wanted = $end - $start + 1;

		$response = $this->authorized(
			'GET',
			$this->apiUrl( '/files/' . rawurlencode( (string) $id ), array( 'alt' => 'media' ) ),
			array( 'Range' => sprintf( 'bytes=%d-%d', $start, $end ) ),
			'',
			$timeout > 0 ? (float) $timeout : 120.0
		);
		$body   = $response->body;
		$length = strlen( $body );

		if ( 206 === $response->status ) {
			$range    = trim( (string) $response->header( 'content-range' ) );
			$mismatch = 0 === $length || $length > $wanted;
			if ( ! $mismatch && preg_match( '#^bytes\s+(\d+)-(\d+)/(\d+|\*)$#i', $range, $match ) ) {
				$mismatch = (int) $match[1] !== $start || (int) $match[2] - (int) $match[1] + 1 !== $length;
			}
			if ( $mismatch ) {
				throw new DriveException( DriveException::INTEGRITY, 'Google Drive returned a different part of the file than requested.', 206, 'range_mismatch' );
			}
			return $body;
		}

		if ( 200 === $response->status ) {
			// The whole file: acceptable only when the range covers all of it.
			if ( 0 === $start && $length <= $wanted ) {
				return $body;
			}
			throw new DriveException( DriveException::BAD_REQUEST, 'Google Drive sent the whole file instead of the requested part.', 200, 'range_ignored' );
		}

		throw DriveException::fromResponse( $response, $this->secrets() );
	}

	/**
	 * Normalise a Drive file resource.
	 *
	 * @param array $file File resource.
	 * @return array{id: string, name: string, size: int, created: int, md5: string, sha256: string, app: array, link: string}
	 */
	public static function normalizeFile( array $file ) {
		$created = 0;
		if ( isset( $file['createdTime'] ) && is_string( $file['createdTime'] ) ) {
			$parsed  = strtotime( $file['createdTime'] );
			$created = false === $parsed ? 0 : (int) $parsed;
		}
		$app = array();
		if ( isset( $file['appProperties'] ) && is_array( $file['appProperties'] ) ) {
			foreach ( $file['appProperties'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$app[ (string) $key ] = (string) $value;
				}
			}
		}
		return array(
			'id'      => isset( $file['id'] ) ? (string) $file['id'] : '',
			'name'    => isset( $file['name'] ) ? (string) $file['name'] : '',
			'size'    => isset( $file['size'] ) && is_numeric( $file['size'] ) ? (int) $file['size'] : 0,
			'created' => $created,
			'md5'     => isset( $file['md5Checksum'] ) ? strtolower( (string) $file['md5Checksum'] ) : '',
			'sha256'  => isset( $file['sha256Checksum'] ) ? strtolower( (string) $file['sha256Checksum'] ) : '',
			'app'     => $app,
			'link'    => isset( $file['webViewLink'] ) ? (string) $file['webViewLink'] : '',
		);
	}

	/**
	 * Quote a string for a Drive search query ("'" and "\" escaped).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function quote( $value ) {
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
	}

	/**
	 * Send an authenticated request; on 401 refresh the token and retry once.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param float  $timeout Timeout in seconds.
	 * @return HttpResponse
	 * @throws DriveException When no access token can be obtained.
	 */
	private function authorized( $method, $url, array $headers = array(), $body = '', $timeout = self::TIMEOUT ) {
		$options  = array( 'timeout' => (float) $timeout );
		$headers  = array_merge( $headers, array( 'Authorization' => 'Bearer ' . $this->oauth->accessToken() ) );
		$response = $this->http->request( $method, $url, $headers, $body, $options );
		if ( 401 === $response->status ) {
			$headers['Authorization'] = 'Bearer ' . $this->oauth->accessToken( true );
			$response                 = $this->http->request( $method, $url, $headers, $body, $options );
		}
		return $response;
	}

	/**
	 * Map a response to an upload session request.
	 *
	 * @param HttpResponse $response    Response.
	 * @param int          $total       Total size.
	 * @param string       $session_uri Session URI (kept out of messages).
	 * @return array{done: bool, offset: int, file: array|null}
	 * @throws DriveException On failure.
	 */
	private function sessionResult(
		HttpResponse $response,
		$total,
		#[\SensitiveParameter]
		$session_uri
	) {
		$status = $response->status;

		if ( 308 === $status ) {
			$range = trim( (string) $response->header( 'range' ) );
			if ( '' === $range ) {
				return array(
					'done'   => false,
					'offset' => 0,
					'file'   => null,
				);
			}
			if ( ! preg_match( '/^bytes=0-(\d+)$/i', $range, $match ) || (int) $match[1] + 1 > $total ) {
				throw new DriveException( DriveException::SERVER, 'Google Drive reported an unexpected upload position. Check the upload status and resume.', 308, 'bad_range' );
			}
			return array(
				'done'   => false,
				'offset' => (int) $match[1] + 1,
				'file'   => null,
			);
		}

		if ( 200 === $status || 201 === $status ) {
			$json = $response->json();
			return array(
				'done'   => true,
				'offset' => $total,
				'file'   => is_array( $json ) && ! empty( $json['id'] ) ? self::normalizeFile( $json ) : null,
			);
		}

		$error = DriveException::fromResponse( $response, $this->secrets( $session_uri ) );
		if ( in_array( $error->kind(), array( DriveException::RATE_LIMITED, DriveException::QUOTA ), true ) ) {
			throw $error;
		}
		if ( $status >= 400 && $status < 500 ) {
			throw new DriveException(
				DriveException::SESSION_EXPIRED,
				sprintf(
					'The Google Drive upload session has expired or was rejected (%d%s). The upload has to start again.',
					$status,
					'' !== $error->reason() ? ' ' . $error->reason() : ''
				),
				$status,
				$error->reason()
			);
		}
		// 5xx, network failures and anything unexpected: ask for the status, then resume.
		if ( DriveException::SERVER === $error->kind() ) {
			throw $error;
		}
		throw new DriveException( DriveException::SERVER, $error->getMessage(), $status, $error->reason() );
	}

	/**
	 * Decode a successful JSON response or throw.
	 *
	 * @param HttpResponse $response Response.
	 * @return array
	 * @throws DriveException On an error status or an unreadable body.
	 */
	private function expectJson( HttpResponse $response ) {
		if ( ! $response->isSuccess() ) {
			throw DriveException::fromResponse( $response, $this->secrets() );
		}
		$json = $response->json();
		if ( null === $json ) {
			throw new DriveException(
				DriveException::SERVER,
				sprintf( 'Google Drive sent an answer that could not be read (HTTP %d).', $response->status ),
				$response->status,
				'invalid_json'
			);
		}
		return $json;
	}

	/**
	 * Check appProperties against Google's limits.
	 *
	 * @param array $properties Properties.
	 * @return array<string, string>
	 * @throws DriveException BAD_REQUEST when a limit is exceeded.
	 */
	private static function checkProperties( array $properties ) {
		if ( count( $properties ) > self::MAX_PROPERTIES ) {
			throw new DriveException(
				DriveException::BAD_REQUEST,
				sprintf( 'Google Drive allows at most %d file properties per app.', self::MAX_PROPERTIES )
			);
		}
		$clean = array();
		foreach ( $properties as $key => $value ) {
			$key   = (string) $key;
			$value = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
			if ( '' === $key || ! preg_match( '//u', $key . $value ) ) {
				throw new DriveException( DriveException::BAD_REQUEST, 'A Google Drive file property has an empty name or is not valid UTF-8.' );
			}
			if ( strlen( $key ) + strlen( $value ) > self::MAX_PROPERTY_BYTES ) {
				throw new DriveException(
					DriveException::BAD_REQUEST,
					sprintf( 'The Google Drive file property "%s" is too long: name and value may have at most %d bytes together.', substr( $key, 0, 60 ), self::MAX_PROPERTY_BYTES )
				);
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/**
	 * Reject a session URI that is not an http(s) URL.
	 *
	 * @param mixed $session_uri Session URI.
	 * @return void
	 * @throws \InvalidArgumentException When invalid.
	 */
	private static function checkSessionUri(
		#[\SensitiveParameter]
		$session_uri
	) {
		if ( ! is_string( $session_uri ) || ! preg_match( '#^https?://[^\s]+$#i', $session_uri ) ) {
			throw new \InvalidArgumentException( 'The upload session URI is not valid.' );
		}
	}

	/**
	 * Secrets to keep out of error messages.
	 *
	 * @param string $extra An additional secret (a session URI).
	 * @return string[]
	 */
	private function secrets(
		#[\SensitiveParameter]
		$extra = ''
	) {
		$secrets = $this->oauth->connection()->secrets();
		if ( is_string( $extra ) && '' !== $extra ) {
			$secrets[] = $extra;
		}
		return $secrets;
	}

	/**
	 * Drive API URL.
	 *
	 * @param string $path  Path below the API base ("/files/ID").
	 * @param array  $query Query parameters.
	 * @return string
	 */
	private function apiUrl( $path, array $query = array() ) {
		return $this->endpoints['api'] . $path . ( empty( $query ) ? '' : '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
	}

	/**
	 * Scheme, host and port of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function origin( $url ) {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Encode a JSON request body.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function encode( array $data ) {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? '{}' : $json;
	}
}
