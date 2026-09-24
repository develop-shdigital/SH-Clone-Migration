# Test results

Two suites back this plugin: an automated PHPUnit suite that runs anywhere,
and a full end-to-end migration between two real WordPress installations.
Version 1.0.1 added tests that run inside WordPress against a real database,
an edge-case migration, and a download test against Apache; they are
summarised first.

---

## Version 1.0.1: downloads, database completeness, file completeness

Prompted by a download that Internet Download Manager reported as "file size
unknown", and by the question whether the database is really in the archive.

### Why the size was unknown

Reproduced on Apache 2.4.58 with PHP 8.4 in both common set-ups:

| Set-up | `Content-Length` of a PHP download | Cause |
|---|---|---|
| Apache + mod_php + `SetOutputFilter DEFLATE` | removed, body gzip-compressed and chunked | mod_deflate recompresses every response unless `no-gzip` is set |
| Apache + PHP-FPM (`mod_proxy_fcgi`), no compression at all | removed, body chunked (HTTP/2: no length) | since 2.4.59 (the CVE-2024-24795 fix, backported by distributions) Apache drops the length a FastCGI backend sends unless the request carries `ap_trust_cgilike_cl` |

A client sees no size in both cases, which is exactly the IDM message. The
bytes that arrive are the complete archive (the old handler already sent the
whole file); the client just cannot prove it, cannot split the download and
cannot resume it. The fix: the download handler sets `no-gzip` itself under
mod_php, and the plugin keeps a marked `.htaccess` block that sets `no-gzip`,
`dont-vary` and `ap_trust_cgilike_cl` for the two download actions (see
[ARCHITECTURE.md](../ARCHITECTURE.md#exporting)). A PHP-only probe under
PHP-FPM confirmed the second cause independently of the plugin: PHP-FPM
emits `Content-Length: 5000000`, Apache's trace shows it received that
header, and the response still goes out chunked.

### Download endpoint — `scripts/download-test.sh`

29 checks: HTTP 200 with the exact `Content-Length`, no `Content-Encoding`,
no chunking, `Accept-Ranges`, `ETag`, `Last-Modified`, `X-SHCM-SHA256`
equal to the file's SHA-256, byte-identical body; `HEAD` with the length;
closed, suffix, open and past-the-end ranges with the right bytes and
`Content-Range`; 416 with `bytes */size`; multi-range and stale `If-Range`
answered with the whole file; matching `If-Range` answered with 206; eight
parallel ranges (the way IDM splits a download) reassembled byte-exactly;
an interrupted download resumed with `curl -C -`; no session, a bad nonce,
traversal in the name and a direct URL to the storage directory refused;
an incomplete archive refused with 409.

| Server (both with `SetOutputFilter DEFLATE`) | 1.0.0 | 1.0.1 |
|---|---|---|
| Apache 2.4.58 + mod_php 8.4 | 17 / 29 | **29 / 29** |
| Apache 2.4.58 + PHP-FPM 8.4 (`mod_proxy_fcgi`) | 16 / 29 | **29 / 29** |

With 1.0.0 both servers sent the archive gzip-compressed and chunked, with no
`Content-Length`, no `ETag` or `Last-Modified` (so Chrome and Edge restart an
interrupted download from zero), the head of the file for a suffix range, a
`Content-Length` larger than the body for a range past the end, a partial
response to a stale `If-Range`, and a 200 for an archive that was still being
written. (A browser decodes the gzip body back into the complete archive;
`curl -o` without `--compressed` saves it compressed, which is why the
"byte-identical" check also fails for 1.0.0.)

### Database engine — `tests/db/database-engine-test.php`

Run inside WordPress against MariaDB 10.11 in a scratch schema, 36 checks,
all passing: a schema holding two complete installations (`wp_` and
`wp_shop_`) is split correctly from either side, with plugin tables such as
`wp_foo_options` and the Simple:Press forum's `wp_sfoptions`/`wp_sfposts`
kept with their site; composite primary keys are found, unique keys on
nullable columns and MariaDB "long unique" HASH indexes rejected; a
composite-key table paged in batches of 1,000 while rows are deleted and
inserted between batches dumps every row that existed throughout exactly
once; a nullable unique key, a float key, a text key, a keyless table and a
HASH-indexed table with values sharing a long prefix all finish with every
row exactly once; BIT columns (a BIT primary key and BIT(64) with 2^64-1
included) survive a dump and restore under strict mode; a key holding
non-ASCII text in a table that mixes character sets pages to the end; a lock
wait timeout and a `max_statement_time` abort in the middle of a table are
reported as errors (the table is not marked done, the cursor stays on the
last row written) and the dump resumes to all 5,000 rows exactly once;
triggers are filtered to exported tables.

### Import safety — `tests/db/import-safety-test.php`

13 checks, all passing, each against a real database: a single statement
larger than the 8 MB checkpoint restores; a sibling installation that shares
this site's users through `CUSTOM_USER_TABLE` is never dropped; an archive
whose metadata lists tables it does not contain drops nothing and is reported
as having no database; a table that would land on a sibling installation's
name after the prefix rewrite is skipped with a warning; a request killed
part way through a keyless table is followed by a restore that completes with
3,000 rows, 3,000 distinct (no row inserted twice); real duplicate keys are
reported with the table name and row count, not dropped silently; the
source's active plugins are still known after a kill right after the options
table was overwritten.

### Found by an independent review, then fixed

Six reviewers each read one area of the 1.0.1 changes and wrote a
reproduction for every problem they reported; each one was then fixed and the
reproduction re-run. Among them:

- a plugin whose tables look like a site (`wp_sfoptions` + `wp_sfposts`) was
  taken for a second installation and left out of the export;
- BIT values were written as hex text, turning every `b'0'` into 1;
- a keyset cursor holding non-ASCII text was refused by `wpdb::query()` on a
  table with mixed character sets, so the export never finished;
- a failed read's retry pause spun the job runner instead of waiting;
- a restore killed part way through a keyless table inserted rows twice on
  resume;
- a symlink to `/` or into `/proc`, a directory deleted between two scan
  requests, two links to the same outside directory, and a second link to the
  uploads directory each produced a wrong file list;
- a symlink whose target was changed inside the archive still verified
  (fixed by ledger scheme 2);
- an incomplete archive, and a complete one whose export skipped files, were
  described with the scan's planned counts instead of what they hold;
- files the scan skipped (unreadable, over the size limit) were missing from
  the archive's record and the CLI output;
- a step that raised more than 500 warnings lost the earlier names from the
  log.

Each has a regression test in the suites above or below.

### Edge-case migration — `scripts/e2e-edge.sh`

A shop on prefix `wp_shop_` sharing its database with a second installation
on `wp_`; uploads in a symlinked directory outside the site
(`wp-content/uploads -> ../../edge-shared/uploads`); a plugin loaded through
a symlink; a Latin-1 file name, a file name with a backslash, an internal
`self -> .` link and an `escape -> ../..` link; runtime files in a theme's
`node_modules`; a plugin's `vendor/cache` directory with "Excluded
directories" set to `cache`; a 40 MB file. It is exported with core files
included, **one tiny request at a time** (8,438 requests, every one starting
with its budget already spent), and the 40 MB file is appended to after its
copy has begun. The archive is imported with "skip core files" into a
destination whose database also holds a sibling installation on
`wpdst_shop_`, a prefix that starts with the destination's own `wpdst_`.

| | 1.0.0 | 1.0.1 |
|---|---|---|
| Checks passed | 9 / 27 | **27 / 27** |
| Tables in the archive | 24 (the sibling's 12 included, with its admin's password hash) | 12 |
| Destination's sibling installation after the import | all 12 tables dropped | untouched |
| Symlinked uploads, symlinked plugin, Latin-1 and backslash names, `node_modules`, `vendor/cache` | missing on the destination | restored byte-identical |
| 40 MB file modified mid-copy | not archived at all (uploads missing) | torn copy discarded, copied again, restored identical to its final content |

The 1.0.1 run also checks that the archive's SHA-256 matches `sha256sum` and
verifies with `sha256sum -c`, that the row count recorded in the archive
equals `SELECT COUNT(*)` over the exported tables, and that every restored
table has the source's row count (composite keys included).

### Standard migration and browser

`scripts/e2e.sh` (below) still passes all 81 checks, `scripts/e2e-edge.sh`
27 of 27, and `scripts/download-test.sh` 29 of 29 on both servers after the
review fixes, every page returns 200
with no source references, and 4,000 of 4,000 compared files are identical.
A browser run through the admin screens (Chromium via Playwright) exported
the source site and showed: "Archive size 67.23 MB — exactly 70,499,481
bytes", the SHA-256, "Database: Included — 50 tables, 1,152 rows, 1.06 MB of
SQL (table prefix wpsrc_)", the files per group and "every one of its 10,153
entries matched its checksum"; the Backups screen showed the same.

---

## Automated suite

```
$ composer test:all

PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.19

............................................................... 145 / 145 (100%)

OK (145 tests, 1785 assertions)
```

| Suite | Tests | What it proves |
|---|---|---|
| `ArchiveTest` | 16 | Round trips of text, binary, empty and directory entries; deflate actually shrinks and reads back; a writer paused mid-entry resumes byte-exactly; a torn tail from a killed request is truncated and recovered; encryption rejects a missing or wrong password and round trips with the right one; bit corruption and truncation are detected; extraction resumes at block boundaries and reproduces the entry digest; an aborted entry leaves no trace; verification resumes inside a large entry; backslashes and non-UTF-8 bytes in entry names round trip |
| `SerializedRewriterTest` | 13 | Every serialized token type; nested arrays and array keys; unicode and binary strings keep their byte lengths; objects of classes that are not loaded survive; back references stay valid; nested serialized strings are recursed into; `Serializable` payloads and PHP 8.1 enums keep their byte counts; a broken payload is refused rather than rewritten; deep nesting is refused rather than exploding |
| `ReplacerTest` | 19 | Absolute URLs in both schemes, protocol-relative, percent-encoded in both cases, JSON-escaped slashes, Gutenberg block attributes, filesystem paths, ports; serialized values keep their lengths; unparsable values are left alone; a Windows path is not mistaken for a serialized payload; bare domains are kept by default and replaceable on request; unrelated domains are untouched; longest-rule-first prevents double replacement |
| `SqlStreamReaderTest` | 11 | Semicolons inside strings, escaped quotes, doubled quotes and backtick identifiers do not split a statement; line, hash and block comments; a trailing backslash at a chunk boundary; identical output at chunk sizes from 1 byte to 1 KB; the resume offset is exact; splitting stays linear on a statement with thousands of semicolons |
| `FilesystemTest` | 11 | Traversal, absolute paths, drive letters, stream wrappers and null bytes are rejected; harmless unicode paths are accepted; symlink escape is detected; exclusion globs match at any depth without over-matching; the on-disk queue resumes from a byte offset |
| `JobsTest` | 10 | A job runs through every stage; it pauses when the budget is spent and resumes from disk with its state intact; a failure is recorded with its stage and every cleanup handler runs; a cancellation written by another request stops the job; progress is weighted by stage; job ids cannot escape the storage directory; the migration password is never written to disk |
| `SupportTest` | 14 | Size parsing and 64-bit packing; fixed-width size fields; JSON helpers; the log redactor hides database passwords, API keys, bearer tokens and connection URIs; the prefix rewriter changes table names but never row data; the cipher round trips and rejects tampered blocks; settings are clamped to sane ranges |
| `DeliveryTest` | 16 | HTTP ranges: none, closed, suffix, open, an end past the file clamped, a start past the file unsatisfiable (416), invalid and multi-range requests ignored, `If-Range` honoured only for the current version, an empty file; bytes that are not UTF-8 survive JSON losslessly, JSON is never written half, and only bad bytes are replaced for display; the whole-file SHA-256 resumes across requests and notices a file that changed; the checksum file round trips and is ignored when stale; deleting an archive deletes its checksum |
| `FileSelectionTest` | 19 | A symlinked uploads directory is its own root; a directory linked from outside is followed once and a loop inside it is kept as a link; links to a parent of the site, to `/` and into `/proc` are refused with a warning; two links to the same outside directory are both archived; a second link to the uploads root is kept as a link; Latin-1 and backslash names survive the queues; content is not walked twice with core included; a directory of 1,234 files is scanned across requests with every file exactly once, and files deleted between requests are neither duplicated nor skipped in another's place; anchored exclusions; queue truncation, torn and corrupt lines; table entry names never collide |
| `IntegrityTest` | 10 | An incomplete archive claims no contents; a complete one shows what was written, not what was planned; resuming a writer onto a file shorter than its saved state is refused; a damaged directory header is caught; a redirected symlink fails verification under ledger scheme 2; the one-shot SHA-256 of older PHP succeeds, retries once after a kill and gives up after repeated kills; every one of 1,200 warnings raised in one step reaches the log |
| `FilePipelineTest` | 6 | A real directory tree scanned, archived and restored, then compared file by file; excluded paths never enter the archive; empty directories survive; a hostile archive containing `../../../evil.php` writes nothing outside the destination; symlinks are recorded, not followed; a scan with an exhausted budget still makes progress and completes |

Two bugs were found by these tests and fixed: a block comment split across a
one-byte chunk boundary was never terminated, and a cancellation issued while
a job was paused was overwritten by the next tick's first save.

---

## End-to-end migration test

A complete WordPress site was built, cloned into a `.wpress` archive, and
restored onto a different WordPress installation with a **different URL** and a
**different table prefix**.

### Environment

| | Source | Destination |
|---|---|---|
| WordPress | 7.1 | 7.1 (clean install) |
| URL | `http://source.test:8081` | `http://destination.test:8082` |
| Table prefix | `wpsrc_` | `wpdst_` |
| Database | `wp_source` | `wp_dest` |
| Admin user | `admin` | `destadmin` |
| PHP | 8.4.19 | 8.4.19 |
| Database server | MariaDB 10.11.14 | MariaDB 10.11.14 |

### Source site contents

- 50 database tables, including WooCommerce's, Elementor's, Action
  Scheduler's, a custom application table with a BLOB column, and a table with
  no primary key
- 27 posts, 9 pages, 5 custom-post-type entries, 5 WooCommerce products,
  1 order, 2 comments
- Categories, tags, a custom hierarchical taxonomy, a navigation menu with an
  external link, a text widget, customizer theme mods
- 4 users across 4 roles
- 10,077 files / 185.45 MB: media library (PNG, PDF, a unicode filename, a
  filename with spaces and parentheses, a 3 MB binary), nested and empty
  directories, 5 plugins, 5 themes including a child theme, an mu-plugin
- Plugins: WooCommerce 11.1, Elementor 4.2, Advanced Custom Fields 6.8,
  Classic Editor
- Deliberately awkward data: serialized options, double-serialized options, a
  serialized object, JSON inside a serialized value, emoji and accented
  characters, literal `%` and `%postname%` tokens, percent-encoded URLs,
  external URLs that must survive, email addresses at the source domain

### Export

```
archive:  e2e-6799c995920610a9.wpress
size:     67.00 MB   (185.45 MB of content)
entries:  10,137
duration: 6.2 s

Scan complete: 10077 files, 185.45 MB, 1731 directories, 0 skipped.
Database export finished: 50 tables.
Files exported: 10083 files, 185.45 MB.
Archive finalised: e2e-6799c995920610a9.wpress (67.00 MB, 10137 entries).
Archive verified: 10137 entries, 187.56 MB of content.
```

`wp shcm verify` on the finished archive: **10,137 entries verified**.

### Import

```
duration: 3.6 s

Database connection:         PASS
WordPress tables:            PASS
Home URL:                    PASS   http://destination.test:8082
Site URL:                    PASS   http://destination.test:8082
Active theme:                PASS   twentytwentyfive-child
Plugins:                     PASS   5 active
Uploads directory:           PASS   /home/user/sites/dest/wp-content/uploads
Rewrite rules:               PASS   scheduled for regeneration
Elementor:                   PASS   data present, CSS cache cleared
WooCommerce:                 PASS   tables present, sessions cleared
Advanced Custom Fields:      PASS   field groups present
Remaining source URLs:       PASS   12 references kept for review
```

The 12 remaining references are email addresses (`admin@source.test`,
`shopper@source.test`) and are listed individually in the report. They are
kept on purpose: they are not URLs.

### Deep verification — 81 checks, 0 failures

**Serialized data**

| Check | Result |
|---|---|
| Serialized option unserializes | PASS |
| URL inside a serialized option replaced | PASS |
| Nested serialized array replaced | PASS |
| JSON string inside a serialized value replaced | PASS |
| Unicode and emoji preserved byte for byte | PASS |
| Absolute server path rewritten | PASS |
| Double-serialized value replaced at both levels | PASS |
| Serialized object survives and is replaced | PASS |
| External URL untouched | PASS |
| Widget option intact and replaced | PASS |
| Theme mods intact and replaced | PASS |

**Content**

| Check | Result |
|---|---|
| 27 posts, 9 pages, 5 custom-post-type entries | PASS |
| Post content URLs replaced | PASS |
| Unicode post titles preserved | PASS |
| Post meta: array, nested URL, emoji | PASS |
| Menu present with 4 items | PASS |
| External menu URL untouched | PASS |
| Categories and tags intact | PASS |
| Comments migrated | PASS |

**Elementor**

| Check | Result |
|---|---|
| Elementor page present | PASS |
| `_elementor_data` is still valid JSON | PASS |
| Background image URL replaced inside escaped JSON | PASS |
| Widget link URL replaced | PASS |
| `_elementor_css` meta cleared | PASS |
| Compiled CSS files cleared and regenerated for the new URL | PASS |

**WooCommerce**

| Check | Result |
|---|---|
| WooCommerce tables present | PASS |
| 5 products migrated | PASS |
| Product lookup table populated (5 rows) | PASS |
| Store settings preserved | PASS |
| Email header image URL replaced | PASS |
| Sessions cleared | PASS |

**ACF**

| Check | Result |
|---|---|
| Field group migrated | PASS |
| Options page value intact | PASS |
| Repeater row URL replaced | PASS |

**Database and users**

| Check | Result |
|---|---|
| All 50 tables restored under the destination prefix | PASS |
| Row counts match for posts, postmeta, users, usermeta, terms, term relationships, comments | PASS |
| Custom application table: 50 rows | PASS |
| Serialized payload in the custom table replaced | PASS |
| Escaped characters (newlines, quotes, backslashes) preserved | PASS |
| BLOB column preserved byte for byte | PASS |
| Table without a primary key replaced correctly | PASS |
| User roles work after prefix rewriting (`wpsrc_user_roles` → `wpdst_user_roles`) | PASS |
| Administrator capabilities intact | PASS |

**Literal percent signs** (a regression suite for a real bug found during this
test — see below)

| Check | Result |
|---|---|
| `permalink_structure` is `/%postname%/` | PASS |
| `100% pure — /%postname%/ and %s %d %% tokens` intact | PASS |
| `%1$s`-style sprintf tokens intact | PASS |
| Percent-encoded URL replaced correctly | PASS |
| No placeholder token anywhere in options, postmeta or posts | PASS |

**Files**

| Check | Result |
|---|---|
| PNG, PDF, unicode filename, filename with spaces and parentheses | PASS |
| 3 MB binary restored byte for byte | PASS |
| Nested directories and empty directories | PASS |
| Child theme, mu-plugin, all plugin directories | PASS |
| The migration plugin's own directory and storage kept | PASS |
| **4,000 files compared byte for byte: 4,000 identical, 0 different** | PASS |

**Configuration safety**

| Check | Result |
|---|---|
| Destination database name kept in `wp-config.php` | PASS |
| Destination table prefix kept | PASS |
| Maintenance mode switched off | PASS |
| Source active plugins reactivated | PASS |
| Migration plugin still active | PASS |
| Child theme still active | PASS |

### Live HTTP checks

Every page served by the restored site, over its own hostname:

| URL | Status | References to the source domain |
|---|---|---|
| `/` | 200 | 0 |
| `/about/` | 200 | 0 |
| `/contact/` | 200 | 0 |
| `/elementor-landing/` | 200 | 0 |
| `/percent-edge-cases/` | 200 | 0 |
| `/portfolio/project-1/` | 200 | 0 |
| `/category/architecture/` | 200 | 0 |
| `/product/product-1/` | 200 | 0 |
| `/shop/` | 200 | 0 |
| `/wp-login.php` | 200 | 0 |
| media (PNG) | 200 | `image/png`, 70 bytes |
| media (PDF) | 200 | `application/pdf`, 193 bytes |

`/wp-admin/` redirects to the **destination's** login page. No request
redirects to the source site.

All six plugin admin screens (Export, Import, Backups, Search & Replace,
Settings, System Status) return 200 with zero PHP errors, warnings or notices.

### Browser flow, chunked upload, maintenance mode and encryption

Tested separately against the same pair of sites:

| Scenario | Result |
|---|---|
| Export driven entirely through `admin-ajax.php` like the browser does | Completed; archive verified |
| Export with a 1-second time budget | Completed across 5 requests; archive identical in content and entry count to the single-request one |
| Chunked upload of an archive in 8 KB chunks | Reassembled correctly |
| Replaying an already-received chunk | Idempotent, reported the same offset |
| Import driven through the browser flow | Paused after the first tick with maintenance mode on |
| Front end during the restore | HTTP 503 with the custom maintenance page |
| `admin-ajax.php` during the restore | HTTP 503 (blocked by WordPress) |
| `endpoint.php` with only a job token and no session cookie | HTTP 200, drove the restore to completion |
| Maintenance mode after completion | Removed |
| Encrypted export and import (`--password`) | Completed; all 81 checks passed |
| `wp shcm verify` without a password | `Error: This archive is encrypted. A migration password is required.` |
| `wp shcm verify` with the wrong password | `Error: The migration password is incorrect.` |
| Rollback point | Created before the database was replaced |
| Operator account missing from the restored database | Warned: "Sign in with the credentials from the source site." |

### Controlled (merge) import mode

A destination carrying its own table was imported with `--mode=merge`:

| | Before | After |
|---|---|---|
| Tables | 13 | 51 (50 from the archive plus the local one) |
| `wpdst_local_only` | 1 row, `keep me` | **1 row, `keep me`** |
| Posts | 4 | 62 |
| Site name | Destination Site | Source Clone Test |

Tables the archive contains are replaced; tables it does not are left alone,
which is what the mode promises.

### Rollback

After a successful restore, `wp shcm rollback <job> --yes` was used to undo it.

| | Before the import | After the import | After the rollback |
|---|---|---|---|
| Site name | Destination Site | Source Clone Test | **Destination Site** |
| Posts | 4 | 62 | **4** |
| Tables | 12 | 50 | **12** |
| Users | `destadmin` | `admin`, `editor`, `author`, `shopper` | **`destadmin`** |
| A destination-only page | present | gone | **present** |
| Home and site URL | destination | destination | **destination** |
| Maintenance mode | off | off | **off** |

Rolling back a job whose rollback point predates this feature fails with
*"The archive contains no database metadata"* and leaves the site untouched,
which is the correct behaviour for an unusable snapshot.

### Distribution

The built ZIP was installed into a fourth, clean WordPress installation
through `wp plugin install <zip> --activate`:

| Check | Result |
|---|---|
| Installs and activates from the ZIP | PASS |
| Storage directory created with its protection files | PASS |
| `wp shcm doctor` reports the environment | PASS |

### Security checks

| Attempt | Result |
|---|---|
| AJAX action with no session | Rejected |
| AJAX action with a wrong nonce | `Your session expired.` (403) |
| `endpoint.php` with no authentication | `Authentication required.` (401) |
| `endpoint.php` with a session but no nonce | 403 |
| `endpoint.php` with a non-whitelisted action | `This action is not available on the migration endpoint.` (400) |
| Download without a nonce | 403 |
| Download with `../../../wp-config.php` as the archive name | 403 |
| Directory listing of the storage directory | 404 |
| Archive containing `../../../evil.php` | Refused; nothing written outside the destination |

---

## Bugs found and fixed during testing

The end-to-end test earned its keep. Four real defects were found and fixed:

1. **`wpdb::_real_escape()` corrupted every value containing `%`.** That
   helper appends WordPress's internal placeholder token, which
   `wpdb::query()` strips again on the way out — but a dump never goes
   through `wpdb::query()`. `permalink_structure` arrived at the destination
   as `/{64-hex-hash}postname{64-hex-hash}/`, which broke every permalink.
   The exporter now escapes through the connection directly. The percent
   regression tests above exist because of this.

2. **Table inventory was cached from before the database was replaced.** URL
   replacement therefore skipped every table that only existed after the
   restore — WooCommerce's tables and the custom application table among them.
   The inventory is now flushed after the restore and re-read when a
   replacement starts.

3. **The standalone endpoint could not load the plugin.** WordPress skips
   every active plugin while `wp_installing()` is true, so the endpoint that
   exists specifically to work during maintenance mode could not work at all.
   It now loads the plugin itself — which is also safer, because a
   half-restored plugin on disk cannot break the request driving the restore.

4. **A restore logged the operator out of its own migration.** Replacing the
   users table invalidates the session cookie, so every tick after the
   database swap returned 401 and the migration stalled. Job-scoped tokens
   now authorise finishing a job that was started by an authenticated request.

Three more were found by the unit tests: an unterminated block comment at a
one-byte chunk boundary, a cancellation lost to the next tick's first save,
and exclusion patterns such as `*/node_modules` that only matched at one
directory level.

---

## Reproducing

`scripts/e2e.sh` in the repository builds both sites, seeds the source,
exports, imports and verifies. It needs a MySQL or MariaDB server, WP-CLI and
PHP 7.4+.

```bash
bash scripts/e2e.sh
```
