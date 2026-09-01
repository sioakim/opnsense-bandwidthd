#!/bin/sh
#
# refresh-theme.sh - fetch OPNsense's own theme stylesheets for the harness, so it
# renders the dashboard exactly as the GUI does (light and dark).
#
# These are OPNsense's files, not this project's, so they are gitignored rather
# than vendored. Run once after cloning, or when the firewall is upgraded.
#
#   sh tests/harness/refresh-theme.sh host[:port]
#
set -eu
HOST="${1:?usage: sh tests/harness/refresh-theme.sh host[:port]}"
DIR="$(cd "$(dirname "$0")" && pwd)"

for t in opnsense:opnsense-main.css opnsense-dark:opnsense-dark.css; do
	theme="${t%%:*}"; out="${t##*:}"
	url="http://${HOST}/ui/themes/${theme}/build/css/main.css"
	# The GUI requires a login for most paths, but the theme CSS is a static asset.
	if curl -fsS --noproxy '*' -m 30 -o "${DIR}/${out}" "$url"; then
		printf 'fetched %-22s -> %s\n' "$theme" "$out"
	else
		printf 'FAILED  %-22s (%s)\n' "$theme" "$url" >&2
		exit 1
	fi
done
