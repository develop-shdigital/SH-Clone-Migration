#!/bin/bash
# End-to-end test of scheduled backups and Google Drive storage against the
# fake Google server (tests/fake-google/router.php).
#
# Uses the source test site on Apache (PHP-FPM, port 8081), connects Google
# Drive through the real OAuth redirect flow, and checks backups made by
# "Back up now", by the schedule (WP-Cron over HTTP) and by WP-CLI: upload,
# verification, retention on both sides, retries after faults, a restarted
# upload session, a revoked grant, encryption, database-only and files-only
# backups, cancelling, the browser fallback when loopbacks are blocked, the
# copy/clone guard, and that no secret reaches a log.
#
# Usage: scripts/backup-e2e.sh [base-url] [host] [site-root] [user] [password]
set -u

BASE=${1:-http://127.0.0.1:8081}
HOSTNAME_PORT=${2:-source.test:8081}
ROOT=${3:-/home/user/sites/source}
USER_NAME=${4:-admin}
USER_PASS=${5:-adminpass}
REPO=$(cd "$(dirname "$0")/.." && pwd)
PLUGIN=$REPO/sh-clone-migration
FAKE_PORT=${FAKE_PORT:-8091}
FAKE=http://127.0.0.1:$FAKE_PORT
WORK=$(mktemp -d /tmp/shcm-backup-e2e.XXXXXX)
JAR=$WORK/cookies.txt
H="Host: $HOSTNAME_PORT"
PASS=0
FAIL=0

wpc() { ( cd "$ROOT" && wp --allow-root "$@" 2>/dev/null | grep -vE '^PHP|^\s|^#|^\)|^Deprecated' ); }
# The newline keeps a notice printed by another plugin at shutdown (Elementor)
# off the line that carries the value.
wpe() { ( cd "$ROOT" && wp --allow-root eval "$1; echo PHP_EOL;" 2>/dev/null | grep -vE '^PHP|^  |^#|^\)\]|^Deprecated|^.,$|^$' ); }
check() {
	if [ "$2" = "0" ]; then PASS=$((PASS + 1)); printf '  PASS  %s\n' "$1"
	else FAIL=$((FAIL + 1)); printf '  FAIL  %s  %s\n' "$1" "${3:-}"; fi
}
is() { [ "$2" = "$3" ] && check "$1" 0 || check "$1" 1 "(got '$2', want '$3')"; }
control() { curl -s -X POST -H 'Content-Type: application/json' --data "$1" "$FAKE/__control" >/dev/null; }
fstate() { curl -s "$FAKE/__state"; }
ajax() {
	local action=$1; shift
	local args=(--data-urlencode "action=shcm_$action" --data-urlencode "nonce=$NONCE")
	for kv in "$@"; do args+=(--data-urlencode "$kv"); done
	curl -s -b "$JAR" -H "$H" "${args[@]}" "$BASE/wp-admin/admin-ajax.php"
}
# Wait for a job without driving it: the site's own background runner must
# finish it (loopback requests, WP-Cron). $2 = poll action (status or tick).
wait_job() {
	local job=$1 how=${2:-status} limit=${3:-300} start now status msg last=''
	start=$(date +%s)
	while true; do
		status=$(ajax "$how" "job_id=$job" | jq -r '.data.status // "error"')
		msg=$(ajax status "job_id=$job" | jq -r '.data.message // ""')
		[ "$msg" != "$last" ] && echo "        · $status: $msg" && last=$msg
		case "$status" in completed|failed|cancelled) echo "$status" > "$WORK/last-status"; return 0 ;; esac
		now=$(date +%s)
		if [ $((now - start)) -gt "$limit" ]; then echo "timeout" > "$WORK/last-status"; return 1; fi
		sleep 2
	done
}
history_field() { wpe "\$h = shcm_bootstrap()->backups()->history()->get('$1'); echo \$h ? json_encode(\$h) : '{}';" | jq -r "$2"; }

echo "=============================================================="
echo " Scheduled backups + Google Drive end-to-end test"
echo " $(date -u '+%Y-%m-%d %H:%M:%S UTC')   site: $HOSTNAME_PORT"
echo "=============================================================="

echo
echo "--- 0. Setup ---------------------------------------------------"
rsync -a --delete --exclude vendor --exclude .phpunit.cache "$PLUGIN/" "$ROOT/wp-content/plugins/sh-clone-migration/"
mkdir -p "$ROOT/wp-content/mu-plugins"
cp "$PLUGIN/tests/wordpress/backup-test-mu.php" "$ROOT/wp-content/mu-plugins/shcm-backup-test.php"
chown -R www-data:www-data "$ROOT/wp-content/plugins/sh-clone-migration" "$ROOT/wp-content/mu-plugins" 2>/dev/null
rm -f "$ROOT/wp-content/shcm-test-mail.log"
# Background requests run as the web server user, which may not be allowed to
# create files in wp-content.
touch "$ROOT/wp-content/shcm-test-mail.log" && chmod 666 "$ROOT/wp-content/shcm-test-mail.log"
rm -rf "$ROOT/wp-content/shcm-storage/config"
rm -f "$ROOT"/wp-content/shcm-storage/archives/*.wpress "$ROOT"/wp-content/shcm-storage/archives/*.wpress.sha256
for c in AUTH:o/oauth2/v2/auth TOKEN:token REVOKE:revoke API:drive/v3 UPLOAD:upload/drive/v3; do
	wpc config set "SHCM_GDRIVE_${c%%:*}_URL" "$FAKE/${c#*:}" --type=constant >/dev/null
done
wpc config delete SHCM_ENABLE_BACKUPS >/dev/null 2>&1
wpc config delete SHCM_DISABLE_BACKUPS >/dev/null 2>&1
wpc option delete shcm_test_backoff shcm_test_chunk shcm_test_no_loopback >/dev/null
fuser -k "$FAKE_PORT/tcp" >/dev/null 2>&1
FAKE_GOOGLE_DIR=$WORK/fake PHP_CLI_SERVER_WORKERS=4 nohup php -S "127.0.0.1:$FAKE_PORT" "$PLUGIN/tests/fake-google/router.php" > "$WORK/fake.log" 2>&1 &
FAKE_PID=$!
trap 'kill $FAKE_PID 2>/dev/null; for c in AUTH TOKEN REVOKE API UPLOAD; do (cd "$ROOT" && wp --allow-root config delete SHCM_GDRIVE_${c}_URL >/dev/null 2>&1); done; rm -f "$ROOT/wp-content/mu-plugins/shcm-backup-test.php"' EXIT
for i in $(seq 1 30); do curl -s "$FAKE/__health" | grep -q ok && break; sleep 0.2; done
control '{"reset":true}'
avail=$(wpe 'var_dump( \SHCM\Core\Plugin::backupsAvailable() );')
is "scheduled backups are on by default" "$avail" "bool(true)"

curl -s -o /dev/null -c "$JAR" -b "$JAR" -H "$H" "$BASE/wp-login.php"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -H "$H" --data "log=$USER_NAME&pwd=$USER_PASS&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
PAGE=$(curl -s -b "$JAR" -H "$H" "$BASE/wp-admin/admin.php?page=shcm-schedules")
NONCE=$(echo "$PAGE" | grep -o 'var shcmData = {[^;]*' | grep -o '"nonce":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
[ -n "$NONCE" ] && check "Scheduled Backups screen loads (nonce found)" 0 || check "Scheduled Backups screen loads" 1 "$(echo "$PAGE" | grep -o 'critical error' | head -1)"

echo
echo "--- 1. Connect Google Drive (OAuth redirect flow) ---------------"
r=$(ajax gdrive_credentials client_id=test-client.apps.googleusercontent.com client_secret=GOCSPX-fake-secret-0123456789)
is "credentials saved" "$(echo "$r" | jq -r '.data.state')" "not_connected"
url=$(ajax gdrive_connect | jq -r '.data.url')
echo "$url" | grep -q "access_type=offline" && echo "$url" | grep -q "prompt=consent" && echo "$url" | grep -q "drive.file"
check "consent URL asks for offline access, consent and drive.file only" $?
callback=$(curl -s -o /dev/null -w '%{redirect_url}' "$url")
echo "$callback" | grep -q "/wp-admin/admin-post.php?.*code=" ; check "fake Google redirects back to admin-post.php with a code" $?
landing=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -c "$JAR" -H "$H" "${callback/http:\/\/$HOSTNAME_PORT/$BASE}")
echo "$landing" | grep -q "page=shcm-schedules"; check "callback redirects to the Scheduled Backups screen" $?
is "connection state" "$(wpc shcm gdrive status | grep '^State' | awk '{print $2}')" "connected"
replay=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -H "$H" "${callback/http:\/\/$HOSTNAME_PORT/$BASE}")
is "a replayed callback does not break the connection" "$(wpc shcm gdrive status | grep '^State' | awk '{print $2}')" "connected"
forged=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H "$H" "$BASE/wp-admin/admin-post.php?state=shcm_$(printf 'a%.0s' $(seq 1 48))&code=4/0forgedcodeXXXXXXXX")
is "a forged state is refused (redirect with message)" "$forged" "302"

echo
echo "--- 2. Schedule ------------------------------------------------"
r=$(ajax save_schedule 'schedule={"frequency":"daily","time":"03:15","contents":"full","include_core":false,"exclusions":"","keep_local":1,"gdrive":true,"keep_remote":2,"encrypt":false,"notify_on":"always","notify_email":"ops@example.test"}')
is "schedule saved" "$(echo "$r" | jq -r '.success')" "true"
is "description" "$(echo "$r" | jq -r '.data.schedule.describe')" "Daily at 03:15"
next=$(wpe 'echo (int) wp_next_scheduled("shcm_scheduled_backup");')
[ "$next" -gt "$(date +%s)" ] 2>/dev/null; check "WP-Cron event armed for the next run" $?
bad=$(ajax save_schedule 'schedule={"keep_remote":-1}' | jq -r '.success')
is "keep_remote=-1 is rejected, not clamped" "$bad" "false"

echo
echo "--- 3. Back up now (browser starts it, the site finishes it) -----"
wpc option update shcm_test_chunk 4194304 >/dev/null
r=$(ajax start_backup gdrive=1)
JOB1=$(echo "$r" | jq -r '.data.id')
is "backup job started in the background" "$(echo "$r" | jq -r '.data.background')" "true"
wait_job "$JOB1" status 600
is "backup completed without the browser driving it" "$(cat "$WORK/last-status")" "completed"
is "history: success" "$(history_field "$JOB1" .status)" "success"
is "history: uploaded to Drive" "$(history_field "$JOB1" .remote.status)" "uploaded"
is "verified by SHA-256" "$(history_field "$JOB1" .remote.verified)" "sha256"
ARCH1=$(history_field "$JOB1" .archive)
FID1=$(history_field "$JOB1" .remote.file_id)
local_sha=$(cut -d' ' -f1 "$ROOT/wp-content/shcm-storage/archives/$ARCH1.sha256" 2>/dev/null)
remote_sha=$(curl -s "$FAKE/__blob/$FID1" | sha256sum | cut -d' ' -f1)
is "bytes on Drive are the archive (sha256)" "$remote_sha" "$local_sha"
chunks=$(fstate | jq '[.log[] | select(.method=="PUT" and (.path|startswith("/upload/")))] | length')
[ "$chunks" -gt 3 ]; check "uploaded in several chunks ($chunks PUTs)" $?
authput=$(fstate | jq '[.log[] | select(.method=="PUT" and (.path|startswith("/upload/")) and .authorization==true)] | length')
is "chunk PUTs carry no Authorization header" "$authput" "0"
folders=$(fstate | jq '[.files[] | select(.mimeType=="application/vnd.google-apps.folder")] | length')
is "one backup folder" "$folders" "1"
kind=$(fstate | jq -r --arg id "$FID1" '.files[] | select(.id==$id) | .appProperties.shcm_kind')
is "Drive file tagged as a backup of this site" "$kind" "backup"
mails=$(grep -c 'Backup completed' "$ROOT/wp-content/shcm-test-mail.log" 2>/dev/null)
is "success e-mail sent (notify: always)" "$mails" "1"

echo
echo "--- 4. Scheduled runs through WP-Cron over HTTP -----------------"
wpc option update shcm_test_chunk 262144 >/dev/null
run_scheduled() {
	wpe '$m = shcm_bootstrap()->backups(); $s = (new \SHCM\Backup\ConfigStore(shcm_bootstrap()->storage()->config()))->update("schedule", function($d){ $d["state"]["next_run"] = time() - 5; return $d; }); wp_unschedule_hook("shcm_scheduled_backup"); wp_schedule_single_event(time() - 5, "shcm_scheduled_backup"); delete_transient("doing_cron");' >/dev/null
	# Without doing_wp_cron: wp-cron.php only runs events for a caller whose
	# value matches the lock it set itself.
	curl -s -o /dev/null -H "$H" "$BASE/wp-cron.php" --max-time 120
	local job
	job=$(wpe 'echo shcm_bootstrap()->backups()->state()["last_job"];')
	wait_job "$job" status 600 >/dev/null
	echo "$job"
}
wpc option update shcm_test_backoff "1,1,1,1,1,1" >/dev/null
r=$(ajax save_schedule 'schedule={"contents":"database"}')
JOB2=$(run_scheduled)
is "scheduled run 1 (database only): success" "$(history_field "$JOB2" .status)" "success"
is "scheduled run 1 started by the schedule" "$(history_field "$JOB2" .trigger)" "schedule"
JOB3=$(run_scheduled)
is "scheduled run 2: success" "$(history_field "$JOB3" .status)" "success"
drive_backups=$(fstate | jq '[.files[] | select(.appProperties.shcm_kind=="backup" and (.trashed|not))] | length')
is "retention keeps 2 backups on Drive" "$drive_backups" "2"
is "the oldest Drive copy is marked deleted" "$(history_field "$JOB1" .remote.status)" "deleted"
local_backups=$(ls "$ROOT"/wp-content/shcm-storage/archives/*-backup-*.wpress 2>/dev/null | wc -l)
is "retention keeps 1 backup on this server" "$local_backups" "1"
next2=$(wpe 'echo (int) shcm_bootstrap()->backups()->state()["next_run"];')
[ "$next2" -gt "$(date +%s)" ]; check "next run moved into the future" $?

echo
echo "--- 5. Faults: server errors, a lost session, a revoked grant ----"
control '{"fail":[{"method":"PUT","path":"/upload/drive/v3/files","status":503,"times":2}]}'
JOB4=$(run_scheduled)
is "two 503s on chunks: retried, success" "$(history_field "$JOB4" .status)" "success"
retried=$(grep -c "Retry" "$ROOT/wp-content/shcm-storage/logs/$JOB4.log")
[ "$retried" -ge 1 ]; check "retries logged ($retried)" $?
control '{"fail":[{"method":"PUT","path":"/upload/drive/v3/files","status":404,"times":1}]}'
JOB5=$(run_scheduled)
is "upload session lost (404): restarted, success" "$(history_field "$JOB5" .status)" "success"
grep -q "started again" "$ROOT/wp-content/shcm-storage/logs/$JOB5.log"; check "restart logged" $?
control '{"revoke_all":true}'
JOB6=$(run_scheduled)
is "revoked grant: backup kept locally (partial)" "$(history_field "$JOB6" .status)" "partial"
is "revoked grant: connection needs reconnecting" "$(wpc shcm gdrive status | grep '^State' | cut -d: -f2 | xargs)" "needs to be reconnected"
grep -q "not uploaded to Google Drive" "$ROOT/wp-content/shcm-test-mail.log"; check "failure e-mail sent" $?
url=$(ajax gdrive_connect | jq -r '.data.url')
callback=$(curl -s -o /dev/null -w '%{redirect_url}' "$url")
curl -s -o /dev/null -b "$JAR" -H "$H" "${callback/http:\/\/$HOSTNAME_PORT/$BASE}"
is "reconnected" "$(wpc shcm gdrive status | grep '^State' | awk '{print $2}')" "connected"
retry=$(ajax backup_upload "history_id=$JOB6" | jq -r '.data.id')
wait_job "$retry" status 300 >/dev/null
is "retry upload of the partial backup" "$(history_field "$JOB6" .remote.status)" "uploaded"

echo
echo "--- 6. WP-CLI: encrypted database-only and files-only backups ----"
r=$(ajax save_schedule 'schedule={"encrypt":true}' set_password=1 password=Correct-Horse-9)
is "encryption saved with a password" "$(echo "$r" | jq -r '.data.schedule.has_password')" "true"
out=$(cd "$ROOT" && wp --allow-root shcm backup now --contents=database --porcelain 2>/dev/null | grep wpress | tail -1)
enc=$(wpe "echo json_encode((new \SHCM\Archive\Catalog(shcm_bootstrap()->storage()))->describe('$out'));" | jq -r '.encrypted')
is "encrypted backup" "$enc" "true"
files=$(wpe "echo json_encode(\SHCM\Archive\Reader::readFooter('$out'));" | jq -r '.files')
is "database-only backup holds no files" "$files" "0"
(cd "$ROOT" && wp --allow-root shcm verify "$(basename "$out")" --password=Correct-Horse-9 2>/dev/null | grep -q Success); check "encrypted backup verifies with the password" $?
ajax save_schedule 'schedule={"encrypt":false}' >/dev/null
out=$(cd "$ROOT" && wp --allow-root shcm backup now --contents=files --no-upload --porcelain 2>/dev/null | grep wpress | tail -1)
db=$(wpe "echo json_encode(\SHCM\Archive\Reader::readFooter('$out'));" | jq -r '.database.included')
is "files-only backup has no database" "$db" "false"

echo
echo "--- 7. Cancel, and the browser fallback without loopbacks --------"
t0=$(date +%s)
r=$(ajax start_backup gdrive=0 contents=full)
JOB7=$(echo "$r" | jq -r '.data.id')
took=$(( $(date +%s) - t0 ))
[ "$took" -le 8 ]; check "\"Back up now\" answers at once (${took}s), the rest runs in the background" $?
ajax cancel "job_id=$JOB7" >/dev/null
wait_job "$JOB7" status 120 >/dev/null
is "cancelled backup ends cancelled" "$(cat "$WORK/last-status")" "cancelled"
is "history: cancelled" "$(history_field "$JOB7" .status)" "cancelled"
[ -z "$(history_field "$JOB7" '.archive // empty')" ] && ! ls "$ROOT"/wp-content/shcm-storage/archives/*.part >/dev/null 2>&1
check "the half-written archive is not kept" $?
# Cancel while the upload waits for a retry (deterministic: the upload stalls).
wpc option update shcm_test_backoff "20,20,20,20,20,20" >/dev/null
control '{"fail":[{"method":"PUT","path":"/upload/drive/v3/files","status":503,"times":50}]}'
r=$(ajax start_backup gdrive=1 contents=database)
JOBC=$(echo "$r" | jq -r '.data.id')
for i in $(seq 1 60); do
	ajax status "job_id=$JOBC" | jq -r '.data.message // ""' | grep -qi 'retry' && break
	sleep 1
done
ajax cancel "job_id=$JOBC" >/dev/null
wait_job "$JOBC" status 60 >/dev/null
is "cancelled while waiting to retry the upload" "$(cat "$WORK/last-status")" "cancelled"
ARCHC=$(history_field "$JOBC" .archive)
[ -n "$ARCHC" ] && [ -f "$ROOT/wp-content/shcm-storage/archives/$ARCHC" ]; check "its finished archive is kept and listed" $?
is "and offered for a retried upload" "$(history_field "$JOBC" .remote.status)" "failed"
control '{"clear_faults":true}'
wpc option update shcm_test_backoff "1,1,1,1,1,1" >/dev/null
wpc option update shcm_test_no_loopback 1 >/dev/null
r=$(ajax start_backup gdrive=0)
JOB8=$(echo "$r" | jq -r '.data.id')
wait_job "$JOB8" tick 600 >/dev/null
is "without loopbacks, the open page drives the backup to the end" "$(cat "$WORK/last-status")" "completed"
wpc option delete shcm_test_no_loopback >/dev/null

echo
echo "--- 8. A copy of the site does not take over the schedule --------"
wpe '(new \SHCM\Backup\ConfigStore(shcm_bootstrap()->storage()->config()))->update("schedule", function($d){ $d["state"]["fingerprint"] = str_repeat("0", 64); return $d; }); shcm_bootstrap()->backups()->reconcile();' >/dev/null
is "schedule paused on a copy" "$(wpe 'var_dump( (bool) wp_next_scheduled("shcm_scheduled_backup") );')" "bool(false)"
is "screen reports the identity problem" "$(ajax backup_status | jq -r '.data.schedule.identity_ok')" "false"
ajax backup_adopt >/dev/null
is "\"this is the same site\" resumes it" "$(wpe 'var_dump( (bool) wp_next_scheduled("shcm_scheduled_backup") );')" "bool(true)"

echo
echo "--- 8b. Settings written by WP-CLI as another system user ---------"
CFG="$ROOT/wp-content/shcm-storage/config"
is "schedule written by WP-CLI (root) is readable by the web server" "$(stat -c %a "$CFG/schedule.php")" "644"
chmod 600 "$CFG/schedule.php"; chown root:root "$CFG/schedule.php"
problems=$(ajax backup_status | jq -r '.data.schedule.problems | length')
is "an unreadable schedule is reported on the screen" "$problems" "1"
curl -s -o /dev/null -b "$JAR" -H "$H" "$BASE/wp-admin/admin.php?page=shcm-schedules"
is "and the scheduled event is left alone" "$(wpe 'var_dump( (bool) wp_next_scheduled("shcm_scheduled_backup") );')" "bool(true)"
refused=$(ajax save_schedule 'schedule={"time":"04:00"}' | jq -r '.success')
is "saving is refused instead of replacing the unreadable settings" "$refused" "false"
chmod 644 "$CFG/schedule.php"
is "the stored schedule survived" "$(wpc shcm backup schedule | grep -c 'Daily at 03:15')" "1"

echo
echo "--- 9. Manual archives: sent by hand, never pruned; keep 0 locally --"
ajax save_schedule 'schedule={"keep_local":1,"keep_remote":1}' >/dev/null
JOB9=$(run_scheduled)
is "scheduled run with Drive: success" "$(history_field "$JOB9" .status)" "success"
ARCH9=$(history_field "$JOB9" .archive)
row=$(curl -s -b "$JAR" -H "$H" "$BASE/wp-admin/admin.php?page=shcm-backups" | tr -d '\n' | grep -o "<tr data-archive=\"$ARCH9\">.*" | sed 's#</tr>.*##')
echo "$row" | grep -q 'on Google Drive'; check "Backups screen marks the backup as on Google Drive" $?
echo "$row" | grep -q 'data-action="gdrive"'; check "Backups screen offers \"Send to Google Drive\"" $?
MAN=$(cd "$ROOT" && wp --allow-root shcm export --porcelain 2>/dev/null | grep wpress | tail -1)
MANB=$(basename "$MAN")
r=$(ajax backup_upload "archive=$MANB")
UJ=$(echo "$r" | jq -r '.data.id')
wait_job "$UJ" status 300 >/dev/null
is "manual export uploaded by hand" "$(cat "$WORK/last-status")" "completed"
mkind=$(fstate | jq -r --arg n "$MANB" '[.files[] | select(.name==$n and (.trashed|not))][0].appProperties.shcm_kind')
is "its Drive copy is tagged as a manual archive" "$mkind" "manual"
ajax save_schedule 'schedule={"keep_local":0,"keep_remote":1}' >/dev/null
JOB10=$(run_scheduled)
is "keep 0 on this server: backup succeeds" "$(history_field "$JOB10" .status)" "success"
ARCH10=$(history_field "$JOB10" .archive)
[ -n "$ARCH10" ] && [ ! -f "$ROOT/wp-content/shcm-storage/archives/$ARCH10" ]; check "the verified backup is deleted locally" $?
is "history says it is on Drive only" "$(history_field "$JOB10" .local.kept)" "false"
is "retention keeps 1 backup on Drive" "$(fstate | jq '[.files[] | select(.appProperties.shcm_kind=="backup" and (.trashed|not))] | length')" "1"
is "the manual archive stays on Drive" "$(fstate | jq --arg n "$MANB" '[.files[] | select(.name==$n and (.trashed|not))] | length')" "1"
[ -f "$MAN" ]; check "the manual archive stays on this server" $?
ajax save_schedule 'schedule={"keep_local":1,"keep_remote":2}' >/dev/null

echo
echo "--- 10. No secret in logs, job files or plain config -------------"
leaks=$(grep -rlE 'ya29\.fake|1//fake|GOCSPX-fake|upload_id=[A-Za-z0-9]' "$ROOT/wp-content/shcm-storage/logs" "$ROOT/wp-content/shcm-storage/jobs" "$ROOT/wp-content/shcm-storage/config" 2>/dev/null | wc -l)
is "files containing a token, secret or session URI" "$leaks" "0"
dbleak=$(wpe 'global $wpdb; $q = chr( 39 ); echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE {$q}%fake-secret%{$q} OR option_value LIKE {$q}%1//fake%{$q} OR option_value LIKE {$q}%ya29.fake%{$q}" );')
is "database rows containing Drive secrets" "$dbleak" "0"

echo
echo "--- 11. SHCM_DISABLE_BACKUPS switches the feature off -------------"
wpc config set SHCM_DISABLE_BACKUPS true --raw --type=constant >/dev/null
sleep 3 # PHP-FPM's opcache looks at wp-config.php again only every few seconds.
menu=$(curl -s -b "$JAR" -H "$H" "$BASE/wp-admin/admin.php?page=shcm" | grep -c "href=.admin.php?page=shcm-schedules.")
is "no Scheduled Backups menu entry" "$menu" "0"
is "backup AJAX actions refuse" "$(ajax backup_status | jq -r '.success')" "false"
(cd "$ROOT" && wp --allow-root shcm backup schedule >/dev/null 2>&1); is "no WP-CLI backup command" "$?" "1"
is "its WP-Cron event is removed" "$(wpe 'do_action( "shcm_worker" ); var_dump( (bool) wp_next_scheduled( "shcm_scheduled_backup" ) );')" "bool(false)"
wpc config delete SHCM_DISABLE_BACKUPS >/dev/null
sleep 3
curl -s -o /dev/null -b "$JAR" -H "$H" "$BASE/wp-admin/admin.php?page=shcm-schedules"
is "back on: the schedule is armed again" "$(wpe 'var_dump( (bool) wp_next_scheduled( "shcm_scheduled_backup" ) );')" "bool(true)"
is "back on: the menu entry returns" "$(curl -s -b "$JAR" -H "$H" "$BASE/wp-admin/admin.php?page=shcm" | grep -c "href=.admin.php?page=shcm-schedules." | awk '{print ($1 > 0)}')" "1"

echo
echo "  checks passed: $PASS, failed: $FAIL"
[ "$FAIL" -eq 0 ]
