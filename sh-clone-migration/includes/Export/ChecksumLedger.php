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
	 * @param Job|null $job Job whose committed ledger size is reset too.
	 * @return void
	 */
	public function reset( $job = null ) {
		$this->close();
		if ( is_file( $this->path ) ) {
			@unlink( $this->path );
		}
		if ( $job instanceof Job ) {
			$job->setShared( 'ledger_size', 0 );
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
			$this->handle = @fopen( $this->path, 'c+b' );
			if ( ! $this->handle ) {
				throw new \RuntimeException( sprintf( 'Cannot write the checksum ledger at %s', $this->path ) );
			}
			// Lines written by a request that died before its job state was
			// saved describe entries the archive no longer has (the writer
			// truncates back to its own committed size): drop them too.
			$committed = (int) $job->shared( 'ledger_size', 0 );
			$stat      = fstat( $this->handle );
			if ( isset( $stat['size'] ) && (int) $stat['size'] > $committed ) {
				ftruncate( $this->handle, $committed );
			}
			fseek( $this->handle, 0, SEEK_END );
		}

		$line    = Json::encode(
			array(
				'p' => $summary['path'],
				'h' => $summary['hash'],
				's' => (string) $summary['size'],
				't' => $summary['type'],
			)
		) . "\n";
		$written = fwrite( $this->handle, $line );
		if ( strlen( $line ) !== $written ) {
			throw new \RuntimeException( sprintf( 'Cannot write the checksum ledger at %s (is the disk full?)', $this->path ) );
		}
		$job->setShared( 'ledger_size', (int) $job->shared( 'ledger_size', 0 ) + $written );

		$group             = isset( $summary['group'] ) && '' !== (string) $summary['group'] ? (string) $summary['group'] : 'other';
		$groups            = (array) $job->shared( 'entry_groups', array() );
		$groups[ $group ]  = array(
			'entries' => ( isset( $groups[ $group ]['entries'] ) ? (int) $groups[ $group ]['entries'] : 0 ) + 1,
			'bytes'   => ( isset( $groups[ $group ]['bytes'] ) ? (int) $groups[ $group ]['bytes'] : 0 ) + (int) $summary['size'],
		);
		$job->setShared( 'entry_groups', $groups );

		$digest = $job->shared( 'checksum_digest', bin2hex( Format::initialHashState() ) );
		$item   = Format::ledgerItem(
			Format::LEDGER_SCHEME,
			$summary['path'],
			$summary['type'],
			isset( $summary['target'] ) ? $summary['target'] : '',
			isset( $summary['mode'] ) ? $summary['mode'] : 0644,
			$summary['hash']
		);
		$digest = bin2hex( Format::advanceHash( hex2bin( $digest ), $item ) );
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

		$committed = (int) $job->shared( 'ledger_size', 0 );
		if ( $committed > 0 ) {
			$handle = @fopen( $this->path, 'rb' );
			if ( ! $handle ) {
				throw new \RuntimeException( 'Cannot read the checksum ledger.' );
			}
			// Only the committed part: anything after it was written by a
			// request whose entries the archive no longer holds.
			$read  = 0;
			$first = true;
			while ( $read < $committed && ! feof( $handle ) ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$read += strlen( $line );
				if ( $read > $committed ) {
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
			if ( $read < $committed ) {
				throw new \RuntimeException( 'The checksum ledger is shorter than expected; the export cannot be finalised safely.' );
			}
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
