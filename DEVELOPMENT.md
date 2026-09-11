# Development

## Getting set up

```bash
git clone <repository>
cd SH-Clone-Migration/sh-clone-migration
composer install
```

Composer is only needed for the test suite. The plugin itself has no runtime
dependencies and ships without a `vendor` directory — it uses its own PSR-4
autoloader in `includes/bootstrap.php`.

To work against a real site, symlink or copy `sh-clone-migration/` into a
WordPress installation's `wp-content/plugins/` and activate it.

## Running the tests

```bash
composer test                 # unit suite
composer test:all             # unit + integration
vendor/bin/phpunit --filter ReplacerTest
```

The suite needs no WordPress installation. `tests/bootstrap.php` loads the
plugin's autoloader and `tests/wp-shims.php` provides faithful stand-ins for
the handful of WordPress functions the engine touches (`is_serialized`,
`maybe_unserialize`, `wp_parse_url`, …).

| Suite | Covers |
|---|---|
| `tests/unit/ArchiveTest.php` | Container round trips, mid-entry resume, torn-tail recovery, compression, encryption, corruption and truncation detection, resumable extraction |
| `tests/unit/SerializedRewriterTest.php` | Every serialized token type, nesting, references, unknown classes, `Serializable` payloads, enums, refusal to touch broken payloads |
| `tests/unit/ReplacerTest.php` | URL variants (schemes, protocol-relative, percent-encoded, JSON-escaped), serialized values, filesystem paths, bare domains, external URLs left alone |
| `tests/unit/SqlStreamReaderTest.php` | Statement splitting across arbitrary chunk boundaries, quotes, escapes, comments, executable comments, linear performance |
| `tests/unit/FilesystemTest.php` | Path traversal, symlink escape, exclusion globs, on-disk queue resume |
| `tests/unit/JobsTest.php` | Stage progression, pause and resume, failure and cleanup, cancellation, weighted progress, secrets never persisted |
| `tests/unit/SupportTest.php` | Size parsing and packing, JSON helpers, log redaction, prefix rewriting, cipher round trips, settings sanitisation |
| `tests/integration/FilePipelineTest.php` | A real directory tree scanned, archived, restored and compared byte for byte; hostile archives refused; scan resumability |

The end-to-end migration test (a real WordPress site cloned onto another one)
is described in [docs/TEST-RESULTS.md](docs/TEST-RESULTS.md).

## Coding standards

WordPress Coding Standards: tabs, Yoda-free but always-braced conditionals,
`snake_case` for WordPress-facing functions and hooks, `camelCase` for
internal class methods, `PascalCase` classes under the `SHCM\` namespace with
one class per file mapped PSR-4 style onto `includes/`.

Everything global is prefixed `shcm_`: options, hooks, AJAX actions, the
capability, cron events and the storage directory.

Rules the codebase holds itself to:

- **No unbounded memory.** No `file_get_contents()` on a file that could be
  large, no `SELECT *` without a limit, no array that grows with the number of
  files or rows.
- **Everything is resumable.** A loop that can run long takes a `Budget` and
  records its position in the job state.
- **Structured results.** Operations return `Result` objects or throw; nothing
  returns a bare `false` and hopes the caller guesses why.
- **Nothing fails silently.** A skipped file, an unparsable serialized value
  and a missing plugin all end up in the job's warnings and in the log.

## Architecture in one paragraph

`JobRunner` advances a `Job` through an ordered list of `Stage` objects,
giving each one a `Budget` and saving the job's JSON state file after every
call. Stages use the `Archive`, `Database`, `Filesystem`, `URL` and
`Compatibility` components to do the actual work. See
[ARCHITECTURE.md](ARCHITECTURE.md).

## Hooks

### Filters

```php
/**
 * The capability required for every migration operation.
 *
 * @param string $capability Default 'shcm_manage_migrations'.
 */
apply_filters( 'shcm_required_capability', $capability );

/**
 * Exclusion patterns applied to an export.
 *
 * @param string[]      $patterns Glob patterns, relative to the WordPress root.
 * @param SHCM\Jobs\Job $job      The export job.
 */
apply_filters( 'shcm_export_exclusions', $patterns, $job );

/**
 * The ordered stages of a job type.
 *
 * @param string[] $stages Stage keys.
 * @param string   $type   'export', 'import' or 'search_replace'.
 * @param array    $params Job parameters.
 */
apply_filters( 'shcm_job_stages', $stages, $type, $params );
```

### Actions

```php
do_action( 'shcm_job_completed', SHCM\Jobs\Job $job );
do_action( 'shcm_job_failed', SHCM\Jobs\Job $job, Throwable $error );
do_action( 'shcm_job_cancelled', SHCM\Jobs\Job $job );
```

## Programmatic API

```php
$plugin     = shcm_bootstrap();
$controller = new SHCM\Admin\Controller( $plugin );

// Start an export and run it to completion.
$job = $controller->startExport( array( 'name' => 'nightly' ) );
while ( ! in_array( $job['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
    $job = $controller->tick( $job['id'] );
}

$path = $plugin->jobs()->load( $job['id'] )->param( 'archive_path' );
```

Useful services on the container:

```php
$plugin->settings();     // SHCM\Core\Settings
$plugin->storage();      // SHCM\Filesystem\Storage
$plugin->logger();       // SHCM\Logging\Logger
$plugin->environment();  // SHCM\Core\Environment
$plugin->inspector();    // SHCM\Database\Inspector
$plugin->jobs();         // SHCM\Jobs\JobStore
$plugin->runner();       // SHCM\Jobs\JobRunner
```

### Reading an archive

```php
$reader = new SHCM\Archive\Reader( $path, $password );
$entry  = $reader->findEntry( 'manifest.json' );
$manifest = json_decode( $reader->readString( $entry ), true );

$verifier = new SHCM\Archive\Verifier( $reader );
$result   = $verifier->verifyAll();   // ok, errors, checked, bytes
```

### Replacing URLs in your own code

```php
$replacer = SHCM\URL\RuleBuilder::forUrls( 'https://old.test', 'https://new.test' );
$result   = $replacer->apply( $value );

if ( $result['failed'] ) {
    // Unparsable serialized payload: left untouched on purpose.
} elseif ( $result['changed'] ) {
    $value = $result['value'];
}
```

## REST API

Namespace `shcm/v1`, authenticated with a WordPress cookie plus an `X-WP-Nonce`
header, or any other authentication your site accepts. Every route requires
the migration capability.

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/jobs` | Recent jobs |
| `POST` | `/jobs` | Start one (`type`: `export`, `import`, `search_replace`) |
| `GET` | `/jobs/<id>` | Job status |
| `POST` | `/jobs/<id>/tick` | Advance a job |
| `POST` | `/jobs/<id>/cancel` | Cancel a job |
| `GET` | `/archives` | Stored archives |
| `GET` | `/status` | System status report |

## AJAX actions

All under `wp_ajax_shcm_*`, all requiring the capability and the
`shcm_migration` nonce: `start_export`, `start_import`, `start_replace`,
`start_rollback`, `tick`, `status`, `cancel`, `delete_job`, `jobs`,
`resumable`, `archives`,
`archive_details`, `delete_archive`, `verify_archive`, `save_settings`,
`system_status`, `upload_begin`, `upload_chunk`, `upload_status`,
`upload_finish`, `upload_abort`, `adopt_archive`.

`tick`, `status` and `cancel` also accept a job token, which is what lets a
restore finish after it has replaced the user account driving it.

## Adding a stage

```php
namespace SHCM\Export\Stages;

use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;

class MyStage extends AbstractStage {

	public function key() {
		return 'my_stage';
	}

	public function label() {
		return __( 'Doing my thing', 'sh-clone-migration' );
	}

	public function weight() {
		return 5;   // Relative cost, used for overall progress.
	}

	public function run( Job $job, Budget $budget ) {
		$state = $job->stageState( $this->key(), array( 'offset' => 0 ) );

		$processed = 0;
		while ( $budget->shouldContinue( $processed ) ) {
			// ... one unit of work, then record where you got to ...
			$state['offset']++;
			++$processed;

			if ( $this->isFinished( $state ) ) {
				$job->setStageState( $this->key(), $state );
				return $this->complete( __( 'Done', 'sh-clone-migration' ) );
			}
		}

		$job->setStageState( $this->key(), $state );
		return $this->progress( __( 'Working', 'sh-clone-migration' ), $state['offset'] / 100 );
	}

	public function cleanup( Job $job, $error = null ) {
		// Undo anything global you switched on.
	}
}
```

Register it in `SHCM\Jobs\Registry::map()`, or insert its key with the
`shcm_job_stages` filter.

Two rules: `run()` must be safe to call again after any return, and it must
use `$budget->shouldContinue()` so it always performs at least one unit of
work per request.

## Debugging

- Per-job logs live in `wp-content/shcm-storage/logs/<job-id>.log` and are
  downloadable from the Backups screen. Set the log level to `debug` in
  Settings for per-entry detail.
- Job state is readable JSON in `wp-content/shcm-storage/jobs/`.
- `wp shcm status` prints the most recent job, its stage and its warnings.
- `wp shcm doctor` prints the environment report.
- Everything written to a log passes through `SHCM\Logging\Redactor` first, so
  logs are safe to attach to a support ticket.

## Releasing

```bash
cd sh-clone-migration
composer install --no-dev
rm -rf vendor .phpunit.cache tests
cd ..
zip -r sh-clone-migration.zip sh-clone-migration \
    -x '*/node_modules/*' '*/.git/*' '*.phpunit.cache*'
```

Bump the version in three places: the plugin header, the `SHCM_VERSION`
constant and `readme.txt`'s stable tag.
