<?php
/**
 * Incremental SQL statement splitter.
 *
 * @package SHCM
 */

namespace SHCM\Database;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Splits a stream of SQL text into individual statements without ever holding
 * the whole dump in memory.
 *
 * Text is pushed in at arbitrary chunk boundaries and complete statements are
 * pulled out. The scanner keeps its position and quoting state between calls,
 * so every byte of a multi-gigabyte dump is examined exactly once and a ";"
 * inside a string literal or a comment never splits a statement.
 */
class SqlStreamReader {

	const STATE_NORMAL        = 0;
	const STATE_QUOTE         = 1;
	const STATE_LINE_COMMENT  = 2;
	const STATE_BLOCK_COMMENT = 3;

	/**
	 * Pending text.
	 *
	 * @var string
	 */
	protected $buffer = '';

	/**
	 * How far the scanner has looked into the buffer.
	 *
	 * @var int
	 */
	protected $position = 0;

	/**
	 * Scanner state.
	 *
	 * @var int
	 */
	protected $state = self::STATE_NORMAL;

	/**
	 * Active quote character.
	 *
	 * @var string
	 */
	protected $quote = '';

	/**
	 * Whether the previous character was a backslash inside a string.
	 *
	 * @var bool
	 */
	protected $escaped = false;

	/**
	 * Push a chunk of SQL text.
	 *
	 * @param string $chunk Chunk.
	 * @return void
	 */
	public function feed( $chunk ) {
		if ( '' === $chunk ) {
			return;
		}
		$this->buffer .= $chunk;
	}

	/**
	 * Bytes still buffered.
	 *
	 * @return int
	 */
	public function bufferedBytes() {
		return strlen( $this->buffer );
	}

	/**
	 * Whether anything meaningful is still buffered.
	 *
	 * @return bool
	 */
	public function hasBuffer() {
		return '' !== trim( $this->buffer );
	}

	/**
	 * Pull the next complete statement.
	 *
	 * @return string|null Statement without its trailing semicolon.
	 */
	public function next() {
		$length = strlen( $this->buffer );

		while ( $this->position < $length ) {
			$before_position = $this->position;
			$before_state    = $this->state;

			switch ( $this->state ) {
				case self::STATE_QUOTE:
					$this->scanQuote( $length );
					break;

				case self::STATE_LINE_COMMENT:
					$end = strpos( $this->buffer, "\n", $this->position );
					if ( false === $end ) {
						$this->position = $length;
					} else {
						$this->position = $end + 1;
						$this->state    = self::STATE_NORMAL;
					}
					break;

				case self::STATE_BLOCK_COMMENT:
					$end = strpos( $this->buffer, '*/', $this->position );
					if ( false === $end ) {
						// Stop one byte short: a trailing "*" may be the first
						// half of the terminator, arriving in the next chunk.
						$this->position = max( $this->position, $length - 1 );
					} else {
						$this->position = $end + 2;
						$this->state    = self::STATE_NORMAL;
					}
					break;

				default:
					$statement = $this->scanNormal( $length );
					if ( null !== $statement ) {
						return $statement;
					}
					break;
			}

			if ( $this->position === $before_position && $this->state === $before_state ) {
				// Nothing more can be decided until the next chunk arrives
				// (a trailing "/" or quote at the very end of the buffer).
				return null;
			}
		}

		return null;
	}

	/**
	 * Scan outside of strings and comments.
	 *
	 * @param int $length Buffer length.
	 * @return string|null Statement when a terminator was reached.
	 */
	protected function scanNormal( $length ) {
		while ( $this->position < $length ) {
			$skip = strcspn( $this->buffer, ";'\"`/-#", $this->position );
			$this->position += $skip;
			if ( $this->position >= $length ) {
				return null;
			}

			$char = $this->buffer[ $this->position ];

			if ( ';' === $char ) {
				$statement      = substr( $this->buffer, 0, $this->position );
				$this->buffer   = (string) substr( $this->buffer, $this->position + 1 );
				$this->position = 0;
				$statement      = trim( $statement );
				return '' === $statement ? '' : $statement;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$this->state   = self::STATE_QUOTE;
				$this->quote   = $char;
				$this->escaped = false;
				++$this->position;
				return null;
			}

			if ( '#' === $char ) {
				$this->state = self::STATE_LINE_COMMENT;
				return null;
			}

			if ( '-' === $char ) {
				if ( $this->position + 1 < $length && '-' === $this->buffer[ $this->position + 1 ] ) {
					$this->state = self::STATE_LINE_COMMENT;
					return null;
				}
				++$this->position;
				continue;
			}

			if ( '/' === $char ) {
				if ( $this->position + 1 >= $length ) {
					// Cannot tell yet whether a block comment starts here.
					return null;
				}
				if ( '*' === $this->buffer[ $this->position + 1 ] ) {
					$this->position += 2;
					$this->state     = self::STATE_BLOCK_COMMENT;
					return null;
				}
				++$this->position;
				continue;
			}

			++$this->position;
		}

		return null;
	}

	/**
	 * Scan inside a quoted section.
	 *
	 * @param int $length Buffer length.
	 * @return void
	 */
	protected function scanQuote( $length ) {
		$quote = $this->quote;

		while ( $this->position < $length ) {
			if ( $this->escaped ) {
				$this->escaped = false;
				++$this->position;
				continue;
			}

			$skip = strcspn( $this->buffer, '\\' . $quote, $this->position );
			$this->position += $skip;
			if ( $this->position >= $length ) {
				return;
			}

			$char = $this->buffer[ $this->position ];

			if ( '\\' === $char && '`' !== $quote ) {
				$this->escaped = true;
				++$this->position;
				continue;
			}

			if ( $char === $quote ) {
				if ( $this->position + 1 >= $length ) {
					// A doubled quote may continue in the next chunk.
					return;
				}
				if ( $this->buffer[ $this->position + 1 ] === $quote ) {
					$this->position += 2;
					continue;
				}
				++$this->position;
				$this->state = self::STATE_NORMAL;
				$this->quote = '';
				return;
			}

			++$this->position;
		}
	}

	/**
	 * Flush whatever is left, for dumps whose last statement has no semicolon.
	 *
	 * @return string|null
	 */
	public function flush() {
		$statement      = trim( $this->buffer );
		$this->buffer   = '';
		$this->position = 0;
		$this->state    = self::STATE_NORMAL;
		return '' === $statement ? null : $statement;
	}

	/**
	 * Reset the scanner completely.
	 *
	 * @return void
	 */
	public function reset() {
		$this->buffer   = '';
		$this->position = 0;
		$this->state    = self::STATE_NORMAL;
		$this->quote    = '';
		$this->escaped  = false;
	}
}
