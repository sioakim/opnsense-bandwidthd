# Building

Both packages are built with `pkg create` from a staged tree. **This only works
on FreeBSD with a matching ABI** — in practice the OPNsense box itself.

```sh
pkg config ABI      # must match the target, e.g. FreeBSD:15:amd64
```

You cannot build on macOS or Linux. On Apple silicon a FreeBSD VM is arm64 and
would produce a wrong-architecture package.

## Build

```sh
sh scripts/build-pkgs.sh
```

Produces `dist/bandwidthd-<ver>.pkg` and `dist/os-bandwidthd-<ver>.pkg`.

No compiler is needed: the `bandwidthd` daemon ships prebuilt for FreeBSD 15 /
amd64 under `daemon/prebuilt`. It links `libpcap.so.8` (base) and `libgd.so.6`
(the `libgd` package, which a stock OPNsense box does **not** have — the package
declares the dependency and `install.sh` installs it).

Identity and versions come from the environment:

```sh
MAINTAINER=you@example.com PLUGIN_VERSION=1.0.1 sh scripts/build-pkgs.sh
```

## Rebuilding the daemon from source

`daemon/freebsd-port/` holds the FreeBSD port recipe (Makefile, patches, rc
template) for BandwidthD 2.0.1 — the upstream `net-mgmt/bandwidthd` port, which
is no longer in the OPNsense repo. To rebuild rather than trust the committed
binary you need a FreeBSD 15 amd64 host with a compiler and `graphics/gd`.

## Install

```sh
sh scripts/install.sh            # first install
sh scripts/install.sh --upgrade  # replace an existing install
```

This installs `libgd` if missing, adds both packages, then restarts configd (to
pick up the new actions), flushes the GUI's menu/ACL cache, renders the service
template, regenerates cron, and restarts the web GUI so the PHP workers pick up
the new controllers. (There is no php-fpm on OPNsense — the GUI is lighttpd +
php-cgi.)

## Iterating during development

`scripts/deploy-dev.sh user@host` syncs the tree and copies the changed files
straight into their live paths, skipping the package build entirely. Use the full
build + install when validating packaging itself: plist contents, the post-install
hooks, a clean-machine install.

Note that the plugin's `src/` tree is rooted at `PREFIX`, so `src/opnsense/…`
installs to `/usr/local/opnsense/…`. `gen_plist` walks the whole staged tree, so
anything you add under `src/` ships automatically — and anything you leave at the
top of a stage root would install at `/`.

## Checks

Off-box, with no firewall, database or network:

```sh
php tests/run.php           # data-layer unit tests
php tests/check_plugin.php  # plugin structure: model/form/API/cron contracts
php -l <file>               # PHP syntax
sh -n <script>              # shell syntax
xmllint --noout <file>      # XML
node --check plugin/src/www/bandwidthd_ui/js/bandwidthd.js
```

And in a browser, with no firewall behind it — the only check that catches a
runtime JS error or a broken render:

```sh
python3 -m http.server 8899   # from the repository root
open http://127.0.0.1:8899/tests/harness/dashboard.html
```

## Releasing to the package repository

Releases are a signed `pkg(8)` repository on GitHub Pages; users add it once and
then install and upgrade through the normal firmware flow. Two steps:

```sh
# on the box, from a synced copy of the tree (scripts/deploy-dev.sh does the sync)
sh scripts/build-repo.sh                 # builds both packages, signs the catalogue

# on the workstation
sh scripts/publish-repo.sh root@fw       # fetches dist/repo, pushes the gh-pages branch
```

Bump `PLUGIN_VERSION` (in `scripts/lib/common.sh`, or via the environment) before
building. `build-repo.sh` refuses to sign with a key whose fingerprint differs
from the committed `repo/fingerprints/trusted/bandwidthd`, because clients would
reject the result. Key custody, first-time setup and rotation: `REPOSITORY.md`.
