#!/bin/bash
# Exercise the archive download endpoint the way browsers and download
# managers (Internet Download Manager, wget -c, curl -C) actually use it.
#
#   bash scripts/download-test.sh <base-url> <host-header> <wp-root> <admin-user> <admin-password>
#
# Example, against the Apache test vhost:
#   bash scripts/download-test.sh http://127.0.0.1:8081 source.test:8081 /home/user/sites/source admin adminpass

set -u
BASE="$1"; HOST="$2"; ROOT="$3"; USER="$4"; PASS="$5"
WORK=$(mktemp -d)
CJ="$WORK/cookies.txt"
PASSES=0; FAILS=0

pass() { PASSES=$((PASSES+1)); printf "  PASS  %s\n" "$1"; }
fail() { FAILS=$((FAILS+1)); printf "  FAIL  %s\n" "$1"; }
check() { if eval "$2"; then pass "$1"; else fail "$1  [$3]"; fi; }
header() { grep -i "^$2:" "$1" | tail -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }
status() { grep -E '^HTTP/' "$1" | tail -1 | awk '{print $2}'; }

curl -sS -m 60 -c "$CJ" -b "$CJ" -H "Host: $HOST" \
	-d "log=$USER&pwd=$PASS&wp-submit=Log+In&redirect_to=$BASE/wp-admin/&testcookie=1" -o /dev/null "$BASE/wp-login.php"
curl -sS -m 60 -b "$CJ" -c "$CJ" -H "Host: $HOST" -o "$WORK/backups.html" "$BASE/wp-admin/admin.php?page=shcm-backups"
URL=$(grep -o 'admin-post.php?action=shcm_download&[^"]*' "$WORK/backups.html" | head -1 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
ARCHIVE=$(echo "$URL" | sed -n 's/.*archive=\([^&]*\).*/\1/p')
FILE="$ROOT/wp-content/shcm-storage/archives/$ARCHIVE"
SIZE=$(stat -c%s "$FILE")
SHA=$(sha256sum "$FILE" | cut -d' ' -f1)
DL="$BASE/wp-admin/$URL"

echo "archive: $ARCHIVE ($SIZE bytes)"
echo
echo "Full download, client advertises gzip (what browsers and IDM send)"
curl -sS -m 600 -b "$CJ" -H "Host: $HOST" -H "Accept-Encoding: gzip, deflate, br" -D "$WORK/h.txt" -o "$WORK/full.bin" "$DL"
check "HTTP 200"                          '[ "$(status $WORK/h.txt)" = 200 ]' "$(status $WORK/h.txt)"
check "Content-Length equals file size"   '[ "$(header $WORK/h.txt Content-Length)" = "$SIZE" ]' "$(header $WORK/h.txt Content-Length)"
check "no Content-Encoding"               '[ -z "$(header $WORK/h.txt Content-Encoding)" ]' "$(header $WORK/h.txt Content-Encoding)"
check "no chunked transfer"               '[ -z "$(header $WORK/h.txt Transfer-Encoding)" ]' "$(header $WORK/h.txt Transfer-Encoding)"
check "Accept-Ranges: bytes"              '[ "$(header $WORK/h.txt Accept-Ranges)" = bytes ]' "$(header $WORK/h.txt Accept-Ranges)"
check "ETag present"                      '[ -n "$(header $WORK/h.txt ETag)" ]' "missing"
check "Last-Modified present"             '[ -n "$(header $WORK/h.txt Last-Modified)" ]' "missing"
check "SHA-256 header matches the file"   '[ "$(header $WORK/h.txt X-SHCM-SHA256)" = "$SHA" ]' "$(header $WORK/h.txt X-SHCM-SHA256)"
check "body is byte-identical"            '[ "$(sha256sum < $WORK/full.bin | cut -d" " -f1)" = "$SHA" ]' "size $(stat -c%s $WORK/full.bin)"
ETAG=$(header "$WORK/h.txt" ETag)

echo
echo "HEAD request (download managers probe the size first)"
curl -sS -m 60 -b "$CJ" -H "Host: $HOST" -I -o /dev/null -D "$WORK/head.txt" "$DL"
check "HEAD returns 200"                  '[ "$(status $WORK/head.txt)" = 200 ]' "$(status $WORK/head.txt)"
check "HEAD carries Content-Length"       '[ "$(header $WORK/head.txt Content-Length)" = "$SIZE" ]' "$(header $WORK/head.txt Content-Length)"

echo
echo "Range requests"
r() { curl -s -m 120 -b "$CJ" -H "Host: $HOST" -H "Accept-Encoding: gzip" -H "Range: $1" ${2:+-H "$2"} -D "$WORK/r.txt" -o "$WORK/r.bin" "$DL"; }
r "bytes=1000-1999"
check "bytes=1000-1999 -> 206, 1000 bytes" '[ "$(status $WORK/r.txt)" = 206 ] && [ "$(stat -c%s $WORK/r.bin)" = 1000 ]' "$(status $WORK/r.txt) $(stat -c%s $WORK/r.bin)"
check "Content-Range correct"             '[ "$(header $WORK/r.txt Content-Range)" = "bytes 1000-1999/$SIZE" ]' "$(header $WORK/r.txt Content-Range)"
check "range bytes match the file"        'cmp -s $WORK/r.bin <(tail -c +1001 $FILE | head -c 1000)' "differs"
r "bytes=-500"
check "suffix bytes=-500 -> last 500"     '[ "$(status $WORK/r.txt)" = 206 ] && cmp -s $WORK/r.bin <(tail -c 500 $FILE)' "$(status $WORK/r.txt) $(header $WORK/r.txt Content-Range)"
r "bytes=$((SIZE-100))-"
check "open range to the end"             '[ "$(status $WORK/r.txt)" = 206 ] && [ "$(stat -c%s $WORK/r.bin)" = 100 ]' "$(status $WORK/r.txt) $(stat -c%s $WORK/r.bin)"
r "bytes=$((SIZE-10))-$((SIZE+5000))"
check "end past EOF is clamped"           '[ "$(status $WORK/r.txt)" = 206 ] && [ "$(stat -c%s $WORK/r.bin)" = 10 ] && [ "$(header $WORK/r.txt Content-Length)" = 10 ]' "$(status $WORK/r.txt) body=$(stat -c%s $WORK/r.bin) Content-Length=$(header $WORK/r.txt Content-Length)"
r "bytes=$((SIZE+10))-$((SIZE+20))"
check "start past EOF -> 416"             '[ "$(status $WORK/r.txt)" = 416 ]' "$(status $WORK/r.txt)"
check "416 carries Content-Range */size"  '[ "$(header $WORK/r.txt Content-Range)" = "bytes */$SIZE" ]' "$(header $WORK/r.txt Content-Range)"
r "bytes=0-9,20-29"
check "multi-range -> full 200 response"  '[ "$(status $WORK/r.txt)" = 200 ] && [ "$(stat -c%s $WORK/r.bin)" = "$SIZE" ]' "$(status $WORK/r.txt) $(stat -c%s $WORK/r.bin)"
r "bytes=0-99" "If-Range: \"stale-etag\""
check "stale If-Range -> full 200"        '[ "$(status $WORK/r.txt)" = 200 ] && [ "$(stat -c%s $WORK/r.bin)" = "$SIZE" ]' "$(status $WORK/r.txt) $(stat -c%s $WORK/r.bin)"
r "bytes=0-99" "If-Range: $ETAG"
check "matching If-Range -> 206"          '[ "$(status $WORK/r.txt)" = 206 ] && [ "$(stat -c%s $WORK/r.bin)" = 100 ]' "$(status $WORK/r.txt) $(stat -c%s $WORK/r.bin)"

echo
echo "Segmented download (8 parallel ranges, like IDM) reassembles byte-exactly"
SEG=$(( (SIZE + 7) / 8 ))
for i in 0 1 2 3 4 5 6 7; do
	S=$((i*SEG)); E=$((S+SEG-1)); [ $E -ge $SIZE ] && E=$((SIZE-1))
	curl -sS -m 600 -b "$CJ" -H "Host: $HOST" -H "Range: bytes=$S-$E" -o "$WORK/seg$i" "$DL" &
done
wait
cat "$WORK"/seg0 "$WORK"/seg1 "$WORK"/seg2 "$WORK"/seg3 "$WORK"/seg4 "$WORK"/seg5 "$WORK"/seg6 "$WORK"/seg7 > "$WORK/joined.bin"
check "joined segments match the archive" '[ "$(sha256sum < $WORK/joined.bin | cut -d" " -f1)" = "$SHA" ]' "size $(stat -c%s $WORK/joined.bin)"

echo
echo "Resume after an interrupted download (curl -C -)"
head -c $((SIZE/3)) "$FILE" > "$WORK/resume.bin"
curl -sS -m 600 -b "$CJ" -H "Host: $HOST" -C - -o "$WORK/resume.bin" "$DL"
check "resumed file matches the archive"  '[ "$(sha256sum < $WORK/resume.bin | cut -d" " -f1)" = "$SHA" ]' "size $(stat -c%s $WORK/resume.bin)"

echo
echo "Access control"
curl -sS -m 30 -H "Host: $HOST" -o /dev/null -D "$WORK/a.txt" "$DL"
check "no session -> refused"             '[ "$(status $WORK/a.txt)" != 200 ] && [ "$(status $WORK/a.txt)" != 206 ]' "$(status $WORK/a.txt)"
curl -sS -m 30 -b "$CJ" -H "Host: $HOST" -o /dev/null -D "$WORK/a.txt" "$(echo "$DL" | sed 's/_wpnonce=[^&]*/_wpnonce=bad/')"
check "bad nonce -> refused"              '[ "$(status $WORK/a.txt)" = 403 ]' "$(status $WORK/a.txt)"
curl -sS -m 30 -b "$CJ" -H "Host: $HOST" -o /dev/null -D "$WORK/a.txt" "$(echo "$DL" | sed 's/archive=[^&]*/archive=..%2F..%2F..%2Fwp-config.php/')"
check "traversal in name -> refused"      '[ "$(status $WORK/a.txt)" != 200 ]' "$(status $WORK/a.txt)"
curl -sS -m 30 -H "Host: $HOST" -o /dev/null -D "$WORK/a.txt" "$BASE/wp-content/shcm-storage/archives/$ARCHIVE"
check "direct URL to the archive refused" '[ "$(status $WORK/a.txt)" != 200 ]' "$(status $WORK/a.txt)"

echo
echo "Incomplete archive (still being written, or its export failed)"
PART="incomplete-$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n').wpress"
head -c 4096 "$FILE" > "$ROOT/wp-content/shcm-storage/archives/$PART"
curl -sS -m 30 -b "$CJ" -H "Host: $HOST" -o "$WORK/i.bin" -D "$WORK/i.txt" "$(echo "$DL" | sed "s/archive=[^&]*/archive=$PART/")"
check "incomplete archive -> 409, not a download" '[ "$(status $WORK/i.txt)" = 409 ] && [ -z "$(header $WORK/i.txt Content-Disposition)" ]' "$(status $WORK/i.txt)"
rm -f "$ROOT/wp-content/shcm-storage/archives/$PART"

rm -rf "$WORK"
echo
echo "RESULT: $PASSES passed, $FAILS failed"
[ "$FAILS" -eq 0 ]
