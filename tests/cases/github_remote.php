<?php
/* publish-repo.sh's GitHub-remote detection is shell (scripts/lib/github-remote.sh),
 * tested by tests/github_remote.sh. Run it from here so the suite everyone runs
 * covers it; on its own it was a test nothing invoked. */
t_group('github_remote');

$script = dirname(__DIR__) . '/github_remote.sh';
if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
	t_ok(false, 'git is required to test publish-repo remote detection');
	return;
}
/* Own names, initialised: every case shares one global scope, and exec()
 * appends to an existing array — another case's $out would leak in. */
$ghOut = array(); $ghRc = -1;
exec('sh ' . escapeshellarg($script) . ' 2>&1', $ghOut, $ghRc);
$tail = $ghOut ? (string) end($ghOut) : '(no output)';
t_eq(0, $ghRc, 'tests/github_remote.sh exits 0 — ' . implode(' | ', $ghOut));
// A script that dies before its first check also exits non-zero, but require
// the count as well: "0 passed" would mean nothing was exercised.
t_ok(preg_match('/^(\d+) passed, 0 failed$/', $tail, $ghM) === 1 && (int) $ghM[1] > 0,
	'remote-detection checks ran and none failed — ' . $tail);
