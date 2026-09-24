#!/bin/bash
# Full end-to-end migration test: source -> archive -> destination.
set -e
SRC=/home/user/sites/source
DST=/home/user/sites/dest
PLUGIN=/home/user/SH-Clone-Migration/sh-clone-migration
CLEAN='grep -vE ^PHP:|trace|^#0'

echo "=============================================================="
echo " SH Clone Migration end-to-end test"
echo " $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "=============================================================="

echo
echo "--- 1. Reset the destination to a clean WordPress -------------"
fuser -k 8082/tcp 2>/dev/null || true
mariadb -e "DROP DATABASE IF EXISTS wp_dest; CREATE DATABASE wp_dest CHARACTER SET utf8mb4; GRANT ALL ON wp_dest.* TO 'wpuser'@'localhost'; FLUSH PRIVILEGES;"
rm -rf $DST && mkdir -p $DST && tar -xzf /tmp/wp.tar.gz -C $DST --strip-components=1
cd $DST
wp --allow-root config create --dbname=wp_dest --dbuser=wpuser --dbpass=wppass --dbhost=127.0.0.1 --dbprefix=wpdst_ --skip-check >/dev/null 2>&1
wp --allow-root core install --url=http://destination.test:8082 --title="Destination Site" --admin_user=destadmin --admin_password=destpass --admin_email=admin@destination.test --skip-email >/dev/null 2>&1
rsync -a --exclude vendor --exclude .phpunit.cache --exclude tests $PLUGIN/ $DST/wp-content/plugins/sh-clone-migration/
wp --allow-root plugin activate sh-clone-migration >/dev/null 2>&1
echo "destination: WordPress $(wp --allow-root core version 2>/dev/null), prefix wpdst_, $(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_dest';") tables, $(wp --allow-root option get home 2>/dev/null)"

echo
echo "--- 2. Source site --------------------------------------------"
cd $SRC
rsync -a --exclude vendor --exclude .phpunit.cache --exclude tests $PLUGIN/ $SRC/wp-content/plugins/sh-clone-migration/
rm -f $SRC/wp-content/shcm-storage/archives/*.wpress $SRC/wp-content/shcm-storage/archives/*.wpress.sha256
echo "source:      WordPress $(wp --allow-root core version 2>/dev/null), prefix wpsrc_, $(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_source';") tables, $(wp --allow-root option get home 2>/dev/null)"
echo "content:     $(du -sh wp-content | cut -f1), $(find wp-content -type f | wc -l) files"
echo "plugins:     $(wp --allow-root plugin list --status=active --field=name 2>/dev/null | grep -v PHP | tr '\n' ' ')"

echo
echo "--- 3. Export --------------------------------------------------"
START=$(date +%s.%N)
ARCHIVE=$(wp --allow-root shcm export --name=e2e --porcelain 2>/dev/null | grep wpress | tail -1)
END=$(date +%s.%N)
echo "archive:     $(basename $ARCHIVE)"
echo "size:        $(du -h $ARCHIVE | cut -f1)"
echo "duration:    $(echo "$END - $START" | bc)s"
grep -E "Scan complete|Database export finished|Files exported|Archive finalised|Archive verified" $SRC/wp-content/shcm-storage/logs/*.log | tail -5 | sed 's/^/  /'

echo
echo "--- 4. Verify the archive --------------------------------------"
wp --allow-root shcm verify $(basename $ARCHIVE) 2>/dev/null | grep -vE '^PHP:|trace|^#0|^\)\]|^ *.trace' | grep -E 'Success|Error|verified|entries' | tail -1

echo
echo "--- 5. Import into the destination -----------------------------"
mkdir -p $DST/wp-content/shcm-storage/archives
cp $ARCHIVE $DST/wp-content/shcm-storage/archives/
cd $DST
START=$(date +%s.%N)
wp --allow-root shcm import $(basename $ARCHIVE) --yes 2>/dev/null | grep -vE "^PHP:|trace|^#0|^\)" | sed 's/^/  /'
END=$(date +%s.%N)
echo "duration:    $(echo "$END - $START" | bc)s"

echo
echo "--- 6. Deep verification of the restored site -------------------"
wp --allow-root eval-file /home/user/scripts/check.php 2>/dev/null > /tmp/e2e-check.txt || true
grep -E "^  (PASS|FAIL)" /tmp/e2e-check.txt | sed 's/^/  /'
echo
echo "  checks passed: $(grep -c '^  PASS' /tmp/e2e-check.txt), failed: $(grep -c '^  FAIL' /tmp/e2e-check.txt)"

echo
echo "--- 7. Live HTTP checks -----------------------------------------"
cd $DST && PHP_CLI_SERVER_WORKERS=8 nohup php -S 0.0.0.0:8082 -t $DST /home/user/scripts/router.php > /tmp/dest-server.log 2>&1 &
sleep 4
H="Host: destination.test:8082"; B=http://127.0.0.1:8082
for path in / /about/ /contact/ /elementor-landing/ /percent-edge-cases/ /portfolio/project-1/ /category/architecture/ /product/product-1/ /shop/ /wp-login.php; do
  code=$(curl -sS -m 60 -o /tmp/p.html -w "%{http_code}" -H "$H" "$B$path" || echo 000)
  refs=$(grep -c 'source.test' /tmp/p.html 2>/dev/null | head -1)
  printf "  %-26s HTTP %s   source.test references: %s\n" "$path" "$code" "$refs"
done
IMG=$(curl -sS -m 30 -H "$H" "$B/" | grep -o '/wp-content/uploads/[^"]*red-pixel.png' | head -1)
curl -sS -m 30 -o /dev/null -H "$H" -w "  media (png)                HTTP %{http_code}   %{content_type} %{size_download} bytes\n" "$B$IMG"
curl -sS -m 30 -o /dev/null -H "$H" -w "  media (pdf)                HTTP %{http_code}   %{content_type} %{size_download} bytes\n" "$B/wp-content/uploads/2026/09/sample-document.pdf"

echo
echo "--- 8. File fidelity --------------------------------------------"
IDENTICAL=0; DIFFERENT=0
while IFS= read -r f; do
  rel=${f#$SRC/wp-content/}
  if [ -f "$DST/wp-content/$rel" ]; then
    if cmp -s "$f" "$DST/wp-content/$rel"; then IDENTICAL=$((IDENTICAL+1)); else DIFFERENT=$((DIFFERENT+1)); echo "  differs: $rel"; fi
  fi
done < <(find $SRC/wp-content -type f -not -path "*/shcm-storage/*" | head -4000)
echo "  compared 4000 files: $IDENTICAL identical, $DIFFERENT different"

echo
echo "=============================================================="
echo " done"
echo "=============================================================="
