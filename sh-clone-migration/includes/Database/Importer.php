<?php
/**
 * Streaming database restore.
 *
 * @package SHCM
 */

namespace SHCM\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Executes a dump statement by statement.
 *
 * Statements are sent straight to the MySQL connection rather than through
 * wpdb::query(), because wpdb rewrites queries it considers unsafe for the
 * current collation - useful for application code, unacceptable for a restore
 * that must reproduce the source bytes exactly.
 */
class Importer {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Prefix rewriter.
	 *
	 * @var PrefixRewriter
	 */
	protected $prefix;

	/**
	 * Statements executed.
	 *
	 * @var int
	 */
	protected $executed = 0;

	/**
	 * Whether the session has been prepared in this request.
	 *
	 * @var bool
	 */
	protected $session_ready = false;

	/**
	 * MySQL error numbers that are safe to ignore during a restore.
	 *
	 * @var int[]
	 */
	protected static $ignorable = array(
		1051, // Unknown table on DROP.
		1091, // Can't drop key/column that does not exist.
		1305, // Routine does not exist.
		1360, // Trigger does not exist.
	);

	/**
	 * Constructor.
	 *
	 * @param \wpdb          $db     Database handle.
	 * @param PrefixRewriter $prefix Prefix rewriter.
	 */
	public function __construct( $db, PrefixRewriter $prefix ) {
		$this->db     = $db;
		$this->prefix = $prefix;
	}

	/**
	 * Prepare the connection for a restore. Must run once per request, because
	 * every request gets a fresh MySQL session.
	 *
	 * @param string $charset Connection charset.
	 * @return void
	 */
	public function prepareSession( $charset = '' ) {
		if ( $this->session_ready ) {
			return;
		}
		$statements = array(
			'SET SESSION sql_mode = "NO_AUTO_VALUE_ON_ZERO"',
			'SET SESSION foreign_key_checks = 0',
			'SET SESSION unique_checks = 0',
		);
		if ( '' !== $charset ) {
			$statements[] = 'SET NAMES ' . $this->quoteString( $charset );
		}
		foreach ( $statements as $sql ) {
			$this->rawQuery( $sql );
		}
		$this->session_ready = true;
	}

	/**
	 * Restore the connection defaults once the restore is finished.
	 *
	 * @return void
	 */
	public function finishSession() {
		$this->rawQuery( 'SET SESSION foreign_key_checks = 1' );
		$this->rawQuery( 'SET SESSION unique_checks = 1' );
		$this->session_ready = false;
	}

	/**
	 * Execute one statement from a dump.
	 *
	 * @param string $sql Statement.
	 * @return bool Whether the statement did anything.
	 * @throws \RuntimeException On an unrecoverable database error.
	 */
	public function execute( $sql ) {
		$sql = trim( $sql );
		if ( '' === $sql ) {
			return false;
		}
		// Skip comment-only fragments.
		$probe = ltrim( $sql );
		while ( '' !== $probe && ( 0 === strpos( $probe, '--' ) || 0 === strpos( $probe, '#' ) ) ) {
			$newline = strpos( $probe, "\n" );
			if ( false === $newline ) {
				return false;
			}
			$probe = ltrim( substr( $probe, $newline + 1 ) );
		}
		if ( '' === $probe ) {
			return false;
		}
		$sql = $probe;

		$sql = $this->prefix->statement( $sql );

		$result = $this->rawQuery( $sql );
		if ( false === $result ) {
			$errno = $this->lastErrorNumber();
			if ( in_array( $errno, self::$ignorable, true ) ) {
				return false;
			}
			// A statement larger than max_allowed_packet is the one failure
			// with an obvious remedy, so say so instead of quoting MySQL.
			if ( in_array( $errno, array( 1153, 2006, 2013 ), true ) ) {
				$packet = $this->maxAllowedPacket();
				throw new \RuntimeException(
					sprintf(
						'A statement of %1$s could not be sent to the database while restoring %2$s. '
						. 'This server accepts at most %3$s per statement (max_allowed_packet). '
						. 'Raise max_allowed_packet on the destination database and resume the migration. '
						. 'MySQL reported: %4$s',
						size_format( strlen( $sql ) ),
						$this->statementTarget( $sql ),
						$packet > 0 ? size_format( $packet ) : 'an unknown amount',
						$this->lastError()
					)
				);
			}

			throw new \RuntimeException(
				sprintf(
					'Database error %1$d while restoring %2$s: %3$s (statement started with: %4$s)',
					$errno,
					$this->statementTarget( $sql ),
					$this->lastError(),
					substr( preg_replace( '/\s+/', ' ', $sql ), 0, 160 )
				)
			);
		}

		++$this->executed;
		return true;
	}

	/**
	 * The table a statement is about, for error messages.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	protected function statementTarget( $sql ) {
		if ( preg_match( '/`((?:[^`]|``)*)`/', $sql, $matches ) ) {
			return $matches[1];
		}
		return 'the database';
	}

	/**
	 * Number of statements executed.
	 *
	 * @return int
	 */
	public function executedCount() {
		return $this->executed;
	}

	/**
	 * Run a query on the raw connection when possible.
	 *
	 * @param string $sql Statement.
	 * @return mixed
	 */
	protected function rawQuery( $sql ) {
		$dbh = isset( $this->db->dbh ) ? $this->db->dbh : null;
		if ( $dbh instanceof \mysqli ) {
			$result = @mysqli_query( $dbh, $sql );
			if ( $result instanceof \mysqli_result ) {
				mysqli_free_result( $result );
				return true;
			}
			return $result;
		}
		// Any drop-in that replaces wpdb without exposing mysqli.
		return $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Last MySQL error number.
	 *
	 * @return int
	 */
	protected function lastErrorNumber() {
		$dbh = isset( $this->db->dbh ) ? $this->db->dbh : null;
		if ( $dbh instanceof \mysqli ) {
			return (int) mysqli_errno( $dbh );
		}
		return 0;
	}

	/**
	 * Last MySQL error message.
	 *
	 * @return string
	 */
	protected function lastError() {
		$dbh = isset( $this->db->dbh ) ? $this->db->dbh : null;
		if ( $dbh instanceof \mysqli ) {
			return (string) mysqli_error( $dbh );
		}
		return (string) $this->db->last_error;
	}

	/**
	 * The server's max_allowed_packet.
	 *
	 * @return int Bytes, 0 when unknown.
	 */
	public function maxAllowedPacket() {
		$row = $this->db->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_N );
		if ( empty( $row[1] ) ) {
			return 0;
		}
		return (int) $row[1];
	}

	/**
	 * Quote a string literal.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected function quoteString( $value ) {
		$dbh = isset( $this->db->dbh ) ? $this->db->dbh : null;
		if ( $dbh instanceof \mysqli ) {
			return "'" . mysqli_real_escape_string( $dbh, $value ) . "'";
		}
		$escaped = $this->db->_real_escape( $value );
		if ( method_exists( $this->db, 'remove_placeholder_escape' ) ) {
			$escaped = $this->db->remove_placeholder_escape( $escaped );
		}
		return "'" . $escaped . "'";
	}

	/**
	 * Drop every table with a given prefix (used by the replace import mode).
	 *
	 * @param string   $prefix Prefix.
	 * @param string[] $keep   Table names to keep.
	 * @return int Number of tables dropped.
	 */
	public function dropTablesWithPrefix( $prefix, array $keep = array() ) {
		$schema = defined( 'DB_NAME' ) ? DB_NAME : (string) $this->db->get_var( 'SELECT DATABASE()' );
		$tables = $this->db->get_results(
			$this->db->prepare(
				'SELECT TABLE_NAME AS name, TABLE_TYPE AS table_type FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
				$schema,
				$this->db->esc_like( $prefix ) . '%'
			),
			ARRAY_A
		);
		$dropped = 0;
		$tables  = is_array( $tables ) ? $tables : array();
		$this->prepareSession();

		// Views must be dropped before the tables they read from.
		usort(
			$tables,
			static function ( $a, $b ) {
				$a_view = 'VIEW' === strtoupper( (string) $a['table_type'] ) ? 0 : 1;
				$b_view = 'VIEW' === strtoupper( (string) $b['table_type'] ) ? 0 : 1;
				return $a_view <=> $b_view;
			}
		);

		foreach ( $tables as $row ) {
			$table = $row['name'];
			if ( in_array( $table, $keep, true ) ) {
				continue;
			}
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			if ( 'VIEW' === strtoupper( (string) $row['table_type'] ) ) {
				$this->rawQuery( 'DROP VIEW IF EXISTS ' . $quoted );
			} else {
				$this->rawQuery( 'DROP TABLE IF EXISTS ' . $quoted );
			}
			++$dropped;
		}
		return $dropped;
	}
}
