#!/usr/bin/env bash
# PHP syntax lint for every plugin/theme file in the monorepo.
#
# This is the same check the "PHP syntax lint (all plugins)" job in
# .github/workflows/php.yml runs, extracted so it can be run locally or from a
# git hook. That matters because the repo is private on a personal account:
# when Actions minutes run out, the hosted job stops running entirely and this
# script is the only thing still guarding the tree.
#
# Usage:
#   ./scripts/lint-php.sh            # lint the whole repo
#   ./scripts/lint-php.sh --staged   # lint only staged .php files (fast, for hooks)
#
# Exit status: 0 = clean, 1 = at least one syntax error, 2 = no PHP CLI found.

set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

if ! command -v php >/dev/null 2>&1; then
  echo "error: no php CLI on PATH — install PHP 8.x to run this lint." >&2
  exit 2
fi

# Match the workflow's parallelism so local timings mirror CI.
JOBS=4
LIST="$( mktemp )"
LOG="$( mktemp )"
trap 'rm -f "$LIST" "$LOG"' EXIT

if [ "${1:-}" = "--staged" ]; then
  # Only files staged for commit, still present on disk (skip deletions).
  git diff --cached --name-only --diff-filter=ACMR -z -- '*.php' > "$LIST"
else
  find . -name '*.php' \
    -not -path './.git/*' \
    -not -path '*/vendor/*' \
    -not -path '*/node_modules/*' \
    -print0 > "$LIST"
fi

TOTAL="$( tr -dc '\0' < "$LIST" | wc -c | tr -d ' ' )"

if [ "$TOTAL" -eq 0 ]; then
  echo "No PHP files to lint."
  exit 0
fi

# `|| true` inside the worker is deliberate: php -l exits 255 on a parse error,
# which makes xargs abort the whole run on the FIRST bad file. Swallowing it
# per-file lets every file get linted, so one run reports every error instead of
# forcing a fix-and-rerun cycle. Success is decided from the log below.
xargs -0 -r -n1 -P"$JOBS" sh -c 'php -l "$1" 2>&1 || true' _ < "$LIST" > "$LOG" 2>&1

CLEAN="$( grep -c '^No syntax errors' "$LOG" || true )"

if [ "$CLEAN" -ne "$TOTAL" ]; then
  echo "PHP syntax errors detected ($(( TOTAL - CLEAN )) of ${TOTAL} files):" >&2
  grep -v '^No syntax errors' "$LOG" >&2 || true
  exit 1
fi

echo "Linted ${CLEAN} PHP files — no syntax errors."
