# Test scripts

The end-to-end migration test. It needs MySQL or MariaDB, WP-CLI, PHP 7.4+ and
a copy of WordPress at `/tmp/wp.tar.gz`.

| Script | Purpose |
|---|---|
| `e2e.sh` | Builds the destination, exports the source, imports, verifies, checks live HTTP responses and compares files byte for byte |
| `seed.php` | Seeds a source site with representative content: posts, pages, custom post types, taxonomies, users, media, menus, widgets, Elementor data, ACF field groups, WooCommerce products, custom tables and awkward serialized values |
| `seed2.php` | Adds the literal-percent edge cases that caught a real corruption bug |
| `check.php` | 81 assertions over the restored site: serialized integrity, URL replacement, Elementor, WooCommerce, ACF, users, custom tables, files and configuration safety |
| `e2e-edge.sh` | The situations that used to lose data silently: a database shared with a second installation whose prefix is a prefix of ours, a symlinked uploads directory and plugin, Latin-1 and backslash file names, a symlink loop, a 40 MB file modified while it is copied, an export of thousands of tiny requests, core files included then skipped on import, and a destination database holding its own sibling installation |
| `download-test.sh` | The archive download endpoint the way browsers and download managers use it: Content-Length, no compression, ranges, If-Range, HEAD, eight parallel segments, resume, SHA-256 header, refusal of incomplete archives and of unauthenticated or hostile requests. Run it against Apache with mod_php and with PHP-FPM, with `SetOutputFilter DEFLATE` |
| `merge-test.sh` | Checks that the controlled (merge) import mode keeps tables the archive does not contain |
| `build.sh` | Builds the distributable plugin ZIP |
| `router.php` | Front controller so PHP's built-in server can serve pretty permalinks |

`seed.php` and `check.php` run through `wp eval-file`. See
[../docs/TEST-RESULTS.md](../docs/TEST-RESULTS.md) for the results.
