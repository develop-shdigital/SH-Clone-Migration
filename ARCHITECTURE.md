# Architecture

How SH Clone Migration is put together, and the reasoning behind the parts
that are not obvious.

## The shape of the problem

A migration plugin has to do two things that are individually easy and
together hard:

1. Move an unbounded amount of data,
2. inside a PHP request that will be killed after 30 seconds.

Everything below follows from that. There is no step that assumes it owns the
request, no data structure that grows with the size of the site, and no
operation that cannot be stopped half way through and picked up by a different
request minutes later.

A second constraint shapes the import in particular: **the code doing the work
is running on the site it is replacing.** Half way through a restore, the
database the plugin reads its own settings from has been swapped out, the user
account driving the migration no longer exists, and the plugins listed in
`active_plugins` are from a site whose files have not arrived yet. Each of
those has a specific answer below.

```
                       ┌──────────────────────────────┐
   Admin UI  ─────────▶│                              │
   WP-CLI    ─────────▶│          JobRunner           │
   WP-Cron   ─────────▶│  (the only thing that moves  │
   endpoint.php ──────▶│   a job's state forward)     │
                       └───────────────┬──────────────┘
                                       │
                        ┌──────────────▼──────────────┐
                        │           Stages            │
                        │  initialize → scan → …      │
                        └──────────────┬──────────────┘
                                       │
        ┌───────────────┬──────────────┼───────────────┬────────────────┐
        ▼               ▼              ▼               ▼                ▼
   Archive         Database       Filesystem         URL            Compatibility
   Writer/Reader   Exporter/      Scanner/Queue/     Replacer/      Elementor/Woo/
   Verifier        Importer       SafePath           Rewriter       ACF
```

## Jobs and stages

A **job** is a resumable unit of work with a type (`export`, `import`,
`search_replace`), an ordered list of **stages**, and a state blob.

A stage implements one method that matters:

```php
public function run( Job $job, Budget $budget ): Result;
```

It does as much work as the budget allows, records where it got to in the
job's state, and returns either "still going, here is my progress" or
"finished". A stage must be safe to call again after any return — that is the
entire contract, and it is what makes every stage resumable.

`JobRunner` is the only code that changes a job's status. It loops stages
until the budget expires, saves after every stage call, catches anything
thrown, runs cleanup handlers on failure, and honours a cancellation written
to the job file by another request. Because the runner is shared, a job
behaves identically whether it is driven by AJAX, by WP-Cron or by WP-CLI.

`Budget` is the clock. It reads `max_execution_time`, keeps 60% of it (25
seconds when there is no limit), tries to lift the limit with
`set_time_limit(0)`, and watches memory against a configurable percentage of
`memory_limit`. Every loop in the engine asks the budget whether to continue
— and `Budget::shouldContinue()` always allows the first unit of work in a
request, so a host with a pathologically small budget still makes progress
instead of handing control back and forth forever.

### Job state lives on disk, not in the database

Job state is a JSON file under `wp-content/shcm-storage/jobs/`, written to a
temporary file and renamed into place so it is never observed half written.

This is not a preference. An import replaces the entire database part way
through its own run; a job that lived in `wp_options` would delete itself
mid-flight. The same reasoning puts logs in files.

### Stage lists

```
export:          initialize → scan → database → files → finalize → verify
import:          initialize → validate → rollback → database → files → urls
                 → compatibility → verify → finalize
search_replace:  initialize → replace → finalize
```

Stages are weighted, so overall progress reflects real cost: the file stage is
worth 50 and the finalize stage is worth 2.

## The archive

`.wpress` is a forward-only container of independently coded blocks. The full
byte layout is in [docs/ARCHIVE-FORMAT.md](docs/ARCHIVE-FORMAT.md); the design
decisions are here.

**Why not ZIP?** A ZIP central directory is written at the end and has to be
rebuilt when an entry changes size, streaming ZIP readers cannot seek, and
`ZipArchive` wants the whole archive in memory for some operations. More
importantly, an entry has to be resumable *mid-entry*: a 10 GB video will not
fit in one request, and no ZIP library will let a second PHP process continue
an entry a first one started.

**Blocks.** Each entry's payload is a sequence of blocks, each with its own
one-byte flags and two length fields. A block is compressed on its own, so a
reader can skip an entry with one `fseek` per block and resume decoding at any
block boundary. Blocks are 1 MB by default, which is also the engine's memory
ceiling for file data.

**Patchable headers.** An entry's size and digest are only known once its
payload has been written, so the header is written first with fixed-width
placeholders and patched afterwards. Because the placeholders are the same
width as the real values, the patch cannot change the header's length. The
writer asserts this before writing and refuses rather than corrupt the
archive.

**Chained digests.** Entry integrity is a chained SHA-256:

```
h(0) = 32 zero bytes
h(i) = sha256( h(i-1) ‖ raw_block_i )
```

The state is 32 bytes, which means it can be persisted in the job state and
carried across requests. A plain `sha256(file)` cannot: PHP's hashing context
is not serializable, so a digest over a multi-request entry would be
impossible. The chain is verified the same way it is produced, over the blocks
as stored.

**Compression.** Blocks are deflated when that helps. Already-compressed file
types (JPEG, MP4, ZIP, WOFF, PDF…) are detected by extension and stored raw,
and any block that does not shrink is stored raw regardless.

**Encryption.** With a migration password, each block is encrypted after
compression with XChaCha20-Poly1305 (libsodium) or AES-256-GCM (OpenSSL). The
key comes from Argon2id or PBKDF2-HMAC-SHA256 with a random salt stored in the
prologue, along with an authentication token that lets a wrong password be
rejected immediately instead of producing garbage. The manifest entry stays
readable so the import screen can describe an archive before you type the
password.

## Exporting

**Scan.** A breadth-first walk writes every file it finds into an on-disk
queue (newline-delimited JSON). Directories still to visit go into a second
queue. Neither list is ever held in memory, and the walk's entire position is
one byte offset — which is what makes a scan of a million files resumable.
Exact totals come out of the scan, so every later progress bar is real.

**Database.** Tables are discovered from `information_schema` (with a
`SHOW FULL TABLES` fallback for hosts that restrict it) and classified. Tables
that belong to a *different* WordPress installation sharing the schema are
detected by finding other `*options` tables and are excluded by default.

Each table is dumped into its own archive entry: `SHOW CREATE TABLE`, then
rows in adaptive batches. Batch size is derived from the table's average row
length so one batch stays around 4 MB regardless of whether rows are 200 bytes
or 2 MB. Where a table has a single numeric key the walk is keyset paginated
(`WHERE id > ?`) instead of `OFFSET`, which keeps the last page as fast as the
first on a ten-million-row table.

Values are escaped through the connection, not through `wpdb::_real_escape()`
— that helper appends WordPress's internal placeholder token, which
`wpdb::query()` strips again on the way out. A dump never goes through
`wpdb::query()`, so using it would write `{hash}postname{hash}` into the
archive instead of `%postname%`. Binary columns are emitted as hex literals.

**Files.** The queue is consumed and each file is streamed into the archive.
A file larger than the remaining budget simply stays open across requests: the
job records the source offset and the writer's block position, and the next
request seeks and continues.

**Verify.** The finished archive is read back and every checksum recomputed,
plus a chained digest over all entry digests compared against the footer.
Only then is the download offered.

## Importing

**Validate before touching anything.** The whole archive is verified before
the first destructive operation. A corrupt archive fails with the destination
untouched.

**The prefix belongs to the destination.** The destination keeps its own
`wp-config.php`, so its `$table_prefix` wins and every statement is rewritten
on the way in. Rewriting is deliberately narrow: for `INSERT` and other
data-carrying statements only the first backtick-quoted identifier — the table
name — is touched, so a post whose content mentions `` `wp_posts` `` is not
mangled. Prefixed values *inside* the data (the `{prefix}user_roles` option,
`{prefix}capabilities` user meta) are fixed afterwards from a whitelist, never
by a blanket `LIKE 'wp\_%'`, which would rename unrelated options such as
`wp_mail_smtp`.

**Point at the destination immediately.** The instant the data is in place,
and before anything else runs, the restore writes `home` and `siteurl` to the
destination URL and reduces `active_plugins` to this plugin alone. A page load
between two ticks therefore cannot redirect a visitor to the source site, and
cannot fatal on a plugin whose files have not been restored yet. The real
plugin list and theme are restored at the end, filtered against what actually
exists on disk.

**Every path is validated.** Absolute paths, `..`, drive letters, stream
wrappers, null bytes and control characters are rejected outright, and the
resolved destination is proven to be inside the target directory even when a
symlink is in the way. Each entry's checksum is verified as it is written, so
corruption is caught during the restore, not after it.

**Maintenance mode and the endpoint.** A restore turns on WordPress
maintenance mode — which blocks `admin-ajax.php` as well as the front end.
That is why `endpoint.php` exists: it bootstraps WordPress with
`WP_INSTALLING` set, the same mechanism core's own installer uses, and the
browser drives the restore through it. WordPress deliberately loads no plugins
in that state, so the endpoint loads this one explicitly — which turns out to
be exactly what you want during a restore, because a half-written plugin on
disk cannot break the request that is finishing the job.

The maintenance flag's timestamp is refreshed on each tick. WordPress expires
it after ten minutes by itself, so a restore that dies in a way that skips
every cleanup path still brings the site back on its own; a cron watchdog
clears it sooner when no job has advanced.

**Authorisation survives the swap.** A restore replaces the users table, so
the operator's authentication cookie stops being valid mid-run. When a job is
created — by a fully authenticated, nonce-checked request — it is issued a
random 256-bit token, and only its SHA-256 hash is stored. The token
authorises exactly three things on exactly one job: advance it, read its
status, cancel it. At the end the restore re-issues a session for the matching
account when one exists, and tells the operator to sign in with the source
site's credentials when it does not.

## URL replacement

Three layers, each one refusing to guess:

**Rules.** A source/destination pair is expanded into literal replacements for
every form a WordPress database actually contains: both schemes, protocol
relative, percent-encoded (upper and lower case), JSON-escaped `https:\/\/`
and unicode-escaped `/`. Filesystem paths get the same treatment. Rules
are applied longest-first so overlapping forms cannot double-replace. A cheap
`stripos` probe skips values that cannot possibly match, which is most of
them.

**Serialized payloads.** Rather than `unserialize()` → walk → `serialize()`,
the engine parses the serialized format directly and rewrites string literals
in place, recomputing every length prefix. That keeps objects of classes that
are not loaded intact (instead of turning them into
`__PHP_Incomplete_Class`), keeps back references pointing at the right value,
and keeps custom `Serializable` payloads and PHP 8.1 enums byte-accurate.
Nested serialized strings — an option holding a serialized string holding
another one — are recursed into.

If a payload does not parse, it is **left completely alone** and reported. A
broken serialized value is worse than an unreplaced URL.

**Walking the database.** Every table, every text and blob column, in batches,
keyset paginated where possible, updating only the columns that actually
changed. Tables without a primary key fall back to matching on the original
row values with `LIMIT 1`. The walk's position is a table index plus a cursor,
so a replacement across twenty million rows survives any number of timeouts.

## Compatibility

Work that only touches the database or the filesystem happens immediately:
Elementor's compiled CSS files and `_elementor_css` meta are deleted,
WooCommerce sessions are truncated and its transients dropped, ACF caches are
cleared, all transients are removed and the object cache and opcode cache are
flushed.

Work that needs a plugin's own API is **deferred**, because right after a
restore those plugins are on disk but not loaded in the running request. The
tasks are queued in an option and executed on the next admin request, when
Elementor and WooCommerce are actually available. That is the difference
between calling a regeneration routine and pretending to.

## Security model

Summarised in the README and detailed in [docs/SECURITY.md](docs/SECURITY.md).
The load-bearing decisions:

- one capability (`shcm_manage_migrations`) plus a nonce on every entry point;
- archives in a protected directory with 64 bits of randomness in every name,
  and an active runtime check that the directory really is unreachable;
- extraction that treats the archive as hostile input;
- logs scrubbed of credentials on the way in;
- a password that never touches disk.

## Directory layout

```
sh-clone-migration/
├── sh-clone-migration.php      Plugin header and bootstrap
├── endpoint.php                Maintenance-safe entry point for restores
├── uninstall.php               Removes plugin data only, never site content
├── includes/
│   ├── bootstrap.php           Autoloader and polyfills
│   ├── Core/                   Plugin container, settings, environment, results
│   ├── Support/                Byte and JSON helpers
│   ├── Logging/                Per-job logger and the secret redactor
│   ├── Jobs/                   Job model, store, runner, budget, registry, cron
│   ├── Archive/                Format, writer, reader, verifier, catalogue
│   ├── Crypto/                 Authenticated block encryption
│   ├── Database/               Inspector, exporter, importer, SQL splitter, prefix
│   ├── Filesystem/             Paths, storage, scanner, queue, exclusions, safety
│   ├── Export/                 Export stages, manifest, checksum ledger
│   ├── Import/                 Import stages, uploader, maintenance mode
│   ├── URL/                    Rule builder, replacer, serialized rewriter
│   ├── Compatibility/          Elementor, WooCommerce, ACF, deferred tasks
│   ├── Security/               Capabilities, request guards, job tokens
│   ├── Admin/                  Menu, controller, AJAX, REST, notices, views
│   └── CLI/                    WP-CLI commands
├── admin/                      Stylesheet and the admin application
└── tests/                      Unit and integration suites
```

## Extension points

Listed with signatures in [DEVELOPMENT.md](DEVELOPMENT.md#hooks).
