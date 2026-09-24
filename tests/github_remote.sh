#!/bin/sh
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "${ROOT}/scripts/lib/github-remote.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' 0
# Isolate configuration; every git command is local.
GIT_CONFIG_NOSYSTEM=1
GIT_CONFIG_GLOBAL=/dev/null
export GIT_CONFIG_NOSYSTEM GIT_CONFIG_GLOBAL
unset GH_REMOTE
git init -q "${WORK}/repo"
passed=0
check() {
	actual="$(bwd_github_remote "${WORK}/repo")"
	if [ "$actual" != "$1" ]; then
		printf 'FAIL: %s (expected %s, got %s)\n' "$2" "$1" "$actual" >&2
		exit 1
	fi
	passed=$((passed + 1))
}
check github 'empty repo defaults to github'
git -C "${WORK}/repo" remote add origin https://git.example.net/x.git
check github 'no GitHub remote defaults to github'
for url in \
	https://git.example.net/mirrors/github.com/x.git \
	https://github.com@git.example.net/x.git \
	https://github.com.example.net/x.git \
	https://notgithub.com/x.git \
	ssh://git@git.example.net/mirrors/github.com/x.git \
	git@git.example.net:github.com/x.git
do
	git -C "${WORK}/repo" remote set-url origin "$url"
	check github "reject non-GitHub host: $url"
done
for url in \
	https://github.com/x/y.git \
	https://user@github.com/x/y.git \
	ssh://github.com/x/y.git \
	ssh://git@github.com/x/y.git \
	ssh://github.com:2222/x/y.git \
	ssh://git@github.com:2222/x/y.git \
	github.com:x/y.git \
	git@github.com:x/y.git
do
	git -C "${WORK}/repo" remote set-url origin "$url"
	check origin "accept GitHub host: $url"
done
git -C "${WORK}/repo" remote add aaa https://github.com/first/repo.git
check aaa 'first host match wins'
git -C "${WORK}/repo" remote add github https://git.example.net/named.git
check github 'named github remote precedes host matches'
GH_REMOTE=explicit
check explicit 'explicit GH_REMOTE precedes named github and host matches'
GH_REMOTE=
check github 'empty GH_REMOTE retains auto-detection'
printf '%s passed, 0 failed\n' "$passed"
