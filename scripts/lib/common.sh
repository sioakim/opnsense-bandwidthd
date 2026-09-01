# shellcheck shell=sh
#
# common.sh - shared helpers for the opnsense-bandwidthd build scripts.
# POSIX sh. Runs only on a FreeBSD host with pkg(8) whose ABI matches the target
# — in practice the OPNsense box itself.

# ---- Version / identity knobs (override via environment) --------------------
: "${PLUGIN_VERSION:=1.0.1}"           # os-bandwidthd version
: "${BWD_VERSION:=2.0.1_12}"           # bandwidthd (daemon) version
: "${LIBGD_VERSION:=2.3.3_13,1}"       # graphics/gd dependency pin
: "${MAINTAINER:=spyros@ioakeim.com}"

# ---- Paths ------------------------------------------------------------------
: "${REPO_ROOT:?REPO_ROOT must be set before sourcing common.sh}"
DIST_DIR="${DIST_DIR:-${REPO_ROOT}/dist}"

# ---- Logging ----------------------------------------------------------------
log()  { printf '==> %s\n' "$*"; }
warn() { printf 'WARN: %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# ---- Preconditions ----------------------------------------------------------
require_freebsd_pkg() {
	[ "$(uname -s)" = "FreeBSD" ] || die "must run on FreeBSD (this is $(uname -s)). Packaging needs pkg(8)."
	command -v pkg >/dev/null 2>&1 || die "pkg(8) not found in PATH."
}

# Detect the running system's package ABI, e.g. FreeBSD:15:amd64
detect_abi() {
	if [ -n "${ABI:-}" ]; then printf '%s' "$ABI"; return; fi
	pkg config ABI 2>/dev/null || die "could not determine ABI via 'pkg config ABI'."
}

# ---- Manifest rendering -----------------------------------------------------
render_manifest() {
	_tpl="$1"; _out="$2"
	[ -f "$_tpl" ] || die "manifest template not found: $_tpl"
	_abi="$(detect_abi)"
	sed \
		-e "s|@@PLUGIN_VERSION@@|${PLUGIN_VERSION}|g" \
		-e "s|@@BWD_VERSION@@|${BWD_VERSION}|g" \
		-e "s|@@LIBGD_VERSION@@|${LIBGD_VERSION}|g" \
		-e "s|@@MAINTAINER@@|${MAINTAINER}|g" \
		-e "s|@@ABI@@|${_abi}|g" \
		"$_tpl" > "$_out"
}

# ---- Plist generation -------------------------------------------------------
# Walks the staged tree and emits an absolute-path plist. Everything under the
# stage root ships, so adding a font or a data file needs no manifest edit.
gen_plist() {
	_root="$1"; _out="$2"
	[ -d "$_root" ] || die "stage root not found: $_root"
	: > "$_out"
	( cd "$_root" && find . \( -type f -o -type l \) | sort ) | while IFS= read -r p; do
		printf '/%s\n' "${p#./}" >> "$_out"
	done
}

# ---- Package creation -------------------------------------------------------
make_package() {
	_root="$1"; _tpl="$2"; _outdir="$3"
	require_freebsd_pkg
	mkdir -p "$_outdir"
	_meta="$(mktemp -d)" || die "mktemp failed"
	render_manifest "$_tpl" "${_meta}/+MANIFEST"
	_plist="${_meta}/pkg-plist"
	gen_plist "$_root" "$_plist"
	log "creating package from ${_root} (abi=$(detect_abi))"
	pkg create -m "$_meta" -p "$_plist" -r "$_root" -o "$_outdir" || die "pkg create failed"
	rm -rf "$_meta"
}

# ---- Plugin staging ---------------------------------------------------------
# An OPNsense plugin's src/ tree is rooted at PREFIX, so src/opnsense/... becomes
# /usr/local/opnsense/... . Stage it that way and drop in the version marker the
# firmware tooling reads.
stage_plugin() {
	_src="$1"; _stage="$2"
	rm -rf "$_stage"
	mkdir -p "${_stage}/usr/local"
	cp -R "${_src}/." "${_stage}/usr/local/"
	mkdir -p "${_stage}/usr/local/opnsense/version"
	printf '%s\n' "${PLUGIN_VERSION}" > "${_stage}/usr/local/opnsense/version/bandwidthd"
	# Executable bits matter: rc script, configd-invoked scripts.
	chmod 755 "${_stage}/usr/local/etc/rc.d/bandwidthd" 2>/dev/null || true
	find "${_stage}/usr/local/opnsense/scripts/OPNsense/Bandwidthd" -maxdepth 1 -name '*.php' \
		-exec chmod 755 {} + 2>/dev/null || true
	# Strip anything the repo carries but the package must not ship.
	find "$_stage" -name '.DS_Store' -delete 2>/dev/null || true
}
