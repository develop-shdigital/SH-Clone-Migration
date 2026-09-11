# Test scripts

The end-to-end migration test. It needs MySQL or MariaDB, WP-CLI, PHP 7.4+ and
a copy of WordPress at `/tmp/wp.tar.gz`.

| Script | Purpose |
|---|---|
| `e2e.sh` | Builds the destination, exports the source, imports, verifies, checks live HTTP responses and compares files byte for byte |
| `seed.php` | Seeds a source site with representative content: posts, pages, custom post types, taxonomies, users, media, menus, widgets, Elementor data, ACF field groups, WooCommerce products, custom tables and awkward serialized values |
| `seed2.php` | Adds the literal-percent edge cases that caught a real corruption bug |
| `check.php` | 81 assertions over the restored site: serialized integrity, URL replacement, Elementor, WooCommerce, ACF, users, custom tables, files and configuration safety |
| `router.php` | Front controller so PHP's built-in server can serve pretty permalinks |

`seed.php` and `check.php` run through `wp eval-file`. See
[../docs/TEST-RESULTS.md](../docs/TEST-RESULTS.md) for the results.
