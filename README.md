# opnsense-bandwidthd

Per-device bandwidth accounting for **OPNsense**: an interactive traffic
dashboard, a traffic-alerting engine, and a multi-signal device classifier, built
around the BandwidthD capture daemon.

OPNsense's package repository carries neither `bandwidthd` nor a plugin for it,
so this ships both: the daemon and the plugin.

Everything that touches the firewall goes through a single platform file; the
dashboard, alerting engine, classifier and fingerprint engine are
platform-independent and run off-box under the test suite. `docs/PLATFORM.md`
records the OPNsense behaviour the plugin depends on — most of it undocumented.

## Installing on an OPNsense box

Requires OPNsense 26.7 or later on amd64 and a root shell (SSH, or the
console). The plugin comes from a signed package repository, so once the
repository is known it installs and upgrades like any other plugin.

**1. Add the repository** — as root on the firewall:

```sh
fetch -o - https://sioakim.github.io/opnsense-bandwidthd/install-repo.sh | sh
```

This writes the signing fingerprint to `/usr/local/etc/pkg/fingerprints/bandwidthd/`
and the repository definition to `/usr/local/etc/pkg/repos/bandwidthd.conf`,
then refreshes the catalogue. Every download is verified against that
fingerprint. The equivalent manual commands are on the [repository page][repo].

**2. Install the plugin** — in the GUI under **System → Firmware → Plugins**
(`os-bandwidthd` is listed with the others; click **+**), or:

```sh
pkg install os-bandwidthd
```

The `bandwidthd` daemon and `libgd` come in as dependencies. Reload the GUI
afterwards so the new menu entries appear.

**3. Turn it on** — **Services → BandwidthD → Settings**: tick *Enable*, pick
the interface to watch (usually LAN), save. Leave *Log CDF data* on — it is
what the dashboard reads.

**4. Look at it** — **Reporting → BandwidthD**. Data appears about 2–3 minutes
after the daemon starts, at its first flush. Alerts, the scheduled report,
active fingerprinting and the external history database are optional and off
by default; `docs/INSTALL.md` describes each.

**Upgrading** happens through the normal firmware flow — **System → Firmware →
Updates** — or `pkg upgrade`. **Removing**: `pkg delete os-bandwidthd bandwidthd`;
settings stay in `config.xml` and history stays under `/usr/local/bandwidthd/`
until you delete it. To forget the repository too, remove the two files step 1
created.

**If something looks wrong**

- Menu entries missing although `/ui/bandwidthd/dashboard` loads: the GUI's
  menu cache is stale — `pluginctl -c cache_flush`, then reload the page.
- `pkg update` reports the `bandwidthd` repository as unavailable after a major
  OPNsense upgrade: the package ABI changed and a build for it is not published
  yet. Nothing else is affected.
- Packages installed from a source build show `unknown-repository` in
  `pkg query %R`; `pkg install -f os-bandwidthd bandwidthd` re-associates them.

[repo]: https://sioakim.github.io/opnsense-bandwidthd/

## What it does

**Dashboard** (Reporting → BandwidthD) — a self-contained vanilla-JS page:

- per-device in/out/total over a chosen window (1h → 1y, or a custom range),
  with the CDF resolution tier derived from the window rather than picked by hand
- stacked top-talker chart, 95th-percentile, per-calendar-day ledger
- protocol mix split into **disjoint** slices that sum to the device total —
  bandwidthd's `http`/`ftp`/`p2p` counters are port-based sub-slices of `tcp`,
  not peers of it, so naively summing them reads an all-HTTPS device at ~200% of
  its real traffic
- device rows **consolidated by MAC**, so a device that changed IP through a DHCP
  lease renewal is one row, not several
- free-form tags, per-device name/vendor/quota overrides edited in place, and
  CSV/JSON export of the current view

**Alerting** — daily quota (interface and per-device), anomaly detection against
a device's own 7-day average, an exfiltration heuristic, and new-device alerts.
Backed by a durable daily rollup that survives CDF log rotation.

**Device identity** — a weighted classifier fusing manual overrides, hostname and
subnet rules, and a bundled OUI vendor table (fully offline), with optional active
fingerprinting (HTTP/TLS/mDNS/SSDP/DHCP) for devices the passive signals miss.
Ambiguous vendors are weighted low on purpose: an Apple OUI says nothing about
whether a device is a phone or a laptop, so the hostname decides.

**API** — the same endpoints the dashboard uses are available to scripts under
`/api/bandwidthd/data/*`, authenticated by OPNsense with an API key.

## Repository layout

```
plugin/src/                      the os-bandwidthd plugin (rooted at /usr/local)
  etc/inc/plugins.inc.d/         service + cron hooks
  etc/rc.d/bandwidthd            rc script; regenerates the config on start
  opnsense/mvc/app/              model, controllers, forms, Volt views
  opnsense/scripts/…/Bandwidthd/ data layer (lib/) + the cron scripts
  opnsense/service/              configd actions + templates
  www/bandwidthd_ui/             CSS, JS, fonts, vendored Chart.js
daemon/                          the bandwidthd package (prebuilt binary + port recipe)
repo/                            package-repository config, signing fingerprint, installer
scripts/                         build, install, dev-deploy, repository publish
tests/                           off-box unit + structural checks
docs/                            BUILD, INSTALL, PLATFORM, POSTGRES, REPOSITORY
```

## Development

```sh
php tests/run.php           # data-layer unit tests (no box, DB or network)
php tests/check_plugin.php  # model/form/API/cron contracts
python3 -m http.server 8899 # then open /tests/harness/dashboard.html — runs the
                            # real dashboard JS against captured fixtures
sh scripts/deploy-dev.sh root@fw   # sync to the box without rebuilding packages
```

`docs/BUILD.md` covers the build; `docs/PLATFORM.md` is worth reading before
changing anything that touches config.xml, cron, or the API.

## Licence

The plugin is Apache-2.0. The bundled `bandwidthd` daemon is GPLv3+ (see
`daemon/prebuilt/usr/local/share/licenses/`). Chart.js is MIT; the vendored fonts
carry the SIL Open Font License.
