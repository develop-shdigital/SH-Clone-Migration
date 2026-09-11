<?php
/**
 * Resumable filesystem scanner.
 *
 * @package SHCM
 */

namespace SHCM\Filesystem;

use SHCM\Jobs\Budget;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Walks the migration roots breadth first, writing every file it finds into a
 * work queue.
 *
 * Directories still to visit live in a second on-disk queue rather than in the
 * job state, so the walk survives both request timeouts and pathologically
 * wide directory trees.
 */
class Scanner {

	/**
	 * File queue.
	 *
	 * @var FileQueue
	 */
	protected $files;

	/**
	 * Directory queue.
	 *
	 * @var FileQueue
	 */
	protected $directories;

	/**
	 * Exclusion matcher.
	 *
	 * @var ExclusionMatcher
	 */
	protected $exclusions;

	/**
	 * Absolute paths never to descend into.
	 *
	 * @var string[]
	 */
	protected $blocked = array();

	/**
	 * Maximum file size, 0 for unlimited.
	 *
	 * @var int
	 */
	protected $max_file_size = 0;

	/**
	 * Whether to follow symlinked directories.
	 *
	 * @var bool
	 */
	protected $follow_symlinks = false;

	/**
	 * Warnings collected during the walk.
	 *
	 * @var string[]
	 */
	protected $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param FileQueue        $files       File queue.
	 * @param FileQueue        $directories Directory queue.
	 * @param ExclusionMatcher $exclusions  Exclusions.
	 */
	public function __construct( FileQueue $files, FileQueue $directories, ExclusionMatcher $exclusions ) {
		$this->files       = $files;
		$this->directories = $directories;
		$this->exclusions  = $exclusions;
	}

	/**
	 * Block absolute paths (the plugin storage directory, for example).
	 *
	 * @param string[] $paths Absolute paths.
	 * @return self
	 */
	public function block( array $paths ) {
		foreach ( $paths as $path ) {
			$path = Paths::normalize( $path );
			if ( '' !== $path ) {
				$this->blocked[] = $path;
			}
		}
		return $this;
	}

	/**
	 * Set a maximum file size.
	 *
	 * @param int $bytes Bytes, 0 for unlimited.
	 * @return self
	 */
	public function maxFileSize( $bytes ) {
		$this->max_file_size = max( 0, (int) $bytes );
		return $this;
	}

	/**
	 * Warnings collected so far.
	 *
	 * @return string[]
	 */
	public function warnings() {
		return $this->warnings;
	}

	/**
	 * Seed the directory queue with the migration roots.
	 *
	 * @param array<string,string> $roots Logical root name => absolute path.
	 * @return void
	 */
	public function seed( array $roots ) {
		foreach ( $roots as $name => $path ) {
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$this->directories->push(
				array(
					'root' => $name,
					'base' => $path,
					'rel'  => '',
				)
			);
		}
		$this->directories->closeWriter();
	}

	/**
	 * Process directories until the budget runs out.
	 *
	 * @param array  $state  Scanner state: dir_offset, totals.
	 * @param Budget $budget Budget.
	 * @return array Updated state.
	 */
	public function scan( array $state, Budget $budget ) {
		$state = array_merge(
			array(
				'dir_offset' => 0,
				'done'       => false,
				'totals'     => array(
					'files'   => 0,
					'bytes'   => 0,
					'dirs'    => 0,
					'skipped' => 0,
					'groups'  => array(),
				),
			),
			$state
		);

		$this->directories->openReader( $state['dir_offset'] );

		$processed = 0;
		while ( $budget->shouldContinue( $processed ) ) {
			$item = $this->directories->next();
			if ( null === $item ) {
				$state['dir_offset'] = $this->directories->tell();
				// The producer may have appended more directories while we
				// were reading; only stop when the file has not grown.
				$this->directories->closeWriter();
				if ( $state['dir_offset'] >= $this->directories->size() ) {
					$state['done'] = true;
					break;
				}
				$this->directories->openReader( $state['dir_offset'] );
				continue;
			}

			$this->scanDirectory( $item, $state );
			$state['dir_offset'] = $this->directories->tell();
			$state['totals']['dirs']++;
			++$processed;
		}

		$this->files->flush();
		$this->directories->flush();

		return $state;
	}

	/**
	 * Scan a single directory, queueing its children.
	 *
	 * @param array $item  Directory queue item.
	 * @param array $state State (by reference).
	 * @return void
	 */
	protected function scanDirectory( array $item, array &$state ) {
		$base     = $item['base'];
		$relative = $item['rel'];
		$root     = $item['root'];
		$absolute = '' === $relative ? $base : $base . '/' . $relative;

		$handle = @opendir( $absolute );
		if ( ! $handle ) {
			$this->warn( sprintf( 'Directory could not be read and was skipped: %s', $absolute ) );
			$state['totals']['skipped']++;
			return;
		}

		$children = 0;
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$child_rel = '' === $relative ? $entry : $relative . '/' . $entry;
			$child_abs = $absolute . '/' . $entry;

			if ( $this->isBlocked( $child_abs ) ) {
				continue;
			}
			if ( $this->exclusions->matches( $this->exclusionPath( $root, $child_rel ) ) ) {
				continue;
			}

			++$children;

			$is_link = is_link( $child_abs );
			if ( $is_link ) {
				$target = @readlink( $child_abs );
				$this->files->push(
					array(
						'root'   => $root,
						'rel'    => $child_rel,
						'type'   => 'l',
						'size'   => 0,
						'mtime'  => (int) @filemtime( $child_abs ),
						'mode'   => 0777,
						'target' => (string) $target,
						'group'  => Paths::group( $root, $child_rel ),
					)
				);
				$state['totals']['files']++;
				continue;
			}

			if ( is_dir( $child_abs ) ) {
				$this->directories->push(
					array(
						'root' => $root,
						'base' => $base,
						'rel'  => $child_rel,
					)
				);
				continue;
			}

			if ( ! is_file( $child_abs ) ) {
				continue; // Sockets, fifos and other things a website does not need.
			}
			if ( ! is_readable( $child_abs ) ) {
				$this->warn( sprintf( 'File is not readable and was skipped: %s', $child_abs ) );
				$state['totals']['skipped']++;
				continue;
			}

			$size = (int) @filesize( $child_abs );
			if ( $this->max_file_size > 0 && $size > $this->max_file_size ) {
				$this->warn( sprintf( 'File exceeds the configured size limit and was skipped: %s', $child_abs ) );
				$state['totals']['skipped']++;
				continue;
			}

			$group = Paths::group( $root, $child_rel );
			$this->files->push(
				array(
					'root'  => $root,
					'rel'   => $child_rel,
					'type'  => 'f',
					'size'  => $size,
					'mtime' => (int) @filemtime( $child_abs ),
					'mode'  => (int) @fileperms( $child_abs ),
					'group' => $group,
				)
			);

			$state['totals']['files']++;
			$state['totals']['bytes'] += $size;
			if ( ! isset( $state['totals']['groups'][ $group ] ) ) {
				$state['totals']['groups'][ $group ] = array(
					'files' => 0,
					'bytes' => 0,
				);
			}
			$state['totals']['groups'][ $group ]['files']++;
			$state['totals']['groups'][ $group ]['bytes'] += $size;
		}
		closedir( $handle );

		// Preserve empty directories so the restored tree matches the source.
		if ( 0 === $children && '' !== $relative ) {
			$this->files->push(
				array(
					'root'  => $root,
					'rel'   => $relative,
					'type'  => 'd',
					'size'  => 0,
					'mtime' => (int) @filemtime( $absolute ),
					'mode'  => (int) @fileperms( $absolute ),
					'group' => Paths::group( $root, $relative ),
				)
			);
		}
	}

	/**
	 * Path used for exclusion matching.
	 *
	 * Content-relative paths are matched with their wp-content/ prefix so that
	 * the familiar "wp-content/cache" style patterns work regardless of where
	 * the content directory physically lives.
	 *
	 * @param string $root     Logical root.
	 * @param string $relative Relative path.
	 * @return string
	 */
	protected function exclusionPath( $root, $relative ) {
		if ( Paths::ROOT_CONTENT === $root ) {
			return 'wp-content/' . $relative;
		}
		if ( Paths::ROOT_CORE === $root ) {
			return $relative;
		}
		return $root . '/' . $relative;
	}

	/**
	 * Whether a path is in the blocked list.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	protected function isBlocked( $path ) {
		$path = Paths::normalize( $path );
		foreach ( $this->blocked as $blocked ) {
			if ( $path === $blocked || Paths::isInside( $path, $blocked ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record a warning, keeping the list bounded.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected function warn( $message ) {
		if ( count( $this->warnings ) < 200 ) {
			$this->warnings[] = $message;
		}
	}
}
