#!/bin/sh
#
# build-repo.sh - build both packages and lay them out as a signed pkg(8)
# repository, ready for publish-repo.sh to push to GitHub Pages.
#
# Must run on FreeBSD with a matching pkg ABI — in practice the OPNsense box.
# Needs the repository signing key (default /root/bandwidthd-repo.key, override
# with REPO_SIGNING_KEY). The key's fingerprint must match the one committed in
# repo/fingerprints/trusted/bandwidthd, or clients will refuse the repository.
#
# Output: dist/repo/<ABI>/{meta.conf,packagesite.pkg,...,All/*.pkg}
#
set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
export REPO_ROOT
# shellcheck source=lib/common.sh
. "${SCRIPT_DIR}/lib/common.sh"

: "${REPO_SIGNING_KEY:=/root/bandwidthd-repo.key}"

require_freebsd_pkg
[ -r "${REPO_SIGNING_KEY}" ] || die "signing key not readable: ${REPO_SIGNING_KEY} (see docs/REPOSITORY.md)"

# The committed fingerprint is what every client trusts; refuse to sign with
# anything else, since the result would install nowhere.
key_fp="$(openssl rsa -in "${REPO_SIGNING_KEY}" -pubout 2>/dev/null | sha256 -q)"
committed_fp="$(awk '/^fingerprint:/ { print $2 }' "${REPO_ROOT}/repo/fingerprints/trusted/bandwidthd")"
[ "${key_fp}" = "${committed_fp}" ] || die "signing key fingerprint ${key_fp} does not match repo/fingerprints/trusted/bandwidthd (${committed_fp})"

# Fresh packages only: build-pkgs.sh does not clear dist/, and a stale version
# left beside the new one would be published too.
rm -f "${DIST_DIR}"/*.pkg
sh "${SCRIPT_DIR}/build-pkgs.sh"

ABI="$(detect_abi)"
OUT="${DIST_DIR}/repo/${ABI}"
rm -rf "${DIST_DIR}/repo"
mkdir -p "${OUT}/All"
cp "${DIST_DIR}"/*.pkg "${OUT}/All/"

log "signing repository catalogue for ${ABI}"
pkg repo "${OUT}" "rsa:${REPO_SIGNING_KEY}"

log "repository ready in ${OUT}:"
ls -la "${OUT}" "${OUT}/All"
