<?php
/**
 * Backup history.
 *
 * @package SHCM
 */

namespace SHCM\Backup;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * What every backup run did, kept in config/history.php.
 *
 * Job files are purged after the retention period and the options table is
 * replaced by imports, so neither can serve as the record of which archives
 * are backups, where their copies are and which Drive files may be pruned.
 */
final class History {

	const DOCUMENT = 'history';
	const MAX      = 100;

	/**
	 * Store.
	 *
	 * @var ConfigStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param ConfigStore $store Store.
	 */
	public function __construct( ConfigStore $store ) {
		$this->store = $store;
	}

	/**
	 * Insert an entry, or merge fields into an existing one.
	 *
	 * Nested arrays (local, remote) are merged key by key.
	 *
	 * @param string $id     Entry id (the job id).
	 * @param array  $fields Fields.
	 * @return array The entry as stored.
	 */
	public function record( $id, array $fields ) {
		$id    = (string) $id;
		$saved = array();
		$this->store->update(
			self::DOCUMENT,
			function ( array $data ) use ( $id, $fields, &$saved ) {
				$entries = isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : array();
				$found   = null;
				foreach ( $entries as $index => $entry ) {
					if ( isset( $entry['id'] ) && $entry['id'] === $id ) {
						$found = $index;
						break;
					}
				}
				if ( null === $found ) {
					$entry = array_merge( array( 'id' => $id, 'created' => time() ), $fields );
					// The id is the lookup key: a field named "id" must not change it.
					$entry['id'] = $id;
					array_unshift( $entries, $entry );
				} else {
					$entry = $entries[ $found ];
					foreach ( $fields as $key => $value ) {
						if ( is_array( $value ) && isset( $entry[ $key ] ) && is_array( $entry[ $key ] ) ) {
							$entry[ $key ] = array_merge( $entry[ $key ], $value );
						} else {
							$entry[ $key ] = $value;
						}
					}
					$entry['id']       = $id;
					$entries[ $found ] = $entry;
				}
				$saved           = $entry;
				$data['entries'] = array_slice( $entries, 0, self::MAX );
				return $data;
			}
		);
		return $saved;
	}

	/**
	 * One entry.
	 *
	 * @param string $id Entry id.
	 * @return array|null
	 */
	public function get( $id ) {
		foreach ( $this->entries() as $entry ) {
			if ( isset( $entry['id'] ) && (string) $entry['id'] === (string) $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Newest entries first.
	 *
	 * @param int $limit Maximum entries.
	 * @return array[]
	 */
	public function all( $limit = 50 ) {
		return array_slice( $this->entries(), 0, max( 1, (int) $limit ) );
	}

	/**
	 * The entry that produced an archive.
	 *
	 * @param string $archive Archive base name.
	 * @return array|null
	 */
	public function forArchive( $archive ) {
		foreach ( $this->entries() as $entry ) {
			if ( isset( $entry['archive'] ) && (string) $entry['archive'] === (string) $archive ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Base names of the archives produced by backup runs (not manual exports
	 * or archives sent to Drive by hand), newest first.
	 *
	 * Only entries whose kind is exactly "backup" count. Retention deletes
	 * what this returns, so an entry without a kind (for example one
	 * recreated by a late update after it fell out of the capped list) must
	 * not make its archive prunable.
	 *
	 * @return string[]
	 */
	public function archiveNames() {
		$names = array();
		foreach ( $this->entries() as $entry ) {
			if ( empty( $entry['archive'] ) || ! isset( $entry['kind'] ) || 'backup' !== $entry['kind'] ) {
				continue;
			}
			$names[] = (string) $entry['archive'];
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Base names of every archive the history mentions, whatever the kind.
	 *
	 * For code that must keep its hands off archives the backup feature
	 * knows about (the max_archives housekeeping), where listing too many is
	 * the safe mistake; retention uses archiveNames().
	 *
	 * @return string[]
	 */
	public function recordedArchives() {
		$names = array();
		foreach ( $this->entries() as $entry ) {
			if ( ! empty( $entry['archive'] ) ) {
				$names[] = (string) $entry['archive'];
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The most recent entry with one of the given statuses.
	 *
	 * @param string[] $statuses Statuses.
	 * @return array|null
	 */
	public function latest( array $statuses = array() ) {
		foreach ( $this->entries() as $entry ) {
			if ( empty( $statuses ) || ( isset( $entry['status'] ) && in_array( $entry['status'], $statuses, true ) ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Remove every entry.
	 *
	 * @return void
	 */
	public function clear() {
		$this->store->delete( self::DOCUMENT );
	}

	/**
	 * Stored entries.
	 *
	 * @return array[]
	 */
	private function entries() {
		$data = $this->store->read( self::DOCUMENT );
		return isset( $data['entries'] ) && is_array( $data['entries'] ) ? array_values( $data['entries'] ) : array();
	}
}
