<?php
/**
 * Resumable whole-file SHA-256.
 *
 * @package SHCM
 */

namespace SHCM\Archive;

use SHCM\Jobs\Budget;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Computes the SHA-256 of a file in slices across as many requests as it
 * takes, so the digest of a multi-gigabyte archive can be produced on a host
 * that allows 30 seconds per request.
 *
 * The digest is the same number `sha256sum`, `shasum -a 256` and PowerShell's
 * `Get-FileHash` print, so a downloaded copy can be checked with standard
 * tools. Between requests the hash context is kept serialised (PHP 8+); on
 * PHP builds that cannot serialise it, the file is hashed in one call.
 */
class FileDigest {

	/**
	 * Bytes hashed between budget checks.
	 */
	const SLICE = 8388608;

	/**
	 * Continue hashing.
	 *
	 * @param string    $path      File.
	 * @param array     $state     State from the previous call (empty to start).
	 * @param Budget    $budget    Budget.
	 * @param bool|null $resumable Whether the hash context can be saved between
	 *                             calls; null detects it (tests force false).
	 * @return array State; 'digest' is set once the whole file is hashed.
	 * @throws \RuntimeException When the file cannot be read or changes size.
	 */
	public static function advance( $path, array $state, Budget $budget, $resumable = null ) {
		clearstatcache( true, $path );
		$size = (int) @filesize( $path );

		if ( empty( $state ) ) {
			$state = array(
				'offset'   => 0,
				'size'     => $size,
				'context'  => '',
				'attempts' => 0,
			);
		}
		if ( $size !== (int) $state['size'] ) {
			throw new \RuntimeException( 'The archive changed size while its SHA-256 was being computed.' );
		}

		if ( null === $resumable ) {
			$resumable = self::resumable();
		}
		if ( ! $resumable ) {
			if ( empty( $state['announced'] ) ) {
				// Report progress once before the long call.
				$state['announced'] = true;
				return $state;
			}
			// One shot. The attempt is counted in a file written before the
			// call, not in the job state (which is saved only after it), so
			// a host that kills the request part way is not retried forever.
			$marker   = $path . '.sha256-attempt';
			$attempts = (int) @file_get_contents( $marker ) + 1;
			if ( $attempts > 2 ) {
				@unlink( $marker );
				$state['unavailable'] = true;
				return $state;
			}
			@file_put_contents( $marker, (string) $attempts );
			$digest = hash_file( 'sha256', $path );
			@unlink( $marker );
			if ( false === $digest ) {
				throw new \RuntimeException( 'The archive could not be read to compute its SHA-256.' );
			}
			$state['digest'] = $digest;
			$state['offset'] = $size;
			return $state;
		}

		$context = '' === $state['context'] ? hash_init( 'sha256' ) : unserialize( base64_decode( $state['context'] ), array( 'allowed_classes' => array( 'HashContext' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( ! $context instanceof \HashContext ) {
			throw new \RuntimeException( 'The saved SHA-256 state could not be restored.' );
		}

		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'The archive could not be opened to compute its SHA-256.' );
		}
		if ( $state['offset'] > 0 && 0 !== fseek( $handle, (int) $state['offset'] ) ) {
			fclose( $handle );
			throw new \RuntimeException( 'The archive could not be read to compute its SHA-256.' );
		}

		$slices = 0;
		while ( $state['offset'] < $size && $budget->shouldContinue( $slices ) ) {
			++$slices;
			$want = (int) min( self::SLICE, $size - $state['offset'] );
			$read = hash_update_stream( $context, $handle, $want );
			if ( $read <= 0 ) {
				fclose( $handle );
				throw new \RuntimeException( 'The archive could not be read to compute its SHA-256.' );
			}
			$state['offset'] += $read;
		}
		fclose( $handle );

		if ( $state['offset'] >= $size ) {
			$state['digest']  = hash_final( $context );
			$state['context'] = '';
			return $state;
		}

		$state['context'] = base64_encode( serialize( $context ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return $state;
	}

	/**
	 * Whether a hash context survives serialisation on this PHP build.
	 *
	 * @return bool
	 */
	public static function resumable() {
		static $resumable = null;
		if ( null === $resumable ) {
			try {
				$context = hash_init( 'sha256' );
				hash_update( $context, 'a' );
				$copy = unserialize( serialize( $context ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				$resumable = $copy instanceof \HashContext && hash_final( $copy ) === hash( 'sha256', 'a' );
			} catch ( \Throwable $e ) {
				$resumable = false;
			}
		}
		return $resumable;
	}
}
