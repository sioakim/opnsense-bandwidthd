#!/bin/sh
#
# build-pkgs.sh - build both packages from the committed tree. No compiler
# required: the bandwidthd daemon ships prebuilt for FreeBSD 15 / amd64.
#
# Must run on FreeBSD with a matching pkg ABI — in practice the OPNsense box.
#
# Output: dist/bandwidthd-<ver>.pkg and dist/os-bandwidthd-<ver>.pkg
#
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
export REPO_ROOT
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

require_freebsd_pkg
log "ABI: $(detect_abi)"
log "versions: bandwidthd=${BWD_VERSION} plugin=${PLUGIN_VERSION} libgd=${LIBGD_VERSION}"

# 1) the capture daemon (prebuilt binary + rc script)
make_package \
	"${REPO_ROOT}/daemon/prebuilt" \
	"${REPO_ROOT}/daemon/manifest/MANIFEST.in.json" \
	"${DIST_DIR}"

# 2) the OPNsense plugin
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT
stage_plugin "${REPO_ROOT}/plugin/src" "${STAGE}"
make_package "${STAGE}" "${REPO_ROOT}/plugin/manifest/MANIFEST.in.json" "${DIST_DIR}"

log "done. Packages in ${DIST_DIR}:"
ls -la "${DIST_DIR}"/*.pkg
