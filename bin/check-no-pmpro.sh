#!/usr/bin/env bash
#
# check-no-pmpro.sh — prove the whole system is free of Paid Memberships Pro.
#
# Memberistic is the single membership authority. No plugin, theme, POS
# adapter, admin bundle or helper may depend on PMPro at runtime.
#
# Scans every source file in the repo and fails on any PMPro occurrence
# outside a narrow, documented allowlist:
#
#   - Memberistic's CSV importer  — one-way migration of a legacy PMPro
#     member base INTO Memberistic. Reads an export; never calls PMPro.
#   - Two Memberistic comments explaining data carried over by that import.
#   - Documentation (docs/, *.md, *.html decks) and historical changelogs —
#     the removal record must be allowed to name what was removed.
#   - releases/*.zip — immutable archived build artifacts of past versions.
#
# Runtime PMPro symbols (function calls, constants, table names) are ALWAYS
# a failure, even inside allow-listed files.
#
# Usage:  bin/check-no-pmpro.sh
# Exit:   0 = clean, 1 = a PMPro dependency is present.

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

# Files permitted to MENTION PMPro (documentation / migration tooling only).
ALLOWLIST_RE='^(archives/|docs/|.*\.md$|.*\.html$|releases/|plugins/memberistic-membership-solutions/(includes/admin/class-import-page\.php|includes/database/class-migrations\.php|includes/database/class-memberships-repository\.php|CHANGELOG\.md)$|bin/check-no-pmpro\.sh$|\.github/workflows/)'

# Runtime symbols that are NEVER acceptable, anywhere.
RUNTIME_RE='pmpro_hasMembershipLevel|pmpro_getMembershipLevel|pmpro_getAllLevels|pmpro_changeMembershipLevel|PMPRO_VERSION|pmpro_memberships_users|pmpro_membership_levels|pmpro_after_checkout|pmpro_send_expiration_warning_email|pmproeewe_'

SOURCE_GLOBS=(--include='*.php' --include='*.js' --include='*.ts' --include='*.tsx' --include='*.jsx')
EXCLUDES=(--exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.phpunit.cache)

fail=0

# ── 1. Runtime PMPro symbols anywhere in source ──────────────────────────────
runtime_hits="$(grep -REn "$RUNTIME_RE" . "${SOURCE_GLOBS[@]}" "${EXCLUDES[@]}" 2>/dev/null || true)"
if [ -n "$runtime_hits" ]; then
  echo "FAIL: runtime Paid Memberships Pro symbols found (never allowed):"
  echo "$runtime_hits" | sed 's/^/  /'
  fail=1
fi

# ── 2. Any PMPro mention in source outside the allowlist ─────────────────────
mention_files="$(grep -Rli "pmpro\|paid-memberships-pro" . "${SOURCE_GLOBS[@]}" "${EXCLUDES[@]}" 2>/dev/null | sed 's|^\./||' || true)"
offenders=""
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if ! printf '%s' "$f" | grep -Eq "$ALLOWLIST_RE"; then
    offenders="${offenders}${f}"$'\n'
  fi
done <<< "$mention_files"

if [ -n "${offenders//[$'\n' ]/}" ]; then
  echo "FAIL: Paid Memberships Pro referenced in source outside the allowlist:"
  printf '%s' "$offenders" | sed 's/^/  /'
  echo ""
  echo "Memberistic is the single membership authority. If this is genuinely"
  echo "migration tooling or documentation, add it to ALLOWLIST_RE in"
  echo "bin/check-no-pmpro.sh with a comment explaining why."
  fail=1
fi

# ── 3. Allowlist hygiene: entries must still mention PMPro ───────────────────
# Stops the allowlist rotting into a blanket exemption after a file is cleaned.
for f in \
  plugins/memberistic-membership-solutions/includes/admin/class-import-page.php \
  plugins/memberistic-membership-solutions/includes/database/class-migrations.php \
  plugins/memberistic-membership-solutions/includes/database/class-memberships-repository.php
do
  if [ -f "$f" ] && ! grep -qi "pmpro" "$f"; then
    echo "NOTE: $f no longer mentions PMPro — drop it from ALLOWLIST_RE."
  fi
done

if [ "$fail" -eq 0 ]; then
  echo "OK: no Paid Memberships Pro dependency anywhere in the system."
fi

exit "$fail"
