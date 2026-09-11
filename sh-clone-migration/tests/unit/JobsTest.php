<?php
/**
 * Job engine tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SHCM\Core\Result;
use SHCM\Core\Settings;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Jobs\JobRunner;
use SHCM\Jobs\JobStore;
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

		// Another request marks the job cancelled by writing to the job file.
		$stored = $this->store->load( $job->id() );
		$stored->set( 'status', Job::STATUS_CANCELLED );
		$this->store->save( $stored );

		$job = $runner->tick( $job );
		$this->assertSame( Job::STATUS_CANCELLED, $job->status() );
		$this->assertTrue( $stages['work']->cleaned );
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
}
