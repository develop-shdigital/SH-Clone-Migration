# Fake Google OAuth 2.0 + Drive v3 server

`router.php` is a PHP built-in-server router that stands in for `accounts.google.com`, `oauth2.googleapis.com` and
`www.googleapis.com` in end-to-end tests of the Google Drive backup storage. It copies the real protocol as closely as
the research notes allow: status codes, headers, error bodies, the plain-text 404 for dead upload sessions, the 308
`Range` rules and Drive v3's default field selection. The plugin's client is written against Google, so where
behaviour differs the fake follows Google, not convenience. The choices that go beyond, or differ from, what was
verified are listed under [Emulation notes](#emulation-notes).

It needs only the PHP CLI (7.4+). It uses no Composer packages or WordPress, and `scripts/build.sh` never ships it
because it excludes `tests/`.

## Run it

```sh
FAKE_GOOGLE_DIR=/tmp/fake-google php -S 127.0.0.1:8091 tests/fake-google/router.php
# optional: PHP_CLI_SERVER_WORKERS=4 (state is flock()ed, parallel workers are safe)

tests/fake-google/selftest.sh          # starts its own server on 8091 (or the next free port), 110 checks
tests/fake-google/selftest.sh 9000     # first port to try
```

Point the plugin at it with the endpoint override constants, where `BASE` is `http://127.0.0.1:8091`:

| Constant | Value |
|---|---|
| `SHCM_GDRIVE_AUTH_URL` | `BASE/o/oauth2/v2/auth` |
| `SHCM_GDRIVE_TOKEN_URL` | `BASE/token` |
| `SHCM_GDRIVE_REVOKE_URL` | `BASE/revoke` |
| `SHCM_GDRIVE_API_URL` | `BASE/drive/v3` |
| `SHCM_GDRIVE_UPLOAD_URL` | `BASE/upload/drive/v3` |

Default credentials are client ID `test-client.apps.googleusercontent.com` and client secret
`GOCSPX-fake-secret-0123456789`. Consent is automatic: the authorization URL answers 302 straight back to the
`redirect_uri`, so a test can follow it with curl or a headless browser.

### Configuration (environment, read when the state is created or reset)

| Variable | Default |
|---|---|
| `FAKE_GOOGLE_DIR` | `sys_get_temp_dir()/shcm-fake-google` (state dir: `state.json`, `state.lock`, `blobs/`, `uploads/`, `spool/`) |
| `FAKE_GOOGLE_CLIENT_ID` / `FAKE_GOOGLE_CLIENT_SECRET` | see above |
| `FAKE_GOOGLE_ACCESS_TOKEN_TTL` | `3600` seconds (also the `expires_in` value) |
| `FAKE_GOOGLE_QUOTA_LIMIT` | `16106127360` (`unlimited` = no limit, so `about` omits `limit` like Google does) |
| `FAKE_GOOGLE_QUOTA_USAGE` | `0` (base usage; stored files are added to it) |
| `FAKE_GOOGLE_EMAIL` / `FAKE_GOOGLE_NAME` | `owner@example.test` / `Test Owner` |
| `FAKE_GOOGLE_REDIRECT_URIS` | empty = accept any http(s) URI; otherwise a space/comma list that must match exactly |
| `FAKE_GOOGLE_PUBLISHING_STATUS` | `production`; `testing` makes refresh tokens die 7 days after consent |
| `FAKE_GOOGLE_MAX_UPLOAD_SIZE` | `5497558138880` (reported as `about.maxUploadSize`) |
| `FAKE_GOOGLE_BASE_URL` | `http://<Host header>`; the base used in upload `Location` headers |

The state survives server restarts. Start each test with `{"reset":true}`.

## Endpoints

### OAuth

**`GET /o/oauth2/v2/auth`**
- Validates `client_id`, `redirect_uri` (absolute http(s), no fragment or userinfo, exact match when a list is
  configured), `response_type=code`, `scope` (must contain `…/auth/drive.file`; unknown scopes fail), `access_type`
  and `prompt`.
- Error pages are 400 HTML, as Google shows them instead of redirecting. An unknown client shows "The OAuth client
  was not found. Error 401: invalid_client".
- On success it answers 302 to `redirect_uri?state=…&iss=https://accounts.google.com&code=4/0fake-…&scope=…&authuser=0&prompt=…`.
- `deny_next_consent` gives `error=access_denied&state=…`.
- `prompt=none` without an existing grant gives `error=consent_required`.

**`POST /token`** (form-encoded; `client_secret_post` or HTTP Basic)
- Errors are JSON `{"error","error_description"}`:
  - missing or unknown `grant_type`: 400 `unsupported_grant_type`
  - missing `client_id`: 400 `invalid_request`
  - unknown client: 401 `invalid_client` "The OAuth client was not found."
  - wrong secret: 401 `invalid_client` "Unauthorized"
- `authorization_code`:
  - A code is single-use and lives 10 minutes. An unknown, used or expired code gets 400 `invalid_grant`.
  - A `redirect_uri` different from the one used at `/auth` gets 400 `redirect_uri_mismatch` "Bad Request".
  - The response is `access_token` (`ya29.fake-…`), `expires_in`, `refresh_token` (`1//fake-…`), `scope` and
    `token_type: Bearer`.
  - `refresh_token` is included only when all of these hold (as Google does):
    - the request had `access_type=offline`;
    - the consent screen was "shown", meaning `prompt=consent` or this client had no active grant yet;
    - `omit_refresh_token` is off.
  - Each client keeps at most 100 live refresh tokens; the oldest is dropped silently.
- `refresh_token`:
  - Returns a new access token with no `refresh_token`, unless `rotate_refresh_tokens` is on.
  - A token that is revoked, unknown, unused for 6 months, or older than 7 days in `testing` mode gets 400
    `{"error":"invalid_grant","error_description":"Token has been expired or revoked."}`.

**`POST /revoke`** (also GET; `token=` in the query or the form)
- Returns 200 `{}` and revokes the client's whole grant, meaning every refresh and access token of that client.
  Google revokes per project, so another site using the same client is disconnected too.
- An unknown, revoked or expired token gets 400 `{"error":"invalid_token","error_description":"Token expired or revoked"}`.

### Drive v3

Every `/drive/v3/*` call and the upload initiation need `Authorization: Bearer <access token>`. The token must be
unexpired and unrevoked, belong to the configured client, and carry `drive.file`.

| Failure | Response |
|---|---|
| No header | 401, `errors[0].reason` `required`, `status` `UNAUTHENTICATED`, `details[0].reason` `CREDENTIALS_MISSING`, `WWW-Authenticate` |
| Bad or expired token | 401, reason `authError` ("Invalid Credentials") |
| Scope missing | 403, reason `insufficientPermissions`, status `PERMISSION_DENIED` |

**`GET /drive/v3/about`**
- `fields` is required; without it you get 400, reason `required`.
- Returns `user{displayName,emailAddress,…}`, `storageQuota{limit,usage,usageInDrive,usageInDriveTrash}` (strings;
  usage is the base usage plus every stored file, and trash counts) and `maxUploadSize`.

**`GET /drive/v3/files`**
- `q` supports:
  - `'ID' in parents` (`'root'` works too)
  - `trashed = true|false`, `starred = …`
  - `name` and `mimeType` with `=`, `!=` and `contains` (`name contains` is a prefix match on the name or one of its words, as documented)
  - `createdTime` and `modifiedTime` with `= != < <= > >=`
  - `appProperties has { key='K' and value='V' }`, and the same for `properties`
  - `'me' in owners|writers|readers`
  - `and`, `or`, `not` and parentheses, with `\'` and `\\` escapes in strings
- Anything else (for example `fullText`) or a syntax error gives 400 "Invalid Value" at `location: q`.
- Trashed files are returned unless the query excludes them, as the docs warn.
- `orderBy` takes Google's keys with an optional ` desc`. The default is `folder,modifiedTime desc,name`.
- `pageSize` defaults to 100, maximum 1000; values below 1 give 400. `pageToken`/`nextPageToken` are opaque, and a
  foreign or garbled token gives 400.
- Pages are full by default. Google documents that "partial or empty result pages are possible even before the end
  of the files list has been reached", so the `short_pages` control serves short or empty pages that still carry
  `nextPageToken`. A client must page until the token is absent, not until a page is shorter than `pageSize`.
- Only files created with the current client ID are visible (`drive.file`), so changing `client_id` hides earlier
  backups, just as a recreated Cloud project would.

**`fields`** (every Drive endpoint)
- Implemented, not faked: `a,b`, `a/b`, `a(b,c)` and `*`.
- An unknown field name gives 400 "Invalid field selection x", reason `invalidParameter`.
- Without `fields`, a File is only `kind,id,name,mimeType` and a list is `kind,incompleteSearch,files(those),nextPageToken`,
  exactly Drive v3's defaults. A client that forgets `fields` fails here as it would against Google, and so does one
  that asks for `files(...)` without `nextPageToken` (it only ever sees page 1).

**`POST /drive/v3/files`**
- Accepts JSON metadata; `Content-Type` must be JSON (see notes).
- Covers folders (`application/vnd.google-apps.folder`) and empty files, with `name`, `parents` (one only; two give
  403 `cannotAddParent`; an unknown parent gives 404), `appProperties`/`properties` (null values are skipped; key +
  value at most 124 bytes; at most 30), `description` and `createdTime`/`modifiedTime`.
- An optional client-supplied `id` gives 409 `duplicate` when it already exists.
- Read-only fields (size, checksums, …) give 403 `fieldNotWritable`.

**`GET /drive/v3/files/generateIds`**: `count` 1–1000.

**`GET /drive/v3/files/{id}`**
- Returns 404 `{"error":{"code":404,"message":"File not found: ID.","errors":[{…"reason":"notFound","location":"fileId"…}]}}`.
- `root` is the My Drive folder.

**`PATCH /drive/v3/files/{id}`**
- Writable: `name`, `description`, `starred`, `trashed`, `mimeType` (never to or from a folder), `originalFilename`,
  `modifiedTime`, and `appProperties`/`properties`. The two property maps are merged, and `null` deletes a key.
- A `parents` field in the body gives 403 `fieldNotWritable`; moves use `addParents`/`removeParents` (single parent,
  no moving into your own subtree).
- Trashing a folder makes its children `trashed: true` (`explicitlyTrashed: false`).

**`DELETE /drive/v3/files/{id}`**: 204 with no body. It is permanent and recursive for folders; the file is then 404.

**`GET /drive/v3/files/{id}?alt=media`**
- Answers 200 with the full content.
- A `Range: bytes=S-E`, `bytes=S-` or `bytes=-N` header gives 206 with `Content-Range: bytes S-E/T`.
- An unsatisfiable range gives 416 with `Content-Range: bytes */T`. Invalid or multi-range headers are ignored (200).
- Folders give 403 `fileNotDownloadable`.

### Resumable upload

**`POST /upload/drive/v3/files?uploadType=resumable`**
- Takes JSON metadata, as for create. `X-Upload-Content-Length` is optional; the size may stay unknown.
  `X-Upload-Content-Type` becomes `mimeType` when the metadata has none.
- Metadata errors (parent, id, properties) come back now.
- If `usage >= limit`, or `usage + X-Upload-Content-Length > limit`, it answers 403 `storageQuotaExceeded`.
- Without a `Content-Length` header (and without `Transfer-Encoding: chunked`) it answers Google's HTML
  `411 Length Required` page, as for the session PUT below.
- On success: `200`, empty body,
  `Location: BASE/upload/drive/v3/files?<original query, e.g. uploadType=resumable&fields=…>&upload_id=AFake…`,
  plus `X-GUploader-UploadID`. Other `uploadType` values give 400 (not emulated).

**`PUT <session URI>`**
- Needs no `Authorization`; the session URI is the credential. An Authorization header is ignored, but the request
  log records whether one was sent (`authorization`), so a test can assert the client leaves it out.
- **Needs `Content-Length`** (or `Transfer-Encoding: chunked`), also on an empty status query: without it the answer
  is Google's HTML `411 Length Required` page ("PUT requests require a `Content-length` header."), before the session
  is even looked up. The research asks status queries to send `Content-Length: 0`, and WordPress's HTTP API sends no
  length for an empty PUT body unless the caller sets the header (see caveats). `{"require_content_length": false}`
  turns the check off. For PUT this is **unverified** against Google (see notes).
- A chunk is `Content-Range: bytes S-E/T` (or `/*`) with exactly `E-S+1` body bytes:
  - A non-final chunk must be a multiple of 262144 bytes; otherwise 400 text/plain.
  - If `S` is beyond the committed size (a gap), nothing is stored and the answer is 308 with the committed range.
  - If `S` is before it, the bytes already committed are ignored and only the new tail is appended (Google/GCS
    semantics).
  - When the committed size reaches `T` the file is created. The answer is `200` with the File resource, using the
    `fields` from the session URI: `id`, `name`, `parents`, `appProperties`, `size` as a string, `md5Checksum`,
    `sha1Checksum`, `sha256Checksum` computed from the bytes, `createdTime` in RFC 3339 with milliseconds,
    `webViewLink`, and so on.
  - Otherwise the answer is `308 Resume Incomplete` with `Range: bytes=0-<committed-1>`, and no `Range` header when
    nothing is committed.
- A status query is `Content-Range: bytes */T` with an empty body. It answers 200 with the file when complete (also
  on any later PUT, so a lost final answer can be recovered), otherwise 308 as above.
- `T` must not change once known. A PUT without `Content-Range` uploads the whole file in one go. A malformed header
  gives 400 `Failed to parse Content-Range header.`
- An unknown or expired session (7 days after creation, or `expire_sessions`) gets **404, `Content-Type: text/plain`,
  body `Not Found`**.
- Quota is checked again at finalization (403 `storageQuotaExceeded`), and a duplicate `id` there gives 409. After
  such a failure the session is gone.

**`DELETE <session URI>`**: cancels the upload with `499 Client Closed Request`.

## Control API (test only)

`POST /__control` with a JSON object. Every key is validated before any is applied, so an unknown key or invalid
value gives 400 `{"ok":false,"error":…}` and changes nothing: no state, no fake clock, no stored or uploaded bytes.
Valid requests are then applied in key order, with `reset` always first; files (`reset`, `expire_sessions`) are deleted
only after every state change is in place. Success is `{"ok":true,"applied":[…]}`.

| Key | Effect |
|---|---|
| `"reset": true` | Wipe everything (state, blobs, log); config comes back from the environment. |
| `"fail": [ {…}, … ]` (or one object) | Queue faults (below). |
| `"clear_faults": true` | Empty the fault queue. |
| `"expire_access_tokens": true` | Every existing access token is expired now. |
| `"revoke_all": true` | Revoke every grant, refresh token and access token. |
| `"expire_sessions": true` | Every open upload session is expired (404 from now on); its bytes are dropped. |
| `"omit_refresh_token": true\|false` | Persistent: code exchanges return no `refresh_token`. |
| `"deny_next_consent": true` | One-shot: the next `/auth` redirects with `error=access_denied`. |
| `"deny_drive_scope_next_consent": true` | One-shot: granular consent without Drive. The next code's `scope` lacks `drive.file` and its tokens get 403 `insufficientPermissions`. |
| `"rotate_refresh_tokens": true\|false` | Persistent: refresh answers include a new `refresh_token` and revoke the old one. |
| `"quota": {"limit": N\|null, "usage": N}` | Quota limit (`null` = unlimited) and base usage. |
| `"access_token_ttl": N` | Lifetime and `expires_in` of new access tokens (0 = born expired). |
| `"chunk_commit_limit": N\|null` | The next chunk PUT commits at most N bytes (partial receipt, so a 308 with a smaller `Range`). One-shot unless `"chunk_commit_times": K` is given (K chunks, `-1` = until reset). `null` clears it. |
| `"short_pages": N\|null` | The next `files.list` page that would hold more than N files holds only N (0 = an empty page), and still carries `nextPageToken` pointing just past what was served, so nothing is skipped. One-shot unless `"short_pages_times": K` is given (K cut pages, `-1` = until reset); pages that are not cut do not count. `null` clears it. With 0 and `-1` a client that follows tokens never finishes, so cap it. |
| `"require_content_length": true\|false` | Persistent, default `true`: a POST or PUT to `/upload/drive/v3/files` without `Content-Length` (and not chunked) gets 411. |
| `"advance_time": seconds` | Move the fake clock forward. It drives token, code, session, 6-month-idle and testing-mode expiry. |
| `"clear_log": true` | Empty the request log. |
| `"client_id"`, `"client_secret"`, `"email"`, `"name"`, `"redirect_uris"`, `"publishing_status"`, `"max_upload_size"` | Set config (also as `"config": {…}`). Changing `client_id` invalidates tokens and hides files, like a new Cloud project. |

**Faults** are matched in queue order, before normal handling, by method (`"*"` default) and path prefix:

```json
{"fail":[{"method":"PUT","path":"/upload/drive/v3/files","status":503,"times":1}]}
```

| Fault key | Meaning |
|---|---|
| `status` | Required, 100–599, or `0` to drop the connection (see notes). |
| `times` | Default 1; `-1` = forever. |
| `body` | A string, or JSON as an object/array. Without it, Drive paths get a Google error JSON (`reason` defaults by status: 429 `rateLimitExceeded`, 5xx `backendError`, …) and `/token` and `/revoke` get `{"error","error_description"}`. |
| `reason`, `message` | Override the generated error. |
| `content_type`, `headers` | Response `Content-Type` and extra headers, e.g. `{"Retry-After":"1"}` or `{"Range":"bytes=0-9"}` with `status` 308. |
| `delay` | Seconds to wait before answering, for client timeouts. |
| `query` | Substring of the decoded query string. |
| `header` | Object of request-header substrings, e.g. `{"content-range":"bytes */"}` to hit only status queries. |
| `process` | `true` handles the request normally first, then replaces its answer. With `status: 0` this means "the chunk was committed, but the client never saw the reply". |

More examples:

```json
{"fail":[{"method":"GET","path":"/drive/v3/files","status":403,"reason":"userRateLimitExceeded","message":"User rate limit exceeded."}]}
{"fail":[{"path":"/token","status":500,"times":2}]}
{"fail":[{"method":"PUT","path":"/upload/","status":0,"process":true}]}
{"chunk_commit_limit":262144}
```

**`GET /__state`** returns JSON with:
- `config`, `controls`, `faults` and `quota`
- `grants`: per client, refresh and access tokens redacted to `***` plus the last 6 characters, with
  expired/revoked flags
- `codes`, redacted
- `files`: full File resources plus `app`, the owning client; no content
- `sessions`: redacted id, name, committed, total, expired, finalized, file_id
- `log`: the last 200 requests as `{time, method, path, query, status, content_range?, content_length?, authorization?, fault?, note?}`

In the log, `query` has `upload_id`, `token`, `code`, … redacted, `status` is `0` for a dropped connection, and
`time` is wall-clock time. The log includes `/__*` calls. Every request to `/upload/drive/v3/files` (initiation,
chunk, status query, cancel) also has `content_length`, the header's value as sent (`"0"` for a proper status query)
or `null` when the header was absent, and `authorization`, `true` when an Authorization header was sent. Together they
let an end-to-end test assert both session-URI rules of the design: status queries send `Content-Length: 0`, chunk
PUTs send no Authorization.

**`GET /__blob/{id}`** returns the raw bytes of a stored file (no auth), for comparing SHA-256.
**`GET /__health`** returns `ok`.

## Emulation notes

These follow the research report (`gdrive-api.md`) and Google's docs:
- the error shapes above
- 404 `text/plain` `Not Found` for dead sessions
- 308 without `Range` meaning "nothing received"
- chunk PUTs without Authorization
- `about` needing `fields`
- quota numbers as strings, with `limit` omitted when unlimited
- refresh tokens only on first consent or `prompt=consent`
- the 100-token cap, the 6-month idle expiry and the 7-day expiry in Testing mode
- revocation of the whole grant
- `drive.file` visibility
- trashed files listed by default
- a single parent
- the 124-byte / 30-key `appProperties` limits
- 409 for a reused ID

These were chosen here because Google does not document them, or the report marks them UNVERIFIED:
- **A non-multiple-of-256 KiB non-final chunk gets 400** (text/plain). Google only says chunks "must" be multiples,
  so the fake is strict to catch client bugs. The real server may instead accept and commit a smaller amount.
- **A chunk starting beyond the committed size** gets 308 with the committed range and stores nothing. **Overlapping
  bytes** are ignored and the tail is appended, per Cloud Storage's documented behaviour; Drive uses the same upload
  front end.
- **`storageQuotaExceeded`** is raised at initiation (declared size) and again at finalization. Google does not say
  which one it uses.
- **Code exchange with a different `redirect_uri`** gets `redirect_uri_mismatch` (400). This is Google's observed
  behaviour; the report does not document it.
- **Status of `invalid_grant`** is 400, per RFC 6749; the report marks it UNVERIFIED.
- **411 Length Required on the upload path.** Google's front end is known to answer a POST without `Content-Length`
  with this HTML page. That it does the same for a **PUT** to a session URI is **UNVERIFIED**; the fake is strict by
  default so a client that relies on WordPress's defaults for an empty status query fails here rather than, possibly,
  against Google. Accepting `Transfer-Encoding: chunked` without `Content-Length` is also unverified.
- **DELETE** gets 204 with no body. The docs say "empty JSON object"; treat any 2xx as success.
- **Metadata must be sent as JSON.** POST/PATCH/initiation bodies without a JSON `Content-Type` are refused (400
  `badContent`). This is stricter than necessary on purpose, because WordPress defaults to
  `application/x-www-form-urlencoded`.
- **Status `0` (drop the connection)**: PHP's built-in server cannot close a socket without answering. The fake sends
  a `503` status line and `Content-Length: 1048576`, then closes after one byte. curl, and so WordPress's cURL
  transport, reports error 18, which is a transport error (`status 0` in the plugin's `HttpResponse`). A client that
  accepts truncated bodies would see a 503.
- **Error JSON is pretty-printed** with 4 spaces rather than Google's 2. Clients must not care.

Not emulated:
- multiple Google accounts (one account per server), `openid`/`id_token`, PKCE, `login_hint` (ignored), and the
  Picker (`trigger_onepick`)
- shared drives (`supportsAllDrives` and friends are ignored), `corpora`/`spaces` filtering, permissions, sharing,
  revisions, export, `files.copy` and `emptyTrash`
- `uploadType=media|multipart` and content updates (`PATCH /upload/drive/v3/files/{id}`)
- the 30-day auto-purge of trash
- rate limits, unless you inject them
- `410 Gone` for cancelled sessions (the fake answers 404 after a cancel)
- `?access_token=` query authentication
- gzip, ETags, `X-Goog-Hash`, `Accept-Ranges`
- time-based access (`refresh_token_expires_in`) and `error_subtype: invalid_rapt`
- `about` import/export formats (returned empty)

## Practical caveats

- WordPress's HTTP API sends no `Content-Length` for a PUT with an empty body unless the caller sets the header. With
  an explicit `Content-Length: 0` the header goes out. Checked against WordPress 7.1 with both Requests transports
  (cURL with curl 8.5.0, and fsockopen): an explicit header gets 308, and no header gets 411. The plugin's
  `Client::queryUpload()` sets it, and its status queries are logged with `content_length: "0"` and
  `authorization: false`.
- `php -S` never answers `Expect: 100-continue`. WordPress's Requests library sends it for bodies over 1 MB, so each
  large chunk PUT waits about 1 s before curl sends the body anyway. That is slow but correct; `selftest.sh` sends
  `Expect:` empty.
- Every request takes one exclusive lock for its whole read-modify-write, so requests are serialized even with
  several workers.
- Chunk bodies are spooled to disk before the lock is taken, and downloads are streamed after it is released, so
  64 MiB chunks work with the default `memory_limit`.
- Everything lives in `FAKE_GOOGLE_DIR`. Use a separate directory per concurrently running server.
