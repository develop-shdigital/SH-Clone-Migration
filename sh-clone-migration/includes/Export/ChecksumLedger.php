<?php
/**
 * Archive checksum ledger.
 *
 * @package SHCM
 */

namespace SHCM\Export;

use SHCM\Archive\Format;
use SHCM\Archive\Writer;
use SHCM\Jobs\Job;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Records the digest of every archive entry as it is written.
 *
 * The ledger is an on-disk file rather than job state: an archive of a large
 * site holds hundreds of thousands of entries, and the running summary must
 * stay a fixed 32 bytes no matter how many there are.
 */
class ChecksumLedger {

	/**
	 * Ledger file path.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Write handle.
	 *
	 * @var resource|null
	 */
	protected $handle = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Ledger path.
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Ledger path.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Reset the ledger.
	 *
	 * @return void
	 */
	public function reset() {
		$this->close();
		if ( is_file( $this->path ) ) {
			@unlink( $this->path );
		}
	}

	/**
	 * Record one entry.
	 *
	 * @param Job   $job     Job (holds the running digest).
	 * @param array $summary Entry summary from the writer.
	 * @return void
	 * @throws \RuntimeException When the ledger cannot be written.
	 */
	public function record( Job $job, array $summary ) {
		if ( null === $this->handle ) {
			$dir = dirname( $this->path );
			if ( ! is_dir( $dir ) ) {
				@mkdir( $dir, 0755, true );
			}
			$this->handle = @fopen( $this->path, 'ab' );
			if ( ! $this->handle ) {
				throw new \RuntimeException( sprintf( 'Cannot write the checksum ledger at %s', $this->path ) );
			}
		}

		fwrite(
			$this->handle,
			Json::encode(
				array(
					'p' => $summary['path'],
					'h' => $summary['hash'],
					's' => (string) $summary['size'],
					't' => $summary['type'],
				)
			) . "\n"
		);

		$digest = $job->shared( 'checksum_digest', bin2hex( Format::initialHashState() ) );
		$digest = bin2hex( Format::advanceHash( hex2bin( $digest ), $summary['path'] . '|' . $summary['hash'] ) );
		$job->setShared( 'checksum_digest', $digest );
		$job->setShared( 'checksum_entries', (int) $job->shared( 'checksum_entries', 0 ) + 1 );
	}

	/**
	 * Flush and close the handle.
	 *
	 * @return void
	 */
	public function close() {
		if ( is_resource( $this->handle ) ) {
			fflush( $this->handle );
			fclose( $this->handle );
		}
		$this->handle = null;
	}

	/**
	 * Stream the ledger into the archive as checksums/checksums.json.
	 *
	 * The JSON document is produced incrementally so that a ledger with a
	 * million rows never becomes a million element PHP array.
	 *
	 * @param Writer $writer Archive writer.
	 * @param Job    $job    Job.
	 * @return array Entry summary.
	 * @throws \RuntimeException When the ledger cannot be read.
	 */
	public function writeTo( Writer $writer, Job $job ) {
		$this->close();

		$writer->beginEntry(
			Format::ENTRY_CHECKSUMS,
			array(
				'type'  => Format::TYPE_FILE,
				'group' => 'meta',
				'mtime' => time(),
			)
		);

		$writer->append(
			'{"algorithm":"sha256-chained","block_size":' . (int) $job->param( 'block_size', Format::DEFAULT_BLOCK_SIZE )
			. ',"digest":"' . $job->shared( 'checksum_digest', '' ) . '"'
			. ',"count":' . (int) $job->shared( 'checksum_entries', 0 )
			. ',"entries":['
		);

		if ( is_file( $this->path ) ) {
			$handle = @fopen( $this->path, 'rb' );
			if ( ! $handle ) {
				throw new \RuntimeException( 'Cannot read the checksum ledger.' );
			}
			$first = true;
			while ( ! feof( $handle ) ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$writer->append( ( $first ? '' : ',' ) . $line );
				$first = false;
			}
			fclose( $handle );
		}

		$writer->append( ']}' );

		return $writer->finishEntry();
	}

	/**
	 * Close on destruction.
	 */
	public function __destruct() {
		$this->close();
	}
}
