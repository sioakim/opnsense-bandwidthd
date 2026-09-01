#!/bin/sh
#
# install-repo.sh - add the os-bandwidthd package repository to an OPNsense box.
#
#   fetch -o - https://sioakim.github.io/opnsense-bandwidthd/install-repo.sh | sh
#
# Installs the repository's signing fingerprint and its pkg(8) configuration,
# then refreshes the catalogue. Afterwards os-bandwidthd appears under
# System > Firmware > Plugins, or install it with `pkg install os-bandwidthd`.
#
set -eu

BASE="https://sioakim.github.io/opnsense-bandwidthd"
FP_DIR=/usr/local/etc/pkg/fingerprints/bandwidthd/trusted
REPO_CONF=/usr/local/etc/pkg/repos/bandwidthd.conf

[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 1; }

mkdir -p "$FP_DIR" /usr/local/etc/pkg/repos
fetch -qo "${FP_DIR}/bandwidthd" "${BASE}/fingerprints/trusted/bandwidthd"
fetch -qo "$REPO_CONF" "${BASE}/bandwidthd.conf"
pkg update -r bandwidthd

echo
echo "Repository 'bandwidthd' added. Available:"
pkg rquery -r bandwidthd '  %n-%v  %c' 2>/dev/null || true
echo
echo "Install with:  pkg install os-bandwidthd"
echo "or from the GUI: System > Firmware > Plugins."
