<?php
/**
 * In-memory Google Drive and a wired-up upload stage for RemoteUploadStageTest.
 *
 * Not a test case itself: the unit suite only collects files ending in
 * "Test.php".
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit\Support;

// The export stages and BackupManager only load inside WordPress
// ("defined( 'ABSPATH' ) || exit"). Nothing in the unit suite depends on
// ABSPATH being undefined, so a scratch value is enough.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/shcm-unit-abspath/' );
}

use SHCM\Archive\Writer;
use SHCM\Backup\BackupManager;
use SHCM\Backup\ConfigStore;
use SHCM\Core\Settings;
use SHCM\Export\Stages\RemoteUploadStage;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Jobs\JobRunner;
use SHCM\Jobs\JobStore;
use SHCM\Jobs\StageResolver;
use SHCM\Logging\Logger;
use SHCM\Logging\Redactor;
use SHCM\Remote\GoogleDrive\Client;
use SHCM\Remote\GoogleDrive\Connection;
use SHCM\Remote\GoogleDrive\Endpoints;
use SHCM\Remote\GoogleDrive\OAuth;
use SHCM\Remote\Http\HttpResponse;
use SHCM\Remote\Http\HttpTransport;
use SHCM\Security\SecretBox;

/**
 * Google's token endpoint, the Drive v3 calls the upload stage makes and
 * resumable upload sessions, kept in memory.
 *
 * Sessions follow Google's rules: every chunk but the last is a multiple of
 * 256 KiB, "308 Resume Incomplete" carries "Range: bytes=0-N" (none while
 * nothing arrived), a status query is "Content-Range: bytes * /total"
 * (without the space), and the complete upload answers 200 with the file.
 * Faults can replace the answer to matching requests.
 */
class DriveEmulator implements HttpTransport {

	const UNIT = 262144;

	const SESSION_HOST = 'upload.session.test';

	const FOLDER_MIME = 'application/vnd.google-apps.folder';

	/**
	 * Drive files by id: id, name, mimeType, parents, appProperties,
	 * createdTime and, for uploads, content.
	 *
	 * @var array<string, array>
	 */
	public $files = array();

	/**
	 * Upload sessions by upload id: meta, total, data, file.
	 *
	 * @var array<string, array>
	 */
	public $sessions = array();

	/**
	 * Every request: method, url, headers, length, options, status, range,
	 * fault.
	 *
	 * @var array[]
	 */
	public $log = array();

	/**
	 * Leave sha256Checksum out of file resources.
	 *
	 * @var bool
	 */
	public $omit_sha256 = false;

	/**
	 * Leave md5Checksum out of file resources.
	 *
	 * @var bool
	 */
	public $omit_md5 = false;

	/**
	 * Keep at most this many bytes of each chunk (null: all of it).
	 *
	 * @var int|null
	 */
	public $commit_limit = null;

	/**
	 * How many times a complete upload answers 308 with a Range covering the
	 * whole file before it answers 200.
	 *
	 * @var int
	 */
	public $full_range_answers = 0;

	/**
	 * Scripted faults: match (callable), response, left.
	 *
	 * @var array[]
	 */
	protected $faults = array();

	/**
	 * Next file id number.
	 *
	 * @var int
	 */
	protected $next_id = 1;

	/**
	 * Answer requests for which $match( $method, $url, $headers ) is true
	 * with $response instead, $times times.
	 *
	 * @param callable     $match    Matcher.
	 * @param HttpResponse $response Answer.
	 * @param int          $times    How often.
	 * @return void
	 */
	public function fail( callable $match, HttpResponse $response, $times = 1 ) {
		$this->faults[] = array(
			'match'    => $match,
			'response' => $response,
			'left'     => (int) $times,
		);
	}

	/**
	 * Answer the chunk PUTs that start at $start with $response, $times
	 * times. Status queries ("bytes * /total") never match.
	 *
	 * @param int          $start    First byte of the chunk.
	 * @param HttpResponse $response Answer.
	 * @param int          $times    How often.
	 * @return void
	 */
	public function failChunk( $start, HttpResponse $response, $times = 1 ) {
		$prefix = 'bytes ' . (int) $start . '-';
		$this->fail(
			function ( $method, $url, $headers ) use ( $prefix ) {
				unset( $url );
				return 'PUT' === $method && isset( $headers['Content-Range'] ) && 0 === strpos( $headers['Content-Range'], $prefix );
			},
			$response,
			$times
		);
	}

	/**
	 * Send a request.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param array  $options Options.
	 * @return HttpResponse
	 */
	public function request( $method, $url, array $headers = array(), $body = '', array $options = array() ) {
		$body  = (string) $body;
		$entry = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'length'  => strlen( $body ),
			'options' => $options,
			'range'   => '',
			'fault'   => false,
		);
		foreach ( $this->faults as $index => $fault ) {
			if ( $fault['left'] > 0 && call_user_func( $fault['match'], $method, $url, $headers ) ) {
				--$this->faults[ $index ]['left'];
				$entry['status'] = $fault['response']->status;
				$entry['fault']  = true;
				$this->log[]     = $entry;
				return $fault['response'];
			}
		}
		$response        = $this->handle( $method, $url, $headers, $body );
		$entry['status'] = $response->status;
		$entry['range']  = (string) $response->header( 'range' );
		$this->log[]     = $entry;
		return $response;
	}

	/**
	 * A JSON response.
	 *
	 * @param int   $status  Status.
	 * @param array $data    Data.
	 * @param array $headers Extra headers.
	 * @return HttpResponse
	 */
	public static function json( $status, array $data, array $headers = array() ) {
		return new HttpResponse( $status, array_merge( array( 'Content-Type' => 'application/json; charset=UTF-8' ), $headers ), json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * A Drive API error response.
	 *
	 * @param int    $status  Status.
	 * @param string $reason  Google's error reason.
	 * @param string $message Message.
	 * @return HttpResponse
	 */
	public static function apiError( $status, $reason, $message = 'Error' ) {
		return self::json(
			$status,
			array(
				'error' => array(
					'code'    => $status,
					'message' => $message,
					'errors'  => array(
						array(
							'domain'  => 'global',
							'reason'  => $reason,
							'message' => $message,
						),
					),
				),
			)
		);
	}

	/**
	 * A request that never got an answer.
	 *
	 * @param string $error Transport error.
	 * @return HttpResponse
	 */
	public static function networkError( $error = 'cURL error 7: Failed to connect' ) {
		return HttpResponse::failure( $error );
	}

	/**
	 * Session PUTs as "Content-Range -> status" lines.
	 *
	 * @return string[]
	 */
	public function puts() {
		$lines = array();
		foreach ( $this->log as $entry ) {
			if ( 'PUT' === $entry['method'] && false !== strpos( $entry['url'], self::SESSION_HOST ) ) {
				$lines[] = ( isset( $entry['headers']['Content-Range'] ) ? $entry['headers']['Content-Range'] : '?' ) . ' -> ' . $entry['status'];
			}
		}
		return $lines;
	}

	/**
	 * Number of requests with this method whose URL contains $needle.
	 *
	 * @param string $method Method.
	 * @param string $needle URL part.
	 * @return int
	 */
	public function count( $method, $needle ) {
		$count = 0;
		foreach ( $this->log as $entry ) {
			if ( $method === $entry['method'] && false !== strpos( $entry['url'], $needle ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Number of answers that came from a fault.
	 *
	 * @return int
	 */
	public function faultsServed() {
		$count = 0;
		foreach ( $this->log as $entry ) {
			if ( $entry['fault'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Uploaded files (everything that is not a folder).
	 *
	 * @return array[]
	 */
	public function uploads() {
		$uploads = array();
		foreach ( $this->files as $file ) {
			if ( isset( $file['content'] ) ) {
				$uploads[] = $file;
			}
		}
		return $uploads;
	}

	/**
	 * Folders.
	 *
	 * @return array[]
	 */
	public function folders() {
		$folders = array();
		foreach ( $this->files as $file ) {
			if ( self::FOLDER_MIME === $file['mimeType'] ) {
				$folders[] = $file;
			}
		}
		return $folders;
	}

	/**
	 * The upload id a request was sent to ('' for other requests).
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	public static function uploadId( array $entry ) {
		parse_str( (string) parse_url( $entry['url'], PHP_URL_QUERY ), $query );
		return isset( $query['upload_id'] ) ? (string) $query['upload_id'] : '';
	}

	/**
	 * Answer a request.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @return HttpResponse
	 */
	protected function handle( $method, $url, array $headers, $body ) {
		$parts = parse_url( $url );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );

		if ( 'oauth2.googleapis.com' === $host && '/token' === $path ) {
			return self::json(
				200,
				array(
					'access_token' => 'AT-fresh-' . bin2hex( random_bytes( 6 ) ),
					'expires_in'   => 3600,
					'token_type'   => 'Bearer',
				)
			);
		}
		if ( self::SESSION_HOST === $host ) {
			return 'PUT' === $method ? $this->sessionPut( $query, $headers, $body ) : new HttpResponse( 405, array(), '' );
		}
		if ( 'POST' === $method && '/upload/drive/v3/files' === $path ) {
			$id                    = 'UP' . bin2hex( random_bytes( 6 ) );
			$this->sessions[ $id ] = array(
				'meta'  => json_decode( $body, true ),
				'total' => isset( $headers['X-Upload-Content-Length'] ) ? (int) $headers['X-Upload-Content-Length'] : 0,
				'data'  => '',
				'file'  => null,
			);
			return new HttpResponse( 200, array( 'Location' => 'https://' . self::SESSION_HOST . '/upload/drive/v3/files?uploadType=resumable&upload_id=' . $id ), '' );
		}
		if ( 'GET' === $method && '/drive/v3/about' === $path ) {
			return self::json(
				200,
				array(
					'user'          => array(
						'emailAddress' => 'owner@example.test',
						'displayName'  => 'Owner',
					),
					'storageQuota'  => array(
						'limit' => '16106127360',
						'usage' => '0',
					),
					'maxUploadSize' => '5497558138880',
				)
			);
		}
		if ( 'GET' === $method && '/drive/v3/files' === $path ) {
			return self::json( 200, array( 'files' => $this->search( isset( $query['q'] ) ? (string) $query['q'] : '' ) ) );
		}
		if ( 'POST' === $method && '/drive/v3/files' === $path ) {
			$meta = json_decode( $body, true );
			$id   = $this->create(
				(string) $meta['name'],
				(string) $meta['mimeType'],
				isset( $meta['parents'] ) ? (array) $meta['parents'] : array( 'root' ),
				isset( $meta['appProperties'] ) ? (array) $meta['appProperties'] : array()
			);
			return self::json( 200, $this->resource( $this->files[ $id ] ) );
		}
		if ( preg_match( '#^/drive/v3/files/([^/]+)$#', $path, $match ) ) {
			$id = rawurldecode( $match[1] );
			if ( ! isset( $this->files[ $id ] ) ) {
				return self::apiError( 404, 'notFound', 'File not found: ' . $id . '.' );
			}
			if ( 'DELETE' === $method ) {
				unset( $this->files[ $id ] );
				return new HttpResponse( 204, array(), '' );
			}
			return self::json( 200, $this->resource( $this->files[ $id ] ) );
		}
		return new HttpResponse( 400, array( 'Content-Type' => 'text/plain' ), 'Unhandled ' . $method . ' ' . $path );
	}

	/**
	 * Files matching the two searches the client makes: backup folders
	 * (by appProperties) and the children of a folder.
	 *
	 * @param string $q Search query.
	 * @return array[]
	 */
	protected function search( $q ) {
		$found = array();
		foreach ( $this->files as $file ) {
			if ( false !== strpos( $q, "key='shcm_role'" ) && self::FOLDER_MIME !== $file['mimeType'] ) {
				continue;
			}
			if ( preg_match( "/^'([^']+)' in parents/", $q, $match ) && $file['parents'][0] !== $match[1] ) {
				continue;
			}
			$found[] = $this->resource( $file );
		}
		return $found;
	}

	/**
	 * Create a file.
	 *
	 * @param string      $name       Name.
	 * @param string      $mime       MIME type.
	 * @param array       $parents    Parents.
	 * @param array       $properties appProperties.
	 * @param string|null $content    Content (null for a folder).
	 * @return string Id.
	 */
	protected function create( $name, $mime, array $parents, array $properties, $content = null ) {
		$id                 = 'F' . $this->next_id++;
		$this->files[ $id ] = array(
			'id'            => $id,
			'name'          => $name,
			'mimeType'      => $mime,
			'parents'       => $parents,
			'appProperties' => $properties,
			'createdTime'   => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
		);
		if ( null !== $content ) {
			$this->files[ $id ]['content'] = $content;
		}
		return $id;
	}

	/**
	 * A file as Drive returns it.
	 *
	 * @param array $file File.
	 * @return array
	 */
	protected function resource( array $file ) {
		$resource = array(
			'id'            => $file['id'],
			'name'          => $file['name'],
			'mimeType'      => $file['mimeType'],
			'parents'       => $file['parents'],
			'appProperties' => (object) $file['appProperties'],
			'createdTime'   => $file['createdTime'],
			'trashed'       => false,
			'webViewLink'   => 'https://drive.google.com/file/d/' . $file['id'] . '/view',
		);
		if ( isset( $file['content'] ) ) {
			$resource['size'] = (string) strlen( $file['content'] );
			if ( ! $this->omit_md5 ) {
				$resource['md5Checksum'] = md5( $file['content'] );
			}
			if ( ! $this->omit_sha256 ) {
				$resource['sha256Checksum'] = hash( 'sha256', $file['content'] );
			}
		}
		return $resource;
	}

	/**
	 * A PUT to an upload session: a chunk or a status query.
	 *
	 * @param array  $query   Query parameters.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @return HttpResponse
	 */
	protected function sessionPut( array $query, array $headers, $body ) {
		$id = isset( $query['upload_id'] ) ? (string) $query['upload_id'] : '';
		if ( ! isset( $this->sessions[ $id ] ) ) {
			return new HttpResponse( 404, array( 'Content-Type' => 'text/plain' ), 'Not Found' );
		}
		if ( null !== $this->sessions[ $id ]['file'] ) {
			return self::json( 200, $this->resource( $this->files[ $this->sessions[ $id ]['file'] ] ) );
		}
		$range = isset( $headers['Content-Range'] ) ? (string) $headers['Content-Range'] : '';
		if ( preg_match( '#^bytes \*/\d+$#', $range ) ) {
			return $this->answer( $id );
		}
		if ( ! preg_match( '#^bytes (\d+)-(\d+)/(\d+)$#', $range, $match ) ) {
			return new HttpResponse( 400, array( 'Content-Type' => 'text/plain' ), 'Failed to parse Content-Range header.' );
		}
		$start = (int) $match[1];
		$end   = (int) $match[2];
		$total = (int) $match[3];
		if ( $total !== $this->sessions[ $id ]['total'] || strlen( $body ) !== $end - $start + 1 ) {
			return new HttpResponse( 400, array( 'Content-Type' => 'text/plain' ), 'Content-Range does not match the upload.' );
		}
		if ( $end + 1 !== $total && 0 !== strlen( $body ) % self::UNIT ) {
			return new HttpResponse( 400, array( 'Content-Type' => 'text/plain' ), 'Chunks must be multiples of 256 KiB.' );
		}
		$committed = strlen( $this->sessions[ $id ]['data'] );
		if ( $start <= $committed ) {
			$tail = (string) substr( $body, $committed - $start );
			if ( null !== $this->commit_limit ) {
				$tail = (string) substr( $tail, 0, $this->commit_limit );
			}
			$this->sessions[ $id ]['data'] .= $tail;
		}
		return $this->answer( $id );
	}

	/**
	 * Where a session stands: 308 while incomplete, 200 with the new file once
	 * every byte arrived (after $full_range_answers 308s covering it all).
	 *
	 * @param string $id Upload id.
	 * @return HttpResponse
	 */
	protected function answer( $id ) {
		$session = $this->sessions[ $id ];
		$have    = strlen( $session['data'] );
		if ( $have < $session['total'] ) {
			return new HttpResponse( 308, 0 === $have ? array() : array( 'Range' => 'bytes=0-' . ( $have - 1 ) ), '' );
		}
		if ( $this->full_range_answers > 0 ) {
			--$this->full_range_answers;
			return new HttpResponse( 308, array( 'Range' => 'bytes=0-' . ( $have - 1 ) ), '' );
		}
		$meta = $session['meta'];
		$file = $this->create(
			(string) $meta['name'],
			isset( $meta['mimeType'] ) ? (string) $meta['mimeType'] : 'application/octet-stream',
			(array) $meta['parents'],
			isset( $meta['appProperties'] ) ? (array) $meta['appProperties'] : array(),
			$session['data']
		);

		$this->sessions[ $id ]['file'] = $file;
		return self::json( 200, $this->resource( $this->files[ $file ] ) );
	}
}

/**
 * BackupManager with the rig's services and without the plugin container.
 */
class RigBackups extends BackupManager {

	/**
	 * Redactor of the rig's logger.
	 *
	 * @var Redactor
	 */
	protected $redactor;

	/**
	 * Constructor.
	 *
	 * @param array    $services Services by key (connection, oauth, drive, box, transport, store).
	 * @param Redactor $redactor Redactor of the rig's logger.
	 */
	public function __construct( array $services, Redactor $redactor ) {
		$this->services = $services;
		$this->redactor = $redactor;
	}

	/**
	 * Make the connection's secrets unprintable in the rig's log.
	 *
	 * @return void
	 */
	public function protectSecrets() {
		foreach ( $this->connection()->secrets() as $secret ) {
			$this->redactor->addLiteral( $secret );
		}
	}
}

/**
 * The upload stage with a fixed chunk size (the real one depends on the
 * memory limit), so small archives take several chunks.
 */
class ChunkedUploadStage extends RemoteUploadStage {

	/**
	 * Chunk size in bytes.
	 *
	 * @var int
	 */
	public $chunk_size = DriveEmulator::UNIT;

	/**
	 * Chunk size.
	 *
	 * @return int
	 */
	protected function chunkSize() {
		return $this->chunk_size;
	}
}

/**
 * Resolver over a fixed set of stages.
 */
class UploadStageResolver implements StageResolver {

	/**
	 * Stages by key.
	 *
	 * @var array
	 */
	protected $stages;

	/**
	 * Constructor.
	 *
	 * @param array $stages Stages by key.
	 */
	public function __construct( array $stages ) {
		$this->stages = $stages;
	}

	/**
	 * Resolve.
	 *
	 * @param string $type      Type.
	 * @param string $stage_key Stage key.
	 * @return \SHCM\Jobs\StageInterface|null
	 */
	public function resolve( $type, $stage_key ) {
		unset( $type );
		return isset( $this->stages[ $stage_key ] ) ? $this->stages[ $stage_key ] : null;
	}

	/**
	 * Stage keys.
	 *
	 * @param string $type   Type.
	 * @param array  $params Params.
	 * @return string[]
	 */
	public function stagesFor( $type, array $params = array() ) {
		unset( $type, $params );
		return array_keys( $this->stages );
	}
}

/**
 * A backup job whose only stage uploads a real archive to the emulator,
 * with a connected Google Drive.
 */
class UploadRig {

	const CLIENT_ID = 'upload-rig' . '.apps.googleusercontent.com'; // Split: fake, but scanner-shaped.

	const CLIENT_SECRET = 'CS-secret-upload-rig-4c5d';

	const REFRESH_TOKEN = 'RT-secret-upload-rig-8e9f';

	const ACCESS_TOKEN = 'AT-secret-upload-rig-2a3b';

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	public $dir;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	public $storage;

	/**
	 * Logger (the job's channel).
	 *
	 * @var Logger
	 */
	public $logger;

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	public $connection;

	/**
	 * Google.
	 *
	 * @var DriveEmulator
	 */
	public $drive;

	/**
	 * Stage.
	 *
	 * @var ChunkedUploadStage
	 */
	public $stage;

	/**
	 * Job store (for runs through the JobRunner).
	 *
	 * @var JobStore
	 */
	public $jobs;

	/**
	 * The job.
	 *
	 * @var Job
	 */
	public $job;

	/**
	 * Archive path.
	 *
	 * @var string
	 */
	public $archive;

	/**
	 * Every backoff that was waited out, in seconds.
	 *
	 * @var int[]
	 */
	public $waits = array();

	/**
	 * Build a connected Drive, an archive and a job with the upload stage.
	 *
	 * @param int   $payload Bytes of random content in the archive.
	 * @param array $params  Job parameters over the backup defaults.
	 */
	public function __construct( $payload = 1000000, array $params = array() ) {
		$this->dir     = sys_get_temp_dir() . '/shcm-upload-' . bin2hex( random_bytes( 6 ) );
		$this->storage = new Storage( $this->dir . '/storage' );
		$this->storage->prepare();
		$this->logger = new Logger( $this->storage, 'debug' );

		$box              = new SecretBox( str_repeat( 'k', 40 ) );
		$config           = new ConfigStore( $this->dir . '/config' );
		$this->connection = new Connection( $config, $box, 'upload-rig-fingerprint' );
		$this->connection->onSecret( array( $this->logger->redactor(), 'addLiteral' ) );
		$this->connection->setCredentials( self::CLIENT_ID, self::CLIENT_SECRET );
		$this->connection->storeTokens( self::REFRESH_TOKEN, self::ACCESS_TOKEN, 3600, OAuth::SCOPE );

		$this->drive = new DriveEmulator();
		$oauth       = new OAuth( $this->connection, $this->drive, Endpoints::defaults() );
		$backups     = new RigBackups(
			array(
				'connection' => $this->connection,
				'oauth'      => $oauth,
				'drive'      => new Client( $oauth, $this->drive, Endpoints::defaults() ),
				'box'        => $box,
				'transport'  => $this->drive,
				'store'      => $config,
			),
			$this->logger->redactor()
		);

		$this->stage   = new ChunkedUploadStage( new Settings(), $this->storage, $this->logger, $backups );
		$this->jobs    = new JobStore( $this->storage );
		$this->archive = $this->makeArchive( $payload );
		$this->job     = Job::create(
			'export',
			array_merge(
				array(
					'archive_path' => $this->archive,
					'backup'       => array(
						'kind'   => 'backup',
						'gdrive' => true,
					),
				),
				$params
			),
			array( 'upload' )
		);
		$this->job->setShared( 'archive_sha256', hash_file( 'sha256', $this->archive ) );
		$this->logger->channel( $this->job->id() );
	}

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	public function cleanup() {
		$this->logger->close( $this->job->id() );
		Storage::rmdirRecursive( $this->dir );
	}

	/**
	 * A complete archive (valid footer) with $payload random bytes in it.
	 *
	 * @param int $payload Bytes.
	 * @return string Path.
	 */
	protected function makeArchive( $payload ) {
		$path   = $this->dir . '/backup-' . bin2hex( random_bytes( 4 ) ) . '.wpress';
		$writer = Writer::create( $path, array( 'compress' => false ) );
		$writer->addString( 'manifest.json', '{"rig":1}' );
		if ( $payload > 0 ) {
			$writer->addString( 'files/blob.bin', random_bytes( (int) $payload ) );
		}
		$writer->close();
		$writer->release();
		return $path;
	}

	/**
	 * One request's worth of the stage; a backoff it asks for stays set.
	 *
	 * @param float $seconds Time budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( $seconds = 30 ) {
		return $this->stage->run( $this->job, new Budget( $seconds, 0 ) );
	}

	/**
	 * One request's worth of the stage, then let any backoff pass.
	 *
	 * @param float $seconds Time budget.
	 * @return \SHCM\Core\Result
	 */
	public function tick( $seconds = 30 ) {
		$result = $this->run( $seconds );
		$this->elapse();
		return $result;
	}

	/**
	 * One unit of work (a budget spent on arrival still does one).
	 *
	 * @return \SHCM\Core\Result
	 */
	public function step() {
		return $this->tick( -1 );
	}

	/**
	 * Step until $condition( $state ) holds.
	 *
	 * @param callable $condition Receives the stage state.
	 * @param int      $max       Most steps.
	 * @return void
	 * @throws \LogicException When the condition is not reached.
	 */
	public function stepUntil( callable $condition, $max = 20 ) {
		for ( $i = 0; $i < $max; $i++ ) {
			if ( call_user_func( $condition, $this->state() ) ) {
				return;
			}
			$this->step();
		}
		throw new \LogicException( 'The upload never reached the expected state: ' . json_encode( $this->state() ) );
	}

	/**
	 * Tick until the stage completes.
	 *
	 * @param int $max Most ticks.
	 * @return \SHCM\Core\Result The last result.
	 */
	public function runToEnd( $max = 50 ) {
		$result = null;
		for ( $i = 0; $i < $max; $i++ ) {
			$result = $this->tick();
			$data   = $result->data();
			if ( ! empty( $data['complete'] ) ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Run the job through the real JobRunner until it finishes, letting every
	 * backoff pass between ticks.
	 *
	 * @param int $max Most ticks.
	 * @return Job The job as the runner left it.
	 */
	public function runJob( $max = 30 ) {
		$runner = new JobRunner( $this->jobs, new UploadStageResolver( array( 'upload' => $this->stage ) ), $this->logger, new Settings() );
		$this->jobs->save( $this->job );
		for ( $i = 0; $i < $max && ! $this->job->isFinished(); $i++ ) {
			$job  = $this->jobs->load( $this->job->id() );
			$wait = (int) $job->shared( 'resume_at', 0 );
			if ( $wait > time() ) {
				$this->waits[] = $wait - time();
				$job->setShared( 'resume_at', 0 );
				$this->jobs->save( $job );
			}
			$this->job = $runner->tick( $job, new Budget( 30, 0 ) );
		}
		// The runner logs to the job's channel; keep using it.
		$this->logger->channel( $this->job->id() );
		return $this->job;
	}

	/**
	 * Let a pending backoff pass (recording how long it was).
	 *
	 * @return void
	 */
	public function elapse() {
		$wait = (int) $this->job->shared( 'resume_at', 0 );
		if ( $wait > time() ) {
			$this->waits[] = $wait - time();
		}
		$this->job->setShared( 'resume_at', 0 );
	}

	/**
	 * Seconds until the stage wants to be called again (0: now).
	 *
	 * @return int
	 */
	public function pendingWait() {
		return max( 0, (int) $this->job->shared( 'resume_at', 0 ) - time() );
	}

	/**
	 * Stage state.
	 *
	 * @return array
	 */
	public function state() {
		return (array) $this->job->stageState( 'upload', array() );
	}

	/**
	 * The job's warning messages.
	 *
	 * @return string[]
	 */
	public function warnings() {
		$messages = array();
		foreach ( (array) $this->job->get( 'warnings', array() ) as $warning ) {
			$messages[] = (string) $warning['message'];
		}
		return $messages;
	}

	/**
	 * The job's log so far.
	 *
	 * @return string
	 */
	public function logText() {
		$path = $this->logger->path( $this->job->id() );
		return is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}
}
