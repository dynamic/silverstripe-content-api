#!/usr/bin/env bash
# Catches the class of drift that shipped once already on this branch: the
# branch-1 (SS5 line) README/docs advertising SS6 requirements or the wrong
# colymba fork branch, and code/docs using SS6's `sake tasks:` invocation
# syntax instead of SS5's `sake dev/tasks/`. See the README's "Branch
# policy" section. (This branch was renamed from `ss5`; the SS6 line was
# renamed from `1` to `2` — see issue #105.)
#
# Deliberately narrow patterns to avoid false-positiving on legitimate
# contrastive mentions of branch `2` (e.g. "branch `2` targets SilverStripe
# 6") — this checks for the *wrong* SS6 requirement/constraint shapes and the
# *wrong* task-invocation syntax, not any mention of SS6 or branch 2 at all.
#
# CHANGELOG.md is deliberately NOT checked: its historical release entries
# accurately describe what this branch's predecessor names (`1` pre-rename,
# then `ss5`) shipped at the time under their own then-current naming, and
# rewriting past releases to match current naming would misrepresent
# history.
set -uo pipefail

cd "$(dirname "$0")/.."

matches=$(grep -rniE 'SilverStripe .?\^6|PHP .?\^8\.3|dev-feature/cms-6-compatibility|sake tasks:' \
    README.md docs/ src/ composer.json)
status=$?

if [ "$status" -eq 0 ]; then
    echo "$matches" >&2
    echo "doc-drift: found SS6-era requirement text or sake tasks: syntax on the ss5 branch" >&2
    exit 1
elif [ "$status" -eq 1 ]; then
    echo "doc-drift: clean"
    exit 0
else
    echo "doc-drift: grep itself failed (exit $status) — treating as a check failure, not a clean result" >&2
    exit "$status"
fi
