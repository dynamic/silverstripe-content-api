#!/usr/bin/env bash
# Catches the class of drift that shipped once already on this branch: the
# ss5 README/docs advertising SS6 requirements or the wrong colymba fork
# branch, and code/docs using SS6's `sake tasks:` invocation syntax instead
# of SS5's `sake dev/tasks/`. See the README's "Branch policy" section.
#
# Deliberately narrow patterns to avoid false-positiving on legitimate
# contrastive mentions of branch `1` (e.g. "branch `1` targets SilverStripe
# 6") — this checks for the *wrong* SS6 requirement/constraint shapes and the
# *wrong* task-invocation syntax, not any mention of SS6 or branch 1 at all.
set -euo pipefail

cd "$(dirname "$0")/.."

if grep -rnE 'SilverStripe .?\^6|PHP .?\^8\.3|dev-feature/cms-6-compatibility|sake tasks:' \
    README.md docs/ src/
then
    echo "doc-drift: found SS6-era requirement text or sake tasks: syntax on the ss5 branch" >&2
    exit 1
fi

echo "doc-drift: clean"
