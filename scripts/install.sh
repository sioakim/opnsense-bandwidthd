#!/bin/sh
#
# install.sh - install (or upgrade) the built packages on this OPNsense box.
#
# Run on the box, after build-pkgs.sh. Pass --upgrade to replace an existing
# install rather than refusing.
#
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
export REPO_ROOT
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

require_freebsd_pkg

UPGRADE=0
[ "${1:-}" = "--upgrade" ] && UPGRADE=1

DAEMON_PKG="$(ls -1t "${DIST_DIR}"/bandwidthd-*.pkg 2>/dev/null | head -1 || true)"
PLUGIN_PKG="$(ls -1t "${DIST_DIR}"/os-bandwidthd-*.pkg 2>/dev/null | head -1 || true)"
[ -n "${DAEMON_PKG}" ] || die "no bandwidthd package in ${DIST_DIR} — run build-pkgs.sh first."
[ -n "${PLUGIN_PKG}" ] || die "no os-bandwidthd package in ${DIST_DIR} — run build-pkgs.sh first."

# libgd is the daemon's only shared-library dependency and is NOT installed on a
# stock OPNsense box, so pull it in first or bandwidthd will not start.
if ! pkg info libgd >/dev/null 2>&1; then
	log "installing libgd (bandwidthd links against libgd.so.6)"
	pkg install -y libgd
fi

# NOT -A. That marks a package "automatic", and OPNsense's firmware tooling runs
# `pkg autoremove` on install, remove, reinstall, cleanup and sync — nothing
# depends on os-bandwidthd, so the next plugin the user installs from the GUI
# would silently take this one with it.
ADD_FLAGS=""
[ "${UPGRADE}" -eq 1 ] && ADD_FLAGS="-f"

log "installing ${DAEMON_PKG}"
pkg add ${ADD_FLAGS} "${DAEMON_PKG}" || die "daemon package install failed"
log "installing ${PLUGIN_PKG}"
pkg add ${ADD_FLAGS} "${PLUGIN_PKG}" || die "plugin package install failed"

# Clear the automatic flag explicitly: an upgrade over a previously -A install
# would otherwise inherit it.
pkg set -y -A 0 bandwidthd os-bandwidthd >/dev/null 2>&1 || true

# Two caches have to be cleared, or the plugin installs correctly and is still
# invisible: configd caches its action list, and the GUI reads its menu and ACL
# from /var/lib/php/tmp/opnsense_menu_cache.xml, which installing files does NOT
# invalidate. 'pluginctl -c cache_flush' clears the menu, ACL and model caches.
# (There is no php-fpm on OPNsense — the GUI is lighttpd + php-cgi — so the PHP
# workers are recycled by restarting the web GUI, not a php-fpm service.)
log "reloading configd, flushing GUI caches, regenerating cron"
/usr/local/etc/rc.d/configd restart >/dev/null 2>&1 || true
/usr/local/sbin/pluginctl -c cache_flush >/dev/null 2>&1 || true
# system_cron_configure() is what writes the crontab. plugins_configure('cron')
# looks right and does nothing — 'cron' is not a registered plugin hook — and it
# needs the full legacy bootstrap besides, or it fatals inside core and `|| true`
# hides that too.
/usr/local/bin/php -r 'require_once("config.inc"); require_once("util.inc"); require_once("legacy_bindings.inc"); require_once("system.inc"); require_once("interfaces.inc"); require_once("plugins.inc"); system_cron_configure();' >/dev/null 2>&1 || true
# Render the service template now. It is otherwise only written on a settings
# save, so a box installed-and-rebooted before the first Save would come up
# with no /etc/rc.conf.d/bandwidthd and the daemon disabled at boot.
/usr/local/sbin/configctl template reload OPNsense/Bandwidthd >/dev/null 2>&1 || true
/usr/local/sbin/configctl webgui restart >/dev/null 2>&1 || true

log "installed. Configure under Services -> BandwidthD, view under Reporting -> BandwidthD."
