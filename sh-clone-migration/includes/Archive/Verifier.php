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

		while ( true ) {
			if ( $budget && $budget->expired() ) {
				break;
			}

			$entry = $this->reader->nextEntry();
			if ( null === $entry ) {
				$state['done'] = true;
				break;
			}

			$hash = Format::initialHashState();
			$size = 0;
			foreach ( $this->reader->blocks( $entry ) as $block ) {
				$hash  = Format::advanceHash( $hash, $block );
				$size += strlen( $block );
			}

			$digest = bin2hex( $hash );
			if ( '' !== $entry['hash'] && $digest !== $entry['hash'] ) {
				$state['errors'][] = sprintf(
					/* translators: %s: entry path */
					__( 'Checksum mismatch for %s.', 'sh-clone-migration' ),
					$entry['path']
				);
			}
			if ( $size !== (int) $entry['size'] ) {
				$state['errors'][] = sprintf(
					/* translators: %s: entry path */
					__( 'Size mismatch for %s.', 'sh-clone-migration' ),
					$entry['path']
				);
			}

			$state['digest'] = bin2hex(
				Format::advanceHash( hex2bin( $state['digest'] ), $entry['path'] . '|' . $entry['hash'] )
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
