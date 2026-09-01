# Installing

## Requirements

- OPNsense 26.7 or later on amd64 (developed against 26.7.3, FreeBSD 15.1, PHP 8.5)
- Root SSH or console access to run the installer

## Install from the package repository

As root on the firewall:

```sh
fetch -o - https://sioakim.github.io/opnsense-bandwidthd/install-repo.sh | sh
```

This installs the repository's signing fingerprint and its `pkg(8)` config
(`/usr/local/etc/pkg/repos/bandwidthd.conf`) and refreshes the catalogue. Then
either install **os-bandwidthd** from *System → Firmware → Plugins* — it is
listed like any other plugin — or run `pkg install os-bandwidthd`. The `bandwidthd`
daemon and `libgd` come in as dependencies. Upgrades arrive through the normal
firmware update flow, since the repository is a regular `pkg` repository.

The manual equivalent, and how the repository is built and signed, is in
`REPOSITORY.md`.

## Install from source

On the box, from a copy of this repository:

```sh
sh scripts/build-pkgs.sh
sh scripts/install.sh
```

## First steps in the GUI

In the GUI:

1. **Services → BandwidthD → Settings** — tick *Enable*, pick the listen
   interface (usually LAN) and save. *Log CDF data* is on by default and is what
   the dashboard reads; without it the dashboard stays empty.
2. **Reporting → BandwidthD** — the dashboard. Data appears after bandwidthd's
   first flush, roughly 2.5 minutes after start.

Direct URLs, if you prefer: `/ui/bandwidthd/general` and `/ui/bandwidthd/dashboard`.

If the menu entries are missing but those URLs work, the GUI's menu cache is
stale — run `pluginctl -c cache_flush` and reload. `install.sh` does this, so it
should not happen on a normal install.

## What gets installed

| Path | What |
|---|---|
| `/usr/local/bandwidthd/` | daemon, generated config, CDF logs, `rollups/` state |
| `/usr/local/opnsense/mvc/app/…/Bandwidthd/` | model, controllers, forms, views |
| `/usr/local/opnsense/scripts/OPNsense/Bandwidthd/` | data layer + cron scripts |
| `/usr/local/opnsense/service/conf/actions.d/actions_bandwidthd.conf` | configd actions |
| `/usr/local/www/bandwidthd_ui/` | CSS, JS, fonts, Chart.js |
| `/usr/local/etc/inc/plugins.inc.d/bandwidthd.inc` | service + cron hooks |
| `/usr/local/etc/rc.d/bandwidthd` | rc script (regenerates the config on start) |

Settings live in `config.xml` under `OPNsense/Bandwidthd`, so they survive
reinstalls. Durable state that is not a setting — the daily rollup, alert state,
fingerprint cache, custom tags — lives as JSON under
`/usr/local/bandwidthd/rollups/`, written atomically.

## Permissions

Two ACL entries are registered, so you can grant the dashboard without granting
the settings:

- **Status: BandwidthD** — the dashboard and its read API
- **Services: BandwidthD** — everything, including settings and service control

## Optional features

All are off by default.

- **Traffic alerts** — quota, anomaly, exfiltration and new-device rules,
  evaluated every 15 minutes. Alerts go to the system log (System → Log Files),
  tagged `bandwidthd`.
- **Scheduled report** — a usage summary by email. OPNsense has no system-wide
  notification SMTP settings, so the report borrows the mail server configured for
  Monit (Services → Monit → Settings). With none configured it logs the report
  instead of dropping it.
- **Active fingerprinting** — probes LAN devices over HTTP/TLS/mDNS/SSDP to
  identify them. Restricted to the monitored subnets. It disables itself after 24
  hours by default, so enabling it fingerprints the fleet and then stops.
- **External PostgreSQL history** — see `POSTGRES.md`; it cannot run on a stock
  OPNsense box, because the repository ships no pgsql PHP extension.

## Uninstalling

```sh
pkg delete os-bandwidthd bandwidthd
```

To drop the repository as well:

```sh
rm -f /usr/local/etc/pkg/repos/bandwidthd.conf
rm -rf /usr/local/etc/pkg/fingerprints/bandwidthd
```

Settings remain in `config.xml`, and the CDF logs and rollups remain under
`/usr/local/bandwidthd/`. Remove that directory to discard the history.
