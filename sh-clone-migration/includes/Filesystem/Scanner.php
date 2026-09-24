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
 *
 * Symlinks are resolved rather than recorded blindly. A link standing where
 * another migration root is (a deploy-style wp-content/uploads ->
 * shared/uploads) is skipped here because that root is walked on its own. A
 * link whose target is inside a root is kept as a link. Any other directory
 * link is followed and its contents archived under the link's path; every
 * directory in a followed tree remembers the chain of links that led to it,
 * so a link back into that chain is kept as a link instead of looping. A link
 * that would pull in a parent of the site (/, a home directory) or a system
 * directory (/proc, /sys, /dev) is reported and skipped. Nothing is dropped
 * without a warning.
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
	 * Migration roots: name => array( path, real ).
	 *
	 * @var array[]
	 */
	protected $roots = array();

	/**
	 * Directory links followed so far, across the whole scan.
	 */
	const MAX_FOLLOWED = 10000;

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
	 * Tell the scanner which roots exist, so that a directory (or a symlink)
	 * that is itself another root is walked only once.
	 *
	 * @param array<string,string> $roots Logical root name => absolute path.
	 * @return self
	 */
	public function roots( array $roots ) {
		$this->roots = array();
		foreach ( $roots as $name => $path ) {
			$real                 = @realpath( $path );
			$this->roots[ $name ] = array(
				'path' => Paths::normalize( $path ),
				'real' => false === $real ? Paths::normalize( $path ) : Paths::normalize( $real ),
			);
		}
		return $this;
	}

	/**
	 * Seed the directory queue with the migration roots.
	 *
	 * @param array<string,string> $roots Logical root name => absolute path.
	 * @return void
	 */
	public function seed( array $roots ) {
		if ( empty( $this->roots ) ) {
			$this->roots( $roots );
		}
		foreach ( $roots as $name => $path ) {
			if ( ! is_dir( $path ) ) {
				$this->warn( sprintf( 'Directory %1$s (%2$s) does not exist or is not readable, so it is not in the archive.', $path, $name ) );
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
				'totals'     => array(),
			),
			$state
		);
		$state['totals'] = array_merge(
			array(
				'files'    => 0,
				'bytes'    => 0,
				'dirs'     => 0,
				'skipped'  => 0,
				'excluded' => 0,
				'links'    => 0,
				'followed' => 0,
				'groups'   => array(),
			),
			(array) $state['totals']
		);

		// A request that died mid-directory appended entries that its saved
		// state does not know about; replaying the directory would add them
		// a second time. Cut both queues back to what was committed.
		if ( isset( $state['files_size'] ) ) {
			$this->files->truncate( (int) $state['files_size'] );
		}
		if ( isset( $state['dirs_size'] ) ) {
			$this->directories->truncate( (int) $state['dirs_size'] );
		}

		$this->directories->openReader( $state['dir_offset'] );

		$processed = 0;

		// Finish a very large directory that the previous request had to leave
		// part way through.
		if ( ! empty( $state['partial'] ) ) {
			$partial = $state['partial'];
			unset( $state['partial'] );
			if ( ! $this->scanDirectory( $partial['item'], $state, $budget, $partial ) ) {
				return $this->commit( $state );
			}
			$state['totals']['dirs']++;
			++$processed;
		}

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
				if ( isset( $stalled ) && $stalled === $state['dir_offset'] ) {
					throw new \RuntimeException( 'The directory queue ends in an incomplete line; the scan cannot continue safely.' );
				}
				$stalled = $state['dir_offset'];
				$this->directories->openReader( $state['dir_offset'] );
				continue;
			}
			unset( $stalled );

			$complete            = $this->scanDirectory( $item, $state, $budget );
			$state['dir_offset'] = $this->directories->tell();
			if ( ! $complete ) {
				break;
			}
			$state['totals']['dirs']++;
			++$processed;
		}

		return $this->commit( $state );
	}

	/**
	 * Flush the queues and record the sizes this state corresponds to.
	 *
	 * @param array $state State.
	 * @return array
	 */
	protected function commit( array $state ) {
		$this->files->flush();
		$this->directories->flush();

		$state['files_size'] = $this->files->size();
		$state['dirs_size']  = $this->directories->size();

		return $state;
	}

	/**
	 * Scan a single directory, queueing its children.
	 *
	 * A directory with hundreds of thousands of entries (uploads without
	 * year/month folders) can take longer than one request allows. When the
	 * budget runs out part way, the rest of the listing is spooled to a file
	 * and the next request continues from a byte offset in it, so nothing
	 * depends on readdir() returning the same order twice (it does not when
	 * files are added or deleted in between).
	 *
	 * @param array       $item    Directory queue item.
	 * @param array       $state   State (by reference).
	 * @param Budget|null $budget  Budget.
	 * @param array|null  $partial Saved position inside this directory.
	 * @return bool False when the directory was left part way through.
	 * @throws \RuntimeException When the queue item is corrupt.
	 */
	protected function scanDirectory( array $item, array &$state, $budget = null, $partial = null ) {
		if ( ! isset( $item['base'], $item['root'] ) || ! is_string( $item['base'] ) || ! isset( $item['rel'] ) || ! is_string( $item['rel'] ) ) {
			throw new \RuntimeException( 'The directory queue holds a corrupt entry; the scan cannot continue safely.' );
		}
		$relative = $item['rel'];
		$root     = $item['root'];
		$absolute = '' === $relative ? $item['base'] : $item['base'] . '/' . $relative;
		$children = is_array( $partial ) && isset( $partial['children'] ) ? (int) $partial['children'] : 0;
		$handled  = 0;
		$spool    = new FileQueue( $this->files->path() . '.partial' );

		if ( is_array( $partial ) && isset( $partial['offset'] ) ) {
			// Continue with the listing an earlier request spooled.
			$spool->openReader( (int) $partial['offset'] );
			while ( true ) {
				if ( null !== $budget && $handled > 0 && 0 === $handled % 500 && $budget->expired() ) {
					$state['partial'] = array(
						'item'     => $item,
						'offset'   => $spool->tell(),
						'children' => $children,
					);
					$spool->closeReader();
					return false;
				}
				$line = $spool->next();
				if ( null === $line ) {
					break;
				}
				++$handled;
				$this->scanChild( $item, (string) $line['n'], $state, $children );
			}
			$spool->delete();
		} else {
			$handle = @opendir( $absolute );
			if ( ! $handle ) {
				$this->warn( sprintf( 'Directory could not be read and was skipped: %s', $absolute ) );
				$state['totals']['skipped']++;
				return true;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( null !== $budget && $handled > 0 && 0 === $handled % 500 && $budget->expired() ) {
					$spool->delete();
					$spool->push( array( 'n' => $entry ) );
					while ( false !== ( $rest = readdir( $handle ) ) ) {
						if ( '.' !== $rest && '..' !== $rest ) {
							$spool->push( array( 'n' => $rest ) );
						}
					}
					$spool->closeWriter();
					closedir( $handle );
					$state['partial'] = array(
						'item'     => $item,
						'offset'   => 0,
						'children' => $children,
					);
					return false;
				}
				++$handled;
				$this->scanChild( $item, $entry, $state, $children );
			}
			closedir( $handle );
		}

		// Preserve empty directories so the restored tree matches the source.
		if ( 0 === $children && '' !== $relative ) {
			$group = Paths::group( $root, $relative );
			$this->files->push(
				array(
					'root'  => $root,
					'rel'   => $relative,
					'type'  => 'd',
					'size'  => 0,
					'mtime' => (int) @filemtime( $absolute ),
					'mode'  => (int) @fileperms( $absolute ),
					'group' => $group,
				)
			);
			$state['totals']['files']++;
			$this->countGroup( $state, $group, 0 );
		}
		return true;
	}

	/**
	 * Handle one directory entry.
	 *
	 * @param array  $item     The directory's queue item.
	 * @param string $entry    Entry name.
	 * @param array  $state    State (by reference).
	 * @param int    $children Entries counted in this directory (by reference).
	 * @return void
	 */
	protected function scanChild( array $item, $entry, array &$state, &$children ) {
		$relative  = $item['rel'];
		$root      = $item['root'];
		$base      = $item['base'];
		$absolute  = '' === $relative ? $base : $base . '/' . $relative;
		$child_rel = '' === $relative ? $entry : $relative . '/' . $entry;
		$child_abs = $absolute . '/' . $entry;

		if ( $this->isBlocked( $child_abs ) ) {
			return;
		}
		if ( $this->exclusions->matchesAny( $this->exclusionPaths( $root, $child_rel ) ) ) {
			$state['totals']['excluded']++;
			return;
		}

		// Another root lives here (wp-content inside a core walk, uploads
		// inside wp-content when uploads is its own root): walked separately.
		if ( null !== $this->otherRootAt( $child_abs, $root ) ) {
			++$children;
			return;
		}

		++$children;

		if ( is_link( $child_abs ) ) {
			$this->handleLink( $item, $child_rel, $child_abs, $state );
			return;
		}

		if ( is_dir( $child_abs ) ) {
			$child = array(
				'root' => $root,
				'base' => $base,
				'rel'  => $child_rel,
			);
			if ( ! empty( $item['chain'] ) ) {
				$child['chain'] = $item['chain'];
			}
			$this->directories->push( $child );
			return;
		}

		$this->queueFile( $root, $child_rel, $child_abs, $state );
	}

	/**
	 * Queue a regular file (or the target of a followed file link).
	 *
	 * @param string $root      Logical root.
	 * @param string $child_rel Path relative to the root.
	 * @param string $child_abs Absolute path.
	 * @param array  $state     State (by reference).
	 * @return void
	 */
	protected function queueFile( $root, $child_rel, $child_abs, array &$state ) {
		if ( ! is_file( $child_abs ) ) {
			return; // Sockets, fifos and other things a website does not need.
		}
		if ( ! is_readable( $child_abs ) ) {
			$this->warn( sprintf( 'File is not readable and was skipped: %s', $child_abs ) );
			$state['totals']['skipped']++;
			return;
		}

		$size = (int) @filesize( $child_abs );
		if ( $this->max_file_size > 0 && $size > $this->max_file_size ) {
			$this->warn( sprintf( 'File exceeds the configured size limit and was skipped: %s', $child_abs ) );
			$state['totals']['skipped']++;
			return;
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
		$this->countGroup( $state, $group, $size );
	}

	/**
	 * Decide what to do with a symlink.
	 *
	 * @param array  $item      The containing directory's queue item.
	 * @param string $child_rel Path relative to the root.
	 * @param string $child_abs Absolute path of the link.
	 * @param array  $state     State (by reference).
	 * @return void
	 */
	protected function handleLink( array $item, $child_rel, $child_abs, array &$state ) {
		$root   = $item['root'];
		$chain  = isset( $item['chain'] ) ? (array) $item['chain'] : array();
		$target = (string) @readlink( $child_abs );
		$real   = @realpath( $child_abs );

		// Dangling, or a target we may not resolve: keep the link itself.
		if ( false === $real ) {
			$this->recordLink( $root, $child_rel, $child_abs, $target, $state );
			return;
		}
		$real = Paths::normalize( $real );

		if ( $this->isSystemPath( $real ) ) {
			$this->warn( sprintf( 'Symlink %1$s points at %2$s, a system directory; it was not followed and its contents are not in the archive.', $child_abs, $real ) );
			$state['totals']['skipped']++;
			return;
		}

		// Inside something that is archived anyway: a link reproduces it.
		if ( $this->insideRoot( $real ) ) {
			$this->recordLink( $root, $child_rel, $child_abs, $target, $state );
			return;
		}

		if ( ! is_dir( $real ) ) {
			// A file link to something outside the site: archive the content.
			$state['totals']['followed']++;
			$this->queueFile( $root, $child_rel, $child_abs, $state );
			return;
		}

		if ( $this->containsRoot( $real ) ) {
			// Following would pull in the site itself, or everything above it.
			$this->warn( sprintf( 'Symlink %1$s points at %2$s, a directory that contains the site itself; it was not followed and its contents are not in the archive.', $child_abs, $real ) );
			$state['totals']['skipped']++;
			return;
		}

		// A link back to a directory this branch is already inside of (or
		// above it) would loop: keep it as a link.
		foreach ( $chain as $seen ) {
			if ( Paths::isInside( $seen, $real ) ) {
				$this->recordLink( $root, $child_rel, $child_abs, $target, $state );
				return;
			}
		}

		if ( ! is_readable( $real ) ) {
			$this->warn( sprintf( 'Symlinked directory %1$s -> %2$s is not readable and was skipped.', $child_abs, $real ) );
			$state['totals']['skipped']++;
			return;
		}
		if ( (int) $state['totals']['followed'] >= self::MAX_FOLLOWED ) {
			$this->warn( sprintf( 'Symlink %1$s -> %2$s was kept as a link: more than %3$d symlinked directories were followed already.', $child_abs, $real, self::MAX_FOLLOWED ) );
			$this->recordLink( $root, $child_rel, $child_abs, $target, $state );
			return;
		}

		// Archive the contents as ordinary files under the link's path.
		$state['totals']['followed']++;
		$chain[] = $real;
		$this->directories->push(
			array(
				'root'  => $root,
				'base'  => $item['base'],
				'rel'   => $child_rel,
				'chain' => $chain,
			)
		);
	}

	/**
	 * Queue a symlink entry.
	 *
	 * @param string $root      Logical root.
	 * @param string $child_rel Path relative to the root.
	 * @param string $child_abs Absolute path of the link.
	 * @param string $target    Link target as stored in the link.
	 * @param array  $state     State (by reference).
	 * @return void
	 */
	protected function recordLink( $root, $child_rel, $child_abs, $target, array &$state ) {
		$group = Paths::group( $root, $child_rel );
		$this->files->push(
			array(
				'root'   => $root,
				'rel'    => $child_rel,
				'type'   => 'l',
				'size'   => 0,
				'mtime'  => (int) @filemtime( $child_abs ),
				'mode'   => 0777,
				'target' => $target,
				'group'  => $group,
			)
		);
		$state['totals']['files']++;
		$state['totals']['links']++;
		$this->countGroup( $state, $group, 0 );
	}

	/**
	 * Name of a root (other than $current) that stands at an absolute path:
	 * the same path, or the same real directory reached without a link. A
	 * link elsewhere that merely resolves to a root (wp-content/blogs.dir ->
	 * uploads) is not the root and is handled as a link.
	 *
	 * @param string $absolute Absolute path.
	 * @param string $current  Root being walked.
	 * @return string|null
	 */
	protected function otherRootAt( $absolute, $current ) {
		if ( empty( $this->roots ) ) {
			return null;
		}
		$path = Paths::normalize( $absolute );
		$real = null;
		foreach ( $this->roots as $name => $root ) {
			if ( $name === $current ) {
				continue;
			}
			if ( $path === $root['path'] ) {
				return $name;
			}
			if ( null === $real ) {
				$real = '';
				if ( ! is_link( $absolute ) && is_dir( $absolute ) ) {
					$resolved = @realpath( $absolute );
					$real     = false === $resolved ? '' : Paths::normalize( $resolved );
				}
			}
			if ( '' !== $real && $real === $root['real'] ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Whether a real path lies inside a root.
	 *
	 * @param string $real Real path.
	 * @return bool
	 */
	protected function insideRoot( $real ) {
		foreach ( $this->roots as $root ) {
			if ( Paths::isInside( $real, $root['real'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a real path contains a root.
	 *
	 * @param string $real Real path.
	 * @return bool
	 */
	protected function containsRoot( $real ) {
		foreach ( $this->roots as $root ) {
			if ( Paths::isInside( $root['real'], $real ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pseudo filesystems no website keeps content in.
	 *
	 * @param string $real Real path.
	 * @return bool
	 */
	protected function isSystemPath( $real ) {
		foreach ( array( '/proc', '/sys', '/dev', '/run' ) as $system ) {
			if ( Paths::isInside( $real, $system ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count one archive entry against its reporting group.
	 *
	 * Directories and symlinks are counted as well as files, so the totals the
	 * scan produces match the entries an import will actually process and the
	 * progress bars land on exactly 100%.
	 *
	 * @param array  $state State (by reference).
	 * @param string $group Group.
	 * @param int    $bytes Bytes.
	 * @return void
	 */
	protected function countGroup( array &$state, $group, $bytes ) {
		if ( '' === $group ) {
			$group = 'other';
		}
		if ( ! isset( $state['totals']['groups'][ $group ] ) ) {
			$state['totals']['groups'][ $group ] = array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		$state['totals']['groups'][ $group ]['files']++;
		$state['totals']['groups'][ $group ]['bytes'] += $bytes;
	}

	/**
	 * Paths used for exclusion matching.
	 *
	 * Patterns are written relative to the WordPress root ("wp-content/cache"),
	 * whatever the physical layout. A separate uploads, plugins or mu-plugins
	 * root is therefore matched under its wp-content alias as well as under
	 * its own name, so "wp-content/uploads/backups" works either way.
	 *
	 * @param string $root     Logical root.
	 * @param string $relative Relative path.
	 * @return string[]
	 */
	protected function exclusionPaths( $root, $relative ) {
		switch ( $root ) {
			case Paths::ROOT_CONTENT:
				return array( 'wp-content/' . $relative );
			case Paths::ROOT_CORE:
				return array( $relative );
			case Paths::ROOT_PLUGINS:
			case Paths::ROOT_MU_PLUGINS:
			case Paths::ROOT_UPLOADS:
				return array( 'wp-content/' . $root . '/' . $relative, $root . '/' . $relative );
		}
		return array( $root . '/' . $relative );
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
	 * Record a warning.
	 *
	 * Not capped: every skipped path has to reach the job log, which is the
	 * only place that names it. The list lives for one scan call, so it is
	 * bounded by the time budget of a single request.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected function warn( $message ) {
		$this->warnings[] = $message;
	}
}
