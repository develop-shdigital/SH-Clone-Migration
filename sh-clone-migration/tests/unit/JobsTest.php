<?php
/**
 * Job engine tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Core\Plugin;
use SHCM\Core\Result;
use SHCM\Core\Settings;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Jobs\JobLock;
use SHCM\Jobs\JobRunner;
use SHCM\Jobs\JobStore;
use SHCM\Jobs\Scheduler;
use SHCM\Jobs\StageResolver;
use SHCM\Logging\Logger;

/**
 * A stage that needs several ticks and records how often it ran.
 */
class CountingStage extends AbstractStage {

	/**
	 * Stage key.
	 *
	 * @var string
	 */
	public $stage_key;

	/**
	 * Iterations needed.
	 *
	 * @var int
	 */
	public $iterations;

	/**
	 * Whether this stage throws.
	 *
	 * @var bool
	 */
	public $explode = false;

	/**
	 * Whether cleanup ran.
	 *
	 * @var bool
	 */
	public $cleaned = false;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings   Settings.
	 * @param Storage  $storage    Storage.
	 * @param Logger   $logger     Logger.
	 * @param string   $key        Stage key.
	 * @param int      $iterations Iterations needed.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger, $key = 'work', $iterations = 3 ) {
		parent::__construct( $settings, $storage, $logger );
		$this->stage_key  = $key;
		$this->iterations = $iterations;
	}

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return $this->stage_key;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Counting ' . $this->stage_key;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return Result
	 */
	public function run( Job $job, Budget $budget ) {
		unset( $budget );

		if ( $this->explode ) {
			throw new \RuntimeException( 'stage exploded' );
		}

		$state          = $job->stageState( $this->key(), array( 'done' => 0 ) );
		$state['done']++;
		$job->setStageState( $this->key(), $state );

		if ( $state['done'] >= $this->iterations ) {
			return $this->complete( 'finished ' . $this->key() );
		}
		return $this->progress( 'working ' . $this->key(), $state['done'] / $this->iterations );
	}

	/**
	 * Cleanup.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		unset( $job, $error );
		$this->cleaned = true;
	}
}

/**
 * Resolver over a fixed set of stages.
 */
class ArrayResolver implements StageResolver {

	/**
	 * Stages by key.
	 *
	 * @var array
	 */
	protected $stages;

	/**
	 * Constructor.
	 *
	 * @param array $stages Stages.
	 */
	public function __construct( array $stages ) {
		$this->stages = $stages;
	}

	/**
	 * Resolve.
	 *
	 * @param string $type      Type.
	 * @param string $stage_key Stage key.
	 * @return \SHCM\Jobs\StageInterface|null
	 */
	public function resolve( $type, $stage_key ) {
		unset( $type );
		return isset( $this->stages[ $stage_key ] ) ? $this->stages[ $stage_key ] : null;
	}

	/**
	 * Stage keys.
	 *
	 * @param string $type   Type.
	 * @param array  $params Params.
	 * @return string[]
	 */
	public function stagesFor( $type, array $params = array() ) {
		unset( $type, $params );
		return array_keys( $this->stages );
	}
}

/**
 * A counting stage that counts every call to run() and can act as "another
 * request" while it runs.
 */
class RunCountingStage extends CountingStage {

	/**
	 * Calls to run().
	 *
	 * @var int
	 */
	public $runs = 0;

	/**
	 * Called before each run with the job and this stage.
	 *
	 * @var callable|null
	 */
	public $before_run = null;

	/**
	 * Called after each run with the job and this stage.
	 *
	 * @var callable|null
	 */
	public $after_run = null;

	/**
	 * Calls to cleanup().
	 *
	 * @var int
	 */
	public $cleanups = 0;

	/**
	 * Value of the "done" counter after each run (a unit done twice shows up
	 * twice).
	 *
	 * @var int[]
	 */
	public $units = array();

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return Result
	 */
	public function run( Job $job, Budget $budget ) {
		++$this->runs;
		if ( null !== $this->before_run ) {
			call_user_func( $this->before_run, $job, $this );
		}
		$result        = parent::run( $job, $budget );
		$this->units[] = $job->stageState( $this->key() )['done'];
		if ( null !== $this->after_run ) {
			call_user_func( $this->after_run, $job, $this );
		}
		return $result;
	}

	/**
	 * Cleanup.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		++$this->cleanups;
		parent::cleanup( $job, $error );
	}
}

/**
 * A runner whose password provider is scripted (the unit suite has no
 * WordPress filters).
 */
class PasswordProviderRunner extends JobRunner {

	/**
	 * Password the provider returns.
	 *
	 * @var string
	 */
	public $provided = 'from-provider';

	/**
	 * Provider calls.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Scripted provider.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function providePassword( Job $job ) {
		unset( $job );
		++$this->calls;
		return $this->provided;
	}
}

/**
 * A runner that records the lifecycle actions it fires (the unit suite has
 * no WordPress hooks).
 */
class AnnouncingRunner extends JobRunner {

	/**
	 * Actions fired by every AnnouncingRunner: array( hook, job id, status ).
	 *
	 * @var array[]
	 */
	public static $announced = array();

	/**
	 * Record the action.
	 *
	 * @param string $hook    Action name.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	protected function announce( $hook, ...$args ) {
		$job               = $args[0];
		self::$announced[] = array( $hook, $job->id(), $job->status() );
	}

	/**
	 * Use this lock instead of creating one.
	 *
	 * @param JobLock $lock Lock.
	 * @return void
	 */
	public function useLock( JobLock $lock ) {
		$this->lock = $lock;
	}

	/**
	 * How often an action fired.
	 *
	 * @param string $hook Action name.
	 * @return int
	 */
	public static function count( $hook ) {
		$count = 0;
		foreach ( self::$announced as $entry ) {
			if ( $hook === $entry[0] ) {
				++$count;
			}
		}
		return $count;
	}
}

/**
 * A lock whose first attempts fail as if another request held it.
 */
class RefusingLock extends JobLock {

	/**
	 * Attempts still to refuse.
	 *
	 * @var int
	 */
	public $refuse = 1;

	/**
	 * Take the lock, unless an attempt is still to be refused.
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	public function acquire( $job_id ) {
		if ( $this->refuse > 0 ) {
			--$this->refuse;
			return false;
		}
		return parent::acquire( $job_id );
	}
}

/**
 * A job store that lets the test act right after a load.
 */
class LoadHookStore extends JobStore {

	/**
	 * Loads so far.
	 *
	 * @var int
	 */
	public $loads = 0;

	/**
	 * Called with the load number after each load.
	 *
	 * @var callable|null
	 */
	public $after_load = null;

	/**
	 * Load.
	 *
	 * @param string $id Job id.
	 * @return Job|null
	 */
	public function load( $id ) {
		$job = parent::load( $id );
		++$this->loads;
		if ( null !== $this->after_load ) {
			call_user_func( $this->after_load, $this->loads );
		}
		return $job;
	}
}

/**
 * A budget the test decides about.
 */
class ScriptedBudget extends Budget {

	/**
	 * Whether the budget is spent.
	 *
	 * @var bool
	 */
	public $spent = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 3600, 0 );
	}

	/**
	 * Whether the budget is spent.
	 *
	 * @return bool
	 */
	public function expired() {
		return $this->spent;
	}
}

/**
 * A job store that lets the test act right before a save.
 */
class SaveHookStore extends JobStore {

	/**
	 * Called with the job before each save.
	 *
	 * @var callable|null
	 */
	public $before_save = null;

	/**
	 * Called with the job before each save; true makes the save fail.
	 *
	 * @var callable|null
	 */
	public $refuse = null;

	/**
	 * Save.
	 *
	 * @param Job $job Job.
	 * @return bool
	 */
	public function save( Job $job ) {
		if ( null !== $this->before_save ) {
			call_user_func( $this->before_save, $job );
		}
		if ( null !== $this->refuse && call_user_func( $this->refuse, $job ) ) {
			return false;
		}
		return parent::save( $job );
	}
}

/**
 * Settings with fixed values instead of the options table.
 */
class ArraySettings extends Settings {

	/**
	 * Constructor.
	 *
	 * @param array $values Values over the defaults.
	 */
	public function __construct( array $values = array() ) {
		$this->values = array_merge( self::defaults(), $values );
	}
}

/**
 * The runner is the only thing allowed to change a job's status, so its
 * behaviour under resumption, failure and cancellation is worth pinning down.
 */
class JobsTest extends TestCase {

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Job store.
	 *
	 * @var JobStore
	 */
	protected $store;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Set up a scratch storage directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		AnnouncingRunner::$announced = array();
		$this->dir     = sys_get_temp_dir() . '/shcm-jobs-' . bin2hex( random_bytes( 6 ) );
		$this->storage = new Storage( $this->dir );
		$this->storage->prepare();
		$this->store    = new JobStore( $this->storage );
		$this->logger   = new Logger( $this->storage, 'error' );
		$this->settings = new Settings();
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

	/**
	 * Build a stage.
	 *
	 * @param string $key        Key.
	 * @param int    $iterations Iterations.
	 * @return CountingStage
	 */
	protected function stage( $key, $iterations = 3 ) {
		return new CountingStage( $this->settings, $this->storage, $this->logger, $key, $iterations );
	}

	public function testJobRunsThroughEveryStage() {
		$stages   = array(
			'first'  => $this->stage( 'first', 2 ),
			'second' => $this->stage( 'second', 3 ),
		);
		$resolver = new ArrayResolver( $stages );
		$runner   = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array_keys( $stages ) );
		$this->store->save( $job );

		$job = $runner->tick( $job );

		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$this->assertSame( 100.0, $job->get( 'progress' ) );
		$this->assertSame( 2, $job->stageState( 'first' )['done'] );
		$this->assertSame( 3, $job->stageState( 'second' )['done'] );
	}

	public function testJobPausesWhenTheBudgetRunsOutAndResumesLater() {
		$stages   = array( 'work' => $this->stage( 'work', 6 ) );
		$resolver = new ArrayResolver( $stages );
		$runner   = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array_keys( $stages ) );
		$this->store->save( $job );

		// A budget that is already spent stops after one unit of work.
		$job = $runner->tick( $job, new Budget( -1, 0 ) );
		$this->assertSame( Job::STATUS_PAUSED, $job->status() );
		$this->assertSame( 1, $job->stageState( 'work' )['done'] );

		// The paused state must survive a full reload from disk.
		$reloaded = $this->store->load( $job->id() );
		$this->assertNotNull( $reloaded );
		$this->assertSame( 1, $reloaded->stageState( 'work' )['done'] );

		$reloaded = $runner->tick( $reloaded );
		$this->assertSame( Job::STATUS_COMPLETED, $reloaded->status() );
		$this->assertSame( 6, $reloaded->stageState( 'work' )['done'] );
	}

	public function testFailureIsRecordedAndCleanupRuns() {
		$failing          = $this->stage( 'boom', 1 );
		$failing->explode = true;
		$other            = $this->stage( 'other', 1 );

		$resolver = new ArrayResolver(
			array(
				'boom'  => $failing,
				'other' => $other,
			)
		);
		$runner = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'boom', 'other' ) );
		$this->store->save( $job );
		$job = $runner->tick( $job );

		$this->assertSame( Job::STATUS_FAILED, $job->status() );
		$this->assertSame( 'stage exploded', $job->get( 'error' )['message'] );
		$this->assertSame( 'boom', $job->get( 'error' )['stage'] );
		$this->assertTrue( $failing->cleaned );
		$this->assertTrue( $other->cleaned, 'Every stage gets a chance to undo global state.' );
	}

	public function testCancellationFromAnotherRequestStopsTheJob() {
		$stages   = array( 'work' => $this->stage( 'work', 50 ) );
		$resolver = new ArrayResolver( $stages );
		$runner   = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array_keys( $stages ) );
		$this->store->save( $job );

		// Another request, which could not take the job's lock, left a cancel
		// request next to the job file.
		$this->assertTrue( JobLock::requestCancel( $this->storage->jobs(), $job->id() ) );

		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertTrue( $stages['work']->cleaned );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ), 'The request was carried out.' );
	}

	/**
	 * A request that dies mid-step (PHP fatal error, memory limit, the process
	 * killed by a server timeout) leaves tick_open behind; simulate that.
	 *
	 * @param Job $job Job as the dying request saved it.
	 * @return void
	 */
	protected function dieMidTick( Job $job ) {
		$job->set( 'status', Job::STATUS_RUNNING );
		$job->set( 'tick_open', true );
		$job->set( 'tick_progress', (float) $job->get( 'progress' ) );
		$this->store->save( $job );
	}

	public function testAJobWhoseRequestsKeepDyingFailsInsteadOfLoopingForever() {
		$stages   = array( 'work' => $this->stage( 'work', 6 ) );
		$resolver = new ArrayResolver( $stages );
		$runner   = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );
		$job      = Job::create( 'export', array(), array_keys( $stages ) );
		$job->set( 'dead_ticks', JobRunner::MAX_DEAD_TICKS - 1 );
		$this->dieMidTick( $job );

		$job = $runner->tick( $this->store->load( $job->id() ) );
		$this->assertSame( Job::STATUS_FAILED, $job->status() );
		$this->assertStringContainsString( 'times in a row', $job->get( 'error' )['message'] );
		$this->assertSame( 0, (int) ( $job->stageState( 'work' )['done'] ?? 0 ), 'the failing step is not run again' );
		$this->assertTrue( $stages['work']->cleaned, 'cleanups run' );
		$this->assertFalse( (bool) $this->store->load( $job->id() )->get( 'tick_open' ) );
	}

	public function testDeadRequestsAreCountedOnlyWhileNothingIsSaved() {
		$stages   = array( 'work' => $this->stage( 'work', 6 ) );
		$resolver = new ArrayResolver( $stages );
		$runner   = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );
		$job      = Job::create( 'export', array(), array_keys( $stages ) );
		$this->store->save( $job );

		// Two requests die without saving anything: counted, not yet fatal.
		for ( $i = 1; $i < JobRunner::MAX_DEAD_TICKS; $i++ ) {
			$this->dieMidTick( $this->store->load( $job->id() ) );
			$job = $runner->tick( $this->store->load( $job->id() ), new Budget( -1, 0 ) );
			$this->assertSame( Job::STATUS_PAUSED, $job->status() );
			$this->assertSame( $i, (int) $job->get( 'dead_ticks' ) );
			$this->assertFalse( (bool) $job->get( 'tick_open' ), 'a request that ends normally closes its tick' );
		}

		// A request that dies after saving progress resets the count.
		$dying = $this->store->load( $job->id() );
		$this->dieMidTick( $dying );
		$dying->set( 'progress', (float) $dying->get( 'progress' ) + 5 );
		$this->store->save( $dying );
		$job = $runner->tick( $this->store->load( $job->id() ), new Budget( -1, 0 ) );
		$this->assertSame( 0, (int) $job->get( 'dead_ticks' ) );

		$job = $runner->tick( $this->store->load( $job->id() ) );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
	}

	public function testUnknownStageFailsCleanly() {
		$resolver = new ArrayResolver( array() );
		$runner   = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'nope' ) );
		$this->store->save( $job );
		$job = $runner->tick( $job );

		$this->assertSame( Job::STATUS_FAILED, $job->status() );
		$this->assertStringContainsString( 'Unknown migration stage', $job->get( 'error' )['message'] );
	}

	public function testProgressIsWeighted() {
		$light = $this->stage( 'light', 1 );
		$heavy = $this->stage( 'heavy', 1 );

		$resolver = new ArrayResolver(
			array(
				'light' => $light,
				'heavy' => $heavy,
			)
		);
		$runner = new JobRunner( $this->store, $resolver, $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'light', 'heavy' ) );
		$job->set( 'stage', 'heavy' );
		$job->set( 'stage_progress', 0.5 );

		$this->assertSame( 75.0, $runner->overallProgress( $job ) );
	}

	public function testJobStoreListsAndDeletes() {
		$job = Job::create( 'export', array( 'a' => 1 ), array( 'x' ) );
		$this->store->save( $job );

		$all = $this->store->all();
		$this->assertCount( 1, $all );
		$this->assertSame( $job->id(), $all[0]->id() );

		$this->assertCount( 0, $this->store->all( 'import' ) );
		$this->assertTrue( $this->store->delete( $job->id() ) );
		$this->assertNull( $this->store->load( $job->id() ) );
	}

	public function testJobIdsAreSanitisedBeforeTouchingTheFilesystem() {
		$path = $this->store->path( '../../etc/passwd' );
		$this->assertStringContainsString( $this->storage->jobs(), $path );
		$this->assertStringNotContainsString( '..', $path );
	}

	public function testRuntimeValuesAreNeverPersisted() {
		$job = Job::create( 'export', array(), array( 'x' ) );
		$job->setRuntime( 'password', 'super secret' );
		$this->store->save( $job );

		$raw = (string) file_get_contents( $this->store->path( $job->id() ) );
		$this->assertStringNotContainsString( 'super secret', $raw );
		$this->assertSame( 'super secret', $job->password() );

		$reloaded = $this->store->load( $job->id() );
		$this->assertSame( '', $reloaded->password() );
	}

	public function testBudgetExpiry() {
		$budget = new Budget( 0.05, 0 );
		$this->assertFalse( $budget->expired() );
		usleep( 60000 );
		$this->assertTrue( $budget->expired() );
	}

	/* ------------------------------------------------------------------
	 * Locking, cancel-safe saves, password provider, worker
	 * ------------------------------------------------------------------ */

	/**
	 * Build a stage that counts its runs.
	 *
	 * @param string $key        Key.
	 * @param int    $iterations Iterations.
	 * @return RunCountingStage
	 */
	protected function countingStage( $key, $iterations = 3 ) {
		return new RunCountingStage( $this->settings, $this->storage, $this->logger, $key, $iterations );
	}

	/**
	 * Save a job as if it was last touched some time ago.
	 *
	 * JobStore::save() stamps updated_at with the current time, so the file
	 * is rewritten afterwards; its mtime orders JobStore::all().
	 *
	 * @param Job $job Job.
	 * @param int $age Seconds since the last save.
	 * @return void
	 */
	protected function saveAged( Job $job, $age ) {
		$this->store->save( $job );
		$job->set( 'updated_at', time() - $age );
		$path = $this->store->path( $job->id() );
		file_put_contents( $path, json_encode( $job->toArray() ) );
		touch( $path, time() - $age );
	}

	/**
	 * A plugin container wired to the scratch storage.
	 *
	 * @param StageResolver $resolver Resolver.
	 * @param array         $settings Settings over the defaults.
	 * @return Plugin
	 */
	protected function plugin( StageResolver $resolver, array $settings = array() ) {
		$settings = new ArraySettings( array_merge( array( 'time_budget' => 5 ), $settings ) );
		$plugin   = new Plugin();
		$plugin->setService( 'settings', $settings );
		$plugin->setService( 'storage', $this->storage );
		$plugin->setService( 'logger', $this->logger );
		$plugin->setService( 'jobs', $this->store );
		$plugin->setService( 'runner', new JobRunner( $this->store, $resolver, $this->logger, $settings ) );
		return $plugin;
	}

	/**
	 * A cancelling request: its own store, runner and lock handle.
	 *
	 * @param StageResolver $resolver Resolver.
	 * @return AnnouncingRunner
	 */
	protected function cancellingRunner( StageResolver $resolver ) {
		return new AnnouncingRunner( new JobStore( $this->storage ), $resolver, $this->logger, $this->settings );
	}

	public function testTickOnAJobLockedElsewhereReturnsBusyWithoutAdvancing() {
		$stages = array( 'work' => $this->countingStage( 'work', 2 ) );
		$runner = new JobRunner( $this->store, new ArrayResolver( $stages ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array_keys( $stages ) );
		$this->store->save( $job );
		$before = (string) file_get_contents( $this->store->path( $job->id() ) );

		// Another request (its own lock handle) is advancing the job.
		$other = new JobLock( $this->storage->jobs() );
		$this->assertTrue( $other->acquire( $job->id() ) );

		$result = $runner->tick( $job );
		$this->assertTrue( $result->runtime( 'busy' ) );
		$this->assertSame( Job::STATUS_PENDING, $result->status() );
		$this->assertSame( 0, $stages['work']->runs );
		$this->assertSame( 0, (int) $result->get( 'ticks' ) );
		$this->assertSame( $before, (string) file_get_contents( $this->store->path( $job->id() ) ), 'A busy tick saves nothing.' );

		$other->release( $job->id() );
		$result = $runner->tick( $result );
		$this->assertFalse( $result->runtime( 'busy' ) );
		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertSame( 2, $stages['work']->runs );
	}

	public function testANestedTickOfTheSameJobIsBusy() {
		$work   = $this->countingStage( 'work', 3 );
		$runner = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$inner  = array();

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );

		// A hook fired inside the tick ticks the same job again.
		$work->after_run = function ( Job $ticking, RunCountingStage $stage ) use ( $runner, &$inner ) {
			if ( 1 === $stage->runs ) {
				$inner[] = $runner->tick( $this->store->load( $ticking->id() ) )->runtime( 'busy' );
			}
		};

		$job = $runner->tick( $job );
		$this->assertSame( array( true ), $inner );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$this->assertSame( 3, $work->runs );
		$this->assertFalse( JobLock::isLocked( $this->storage->jobs(), $job->id() ), 'The outer tick still releases its lock.' );
	}

	public function testTickReleasesTheLockOnEveryPath() {
		$dir = $this->storage->jobs();

		// Paused.
		$slow   = array( 'work' => $this->countingStage( 'work', 5 ) );
		$runner = new JobRunner( $this->store, new ArrayResolver( $slow ), $this->logger, $this->settings );
		$job    = Job::create( 'export', array(), array_keys( $slow ) );
		$this->store->save( $job );
		$job = $runner->tick( $job, new Budget( -1, 0 ) );
		$this->assertSame( Job::STATUS_PAUSED, $job->status() );
		$this->assertFalse( JobLock::isLocked( $dir, $job->id() ) );
		$this->assertFalse( $runner->lock()->isHeld( $job->id() ) );

		// Completed.
		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$this->assertFalse( JobLock::isLocked( $dir, $job->id() ) );

		// Failed by an exception.
		$boom          = $this->stage( 'boom', 1 );
		$boom->explode = true;
		$runner        = new JobRunner( $this->store, new ArrayResolver( array( 'boom' => $boom ) ), $this->logger, $this->settings );
		$job           = Job::create( 'export', array(), array( 'boom' ) );
		$this->store->save( $job );
		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_FAILED, $job->status() );
		$this->assertFalse( JobLock::isLocked( $dir, $job->id() ) );
	}

	public function testACancelRequestedDuringAStageIsCarriedOutAfterIt() {
		$work    = $this->countingStage( 'work', 50 );
		$other   = $this->countingStage( 'other', 1 );
		$stages  = array(
			'work'  => $work,
			'other' => $other,
		);
		$runner  = new AnnouncingRunner( $this->store, new ArrayResolver( $stages ), $this->logger, $this->settings );
		$request = $this->cancellingRunner( new ArrayResolver( $stages ) );

		$job = Job::create( 'export', array(), array_keys( $stages ) );
		$this->store->save( $job );

		// While the stage runs, another request cancels the job.
		$work->after_run = function ( Job $ticking, RunCountingStage $stage ) use ( $request ) {
			if ( 1 === $stage->runs ) {
				$request->cancelJob( $request->store()->load( $ticking->id() ) );
			}
		};

		$job = $runner->tick( $job );

		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ), 'Announced once, by the ticking request.' );
		$this->assertSame( array( 'shcm_job_cancelled', $job->id(), Job::STATUS_CANCELLED ), AnnouncingRunner::$announced[0] );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ) );
		$this->assertSame( 1, $work->runs, 'The runner stops right after the stage returns.' );
		$this->assertTrue( $work->cleaned );
		$this->assertTrue( $other->cleaned );
		$this->assertNull( $job->get( 'error' ) );
		$this->assertGreaterThan( 0, (int) $job->get( 'finished_at' ) );

		$saved = $this->store->load( $job->id() );
		$this->assertSame( Job::STATUS_CANCELLED, $saved->status() );
		$this->assertSame( 1, $saved->stageState( 'work' )['done'], 'The ticking request saved its own, newest state.' );
		$this->assertFalse( JobLock::isLocked( $this->storage->jobs(), $job->id() ) );
	}

	public function testACancelDuringThePausingSliceIsNotOverwritten() {
		$work    = $this->countingStage( 'work', 50 );
		$runner  = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$request = $this->cancellingRunner( new ArrayResolver( array( 'work' => $work ) ) );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$work->after_run = function ( Job $ticking ) use ( $request ) {
			$request->cancelJob( $request->store()->load( $ticking->id() ) );
		};

		// The budget is spent: without the check this slice would save "paused".
		$job = $runner->tick( $job, new Budget( -1, 0 ) );
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertSame( Job::STATUS_CANCELLED, $this->store->load( $job->id() )->status() );
		$this->assertTrue( $work->cleaned );
	}

	public function testACancelDuringTheLastStageWinsOverCompletion() {
		$last    = $this->countingStage( 'last', 1 );
		$runner  = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'last' => $last ) ), $this->logger, $this->settings );
		$request = $this->cancellingRunner( new ArrayResolver( array( 'last' => $last ) ) );

		$job = Job::create( 'export', array(), array( 'last' ) );
		$this->store->save( $job );
		$last->after_run = function ( Job $ticking ) use ( $request ) {
			$request->cancelJob( $request->store()->load( $ticking->id() ) );
		};

		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertSame( Job::STATUS_CANCELLED, $this->store->load( $job->id() )->status() );
		$this->assertTrue( $last->cleaned );
		$this->assertSame( 0, AnnouncingRunner::count( 'shcm_job_completed' ), 'Never both outcomes.' );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
	}

	public function testAFailureAfterACancelEndsCancelled() {
		$boom          = $this->countingStage( 'boom', 5 );
		$boom->explode = true;
		$runner        = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'boom' => $boom ) ), $this->logger, $this->settings );
		$request       = $this->cancellingRunner( new ArrayResolver( array( 'boom' => $boom ) ) );

		$job = Job::create( 'export', array(), array( 'boom' ) );
		$this->store->save( $job );

		// Another request cancels and the stage throws (a stage often fails
		// because of what the user did to cancel): the job ends cancelled,
		// not failed.
		$boom->before_run = function ( Job $ticking ) use ( $request ) {
			$request->cancelJob( $request->store()->load( $ticking->id() ) );
		};

		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertNull( $job->get( 'error' ) );
		$this->assertTrue( $boom->cleaned );
		$this->assertSame( Job::STATUS_CANCELLED, $this->store->load( $job->id() )->status() );
		$this->assertSame( 0, AnnouncingRunner::count( 'shcm_job_failed' ) );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
	}

	public function testCancelJobOnAFinishedJobIsANoOp() {
		$work   = $this->countingStage( 'work', 1 );
		$runner = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$message = $job->get( 'message' );

		$work->cleaned = false;
		$result        = $runner->cancelJob( $job );

		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertSame( $message, $result->get( 'message' ) );
		$this->assertFalse( $work->cleaned, 'A completed job keeps its archive: no cleanup.' );
		$this->assertSame( Job::STATUS_COMPLETED, $this->store->load( $job->id() )->status() );

		// Even when its file was removed meanwhile: nothing is written back.
		$this->store->delete( $job->id() );
		$result = $runner->cancelJob( $job );
		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertFalse( $work->cleaned );
		$this->assertNull( $this->store->load( $job->id() ) );
	}

	public function testCancelJobOnAStaleCopyOfAFinishedJobIsANoOp() {
		$work   = $this->countingStage( 'work', 1 );
		$runner = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$stale = $this->store->load( $job->id() );
		$runner->tick( $job );

		$work->cleaned = false;
		$result        = $runner->cancelJob( $stale );
		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertFalse( $work->cleaned );
		$this->assertSame( Job::STATUS_COMPLETED, $this->store->load( $job->id() )->status() );
	}

	public function testCancelJobTakesTheLockAndCleansUp() {
		$work   = $this->countingStage( 'work', 5 );
		$runner = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$job = $runner->tick( $job, new Budget( -1, 0 ) );

		$result = $runner->cancelJob( $this->store->load( $job->id() ) );
		$this->assertSame( Job::STATUS_CANCELLED, $result->status() );
		$this->assertSame( 'Migration cancelled.', $result->get( 'message' ) );
		$this->assertTrue( $work->cleaned );
		$this->assertSame( Job::STATUS_CANCELLED, $this->store->load( $job->id() )->status() );
		$this->assertFalse( JobLock::isLocked( $this->storage->jobs(), $job->id() ) );
	}

	public function testCancelJobWhileLockedElsewhereOnlyRecordsARequest() {
		$work   = $this->countingStage( 'work', 5 );
		$runner = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$job = $runner->tick( $job, new Budget( -1, 0 ) );
		$this->assertSame( Job::STATUS_PAUSED, $job->status() );
		$this->assertFalse( $runner->cancelRequested( $job ) );

		$holder = new JobLock( $this->storage->jobs() );
		$this->assertTrue( $holder->acquire( $job->id() ) );

		$path   = $this->store->path( $job->id() );
		$before = (string) file_get_contents( $path );
		clearstatcache();
		$mtime  = filemtime( $path );

		$result = $runner->cancelJob( $this->store->load( $job->id() ) );
		$this->assertSame( Job::STATUS_PAUSED, $result->status(), 'The job as saved, unchanged.' );
		$this->assertSame( 0, (int) $result->get( 'finished_at' ) );
		$this->assertSame( $before, (string) file_get_contents( $path ), 'The job file is not rewritten at all.' );
		clearstatcache();
		$this->assertSame( $mtime, filemtime( $path ) );
		$this->assertFileExists( $this->storage->jobs() . '/' . $job->id() . '.cancel' );
		$this->assertTrue( $runner->cancelRequested( $result ), 'The admin screen shows "Cancelling…".' );
		$this->assertTrue( $runner->cancelRequested( $job->id() ) );
		$this->assertSame( array(), AnnouncingRunner::$announced, 'Nothing is announced before the cancel is carried out.' );
		$this->assertFalse( $work->cleaned, 'Cleanups are left to the request holding the job.' );
		$this->assertTrue( $holder->isHeld( $job->id() ), 'The holder keeps its lock.' );

		// A second click while still pending changes nothing either.
		$runner->cancelJob( $this->store->load( $job->id() ) );
		$this->assertSame( $before, (string) file_get_contents( $path ) );
		$this->assertSame( array(), AnnouncingRunner::$announced );

		// When the job is next ticked (here: on the old copy), the request is
		// carried out: cleanups, cancelled status, marker gone, one announcement.
		$holder->release( $job->id() );
		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertSame( 'Migration cancelled.', $job->get( 'message' ) );
		$this->assertGreaterThan( 0, (int) $job->get( 'finished_at' ) );
		$this->assertTrue( $work->cleaned );
		$this->assertSame( 1, $work->runs, 'No further step once a cancel is pending.' );
		$this->assertFalse( $runner->cancelRequested( $job->id() ) );
		$this->assertFileDoesNotExist( $this->storage->jobs() . '/' . $job->id() . '.cancel' );
		$this->assertSame( array( array( 'shcm_job_cancelled', $job->id(), Job::STATUS_CANCELLED ) ), AnnouncingRunner::$announced );
	}

	public function testACancelFromAnotherRunnerMidTickIsFinishedByTheTickingRunner() {
		$work   = $this->countingStage( 'work', 50 );
		$runner = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		// The cancelling request: its own store, runner and lock handle.
		$request_runner = $this->cancellingRunner( new ArrayResolver( array( 'work' => $work ) ) );
		$seen           = array();

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );

		$work->after_run = function ( Job $ticking, RunCountingStage $stage ) use ( $request_runner, &$seen ) {
			if ( 1 !== $stage->runs ) {
				return;
			}
			$result = $request_runner->cancelJob( $request_runner->store()->load( $ticking->id() ) );
			$seen   = array(
				'status'    => $result->status(),
				'requested' => $request_runner->cancelRequested( $result ),
				'cleaned'   => $stage->cleaned,
				'announced' => count( AnnouncingRunner::$announced ),
			);
		};

		$job = $runner->tick( $job );

		$this->assertSame(
			array(
				'status'    => Job::STATUS_RUNNING,
				'requested' => true,
				'cleaned'   => false,
				'announced' => 0,
			),
			$seen,
			'The cancelling request must not clean up under the running stage, nor announce anything.'
		);
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertSame( 1, $work->runs );
		$this->assertTrue( $work->cleaned );
		$this->assertSame( 'Migration cancelled.', $this->store->load( $job->id() )->get( 'message' ) );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
	}

	public function testTickContinuesFromANewerSavedState() {
		$work   = $this->countingStage( 'work', 3 );
		$first  = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$second = new JobRunner( new JobStore( $this->storage ), new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );

		// The worker loads the job, then a browser tick advances it first.
		$stale = $this->store->load( $job->id() );
		$stale->setRuntime( 'password', 'kept' );
		$first->tick( $job, new Budget( -1, 0 ) );
		$this->assertSame( 1, $work->runs );

		$result = $second->tick( $stale );
		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertSame( 3, $work->runs, 'Work already saved is not done again.' );
		$this->assertSame( 2, (int) $result->get( 'ticks' ) );
		$this->assertSame( 'kept', $result->password() );
	}

	public function testPasswordProviderSuppliesTheRuntimePassword() {
		$seen = array();
		$work = $this->countingStage( 'work', 1 );

		$work->after_run = function ( Job $ticking ) use ( &$seen ) {
			$seen[] = $ticking->password();
		};
		$runner = new PasswordProviderRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create(
			'export',
			array(
				'encrypted'       => true,
				'password_source' => 'backup',
			),
			array( 'work' )
		);
		$this->store->save( $job );
		$job = $runner->tick( $job );

		$this->assertSame( array( 'from-provider' ), $seen );
		$this->assertSame( 1, $runner->calls );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$this->assertStringNotContainsString( 'from-provider', (string) file_get_contents( $this->store->path( $job->id() ) ) );
	}

	public function testPasswordProviderIsOnlyAskedWhenItShouldBe() {
		$seen = array();
		$work = $this->countingStage( 'work', 1 );

		$work->after_run = function ( Job $ticking ) use ( &$seen ) {
			$seen[] = $ticking->password();
		};
		$runner = new PasswordProviderRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		// A browser-started encrypted job: the password came with the request.
		$typed = Job::create( 'export', array( 'encrypted' => true ), array( 'work' ) );
		$typed->setRuntime( 'password', 'typed' );
		$this->store->save( $typed );
		$runner->tick( $typed );

		// Password already supplied: the provider is not consulted.
		$given = Job::create(
			'export',
			array(
				'encrypted'       => true,
				'password_source' => 'backup',
			),
			array( 'work' )
		);
		$given->setRuntime( 'password', 'given' );
		$this->store->save( $given );
		$runner->tick( $given );

		// Not encrypted.
		$plain = Job::create( 'export', array( 'password_source' => 'backup' ), array( 'work' ) );
		$this->store->save( $plain );
		$runner->tick( $plain );

		// No source: an encrypted browser job resumed without its password.
		$none = Job::create( 'export', array( 'encrypted' => true ), array( 'work' ) );
		$this->store->save( $none );
		$runner->tick( $none );

		$this->assertSame( array( 'typed', 'given', '', '' ), $seen );
		$this->assertSame( 0, $runner->calls );
	}

	public function testDefaultPasswordProviderAsksTheFilter() {
		$seen = array();
		$work = $this->countingStage( 'work', 1 );

		$work->after_run = function ( Job $ticking ) use ( &$seen ) {
			$seen[] = $ticking->password();
		};
		$runner = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		// The test shim's apply_filters() returns the value unchanged.
		$job = Job::create(
			'export',
			array(
				'encrypted'       => true,
				'password_source' => 'backup',
			),
			array( 'work' )
		);
		$this->store->save( $job );
		$runner->tick( $job );
		$this->assertSame( array( '' ), $seen );
	}

	public function testWorkerEligibility() {
		$dir = $this->storage->jobs();
		$now = time();
		$job = function ( $status, array $params, $idle ) use ( $now ) {
			$job = Job::create( 'export', $params, array( 'work' ) );
			$job->set( 'status', $status );
			$job->set( 'updated_at', $now - $idle );
			return $job;
		};
		$bg = array( 'background' => true );

		$this->assertFalse( Scheduler::workerMayTick( $job( Job::STATUS_PENDING, array(), 600 ), $dir, $now ), 'A pending browser job is still being started.' );
		$this->assertTrue( Scheduler::workerMayTick( $job( Job::STATUS_PENDING, $bg, Scheduler::WORKER_IDLE_BACKGROUND ), $dir, $now ), 'A background job may never have been ticked.' );
		$this->assertFalse( Scheduler::workerMayTick( $job( Job::STATUS_PENDING, $bg, Scheduler::WORKER_IDLE_BACKGROUND - 1 ), $dir, $now ) );
		$this->assertTrue( Scheduler::workerMayTick( $job( Job::STATUS_PAUSED, array(), 120 ), $dir, $now ) );
		$this->assertFalse( Scheduler::workerMayTick( $job( Job::STATUS_PAUSED, array(), 119 ), $dir, $now ) );
		$this->assertTrue( Scheduler::workerMayTick( $job( Job::STATUS_RUNNING, array(), 300 ), $dir, $now ), 'A job stuck "running" after a fatal error.' );
		$this->assertFalse( Scheduler::workerMayTick( $job( Job::STATUS_COMPLETED, $bg, 600 ), $dir, $now ) );
		$this->assertFalse( Scheduler::workerMayTick( $job( Job::STATUS_CANCELLED, $bg, 600 ), $dir, $now ) );
		$this->assertFalse( Scheduler::workerMayTick( $job( Job::STATUS_PAUSED, array( 'encrypted' => true ), 600 ), $dir, $now ), 'No way to get the password back.' );
		$this->assertTrue(
			Scheduler::workerMayTick(
				$job(
					Job::STATUS_PAUSED,
					array(
						'encrypted'       => true,
						'password_source' => 'backup',
						'background'      => true,
					),
					600
				),
				$dir,
				$now
			)
		);

		// A slice that has not saved for a while but is still running.
		$locked = $job( Job::STATUS_RUNNING, $bg, 600 );
		$holder = new JobLock( $dir );
		$this->assertTrue( $holder->acquire( $locked->id() ) );
		$this->assertFalse( Scheduler::workerMayTick( $locked, $dir, $now ) );
		$holder->release( $locked->id() );
		$this->assertTrue( Scheduler::workerMayTick( $locked, $dir, $now ) );
	}

	public function testWorkerPrefersBackgroundJobs() {
		$dir     = $this->storage->jobs();
		$now     = time();
		$browser = Job::create( 'export', array(), array( 'work' ) );
		$browser->set( 'status', Job::STATUS_PAUSED )->set( 'updated_at', $now - 600 );
		$young = Job::create( 'export', array( 'background' => true ), array( 'work' ) );
		$young->set( 'status', Job::STATUS_PAUSED )->set( 'updated_at', $now - 10 );
		$background = Job::create( 'export', array( 'background' => true ), array( 'work' ) );
		$background->set( 'status', Job::STATUS_PAUSED )->set( 'updated_at', $now - 60 );

		$this->assertSame( $background, Scheduler::pickWorkerJob( array( $browser, $young, $background ), $dir, $now ) );
		$this->assertSame( $browser, Scheduler::pickWorkerJob( array( $browser, $young ), $dir, $now ) );
		$this->assertNull( Scheduler::pickWorkerJob( array( $young ), $dir, $now ) );
		$this->assertNull( Scheduler::pickWorkerJob( array(), $dir, $now ) );
	}

	public function testWorkerTicksOneBackgroundJobPerRun() {
		$work   = $this->countingStage( 'work', 2 );
		$plugin = $this->plugin( new ArrayResolver( array( 'work' => $work ) ) );

		// Newest first: an abandoned browser job, then a background backup
		// that was created but never ticked.
		$background = Job::create( 'export', array( 'background' => true ), array( 'work' ) );
		$this->saveAged( $background, 60 );
		$browser = Job::create( 'export', array(), array( 'work' ) );
		$browser->set( 'status', Job::STATUS_PAUSED );
		$this->saveAged( $browser, 30 * 60 );
		touch( $this->store->path( $browser->id() ), time() - 30 );

		( new Scheduler( $plugin ) )->runWorker();

		$this->assertSame( Job::STATUS_COMPLETED, $this->store->load( $background->id() )->status() );
		$this->assertSame( Job::STATUS_PAUSED, $this->store->load( $browser->id() )->status(), 'One job per run.' );
		$this->assertFalse( JobLock::isLocked( $this->storage->jobs(), $background->id() ) );

		( new Scheduler( $plugin ) )->runWorker();
		$this->assertSame( Job::STATUS_COMPLETED, $this->store->load( $browser->id() )->status() );
	}

	public function testWorkerSkipsALockedJobAndRespectsTheSetting() {
		$work    = $this->countingStage( 'work', 1 );
		$browser = Job::create( 'export', array(), array( 'work' ) );
		$browser->set( 'status', Job::STATUS_PAUSED );
		$this->saveAged( $browser, 600 );

		// "Background worker" off: browser migrations are left alone...
		( new Scheduler( $this->plugin( new ArrayResolver( array( 'work' => $work ) ), array( 'enable_cron_worker' => false ) ) ) )->runWorker();
		$this->assertSame( 0, $work->runs );
		$this->assertSame( Job::STATUS_PAUSED, $this->store->load( $browser->id() )->status() );
		$this->store->delete( $browser->id() );

		// ...but a background backup has no other fallback and is still run.
		$bg_work = $this->countingStage( 'work', 1 );
		$bg      = Job::create( 'export', array( 'background' => true ), array( 'work' ) );
		$this->saveAged( $bg, 600 );
		( new Scheduler( $this->plugin( new ArrayResolver( array( 'work' => $bg_work ) ), array( 'enable_cron_worker' => false ) ) ) )->runWorker();
		$this->assertSame( 1, $bg_work->runs );
		$this->store->delete( $bg->id() );

		$job = Job::create( 'export', array( 'background' => true ), array( 'work' ) );
		$this->saveAged( $job, 600 );

		$holder = new JobLock( $this->storage->jobs() );
		$this->assertTrue( $holder->acquire( $job->id() ) );
		( new Scheduler( $this->plugin( new ArrayResolver( array( 'work' => $work ) ) ) ) )->runWorker();
		$this->assertSame( 0, $work->runs );
		$this->assertSame( Job::STATUS_PENDING, $this->store->load( $job->id() )->status() );

		$holder->release( $job->id() );
		( new Scheduler( $this->plugin( new ArrayResolver( array( 'work' => $work ) ) ) ) )->runWorker();
		$this->assertSame( 1, $work->runs );
	}

	public function testDailyCleanupKeepsTheWorkFilesOfRunnableJobs() {
		$plugin = $this->plugin(
			new ArrayResolver( array() ),
			array(
				'cleanup_temp_hours' => 1,
				'retention_days'     => 30,
				'max_archives'       => 0,
			)
		);

		$running = Job::create( 'export', array(), array( 'work' ) );
		$running->set( 'status', Job::STATUS_PAUSED );
		$this->store->save( $running );
		$done = Job::create( 'export', array(), array( 'work' ) );
		$done->set( 'status', Job::STATUS_COMPLETED );
		$this->store->save( $done );

		$old   = time() - 2 * 3600;
		$files = array(
			'tmp/' . $running->id() . '-files.ndjson'    => true,  // Queue of a job still running: kept.
			'tmp/' . $running->id() . '-checksums.ndjson' => true,
			'tmp/' . $done->id() . '-files.ndjson'       => false, // Finished job: purged.
			'tmp/leftover.tmp'                           => false,
			'incoming/' . $running->id() . '-part'       => true,
			'incoming/abandoned.part'                    => false,
		);
		foreach ( $files as $relative => $kept ) {
			$path = $this->storage->base() . '/' . $relative;
			file_put_contents( $path, 'x' );
			touch( $path, $old );
		}
		$fresh = $this->storage->tmp() . '/fresh.tmp';
		file_put_contents( $fresh, 'x' );

		// Lock files: the running job's stays, a deleted job's goes.
		$jobs = $this->storage->jobs();
		touch( $jobs . '/' . $running->id() . '.lock' );
		touch( $jobs . '/20200101-000000-deadbeef.lock' );

		( new Scheduler( $plugin ) )->runCleanup();

		foreach ( $files as $relative => $kept ) {
			$this->assertSame( $kept, file_exists( $this->storage->base() . '/' . $relative ), $relative );
		}
		$this->assertFileExists( $fresh );
		$this->assertFileExists( $jobs . '/' . $running->id() . '.lock' );
		$this->assertFileDoesNotExist( $jobs . '/20200101-000000-deadbeef.lock' );
		$this->assertNotNull( $this->store->load( $running->id() ) );
		$this->assertNotNull( $this->store->load( $done->id() ) );
	}

	public function testBelongsToJobMatchesTheJobIdPrefixOnly() {
		$ids = array( '20260928-101500-a1b2c3d4', '' );
		$this->assertTrue( Scheduler::belongsToJob( '20260928-101500-a1b2c3d4', $ids ) );
		$this->assertTrue( Scheduler::belongsToJob( '20260928-101500-a1b2c3d4-files.ndjson', $ids ) );
		$this->assertFalse( Scheduler::belongsToJob( '20260928-101500-a1b2c3d45-files.ndjson', $ids ) );
		$this->assertFalse( Scheduler::belongsToJob( 'x20260928-101500-a1b2c3d4-files.ndjson', $ids ) );
		$this->assertFalse( Scheduler::belongsToJob( 'anything', array( '' ) ), 'An empty id matches nothing.' );
		$this->assertFalse( Scheduler::belongsToJob( 'anything', array() ) );
	}
	/* ------------------------------------------------------------------
	 * Stale copies, cancel requests, worker and housekeeping (review fixes)
	 * ------------------------------------------------------------------ */

	public function testACopyLoadedMidTickIsNotRunAgainOnceTheJobCompleted() {
		$work   = $this->countingStage( 'work', 3 );
		$first  = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$worker = new AnnouncingRunner( new JobStore( $this->storage ), new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );

		// The worker reads the job while the first request is half way
		// through its tick: same "ticks" count, older steps.
		$stale            = null;
		$work->before_run = function ( Job $ticking, RunCountingStage $stage ) use ( $worker, &$stale ) {
			if ( 2 === $stage->runs ) {
				$stale = $worker->store()->load( $ticking->id() );
			}
		};
		$done             = $first->tick( $job );
		$work->before_run = null;
		$this->assertSame( Job::STATUS_COMPLETED, $done->status() );
		$this->assertSame( 1, (int) $stale->get( 'ticks' ) );
		$this->assertSame( Job::STATUS_RUNNING, $stale->status() );
		$saved = (string) file_get_contents( $this->store->path( $job->id() ) );

		// The first request let go of the lock; the worker ticks its copy.
		$stale->setRuntime( 'password', 'kept' );
		$result = $worker->tick( $stale );
		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertSame( 3, $work->runs, 'A completed job is not advanced again.' );
		$this->assertSame( array( 1, 2, 3 ), $work->units );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_completed' ), 'Completed once.' );
		$this->assertSame( $saved, (string) file_get_contents( $this->store->path( $job->id() ) ), 'Nothing written back.' );
		$this->assertSame( 'kept', $result->password(), 'Runtime values stay with the caller\'s object.' );
		$this->assertFalse( $result->runtime( 'busy' ) );
	}

	public function testACopyLoadedMidTickDoesNotRedoFinishedUnits() {
		$work   = $this->countingStage( 'work', 6 );
		$first  = new JobRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$worker = new JobRunner( new JobStore( $this->storage ), new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$budget = new ScriptedBudget();

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );

		$stale           = null;
		$work->after_run = function ( Job $ticking, RunCountingStage $stage ) use ( $worker, $budget, &$stale ) {
			if ( 1 === $stage->runs ) {
				$stale = $worker->store()->load( $ticking->id() );
			}
			if ( 4 === $stage->runs ) {
				$budget->spent = true;
			}
		};
		$paused          = $first->tick( $job, $budget );
		$work->after_run = null;
		$this->assertSame( Job::STATUS_PAUSED, $paused->status() );
		$this->assertSame( 4, $paused->stageState( 'work' )['done'] );
		$this->assertSame( (int) $paused->get( 'ticks' ), (int) $stale->get( 'ticks' ), 'The stale copy looks as new as the saved one.' );

		$result = $worker->tick( $stale );
		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertSame( array( 1, 2, 3, 4, 5, 6 ), $work->units, 'Every unit ran exactly once.' );
	}

	public function testACancelRequestedBetweenTheLastCheckAndTheSaveIsNotLost() {
		$store   = new SaveHookStore( $this->storage );
		$work    = $this->countingStage( 'work', 50 );
		$runner  = new AnnouncingRunner( $store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$request = $this->cancellingRunner( new ArrayResolver( array( 'work' => $work ) ) );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$store->save( $job );

		// The cancel lands after the "after stage" check, right before the
		// pausing save: the ticking request writes "paused" regardless.
		$seen               = null;
		$store->before_save = function ( Job $saving ) use ( $request, &$seen ) {
			if ( null === $seen && Job::STATUS_PAUSED === $saving->status() ) {
				$result = $request->cancelJob( $request->store()->load( $saving->id() ) );
				$seen   = array( $result->status(), $request->cancelRequested( $result ) );
			}
		};

		$result = $runner->tick( $job, new Budget( -1, 0 ) );

		$this->assertSame( array( Job::STATUS_RUNNING, true ), $seen, 'The canceller was told a cancel is pending.' );
		$this->assertSame( Job::STATUS_CANCELLED, $result->status() );
		$this->assertSame( Job::STATUS_CANCELLED, ( new JobStore( $this->storage ) )->load( $job->id() )->status(), 'The cancel survives the save.' );
		$this->assertSame( 1, $work->runs );
		$this->assertSame( 1, $work->cleanups );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ) );
		$this->assertFalse( JobLock::isLocked( $this->storage->jobs(), $job->id() ) );
	}

	public function testTheReviewersLostCancelInterleavingEndsCancelled() {
		// Same interleaving as the review's reproduction: the cancel runs
		// right after the fourth load, the "after stage" status read.
		$store   = new LoadHookStore( $this->storage );
		$work    = $this->countingStage( 'work', 50 );
		$runner  = new AnnouncingRunner( $store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$request = $this->cancellingRunner( new ArrayResolver( array( 'work' => $work ) ) );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$store->save( $job );
		$fired             = 0;
		$store->after_load = function () use ( $request, $job, $work, &$fired ) {
			if ( 0 === $fired && 1 === $work->runs ) {
				++$fired;
				$request->cancelJob( $request->store()->load( $job->id() ) );
			}
		};

		$runner->tick( $job, new Budget( -1, 0 ) );
		$this->assertSame( 1, $fired );
		$this->assertSame( Job::STATUS_CANCELLED, ( new JobStore( $this->storage ) )->load( $job->id() )->status() );
		$this->assertSame( 1, $work->cleanups );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
	}

	public function testAJobCancelledElsewhereMidTickIsNeitherOverwrittenNorAnnouncedAgain() {
		$work   = $this->countingStage( 'work', 50 );
		$runner = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$other  = new JobStore( $this->storage );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );

		// Where flock() does not work, another request can cancel (clean up,
		// save, announce) while this one runs a step.
		$work->after_run = function ( Job $ticking ) use ( $other ) {
			$stored = $other->load( $ticking->id() );
			$stored->set( 'status', Job::STATUS_CANCELLED );
			$stored->set( 'message', 'Cancelled elsewhere.' );
			$stored->set( 'finished_at', 1234 );
			$other->save( $stored );
		};

		$result = $runner->tick( $job );
		$this->assertSame( Job::STATUS_CANCELLED, $result->status() );
		$this->assertSame( 1, $work->runs );
		$this->assertSame( 1, $work->cleanups, 'What this step set up is undone.' );
		$this->assertSame( array(), AnnouncingRunner::$announced, 'Already announced by the other request.' );
		$saved = $this->store->load( $job->id() );
		$this->assertSame( 'Cancelled elsewhere.', $saved->get( 'message' ) );
		$this->assertSame( 1234, (int) $saved->get( 'finished_at' ) );
	}

	public function testTickingAStaleCopyOfACancelledJobDoesNothing() {
		$work   = $this->countingStage( 'work', 5 );
		$runner = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$job   = $runner->tick( $job, new Budget( -1, 0 ) );
		$stale = $this->store->load( $job->id() );

		$cancelled = $runner->cancelJob( $this->store->load( $job->id() ) );
		$this->assertSame( Job::STATUS_CANCELLED, $cancelled->status() );
		$this->assertSame( 1, $work->cleanups );

		$result = $runner->tick( $stale );
		$this->assertSame( Job::STATUS_CANCELLED, $result->status() );
		$this->assertSame( 1, $work->runs );
		$this->assertSame( 1, $work->cleanups, 'No second cleanup.' );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ), 'No second announcement.' );
	}

	public function testCancelJobFromInsideItsOwnTickStopsAfterTheStep() {
		$work   = $this->countingStage( 'work', 50 );
		$runner = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );
		$inside = array();

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$work->after_run = function ( Job $ticking, RunCountingStage $stage ) use ( $runner, &$inside ) {
			if ( 1 === $stage->runs ) {
				$result = $runner->cancelJob( $ticking );
				$inside = array( $result->status(), $stage->cleanups, $runner->cancelRequested( $ticking ) );
			}
		};

		$job = $runner->tick( $job );
		$this->assertSame( array( Job::STATUS_RUNNING, 0, true ), $inside, 'Nothing is undone under the running step.' );
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertSame( 1, $work->runs );
		$this->assertSame( 1, $work->cleanups );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
	}

	public function testCancelJobCarriesTheCancelOutWhenTheHolderLetGoMeanwhile() {
		$work   = $this->countingStage( 'work', 5 );
		$runner = new AnnouncingRunner( $this->store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $job );
		$job = $runner->tick( $job, new Budget( -1, 0 ) );

		// The first attempt finds the lock taken; by the second the holder
		// is gone and may never have seen the request.
		$lock = new RefusingLock( $this->storage->jobs() );
		$runner->useLock( $lock );

		$result = $runner->cancelJob( $this->store->load( $job->id() ) );
		$this->assertSame( 0, $lock->refuse );
		$this->assertSame( Job::STATUS_CANCELLED, $result->status() );
		$this->assertSame( 'Migration cancelled.', $result->get( 'message' ) );
		$this->assertSame( 1, $work->cleanups );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ) );
		$this->assertFalse( $lock->isHeld( $job->id() ) );
	}

	public function testARequestDyingWhileSavingTheCancelLeavesItPending() {
		$store  = new SaveHookStore( $this->storage );
		$work   = $this->countingStage( 'work', 5 );
		$runner = new AnnouncingRunner( $store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$job->set( 'status', Job::STATUS_PAUSED );
		$store->save( $job );
		JobLock::requestCancel( $this->storage->jobs(), $job->id() );

		// The request dies (a timeout, say) while saving the cancelled job.
		$store->before_save = function ( Job $saving ) {
			if ( Job::STATUS_CANCELLED === $saving->status() ) {
				throw new \RuntimeException( 'request died' );
			}
		};
		try {
			$runner->tick( $job );
			$this->fail( 'The simulated death did not happen.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'request died', $e->getMessage() );
		}
		$this->assertTrue( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ), 'The cancel is still pending, not lost.' );
		$this->assertSame( Job::STATUS_PAUSED, $this->store->load( $job->id() )->status() );
		$this->assertSame( array(), AnnouncingRunner::$announced );
		$this->assertFalse( JobLock::isLocked( $this->storage->jobs(), $job->id() ) );

		// The next tick carries it out.
		$store->before_save = null;
		$result             = $runner->tick( $this->store->load( $job->id() ) );
		$this->assertSame( Job::STATUS_CANCELLED, $result->status() );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ) );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
	}

	public function testACancelWhoseSaveFailsStaysPendingAndIsAnnouncedOnce() {
		$store  = new SaveHookStore( $this->storage );
		$work   = $this->countingStage( 'work', 5 );
		$runner = new AnnouncingRunner( $store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$job->set( 'status', Job::STATUS_PAUSED );
		$store->save( $job );
		JobLock::requestCancel( $this->storage->jobs(), $job->id() );

		// The disk is full when the cancelled state is written.
		$store->refuse = function ( Job $saving ) {
			return Job::STATUS_CANCELLED === $saving->status();
		};
		$runner->tick( $job );
		$this->assertTrue( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ) );
		$this->assertSame( Job::STATUS_PAUSED, $this->store->load( $job->id() )->status() );
		$this->assertSame( array(), AnnouncingRunner::$announced, 'Not announced while nothing was recorded.' );

		$store->refuse = null;
		$result        = $runner->tick( $this->store->load( $job->id() ) );
		$this->assertSame( Job::STATUS_CANCELLED, $result->status() );
		$this->assertSame( Job::STATUS_CANCELLED, $this->store->load( $job->id() )->status() );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ) );
		$this->assertSame( 1, AnnouncingRunner::count( 'shcm_job_cancelled' ) );
	}

	public function testACancelRequestForAJobThatFinishedMeanwhileIsDropped() {
		$work    = $this->countingStage( 'work', 5 );
		$holder  = new JobLock( $this->storage->jobs() );
		$store   = new LoadHookStore( $this->storage );
		$request = new AnnouncingRunner( $store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$job->set( 'status', Job::STATUS_PAUSED );
		$this->store->save( $job );
		$this->assertTrue( $holder->acquire( $job->id() ) );

		// The holder completes the job while the request is being written
		// (after the canceller's first look at the job).
		$store->after_load = function ( $n ) use ( $job ) {
			if ( 2 === $n ) {
				$done = $this->store->load( $job->id() );
				$done->set( 'status', Job::STATUS_COMPLETED );
				$this->store->save( $done );
			}
		};
		$result = $request->cancelJob( $store->load( $job->id() ) );

		$this->assertSame( Job::STATUS_COMPLETED, $result->status() );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ), 'No marker outlives its job.' );
		$this->assertSame( array(), AnnouncingRunner::$announced );
		$this->assertSame( 0, $work->cleanups );
	}

	public function testACancelRequestArrivingAfterTheFinalCheckIsDroppedOnCompletion() {
		$store  = new SaveHookStore( $this->storage );
		$work   = $this->countingStage( 'work', 1 );
		$runner = new AnnouncingRunner( $store, new ArrayResolver( array( 'work' => $work ) ), $this->logger, $this->settings );

		$job = Job::create( 'export', array(), array( 'work' ) );
		$store->save( $job );
		$store->before_save = function ( Job $saving ) {
			if ( Job::STATUS_COMPLETED === $saving->status() ) {
				JobLock::requestCancel( $this->storage->jobs(), $saving->id() );
			}
		};

		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_COMPLETED, $job->status() );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $job->id() ) );
		$this->assertSame( array( array( 'shcm_job_completed', $job->id(), Job::STATUS_COMPLETED ) ), AnnouncingRunner::$announced );
		$this->assertSame( 0, $work->cleanups );
	}

	public function testCancelRequestedOnlyReportsJobsThatCanStillStop() {
		$runner = new JobRunner( $this->store, new ArrayResolver( array() ), $this->logger, $this->settings );
		$dir    = $this->storage->jobs();

		$job = Job::create( 'export', array(), array( 'work' ) );
		$job->set( 'status', Job::STATUS_PAUSED );
		$this->store->save( $job );
		$this->assertFalse( $runner->cancelRequested( $job ) );
		$this->assertFalse( $runner->cancelRequested( $job->id() ) );

		JobLock::requestCancel( $dir, $job->id() );
		$this->assertTrue( $runner->cancelRequested( $job ) );
		$this->assertTrue( $runner->cancelRequested( $job->id() ) );

		// A marker left next to a finished job means nothing.
		$job->set( 'status', Job::STATUS_COMPLETED );
		$this->store->save( $job );
		$this->assertFalse( $runner->cancelRequested( $job ) );
		$this->assertFalse( $runner->cancelRequested( $job->id() ) );

		// Nor does one for a job that no longer exists, or a bad id.
		$this->store->delete( $job->id() );
		$this->assertFalse( $runner->cancelRequested( $job->id() ) );
		$this->assertFalse( $runner->cancelRequested( '../x' ) );
	}

	public function testWorkerCarriesOutAnAbandonedCancelRequestAtOnce() {
		$dir = $this->storage->jobs();
		$now = time();

		// Encrypted without a password source, touched a moment ago: the
		// worker would never take it over, but a cancel needs neither.
		$encrypted = Job::create( 'export', array( 'encrypted' => true ), array( 'work' ) );
		$encrypted->set( 'status', Job::STATUS_RUNNING )->set( 'updated_at', $now );
		$pending = Job::create( 'export', array(), array( 'work' ) );
		$pending->set( 'updated_at', $now );
		$waiting = Job::create( 'export', array( 'background' => true ), array( 'work' ) );
		$waiting->set( 'status', Job::STATUS_PAUSED )->set( 'updated_at', $now );
		$waiting->setShared( 'resume_at', $now + 600 );

		foreach ( array( $encrypted, $pending, $waiting ) as $candidate ) {
			$this->assertFalse( Scheduler::workerMayTick( $candidate, $dir, $now ) );
			JobLock::requestCancel( $dir, $candidate->id() );
			$this->assertTrue( Scheduler::workerMayTick( $candidate, $dir, $now ), $candidate->id() );
		}

		// A live holder carries it out itself.
		$holder = new JobLock( $dir );
		$this->assertTrue( $holder->acquire( $encrypted->id() ) );
		$this->assertFalse( Scheduler::workerMayTick( $encrypted, $dir, $now ) );
		$holder->release( $encrypted->id() );

		// The worker ticks it and the job ends cancelled.
		$work = $this->countingStage( 'work', 5 );
		$job  = Job::create( 'export', array( 'encrypted' => true ), array( 'work' ) );
		$job->set( 'status', Job::STATUS_RUNNING );
		$this->store->save( $job );
		JobLock::requestCancel( $dir, $job->id() );

		( new Scheduler( $this->plugin( new ArrayResolver( array( 'work' => $work ) ) ) ) )->runWorker();

		$saved = $this->store->load( $job->id() );
		$this->assertSame( Job::STATUS_CANCELLED, $saved->status() );
		$this->assertSame( 0, $work->runs );
		$this->assertSame( 1, $work->cleanups );
		$this->assertFalse( JobLock::cancelRequested( $dir, $job->id() ) );
	}

	public function testWorkerPicksACancelRequestFirst() {
		$dir        = $this->storage->jobs();
		$now        = time();
		$background = Job::create( 'export', array( 'background' => true ), array( 'work' ) );
		$background->set( 'status', Job::STATUS_PAUSED )->set( 'updated_at', $now - 600 );
		$browser = Job::create( 'export', array(), array( 'work' ) );
		$browser->set( 'status', Job::STATUS_PAUSED )->set( 'updated_at', $now - 600 );
		$cancelling = Job::create( 'export', array(), array( 'work' ) );
		$cancelling->set( 'status', Job::STATUS_RUNNING )->set( 'updated_at', $now );

		$this->assertSame( $background, Scheduler::pickWorkerJob( array( $browser, $background, $cancelling ), $dir, $now ) );
		JobLock::requestCancel( $dir, $cancelling->id() );
		$this->assertSame( $cancelling, Scheduler::pickWorkerJob( array( $browser, $background, $cancelling ), $dir, $now ) );
	}

	public function testWorkerLeavesABackgroundJobThatAskedToResumeLater() {
		$dir = $this->storage->jobs();
		$now = time();
		$job = function ( array $params, $resume_at ) use ( $now ) {
			$job = Job::create( 'export', $params, array( 'work' ) );
			$job->set( 'status', Job::STATUS_PAUSED )->set( 'updated_at', $now - 600 );
			$job->setShared( 'resume_at', $resume_at );
			return $job;
		};
		$bg = array( 'background' => true );

		$this->assertFalse( Scheduler::workerMayTick( $job( $bg, $now + 300 ), $dir, $now ), 'Its WP-Cron event comes back for it.' );
		$this->assertFalse( Scheduler::workerMayTick( $job( $bg, $now + 1 ), $dir, $now ) );
		$this->assertTrue( Scheduler::workerMayTick( $job( $bg, $now ), $dir, $now ), 'Due now.' );
		$this->assertTrue( Scheduler::workerMayTick( $job( $bg, $now - 60 ), $dir, $now ), 'Overdue: the event did not fire.' );
		$this->assertTrue( Scheduler::workerMayTick( $job( $bg, 0 ), $dir, $now ) );
		$this->assertTrue( Scheduler::workerMayTick( $job( array(), $now + 300 ), $dir, $now ), 'Only background jobs are held back.' );

		// And the worker does not tick it.
		$work    = $this->countingStage( 'work', 1 );
		$waiting = Job::create( 'export', $bg, array( 'work' ) );
		$waiting->set( 'status', Job::STATUS_PAUSED );
		$waiting->setShared( 'resume_at', time() + 300 );
		$this->saveAged( $waiting, 600 );
		( new Scheduler( $this->plugin( new ArrayResolver( array( 'work' => $work ) ) ) ) )->runWorker();
		$this->assertSame( 0, $work->runs );
		$this->assertSame( Job::STATUS_PAUSED, $this->store->load( $waiting->id() )->status() );
	}

	/**
	 * Create an archive file (contents do not matter to the pruning).
	 *
	 * @param string $name Base name.
	 * @param int    $age  Seconds since it was written.
	 * @return string Path.
	 */
	protected function archive( $name, $age ) {
		$path = $this->storage->archives() . '/' . $name;
		file_put_contents( $path, 'x' );
		touch( $path, time() - $age );
		return $path;
	}

	/**
	 * A stand-in for the backup module, whose history lists some archives.
	 *
	 * @param string[]|null $names Archive names, or null for a history that fails.
	 * @return object
	 */
	protected function backups( $names ) {
		return new class( $names ) {
			/**
			 * Names.
			 *
			 * @var string[]|null
			 */
			public $names;

			/**
			 * Constructor.
			 *
			 * @param string[]|null $names Names.
			 */
			public function __construct( $names ) {
				$this->names = $names;
			}

			/**
			 * History.
			 *
			 * @return object
			 */
			public function history() {
				if ( null === $this->names ) {
					throw new \RuntimeException( 'history unreadable' );
				}
				$names = $this->names;
				return new class( $names ) {
					/**
					 * Names.
					 *
					 * @var string[]
					 */
					public $names;

					/**
					 * Constructor.
					 *
					 * @param string[] $names Names.
					 */
					public function __construct( $names ) {
						$this->names = $names;
					}

					/**
					 * Archive names.
					 *
					 * @return string[]
					 */
					public function archiveNames() {
						return $this->names;
					}
				};
			}
		};
	}

	public function testMaxArchivesSparesBackupArchivesAndArchivesInUse() {
		$plugin = $this->plugin(
			new ArrayResolver( array() ),
			array(
				'cleanup_temp_hours' => 0,
				'retention_days'     => 0,
				'max_archives'       => 1,
			)
		);
		$plugin->setService( 'backups', $this->backups( array( 'backup-new.wpress', 'backup-old.wpress' ) ) );

		$this->archive( 'manual-newest.wpress', 100 );
		$this->archive( 'backup-new.wpress', 200 );
		$this->archive( 'manual-older.wpress', 300 );
		$this->archive( 'importing.wpress', 400 );
		$this->archive( 'manual-finished-job.wpress', 500 );
		$this->archive( 'manual-oldest.wpress', 600 );
		$this->archive( 'backup-old.wpress', 700 );
		file_put_contents( $this->storage->archives() . '/manual-older.wpress.sha256', 'x' );

		// A runnable job works with one archive; a finished job's archive is
		// not protected.
		$import = Job::create( 'import', array( 'archive_path' => $this->storage->archives() . '/importing.wpress' ), array( 'work' ) );
		$import->set( 'status', Job::STATUS_PAUSED );
		$this->store->save( $import );
		$finished = Job::create( 'export', array( 'archive_path' => $this->storage->archives() . '/manual-finished-job.wpress' ), array( 'work' ) );
		$finished->set( 'status', Job::STATUS_COMPLETED );
		$this->store->save( $finished );

		( new Scheduler( $plugin ) )->runCleanup();

		$left = array_map( 'basename', glob( $this->storage->archives() . '/*.wpress' ) );
		sort( $left );
		$this->assertSame( array( 'backup-new.wpress', 'backup-old.wpress', 'importing.wpress', 'manual-newest.wpress' ), $left );
		$this->assertFileDoesNotExist( $this->storage->archives() . '/manual-older.wpress.sha256' );
	}

	public function testMaxArchivesIsSkippedWhenTheBackupHistoryCannotBeRead() {
		$plugin = $this->plugin(
			new ArrayResolver( array() ),
			array(
				'cleanup_temp_hours' => 0,
				'retention_days'     => 0,
				'max_archives'       => 1,
			)
		);
		$plugin->setService( 'backups', $this->backups( null ) );
		$this->archive( 'a.wpress', 100 );
		$this->archive( 'b.wpress', 200 );
		$this->archive( 'c.wpress', 300 );

		( new Scheduler( $plugin ) )->runCleanup();
		$this->assertCount( 3, glob( $this->storage->archives() . '/*.wpress' ), 'Nothing is deleted while backups cannot be told apart.' );

		// With a readable history (listing none of them) the limit applies.
		$plugin->setService( 'backups', $this->backups( array() ) );
		( new Scheduler( $plugin ) )->runCleanup();
		$this->assertSame( array( $this->storage->archives() . '/a.wpress' ), glob( $this->storage->archives() . '/*.wpress' ) );
	}

	public function testOrphanedChecksumFilesAndAttemptMarkersAreRemoved() {
		$plugin = $this->plugin(
			new ArrayResolver( array() ),
			array(
				'cleanup_temp_hours' => 0,
				'retention_days'     => 0,
				'max_archives'       => 0,
			)
		);
		$dir = $this->storage->archives();
		$this->archive( 'kept.wpress', 10 );
		foreach ( array( '.sha256', '.sha256-attempt', '.md5-attempt' ) as $suffix ) {
			file_put_contents( $dir . '/kept.wpress' . $suffix, '1' );
			file_put_contents( $dir . '/gone.wpress' . $suffix, '1' );
		}

		( new Scheduler( $plugin ) )->runCleanup();

		foreach ( array( '.sha256', '.sha256-attempt', '.md5-attempt' ) as $suffix ) {
			$this->assertFileExists( $dir . '/kept.wpress' . $suffix );
			$this->assertFileDoesNotExist( $dir . '/gone.wpress' . $suffix );
		}
	}

	public function testDailyCleanupRemovesCancelMarkersOfDeletedJobs() {
		$plugin = $this->plugin(
			new ArrayResolver( array() ),
			array(
				'cleanup_temp_hours' => 0,
				'retention_days'     => 0,
				'max_archives'       => 0,
			)
		);
		$live = Job::create( 'export', array(), array( 'work' ) );
		$live->set( 'status', Job::STATUS_PAUSED );
		$this->store->save( $live );
		$deleted = Job::create( 'export', array(), array( 'work' ) );
		$this->store->save( $deleted );
		JobLock::requestCancel( $this->storage->jobs(), $live->id() );
		JobLock::requestCancel( $this->storage->jobs(), $deleted->id() );
		$this->store->delete( $deleted->id() );

		( new Scheduler( $plugin ) )->runCleanup();

		$this->assertTrue( JobLock::cancelRequested( $this->storage->jobs(), $live->id() ) );
		$this->assertFalse( JobLock::cancelRequested( $this->storage->jobs(), $deleted->id() ) );
	}
}
