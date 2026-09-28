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

# Only files under version control ship, so leftovers in the working copy
# (scratch files, test output) never end up in a release.
FILES=( --files-from=- --from0 --ignore-missing-args )
if ! git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	FILES=()
fi

list_files() {
	if [ ${#FILES[@]} -gt 0 ]; then
		git -C "$ROOT/$NAME" ls-files -z
	fi
}

list_files | rsync -a "${FILES[@]}" \
	--exclude 'vendor/***' \
	--exclude 'tests/***' \
	--exclude '.phpunit.cache/***' \
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
