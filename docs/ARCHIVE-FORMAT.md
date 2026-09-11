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
  "generator": "SH Clone Migration 1.0.0",
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
  "entries": 10125,
  "raw_size": "196512345",
  "stored": "70211045",
  "completed": 1789110509,
  "manifest_entry": "manifest.json",
  "checksum_entry": "checksums/checksums.json",
  "checksum_digest": "…64 hex chars…",
  "source": "https://example.com",
  "files": 10063,
  "tables": 50
}
```

`checksum_digest` chains every entry's `path|digest` pair in archive order, so
one comparison detects a missing, added, reordered or altered entry.

The footer pointer at the very end lets a reader jump straight to it: an
archive can be described (size, entry count, completeness, source URL) without
reading it, and without the password when it is encrypted.

## Logical layout

Entries appear in this order:

```
manifest.json                     Everything about the source site
config/metadata.json              Safe wp-config constants, .htaccess, robots.txt
database/metadata.json            Prefix, charset, table list, sizes
database/tables/<table>.sql       One entry per table: DROP, CREATE, INSERTs
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
| `wp-content` | `WP_CONTENT_DIR` | the destination's `WP_CONTENT_DIR` |
| `plugins` | `WP_PLUGIN_DIR`, when outside the content directory | the destination's plugin directory |
| `mu-plugins` | `WPMU_PLUGIN_DIR`, when outside | the destination's mu-plugin directory |
| `uploads` | the uploads base directory, when outside | the destination's uploads directory |
| `wp-root` | `ABSPATH`, only when core files are included | the destination's `ABSPATH` |

A root that lives inside another one is not recorded separately; its files are
already covered by the parent walk.

## Manifest

```json
{
  "format": 1,
  "generator": "SH Clone Migration",
  "version": "1.0.0",
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
keys, no salts. It stays unencrypted so the import screen can describe an
archive before the password is entered.

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
