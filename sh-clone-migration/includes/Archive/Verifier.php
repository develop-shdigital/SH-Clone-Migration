<?php
/**
 * Archive verification.
 *
 * @package SHCM
 */

namespace SHCM\Archive;

use SHCM\Jobs\Budget;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Checks that an archive is complete and internally consistent.
 *
 * Verification recomputes the chained digest of every entry from the stored
 * blocks, so it detects truncation, bit rot and tampering rather than just a
 * missing end marker.
 */
class Verifier {

	/**
	 * Ledger scheme of the archive being verified (null until known).
	 *
	 * @var int|null
	 */
	protected $scheme = null;

	/**
	 * Reader.
	 *
	 * @var Reader
	 */
	protected $reader;

	/**
	 * Constructor.
	 *
	 * @param Reader $reader Reader.
	 */
	public function __construct( Reader $reader ) {
		$this->reader = $reader;
	}

	/**
	 * Structural checks that do not need a full pass over the data.
	 *
	 * @return array{ok:bool,errors:string[],manifest:array|null,footer:array|null}
	 */
	public function structure() {
		$errors = array();

		$footer = $this->reader->footer();
		if ( null === $footer ) {
			$errors[] = __( 'The archive has no valid end marker: it is incomplete or was truncated during transfer.', 'sh-clone-migration' );
		}

		$manifest = null;
		try {
			$entry = $this->reader->findEntry( Format::ENTRY_MANIFEST );
			if ( null === $entry ) {
				$errors[] = __( 'The archive contains no manifest.', 'sh-clone-migration' );
			} else {
				$manifest = Json::decode( $this->reader->readString( $entry ) );
				if ( null === $manifest ) {
					$errors[] = __( 'The archive manifest could not be parsed.', 'sh-clone-migration' );
				}
			}
		} catch ( \Exception $e ) {
			$errors[] = sprintf(
				/* translators: %s: error message */
				__( 'The archive manifest could not be read: %s', 'sh-clone-migration' ),
				$e->getMessage()
			);
		}

		return array(
			'ok'       => empty( $errors ),
			'errors'   => $errors,
			'manifest' => $manifest,
			'footer'   => $footer,
		);
	}

	/**
	 * Fresh state for a full verification pass.
	 *
	 * @return array
	 */
	public function initialState() {
		return array(
			'offset'  => $this->reader->firstEntryOffset(),
			'checked' => 0,
			'bytes'   => 0,
			'digest'  => bin2hex( Format::initialHashState() ),
			'errors'  => array(),
			'done'    => false,
		);
	}

	/**
	 * Verify entries until the budget runs out.
	 *
	 * @param array       $state  State.
	 * @param Budget|null $budget Budget, null to run to completion.
	 * @return array Updated state.
	 */
	public function verifyEntries( array $state, ?Budget $budget = null ) {
		$this->reader->seek( $state['offset'] );
		$units = 0;

		while ( true ) {
			// At least one unit of work per call, whatever the budget says:
			// a request that starts with its budget already spent (memory
			// close to the guard, a slow key derivation) must still move on.
			if ( $budget && ! $budget->shouldContinue( $units ) ) {
				break;
			}

			if ( ! empty( $state['current'] ) ) {
				// Part way through a large entry from the previous request.
				$this->reader->seek( (int) $state['current']['header'] );
				$entry    = $this->reader->nextEntry();
				$position = (int) $state['current']['pos'];
				$hash     = hex2bin( $state['current']['hash'] );
				$size     = (int) $state['current']['size'];
			} else {
				$entry = $this->reader->nextEntry();
				if ( null === $entry ) {
					$state['done'] = true;
					break;
				}
				$position = $entry['payload_offset'];
				$hash     = Format::initialHashState();
				$size     = 0;
			}

			$end      = $this->reader->entryEndOffset( $entry );
			$finished = true;
			foreach ( $this->reader->blocksFromOffset( $entry, $position ) as $block ) {
				$hash  = Format::advanceHash( $hash, $block );
				$size += strlen( $block );
				++$units;
				if ( $budget && $this->reader->tell() < $end && ! $budget->shouldContinue( $units ) ) {
					// A multi-gigabyte entry does not have to fit in one request.
					$state['current'] = array(
						'header' => $entry['header_offset'],
						'pos'    => $this->reader->tell(),
						'hash'   => bin2hex( $hash ),
						'size'   => $size,
					);
					$state['offset']  = $entry['header_offset'];
					$finished         = false;
					break;
				}
			}
			if ( ! $finished ) {
				break;
			}
			unset( $state['current'] );
			++$units;

			// A directory or link has no payload, and only a link has a
			// target: anything else is a damaged header.
			if ( Format::TYPE_FILE !== $entry['type'] && ( 0 !== (int) $entry['size'] || 0 !== (int) $entry['stored'] ) ) {
				$state['errors'][] = sprintf(
					/* translators: %s: entry path */
					__( 'Damaged entry header for %s.', 'sh-clone-migration' ),
					\SHCM\Support\Json::printable( $entry['path'] )
				);
			}
			if ( ! in_array( $entry['type'], array( Format::TYPE_FILE, Format::TYPE_DIR, Format::TYPE_LINK ), true )
				|| ( Format::TYPE_LINK !== $entry['type'] && '' !== (string) $entry['target'] ) ) {
				$state['errors'][] = sprintf(
					/* translators: %s: entry path */
					__( 'Damaged entry header for %s.', 'sh-clone-migration' ),
					\SHCM\Support\Json::printable( $entry['path'] )
				);
			}

			$digest = bin2hex( $hash );
			if ( '' !== $entry['hash'] && $digest !== $entry['hash'] ) {
				$state['errors'][] = sprintf(
					/* translators: %s: entry path */
					__( 'Checksum mismatch for %s.', 'sh-clone-migration' ),
					\SHCM\Support\Json::printable( $entry['path'] )
				);
			}
			if ( $size !== (int) $entry['size'] ) {
				$state['errors'][] = sprintf(
					/* translators: %s: entry path */
					__( 'Size mismatch for %s.', 'sh-clone-migration' ),
					\SHCM\Support\Json::printable( $entry['path'] )
				);
			}

			$state['digest'] = bin2hex(
				Format::advanceHash(
					hex2bin( $state['digest'] ),
					Format::ledgerItem( $this->ledgerScheme(), $entry['path'], $entry['type'], (string) $entry['target'], (int) $entry['mode'], $entry['hash'] )
				)
			);
			$state['checked']++;
			$state['bytes'] += $size;
			$state['offset'] = $this->reader->entryEndOffset( $entry );
			$this->reader->seek( $state['offset'] );

			if ( count( $state['errors'] ) > 50 ) {
				$state['done'] = true;
				break;
			}
		}

		if ( ! empty( $state['done'] ) ) {
			$footer = $this->reader->footer();
			if ( is_array( $footer ) && ! empty( $footer['checksum_digest'] ) ) {
				if ( $footer['checksum_digest'] !== $state['digest'] ) {
					$state['errors'][] = __( 'The archive digest does not match its contents: the archive is incomplete or has been modified.', 'sh-clone-migration' );
				}
			}
		}

		return $state;
	}

	/**
	 * Which ledger scheme the footer's digest was built with.
	 *
	 * @return int
	 */
	protected function ledgerScheme() {
		if ( null === $this->scheme ) {
			$footer       = $this->reader->footer();
			$this->scheme = is_array( $footer ) && isset( $footer['checksum_scheme'] ) ? (int) $footer['checksum_scheme'] : 1;
		}
		return $this->scheme;
	}

	/**
	 * Run a complete verification in one go (WP-CLI and small archives).
	 *
	 * @return array{ok:bool,errors:string[],checked:int,bytes:int}
	 */
	public function verifyAll() {
		$structure = $this->structure();
		$state     = $this->initialState();

		try {
			$state = $this->verifyEntries( $state, null );
		} catch ( \Exception $e ) {
			$state['errors'][] = $e->getMessage();
		}

		$errors = array_merge( $structure['errors'], $state['errors'] );

		return array(
			'ok'       => empty( $errors ),
			'errors'   => $errors,
			'checked'  => $state['checked'],
			'bytes'    => $state['bytes'],
			'manifest' => $structure['manifest'],
		);
	}

	/**
	 * Total number of entries recorded in the footer, for progress reporting.
	 *
	 * @return int
	 */
	public function expectedEntries() {
		$footer = $this->reader->footer();
		return is_array( $footer ) && isset( $footer['entries'] ) ? (int) $footer['entries'] : 0;
	}
}
