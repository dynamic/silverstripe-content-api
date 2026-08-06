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
# CHANGELOG.md is deliberately NOT checked, including its `[Unreleased]`
# sections: every entry narrates a specific past decision or change under
# the branch names current *at the time it was written* (the SS6 line was
# `1` then, now `2`; this branch was `ss5` then, now `1`) — rewriting any of
# it, released or not, to match current naming would misrepresent history.
# Only the "[Unreleased] — ss5" section heading and its top process
# paragraph are exceptions (they assert an ongoing fact, not narrate a past
# event) — fixed by hand at rename time, not worth a dedicated check for
# two lines.
set -uo pipefail

cd "$(dirname "$0")/.."

matches=$(grep -rniE 'SilverStripe .?\^6|PHP .?\^8\.3|dev-feature/cms-6-compatibility|sake tasks:' \
    README.md docs/ src/ tests/ composer.json phpstan.neon.dist)
status=$?

if [ "$status" -eq 0 ]; then
    echo "$matches" >&2
    echo "doc-drift: found SS6-era requirement text or sake tasks: syntax on branch 1" >&2
    exit 1
elif [ "$status" -ne 1 ]; then
    echo "doc-drift: grep itself failed (exit $status) — treating as a check failure, not a clean result" >&2
    exit "$status"
fi

# This branch's own prior name — a leftover reference here means a rename
# (like the 2026-08 `ss5`->`1` / `1`->`2` split, issue #105) half-landed.
# CHANGELOG.md excluded — see comment above. Case-SENSITIVE and no `-i`:
# a case-insensitive \bss5\b collides with "SS5" the framework version,
# which is legitimate everywhere on this branch — the lowercase `ss5`
# branch-name token never legitimately appears with that casing.
stale_name=$(grep -rnE '\bss5\b' \
    README.md docs/ src/ tests/ composer.json .local-ci.json phpstan.neon.dist)
status=$?

if [ "$status" -eq 0 ]; then
    echo "$stale_name" >&2
    echo "doc-drift: found a stale \`ss5\` branch-name reference — this branch is now \`1\`" >&2
    exit 1
elif [ "$status" -eq 1 ]; then
    echo "doc-drift: clean"
    exit 0
else
    echo "doc-drift: grep itself failed (exit $status) — treating as a check failure, not a clean result" >&2
    exit "$status"
fi
