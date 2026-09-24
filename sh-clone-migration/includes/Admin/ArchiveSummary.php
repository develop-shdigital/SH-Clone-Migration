<?php
/**
 * Human readable archive contents.
 *
 * @package SHCM
 */

namespace SHCM\Admin;

use SHCM\Filesystem\Paths;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Turns what the footer records about an archive into short lines for the
 * admin screens: is the database in it, how much of it, which file groups,
 * and the SHA-256 to compare a downloaded copy against.
 */
class ArchiveSummary {

	/**
	 * Database line.
	 *
	 * @param array $archive Catalog entry.
	 * @return array{text: string, missing: bool}
	 */
	public static function database( array $archive ) {
		// Without a footer the export did not finish: what the manifest
		// planned is not what the archive holds, so nothing is claimed.
		if ( empty( $archive['complete'] ) ) {
			return array(
				'text'    => __( 'Database: unknown, the archive is incomplete', 'sh-clone-migration' ),
				'missing' => true,
			);
		}
		if ( isset( $archive['database_contents'] ) ) {
			$db = $archive['database_contents'];
			if ( empty( $db['included'] ) || 0 === (int) $db['tables'] ) {
				return array(
					'text'    => __( 'Database: NOT included', 'sh-clone-migration' ),
					'missing' => true,
				);
			}
			return array(
				'text'    => sprintf(
					/* translators: 1: tables, 2: rows, 3: SQL size, 4: table prefix */
					__( 'Database: %1$s tables, %2$s rows, %3$s of SQL (prefix %4$s)', 'sh-clone-migration' ),
					number_format_i18n( (int) $db['tables'] ),
					number_format_i18n( (int) $db['rows'] ),
					Bytes::format( (int) $db['sql_bytes'] ),
					'' !== $db['prefix'] ? $db['prefix'] : '—'
				),
				'missing' => false,
			);
		}

		// Archives from version 1.0.0 only record a table count.
		$tables = isset( $archive['tables'] ) ? (int) $archive['tables'] : 0;
		return array(
			'text'    => $tables > 0
				/* translators: %s: number of tables */
				? sprintf( __( 'Database: %s tables', 'sh-clone-migration' ), number_format_i18n( $tables ) )
				: __( 'Database: not recorded', 'sh-clone-migration' ),
			'missing' => 0 === $tables,
		);
	}

	/**
	 * Files line, with the entries per group.
	 *
	 * @param array $archive Catalog entry.
	 * @return string
	 */
	public static function files( array $archive ) {
		if ( empty( $archive['complete'] ) ) {
			return __( 'Files: unknown, the archive is incomplete', 'sh-clone-migration' );
		}
		$line = sprintf(
			/* translators: %s: number of files */
			__( 'Files: %s', 'sh-clone-migration' ),
			number_format_i18n( isset( $archive['files'] ) ? (int) $archive['files'] : 0 )
		);

		if ( ! empty( $archive['groups'] ) && is_array( $archive['groups'] ) ) {
			$parts = array();
			foreach ( Paths::groups() as $group ) {
				if ( isset( $archive['groups'][ $group ] ) ) {
					$parts[] = $group . ' ' . number_format_i18n( (int) $archive['groups'][ $group ]['entries'] );
				}
			}
			foreach ( $archive['groups'] as $group => $counts ) {
				if ( ! in_array( $group, Paths::groups(), true ) && ! in_array( $group, array( 'meta', 'database' ), true ) ) {
					$parts[] = $group . ' ' . number_format_i18n( (int) $counts['entries'] );
				}
			}
			if ( ! empty( $parts ) ) {
				$line .= ' (' . implode( ', ', $parts ) . ')';
			}
		}

		if ( ! empty( $archive['files_skipped'] ) ) {
			$line .= ' — ' . sprintf(
				/* translators: %s: number of files */
				_n( '%s file skipped, see the log', '%s files skipped, see the log', (int) $archive['files_skipped'], 'sh-clone-migration' ),
				number_format_i18n( (int) $archive['files_skipped'] )
			);
		}
		return $line;
	}

	/**
	 * Exact size line.
	 *
	 * @param int $bytes Size.
	 * @return string
	 */
	public static function exactSize( $bytes ) {
		return sprintf(
			/* translators: %s: number of bytes */
			_n( '%s byte', '%s bytes', (int) $bytes, 'sh-clone-migration' ),
			number_format_i18n( (int) $bytes )
		);
	}
}
