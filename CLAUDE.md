# CLAUDE.md — working in this repo

Guidance for Claude Code and human contributors. **No secrets or deployment
details here** — those live in `CLAUDE.local.md` (gitignored). This file is public.

## What this is

An OPNsense plugin (`os-bandwidthd`) plus the `bandwidthd` capture daemon, which
OPNsense's repository does not carry. The dashboard, alerting, classifier and
fingerprint engine are platform-independent; everything firewall-facing goes
through one seam. **Read `docs/PLATFORM.md` before touching anything
that involves config.xml, cron, the API or packaging** — it records the traps that
cost real debugging time, most of which are silent failures rather than errors.

## This repository is public

Everything tracked here is published. The line between the two instruction files
is the line between public and private:

- `CLAUDE.md` (this file) is committed and must stay that way — do not add it to
  `.gitignore`. It carries only what any contributor needs.
- `CLAUDE.local.md` is gitignored and never committed. It is the only place for
  deployment details: the box's addresses, ports, interface names, credentials
  and 1Password item names, the SSH loop, what is enabled on the live install.
- Tracked files ship **no default host**. `deploy-dev.sh` and `refresh-theme.sh`
  take the target as a required argument; a `root@10.0.0.1` default in a script
  is a description of someone's network.
- Harness fixtures are captures from a real network and are anonymised before
  commit: IPs remapped into TEST-NET-1 (`192.0.2.0/24`), MACs and hostnames
  replaced with synthetic values, vendors with placeholder brands. Check every
  field — a series key was once missed while the summary beside it was scrubbed.
  `tests/check_plugin.php` refuses any RFC 1918 address in `tests/harness/*.json`.
- Examples in code and docs use TEST-NET-1 or a generic `user@host`, never a real
  device.
- Before publishing or after a large change, sweep tracked files **and history**
  (`git log --all -p -G<pattern>`) for LAN addresses, hostnames, credential
  markers and secret-store references. A secret that was ever committed is
  public even after the file is removed.

## Layout

See the tree in `README.md`. The one structural rule: `plugin/src/` is rooted at
`PREFIX`, so `src/opnsense/foo` installs to `/usr/local/opnsense/foo`, and
`gen_plist` walks the whole staged tree — anything you add under `src/` ships,
and anything at the top of a stage root would install at `/`.

## Releasing

Releases go out as a signed `pkg(8)` repository on GitHub Pages (`gh-pages`
branch); `docs/REPOSITORY.md` has the whole picture. Two things to know when
touching it:

- `repo/` is the client-facing material: the repo config, the installer, the
  landing page, and the public key with its fingerprint. **The fingerprint is
  load-bearing** — every client has it installed, `build-repo.sh` refuses a key
  that doesn't match it, and `check_plugin.php` asserts it is the sha256 of
  `repo/bandwidthd.pub`. Never regenerate it casually.
- The flow is `scripts/build-repo.sh` on the box, then `scripts/publish-repo.sh`
  here. `origin` is a private remote; `github` is the public mirror and the Pages
  host. Both get `main`.

## The platform seam

`lib/bwd_platform.inc.php` is the **only** file that knows about OPNsense. The
data layer, the fingerprint engine and every cron script reach the box through it
alone. Keep it that way: it is what made this port a one-file rewrite rather than
a fork, and it is what lets the test suite run off-box.

- Settings: `bwd_cfg($key, $default)` / `bwd_cfg_on($key)`; write with
  `bwd_cfg_set()`.
- Overrides: `bwd_overrides_rows()` / `bwd_overrides_save()`.
- Identity: `bwd_platform_hostmap()` / `bwd_platform_macmap()` / `bwd_platform_subnets()`.
- Notices: `bwd_notify($subject, $msg, $priority)` → syslog, tag `bandwidthd`.
- Mail: `bwd_send_mail()` — direct SMTP, borrowing Monit's server.

## Conventions

- POSIX `sh` for scripts (`sh -n` clean); PHP in the surrounding style (`php -l`
  clean). Never write a cron interval like `*/5` inside a `/* … */` comment — the
  `*/` closes it.
- Vendored JS and fonts stay local; no CDNs. The OUI table is vendored for offline
  lookup.
- **Server-side data never goes under `www/`.** The OUI table and fingerprint
  signature DB live in `lib/data/`, beside the code that reads them; under the web
  root they were fetchable. A test enforces this.
- **The dashboard is styled to look like OPNsense, not like itself.** The rule is
  *inherit, don't declare*:
    - Structure uses OPNsense's own classes — every panel is a `content-box` on
      the Bootstrap 3 grid (`row` / `col-xs-12` / `col-md-*`), so typography, text
      colour and panel chrome come from the active theme.
    - Anything the plugin draws itself uses `currentColor` or a neutral
      `rgba(128,128,128,α)` overlay. Those land correctly on the light
      (`#fff`/`#373736`) and dark (`#101218`/`#ECECEC`) themes with **no
      dark-mode branching at all** — there is nothing to keep in sync.
    - Real colour is OPNsense's own: `#C03E14` brand rust for active controls,
      Bootstrap contextual colours for state, and **Tableau Classic10** for chart
      series — the palette core's Traffic and Firewall widgets use
      (`Chart.colorschemes.tableau.Classic10`). In/out are its first two entries.
  Colour still lives only in the `--bwd-*` tokens at the top of `bandwidthd.css`,
  and the chart JS reads them via `cssVar()`, so a re-skin is one file.
- **Never detect the theme by name or hardcode a dark palette.** OPNsense themes
  are whole-stylesheet swaps: no CSS custom properties, no class on `<body>`, and
  `opnsense-auto` switches at runtime. The only value that cannot be inherited is
  the chart's text colour, which `themeFg()` takes from the computed style of the
  live page. Verify a change in **both** themes with
  `tests/harness/dashboard.html?theme=dark`.
- The page must stay horizontal-overflow-free at phone width; the toolbar's window
  strip scrolls rather than widening the sheet. Bump the `?v=` on the css/js tags
  in `dashboard.volt` when you change either.
- **Toggle visibility with `el.hidden`, never `style.display`** — `.bwd-app [hidden]`
  is `display:none !important`, so an inline display never wins against it. And
  **call `chart.destroy()` before hiding its canvas**: Chart.js restores the
  canvas's original inline style on destroy, which un-hid it and left a blank
  panel over an empty-state message that never appeared.
- **Direction colour goes on the ▼/▲ glyph or the dot, never on the number.** The
  Classic10 orange is 2.5:1 as text on the light theme and the blue 3.9:1 on
  dark; both fail AA at any size the dashboard uses. Numbers inherit the ink.
- **Host rows are `role="option"` with `tabindex="0"` inside a `listbox`**, opened
  by Enter/Space (one delegated handler on the `<ul>`); a rebuild of the list puts
  focus back on the selected row. Keep that when touching `renderList`.
- Keep the data layer free of output so it stays CLI-testable.
- **Verify a reported finding on the box before acting on it.** In one review
  round: a "missing" Monit field existed (the fix would have broken working TLS
  verification), a suggested `find_interface_subnet()` does not exist on OPNsense
  any more than the call it replaced, and a suggested `curl --max-filesize` returned 0 bytes on a
  large page — it aborts the transfer rather than truncating it.
- **Capture discoveries in the docs.** A new gotcha, constraint or workaround goes
  into `docs/PLATFORM.md` or this file in the same change, not just the conversation.

## Gotchas

Full detail in `docs/PLATFORM.md`; the ones that bite hardest:

- **uuids are XML attributes.** Writing an `ArrayField` through the legacy
  `$config` array makes the uuid an element name. The XML stays well-formed, the
  model reads nothing, and the settings grid shows one empty row with no error.
  All writes go through `bwd_model()`. `ArrayField` has no `get()` — use
  `hasChild()` / `getChild()`.
- **Only `autocron` reaches the crontab.** `system_cron_configure()` reads that
  key alone, positionally: `[command, minute, hour, monthday, month, weekday]`.
  Other keys are silently dropped, which looks exactly like a job that never fires.
- **Boolean settings are listed, not detected.** `bwd_bool_keys()` names them.
  Coercing `"1"`/`"0"` by value instead turns a 1 GB quota into `"on"`, and
  `(float)"on"` is `0` — silently disabling the quota.
- **configd discards output on a non-zero exit** (`type:script_output` → "Execute
  error"). A diagnostic whose result is its message must exit 0.
- **CSRF: the layout only patches jQuery.** The dashboard uses `fetch()`, so
  `dashboard.volt` emits `window.bwdCsrfToken` and the JS sends `X-CSRFToken`.
- **CLI harnesses need the full legacy bootstrap** — `load_phalcon.php` +
  `config.inc` + `util.inc` + `legacy_bindings.inc` + `system.inc` +
  `interfaces.inc` + `plugins.inc`. Loading less fatals inside *core* OPNsense
  files, which reads like a plugin bug and is not one.
- **The two packages must not both ship `etc/rc.d/bandwidthd`** — `pkg add`
  refuses on a file conflict. The plugin owns it.
- **`plugins_configure('cron')` does nothing** — `cron` is not a plugin hook. Use
  `system_cron_configure()`, with the full legacy bootstrap or it fatals in core.
- **rc.conf.d renders to `/etc/rc.conf.d/`**, the only path the boot reads; and
  the template must be rendered at install, not just on a settings save.
- **Stop the daemon in `pre-deinstall`** — pkg deletes the rc script before
  post-deinstall runs.
- **Never `pkg add -A`** — the firmware tooling's `pkg autoremove` would delete
  the plugin the next time any other plugin is touched.
- **No php-fpm exists here** (lighttpd + php-cgi); use `configctl webgui restart`.
- **`flock` around `configctl -d` is illusory** — `-d` detaches immediately.
- **No pgsql PHP extension exists in the OPNsense repo**, so the external history
  database is inert there. Everything is gated on `bwd_db_available()`. See
  `docs/POSTGRES.md`.
- **Settings-form field ids start at the model name** (`bandwidthd.general.x`),
  because `setFormData()` walks the id against the whole response. Start at the
  container instead and the form renders entirely blank with no error. Dialogs fed
  by `getBase()` are rooted at the item (`override.x`) instead.
- **`Config::save($revision, $backup)`** — put the message in
  `['description' => …]`; a third argument is dropped and a `false` second turns
  off the config backup.
- **Never `$this->response->send()` in an API action** — the framework sends
  again and its error handler appends JSON to the file being downloaded.
- **An ACL pattern is an anchored regex over the full request URI, query string
  included** — not a glob over the path. A read endpoint called with parameters
  needs a trailing `*` or it never matches (a Status-only user saw 403 on every
  dashboard fetch). And `api/<plugin>/data/*` grants the write actions too, so
  read-only roles list their endpoints explicitly.
- **The menu/ACL cache is separate and must be flushed.** A new `Menu.xml` or
  `ACL.xml` on disk changes nothing until `pluginctl -c cache_flush` runs — the
  plugin installs fine and is just missing from the navigation. Worse,
  `pluginctl -c <name>` exits 0 for *any* name, so a wrong hook name is a silent
  no-op. The hook is `cache_flush`.
- **Three caches sit in front of changed GUI PHP**: opcache (revalidates on
  timestamp, so an edited file is usually picked up), the MVC layer's
  controller/model metadata, and configd's action list. `install.sh` and
  `deploy-dev.sh` clear all of them — via `configctl webgui restart` for the PHP
  workers, a configd restart, and `pluginctl -c cache_flush`.

## Data model

bandwidthd logs **CDF** time series (`/usr/local/bandwidthd/log.<period>.<0-5>.cdf`,
16 fields: `ip,ts, send×7, recv×7`; send=out, recv=in). The seven per-direction
counters are `total,icmp,udp,tcp,ftp,http,p2p`, but **`http`/`ftp`/`p2p` are
port-based sub-slices of `tcp`, not peers of it** — only `tcp`/`udp`/`icmp` are
mutually exclusive and sum to ≈`total`. Never sum `tcp+http+…`; the dashboard
splits `tcp` into HTTP/HTTPS + FTP + P2P + "other TCP" and adds "other IP"
(`total−tcp−udp−icmp`) so the slices are disjoint (`renderProto`).

**Identity is the MAC; the IP is a descriptive fallback.** Durable layers key by
MAC, `bwd_hosts()` consolidates rows by MAC, and series/percentile union a MAC's
IPs. Two consequences: devices that reused an IP never merge as long as both have
MACs, and rotating private MACs (iOS/Android) present a new identity per rotation
— never mis-merged, but not unifiable either.

## Testing

- `php tests/run.php` — data-layer units (CDF parsing and the protocol invariant,
  MAC consolidation, tags, percentile/CIDR maths, OUI/vendor, a classifier accuracy
  floor, CSV escaping). Runs off-box via the stubs in `tests/bootstrap.php`, which
  replace the platform seam.
- `php tests/check_plugin.php` — structural contracts: model/form field agreement,
  the boolean list, JS-to-controller endpoint agreement, configd action coverage,
  no duplicate function definitions, no server-side data in the web root, and the
  `autocron` shape. These catch the silent failures above, which `php -l` cannot.
- `tests/harness/dashboard.html` — runs the real dashboard JS/CSS against captured
  (anonymised) fixtures in a browser, with no firewall. The only check that catches
  a runtime JS error or a broken render. Serve the repo root and open it; see
  `tests/harness/README.md`.
- Add a case under `tests/cases/` whenever you touch a pure function.
- On-box checks: `configctl bandwidthd configure`, `php -f …/alerts.php -- --dry-run`,
  `php -f …/report.php -- --dry-run --window=today`, `configctl bandwidthd dbtest`.
- **Run both suites and read both counts.** They print near-identical tails; a
  commit went out with `86 passed, 3 failed` above a green `620 passed` line.
- **Negative-test every new guard** — break the thing it guards, see it fail, put
  it back. Two guards here passed vacuously until that was done.
- **A guard must compare against code, not comments.** Two of them tripped on
  their own explanatory comment naming the wrong call; strip comments first
  (`token_get_all` for PHP, `^\s*#` for shell/JSON hooks).
- Drive `tests/harness/dashboard.html` with Playwright and assert **computed
  styles**, not screenshots — that is what caught a dead `[hidden]`, three CSS
  rules matching no emitted markup, and an AA contrast failure. The GUI itself
  needs an authenticated cookie jar or API key; Playwright can't log in without
  putting the password in a tool argument.
