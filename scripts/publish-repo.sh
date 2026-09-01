#!/bin/sh
#
# publish-repo.sh - fetch the signed repository built on the box by
# build-repo.sh and publish it to GitHub Pages (the gh-pages branch), together
# with the repository config, fingerprint and installer under repo/.
#
#   sh scripts/publish-repo.sh user@host
#
# Runs on the workstation. Environment: REMOTE_DIR (checkout on the box,
# default /root/opnsense-bandwidthd), GH_REMOTE (git remote for GitHub,
# default github), SCP (copy command, default scp).
#
set -eu

HOST="${1:?usage: sh scripts/publish-repo.sh user@host}"
REMOTE_DIR="${REMOTE_DIR:-/root/opnsense-bandwidthd}"
SCP="${SCP:-scp}"               # e.g. SCP="sshpass -e scp" for a password-auth box
GH_REMOTE="${GH_REMOTE:-github}"
PAGES_BRANCH=gh-pages

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORK="$(mktemp -d)"
SITE="${WORK}/site"
cleanup() {
	git -C "${REPO_ROOT}" worktree remove --force "${SITE}" 2>/dev/null || true
	rm -rf "${WORK}"
}
trap cleanup EXIT

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

git -C "${REPO_ROOT}" remote get-url "${GH_REMOTE}" >/dev/null 2>&1 \
	|| die "no git remote '${GH_REMOTE}' — add the GitHub remote first"

# 1. Fetch the built repository: one directory per ABI.
log "fetching dist/repo from ${HOST}:${REMOTE_DIR}"
mkdir -p "${WORK}/repo"
${SCP} -rq "${HOST}:${REMOTE_DIR}/dist/repo/." "${WORK}/repo/"
ABIS="$(ls "${WORK}/repo")"
[ -n "${ABIS}" ] || die "nothing under ${REMOTE_DIR}/dist/repo on the box — run build-repo.sh there first"
for abi in ${ABIS}; do
	[ -f "${WORK}/repo/${abi}/packagesite.pkg" ] || die "${abi}: no packagesite.pkg — not a pkg repo"
done
PLUGIN_PKG="$(ls "${WORK}/repo"/*/All/os-bandwidthd-*.pkg | head -1)"
VERSION="$(basename "${PLUGIN_PKG}" .pkg | sed 's/^os-bandwidthd-//')"

# 2. Check out gh-pages in a worktree (create it as an orphan the first time).
git -C "${REPO_ROOT}" fetch -q "${GH_REMOTE}" "${PAGES_BRANCH}" 2>/dev/null || true
if git -C "${REPO_ROOT}" show-ref --verify --quiet "refs/remotes/${GH_REMOTE}/${PAGES_BRANCH}"; then
	git -C "${REPO_ROOT}" worktree add -q --detach "${SITE}" "${GH_REMOTE}/${PAGES_BRANCH}"
else
	log "no ${PAGES_BRANCH} branch on ${GH_REMOTE} yet — creating it"
	git -C "${REPO_ROOT}" worktree add -q --detach "${SITE}"
	git -C "${SITE}" checkout -q --orphan "${PAGES_BRANCH}"
	git -C "${SITE}" rm -rfq . 2>/dev/null || true
fi

# 3. Replace the site contents wholesale: the ABI directories plus repo/.
find "${SITE}" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R "${WORK}/repo/." "${SITE}/"
cp "${REPO_ROOT}/repo/bandwidthd.conf" "${REPO_ROOT}/repo/install-repo.sh" "${REPO_ROOT}/repo/index.html" "${SITE}/"
mkdir -p "${SITE}/fingerprints/trusted"
cp "${REPO_ROOT}/repo/fingerprints/trusted/bandwidthd" "${SITE}/fingerprints/trusted/"
: > "${SITE}/.nojekyll"   # serve every file as-is, including dot-files and ${ABI} paths

# 4. Commit and push.
git -C "${SITE}" add -A
if git -C "${SITE}" diff --cached --quiet; then
	log "site unchanged — nothing to publish"
	exit 0
fi
git -C "${SITE}" commit -q -m "Publish os-bandwidthd ${VERSION} ($(echo ${ABIS} | tr ' ' ','))"
git -C "${SITE}" push -q "${GH_REMOTE}" "HEAD:refs/heads/${PAGES_BRANCH}"

# A quiet push hides a rejection; compare the refs explicitly.
local_head="$(git -C "${SITE}" rev-parse HEAD)"
remote_head="$(git -C "${REPO_ROOT}" ls-remote "${GH_REMOTE}" "refs/heads/${PAGES_BRANCH}" | cut -f1)"
[ "${local_head}" = "${remote_head}" ] || die "push did not land: local ${local_head} vs remote ${remote_head}"
log "published ${VERSION} for ${ABIS} as ${GH_REMOTE}/${PAGES_BRANCH} ${local_head}"
