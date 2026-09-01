# External PostgreSQL history

The plugin can offload durable traffic history to an external PostgreSQL server,
turning the firewall into a thin exporter — **but it cannot run on a stock
OPNsense box.**

## The blocker

OPNsense's package repository ships **no PostgreSQL PHP extension at all**:

```
# pkg search -q pgsql          # returns nothing
# pkg search -q php85          # php85-mysqli and php85-pdo exist; no pgsql
```

The code uses the native `pg_*` functions, not PDO.

Every entry point is gated on `bwd_db_available()`, which simply checks for
`pg_connect()`. With no extension present:

- `db_enable` can be ticked, but `bwd_db_enabled()` stays false;
- the dashboard silently serves CDF-only data, exactly as if the feature were off;
- the "Test database connection" button reports
  `failed: no pgsql PHP extension on this system`;
- no export or maintenance cron job is registered.

Nothing breaks — the feature is inert, not broken.

## Making it work anyway

`postgresql18-client` **is** in the OPNsense repo, so the server side is
reachable; only the PHP binding is missing. Two options, neither of them
supported by OPNsense:

1. **Install the extension from the FreeBSD repo.** The ABI matches
   (`FreeBSD:15:amd64`), so the stock FreeBSD `php85-pgsql` package works.
   OPNsense disables the FreeBSD repo by default and re-enabling it wholesale is
   a bad idea — fetch the one package and add it directly:

   ```sh
   fetch https://pkg.freebsd.org/FreeBSD:15:amd64/latest/All/php85-pgsql-8.5.9.pkg
   pkg add -f php85-pgsql-8.5.9.pkg
   configctl webgui restart      # there is no php-fpm here; this recycles php-cgi
   ```

   A firmware upgrade that moves the PHP version will drop it; re-add it after.

2. **Leave it off.** The local CDF logs plus the durable MAC-keyed rollup under
   `/usr/local/bandwidthd/rollups/` already give per-device daily history that
   survives log rotation. The database buys long-range retention and downsampling,
   not day-to-day function.

## If you do enable it

The schema, hybrid read seam, exporter spool and downsampling:

- The export **watermark partitions time**: the database owns `ts ≤ W`, the local
  CDF owns `ts > W`, so a bucket total is `DB_sum(≤W) + CDF_sum(>W)` and nothing
  is double-counted.
- There are deliberately **two** watermarks. The DB `usage_watermark` advances
  only to data actually in the database; a local `capture_watermark` (in
  `rollups/dbexport_state.json`, because the DB cannot be written while it is
  down) advances to the highest timestamp read from CDF. During an outage the
  exporter spools to `rollups/dbexport_spool.ndjson` and replays it, idempotently,
  on reconnect.
- Downsampling is **authoritative, not additive**: rolling a day into `bwd_daily`
  uses `ON CONFLICT (day,mac) DO UPDATE SET in_bytes = EXCLUDED…`, replacing any
  earlier estimate rather than adding to it, then deletes that day's fine rows so
  the read seam advances.

Credentials are stored in `config.xml` in plain text (the model has no encrypted
field type), so the database user should be scoped to its own database.
