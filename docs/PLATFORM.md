# OPNsense platform notes

Everything that touches the firewall goes through one file,
`lib/bwd_platform.inc.php`; the data layer, the fingerprint engine and the cron
scripts reach the box only through it. This file records how OPNsense actually
behaves at each of those points, because almost none of it is documented.

## Where things live

| Concern | OPNsense |
|---|---|
| Settings storage | `OPNsense/Bandwidthd/general` (MVC model) |
| Settings UI | `Bandwidthd.xml` model + `forms/general.xml` + Volt view |
| Per-device overrides | `ArrayField` grid |
| Settings read | `bwd_cfg(...)` |
| Config write | the model — see "uuids are attributes" |
| Dashboard page | `DashboardController` + `dashboard.volt` |
| Dashboard JSON API | `Api/DataController` → `/api/bandwidthd/data/*` |
| API authentication | the framework (session or HTTP Basic API key) |
| CSRF | `X-CSRFToken` header (the layout patches jQuery only) |
| Service control | rc script + configd actions + `*_services()` hook |
| Cron | `*_cron()` hook returning `autocron` entries |
| Notices | syslog (`bwd_notify`) |
| Outbound mail | direct SMTP, borrowing Monit's server |
| DHCP leases | Kea CSV + dnsmasq leases |
| Static DHCP | `OPNsense/Kea/dhcp4/reservations` |
| Host overrides | `OPNsense/unboundplus/hosts`, `dnsmasq/hosts` |
| PHP Postgres driver | **not packaged at all** (see `POSTGRES.md`) |

## Things that cost real debugging time

### uuids are XML attributes, not element names

An OPNsense `ArrayField` item is `<override uuid="…">`. Writing the legacy
`$config` array with the uuid as the PHP array key produces
`<override><uuid-as-element>…` instead, which is well-formed XML that the model
cannot read: the settings grid renders one empty row with a freshly minted uuid,
and no error appears anywhere. **All writes go through the model API**
(`bwd_model()` / `bwd_overrides_save()`), which is the only thing that gets this
right. `ArrayField` has no `get()` — item lookup is `hasChild()` / `getChild()`.

### A settings form's field ids must start with the model name

`setFormData()` splits each field id on `.` and walks the **whole** API response,
which is rooted at the controller's `$internalModelName`. So a settings field id
is `<modelName>.<container>.<field>` — `bandwidthd.general.enabled`, matching
Monit's `monit.general.*`. Ids that start at the container render a form where
**nothing** populates: every control blank, every dropdown "Nothing selected", no
error anywhere, and Save silently mismatched in the same way.

A dialog fed by `getBase()`/`setBase()` is different — that response is rooted at
the item name, so the override dialog's ids correctly start at `override.`.

### `Config::save()` takes `($revision, $backup)`, not a message

The signature is `save($revision = null, $backup = true)`. Passing a description
as a third argument silently discards it, and passing `false` second **turns off
the config backup and the audit-log entry** that every other OPNsense settings
write produces. The framework's own pattern is
`Config::getInstance()->save(['description' => $msg])` with backup left alone.

### An API action must return its body, never `send()` it

Calling `$this->response->send()` in an action sends the response, then the
framework sends it again, throws `Response Already Sent`, and its error handler
appends `{"errorMessage":…}` to what the browser is saving. The result is a
corrupt download served with HTTP 200 — a CSV with a JSON error glued to the end.
Set the headers and `return` the string; that is what OPNsense's own download
endpoints do.

### An ACL pattern is a glob over the request path

`api/<plugin>/data/*` grants **every** action on that controller, writes
included. A read-only role therefore has to list its read endpoints explicitly,
or "may view the dashboard" quietly also means "may rewrite device overrides and
trigger active LAN scans".

### Kea lease files must be read oldest-first

Kea's memfile keeps the live `kea-leases4.csv` alongside older LFC output
(`kea-leases4.csv.2`). Reading the current file first and letting later rows
overwrite means a stale lease wins: a reused IP is attributed to the previous
device, and an old `state != 0` row can unset a valid active lease. Read the
rotated file first so the current one takes precedence.

### Only `autocron` reaches the crontab

`system_cron_configure()` iterates `plugins_cron()` but reads **only** the
`autocron` key, as the positional list
`[command, minute, hour, monthday, month, weekday]`. A job described with
`minutes`/`hours`/`weekdays` keys appears in `plugins_cron()` output and is then
silently dropped — the failure is indistinguishable from a schedule that simply
never fires. `tests/check_plugin.php` guards this.

### Booleans must be listed, not detected

The model stores a `BooleanField` as `"1"`/`"0"`. Translating those to
`"on"`/`"off"` by *value* corrupts every numeric setting: a 1 GB quota reads back
as `"on"`, and `(float)"on"` is `0` — silently disabling the quota it was meant
to set. `bwd_bool_keys()` lists the boolean fields explicitly, and a test asserts
that list matches the model's `BooleanField`s.

### configd swallows a non-zero exit

An action of `type:script_output` reports a non-zero exit as `Execute error` and
discards the script's stdout. A diagnostic whose *result is its message* (the
"Test database connection" button) must therefore always exit 0 and report in
text.

### The layout only patches jQuery for CSRF

`layouts/default.volt` adds the `X-CSRFToken` header to jQuery's ajax. The
dashboard is vanilla `fetch()`, so `dashboard.volt` emits the token as
`window.bwdCsrfToken` and the JS sends the header itself. Without it every write
returns a bare 403.

### The menu and ACL live in a cache that installing files does not invalidate

The GUI builds its navigation and ACL from
`/var/lib/php/tmp/opnsense_menu_cache.xml` and the model caches beside it.
Dropping a new `Menu.xml` and `ACL.xml` on disk does **not** invalidate them, so a
correctly installed plugin is simply absent from the navigation — no error, and
the pages work perfectly if you type the URL. Restarting configd or the web GUI
does not help either; those are different caches.

The fix is `pluginctl -c cache_flush`, which clears the menu, ACL and model
caches (it is the `cache_flush` plugin hook, implemented by
`system_cache_flush()`). The installer, the dev deploy script and the package's
post-install script all run it.

⚠ **`pluginctl -c <name>` exits 0 for any name at all.** An invalid hook name
prints nothing and succeeds, so a typo is a silent no-op that looks like it ran —
this shipped once as `pluginctl -c bandwidthd`, which did nothing. A test asserts
the hook name.

### CLI scripts need the full legacy bootstrap

`plugins_cron()` and friends call `log_msg()`, `exec_safe()` and `shell_safe()`
from `util.inc`, and core plugin hooks reach further still. A CLI harness that
loads only `config.inc` fatals inside *core* OPNsense files, which reads like a
plugin bug and is not one. The working bootstrap is `load_phalcon.php` +
`config.inc` + `util.inc` + `legacy_bindings.inc` + `system.inc` +
`interfaces.inc` + `plugins.inc`.

### Packaging traps that only surface on a reboot or an uninstall

None of these fail during ordinary testing, which is exactly why they are worth
listing.

- **`pkg add -A` marks the package automatic**, and OPNsense's firmware tooling
  runs `pkg autoremove` on install, remove, reinstall, cleanup and sync. Nothing
  depends on a leaf plugin, so the next plugin the user installs from the GUI
  would silently take this one with it. Use plain `pkg add`, and `pkg set -A 0`
  to clear the flag on an upgrade over an older install.
- **`rc.conf.d` must render to `/etc/rc.conf.d/<name>`.** The boot path
  (`/usr/local/etc/rc.freebsd`) sources only `/etc/rc.conf`,
  `/etc/rc.conf.local` and `/etc/rc.conf.d` before its `rc_enabled` gate. A file
  under `/usr/local/etc/rc.conf.d` leaves the service disabled at boot while
  every manual `service`/`onestart` still works — so only a reboot exposes it.
  Every shipped plugin targets `/etc/rc.conf.d`.
- **Render the service template at install time.** It is otherwise written only
  on a settings save, so a box installed and rebooted before the first Save comes
  up with no rc.conf.d file at all.
- **pkg removes the package's files before `post-deinstall`.** Stopping a daemon
  through an rc script the package owns has to happen in **`pre-deinstall`**; in
  post-deinstall the script is already gone and the `|| true` hides it, leaving
  the daemon running with nothing left to stop it.
- **`plugins_configure('cron')` is a no-op** — `cron` is not a registered plugin
  hook. The crontab writer is `system_cron_configure()`, and it needs the full
  legacy bootstrap (it reaches core hooks calling `log_msg()`/`exec_safe()`) or
  it fatals inside OPNsense's own files. Both failure modes are silent behind the
  usual `|| true`.
- **`flock` around `configctl -d` guards nothing.** `-d` detaches and returns
  immediately, so the lock is released the moment the job is queued. Either drop
  `-d`, or hold the lock inside the script itself.
- **There is no php-fpm on OPNsense.** The GUI is lighttpd + php-cgi;
  `/usr/local/etc/rc.d/php-fpm` does not exist, so a "restart php-fpm" step is a
  silently swallowed no-op. The equivalent lever is `configctl webgui restart`.

### Two packages must not both ship the rc script

`pkg add` refuses on a file conflict. The daemon package deliberately ships no
`etc/rc.d/bandwidthd`; the plugin owns it, because its `start_precmd` regenerates
`bandwidthd.conf` from `config.xml` before every start.

## Design notes

- **Per-device overrides are an `ArrayField`.** It has no column limit and
  preserves every key it is given, so adding an override field is a model change
  and nothing else.
- **No hand-rolled API-key layer.** OPNsense authenticates `/api/*` itself, so
  the same endpoints serve the dashboard and external callers with one
  implementation and one ACL.
- **Cron is derived, not imperative.** Jobs come from the settings, so turning a
  feature off removes its job; there is no cleanup path to forget. This also
  simplified the probe auto-off logic.
- **Kea's lease CSV is the primary identity source.** It carries address, MAC,
  hostname and state per row, and gives identity for devices that are currently
  quiet.
- **Server-side data stays out of the web root.** The OUI table and fingerprint
  signature DB live beside the code that reads them, so they are not fetchable.
