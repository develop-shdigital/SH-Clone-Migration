# The `.wpress` archive format

Format version 1. This document is the specification: an archive that follows
it can be read by any implementation, and the plugin's own reader is written
against it rather than against the writer.

Everything is big endian. "uint32" means four bytes, "uint64" means eight.

## Container

```
┌─────────────────────────────────────────────────────────────────┐
│ MAGIC                8 bytes   "SHCMWPRS"                       │
├─────────────────────────────────────────────────────────────────┤
│ prologue length      uint32                                     │
│ prologue             JSON, never encrypted                      │
├─────────────────────────────────────────────────────────────────┤
│ entry                repeated:                                  │
│   header length      uint32   (0 terminates the entry list)     │
│   header             JSON, never encrypted                      │
│   payload            blocks, see below                          │
├─────────────────────────────────────────────────────────────────┤
│ end of entries       uint32   0x00000000                        │
│ footer length        uint32                                     │
│ footer               JSON                                       │
│ footer pointer       uint64   absolute offset of "footer length"│
│ END MAGIC            8 bytes   "SHCMEND1"                       │
└─────────────────────────────────────────────────────────────────┘
```

An archive without the end magic and a resolvable footer pointer is
incomplete: it was truncated in transfer, or the export never finished. The
reader reports that rather than restoring part of it.

## Prologue

```json
{
  "format": 1,
  "generator": "SH Clone Migration 1.0.1",
  "created": 1789110462,
  "block_size": 1048576,
  "compress": "deflate",
  "encrypted": false,
  "source": "https://example.com",
  "job": "20260911-075824-b11fa0b3"
}
```

When `encrypted` is true an `encryption` object is present:

```json
{
  "cipher": "xchacha20poly1305-ietf",
  "kdf": "argon2id",
  "salt": "…32 hex chars…",
  "ops": 2,
  "mem": 67108864,
  "check": "base64 of an encrypted known plaintext"
}
```

`cipher` is `xchacha20poly1305-ietf` (libsodium) or `aes-256-gcm` (OpenSSL).
`kdf` is `argon2id` (with `ops` and `mem`) or `pbkdf2-sha256` (with `rounds`).
`check` decrypts to `shcm-archive-key-check-v1` when the password is right,
which lets a wrong password be rejected before any data is touched.

The prologue is never encrypted: a reader has to know how to decrypt before it
can decrypt.

## Entry header

JSON, with short keys because an archive can hold hundreds of thousands of
entries.

| Key | Type | Meaning |
|---|---|---|
| `p` | string | Logical path inside the archive |
| `t` | string | `f` file, `d` directory, `l` symlink |
| `s` | string | Raw size, 20-digit zero-padded decimal |
| `z` | string | Stored size in bytes, including block headers |
| `n` | string | Block count, 20-digit zero-padded decimal |
| `h` | string | 64 hex characters, chained SHA-256 of the raw payload |
| `m` | int | Modification time, Unix seconds |
| `x` | string | Permission bits, four octal digits |
| `g` | string | Reporting group (`uploads`, `plugins`, `database`, …) |
| `r` | string | Logical root (`wp-content`, `uploads`, `wp-root`, …) |
| `lt` | string | Link target, symlinks only |

`s`, `z`, `n` and `h` are fixed width on purpose. They are written as
placeholders before the payload and patched afterwards, and a fixed width
guarantees the patch cannot change the header's length. Sizes are strings so
they survive a JSON round trip above `PHP_INT_MAX` on a 32-bit build.

JSON can only carry UTF-8, but file names are arbitrary bytes. A string that
is not valid UTF-8 (a Latin-1 or CP437 file name, typically) is written as
`"\u0000b64:"` followed by the base64 of its bytes, and read back as those
exact bytes. A backslash in `p` is part of the name, not a separator; an
importer on Windows, where it cannot be represented, treats it as a separator
and applies the usual `..` checks afterwards.

## Payload blocks

```
┌──────────────────────────────────────────┐
│ flags          1 byte                    │
│                  bit 0  deflate          │
│                  bit 1  encrypted        │
│ stored length  uint32   bytes on disk    │
│ raw length     uint32   bytes decoded    │
│ data           stored length bytes       │
└──────────────────────────────────────────┘
```

Coding order is **compress, then encrypt**; decoding is the reverse. Each
block stands on its own, which is what makes the format work:

- a reader skips an entry with one `fseek` per block, decoding nothing;
- a reader resumes decoding at any block boundary, so an import that runs out
  of time continues from the exact block it stopped at;
- a writer stops after any block and continues in the next request;
- memory use is bounded by the block size, not the entry size.

A block whose deflated form is not smaller is stored raw, and the deflate flag
is left clear. File types that are already compressed (JPEG, PNG, WebP, MP4,
ZIP, WOFF, PDF and friends) skip the attempt entirely.

## Entry digests

```
h(0) = 32 zero bytes
h(i) = sha256( h(i-1) ‖ raw_block_i )
digest = hex( h(n) )
```

A chained digest rather than `sha256(entry)` because its whole state is 32
bytes and can therefore be persisted between requests. PHP's incremental
hashing context cannot be serialized, so an entry that spans several requests
could not otherwise be digested at all.

The digest is a function of the content *and* the block boundaries, which is
what the verifier recomputes: it reads the blocks as stored and chains them
the same way. It is an archive-integrity checksum, not a content fingerprint
to compare across archives.

## Footer

```json
{
  "entries": 10150,
  "raw_size": "196908231",
  "stored": "70321322",
  "completed": 1789110509,
  "manifest_entry": "manifest.json",
  "checksum_entry": "checksums/checksums.json",
  "checksum_digest": "…64 hex chars…",
  "checksum_scheme": 2,
  "source": "https://example.com",
  "files": 10096,
  "files_skipped": 3,
  "skipped": { "scan": 2, "copy": 1 },
  "tables": 50,
  "database": { "included": true, "prefix": "wp_", "tables": 50,
                "rows": 1119, "sql_bytes": 343104 },
  "groups": { "meta": { "entries": 4, "bytes": 21170 },
              "database": { "entries": 50, "bytes": 343104 },
              "plugins": { "entries": 9675, "bytes": 177036912 }, "…": {} },
  "warnings": 0,
  "generator": "SH Clone Migration 1.0.1"
}
```

`checksum_digest` chains one ledger item per entry, in archive order, with
the same chained SHA-256 as the entries themselves, so one comparison detects
a missing, added, reordered or altered entry. `checksum_scheme` says what a
ledger item holds:

| Scheme | Ledger item | Written by |
|---|---|---|
| 1 (or absent) | `path|digest` | 1.0.0 |
| 2 | `path|type|link target|mode (4 octal digits)|digest` | 1.0.1 and later |

Scheme 2 also covers what an entry has besides its payload: a symlink whose
target was changed, or an entry whose type or permissions were altered, no
longer passes. Verifiers read the scheme from the footer, so archives from
1.0.0 still verify.

The footer is never encrypted. From version 1.0.1 it records what was actually
written rather than what the scan planned: the file entries archived; the
files that are not in the archive (`files_skipped`, split in `skipped` into
those the scan passed over — unreadable, or above the size limit — and those
the copy had to give up on); whether the database is included, its tables,
rows and SQL bytes as counted while dumping; and the entries and bytes per
group. Version 1.0.0 footers only carry `files` and `tables` (the planned
counts). The manifest's counts are what the scan planned and are never shown
as an archive's contents; an archive without a footer has no known contents.

The footer pointer at the very end lets a reader jump straight to it: an
archive can be described (size, entry count, completeness, source URL, database
and file totals) without reading it, and without the password when it is
encrypted. An archive without a valid footer is incomplete — still being
written, or from an export that did not finish — and is neither offered for
download nor accepted for import.

## Checksum file

Next to a finished archive the plugin writes `<archive>.wpress.sha256` in the
format `sha256sum` reads and writes:

```
d5de21a000ee232e08c8e0f2eca739125d37491fd0f08e89443e81f2f2e416e3  site-20260924-084722-e385da5172904dec.wpress
```

It is the plain SHA-256 of the whole archive file, so `sha256sum -c`,
`shasum -a 256` or `Get-FileHash -Algorithm SHA256` can check a downloaded
copy. A checksum file older than its archive is ignored.

## Logical layout

Entries appear in this order:

```
manifest.json                     Everything about the source site
config/metadata.json              Safe wp-config constants, .htaccess, robots.txt
database/metadata.json            Prefix, charset, table list, sizes
database/tables/<table>.sql       One entry per table: DROP, CREATE, INSERTs
                                  (a name with characters outside [A-Za-z0-9_-]
                                  gets them replaced by "_" plus "-" and the
                                  first 8 hex digits of md5(name), so two
                                  tables never share an entry)
database/views.sql                Views, when present
database/routines.json            Trigger definitions, when present
files/<root>/<relative path>      Every file, by logical root
checksums/checksums.json          The digest ledger
```

The manifest comes first so an importer can describe an archive without
walking it. The checksum ledger comes last because it is only complete when
everything else has been written.

### Logical roots

A file's path is recorded relative to a *logical root* rather than to
`ABSPATH`, because the destination may lay the installation out differently.

| Root | Source | Destination |
|---|---|---|
| `wp-content` | `WP_CONTENT_DIR` (always) | the destination's `WP_CONTENT_DIR` |
| `plugins` | `WP_PLUGIN_DIR`, when not physically inside the content directory | the destination's plugin directory |
| `mu-plugins` | `WPMU_PLUGIN_DIR`, likewise | the destination's mu-plugin directory |
| `uploads` | the uploads base directory, likewise (a symlinked uploads directory counts as outside) | the destination's uploads directory |
| `wp-root` | `ABSPATH` minus the other roots, only when core files are included | the destination's `ABSPATH` |

A root that physically lives inside another one is not recorded separately;
its files are already covered by the parent walk. Containment is decided on
real paths, so `wp-content/uploads -> ../../shared/uploads` becomes an
`uploads` root instead of a symlink entry with nothing behind it. Archives made
by version 1.0.0 with core files included stored `wp-content` under `wp-root`;
importers map `wp-root/<content dir>/…` back to the content directory.

## Manifest

```json
{
  "format": 1,
  "generator": "SH Clone Migration",
  "version": "1.0.1",
  "created": 1789110462,
  "site": { "home": "…", "siteurl": "…", "name": "…", "language": "…",
            "multisite": false, "abspath": "…", "content_dir": "…",
            "uploads_dir": "…", "permalink": "/%postname%/" },
  "wordpress": { "version": "6.x", "table_prefix": "wp_", "multisite": false },
  "php": { "version": "8.3.0", "sapi": "cli" },
  "database": { "server": "10.11.14-MariaDB", "charset": {…}, "prefix": "wp_",
                "tables": 50, "size": 12345678, "table_list": [ … ] },
  "files": { "count": 10063, "size": 194318336, "groups": { … }, "roots": [ … ] },
  "plugins": [ { "file": "…", "name": "…", "version": "…", "active": true } ],
  "mu_plugins": [ … ], "dropins": [ … ],
  "active_plugins": [ … ], "network_active_plugins": [ … ],
  "themes": [ … ], "active_theme": { "stylesheet": "…", "template": "…" },
  "components": { "woocommerce": "9.0.0", "elementor": "3.x", "acf": false },
  "archive": { "block_size": 1048576, "compression": "gzip",
               "encrypted": false, "include_core": false }
}
```

The manifest holds no secrets: no database credentials, no authentication
keys, no salts. In an encrypted archive it is encrypted like every other
entry; the import screen describes such an archive from the footer until the
password is entered.

From version 1.0.1 the manifest's `database` block also carries `included`
(false when the export left the database out, in which case `tables` is 0 and
`table_list` is empty), and the manifest records `effective_exclusions` (every
pattern that applied, defaults and settings included) and `max_file_size`.

## Reading an archive without this plugin

```php
$handle = fopen( $path, 'rb' );
fread( $handle, 8 );                                    // magic
$length   = unpack( 'N', fread( $handle, 4 ) )[1];
$prologue = json_decode( fread( $handle, $length ), true );

while ( true ) {
    $raw = fread( $handle, 4 );
    $len = unpack( 'N', $raw )[1];
    if ( 0 === $len ) {
        break;                                          // end of entries
    }
    $header = json_decode( fread( $handle, $len ), true );
    $end    = ftell( $handle ) + (int) ltrim( $header['z'], '0' );

    while ( ftell( $handle ) < $end ) {
        $block  = fread( $handle, 9 );
        $flags  = ord( $block[0] );
        $stored = unpack( 'N', substr( $block, 1, 4 ) )[1];
        $rawlen = unpack( 'N', substr( $block, 5, 4 ) )[1];
        $data   = fread( $handle, $stored );

        if ( $flags & 2 ) { $data = decrypt( $data ); }
        if ( $flags & 1 ) { $data = gzinflate( $data, $rawlen ); }

        // $data is the next $rawlen raw bytes of this entry.
    }
}
```

That is the whole reader. There is no index to rebuild and no central
directory to trust.

## Compatibility

The extension `.wpress` is shared with other migration plugins, but the
container is not: an archive from a different plugin starts with different
magic bytes and is rejected with a clear message rather than half-read. This
format is an independent design.
