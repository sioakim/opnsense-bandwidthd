#!/bin/sh
# Sourceable selection only: never fetches or publishes anything.
bwd_github_remote() {
	if [ -n "${GH_REMOTE:-}" ]; then
		printf '%s\n' "${GH_REMOTE}"
	elif git -C "$1" remote get-url github >/dev/null 2>&1; then
		printf '%s\n' github
	else
		git -C "$1" remote -v | awk '
			$2 ~ /^https:\/\/([^\/@]+@)?github\.com\// ||
			$2 ~ /^ssh:\/\/([^\/@]+@)?github\.com(:[0-9]+)?\// ||
			$2 ~ /^([^\/@:]+@)?github\.com:/ {
				print $1; found = 1; exit
			}
			END { if (!found) print "github" }
		'
	fi
}
