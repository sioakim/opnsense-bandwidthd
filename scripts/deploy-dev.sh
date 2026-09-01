#!/bin/sh
#
# deploy-dev.sh - fast iteration loop: sync the repo to the box and copy the
# changed files straight into their live paths, skipping pkg build/install.
#
#   sh scripts/deploy-dev.sh user@host
#
# Use build-pkgs.sh + install.sh instead when validating packaging itself
# (plist contents, post-install hooks, a clean-machine install).
#
set -eu

HOST="${1:?usage: sh scripts/deploy-dev.sh user@host}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# macOS tar writes AppleDouble/xattr entries that FreeBSD's bsdtar aborts on.
COPYFILE_DISABLE=1 tar czf - -C "${REPO_ROOT}" \
	--exclude='.git' --exclude='dist' --exclude='.DS_Store' --exclude='._*' \
	. | ssh "${HOST}" 'rm -rf /root/opnsense-bandwidthd && mkdir -p /root/opnsense-bandwidthd && tar xzf - -C /root/opnsense-bandwidthd'

ssh "${HOST}" 'sh -s' <<'REMOTE'
set -eu
SRC=/root/opnsense-bandwidthd/plugin/src

# The plugin's src/ tree is rooted at /usr/local.
cp -R "$SRC/etc/." /usr/local/etc/
cp -R "$SRC/opnsense/." /usr/local/opnsense/
cp -R "$SRC/www/." /usr/local/www/
chmod 755 /usr/local/etc/rc.d/bandwidthd
chmod 755 /usr/local/opnsense/scripts/OPNsense/Bandwidthd/*.php

# configd caches its action list, and the GUI reads its menu and ACL from a cache
# that copying files does not invalidate. There is no php-fpm on OPNsense (the
# GUI is lighttpd + php-cgi), so PHP workers recycle via a webgui restart.
/usr/local/etc/rc.d/configd restart >/dev/null 2>&1 || true
/usr/local/sbin/pluginctl -c cache_flush >/dev/null 2>&1 || true
/usr/local/sbin/configctl webgui restart >/dev/null 2>&1 || true
echo "deployed"
REMOTE
