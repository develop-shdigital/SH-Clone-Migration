=== SH Clone Migration ===
Contributors: shdigital
Tags: migration, clone, backup, duplicate, move site
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clone an entire WordPress site into a single portable .wpress archive and restore it anywhere. No size limits, no paid extensions, no external services.

== Description ==

SH Clone Migration exports a complete WordPress installation - the whole
database plus every file under wp-content - into one portable `.wpress`
archive, and restores it onto any other WordPress installation.

There is no premium tier. There are no size caps, no file-count limits, no
license keys, no accounts and no cloud services. The only limits are your
server's disk space and database.

= What it migrates =

* The complete database, with every table discovered dynamically from the live
  table prefix: core tables, WooCommerce tables, plugin and theme tables, and
  your own application tables
* Table structure, indexes, keys, character sets, collations, auto-increment
  values, views and triggers
* Posts, pages, custom post types, taxonomies, categories, tags and comments
* Users, roles and capabilities
* The complete media library with its directory structure and original
  filenames, including unicode names
* All plugins, active and inactive, with their settings and their own tables
* All themes, parent and child, with customizer settings, widgets and menus
* Must-use plugins, drop-ins, languages and any custom wp-content directory
* WooCommerce products, variations, orders, customers, coupons, tax and
  shipping configuration
* Elementor documents, templates, kits, global settings and generated assets
* ACF field groups, options pages, repeaters, flexible content and galleries
* Options, transients, cron entries and rewrite rules

`wp-config.php` is never exported and never overwritten. The destination keeps
its own database credentials, table prefix and authentication salts.

= Built for large sites =

Nothing is ever loaded whole. Files stream through 1 MB blocks and database
tables are read in adaptive batches with keyset pagination. Every stage of
every migration is resumable: a request that runs out of time saves its
position - down to the byte inside a half-written file - and the next request
continues from there. Nothing restarts.

Keep the browser tab open and it drives the migration; close it and WP-Cron
picks the job up. Or run the whole thing from WP-CLI, which is what very large
sites should do.

Archive uploads are chunked and resumable, so the PHP upload limit does not
cap how large an archive may be. A dropped connection resumes from the last
byte the server confirmed rather than starting a 10 GB upload again.

= Correct URL replacement =

Moving a site means rewriting its URLs, and doing that with SQL REPLACE
corrupts serialized data. This plugin parses the serialized format directly
and recomputes every length prefix, which keeps objects of classes that are
not loaded intact, keeps back references valid, and keeps custom Serializable
payloads and PHP 8.1 enums byte-accurate.

It rewrites every form a WordPress database actually contains: absolute URLs
in both schemes, protocol-relative references, percent-encoded URLs,
JSON-escaped `https:\/\/` inside Elementor and Gutenberg data, and absolute
server paths.

It deliberately does not rewrite occurrences of the domain that are not URLs.
An email address at the old domain stays an email address; an external link
that merely mentions it stays external. Those are counted and listed in the
migration report so you can decide.

If a serialized value cannot be parsed it is left completely alone and
reported. A broken serialized value is worse than an unreplaced URL.

= The destination never redirects to the source =

The site URL is written to the destination the moment the database is
replaced, before anything else runs, so even a page load in the middle of a
restore cannot bounce a visitor to the old site. The final verification
asserts that `home` and `siteurl` match the destination and warns if
`wp-config.php` defines a URL constant that would override them.

= Safety =

* The whole archive is verified before a restore touches anything
* A database rollback point is taken before the destination is replaced
* Maintenance mode is on for the duration, and expires by itself if a restore
  is abandoned
* Every path in an archive is validated before a byte is written: traversal,
  absolute paths, stream wrappers and symlink escapes are refused
* Optional archive encryption with XChaCha20-Poly1305 or AES-256-GCM
* Logs are scrubbed of credentials, keys and tokens
* Uninstalling never deletes website content

= WP-CLI =

`wp shcm export`
`wp shcm import backup.wpress --yes`
`wp shcm verify backup.wpress`
`wp shcm list`
`wp shcm status`
`wp shcm resume <job>`
`wp shcm rollback <job>`
`wp shcm search-replace https://old.test https://new.test --dry-run`
`wp shcm doctor`

== Installation ==

1. Upload the plugin through Plugins > Add New > Upload Plugin, or copy the
   `sh-clone-migration` directory into `wp-content/plugins/`.
2. Activate it.
3. Install and activate it on the destination site as well.
4. Use Clone Migration > Export on the source, download the `.wpress` file,
   then Clone Migration > Import on the destination.

The plugin creates `wp-content/shcm-storage/` for archives, job state and
logs, and protects it with `.htaccess`, `web.config` and index files. The
System Status screen performs a live check that the directory really is
unreachable over HTTP and tells you what to add to your server configuration
if it is not.

== Frequently Asked Questions ==

= Is there a size limit? =

No. The plugin imposes none. What limits you is disk space, and PHP's memory
limit only in the sense that the engine keeps one block (1 MB by default) in
memory at a time.

= Do I need shell access, ZipArchive or mysqldump? =

No. The plugin uses them if they exist and helps, and falls back to pure PHP
streaming when they do not. It runs on standard shared hosting.

= What happens to my database credentials? =

Nothing. The plugin never reads, writes or transmits `wp-config.php`. The
destination keeps its own credentials and its own table prefix; the dump is
rewritten to match on the way in.

= The source and destination have different table prefixes. Is that a problem? =

No. The destination's prefix wins and every statement is rewritten to it,
including the prefixed option and user-meta keys WordPress uses for roles and
capabilities.

= Will I stay logged in after a restore? =

If an account with your username exists in the restored database, your session
is re-issued automatically. Otherwise sign in with the source site's
credentials - the destination's own accounts are gone, because the restore
replaced them.

= Can I migrate a multisite network? =

A whole network migrates to a whole network. The plugin refuses to restore a
network archive onto a single site, or a single-site archive onto a network,
because that cannot be done safely.

= What if the migration is interrupted? =

Reopen the Export or Import screen: it offers to resume the job from where it
stopped. WP-Cron also picks up abandoned jobs a couple of minutes later.

= Are archives publicly accessible? =

They are stored in a directory protected by `.htaccess`, `web.config` and
index files, every archive name carries 64 bits of randomness, and downloads
go through an authenticated endpoint. Because some web servers ignore
`.htaccess`, the plugin actively tests whether the directory is reachable and
warns you when it is.

== Screenshots ==

1. Export: one button, with the advanced controls tucked away
2. Live per-stage progress with real file and table counts
3. Import: drag and drop, with the archive described before anything happens
4. The post-migration verification report
5. Backups: verify, download or delete stored archives
6. System Status: what this server can do and how the engine adapts

== Changelog ==

= 1.0.0 =
* First release.
* Streaming `.wpress` archive format with per-block compression, optional
  authenticated encryption and chained checksums.
* Fully resumable export and import: AJAX, WP-Cron and WP-CLI drivers.
* Dynamic table discovery, prefix rewriting and streaming database dump and
  restore.
* Serialization-aware URL and path replacement with a dry-run mode.
* Chunked resumable archive uploads.
* Database rollback point, maintenance mode and post-restore verification.
* Elementor, WooCommerce and ACF compatibility handling.
* WP-CLI commands and a REST API.

== Upgrade Notice ==

= 1.0.0 =
First release.
