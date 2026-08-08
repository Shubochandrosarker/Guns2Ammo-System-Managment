#!/usr/bin/env bash
#
# check-plugin-sync.sh — verify the monorepo's embedded plugin copies match
# the standalone plugin repos.
#
# The monorepo carries byte-identical copies of two plugins that also live in
# standalone repos:
#   plugins/g2a-booking-engine/                → github.com/Shubochandrosarker/g2a-booking-engine
#   plugins/memberistic-membership-solutions/  → github.com/Shubochandrosarker/memberistic-membership-solutions
#
# Usage:
#   bin/check-plugin-sync.sh [G2A_CHECKOUT] [MEMBERISTIC_CHECKOUT]
#
# With no arguments it compares against sibling checkouts next to the
# monorepo checkout (../g2a-booking-engine and
# ../memberistic-membership-solutions) when they exist — the layout used
# locally and by the release process. Missing checkouts are skipped with a
# notice, never failed.
#
# Dev-tooling files that intentionally live only in the standalone repos
# (tests, phpunit/composer config, vendor) are excluded from the comparison,
# as are VCS/CI metadata files.
#
# Exit codes: 0 = in sync (or nothing to compare), 1 = trees diverged.

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

EXCLUDES=(
  --exclude=.git
  --exclude=.gitattributes
  --exclude=.gitignore
  --exclude=.github
  --exclude=tests
  --exclude=vendor
  --exclude=node_modules
  --exclude=composer.json
  --exclude=composer.lock
  --exclude=phpunit.xml
  --exclude=.phpunit.cache
)

fail=0
compared=0

compare_tree() {
  local name="$1" standalone="$2"

  if [ ! -d "$standalone" ]; then
    echo "SKIP: ${name} — no standalone checkout at ${standalone}"
    return 0
  fi
  if [ ! -d "${ROOT}/${name}" ]; then
    echo "FAIL: monorepo is missing the ${name}/ plugin directory"
    fail=1
    return 0
  fi

  compared=1
  echo "== ${ROOT}/${name}  <->  ${standalone}"

  local report
  report="$(mktemp)"
  if diff -r "${EXCLUDES[@]}" "${ROOT}/${name}" "${standalone}" > "$report" 2>&1; then
    echo "   OK: trees are identical"
  else
    fail=1
    echo "   DIVERGED — the following files differ or exist on only one side:"
    awk '
      /^diff /         { print "     differs:  " $(NF-1) }
      /^Only in /      { print "     " $0 }
      /^Binary files / { print "     " $0 }
    ' "$report"
    echo "   Sync the plugin copies before releasing (full diff: diff -r ${ROOT}/${name} ${standalone})."
  fi
  rm -f "$report"
}

compare_tree "plugins/g2a-booking-engine"               "${1:-${ROOT}/../g2a-booking-engine}"
compare_tree "plugins/memberistic-membership-solutions" "${2:-${ROOT}/../memberistic-membership-solutions}"

if [ "$compared" -eq 0 ]; then
  echo "Nothing compared (no standalone checkouts found) — treating as success."
fi

if [ "$fail" -ne 0 ]; then
  echo
  echo "RESULT: plugin trees have DIVERGED between the monorepo and the standalone repos."
  exit 1
fi

echo "RESULT: plugin trees are in sync."
exit 0
