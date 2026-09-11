#!/bin/bash
# Build a distributable plugin ZIP.
#
#   bash scripts/build.sh [output-directory]
#
# The result installs through Plugins > Add New > Upload Plugin.

set -e

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-$ROOT/build}"
NAME="sh-clone-migration"
VERSION=$(grep -m1 "^ \* Version:" "$ROOT/$NAME/$NAME.php" | awk '{print $3}')

rm -rf "$OUT/$NAME" "$OUT/$NAME-$VERSION.zip"
mkdir -p "$OUT"

rsync -a \
	--exclude 'vendor/' \
	--exclude 'tests/' \
	--exclude '.phpunit.cache/' \
	--exclude '.phpunit.result.cache' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpunit.xml.dist' \
	--exclude '*.wpress' \
	"$ROOT/$NAME/" "$OUT/$NAME/"

cp "$ROOT/README.md" "$ROOT/ARCHITECTURE.md" "$ROOT/DEVELOPMENT.md" "$OUT/$NAME/"
mkdir -p "$OUT/$NAME/docs"
cp "$ROOT/docs/"*.md "$OUT/$NAME/docs/"

cd "$OUT"
zip -rq "$NAME-$VERSION.zip" "$NAME"
rm -rf "$OUT/$NAME"

echo "$OUT/$NAME-$VERSION.zip"
unzip -l "$NAME-$VERSION.zip" | tail -1
