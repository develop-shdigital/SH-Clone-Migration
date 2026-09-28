# Security model

A migration plugin is unusually dangerous: it reads the entire site, it writes
anywhere in `wp-content`, and it executes arbitrary SQL. This document sets out
what it defends against and how.

## Trust boundaries

| Input | Trusted? | Treatment |
|---|---|---|
| The site's own database and files | yes | Read as-is |
| An uploaded or supplied `.wpress` archive | **no** | Validated at every step |
| Admin form and AJAX input | **no** | Capability, nonce, sanitisation |
| Migration passwords | secret | Never persisted (except the password of encrypted scheduled backups, sealed; see below) |
| Google Drive tokens and client secret | secret | Sealed at rest in `shcm-storage/config/`, never in the database |
| `wp-config.php` | untouched | Never written; its secret keys (constants) derive the key that seals stored secrets |

## Authorisation

Every entry point requires the `shcm_manage_migrations` capability. It is
granted to administrators on activation, and the check falls back to
`manage_options` (`manage_network_options` on multisite) so a site whose roles
were edited by a role manager still lets real administrators in. The
capability is filterable via `shcm_required_capability`.

Every AJAX action and every REST route verifies the `shcm_migration` nonce in
addition to the capability. Downloads go through `admin-post.php` with their
own nonce.

### Job tokens

A restore replaces the users table, which invalidates the operator's
authentication cookie mid-run: the account it points at no longer exists.
Without a second mechanism the migration would stall at the worst moment.

When a job is created by a fully authenticated, nonce-checked request, it is
issued a 256-bit random token. **Only its SHA-256 hash is stored** with the
job; the token is returned once and lives in the browser tab. It authorises
exactly three actions on exactly one job: advance it, read its status, cancel
it. It cannot start a job, delete an archive, read settings or reach anything
else. Comparison is constant time.

### The standalone endpoint

`endpoint.php` exists because WordPress maintenance mode blocks
`admin-ajax.php` as well as the front end, and a restore needs both
maintenance mode and a way to keep working. It bootstraps WordPress with
`WP_INSTALLING` defined — the same mechanism core's own installer uses — and
then applies exactly the same authentication, capability and nonce checks as
`admin-ajax.php`, plus a whitelist of four actions (`tick`, `status`,
`cancel`, `resumable`). It is not a bypass; it is the same door in a wall that
WordPress temporarily bricked up.

## Archive storage

Archives are a complete copy of the site, including its users table. Leaking
one is equivalent to leaking the database.

* They live in `wp-content/shcm-storage/`, which is created with `.htaccess`
  (`Require all denied` plus the 2.2 fallback), `web.config` (IIS) and an
  `index.php` in every subdirectory.
* Because nginx and some managed platforms ignore `.htaccess`, the plugin
  **actively tests** whether the directory is reachable: it writes a canary
  file with random content, fetches it over the site's own URL, and warns on
  the System Status screen (with an nginx snippet) when the content comes
  back.
* Every archive name carries 64 bits of randomness, so even on a misconfigured
  server the path cannot be guessed.
* Downloads are streamed through an authenticated, nonce-checked endpoint that
  resolves the requested name against the archive directory and rejects
  anything that is not a plain `.wpress` filename in it. Archive names are
  reduced to `[A-Za-z0-9._-]` when they are created, so every archive the
  plugin writes can be resolved (and nothing else can). An archive without a
  footer (still being written) is refused with 409.
* To keep the exact `Content-Length` of a download on Apache with PHP-FPM and
  behind servers that compress everything, the plugin maintains one marked
  block in the site's `.htaccess`. It only sets the environment variables
  `no-gzip`, `dont-vary` and `ap_trust_cgilike_cl`, only for requests to
  `admin-post.php` whose query string is `action=shcm_download` or
  `action=shcm_download_log`, is written only when the file already contains
  rewrite rules (so it cannot introduce a directive the host forbids), and is
  removed on deactivation and uninstall. `ap_trust_cgilike_cl` tells Apache to
  trust the length the download handler sends; that handler always sends the
  exact length of the bytes it streams. The filter `shcm_manage_htaccess` can
  switch this off.
* The delivery self-test, `admin-post.php?action=shcm_download_probe`, is the
  one download action that needs no session: the site fetches it over a
  loopback request to see what the web server does to a download. It always
  serves the same 128 KB of fixed text from the storage directory and accepts
  no parameters.

## Untrusted archives

An archive may have been produced anywhere, by anyone with import rights.

* **Magic bytes** are checked as soon as the first chunk of an upload lands,
  so a wrong file is rejected before the rest of a multi-gigabyte upload
  arrives, and again before a restore starts.
* **Structure** is validated: prologue, entry headers, footer pointer and end
  marker. An archive without a valid end marker is incomplete and is refused.
* **Checksums** are recomputed for every entry before the restore touches
  anything, and again for each entry as it is written.
* **Block sizes** are bounded, so a hostile header cannot make the reader
  allocate gigabytes, and inflation is capped at the declared raw length,
  which stops decompression bombs.
* **Paths** are rejected if they are absolute, contain `..`, start with a
  Windows drive letter, use a stream wrapper (`phar://`, `http://`), contain a
  null byte or a control character, or resolve outside the destination through
  a symlink: the deepest part of the target path that exists is resolved
  through every link in it, and a dangling link, whose destination cannot be
  proven, counts as outside. Traversal is refused outright rather than
  normalised away. On
  Windows a backslash is converted to a separator *before* these checks; on
  other systems it is an ordinary character in a file name, so `..\..\x` is
  a single, harmless file name there.
* **Protected paths** are never overwritten whatever the archive says:
  `wp-config.php`, `.htaccess`, `.user.ini`, `php.ini`, `web.config`, the
  storage directory and this plugin's own directory. They are matched after
  the entry path has been normalised (`./wp-config.php`, `a/../wp-config.php`)
  and case-insensitively (`WP-CONFIG.PHP` is the same file on Windows and
  macOS).
* **Symlinks** are recreated only when their target, with `.` and `..`
  resolved, stays inside the installation and does not pass through another
  link; anything else is reported and skipped. A link that would replace
  something already on the destination is reported too, and a file entry
  never writes through a link that is already at its path: the link is
  removed first.
* **File modes** are clamped: setuid, setgid and sticky bits are never
  restored, the world-writable bit is stripped, and an unreadable mode is
  corrected.

## What an export reads

The export runs with the web server's permissions and writes an archive only
administrators can download, but it still never wanders outside the site on
its own. A symlinked directory is followed only when it does not contain the
site: a link to `/`, a home directory or a parent of `ABSPATH` is reported and
skipped, and so is a link into a system directory (`/proc`, `/sys`, `/dev`,
`/run`). A link back to a directory the same branch is already inside is kept
as a link, so loops end; at most 10,000 directory links are followed in one
export. A link whose target is already inside the site is stored as a link
rather than copied twice.

## SQL execution

A restore executes SQL from the archive. That SQL is generated by this plugin
and verified by checksum, but the execution path is still conservative:

* statements are sent to the connection directly rather than through
  `wpdb::query()`, so WordPress cannot rewrite the bytes being restored;
* only the table-name position of a data-carrying statement is rewritten by the
  prefix translator, so row data that happens to contain a table name is never
  modified;
* prefixed option names and user-meta keys are fixed from an explicit
  whitelist, never a blanket `LIKE 'wp\_%'`, which would rename unrelated
  options such as `wp_mail_smtp`;
* every query the plugin builds itself is either a prepared statement or an
  identifier passed through backtick escaping.

## Encryption

Optional, and off by default.

* **Cipher**: XChaCha20-Poly1305-IETF via libsodium, falling back to
  AES-256-GCM via OpenSSL. Both are AEAD, so a tampered block fails
  authentication instead of decrypting to garbage.
* **Key derivation**: Argon2id (libsodium, interactive parameters) or
  PBKDF2-HMAC-SHA256 with 210,000 iterations, from a 128-bit random salt
  stored in the prologue.
* **Nonces**: a fresh random nonce per block, stored with the block.
* **Password verification**: the prologue holds an encrypted known plaintext,
  so a wrong password is rejected immediately.
* **The password of a manual export is never written down.** It is not in the
  job state, not in the database, not in the log. It travels with each request
  and lives in the browser tab for the life of the job, so WP-Cron cannot
  resume such a job unattended. Encrypted *scheduled* backups are the one
  exception, by the owner's choice (see "Scheduled backups and Google Drive").
* Keys are wiped with `sodium_memzero()` where available.

Nothing here is home-grown. No custom cipher, no custom construction.

## Scheduled backups and Google Drive

* **Least privilege at Google.** The plugin requests only
  `https://www.googleapis.com/auth/drive.file`: it can read, change and delete
  only the files it created itself, never anything else in the user's Drive.
  Each site uses the owner's own Google Cloud OAuth client; no third-party
  service ever sees a token or a backup.
* **OAuth callback.** The redirect URI is `admin-post.php`. The request must
  carry a `state` that was issued to the same logged-in user less than 15
  minutes earlier (a random 192-bit value, stored hashed, compared in constant
  time, usable once), and the user must have the plugin's capability. A
  replayed callback cannot break a working connection. Disconnecting asks
  Google to revoke the grant and deletes the stored tokens; when Google does
  not confirm (offline, a server error), the screen and WP-CLI say so and
  point to the Google account's permissions page.
* **Secrets at rest.** The client secret, the refresh and access tokens, the
  upload session URI (itself a credential) and the password of encrypted
  backups are sealed with XChaCha20-Poly1305 or AES-256-GCM under keys derived
  by HKDF-SHA256 from the secret keys in `wp-config.php`, one key per field.
  They are kept in `wp-content/shcm-storage/config/`, never in the database,
  so they are never part of an export, an import or a rollback point. When the
  salts are missing from `wp-config.php` the key has to come from salts in the
  database; System Status and the Scheduled Backups screen say so.
  `SHCM_SECRET_KEY` can supply a dedicated key.
* **Stored backup password.** An exception to "migration passwords are never
  stored": unattended encrypted backups need the password. It is stored only
  when the owner switches encryption on, sealed as above, and is deleted when
  encryption is switched off. A running encrypted backup keeps its own sealed
  copy in its job file, which is never sent to the browser.
* **Copies of the site.** A copy made by other means cannot use the original's
  connection or run its schedule until an administrator confirms (see
  ARCHITECTURE.md, "Scheduled backups").
* **Loopback requests.** The background runner's requests to
  `admin-ajax.php` are authenticated by an HMAC of the job id; they can only
  advance that job, which is locked against concurrent ticks.
* **Logs.** Tokens, secrets, authorization codes and session URIs are
  registered with the log redactor the moment they are opened or received, and
  Google-specific token shapes are scrubbed by pattern as well. Error messages
  from Google are filtered before they are stored or shown.

## Logging

Migration logs are downloadable by administrators and routinely pasted into
support tickets, so every line passes through a redactor before it is written:

* literal values of `DB_PASSWORD`, `DB_USER` and all eight authentication keys
  and salts are collected at runtime and replaced wherever they appear;
* `password=`, `secret=`, `token=`, `api_key=`, `bearer` and similar
  key/value shapes are masked;
* `Authorization:` headers, PEM private key blocks and credentials embedded in
  connection URIs are masked.

## Uninstall

`uninstall.php` never deletes website content. It removes scheduled events and
a stale maintenance flag unconditionally, and on every uninstall it asks Google to
revoke the Drive grant (best effort) and deletes the stored tokens, keeping only
the site's random backup identity so that earlier backups on Drive are found
again after a reinstall; backups on Drive are never deleted. Only when the
administrator has explicitly ticked the setting does it remove the plugin's
own options, jobs, logs, archives, schedule and history. It does not touch
posts, users, uploads or any table other than its own options rows.

## Reporting a vulnerability

Open a private security advisory on the repository rather than a public issue.
