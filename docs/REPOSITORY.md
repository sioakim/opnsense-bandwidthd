# The package repository

Releases are distributed as a signed `pkg(8)` repository served from GitHub Pages
at `https://sioakim.github.io/opnsense-bandwidthd/`. Users add it once (the
`install-repo.sh` one-liner in `INSTALL.md`); after that `os-bandwidthd` shows up
in *System → Firmware → Plugins* like any other plugin and upgrades through the
normal firmware flow.

## Layout

The `gh-pages` branch is the published site. `publish-repo.sh` regenerates it
wholesale on every release:

```
index.html                      landing page with the install instructions
install-repo.sh                 one-shot client setup (from repo/)
bandwidthd.conf                 pkg repo config the client installs (from repo/)
fingerprints/trusted/bandwidthd the signing fingerprint the client trusts (from repo/)
FreeBSD:15:amd64/               one pkg repository per ABI
  meta.conf, packagesite.pkg…   catalogue, signed
  All/os-bandwidthd-<ver>.pkg
  All/bandwidthd-<ver>.pkg
.nojekyll                       so Pages serves every file verbatim
```

`bandwidthd.conf` uses `${ABI}` in its URL, so a client fetches the directory for
its own ABI. Only `FreeBSD:15:amd64` is built today. **A major OPNsense upgrade
changes the ABI** and the repository 404s for that client until a build for the
new ABI is published — `pkg update` then reports the `bandwidthd` repository as
unavailable, which is harmless but visible.

## Signing

`pkg repo` signs the catalogue with an RSA key through an external
`signing_command` (an `openssl dgst -sign` helper that `build-repo.sh` writes
to a temp file); clients verify it against a fingerprint file (`function: sha256` / `fingerprint: <sha256 of the public key>`)
under `/usr/local/etc/pkg/fingerprints/bandwidthd/trusted/`. `signature_type:
"fingerprints"` in the repo config makes that verification mandatory.

Committed, public: `repo/bandwidthd.pub` and `repo/fingerprints/trusted/bandwidthd`.
`tests/check_plugin.php` asserts the fingerprint is the sha256 of that public key.

⚠ Not `pkg repo <dir> rsa:<key>`. That is pkg's *internal* signer: it reports
success and writes a `signature` member, but a `fingerprints` client ignores that
member and reports `No signature found`, then refuses the repository. Fingerprint
clients read `data.sig` + `data.pub`, which only the `signing_command:` path
produces. The two are silent about each other; `build-repo.sh` checks for
`data.sig` after signing for exactly this reason.

**Private, never committed:** the signing key. It lives only on the build box
(`REPO_SIGNING_KEY`, default `/root/bandwidthd-repo.key`, mode 600) and in the
maintainer's password manager. `build-repo.sh` refuses to sign with a key whose
fingerprint differs from the committed one.

### Rotating the key

Every client has the old fingerprint installed, so rotation is a client-visible
event — do it only if the key is compromised.

1. `openssl genrsa -out bandwidthd-repo.key 4096`, install it on the build box and
   in the password manager.
2. `openssl rsa -in bandwidthd-repo.key -pubout > repo/bandwidthd.pub`, then
   rewrite `repo/fingerprints/trusted/bandwidthd` with the new sha256 (the test
   suite checks it).
3. Rebuild and publish. Announce that clients must re-run `install-repo.sh` (it
   overwrites the fingerprint file); until they do, `pkg update` fails signature
   verification for this repository.

## Releasing

```sh
# on the box (FreeBSD; the only place pkg(8) can build for the target ABI)
sh scripts/build-repo.sh          # -> dist/repo/<ABI>/, signed

# on the workstation
sh scripts/publish-repo.sh root@fw
```

`publish-repo.sh` copies `dist/repo` off the box, checks out `gh-pages` in a
temporary worktree, replaces its contents, commits and pushes to the `github`
remote, and verifies the pushed ref. GitHub Pages picks the change up within a
few minutes.

First-time setup: the GitHub repository needs Pages enabled with source
`gh-pages` / root. Everything else is created by the script.

## Client-side notes

- Packages installed by hand (`scripts/install.sh`) before the repository existed
  show `unknown-repository` in `pkg query %R`. They re-associate on the next
  upgrade, or immediately with `pkg install -f os-bandwidthd bandwidthd` once the
  repository is configured.
- `pkg` follows the OPNsense repository's `priority: 11`; this repository leaves
  priority at the default, and the two carry disjoint packages, so nothing shadows
  anything.
