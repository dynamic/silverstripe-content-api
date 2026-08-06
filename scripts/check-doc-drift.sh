#!/usr/bin/env bash
# Mirror of branch 1's scripts/check-doc-drift.sh (see issue #105), flipped
# for this direction: this catches SS5-isms leaking UP onto branch 2 (the
# SS6 line) — most likely via a merge from branch 1 that drags SS5-specific
# requirement/constraint text or invocation syntax into a shared doc/file,
# or a docblock hand-edited by copying from branch 1 without updating it for
# this branch. (This branch was renamed from `1`; the SS5 line was renamed
# from `ss5` to `1` — see issue #106. Before this script existed, branch 2
# went unswept after that rename and accumulated real "SS6 (branch `1`)"
# self-contradictions and a stale lowercase `ss5` reference — see #105's own
# fix commit.)
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

cd "$(dirname "$0")/.." || exit 1

# Prose forms ("SilverStripe ^5", "PHP ^8.1") and raw composer.json forms
# ("silverstripe/framework": "^5.2", "php": "^8.1") both count — a merge-up
# reverting the framework/PHP floor in composer.json wouldn't match the
# prose-only patterns at all (no space before the version constraint).
# colymba's own SS5 pin ("^5.0") and branch 1's SS5-line fork repository URL
# are checked the same way branch 1's script checks for ITS OWN SS6-only
# colymba branch (`dev-feature/cms-6-compatibility`) leaking the other way.
# `silverstripe/framework`/`cms` are checked against their SS5-line "^5"
# constraint; `versioned`/`asset-admin` version on their own schedule (SS5
# line: "^2.2"; this branch: "^3") rather than tracking the CMS major, so
# they're checked against their own real SS5-line value instead — a bare
# "^5" alternative for them would never match anything real (their SS5-line
# constraint isn't "^5"), silently covering nothing. `silverstripe/linkfield`
# and `silverstripe/recipe-testing` version independently of both, so
# they're deliberately left out — a broader `silverstripe/[a-z-]+": *"\^5`
# pattern false-positives on `silverstripe/linkfield": "^5"`, which is
# correct on this branch.
matches=$(grep -rniE 'SilverStripe.{0,8}\^5|PHP.{0,8}\^8\.1|"php": *"\^8\.1|"silverstripe/(framework|cms)": *"\^5|"silverstripe/(versioned|asset-admin)": *"\^2|"colymba/silverstripe-restfulapi": *"\^5|dynamic/silverstripe-restfulapi|sake dev/tasks/' \
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

# This branch's own prior name (a bare `1`, too common a token to grep for
# safely) can't be checked the way branch 1 checks for ITS old name `ss5` —
# but `ss5` itself is equally stale here: the SS5 line has been `1` since
# the same rename, so a lowercase `ss5` reference is wrong on either branch,
# and this is what #105 actually found most of (8 of 9 stale references were
# this, not the SS6/branch-`1` contradiction below). Case-sensitive and no
# `-i`, for the same reason branch 1's script is: a case-insensitive \bss5\b
# would collide with "SS5" the framework version, which is legitimate
# everywhere on this branch — the lowercase `ss5` branch-name token never
# legitimately appears with that casing.
stale_name=$(grep -rnE '\bss5\b' \
    README.md docs/ src/ tests/ composer.json .local-ci.json phpstan.neon.dist)
status=$?

if [ "$status" -eq 0 ]; then
    echo "$stale_name" >&2
    echo "doc-drift: found a stale \`ss5\` branch-name reference — that branch is now \`1\`" >&2
    exit 1
elif [ "$status" -gt 1 ]; then
    echo "doc-drift: grep itself failed (exit $status) — treating as a check failure, not a clean result" >&2
    exit "$status"
fi

# A genuine contradiction, not just a mention: SS6 content textually
# adjacent to branch `1` (which is the SS5 line) — the exact shape of every
# stale self-reference #105 found and fixed (e.g. "SS6 (branch `1`) entry
# point", "branch `1`'s SS6 entry point"). Every real instance found had SS6
# and `branch `1`` within ~15 characters of each other (a parenthetical or
# possessive), so the window here is deliberately short — long enough to
# catch that shape, short enough not to span into an unrelated clause on the
# same (possibly long, multi-clause) line.
#
# A line can legitimately mention both "SS6" and "branch `1`" close together
# without being a contradiction if branch `1` is correctly paired with its
# own version right there (e.g. "branch `2` (SS6) and branch `1` (SS5)").
# Checked positionally, not line-wide: the legitimate pairing text is
# stripped out of each candidate line first, and only the *remainder* is
# re-tested against the same SS6/branch-`1` pattern. A line-wide exclusion
# (grep -v on the whole line for a legitimate pairing appearing anywhere)
# was tried first and rejected: a line carrying both a legitimate pairing
# AND a genuine, separate contradiction would have the whole line waved
# through, masking the real one. The "SS5" half of the strip is
# deliberately case-SENSITIVE (unlike the rest of this check) for the same
# reason the stale-name check above is: a case-insensitive match would let
# the stale lowercase `ss5` branch-name token satisfy a strip meant for the
# framework version "SS5", silently suppressing a real contradiction that
# happens to sit near a leftover `ss5` reference.
candidates=$(grep -rniE 'SS6.{0,15}branch .1.|branch .1.{0,15}SS6' README.md docs/ src/ tests/)
status=$?

if [ "$status" -gt 1 ]; then
    echo "doc-drift: grep itself failed (exit $status) — treating as a check failure, not a clean result" >&2
    exit "$status"
fi

contradiction=$(printf '%s\n' "$candidates" \
    | sed -E 's/[Bb]ranch .1.{0,15}\(?SS5/ /g; s/SS5.{0,15}[Bb]ranch .1./ /g' \
    | grep -iE 'SS6.{0,15}branch .1.|branch .1.{0,15}SS6')

if [ -n "$contradiction" ]; then
    echo "$contradiction" >&2
    echo "doc-drift: found SS6 content self-referentially attributed to branch \`1\` (the SS5 line) — this branch is \`2\`" >&2
    exit 1
fi

echo "doc-drift: clean"
exit 0
