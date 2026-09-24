# SH Clone Migration

Complete WordPress website cloning and migration system.

SH Clone Migration exports an entire WordPress installation — the whole
database plus every file under `wp-content` — into a single portable
`.wpress` archive, and restores it onto any other WordPress installation.
There are no size caps, no premium tier, no license keys and no external
services: the archive travels from source to destination and nothing else is
involved.

```
Source WordPress  ──▶  site.wpress  ──▶  Destination WordPress
```

---

## Contents

- [What gets migrated](#what-gets-migrated)
- [Requirements](#requirements)
- [Installation](#installation)
- [Exporting a site](#exporting-a-site)
- [Importing a site](#importing-a-site)
- [Large sites](#large-sites)
- [WP-CLI](#wp-cli)
- [Search and replace](#search-and-replace)
- [Settings](#settings)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [Documentation](#documentation)
- [License](#license)

---

## What gets migrated

**Database** — every table in the schema that belongs to this installation,
discovered dynamically from the live table prefix. That includes the core
WordPress tables, WooCommerce's tables, tables created by plugins, themes or
your own application code, and any table that shares the prefix. Table
structure, indexes, primary keys, foreign keys, character sets, collations,
auto-increment values, views and triggers are all preserved.

Tables belonging to a *different* WordPress installation that happens to share
the same database are detected and left alone (there is an option to include
them), also when one prefix starts with the other (`wp_` next to `wp_shop_`).
The database is always included unless you switch it off, and the export
stops with an explanation rather than produce an archive whose database is
empty, missing a core table, or cut short by a database error.

**Files** — the complete `wp-content` tree: plugins (active and inactive),
themes (parents and children), the uploads library with its full directory
structure and original filenames, must-use plugins, languages, drop-ins and
any custom directory a plugin invented. Optionally the WordPress core files
and root files too. A symlinked uploads or plugin directory (deploy layouts
such as Capistrano, Envoyer or Pantheon) is archived with its contents, file
names that are not UTF-8 are kept byte for byte, and anything that cannot be
archived is named in a warning and in the log — never dropped silently.

**Everything that lives in those two places** — posts, pages, custom post
types, taxonomies, categories, tags, comments, users, roles and capabilities,
menus and their relationships, widgets, customizer settings, options,
transients, cron entries, custom fields, ACF field groups and values,
Elementor documents, templates, kits and global settings, WooCommerce
products, variations, orders, customers, coupons, tax and shipping
configuration, and every plugin's own data.

`wp-config.php` is never exported and never overwritten. The destination keeps
its own database credentials, its own table prefix and its own authentication
salts.

## Requirements

| | |
|---|---|
| WordPress | 5.6 or newer |
| PHP | 7.4 or newer (8.0–8.4 supported) |
| Database | MySQL 5.6+ or MariaDB 10.1+ |
| PHP extensions | none required; `zlib` enables compression, `sodium` or `openssl` enable encryption |
| Shell access | not required |

The plugin works on standard shared hosting. It does not need `exec()`,
`mysqldump`, `ZipArchive`, `Phar` or a writable `/tmp`; when those exist it
uses them where they help, and when they do not it falls back to pure PHP
streaming.

## Installation

1. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**, or
   copy the `sh-clone-migration` directory into `wp-content/plugins/`.
2. Activate it.
3. A new **Clone Migration** menu appears in the admin sidebar.

Install it on both the source and the destination site.

On activation the plugin creates `wp-content/shcm-storage/` for archives, job
state, logs and temporary files, and protects it with `.htaccess`,
`web.config` and an `index.php` in every directory. **System Status** performs
a live check that the directory really is unreachable over HTTP and tells you
what to add to your server configuration if it is not.

## Exporting a site

**Clone Migration → Export → Create Migration.**

The export runs through these stages, and each one is resumable:

1. **Validate environment** — storage writable, disk space, capabilities.
2. **Analyse** — discover tables, plugins, themes, and walk the filesystem to
   count exactly how much work there is.
3. **Export the database** — table by table, streamed straight into the
   archive.
4. **Export the files** — plugins, themes, uploads, mu-plugins, everything
   else, streamed block by block.
5. **Finalise** — write the checksum ledger and close the archive.
6. **Verify** — read the finished archive back, recompute every checksum, and
   compute the SHA-256 of the whole file.

Only when verification passes does the download button appear, together with
exactly what the archive contains:

- the archive size in bytes and its SHA-256, with the commands to check a
  downloaded copy (`Get-FileHash .\<file> -Algorithm SHA256` on Windows,
  `shasum -a 256 <file>` on macOS, `sha256sum <file>` on Linux);
- **Database: included — N tables, M rows** (counted while dumping, not
  estimated), or a red **NOT included**;
- the files per group (plugins, themes, uploads, …) and anything skipped;
- whether every entry was verified or only the structure (quick mode).

The Backups screen shows the same for every stored archive, and
`wp shcm export` prints it.

Progress is reported per stage with real counts (tables, files, bytes), and
the numbers come from the server, never from the browser.

### Advanced export options

| Option | Default | Notes |
|---|---|---|
| Archive name | site host + timestamp | An unguessable suffix is always appended |
| Migration password | empty | Encrypts the archive; the password is never stored |
| Include WordPress core files | off | Not needed for a normal clone |
| Include other installations' tables | off | For shared databases |
| Additional exclusions | empty | Glob patterns, one per line |

## Importing a site

**Clone Migration → Import.**

1. Drag the `.wpress` file onto the drop zone, choose it with the file picker,
   or place it on the server over SFTP and register it by path.
2. The archive is described before anything happens: source URL, WordPress
   version, PHP version, database server, table prefix, file count, size,
   active theme and plugin count.
3. Choose the destination URL (detected automatically from this installation),
   the database mode and the safety options.
4. Tick the confirmation and press **Restore This Website**.

The restore runs through:

1. **Prepare** — writability checks, maintenance mode on.
2. **Validate** — every checksum in the archive is recomputed before a single
   byte of the destination is touched. A corrupt archive stops here, with the
   destination untouched.
3. **Rollback point** — a snapshot of the current database.
4. **Restore the database** — statements are rewritten to the destination's
   table prefix as they are executed. The moment the data is in place, the
   site URL is pointed at the destination and only this plugin is left active,
   so a page load mid-restore can neither bounce a visitor to the source site
   nor fatal on a plugin whose files have not arrived yet.
5. **Restore the files** — every path validated, every entry checksum
   verified as it is written.
6. **Update URLs** — serialization-aware replacement across every table.
7. **Post-migration** — restore the active theme and plugin list, clear
   transients, caches and Elementor's compiled CSS, drop WooCommerce sessions,
   schedule rewrite rules for regeneration.
8. **Verify** — a report of what works.
9. **Finish** — maintenance mode off.

### Never redirecting to the source

The destination URL wins, always:

- `home` and `siteurl` are written to the destination URL immediately after
  the database is replaced, before anything else runs.
- Every URL form is rewritten in the data: absolute (both schemes),
  protocol-relative, percent-encoded, JSON-escaped (`https:\/\/…`) and
  unicode-escaped.
- Absolute server paths from the source are rewritten too, which is what
  Elementor's compiled CSS and several caching plugins store.
- The final verification asserts that `home` and `siteurl` match the
  destination, and warns if `wp-config.php` defines `WP_HOME` or `WP_SITEURL`,
  since a constant overrides the database.

What is *not* rewritten: bare occurrences of the source domain that are not
URLs. `admin@oldsite.com` stays an email address, and
`https://example.org/oldsite.com-review` stays an external link. Those
occurrences are counted and listed in the migration report so you can decide,
and there is an advanced option to replace the bare domain too.

## Large sites

Nothing in the plugin caps the size of a migration. A 50 GB site is limited
only by the destination's disk, not by the plugin.

- **Nothing is loaded whole.** Files stream through 1 MB blocks. Database
  tables are read in adaptive batches (smaller when rows are large), with
  keyset pagination instead of `OFFSET` wherever a numeric key exists.
- **Every stage is resumable.** A request that runs out of time saves its
  position — down to the byte inside a half-written file, or the byte offset
  inside a half-executed SQL dump — and the next request continues there.
  Nothing restarts.
- **The browser is not required.** Keep the tab open and it drives the job;
  close it and WP-Cron picks the job up a couple of minutes later. WP-CLI runs
  the whole thing without either.
- **Uploads are chunked and resumable.** The chunk size is derived from
  `upload_max_filesize` and `post_max_size`, so the PHP upload limit does not
  cap the archive size. A failed chunk is retried on its own; a dropped
  connection resumes from the last byte the server confirmed.
- **Or skip the browser entirely.** Copy the `.wpress` file into
  `wp-content/shcm-storage/archives/` over SFTP and register it on the Import
  screen, or point WP-CLI at it.

## WP-CLI

```bash
wp shcm export                                  # export this site
wp shcm export --name=snapshot --password=secret
wp shcm export --porcelain                      # print only the archive path

wp shcm import backup.wpress --yes
wp shcm import /tmp/site.wpress --url-to=https://new.example.com --yes
wp shcm import backup.wpress --no-rollback --no-verify --yes

wp shcm verify backup.wpress
wp shcm list
wp shcm status [<job>]
wp shcm resume <job>
wp shcm cancel <job>
wp shcm rollback <job> --yes                    # undo an import
wp shcm search-replace https://old.test https://new.test --dry-run
wp shcm doctor                                  # system status report
```

Every command exits non-zero on failure and prints a plain error message.

## Search and replace

**Clone Migration → Search & Replace** runs the same engine the importer uses,
on demand:

- serialization-aware: nested arrays, objects of classes that are not loaded,
  back references, custom `Serializable` payloads and PHP 8.1 enums are all
  parsed properly and their length prefixes recalculated;
- URL-aware: give it two URLs and it also replaces the escaped,
  percent-encoded and protocol-relative variants;
- dry run first: **Preview Changes** reports what would change without writing
  anything;
- a report of tables scanned, rows scanned, values changed, serialized values
  rewritten, values it refused to touch, and remaining references to the old
  domain with excerpts.

A value whose serialization cannot be parsed is **never** rewritten. It is
reported instead. A broken serialized value is worse than an unreplaced URL.

## Settings

Everything is a performance or policy knob; none of it limits migration size.

- **What to migrate** — core files, default exclusions, extra directories and
  glob patterns.
- **Engine** — compression mode and level, block size, per-request time
  budget, memory guard, database rows per query, upload chunk size, whether
  WP-Cron may continue a job.
- **Restore behaviour** — rollback point, maintenance mode, URL and path
  rewriting, plugin reactivation, archive verification depth.
- **Housekeeping** — how long jobs, logs and rollback points are kept, how
  many archives to keep, temporary file cleanup, log level, and whether
  uninstalling should delete the plugin's own data.

## Security

- Every screen, AJAX action and REST route requires the
  `shcm_manage_migrations` capability (granted to administrators, and to
  super admins on multisite) plus a valid nonce.
- Archives live outside the web root's reach: `wp-content/shcm-storage/` is
  protected by `.htaccess`, `web.config` and `index.php` files, every archive
  name carries 64 bits of randomness, and the plugin actively tests whether
  the directory is reachable over HTTP and warns you if it is.
- Downloads are streamed through an authenticated endpoint with a nonce, with
  an exact `Content-Length`, validators and correct HTTP range handling, so
  browsers and download managers can show progress, split and resume a large
  download. An archive that is still being written is never handed out.
- Uploaded archives are validated by magic bytes before the rest of the upload
  is accepted, and again in full before a restore starts.
- Extraction refuses absolute paths, `..` traversal, Windows drive letters,
  stream wrappers, null bytes, control characters in filenames, and any path
  that resolves outside the installation through a symlink. Restored file
  modes are clamped; setuid, setgid and world-writable bits are never
  restored.
- Optional archive encryption uses XChaCha20-Poly1305 (libsodium) or
  AES-256-GCM (OpenSSL), with the key derived by Argon2id or PBKDF2-HMAC-SHA256
  from a password that is never written to disk. Each block is authenticated,
  so tampering is detected rather than decrypted.
- Migration logs are scrubbed on the way in: database credentials,
  authentication salts, API keys, tokens and anything that looks like a
  password never reach the log file.
- The plugin's own database credentials handling is simple: it has none. It
  never reads, writes or transmits `wp-config.php`.

See [docs/SECURITY.md](docs/SECURITY.md) for the full threat model.

## Troubleshooting

**The download manager says "the file size is unknown" / "may not have been
downloaded completely".**
The server removed the size from the response. Two common causes: Apache with
PHP-FPM (since Apache 2.4.59 it drops `Content-Length` from every PHP response
unless told otherwise), and hosts that compress every response ("Compress all
content" in cPanel). The plugin adds a small marked block to your `.htaccess`
that fixes both for archive downloads, and then checks with a test download
whether it worked. **System Status → Archive downloads** shows the result and,
when the size is still lost, what to add: the `.htaccess` lines if the plugin
could not write them, or a single `SetEnvIfExpr` line for your host to put in
the server configuration when `.htaccess` rules are not applied. Either way
the downloaded archive is normally complete: compare its
size in bytes and its SHA-256 with the values on the export screen or the
Backups screen. The import also re-verifies every entry before it changes
anything, so a damaged copy is always refused.

**Is the database in my archive?**
Yes, unless you switched it off. The export result, the Backups screen and the
import screen show **Database: included — N tables, M rows** for every archive
made with version 1.0.1 or later (archives from 1.0.0 show the table count).
An export that cannot read a table, finds no tables, or is missing a core
table stops with the reason instead of producing an archive. An archive whose
export did not finish is marked *incomplete*, shows "contents unknown", and
can be neither downloaded nor imported.

**"Migration archive validation failed."**
The archive is incomplete or was damaged in transfer. Re-download or re-upload
it. Nothing has been changed on the destination — validation runs before the
restore touches anything.

**The migration seems to stall.**
Open **Backups → Migration jobs** and download the job log. Every stage logs
what it did. If a job stopped because a request was killed, the Export or
Import screen offers to resume it from where it stopped.

**The site is stuck in maintenance mode.**
It should not be: WordPress expires its own maintenance flag after ten
minutes, the plugin refreshes it only while a job is alive, and a watchdog
clears it when no job has advanced for fifteen minutes. If you need it gone
now, delete the `.maintenance` file in the WordPress root.

**"Sign in with the credentials from the source site."**
Expected. The restore replaced the users table, so the destination's own
accounts are gone and the source site's accounts are what exists now. If the
same username exists on both sides your session is re-issued automatically.

**Some URLs still point at the old domain.**
Check the migration report. References that are not URLs (email addresses,
external links that merely mention the domain) are kept on purpose and listed
individually. Use **Search & Replace** with **Preview Changes** if you want to
rewrite them.

**Plugins are missing after the restore.**
The report lists any plugin that was active on the source but whose files did
not arrive — usually because it was excluded, or the export was made with an
exclusion pattern that matched it.

**The storage directory is readable over HTTP.**
Add the rule shown on the System Status page to your server configuration.
For nginx:

```nginx
location ~* /wp-content/shcm-storage/ {
    deny all;
    return 404;
}
```

## Known limitations

These are environmental, not artificial:

- **Multisite.** A network exports and restores as a whole network; the plugin
  refuses to restore a network archive onto a single site or the other way
  round, because that cannot be done safely. Moving one subsite out of a
  network is not supported.
- **Files are not rolled back.** The rollback point is a full database
  snapshot, and `wp shcm rollback <job>` (or the button a failed restore
  offers) puts it back. Files are restored in place, so a failed restore may
  leave new files on disk. Take a full export as a safety backup first — the
  Import screen links to it.
- **File contents are not rewritten.** URLs inside a `.css` or `.js` file that
  was written by hand are migrated as they are. Generated CSS (Elementor and
  friends) is cleared so it regenerates against the new URL.
- **Encrypted jobs cannot be resumed unattended.** The password only exists in
  the request that supplied it, so WP-Cron cannot continue an encrypted job.
  Resume it from the browser or the command line.
- **`wp-config.php` is never written.** If the source relied on a constant such
  as `WP_HOME`, you must set it on the destination yourself. The verification
  step warns you when this applies.
- **Very large single rows.** A single database row bigger than the
  destination's `max_allowed_packet` cannot be inserted. The error names the
  table and suggests raising the limit.
- **Object cache drop-ins.** `advanced-cache.php` is excluded by default
  because it points at a caching plugin's configuration for the source server.
- **Download size on some servers.** Behind nginx with `gzip_types` covering
  `application/octet-stream`, or a proxy that compresses everything, PHP
  cannot keep the `Content-Length` of a download. The archive still arrives
  complete; check it by size and SHA-256.

## Documentation

| Document | Contents |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | How the engine is put together and why |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Working on the plugin, running the tests, hooks and APIs |
| [docs/ARCHIVE-FORMAT.md](docs/ARCHIVE-FORMAT.md) | The `.wpress` container, byte by byte |
| [docs/SECURITY.md](docs/SECURITY.md) | Threat model and security decisions |
| [docs/TEST-RESULTS.md](docs/TEST-RESULTS.md) | End-to-end migration test results |

## License

GPL-2.0-or-later. See the plugin header.

This is an independent implementation. No code was taken from any other
migration plugin.
