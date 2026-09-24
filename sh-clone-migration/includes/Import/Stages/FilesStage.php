<?php
/**
 * Import: file restore.
 *
 * @package SHCM
 */

namespace SHCM\Import\Stages;

use SHCM\Archive\Format;
use SHCM\Archive\Reader;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\SafePath;
use SHCM\Filesystem\Storage;
use SHCM\Import\MaintenanceMode;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Support\Bytes;
use SHCM\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the archived files back onto disk.
 *
 * Every destination path is validated before a byte is written, every entry is
 * checksum verified as it is extracted, and the handful of paths that must
 * never be overwritten (wp-config.php, this plugin, the storage directory) are
 * skipped no matter what the archive claims.
 */
class FilesStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'files';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Restoring files', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 45;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		if ( ! $job->param( 'include_files', true ) ) {
			return $this->complete( __( 'File restore skipped', 'sh-clone-migration' ) );
		}

		$reader = new Reader( $job->param( 'archive_path' ), $job->password() );
		$state  = $job->stageState(
			$this->key(),
			array(
				'entry_offset' => (int) $job->shared( 'files_start_offset', 0 ),
				'entry'        => null,
				'extract'      => array(),
				'target'       => '',
				'files'        => 0,
				'bytes'        => 0,
				'skipped'      => 0,
				'groups'       => array(),
				'done'         => false,
			)
		);

		if ( $state['entry_offset'] <= 0 ) {
			$state['entry_offset'] = $reader->firstEntryOffset();
		}

		$manifest = (array) $job->shared( 'manifest', array() );
		$expected = isset( $manifest['files']['count'] ) ? (int) $manifest['files']['count'] : 0;
		$expected_bytes = isset( $manifest['files']['size'] ) ? (int) $manifest['files']['size'] : 0;

		$reader->seek( $state['entry_offset'] );

		$processed = 0;
		while ( $budget->shouldContinue( $processed ) ) {
			++$processed;

			if ( null === $state['entry'] ) {
				$entry = $reader->nextEntry();
				if ( null === $entry ) {
					$state['done'] = true;
					break;
				}
				$state['entry_offset'] = $entry['header_offset'];

				if ( 0 !== strpos( $entry['path'], Format::ENTRY_FILES ) ) {
					$reader->skipEntry( $entry );
					$state['entry_offset'] = $reader->entryEndOffset( $entry );
					continue;
				}

				$target = $this->resolveTarget( $job, $entry, $state );
				if ( null === $target ) {
					// A deliberately skipped entry (this plugin's own files,
					// wp-config.php, core when core is excluded) still counts
					// as handled, or its group would never reach 100%.
					$this->trackGroup( $state, $entry['group'], 1, (int) $entry['size'] );
					$reader->skipEntry( $entry );
					$state['entry_offset'] = $reader->entryEndOffset( $entry );
					continue;
				}

				if ( Format::TYPE_DIR === $entry['type'] ) {
					$this->makeDirectory( $target, $entry, $job );
					$this->trackGroup( $state, $entry['group'], 1, 0 );
					$state['files']++;
					$state['entry_offset'] = $reader->entryEndOffset( $entry );
					$reader->skipEntry( $entry );
					continue;
				}

				if ( Format::TYPE_LINK === $entry['type'] ) {
					$this->restoreSymlink( $job, $entry, $target, $state );
					$this->trackGroup( $state, $entry['group'], 1, 0 );
					$state['entry_offset'] = $reader->entryEndOffset( $entry );
					$reader->skipEntry( $entry );
					continue;
				}

				$state['entry']   = $entry;
				$state['target']  = $target;
				$state['extract'] = array();
				$this->prepareFile( $target );
			}

			$this->extract( $job, $reader, $state, $budget );
		}

		$job->setStageState( $this->key(), $state );
		$job->setShared( 'files_restored', $state['files'] );
		$job->setShared( 'files_progress', $state['groups'] );

		if ( ! empty( $state['done'] ) ) {
			$this->logger->info(
				sprintf(
					'Files restored: %1$d entries, %2$s (%3$d skipped).',
					$state['files'],
					Bytes::format( $state['bytes'] ),
					$state['skipped']
				)
			);
			return $this->complete(
				sprintf(
					/* translators: 1: file count, 2: size */
					__( '%1$s files restored (%2$s)', 'sh-clone-migration' ),
					number_format_i18n( $state['files'] ),
					Bytes::format( $state['bytes'] )
				)
			);
		}

		$progress = 0.0;
		if ( $expected_bytes > 0 ) {
			$progress = $state['bytes'] / $expected_bytes;
		} elseif ( $expected > 0 ) {
			$progress = $state['files'] / $expected;
		}

		return $this->progress(
			sprintf(
				/* translators: 1: restored, 2: total, 3: size */
				__( 'Restoring files: %1$s of %2$s (%3$s)', 'sh-clone-migration' ),
				number_format_i18n( $state['files'] ),
				number_format_i18n( $expected ),
				Bytes::format( $state['bytes'] )
			),
			min( 0.999, $progress )
		);
	}

	/**
	 * Work out where an entry has to be written, or null when it must be
	 * skipped.
	 *
	 * @param Job   $job   Job.
	 * @param array $entry Entry descriptor.
	 * @param array $state State (by reference).
	 * @return string|null
	 */
	protected function resolveTarget( Job $job, array $entry, array &$state ) {
		$relative = substr( $entry['path'], strlen( Format::ENTRY_FILES ) );
		$slash    = strpos( $relative, '/' );
		if ( false === $slash ) {
			return null;
		}
		$root = substr( $relative, 0, $slash );
		$rest = SafePath::sanitizeRelative( substr( $relative, $slash + 1 ) );
		if ( null === $rest ) {
			$job->addWarning( sprintf( 'Unsafe archive path rejected: %s', $entry['path'] ) );
			$this->logger->warning( sprintf( 'Rejected unsafe archive path: %s', $entry['path'] ) );
			$state['skipped']++;
			return null;
		}

		// Archives made by version 1.0.0 with core files stored wp-content
		// under the core root. Content belongs in the destination's content
		// directory (wherever that is), and "skip core files" must not skip it.
		if ( Paths::ROOT_CORE === $root ) {
			$content_rel = $this->sourceContentPath( $job );
			if ( '' !== $content_rel && 0 === strncmp( $rest, $content_rel . '/', strlen( $content_rel ) + 1 ) ) {
				$root = Paths::ROOT_CONTENT;
				$rest = substr( $rest, strlen( $content_rel ) + 1 );
			} elseif ( $rest === $content_rel ) {
				return null;
			}
		}

		$base = Paths::resolveRoot( $root );
		if ( null === $base ) {
			$job->addWarning( sprintf( 'Archive entry with an unknown root was skipped: %s', $entry['path'] ) );
			$state['skipped']++;
			return null;
		}

		if ( Paths::ROOT_CORE === $root && $job->param( 'skip_core', false ) ) {
			$state['skipped']++;
			return null;
		}

		if ( $this->isProtected( $root, $rest ) ) {
			$state['skipped']++;
			return null;
		}

		$target = SafePath::resolve( $base, $rest );
		if ( null === $target ) {
			$job->addWarning( sprintf( 'Unsafe archive path rejected: %s', $entry['path'] ) );
			$this->logger->warning( sprintf( 'Rejected unsafe archive path: %s', $entry['path'] ) );
			$state['skipped']++;
			return null;
		}
		if ( ! SafePath::isAcceptableName( $rest ) ) {
			$job->addWarning( sprintf( 'Archive path with unusable characters rejected: %s', $entry['path'] ) );
			$state['skipped']++;
			return null;
		}
		if ( SafePath::escapesViaSymlink( $base, $target ) ) {
			$job->addWarning( sprintf( 'Archive path rejected: it resolves outside the installation through a symlink (%s).', $entry['path'] ) );
			$this->logger->warning( sprintf( 'Rejected symlinked path: %s', $entry['path'] ) );
			$state['skipped']++;
			return null;
		}

		return $target;
	}

	/**
	 * The source's content directory relative to its WordPress root
	 * ("wp-content" normally), or '' when it lived elsewhere.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function sourceContentPath( Job $job ) {
		$manifest = (array) $job->shared( 'manifest', array() );
		$abspath  = isset( $manifest['wordpress']['abspath'] ) ? (string) $manifest['wordpress']['abspath'] : '';
		$content  = isset( $manifest['wordpress']['content_dir'] ) ? (string) $manifest['wordpress']['content_dir'] : '';
		if ( '' === $abspath || '' === $content ) {
			return 'wp-content';
		}
		$relative = Paths::relativeTo( $content, $abspath );
		return null === $relative ? '' : $relative;
	}

	/**
	 * Paths that are never overwritten by a restore.
	 *
	 * @param string $root Logical root.
	 * @param string $rest Path relative to the root.
	 * @return bool
	 */
	protected function isProtected( $root, $rest ) {
		$protected_root = array(
			'wp-config.php',
			'wp-config-sample.php',
			'.maintenance',
			'.htaccess',
			'.user.ini',
			'php.ini',
			'web.config',
		);

		// Compared case-insensitively: on Windows and macOS "WP-CONFIG.PHP"
		// is the same file, and over-protecting such a name elsewhere is
		// harmless. $rest is already normalised (no "." or empty segments).
		$rest = strtolower( $rest );
		if ( Paths::ROOT_CORE === $root && in_array( $rest, $protected_root, true ) ) {
			return true;
		}

		// This plugin, and everything it owns, keeps the destination's copy.
		$self_dir = defined( 'SHCM_PLUGIN_BASENAME' ) ? dirname( SHCM_PLUGIN_BASENAME ) : 'sh-clone-migration';
		$prefixes = array();
		if ( Paths::ROOT_CONTENT === $root ) {
			$prefixes[] = Storage::DIR_NAME;
			$prefixes[] = 'plugins/' . $self_dir;
			$prefixes[] = 'maintenance.php';
		} elseif ( Paths::ROOT_PLUGINS === $root ) {
			$prefixes[] = $self_dir;
		} elseif ( Paths::ROOT_CORE === $root ) {
			$prefixes[] = 'wp-content/' . Storage::DIR_NAME;
		}

		foreach ( $prefixes as $prefix ) {
			$prefix = strtolower( $prefix );
			if ( $rest === $prefix || 0 === strncmp( $rest, $prefix . '/', strlen( $prefix ) + 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create the parent directory of a file.
	 *
	 * @param string $target Absolute path.
	 * @return void
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	protected function prepareFile( $target ) {
		// A file entry replaces whatever link stands at its path; writing
		// through a link would put the bytes wherever the link points.
		if ( is_link( $target ) ) {
			@unlink( $target );
		}
		$dir = dirname( $target );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: directory */
					__( 'Cannot create directory %s. Check the filesystem permissions and resume the migration.', 'sh-clone-migration' ),
					$dir
				)
			);
		}
	}

	/**
	 * Create a directory entry.
	 *
	 * @param string $target Absolute path.
	 * @param array  $entry  Entry descriptor.
	 * @param Job    $job    Job.
	 * @return void
	 */
	protected function makeDirectory( $target, array $entry, Job $job ) {
		if ( ! is_dir( $target ) && ! @mkdir( $target, 0755, true ) && ! is_dir( $target ) ) {
			$job->addWarning( sprintf( 'Directory could not be created: %s', $target ) );
			return;
		}
		if ( $entry['mtime'] > 0 ) {
			@touch( $target, $entry['mtime'] );
		}
	}

	/**
	 * Recreate a symlink, but only when it stays inside the installation.
	 *
	 * @param Job    $job    Job.
	 * @param array  $entry  Entry descriptor.
	 * @param string $target Absolute link path.
	 * @param array  $state  State (by reference).
	 * @return void
	 */
	protected function restoreSymlink( Job $job, array $entry, $target, array &$state ) {
		$link_target = (string) $entry['target'];
		if ( '' === $link_target ) {
			$state['skipped']++;
			return;
		}

		$resolved = $link_target;
		if ( '/' !== substr( $link_target, 0, 1 ) ) {
			$resolved = dirname( $target ) . '/' . $link_target;
		}
		// Collapse "..": "uploads/../../../../etc" must not pass a prefix test.
		$resolved = Paths::collapse( $resolved );

		// The kernel resolves a symlinked path component before it applies a
		// later "..", so "s1/../outside" is not what it looks like when s1 is
		// itself a link. Refuse any target that passes through a link.
		$walk = '/' === substr( $link_target, 0, 1 ) ? '' : dirname( $target );
		$parts = explode( '/', str_replace( '\\', '/', $link_target ) );
		array_pop( $parts );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			$walk = '..' === $part ? dirname( '' === $walk ? '/' : $walk ) : $walk . '/' . $part;
			if ( is_link( $walk ) ) {
				$job->addWarning(
					Json::printable(
						sprintf(
							/* translators: 1: link, 2: target */
							__( 'Symlink %1$s was not recreated because its target (%2$s) passes through another symlink.', 'sh-clone-migration' ),
							$entry['path'],
							$link_target
						)
					)
				);
				$state['skipped']++;
				return;
			}
		}

		if ( ! Paths::isInside( $resolved, Paths::abspath() ) && ! Paths::isInside( $resolved, Paths::contentDir() ) ) {
			$job->addWarning(
				Json::printable(
					sprintf(
						/* translators: 1: link, 2: target */
						__( 'Symlink %1$s was not recreated because it points outside the installation (%2$s).', 'sh-clone-migration' ),
						$entry['path'],
						$link_target
					)
				)
			);
			$state['skipped']++;
			return;
		}

		if ( is_link( $target ) || file_exists( $target ) ) {
			$existing = is_link( $target ) ? (string) @readlink( $target ) : '';
			if ( $existing !== $link_target ) {
				$job->addWarning(
					Json::printable(
						sprintf(
							/* translators: 1: link, 2: target */
							__( 'Symlink %1$s (-> %2$s) was not recreated because something already exists at that path on this site.', 'sh-clone-migration' ),
							$entry['path'],
							$link_target
						)
					)
				);
				$state['skipped']++;
			}
			return;
		}
		$this->prepareFile( $target );
		if ( ! @symlink( $link_target, $target ) ) {
			$job->addWarning( Json::printable( sprintf( 'Symlink could not be created: %s', $entry['path'] ) ) );
			$state['skipped']++;
			return;
		}
		$state['files']++;
	}

	/**
	 * Extract (more of) the current entry.
	 *
	 * @param Job    $job    Job.
	 * @param Reader $reader Reader.
	 * @param array  $state  State (by reference).
	 * @param Budget $budget Budget.
	 * @return void
	 */
	protected function extract( Job $job, Reader $reader, array &$state, Budget $budget ) {
		$entry  = $state['entry'];
		$target = $state['target'];

		$mode   = empty( $state['extract'] ) ? 'wb' : 'r+b';
		$handle = @fopen( $target, $mode );
		if ( ! $handle && 'r+b' === $mode ) {
			$handle = @fopen( $target, 'wb' );
			$state['extract'] = array();
		}
		if ( ! $handle ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file path */
					__( 'Cannot write %s. Check the filesystem permissions and resume the migration.', 'sh-clone-migration' ),
					$target
				)
			);
		}

		if ( ! empty( $state['extract']['raw'] ) ) {
			fseek( $handle, (int) $state['extract']['raw'] );
		}

		$before = isset( $state['extract']['raw'] ) ? (int) $state['extract']['raw'] : 0;
		$slice  = $budget->remaining() > 5 ? 16777216 : 4194304;

		try {
			$result = $reader->extractTo( $entry, $handle, $state['extract'], $slice );
		} finally {
			fclose( $handle );
		}

		$written = max( 0, $result['raw'] - $before );
		$state['extract'] = $result;
		$state['bytes']  += $written;
		$this->trackGroup( $state, $entry['group'], 0, $written );

		if ( empty( $result['done'] ) ) {
			return;
		}

		if ( '' !== $entry['hash'] && $result['hash'] !== $entry['hash'] ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file path */
					__( 'Checksum mismatch while restoring %s: the archive is corrupted. Nothing further has been written.', 'sh-clone-migration' ),
					$entry['path']
				)
			);
		}

		if ( $entry['mtime'] > 0 ) {
			@touch( $target, $entry['mtime'] );
		}
		if ( $entry['mode'] > 0 ) {
			@chmod( $target, $this->safeMode( $entry['mode'] ) );
		}

		$this->trackGroup( $state, $entry['group'], 1, 0 );

		$state['files']++;
		$state['entry_offset'] = $reader->entryEndOffset( $entry );
		$state['entry']        = null;
		$state['extract']      = array();
		$state['target']       = '';
	}

	/**
	 * Track per-group progress for the UI.
	 *
	 * @param array  $state State (by reference).
	 * @param string $group Group.
	 * @param int    $files Files to add.
	 * @param int    $bytes Bytes to add.
	 * @return void
	 */
	protected function trackGroup( array &$state, $group, $files, $bytes ) {
		if ( '' === $group ) {
			$group = 'other';
		}
		if ( ! isset( $state['groups'][ $group ] ) ) {
			$state['groups'][ $group ] = array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		$state['groups'][ $group ]['files'] += $files;
		$state['groups'][ $group ]['bytes'] += $bytes;
	}

	/**
	 * Clamp a restored file mode to something sane.
	 *
	 * @param int $mode Mode from the archive.
	 * @return int
	 */
	protected function safeMode( $mode ) {
		$mode &= 0777;
		// Never restore setuid/setgid/sticky, and never write a world writable
		// file even if the source had one.
		$mode &= ~0002;
		if ( 0 === ( $mode & 0400 ) ) {
			$mode |= 0600;
		}
		return $mode;
	}

	/**
	 * Keep maintenance mode from lingering after a failure.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		if ( null !== $error && $job->shared( 'maintenance' ) ) {
			MaintenanceMode::disable();
			$job->setShared( 'maintenance', false );
		}
	}
}
