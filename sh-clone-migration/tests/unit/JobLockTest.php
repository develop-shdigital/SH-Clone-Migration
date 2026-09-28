<?php
/**
 * Job lock tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\JobLock;

/**
 * Two requests advancing the same job corrupt its archive, so the lock has
 * to exclude other handles in this process as well as other processes, and
 * must never outlive the process holding it.
 *
 * flock() locks belong to an open file description on Linux: two fopen()
 * calls in one process conflict exactly like two processes do, which is what
 * lets most of these tests run in a single PHP process.
 */
class JobLockTest extends TestCase {

	/**
	 * Scratch jobs directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * A valid job id.
	 *
	 * @var string
	 */
	protected $id = '20260928-101500-a1b2c3d4';

	/**
	 * Create the scratch directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/shcm-lock-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir, 0777, true );
	}

	/**
	 * Remove the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Storage::rmdirRecursive( $this->dir );
		parent::tearDown();
	}

	public function testSecondHandleCannotAcquireWhileTheFirstHoldsTheLock() {
		$first  = new JobLock( $this->dir );
		$second = new JobLock( $this->dir );

		$this->assertTrue( $first->acquire( $this->id ) );
		$this->assertTrue( $first->isHeld( $this->id ) );
		$this->assertFileExists( $this->dir . '/' . $this->id . '.lock' );

		$this->assertFalse( $second->acquire( $this->id ) );
		$this->assertFalse( $second->isHeld( $this->id ) );

		$first->release( $this->id );
		$this->assertFalse( $first->isHeld( $this->id ) );

		$this->assertTrue( $second->acquire( $this->id ) );
		$this->assertTrue( $second->isHeld( $this->id ) );
		$this->assertFalse( $first->acquire( $this->id ), 'The lock now belongs to the second handle.' );
		$second->release( $this->id );
	}

	public function testLocksOfDifferentJobsAreIndependent() {
		$first  = new JobLock( $this->dir );
		$second = new JobLock( $this->dir );

		$this->assertTrue( $first->acquire( $this->id ) );
		$this->assertTrue( $second->acquire( '20260928-101500-ffffffff' ) );
	}

	public function testAcquiringAHeldLockAgainIsIdempotent() {
		$lock = new JobLock( $this->dir );
		$this->assertTrue( $lock->acquire( $this->id ) );
		$this->assertTrue( $lock->acquire( $this->id ) );

		// One release frees it: the runner tracks nesting itself.
		$lock->release( $this->id );
		$this->assertFalse( JobLock::isLocked( $this->dir, $this->id ) );

		// Releasing what is not held is harmless.
		$lock->release( $this->id );
		$lock->release( 'never-acquired' );
		$this->assertFalse( $lock->isHeld( $this->id ) );
	}

	public function testIsLockedProbesWithoutTakingTheLock() {
		$this->assertFalse( JobLock::isLocked( $this->dir, $this->id ), 'No lock file yet.' );
		$this->assertFileDoesNotExist( $this->dir . '/' . $this->id . '.lock', 'Probing must not create lock files.' );

		$holder = new JobLock( $this->dir );
		$this->assertTrue( $holder->acquire( $this->id ) );
		$this->assertTrue( JobLock::isLocked( $this->dir, $this->id ) );
		$this->assertTrue( JobLock::isLocked( $this->dir . '/', $this->id ), 'A trailing slash does not matter.' );

		$holder->release( $this->id );
		$this->assertFalse( JobLock::isLocked( $this->dir, $this->id ) );

		// The probe released its shared lock: an exclusive one is available.
		$other = new JobLock( $this->dir );
		$this->assertTrue( $other->acquire( $this->id ) );
		$this->assertTrue( $other->isHeld( $this->id ) );
	}

	public function testInvalidIdsAreRejected() {
		$lock = new JobLock( $this->dir );
		foreach ( array( '', '../escape', 'a/b', "id\0x", 'with space', str_repeat( 'a', 65 ) ) as $bad ) {
			$this->assertFalse( JobLock::isValidId( $bad ), var_export( $bad, true ) );
			$this->assertFalse( $lock->acquire( $bad ), var_export( $bad, true ) );
			$this->assertFalse( $lock->isHeld( $bad ) );
			// Reported as locked so the worker skips a job tick() cannot take.
			$this->assertTrue( JobLock::isLocked( $this->dir, $bad ) );
		}
		$this->assertFalse( JobLock::isValidId( null ) );
		$this->assertFalse( JobLock::isValidId( 123 ) );

		$this->assertSame( array(), glob( $this->dir . '/*' ) );
		$this->assertFileDoesNotExist( dirname( $this->dir ) . '/escape.lock' );
		$this->assertTrue( JobLock::isValidId( $this->id ) );
		$this->assertTrue( JobLock::isValidId( str_repeat( 'a', 64 ) ) );
	}

	public function testTheLockIsReleasedWhenItsInstanceIsDestroyed() {
		$holder = new JobLock( $this->dir );
		$this->assertTrue( $holder->acquire( $this->id ) );
		$this->assertTrue( JobLock::isLocked( $this->dir, $this->id ) );

		unset( $holder );
		gc_collect_cycles();

		$this->assertFalse( JobLock::isLocked( $this->dir, $this->id ) );
		$other = new JobLock( $this->dir );
		$this->assertTrue( $other->acquire( $this->id ) );
	}

	public function testTheOperatingSystemReleasesTheLockOfADeadProcess() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is not available.' );
		}

		$script = $this->dir . '/child.php';
		file_put_contents(
			$script,
			'<?php define( "SHCM_ALLOW_STANDALONE", true );'
			. ' require ' . var_export( dirname( __DIR__, 2 ) . '/includes/bootstrap.php', true ) . ';'
			. ' $lock = new \\SHCM\\Jobs\\JobLock( $argv[1] );'
			. ' echo $lock->acquire( $argv[2] ) ? "locked\n" : "busy\n";'
			. ' fgets( STDIN );' // Hold the lock until the parent says so.
		);

		$pipes   = array();
		$process = proc_open(
			array( PHP_BINARY, $script, $this->dir, $this->id ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );

		stream_set_timeout( $pipes[1], 20 );
		$line = trim( (string) fgets( $pipes[1] ) );
		if ( 'locked' !== $line ) {
			// Only read stderr once the child is gone, or this blocks.
			proc_terminate( $process, 9 );
			$errors = stream_get_contents( $pipes[2] );
			foreach ( $pipes as $pipe ) {
				fclose( $pipe );
			}
			proc_close( $process );
			$this->fail( 'The child process did not take the lock: ' . $line . ' ' . $errors );
		}

		$here = new JobLock( $this->dir );
		$this->assertTrue( JobLock::isLocked( $this->dir, $this->id ) );
		$this->assertFalse( $here->acquire( $this->id ), 'Another process holds the lock.' );

		// Kill the child rather than letting it exit cleanly: the lock must
		// not depend on the holder releasing it.
		proc_terminate( $process, 9 );
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}
		proc_close( $process );

		$this->assertFalse( JobLock::isLocked( $this->dir, $this->id ) );
		$this->assertTrue( $here->acquire( $this->id ) );
	}

	public function testAnUnopenableLockFileFallsBackToUnlockedBehaviour() {
		// A directory that does not exist stands in for a read-only
		// filesystem: the lock file cannot be created.
		$missing = $this->dir . '/does-not-exist';
		$lock    = new JobLock( $missing );

		$this->assertTrue( $lock->acquire( $this->id ), 'Jobs must still run where locking is impossible.' );
		$this->assertFalse( $lock->isHeld( $this->id ) );
		$this->assertFalse( JobLock::isLocked( $missing, $this->id ) );
		$lock->release( $this->id );
		$this->assertDirectoryDoesNotExist( $missing );
	}

	public function testPurgeStaleRemovesOnlyOrphanedUnlockedLockFiles() {
		$live     = '20260928-101500-00000001';
		$orphan   = '20260928-101500-00000002';
		$held     = '20260928-101500-00000003';
		$json_for = function ( $id ) {
			file_put_contents( $this->dir . '/' . $id . '.json', '{"id":"' . $id . '"}' );
		};

		$json_for( $live );
		touch( $this->dir . '/' . $live . '.lock' );
		touch( $this->dir . '/' . $orphan . '.lock' );
		touch( $this->dir . '/unrelated.txt' );

		// A job deleted while a request still works on it keeps its lock.
		$holder = new JobLock( $this->dir );
		$this->assertTrue( $holder->acquire( $held ) );

		$this->assertSame( 1, JobLock::purgeStale( $this->dir ) );
		$this->assertFileExists( $this->dir . '/' . $live . '.lock' );
		$this->assertFileDoesNotExist( $this->dir . '/' . $orphan . '.lock' );
		$this->assertFileExists( $this->dir . '/' . $held . '.lock' );
		$this->assertFileExists( $this->dir . '/unrelated.txt' );
		$this->assertTrue( JobLock::isLocked( $this->dir, $held ), 'The holder keeps its lock.' );

		$holder->release( $held );
		$this->assertSame( 1, JobLock::purgeStale( $this->dir ) );
		$this->assertFileDoesNotExist( $this->dir . '/' . $held . '.lock' );

		$this->assertSame( 0, JobLock::purgeStale( $this->dir . '/missing' ) );
	}

	public function testAcquireRecreatesALockFileThatWasPurged() {
		$first = new JobLock( $this->dir );
		$this->assertTrue( $first->acquire( $this->id ) );
		$first->release( $this->id );

		$this->assertSame( 1, JobLock::purgeStale( $this->dir ) );
		$this->assertFileDoesNotExist( $this->dir . '/' . $this->id . '.lock' );

		$second = new JobLock( $this->dir );
		$this->assertTrue( $second->acquire( $this->id ) );
		$this->assertTrue( $second->isHeld( $this->id ) );
		$this->assertTrue( JobLock::isLocked( $this->dir, $this->id ) );
	}
	public function testACancelRequestIsAMarkerWrittenInOneStep() {
		$marker = $this->dir . '/' . $this->id . '.cancel';
		$this->assertFalse( JobLock::cancelRequested( $this->dir, $this->id ) );

		$this->assertTrue( JobLock::requestCancel( $this->dir, $this->id ) );
		$this->assertFileExists( $marker );
		$this->assertMatchesRegularExpression( '/^\d+$/', (string) file_get_contents( $marker ), 'The request time.' );
		$this->assertSame( array( $marker ), glob( $this->dir . '/*' ), 'No temporary file is left behind.' );
		$this->assertTrue( JobLock::cancelRequested( $this->dir, $this->id ) );
		$this->assertTrue( JobLock::cancelRequested( $this->dir . '/', $this->id ) );

		// Asking again is harmless; the lock itself is not involved.
		$this->assertTrue( JobLock::requestCancel( $this->dir, $this->id ) );
		$this->assertFileDoesNotExist( $this->dir . '/' . $this->id . '.lock' );
		$this->assertFalse( JobLock::isLocked( $this->dir, $this->id ) );

		$this->assertTrue( JobLock::clearCancel( $this->dir, $this->id ) );
		$this->assertFalse( JobLock::cancelRequested( $this->dir, $this->id ) );
		$this->assertTrue( JobLock::clearCancel( $this->dir, $this->id ), 'Clearing twice is harmless.' );
		$this->assertSame( array(), glob( $this->dir . '/*' ) );
	}

	public function testCancelRequestsRefuseInvalidIds() {
		foreach ( array( '', '../escape', 'a/b', "id\0x", str_repeat( 'a', 65 ) ) as $bad ) {
			$this->assertFalse( JobLock::requestCancel( $this->dir, $bad ), var_export( $bad, true ) );
			$this->assertFalse( JobLock::cancelRequested( $this->dir, $bad ) );
			$this->assertTrue( JobLock::clearCancel( $this->dir, $bad ) );
		}
		$this->assertSame( array(), glob( $this->dir . '/*' ) );
		$this->assertFileDoesNotExist( dirname( $this->dir ) . '/escape.cancel' );

		// A directory that cannot be written to: the request fails visibly.
		$this->assertFalse( JobLock::requestCancel( $this->dir . '/missing', $this->id ) );
	}

	public function testPurgeStaleRemovesOrphanedCancelMarkers() {
		$live   = '20260928-101500-00000001';
		$orphan = '20260928-101500-00000002';
		file_put_contents( $this->dir . '/' . $live . '.json', '{"id":"' . $live . '"}' );
		JobLock::requestCancel( $this->dir, $live );
		JobLock::requestCancel( $this->dir, $orphan );
		touch( $this->dir . '/bad id.cancel' );

		// A marker write that died before its rename: old ones go.
		$old_tmp   = $this->dir . '/' . $orphan . '.cancel.123-abc.tmp';
		$fresh_tmp = $this->dir . '/' . $live . '.cancel.456-def.tmp';
		touch( $old_tmp, time() - 7200 );
		touch( $fresh_tmp );

		$this->assertSame( 2, JobLock::purgeStale( $this->dir ) );
		$this->assertTrue( JobLock::cancelRequested( $this->dir, $live ) );
		$this->assertFalse( JobLock::cancelRequested( $this->dir, $orphan ) );
		$this->assertFileExists( $this->dir . '/bad id.cancel', 'Only names this class writes are touched.' );
		$this->assertFileDoesNotExist( $old_tmp );
		$this->assertFileExists( $fresh_tmp );
	}
}
