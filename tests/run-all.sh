#!/usr/bin/env bash
# Every PHP-level suite: the mock harness + every tests/*.php. Same command in
# CI and locally. Exit 0 = all passed.
set -uo pipefail
cd "$(dirname "$0")/.."
fail=0
echo "== harness ($(php -r 'echo PHP_VERSION;')) =="
php test-harness.php > /tmp/ct-harness.log 2>&1 && tail -1 /tmp/ct-harness.log || { cat /tmp/ct-harness.log; fail=$((fail+1)); }
n=0
for t in tests/*.php; do
	n=$((n+1))
	if out=$(php "$t" 2>&1); then
		echo "ok   $t"
	else
		fail=$((fail+1)); echo "FAIL $t"; echo "$out" | grep -E "FAIL|Fatal|Parse error|Warning" | head -8 | sed 's/^/     /'
	fi
done
echo "suites: $n wired + harness, failures: $fail"
[ "$fail" = 0 ]
