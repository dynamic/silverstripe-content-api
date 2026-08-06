#!/usr/bin/env bash
# Mirror of branch 1's scripts/check-doc-drift.sh (see issue #105), flipped
# for this direction: this catches SS5-isms leaking UP onto branch 2 (the
# SS6 line) — most likely via a merge from branch 1 that drags SS5-specific
# requirement/constraint text or invocation syntax into a shared doc/file,
# or a docblock hand-edited by copying from branch 1 without updating it for
# this branch. (This branch was renamed from `1`; the SS5 line was renamed
# from `ss5` to `1` — see issue #106. Before this script existed, branch 2
# went unswept after that rename and accumulated real "SS6 (branch `1`)"
# self-contradictions — see #105's own fix commit.)
#
# Deliberately narrow patterns to avoid false-positiving on legitimate
# contrastive mentions of branch `1` (e.g. "branch `1`'s legacy adapter
# calls this directly too") — this checks for the *wrong* SS5 requirement/
# constraint shapes, the *wrong* task-invocation syntax, and SS6 text
# self-contradictorily attributed to branch `1`, not any mention of SS5 or
# branch 1 at all.
#
# CHANGELOG.md is deliberately NOT checked — see branch 1's script for the
# full rationale (every entry narrates a specific past decision under the
# branch names current *at the time it was written*; rewriting any of it
# would misrepresent history).
set -uo pipefail

cd "$(dirname "$0")/.."

matches=$(grep -rniE 'SilverStripe .?\^5|PHP .?\^8\.1|sake dev/tasks/' \
    README.md docs/ src/ tests/ composer.json phpstan.neon.dist)
status=$?

if [ "$status" -eq 0 ]; then
    echo "$matches" >&2
    echo "doc-drift: found SS5-era requirement text or sake dev/tasks/ syntax on branch 2" >&2
    exit 1
elif [ "$status" -ne 1 ]; then
    echo "doc-drift: grep itself failed (exit $status) — treating as a check failure, not a clean result" >&2
    exit "$status"
fi

# A genuine contradiction, not just a mention: SS6 content attributed to
# branch `1` (which is the SS5 line) within the same line, with no
# offsetting mention of branch `2` on that same line — the exact shape of
# every stale self-reference #105 found and fixed (e.g. "SS6 (branch `1`)
# entry point"). A line that properly attributes SS6 to branch `2` and SS5
# to branch `1` in the same breath (e.g. "both branch `2` (SS6) and branch
# `1` (SS5)") is a legitimate dual-branch mention, not a contradiction — the
# `branch \`2\`` exclusion below exists specifically to not flag that shape.
candidates=$(grep -rniE 'SS6.{0,40}branch .1.|branch .1.{0,40}SS6' README.md docs/ src/ tests/)
status=$?

if [ "$status" -gt 1 ]; then
    echo "doc-drift: grep itself failed (exit $status) — treating as a check failure, not a clean result" >&2
    exit "$status"
fi

contradiction=$(printf '%s\n' "$candidates" | grep -viE 'branch .2.')

if [ -n "$contradiction" ]; then
    echo "$contradiction" >&2
    echo "doc-drift: found SS6 content self-referentially attributed to branch \`1\` (the SS5 line) — this branch is \`2\`" >&2
    exit 1
fi

echo "doc-drift: clean"
exit 0
