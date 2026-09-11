#!/bin/bash
# Verify that the controlled (merge) import mode keeps tables the archive does not contain.
set -e
DST=/home/user/sites/dest
fuser -k 8082/tcp 2>/dev/null || true
mariadb -e "DROP DATABASE IF EXISTS wp_dest; CREATE DATABASE wp_dest CHARACTER SET utf8mb4; GRANT ALL ON wp_dest.* TO 'wpuser'@'localhost'; FLUSH PRIVILEGES;"
rm -rf $DST && mkdir -p $DST && tar -xzf /tmp/wp.tar.gz -C $DST --strip-components=1
cd $DST
wp --allow-root config create --dbname=wp_dest --dbuser=wpuser --dbpass=wppass --dbhost=127.0.0.1 --dbprefix=wpdst_ --skip-check >/dev/null 2>&1
wp --allow-root core install --url=http://destination.test:8082 --title="Destination Site" --admin_user=destadmin --admin_password=destpass --admin_email=admin@destination.test --skip-email >/dev/null 2>&1
rsync -a --exclude vendor --exclude .phpunit.cache --exclude tests /home/user/SH-Clone-Migration/sh-clone-migration/ $DST/wp-content/plugins/sh-clone-migration/
wp --allow-root plugin activate sh-clone-migration >/dev/null 2>&1

mariadb -e "USE wp_dest; CREATE TABLE wpdst_local_only (id INT PRIMARY KEY, note VARCHAR(50)); INSERT INTO wpdst_local_only VALUES (1,'keep me');"
mkdir -p $DST/wp-content/shcm-storage/archives
cp $(ls /home/user/sites/source/wp-content/shcm-storage/archives/*.wpress | head -1) $DST/wp-content/shcm-storage/archives/
ARCHIVE=$(basename $(ls $DST/wp-content/shcm-storage/archives/*.wpress | head -1))

echo "before: $(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_dest';") tables, local_only rows: $(mariadb -N -e "SELECT COUNT(*) FROM wp_dest.wpdst_local_only;")"
wp --allow-root shcm import "$ARCHIVE" --mode=merge --yes 2>/dev/null | grep -E "Success|Error" | tail -1
echo "after:  $(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_dest';") tables"
echo "local_only survived: $(mariadb -N -e "SELECT note FROM wp_dest.wpdst_local_only WHERE id=1;" 2>&1)"
echo "posts: $(mariadb -N -e "SELECT COUNT(*) FROM wp_dest.wpdst_posts;")"
echo "blogname: $(mariadb -N -e "SELECT option_value FROM wp_dest.wpdst_options WHERE option_name='blogname';")"
