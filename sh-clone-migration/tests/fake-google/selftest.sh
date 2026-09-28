#!/usr/bin/env bash
# Self-test of the fake Google OAuth 2.0 + Drive v3 server (tests/fake-google/router.php).
#
# Usage:  tests/fake-google/selftest.sh [port]
#   port  first port to try (default 8091); if it is taken, the next free one is used.
# Needs php (CLI) and curl. Prints PASS/FAIL per check and exits non-zero when any check fails.
# Set PHP_CLI_SERVER_WORKERS=4 in the environment to exercise the flock()ed state with parallel workers.
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT="${1:-8091}"
CLIENT_ID='test-client.apps.googleusercontent.com'
CLIENT_SECRET='GOCSPX-fake-secret-0123456789'
REDIRECT='http://localhost:8080/wp-admin/admin-post.php'
SCOPE='https://www.googleapis.com/auth/drive.file'
FOLDER_MIME='application/vnd.google-apps.folder'
TOTAL=1300000

port_busy() { php -r '$s = @fsockopen("127.0.0.1", (int) $argv[1], $e, $m, 0.2); exit($s ? 0 : 1);' "$1"; }
tries=0
while port_busy "$PORT"; do
	PORT=$((PORT + 1))
	tries=$((tries + 1))
	if [ "$tries" -gt 50 ]; then echo "No free port found." >&2; exit 2; fi
done

WORK="$(mktemp -d "${TMPDIR:-/tmp}/shcm-fake-google-selftest.XXXXXX")"
export FAKE_GOOGLE_DIR="$WORK/state"
# The checks assume the defaults, whatever the caller's environment says.
unset FAKE_GOOGLE_CLIENT_ID FAKE_GOOGLE_CLIENT_SECRET FAKE_GOOGLE_ACCESS_TOKEN_TTL FAKE_GOOGLE_QUOTA_LIMIT \
	FAKE_GOOGLE_QUOTA_USAGE FAKE_GOOGLE_EMAIL FAKE_GOOGLE_NAME FAKE_GOOGLE_REDIRECT_URIS FAKE_GOOGLE_BASE_URL \
	FAKE_GOOGLE_PUBLISHING_STATUS FAKE_GOOGLE_MAX_UPLOAD_SIZE
php -S "127.0.0.1:$PORT" "$HERE/router.php" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
cleanup() {
	pkill -P "$SERVER_PID" 2>/dev/null
	kill "$SERVER_PID" 2>/dev/null
	wait "$SERVER_PID" 2>/dev/null
	rm -rf "$WORK"
}
trap cleanup EXIT
BASE="http://127.0.0.1:$PORT"
H="$WORK/headers"
B="$WORK/body"

for _ in $(seq 1 50); do
	[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/__health")" = 200 ] && break
	sleep 0.1
done

PASSED=0
FAILED=0
pass() { printf 'PASS  %s\n' "$1"; PASSED=$((PASSED + 1)); }
fail() { printf 'FAIL  %s  (%s)\n' "$1" "$2"; FAILED=$((FAILED + 1)); }
eq() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected [$2], got [$3]"; fi; }
has() { case "$3" in *"$2"*) pass "$1" ;; *) fail "$1" "[$3] does not contain [$2]" ;; esac; }
hasnt() { case "$3" in *"$2"*) fail "$1" "unexpected [$2]" ;; *) pass "$1" ;; esac; }
# matches NAME ERE VALUE: VALUE must match the extended regular expression.
matches() { if [[ "$3" =~ $2 ]]; then pass "$1"; else fail "$1" "[$3] does not match /$2/"; fi; }

# http METHOD URL [curl args...]: prints the status; headers go to $H, the body to $B. "Expect:" is blanked because
# php -S never answers "100-continue" and curl would stall a second on every large PUT.
http() {
	local method="$1" url="$2"
	shift 2
	curl -s -H 'Expect:' -X "$method" -D "$H" -o "$B" -w '%{http_code}' "$@" "$url"
}
hdr() { tr -d '\r' <"$H" | grep -i "^$1:" | head -n 1 | cut -d' ' -f2-; }
body() { cat "$B"; }
# j PATH: value at a dotted path of the last JSON body ("<missing>" when absent).
j() {
	php -r '
		$d = json_decode((string) file_get_contents($argv[1]), true);
		foreach ("" === $argv[2] ? array() : explode(".", $argv[2]) as $k) {
			if (!is_array($d) || !array_key_exists($k, $d)) { echo "<missing>"; exit(0); }
			$d = $d[$k];
		}
		if (is_bool($d)) { echo $d ? "true" : "false"; } elseif (null === $d) { echo "null"; }
		elseif (is_scalar($d)) { echo $d; } else { echo json_encode($d, JSON_UNESCAPED_SLASHES); }
	' "$B" "$1"
}
jcount() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); foreach (explode(".", $argv[2]) as $k) { $d = is_array($d) && isset($d[$k]) ? $d[$k] : null; } echo is_array($d) ? count($d) : -1;' "$B" "$1"; }
jkeys() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $k = array_keys((array) $d); sort($k); echo implode(",", $k);' "$B"; }
enc() { php -r 'echo rawurlencode($argv[1]);' "$1"; }
qparam() { php -r 'parse_str((string) parse_url($argv[1], PHP_URL_QUERY), $q); echo isset($q[$argv[2]]) ? $q[$argv[2]] : "<missing>";' "$1" "$2"; }
sha() { php -r 'echo hash_file("sha256", $argv[1]);' "$1"; }
md5f() { php -r 'echo hash_file("md5", $argv[1]);' "$1"; }
ctl() {
	local s
	s=$(curl -s -o "$WORK/ctl" -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "$1" "$BASE/__control")
	[ "$s" = 200 ] || fail "control $1" "HTTP $s $(cat "$WORK/ctl")"
}
# ctl_status JSON: posts a control request and prints its HTTP status (the body lands in $B).
ctl_status() { http POST "$BASE/__control" -H 'Content-Type: application/json' --data "$1"; }
# uplog: "status|content_length|authorization" of the newest request to /upload/drive/v3/files in the request log
# (content_length "absent" when the header was not sent).
uplog() {
	curl -s -o "$WORK/state.json" "$BASE/__state"
	php -r '
		$d = json_decode((string) file_get_contents($argv[1]), true);
		foreach (array_reverse($d["log"]) as $e) {
			if ("/upload/drive/v3/files" !== $e["path"]) { continue; }
			$cl = array_key_exists("content_length", $e) ? (null === $e["content_length"] ? "absent" : $e["content_length"]) : "<missing>";
			$au = array_key_exists("authorization", $e) ? ($e["authorization"] ? "true" : "false") : "<missing>";
			echo $e["status"], "|", $cl, "|", $au;
			exit(0);
		}
		echo "<none>";
	' "$WORK/state.json"
}
# state_value PATH: value at a dotted path of GET /__state.
state_value() { curl -s -o "$B" "$BASE/__state"; j "$1"; }
# part_bytes: total size of the open upload sessions' part files on disk.
part_bytes() { cat "$FAKE_GOOGLE_DIR"/uploads/*.part 2>/dev/null | wc -c | tr -d ' '; }
auth_url() { echo "$BASE/o/oauth2/v2/auth?client_id=$(enc "$1")&redirect_uri=$(enc "$REDIRECT")&response_type=code&scope=$(enc "$SCOPE")&access_type=offline&state=st-123$2"; }
exchange() {
	http POST "$BASE/token" --data-urlencode "code=$1" --data-urlencode "client_id=$CLIENT_ID" \
		--data-urlencode "client_secret=$2" --data-urlencode "redirect_uri=$3" --data-urlencode 'grant_type=authorization_code'
}
refresh() {
	http POST "$BASE/token" --data-urlencode "refresh_token=$1" --data-urlencode "client_id=$CLIENT_ID" \
		--data-urlencode "client_secret=$CLIENT_SECRET" --data-urlencode 'grant_type=refresh_token'
}

echo "Fake Google server on $BASE (state in $FAKE_GOOGLE_DIR)"
ctl '{"reset":true}'

echo "--- OAuth"
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' "$(auth_url "$CLIENT_ID" '&prompt=consent')")
has "auth: 302 back to the redirect_uri" "$REDIRECT?" "$LOC"
eq "auth: state echoed" "st-123" "$(qparam "$LOC" state)"
CODE=$(qparam "$LOC" code)
has "auth: code issued" "4/0fake-" "$CODE"
eq "auth: granted scope returned" "$SCOPE" "$(qparam "$LOC" scope)"

s=$(http GET "$(auth_url 'nope.apps.googleusercontent.com' '')")
eq "auth: unknown client -> 400 page" 400 "$s"
has "auth: page says Error 401: invalid_client" "Error 401: invalid_client" "$(body)"

ctl '{"deny_next_consent":true}'
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' "$(auth_url "$CLIENT_ID" '&prompt=consent')")
eq "auth: denied consent -> error=access_denied" "access_denied" "$(qparam "$LOC" error)"
eq "auth: denied consent keeps state" "st-123" "$(qparam "$LOC" state)"

s=$(exchange "$CODE" "$CLIENT_SECRET" "$REDIRECT")
eq "token: code exchange -> 200" 200 "$s"
AT=$(j access_token)
RT=$(j refresh_token)
has "token: access token" "ya29.fake-" "$AT"
has "token: refresh token" "1//fake-" "$RT"
eq "token: token_type" "Bearer" "$(j token_type)"
eq "token: expires_in" "3600" "$(j expires_in)"
has "token: scope contains drive.file" "$SCOPE" "$(j scope)"
s=$(exchange "$CODE" "$CLIENT_SECRET" "$REDIRECT")
eq "token: reused code -> 400 invalid_grant" "400 invalid_grant" "$s $(j error)"

LOC=$(curl -s -o /dev/null -w '%{redirect_url}' "$(auth_url "$CLIENT_ID" '&prompt=consent')")
CODE2=$(qparam "$LOC" code)
s=$(exchange "$CODE2" "wrong-secret" "$REDIRECT")
eq "token: wrong client secret -> 401 invalid_client" "401 invalid_client" "$s $(j error)"
s=$(exchange "$CODE2" "$CLIENT_SECRET" "http://localhost:8080/other")
eq "token: different redirect_uri -> 400 redirect_uri_mismatch" "400 redirect_uri_mismatch" "$s $(j error)"

LOC=$(curl -s -o /dev/null -w '%{redirect_url}' "$(auth_url "$CLIENT_ID" '')")
s=$(exchange "$(qparam "$LOC" code)" "$CLIENT_SECRET" "$REDIRECT")
eq "token: re-authorization without prompt=consent -> no refresh_token" "200 <missing>" "$s $(j refresh_token)"

s=$(refresh "$RT")
eq "refresh: 200" 200 "$s"
AT=$(j access_token)
has "refresh: new access token" "ya29.fake-" "$AT"
eq "refresh: no refresh_token in the answer" "<missing>" "$(j refresh_token)"
AUTH=(-H "Authorization: Bearer $AT")
JSON=(-H 'Content-Type: application/json; charset=UTF-8')

echo "--- Auth errors, about"
s=$(http GET "$BASE/drive/v3/files")
eq "drive: no Authorization -> 401 required" "401 required UNAUTHENTICATED" "$s $(j error.errors.0.reason) $(j error.status)"
s=$(http GET "$BASE/drive/v3/files" -H 'Authorization: Bearer ya29.bogus')
eq "drive: bad token -> 401 authError" "401 authError" "$s $(j error.errors.0.reason)"
s=$(http GET "$BASE/drive/v3/about" "${AUTH[@]}")
eq "about: fields missing -> 400 required" "400 required" "$s $(j error.errors.0.reason)"
s=$(http GET "$BASE/drive/v3/about" "${AUTH[@]}" -G --data-urlencode 'fields=user(displayName,emailAddress),storageQuota(limit,usage,usageInDrive,usageInDriveTrash),maxUploadSize')
eq "about: 200" 200 "$s"
eq "about: email" "owner@example.test" "$(j user.emailAddress)"
eq "about: display name" "Test Owner" "$(j user.displayName)"
eq "about: quota limit (string)" "16106127360" "$(j storageQuota.limit)"
eq "about: usage" "0" "$(j storageQuota.usage)"
eq "about: only requested fields" "maxUploadSize,storageQuota,user" "$(jkeys)"

echo "--- Folders and queries"
s=$(http POST "$BASE/drive/v3/files?fields=id,name,mimeType,appProperties" "${AUTH[@]}" "${JSON[@]}" \
	--data '{"name":"SH Clone Migration Backups (example.test)","mimeType":"'"$FOLDER_MIME"'","appProperties":{"shcm_role":"backup_root","shcm_site":"site-abc"}}')
eq "folder: create -> 200" 200 "$s"
FOLDER=$(j id)
eq "folder: appProperties stored" "site-abc" "$(j appProperties.shcm_site)"
http POST "$BASE/drive/v3/files" "${AUTH[@]}" "${JSON[@]}" \
	--data '{"name":"Decoy","mimeType":"'"$FOLDER_MIME"'","appProperties":{"shcm_role":"backup_root","shcm_site":"site-other"}}' >/dev/null
http POST "$BASE/drive/v3/files" "${AUTH[@]}" "${JSON[@]}" --data '{"name":"Bob'"'"'s backups","mimeType":"'"$FOLDER_MIME"'"}' >/dev/null
Q="mimeType='$FOLDER_MIME' and trashed=false and appProperties has { key='shcm_role' and value='backup_root' } and appProperties has { key='shcm_site' and value='site-abc' }"
s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" -G --data-urlencode "q=$Q" --data-urlencode 'spaces=drive' \
	--data-urlencode 'orderBy=createdTime' --data-urlencode 'pageSize=10' --data-urlencode 'fields=files(id,name,createdTime)')
eq "folder: find by appProperties -> exactly our folder" "200 1 $FOLDER" "$s $(jcount files) $(j files.0.id)"
s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" -G --data-urlencode "q=name = 'Bob\\'s backups' and trashed = false")
eq "query: \\' escape in a string" "200 1" "$s $(jcount files)"
s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" -G --data-urlencode "q=name = 'unterminated")
eq "query: syntax error -> 400 invalid" "400 invalid q" "$s $(j error.errors.0.reason) $(j error.errors.0.location)"
s=$(http GET "$BASE/drive/v3/files/$FOLDER" "${AUTH[@]}")
eq "files.get: default fields only (Drive v3)" "200 id,kind,mimeType,name" "$s $(jkeys)"
s=$(http GET "$BASE/drive/v3/files/$FOLDER" "${AUTH[@]}" -G --data-urlencode 'fields=id,sha256checksum')
eq "files.get: unknown field -> 400" "400 invalidParameter" "$s $(j error.errors.0.reason)"

echo "--- Resumable upload"
head -c "$TOTAL" /dev/urandom >"$WORK/upload.bin"
LOCAL_SHA=$(sha "$WORK/upload.bin")
LOCAL_MD5=$(md5f "$WORK/upload.bin")
ctl '{"quota":{"limit":1000000}}'
s=$(http POST "$BASE/upload/drive/v3/files?uploadType=resumable" "${AUTH[@]}" "${JSON[@]}" -H "X-Upload-Content-Length: $TOTAL" --data '{"name":"too-big.wpress"}')
eq "upload: declared size over quota -> 403 storageQuotaExceeded" "403 storageQuotaExceeded" "$s $(j error.errors.0.reason)"
ctl '{"quota":{"limit":16106127360}}'
s=$(http POST "$BASE/upload/drive/v3/files?uploadType=resumable" "${AUTH[@]}")
eq "upload: initiation without Content-Length -> 411 HTML" "411|text/html; charset=utf-8" "$s|$(hdr Content-Type)"
has "upload: 411 page as Google's front end words it" "POST requests require a <code>Content-length</code> header." "$(body)"

META='{"name":"example.test-backup-202609281200.wpress","parents":["'"$FOLDER"'"],"mimeType":"application/octet-stream","description":"SH Clone Migration backup","appProperties":{"shcm_site":"site-abc","shcm_backup":"job-1","shcm_sha256":"'"$LOCAL_SHA"'"}}'
FIELDS='id,name,size,md5Checksum,sha256Checksum,createdTime,parents,appProperties'
s=$(http POST "$BASE/upload/drive/v3/files?uploadType=resumable&fields=$FIELDS" "${AUTH[@]}" "${JSON[@]}" \
	-H 'X-Upload-Content-Type: application/octet-stream' -H "X-Upload-Content-Length: $TOTAL" --data "$META")
SESSION=$(hdr Location)
eq "upload: initiation -> 200 with empty body" "200 0" "$s $(wc -c <"$B" | tr -d ' ')"
has "upload: Location carries upload_id" "upload_id=" "$SESSION"
has "upload: Location keeps the fields parameter" "fields=" "$SESSION"
eq "log: initiation logged with its Content-Length and Authorization" "200|${#META}|true" "$(uplog)"

put_chunk() { # START LENGTH [file] (no Authorization: the session URI is the credential)
	local file="${3:-$WORK/upload.bin}" total
	total=$(wc -c <"$file" | tr -d ' ')
	tail -c +$(($1 + 1)) "$file" | head -c "$2" >"$WORK/chunk"
	http PUT "$SESSION" -H "Content-Range: bytes $1-$(($1 + $2 - 1))/$total" --data-binary "@$WORK/chunk"
}
status_query() { http PUT "$SESSION" -H "Content-Range: bytes */$1" --data-binary ''; }

s=$(put_chunk 0 524288)
eq "chunk 1 (512 KiB) -> 308 Range bytes=0-524287" "308 bytes=0-524287" "$s $(hdr Range)"
eq "log: chunk PUT has Content-Length and no Authorization" "308|524288|false" "$(uplog)"
s=$(status_query "$TOTAL")
eq "status query -> 308 Range bytes=0-524287" "308 bytes=0-524287" "$s $(hdr Range)"
eq "log: status query sent Content-Length: 0" "308|0|false" "$(uplog)"
s=$(http PUT "$SESSION" -H "Content-Range: bytes */$TOTAL")
eq "status query without Content-Length -> 411 HTML" "411|text/html; charset=utf-8" "$s|$(hdr Content-Type)"
has "411 page names the missing header" "PUT requests require a <code>Content-length</code> header." "$(body)"
eq "log: the 411 request had no Content-Length" "411|absent|false" "$(uplog)"
ctl '{"require_content_length":false}'
s=$(http PUT "$SESSION" -H "Content-Range: bytes */$TOTAL")
eq "require_content_length false: the same request -> 308" "308 bytes=0-524287" "$s $(hdr Range)"
ctl '{"require_content_length":true}'
s=$(http PUT "$SESSION" "${AUTH[@]}" -H "Content-Range: bytes */$TOTAL" --data-binary '')
eq "Authorization on the session URI: ignored, but logged" "308 308|0|true" "$s $(uplog)"
s=$(ctl_status '{"expire_sessions":true,"access_token_ttl":-1}')
eq "control: one invalid value -> 400, nothing applied" "400 false" "$s $(j ok)"
eq "control 400 applies nothing: the session's bytes stay on disk" "524288" "$(part_bytes)"
s=$(status_query "$TOTAL")
eq "control 400 applies nothing: the session stays open" "308 bytes=0-524287" "$s $(hdr Range)"
s=$(put_chunk 524288 100000)
eq "non-final chunk not a multiple of 256 KiB -> 400" "400" "$s"
ctl '{"chunk_commit_limit":262144}'
s=$(put_chunk 524288 524288)
eq "chunk 2 with partial-commit fault -> 308 Range bytes=0-786431" "308 bytes=0-786431" "$s $(hdr Range)"
s=$(put_chunk 786432 262144)
eq "resend the uncommitted rest -> 308 Range bytes=0-1048575" "308 bytes=0-1048575" "$s $(hdr Range)"
s=$(put_chunk 524288 524288)
eq "resending already-committed bytes changes nothing" "308 bytes=0-1048575" "$s $(hdr Range)"
ctl '{"fail":[{"method":"PUT","path":"/upload/drive/v3/files","status":503,"times":1}]}'
s=$(put_chunk 1048576 $((TOTAL - 1048576)))
eq "fault injection: final chunk -> 503" "503" "$s"
s=$(status_query "$TOTAL")
eq "status query after 503 -> nothing lost, nothing added" "308 bytes=0-1048575" "$s $(hdr Range)"
s=$(put_chunk 1048576 $((TOTAL - 1048576)))
eq "final chunk -> 200" "200" "$s"
FILE_ID=$(j id)
eq "final: size (string)" "$TOTAL" "$(j size)"
eq "final: sha256Checksum equals the local file" "$LOCAL_SHA" "$(j sha256Checksum)"
eq "final: md5Checksum equals the local file" "$LOCAL_MD5" "$(j md5Checksum)"
eq "final: parent folder" "$FOLDER" "$(j parents.0)"
eq "final: appProperties kept" "job-1" "$(j appProperties.shcm_backup)"
matches "final: createdTime is RFC 3339 with ms" '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z$' "$(j createdTime)"
s=$(status_query "$TOTAL")
eq "status query after completion -> 200 same file" "200 $FILE_ID" "$s $(j id)"
curl -s -o "$WORK/blob.bin" "$BASE/__blob/$FILE_ID"
eq "/__blob sha256 equals the local file" "$LOCAL_SHA" "$(sha "$WORK/blob.bin")"

head -c 300000 /dev/urandom >"$WORK/small.bin"
s=$(http POST "$BASE/upload/drive/v3/files?uploadType=resumable&fields=id,size" "${AUTH[@]}" "${JSON[@]}" -H 'X-Upload-Content-Length: 300000' \
	--data '{"name":"example.test-backup-202609291200.wpress","parents":["'"$FOLDER"'"],"appProperties":{"shcm_site":"site-abc"}}')
SESSION=$(hdr Location)
ctl '{"fail":[{"method":"PUT","path":"/upload/drive/v3/files","status":0,"process":true}]}'
curl -s -H 'Expect:' -X PUT -H 'Content-Range: bytes 0-299999/300000' --data-binary "@$WORK/small.bin" -o /dev/null "$SESSION" 2>/dev/null
rc=$?
if [ "$rc" -ne 0 ]; then pass "fault status 0: connection dropped (curl exit $rc)"; else fail "fault status 0: connection dropped" "curl succeeded"; fi
s=$(status_query 300000)
eq "dropped answer after processing: status query -> 200 finalized" "200 300000" "$s $(j size)"
SMALL_ID=$(j id)

s=$(http POST "$BASE/drive/v3/files?fields=id" "${AUTH[@]}" "${JSON[@]}" \
	--data '{"name":"empty.wpress","parents":["'"$FOLDER"'"],"appProperties":{"shcm_site":"site-abc"}}')
EMPTY_ID=$(j id)

echo "--- Listing, trash"
Q="'$FOLDER' in parents and trashed=false and appProperties has { key='shcm_site' and value='site-abc' }"
# list_walk ORDER PAGE_SIZE: follows nextPageToken through the listing of $Q until it is absent. Sets WALK_PAGES,
# WALK_SIZES (files per page, comma-separated) and WALK_IDS (ids in the order served). ORDER "" = Google's default.
list_walk() {
	local token="" args ids
	WALK_PAGES=0
	WALK_SIZES=""
	WALK_IDS=""
	while [ "$WALK_PAGES" -lt 10 ]; do
		args=(-G --data-urlencode "q=$Q" --data-urlencode "pageSize=$2" --data-urlencode 'fields=nextPageToken,files(id)')
		[ -n "$1" ] && args+=(--data-urlencode "orderBy=$1")
		[ -n "$token" ] && args+=(--data-urlencode "pageToken=$token")
		s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" "${args[@]}")
		if [ "$s" != 200 ]; then WALK_IDS="$WALK_IDS HTTP-$s"; return; fi
		WALK_PAGES=$((WALK_PAGES + 1))
		WALK_SIZES="$WALK_SIZES${WALK_SIZES:+,}$(jcount files)"
		ids=$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo implode(" ", array_column(isset($d["files"]) ? $d["files"] : array(), "id"));' "$B")
		WALK_IDS="$WALK_IDS${WALK_IDS:+${ids:+ }}$ids"
		token=$(j nextPageToken)
		[ "$token" = "<missing>" ] && return
	done
}
# The oldest file becomes the most recently modified, so createdTime order differs from the default
# (folder,modifiedTime desc,name), from name order and from creation (seq) order.
s=$(http PATCH "$BASE/drive/v3/files/$FILE_ID?fields=id" "${AUTH[@]}" "${JSON[@]}" --data '{"description":"touched"}')
eq "patch: description -> 200 (oldest file is now the newest modified)" "200" "$s"
list_walk 'createdTime desc' 1
eq "list: orderBy createdTime desc, pageSize=1 -> 3 pages, newest created first" "3|1,1,1|$EMPTY_ID $SMALL_ID $FILE_ID" "$WALK_PAGES|$WALK_SIZES|$WALK_IDS"
list_walk 'createdTime' 10
eq "list: orderBy createdTime -> oldest created first" "1|$FILE_ID $SMALL_ID $EMPTY_ID" "$WALK_PAGES|$WALK_IDS"
list_walk '' 10
eq "list: default order is folder,modifiedTime desc,name" "$FILE_ID $EMPTY_ID $SMALL_ID" "$WALK_IDS"
ctl '{"short_pages":1}'
list_walk 'createdTime' 10
eq "short_pages 1: a short page still has nextPageToken, nothing is skipped" "2|1,2|$FILE_ID $SMALL_ID $EMPTY_ID" "$WALK_PAGES|$WALK_SIZES|$WALK_IDS"
ctl '{"short_pages":0,"short_pages_times":2}'
list_walk 'createdTime' 10
eq "short_pages 0 x2: two empty pages with nextPageToken, then the rest" "3|0,0,3|$FILE_ID $SMALL_ID $EMPTY_ID" "$WALK_PAGES|$WALK_SIZES|$WALK_IDS"
eq "short_pages is off once used up" "null" "$(state_value controls.short_pages)"
ctl '{"short_pages":2}'
list_walk 'createdTime' 1
eq "short_pages: pages that are not cut do not use it up" "3|1,1,1|1" "$WALK_PAGES|$WALK_SIZES|$(state_value controls.short_pages_times)"
ctl '{"short_pages":null}'
s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" -G --data-urlencode "q=$Q" --data-urlencode 'pageSize=1' --data-urlencode 'fields=files(id)')
eq "list: no nextPageToken unless it is in fields (as Google)" "200 <missing>" "$s $(j nextPageToken)"
s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" -G --data-urlencode 'pageToken=bogus')
eq "list: bad pageToken -> 400" "400" "$s"
s=$(http PATCH "$BASE/drive/v3/files/$EMPTY_ID?fields=id,trashed" "${AUTH[@]}" "${JSON[@]}" --data '{"trashed":true}')
eq "patch: trash -> 200 trashed" "200 true" "$s $(j trashed)"
s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" -G --data-urlencode "q=$Q")
eq "list: trashed=false hides the trashed file" "2" "$(jcount files)"
s=$(http GET "$BASE/drive/v3/files" "${AUTH[@]}" -G --data-urlencode "q='$FOLDER' in parents")
eq "list: without trashed=false Google also returns trashed files" "3" "$(jcount files)"

echo "--- Download, delete"
s=$(ctl_status '{"reset":true,"quota":{"limit":"x"}}')
eq "control: reset plus an invalid value -> 400" "400 false" "$s $(j ok)"
curl -s -o "$WORK/blob.bin" "$BASE/__blob/$FILE_ID"
eq "control 400 applies nothing: no blob is wiped" "$LOCAL_SHA" "$(sha "$WORK/blob.bin")"
s=$(http GET "$BASE/drive/v3/files/$FILE_ID?alt=media" "${AUTH[@]}" -H 'Range: bytes=100-199')
tail -c +101 "$WORK/upload.bin" | head -c 100 >"$WORK/slice.bin"
eq "alt=media Range -> 206 Content-Range" "206 bytes 100-199/$TOTAL" "$s $(hdr Content-Range)"
cmp -s "$B" "$WORK/slice.bin" && pass "alt=media Range: bytes match" || fail "alt=media Range: bytes match" "differs"
s=$(http GET "$BASE/drive/v3/files/$FILE_ID?alt=media" "${AUTH[@]}")
eq "alt=media full -> 200, sha256 matches" "200 $LOCAL_SHA" "$s $(sha "$B")"
s=$(http DELETE "$BASE/drive/v3/files/$SMALL_ID" "${AUTH[@]}")
eq "delete -> 204 with empty body" "204 0" "$s $(wc -c <"$B" | tr -d ' ')"
s=$(http GET "$BASE/drive/v3/files/$SMALL_ID" "${AUTH[@]}")
eq "get deleted file -> 404 notFound" "404 notFound" "$s $(j error.errors.0.reason)"

echo "--- Sessions, faults, expiry"
s=$(http POST "$BASE/upload/drive/v3/files?uploadType=resumable" "${AUTH[@]}" "${JSON[@]}" -H 'X-Upload-Content-Length: 300000' --data '{"name":"late.wpress"}')
SESSION=$(hdr Location)
s=$(ctl_status '{"advance_time":1000000,"quota":{"limit":"x"}}')
eq "control: advance_time plus an invalid value -> 400" "400 false" "$s $(j ok)"
eq "control 400 applies nothing: the fake clock stays put" "0" "$(state_value clock_offset)"
s=$(http GET "$BASE/drive/v3/about?fields=user" "${AUTH[@]}")
eq "control 400 applies nothing: the access token still works" "200" "$s"
s=$(status_query 300000)
eq "control 400 applies nothing: the open session survives (308, no Range)" "308|" "$s|$(hdr Range)"
ctl '{"expire_sessions":true}'
s=$(put_chunk 0 262144 "$WORK/small.bin")
eq "expired session -> 404 text/plain Not Found" "404|text/plain; charset=utf-8|Not Found" "$s|$(hdr Content-Type)|$(body)"
ctl '{"fail":[{"method":"GET","path":"/drive/v3/about","status":503,"times":1}]}'
s=$(http GET "$BASE/drive/v3/about?fields=user" "${AUTH[@]}")
eq "fault injection: GET about -> 503 backendError" "503 backendError" "$s $(j error.errors.0.reason)"
s=$(http GET "$BASE/drive/v3/about?fields=user" "${AUTH[@]}")
eq "fault consumed: next call -> 200" "200" "$s"
ctl '{"expire_access_tokens":true}'
s=$(http GET "$BASE/drive/v3/about?fields=user" "${AUTH[@]}")
eq "expired access token -> 401 authError" "401 authError" "$s $(j error.errors.0.reason)"
refresh "$RT" >/dev/null
AT=$(j access_token)
AUTH=(-H "Authorization: Bearer $AT")
s=$(http GET "$BASE/drive/v3/about?fields=user" "${AUTH[@]}")
eq "refreshed token works again" "200" "$s"

echo "--- Revoke"
s=$(http POST "$BASE/revoke" --data-urlencode "token=$RT")
eq "revoke -> 200 {}" "200 {}" "$s $(tr -d ' \n' <"$B")"
s=$(refresh "$RT")
eq "refresh after revoke -> 400 invalid_grant" "400 invalid_grant|Token has been expired or revoked." "$s $(j error)|$(j error_description)"
s=$(http GET "$BASE/drive/v3/about?fields=user" "${AUTH[@]}")
eq "access token revoked with the grant -> 401" "401" "$s"
s=$(http POST "$BASE/revoke" --data-urlencode "token=$RT")
eq "revoke again -> 400 invalid_token" "400 invalid_token" "$s $(j error)"

echo "--- State"
curl -s -o "$B" "$BASE/__state"
STATE=$(body)
hasnt "state: refresh token redacted" "$RT" "$STATE"
has "state: redacted token keeps last 6 chars" "***${RT: -6}" "$STATE"
hasnt "state/log: upload session ids redacted" "$(qparam "$SESSION" upload_id)" "$STATE"
has "state: request log records 308s" '"status": 308' "$STATE"

echo
echo "$PASSED passed, $FAILED failed"
if [ "$FAILED" -gt 0 ]; then
	echo "--- server log (tail)"
	tail -n 30 "$WORK/server.log"
	exit 1
fi
exit 0
