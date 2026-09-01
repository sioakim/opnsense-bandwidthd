# Licensing

This repository bundles components under two licenses. There is no single
project-wide license.

## `plugin/` — Apache License 2.0

The OPNsense plugin (`os-bandwidthd`): the MVC model, controllers and views, the
data layer and cron scripts under `opnsense/scripts/`, the rc script, the configd
actions and templates, and the dashboard CSS and JS.

Copyright (c) 2026 opnsense-bandwidthd contributors. Licensed under the Apache
License, Version 2.0 — <https://www.apache.org/licenses/LICENSE-2.0>.

## `daemon/` — GNU GPL v3 or later

The BandwidthD daemon: the FreeBSD port recipe and the compiled binary in
`daemon/prebuilt/`.

Copyright (c) David Hinkle and contributors. Licensed GPLv3+ ("any version of
the GPL that is current as of your download") — full text at
`daemon/prebuilt/usr/local/share/licenses/bandwidthd-*/GPLv3+`.

## Vendored third-party code

Chart.js (`plugin/src/www/bandwidthd_ui/vendor/`) is MIT-licensed; see
`CHARTJS-VERSION.txt` beside it.

## `scripts/`, `docs/`, manifest templates

The packaging and automation glue is offered under Apache-2.0, matching the
plugin it serves.
