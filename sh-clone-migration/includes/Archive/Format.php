<?php
/**
 * .wpress archive format definition.
 *
 * @package SHCM
 */

namespace SHCM\Archive;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Binary layout of an SH Clone Migration .wpress archive (format version 1).
 *
 * The container is a single forward-only stream so that it can be written and
 * read without ever holding more than one block in memory, and so that a
 * request that dies half way through can be resumed by truncating back to the
 * last committed offset.
 *
 *   MAGIC                    8 bytes  "SHCMWPRS"
 *   prologue length          4 bytes  uint32 big endian
 *   prologue                 N bytes  JSON, always unencrypted
 *   entries                  repeated:
 *       header length        4 bytes  uint32 big endian (0 terminates the list)
 *       header               N bytes  JSON, always unencrypted
 *       payload              blocks, see below
 *   end of entries           4 bytes  0x00000000
 *   footer length            4 bytes  uint32 big endian
 *   footer                   N bytes  JSON
 *   footer pointer           8 bytes  uint64 big endian, absolute offset of the
 *                                     "footer length" field
 *   END MAGIC                8 bytes  "SHCMEND1"
 *
 * A payload is a sequence of independently coded blocks:
 *
 *       flags                1 byte   bit0 = deflate, bit1 = encrypted
 *       stored length        4 bytes  uint32 big endian, bytes on disk
 *       raw length           4 bytes  uint32 big endian, bytes after decoding
 *       data                 stored length bytes
 *
 * Because every block stands on its own, a reader can skip an entry with a
 * single fseek per block, resume decoding at any block boundary, and a writer
 * can stop after any block and continue in the next request.
 *
 * Entry integrity uses a chained SHA-256 over the raw blocks:
 *
 *       h(0) = 32 zero bytes
 *       h(i) = sha256( h(i-1) || raw_block_i )
 *
 * The chain is resumable (its whole state is 32 bytes), which a plain
 * hash-the-whole-file digest is not once an entry spans several requests.
 */
class Format {

	const MAGIC         = 'SHCMWPRS';
	const MAGIC_END     = 'SHCMEND1';
	const VERSION       = 1;
	const EXTENSION     = 'wpress';

	const TYPE_FILE     = 'f';
	const TYPE_DIR      = 'd';
	const TYPE_LINK     = 'l';

	const FLAG_DEFLATE  = 1;
	const FLAG_ENCRYPT  = 2;

	const SIZE_FIELD_WIDTH = 20;
	const HASH_WIDTH       = 64;

	const DEFAULT_BLOCK_SIZE = 1048576;
	const MAX_BLOCK_SIZE     = 33554432;

	/** Reserved logical entry paths. */
	const ENTRY_MANIFEST  = 'manifest.json';
	const ENTRY_CHECKSUMS = 'checksums/checksums.json';
	const ENTRY_CONFIG    = 'config/metadata.json';
	const ENTRY_DB_META   = 'database/metadata.json';
	const ENTRY_DB_TABLES = 'database/tables/';
	const ENTRY_DB_VIEWS  = 'database/views.sql';
	const ENTRY_DB_ROUTINES = 'database/routines.sql';
	const ENTRY_FILES     = 'files/';

	/**
	 * The ledger scheme new archives use: see ledgerItem().
	 */
	const LEDGER_SCHEME = 2;

	/**
	 * What the footer's chained digest covers for one entry.
	 *
	 * Scheme 1 (version 1.0.0) chains "path|hash", which leaves the entry's
	 * type, link target and mode unprotected: one flipped bit could turn a
	 * file into an empty directory and still verify. Scheme 2 chains
	 * "path|type|target|mode|hash".
	 *
	 * @param int    $scheme Scheme.
	 * @param string $path   Entry path.
	 * @param string $type   Entry type.
	 * @param string $target Link target.
	 * @param int    $mode   Permission bits.
	 * @param string $hash   Entry digest (hex).
	 * @return string
	 */
	public static function ledgerItem( $scheme, $path, $type, $target, $mode, $hash ) {
		if ( (int) $scheme < 2 ) {
			return $path . '|' . $hash;
		}
		return $path . '|' . $type . '|' . $target . '|' . sprintf( '%04o', (int) $mode & 0777 ) . '|' . $hash;
	}

	/**
	 * Archive entry path of a table dump.
	 *
	 * Names that are not plain [A-Za-z0-9_-] get a hash suffix, so two
	 * tables whose names sanitise alike never share an entry.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	public static function tableEntryPath( $table ) {
		$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '_', (string) $table );
		if ( $safe !== $table ) {
			$safe = ( '' === trim( $safe, '_' ) ? 'table' : $safe ) . '-' . substr( md5( (string) $table ), 0, 8 );
		}
		return self::ENTRY_DB_TABLES . $safe . '.sql';
	}

	/**
	 * Entry path archives from version 1.0.0 used for a table dump.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	public static function legacyTableEntryPath( $table ) {
		$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '_', (string) $table );
		return self::ENTRY_DB_TABLES . ( '' === $safe ? 'table_' . md5( (string) $table ) : $safe ) . '.sql';
	}

	/**
	 * Empty chained-hash state.
	 *
	 * @return string 32 raw bytes.
	 */
	public static function initialHashState() {
		return str_repeat( "\0", 32 );
	}

	/**
	 * Advance the chained hash with one raw block.
	 *
	 * @param string $state Current 32 byte state.
	 * @param string $block Raw block data.
	 * @return string New 32 byte state.
	 */
	public static function advanceHash( $state, $block ) {
		return hash( 'sha256', $state . $block, true );
	}

	/**
	 * Chained digest of a complete in-memory string, using a given block size.
	 *
	 * @param string $data       Raw data.
	 * @param int    $block_size Block size.
	 * @return string Hex digest.
	 */
	public static function hashString( $data, $block_size = self::DEFAULT_BLOCK_SIZE ) {
		$state  = self::initialHashState();
		$length = strlen( $data );
		if ( 0 === $length ) {
			return bin2hex( $state );
		}
		for ( $offset = 0; $offset < $length; $offset += $block_size ) {
			$state = self::advanceHash( $state, substr( $data, $offset, $block_size ) );
		}
		return bin2hex( $state );
	}
}
