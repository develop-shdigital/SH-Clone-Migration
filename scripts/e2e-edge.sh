#!/bin/bash
# Edge-case end-to-end test: the situations that used to lose data silently.
#
#   bash scripts/e2e-edge.sh
#
# Source "edge" (prefix wp_shop_) shares its database with a second WordPress
# installation (prefix wp_), keeps its uploads in a symlinked directory outside
# the site (Capistrano style), loads a plugin through a symlink, and has file
# names that are Latin-1 bytes or contain a backslash, a 40 MB file that is
# modified while it is being copied, a symlink loop, and runtime files inside
# node_modules. It is exported one tiny request at a time with the core files
# included, then imported with "skip core" into a destination whose database
# also holds a sibling installation (prefix wpdst_shop_, which starts with the
# destination's own wpdst_).
#
# Needs MariaDB/MySQL (root via socket), WP-CLI and /tmp/wp.tar.gz.

set -u
SITES=/home/user/sites
EDGE=$SITES/edge
SIB=$SITES/edge-sibling
SHARED=$SITES/edge-shared
DST=$SITES/dest
DSIB=$SITES/dest-sibling
PLUGIN="$(cd "$(dirname "$0")/.." && pwd)/sh-clone-migration"
WP="wp --allow-root"
PASSES=0; FAILS=0
pass() { PASSES=$((PASSES+1)); printf "  PASS  %s\n" "$1"; }
fail() { FAILS=$((FAILS+1)); printf "  FAIL  %s\n" "$1"; }
check() { if eval "$2"; then pass "$1"; else fail "$1  [${3:-}]"; fi; }
q() { mariadb -N -B -e "$1" 2>/dev/null; }
quiet() { grep -vE '^PHP:|trace|^#0|^\)\]|^  .trace' ; }

echo "=============================================================="
echo " SH Clone Migration edge-case end-to-end test"
echo " $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "=============================================================="

echo; echo "--- 1. Source database shared by two installations ------------"
q "DROP DATABASE IF EXISTS shcm_edge; CREATE DATABASE shcm_edge CHARACTER SET utf8mb4; GRANT ALL ON shcm_edge.* TO 'wpuser'@'localhost'; FLUSH PRIVILEGES;"
rm -rf "$EDGE" "$SIB" "$SHARED"
mkdir -p "$EDGE" "$SIB" "$SHARED"
tar -xzf /tmp/wp.tar.gz -C "$SIB" --strip-components=1
tar -xzf /tmp/wp.tar.gz -C "$EDGE" --strip-components=1

cd "$SIB"
$WP config create --dbname=shcm_edge --dbuser=wpuser --dbpass=wppass --dbhost=127.0.0.1 --dbprefix=wp_ --skip-check >/dev/null 2>&1
$WP core install --url=http://sibling.test --title="Sibling" --admin_user=siblingadmin --admin_password=x --admin_email=s@sibling.test --skip-email >/dev/null 2>&1
$WP post create --post_title="Sibling-only post" --post_status=publish >/dev/null 2>&1

cd "$EDGE"
$WP config create --dbname=shcm_edge --dbuser=wpuser --dbpass=wppass --dbhost=127.0.0.1 --dbprefix=wp_shop_ --skip-check >/dev/null 2>&1
$WP core install --url=http://edge.test:8084 --title="Edge Shop" --admin_user=edgeadmin --admin_password=edgepass --admin_email=e@edge.test --skip-email >/dev/null 2>&1
rsync -a --exclude vendor --exclude .phpunit.cache "$PLUGIN/" "$EDGE/wp-content/plugins/sh-clone-migration/"
$WP plugin activate sh-clone-migration >/dev/null 2>&1
$WP user create shopuser shop@edge.test --role=editor --user_pass=x >/dev/null 2>&1
for c in Alpha Beta Gamma; do $WP term create category "$c" >/dev/null 2>&1; done
for i in $(seq 1 30); do
  $WP post create --post_title="Edge post $i" --post_status=publish --post_category=Alpha,Beta --porcelain >/dev/null 2>&1
done
echo "tables: $(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='shcm_edge' AND table_name LIKE 'wp\_shop\_%'") with prefix wp_shop_, $(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='shcm_edge' AND table_name NOT LIKE 'wp\_shop\_%'") belonging to the sibling (wp_)"

echo; echo "--- 2. Awkward files -------------------------------------------"
mkdir -p "$SHARED/plugins/linked-plugin/assets"
mv "$EDGE/wp-content/uploads" "$SHARED/uploads" 2>/dev/null || mkdir -p "$SHARED/uploads"
ln -s ../../edge-shared/uploads "$EDGE/wp-content/uploads"
mkdir -p "$SHARED/uploads/2026/09" "$SHARED/uploads/big"
head -c 5000 /dev/urandom > "$SHARED/uploads/2026/09/shared-image.png"
printf 'Latin-1 name' > "$SHARED/uploads/$(printf 'Preisliste_M\xe4rz.pdf')"
printf 'backslash name' > "$SHARED/uploads/images\\logo.png"
head -c 41943040 /dev/urandom > "$SHARED/uploads/big/video.mp4"
ln -s . "$SHARED/uploads/self"
ln -s ../.. "$SHARED/uploads/escape"
cat > "$SHARED/plugins/linked-plugin/linked-plugin.php" <<'PHP'
<?php
/**
 * Plugin Name: Linked Plugin
 * Description: Lives outside the site and is loaded through a symlink.
 */
PHP
printf 'console.log("linked");' > "$SHARED/plugins/linked-plugin/assets/app.js"
ln -s ../../../edge-shared/plugins/linked-plugin "$EDGE/wp-content/plugins/linked-plugin"
$WP plugin activate linked-plugin >/dev/null 2>&1
THEME=$($WP option get stylesheet 2>/dev/null | quiet | tail -1)
mkdir -p "$EDGE/wp-content/themes/$THEME/node_modules/lib" "$EDGE/wp-content/plugins/somepl/vendor/cache"
printf 'window.runtime=1;' > "$EDGE/wp-content/themes/$THEME/node_modules/lib/runtime.js"
printf '<?php // keep' > "$EDGE/wp-content/plugins/somepl/vendor/cache/Keep.php"
mkdir -p "$EDGE/cache" && printf 'junk' > "$EDGE/cache/junk.txt"
# A bare name in "Excluded directories" means the top-level directory only.
$WP eval '$s = get_option("shcm_settings"); $s["exclude_directories"] = array("cache"); update_option("shcm_settings", $s);' >/dev/null 2>&1
echo "uploads: $(readlink "$EDGE/wp-content/uploads") ($(find "$SHARED/uploads" -type f 2>/dev/null | wc -l) files), plugins/linked-plugin -> $(readlink "$EDGE/wp-content/plugins/linked-plugin")"

echo; echo "--- 3. Export, one tiny request at a time, core included ------"
cd "$EDGE"
BIG_BEFORE=$(stat -c%s "$SHARED/uploads/big/video.mp4")
OUT=$($WP eval-file "$PLUGIN/tests/wordpress/export-harness.php" name=edge include_core=1 mutate=wp-content/uploads/big/video.mp4 2>&1 | quiet)
echo "$OUT" | grep -E "^(TICKS|MUTATED|SHA256|DATABASE|ARCHIVE)" | sed 's/^/  /'
echo "$OUT" | grep -E "^WARNING" | sed 's/^/  /'
ARCHIVE=$(echo "$OUT" | sed -n 's/^ARCHIVE //p')
SHA=$(echo "$OUT" | sed -n 's/^SHA256 //p')
check "export completed" '[ -n "$ARCHIVE" ] && [ -f "$ARCHIVE" ]' "$(echo "$OUT" | tail -3)"
check "export needed many requests" '[ "$(echo "$OUT" | sed -n "s/^TICKS //p")" -gt 100 ]'
check "big file was modified during its copy" 'echo "$OUT" | grep -q "^MUTATED yes"'
check "SHA-256 matches the file" '[ "$SHA" = "$(sha256sum "$ARCHIVE" | cut -d" " -f1)" ]'
check "checksum file verifies with sha256sum -c" '(cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$ARCHIVE").sha256" >/dev/null 2>&1)'
check "warning about the escaping symlink" 'echo "$OUT" | grep -q "contains the site itself"'
check "no sibling tables claimed" '! echo "$OUT" | grep -q "\"tables\":2[0-9]"' "$(echo "$OUT" | sed -n 's/^DATABASE //p')"
DBTABLES=$(echo "$OUT" | sed -n 's/^DATABASE //p' | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["tables"];')
DBROWS=$(echo "$OUT" | sed -n 's/^DATABASE //p' | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["rows"];')
SRCROWS=0
for t in $(q "SELECT table_name FROM information_schema.tables WHERE table_schema='shcm_edge' AND table_name LIKE 'wp\_shop\_%'"); do
  SRCROWS=$((SRCROWS + $(q "SELECT COUNT(*) FROM shcm_edge.\`$t\`")))
done
check "archive holds exactly the 12 wp_shop_ tables" '[ "$DBTABLES" = 12 ]' "$DBTABLES"
check "archive row count equals SELECT COUNT(*) over those tables" '[ "$DBROWS" = "$SRCROWS" ]' "archive $DBROWS, database $SRCROWS"

echo; echo "--- 4. Destination with its own sibling installation ----------"
q "DROP DATABASE IF EXISTS wp_dest; CREATE DATABASE wp_dest CHARACTER SET utf8mb4; GRANT ALL ON wp_dest.* TO 'wpuser'@'localhost'; FLUSH PRIVILEGES;"
rm -rf "$DST" "$DSIB" && mkdir -p "$DST" "$DSIB"
tar -xzf /tmp/wp.tar.gz -C "$DST" --strip-components=1
tar -xzf /tmp/wp.tar.gz -C "$DSIB" --strip-components=1
cd "$DSIB"
$WP config create --dbname=wp_dest --dbuser=wpuser --dbpass=wppass --dbhost=127.0.0.1 --dbprefix=wpdst_shop_ --skip-check >/dev/null 2>&1
$WP core install --url=http://dest-sibling.test --title="Destination sibling" --admin_user=dsib --admin_password=x --admin_email=d@dsib.test --skip-email >/dev/null 2>&1
cd "$DST"
$WP config create --dbname=wp_dest --dbuser=wpuser --dbpass=wppass --dbhost=127.0.0.1 --dbprefix=wpdst_ --skip-check >/dev/null 2>&1
$WP core install --url=http://destination.test:8082 --title="Destination" --admin_user=destadmin --admin_password=destpass --admin_email=a@dest.test --skip-email >/dev/null 2>&1
rsync -a --exclude vendor --exclude .phpunit.cache --exclude tests "$PLUGIN/" "$DST/wp-content/plugins/sh-clone-migration/"
$WP plugin activate sh-clone-migration >/dev/null 2>&1
DSIB_BEFORE=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_dest' AND table_name LIKE 'wpdst\_shop\_%'")
echo "destination: prefix wpdst_; sibling installation wpdst_shop_ with $DSIB_BEFORE tables in the same database"

echo; echo "--- 5. Import with 'skip core files' ---------------------------"
mkdir -p "$DST/wp-content/shcm-storage/archives"
cp "$ARCHIVE" "$DST/wp-content/shcm-storage/archives/"
cp "$ARCHIVE.sha256" "$DST/wp-content/shcm-storage/archives/" 2>/dev/null
$WP shcm import "$(basename "$ARCHIVE")" --skip-core --yes 2>&1 | quiet | tail -4 | sed 's/^/  /'

echo; echo "--- 6. Checks ---------------------------------------------------"
DSIB_AFTER=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_dest' AND table_name LIKE 'wpdst\_shop\_%'")
check "destination sibling tables untouched ($DSIB_BEFORE)" '[ "$DSIB_AFTER" = "$DSIB_BEFORE" ]' "after: $DSIB_AFTER"
check "sibling admin still in wpdst_shop_users" '[ "$(q "SELECT COUNT(*) FROM wp_dest.wpdst_shop_users WHERE user_login=\"dsib\"")" = 1 ]'
check "restored users are the shop's (edgeadmin, shopuser)" '[ "$(q "SELECT GROUP_CONCAT(user_login ORDER BY user_login) FROM wp_dest.wpdst_users")" = "edgeadmin,shopuser" ]' "$(q "SELECT GROUP_CONCAT(user_login) FROM wp_dest.wpdst_users")"
check "no sibling data (siblingadmin) restored" '[ "$(q "SELECT COUNT(*) FROM wp_dest.wpdst_users WHERE user_login=\"siblingadmin\"")" = 0 ]'
MISMATCH=""
for t in $(q "SELECT table_name FROM information_schema.tables WHERE table_schema='shcm_edge' AND table_name LIKE 'wp\_shop\_%'"); do
  d="wpdst_${t#wp_shop_}"
  # WordPress itself deletes _pingme/_encloseme once it has processed them.
  w=""; case "$t" in *postmeta) w="WHERE meta_key NOT IN ('_pingme','_encloseme')";; esac
  a=$(q "SELECT COUNT(*) FROM shcm_edge.\`$t\` $w"); b=$(q "SELECT COUNT(*) FROM wp_dest.\`$d\` $w")
  if [ "$d" != "wpdst_options" ] && [ "$d" != "wpdst_usermeta" ] && [ "$a" != "$b" ]; then MISMATCH="$MISMATCH $t:$a/$b"; fi
done
check "row counts identical for every restored table (composite keys included)" '[ -z "$MISMATCH" ]' "$MISMATCH"
check "term relationships all restored" '[ "$(q "SELECT COUNT(*) FROM shcm_edge.wp_shop_term_relationships")" = "$(q "SELECT COUNT(*) FROM wp_dest.wpdst_term_relationships")" ]'
U="$DST/wp-content/uploads"
check "symlinked uploads restored (shared-image.png identical)" 'cmp -s "$SHARED/uploads/2026/09/shared-image.png" "$U/2026/09/shared-image.png"'
check "40 MB file restored byte-identical (its final content)" 'cmp -s "$SHARED/uploads/big/video.mp4" "$U/big/video.mp4"' "$(stat -c%s "$U/big/video.mp4" 2>/dev/null) vs $(stat -c%s "$SHARED/uploads/big/video.mp4")"
check "big file is the modified version, not a torn copy" '[ "$(stat -c%s "$U/big/video.mp4" 2>/dev/null)" -gt "$BIG_BEFORE" ]'
check "Latin-1 file name restored byte-exact" '[ -f "$U/$(printf "Preisliste_M\xe4rz.pdf")" ] && [ "$(cat "$U/$(printf "Preisliste_M\xe4rz.pdf")")" = "Latin-1 name" ]'
check "backslash file name restored as one file" '[ -f "$U/images\\logo.png" ] && [ ! -d "$U/images" ]'
check "symlinked plugin restored with its files" 'cmp -s "$SHARED/plugins/linked-plugin/assets/app.js" "$DST/wp-content/plugins/linked-plugin/assets/app.js"'
check "theme node_modules runtime file restored" '[ -f "$DST/wp-content/themes/$THEME/node_modules/lib/runtime.js" ]'
check "nested vendor/cache directory not excluded by bare \"cache\"" '[ -f "$DST/wp-content/plugins/somepl/vendor/cache/Keep.php" ]'
check "internal self link kept as a link" '[ -L "$U/self" ]'
check "escaping link not restored" '[ ! -e "$U/escape" ]'
check "core files skipped (wp-config untouched, destination prefix kept)" 'grep -q "wpdst_" "$DST/wp-config.php"'
check "home URL is the destination" '[ "$(q "SELECT option_value FROM wp_dest.wpdst_options WHERE option_name=\"home\"")" = "http://destination.test:8082" ]'

echo; echo "  passed: $PASSES, failed: $FAILS"
echo "=============================================================="
exit $FAILS
