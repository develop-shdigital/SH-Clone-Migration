<?php
/**
 * Database read failure.
 *
 * @package SHCM
 */

namespace SHCM\Database;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Thrown when the server reports an error for a query the dump depends on.
 *
 * Kept distinct so that the export can retry a transient failure (a lock
 * wait timeout, a dropped connection) instead of recording a short table.
 */
class DatabaseReadException extends \RuntimeException {
}
