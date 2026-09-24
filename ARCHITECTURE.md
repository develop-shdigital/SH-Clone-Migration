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
carried across requests on every PHP version the plugin supports (PHP 7.4
cannot serialise a hashing context, so a plain digest over a multi-request
entry would be impossible there). The chain is verified the same way it is
produced, over the blocks as stored.

**Whole-file SHA-256.** In addition, once an archive is finished and verified,
its plain SHA-256 is computed — the number `sha256sum`, `shasum -a 256` and
PowerShell's `Get-FileHash` print — so a downloaded copy can be checked with
standard tools. It is computed in 8 MB slices across as many requests as it
takes, carrying the serialised hash context between them (PHP 8+; on older
PHP the file is hashed in one call, attempted at most twice). It is stored
next to the archive as `<archive>.wpress.sha256` in `sha256sum` format, sent
as the `X-SHCM-SHA256` download header, and shown on the export result, the
Backups screen, the import screen and by `wp shcm export`/`wp shcm list`.
Chunked uploads build the same digest chunk by chunk, so the destination can
show the SHA-256 of what it received.

**Compression.** Blocks are deflated when that helps. Already-compressed file
types (JPEG, MP4, ZIP, WOFF, PDF…) are detected by extension and stored raw,
and any block that does not shrink is stored raw regardless.

**Encryption.** With a migration password, each block is encrypted after
compression with XChaCha20-Poly1305 (libsodium) or AES-256-GCM (OpenSSL). The
key comes from Argon2id or PBKDF2-HMAC-SHA256 with a random salt stored in the
prologue, along with an authentication token that lets a wrong password be
rejected immediately instead of producing garbage. Every entry is encrypted,
the manifest included. Only the footer stays readable without the password,
and it records what was actually written — entry counts per group, whether
the database is included, its table/row/SQL totals — so an encrypted archive
can still be described before the password is typed.

## Exporting

**Scan.** A breadth-first walk writes every file it finds into an on-disk
queue (newline-delimited JSON). Directories still to visit go into a second
queue. Neither list is ever held in memory, and the walk's position is a byte
offset — which is what makes a scan of a million files resumable. A directory
with hundreds of thousands of entries is listed once: when the request's time
runs out part way through it, the names not yet handled are spooled to a file
and the next request carries on from there, so a file deleted in between is
simply not found (never skipped in someone else's place) and none is queued
twice. Both queues are cut back to their committed sizes at the start of every
request, so a request killed in the middle of a directory cannot leave
duplicates, and a line is never read before its newline has been written.
Exact totals come out of the scan, so every later progress bar is real. What
the scan passes over — an unreadable file or directory, a file above the size
limit — is counted, named in a warning, and recorded in the archive's footer
together with what the copy had to skip.

The roots are resolved physically. `wp-content` is always a root of its own
(also when the core files are included, so an import can restore content while
skipping core), and a plugins, mu-plugins or uploads directory that does not
physically live inside `wp-content` — a deploy-style symlink such as
`wp-content/uploads -> ../../shared/uploads` — is walked as a root in its own
right. Other symlinks are resolved rather than copied blindly. A link that is
itself a root's path is left to that root's walk. A link whose target is
already inside a walked tree — including a second name for a root, such as
`blogs.dir -> uploads` — is kept as a link, so nothing is archived twice. A
dangling link is kept as a link. A file link to something outside the site
archives that file's content. Any other directory link is followed and
archived as ordinary files under the link's path; loops are recognised along
each followed branch, so two links to the same outside library are both
archived while a link back up the branch is kept as a link. A link that would
pull in a parent of the site (`/`, a home directory) or a system directory
(`/proc`, `/sys`, `/dev`, `/run`) is reported and skipped, and at most 10,000
directory links are followed. File names are arbitrary bytes on disk;
names that are not UTF-8 (Latin-1 from old FTP clients, CP437 from Windows
ZIPs) are carried losslessly through the queues and archive headers as tagged
base64, and a backslash is kept as an ordinary character.

"Excluded directories" are anchored paths relative to the WordPress root —
`cache` there means the top-level `cache` only, never every `cache` directory
inside every plugin — and patterns for a separate uploads/plugins root also
match under their `wp-content/…` spelling. Every exclusion that applied is
recorded in the manifest.

**Database.** Tables are discovered from `information_schema` of the schema
the connection actually uses (`SELECT DATABASE()`), with a `SHOW FULL TABLES`
fallback for hosts that restrict it; if both fail the export stops instead of
continuing without a database. Tables are then classified. A WordPress
installation is a prefix for which every table a site always has exists
(`options`, `posts`, `postmeta`, `comments`, `terms`, `term_taxonomy`,
`term_relationships`), with or without a users table of its own (sites can
share one through `CUSTOM_USER_TABLE`); on a multisite network the numbered
sites (`wp_2_`, …) are part of this installation. Every table belongs to the
*longest* installation prefix it starts with,
so a site on `wp_shop_` never claims the tables of a site on `wp_` and vice
versa, while plugin tables that only look like a site (`wp_foo_options`, or
the Simple:Press forum's `wp_sfoptions` and `wp_sfposts`) stay with theirs.
Table names are compared case-insensitively when `lower_case_table_names` is
not 0 (Windows, Azure). Tables of another installation are excluded by default
and reported. The export refuses to start when no tables were found, or when
`{prefix}options`, `{prefix}posts` or `{prefix}users` is missing without
having been excluded on purpose.

Each table is dumped into its own archive entry: `SHOW CREATE TABLE`, then
rows in adaptive batches. Batch size is derived from the table's average row
length so one batch stays around 4 MB regardless of whether rows are 200 bytes
or 2 MB. Every table with a primary key, or a unique key over NOT NULL columns,
is walked in key order with keyset pagination — `WHERE (k1, k2) > (…)`, written
out so older MySQL can use the index — which keeps the last page as fast as
the first and means a row written or deleted elsewhere on a live site cannot
shift other rows out of the dump or duplicate them. The composite key of
`wp_term_relationships` is the everyday case. Key values travel into the next
query as SQL literals, so the dump reads through the connection itself rather
than `wpdb::query()`, which rejects a query whose text does not fit the
narrowest character set among a table's columns. Only a table without such a key
falls back to `OFFSET`, ordered by all of its columns so consecutive pages
see one fixed order. (MySQL sorts long TEXT/BLOB values on their first
`max_sort_length` bytes, so two keyless rows that are equal up to that point
have no guaranteed order between them; that is the one remaining case in
which a row of a keyless table could be read twice or missed. Giving such a
table a primary key removes it.) Float, ENUM, SET, BIT, TEXT and BLOB keys,
and MariaDB's "long unique" HASH indexes, are never keyset-paged, because
their comparison and sort orders can disagree. BIT values are written as
numbers (mysqlnd returns them as decimal text, and writing that text as hex
would turn every `b'0'` into 1).

`wpdb::get_results()` returns an empty array — not `null` — when a query fails,
so every read the dump depends on checks `last_error` explicitly. A failed
batch (a lock wait timeout, a host's statement time limit, a dropped
connection) never ends a table early: the rows read so far stay in the archive
with the cursor on the last one written, and the same table is retried from
there in the next request, up to five times with a growing pause, before the
export stops with the table name and the database error. The pause between
attempts is spent waiting inside the request when it has time for it, and
otherwise ends the request, instead of calling the stage in a tight loop. A
table dropped after the scan is skipped with a warning. When the tables are done, every
planned table must have an entry, or the export stops. The rows and SQL bytes
actually written per table are counted, logged, recorded in the footer and
shown to the user; triggers are recorded only for exported tables.

Values are escaped through the connection, not through `wpdb::_real_escape()`
— that helper appends WordPress's internal placeholder token, which
`wpdb::query()` strips again on the way out. A dump never goes through
`wpdb::query()`, so using it would write `{hash}postname{hash}` into the
archive instead of `%postname%`. Binary columns are emitted as hex literals.

**Files.** The queue is consumed and each file is streamed into the archive.
A file larger than the remaining budget simply stays open across requests: the
job records the source offset and the writer's block position, and the next
request seeks and continues; no other entry is begun while one is open. On
every resume the file's size and mtime are compared with the values recorded
when its copy started, and once more at the end. A file that changed, shrank
or became unreadable is not archived as a torn copy (which would still pass
verification, because the checksum covers exactly what was written): the
partial entry is discarded — the archive is truncated back to the entry's
header — and the file copied again from the start, or skipped with a warning
after two attempts. Every skip is a warning, and every warning is written to
the job's log the moment it is raised: the job file keeps only the newest 500
for the screen, but the downloadable log names every one. The number of
entries archived plus skipped is compared with the scan.

**Verify.** The finished archive is read back and every checksum recomputed,
plus a chained digest over all entries compared against the footer. The
ledger item of each entry covers its path, type, link target, permissions and
checksum (`checksum_scheme` 2 in the footer; archives from 1.0.0 covered path
and checksum only), so a redirected symlink — which has no payload to
checksum — is caught too. Directory and link headers must have no payload,
and only a link may carry a target; anything else is reported as a damaged
header.
Verification also resumes inside a large entry, so a multi-gigabyte file does
not have to be checked within one request. The whole-file SHA-256 follows.
Only then is the download offered.

**Download.** The download handler refuses an archive without a footer (still
being written, or from a failed export) with HTTP 409, and otherwise sends it
with an exact `Content-Length`, `Accept-Ranges`, a strong `ETag`,
`Last-Modified`, correct single-range, suffix, open and clamped ranges, 416
with `Content-Range: bytes */size`, `If-Range`, `HEAD`, and the
`X-SHCM-SHA256` header. It never buffers the body and stops reading when the
client disconnects. Web servers can still take `Content-Length` away: Apache
with PHP-FPM drops it from every FastCGI response since 2.4.59 (the
CVE-2024-24795 fix) unless the request carries `ap_trust_cgilike_cl`, and
servers that compress every response replace it with chunked encoding unless
`no-gzip` is set. Under mod_php the handler sets `no-gzip` itself; for
PHP-FPM, the plugin keeps a small marked block in the `.htaccess` files that
govern `wp-admin` (the site root's, and WordPress's own directory's when it
has rewrite rules of its own) that sets both variables for the download
actions only. It is written only into a file that already carries rewrite
rules, retried from wp-admin until it is in place (the attempt made on
activation may run under WP-CLI), refreshed when the rule changes, and removed
on deactivation. Whether downloads really keep their size is then measured,
not assumed: a loopback request downloads a small, very compressible probe
file through the same `admin-post.php` path and checks `Content-Length` and
`Content-Encoding`. System Status shows the result and, when the size is
lost, the fix that fits the server — the `.htaccess` lines when the plugin
could not write them, or a one-line `SetEnvIfExpr` for the server
configuration when `.htaccess` rules are not applied (`AllowOverride None`,
PHP proxied with `ProxyPassMatch`) or not used by the site at all.

## Importing

**Validate before touching anything.** The whole archive is verified before
the first destructive operation. A corrupt archive fails with the destination
untouched. So does an archive whose database has no `{prefix}options` table.
An archive without a database (exported with the database switched off) never
drops anything: the destination database is left as it is, and the URL
replacement and plugin/theme restoration that only make sense after a
database restore are skipped.

**Only this site's tables are replaced.** Replace mode drops the tables that
belong to this installation by the same longest-prefix rule as the export —
never every table whose name merely starts with the prefix, so a second
installation on `wpdst_shop_` next to this site's `wpdst_` survives. Archives
made by version 1.0.0 could contain a sibling installation's tables; those are
skipped when they would land on one of this site's restored tables after the
prefix rewrite, or belong to another installation at the destination.

A request that is killed part way through a table leaves a marker file
behind. The next request finds it, sees that the job state still points at
the position the killed request started from, and restores that table again
from the start of its dump — which begins with `DROP TABLE` and `CREATE
TABLE`, so this is idempotent and a table without a unique key cannot receive
rows twice. A multi-row `INSERT` that still meets existing rows is re-run as
`INSERT IGNORE`, which keeps the rows that are there and adds the ones that
are not, and the number of rows that were really duplicates is reported as a
warning naming the table.

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
symlink is in the way: the deepest part of the path that exists (a link
counts, even a dangling one) is resolved through every link in it and must
stay inside, and a dangling link counts as unsafe. Protected files (`wp-config.php`,
`.htaccess`, this plugin and its storage) are matched after the path has been
normalised and case-insensitively, so `./wp-config.php` or `WP-CONFIG.PHP`
does not slip past. A backslash is a separator only on Windows; elsewhere
it is an ordinary character in a file name. Symlink targets are collapsed
(`.` and `..` resolved) before the check that they stay inside the
installation. Content stored under the core root by version 1.0.0 archives is
restored into the destination's content directory, and "skip core files" skips
only core. Each entry's checksum is verified as it is written, so corruption
is caught during the restore, not after it.

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
