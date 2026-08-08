#!/usr/bin/env bash
#
# Assert that every plugin's "Version:" header matches its runtime version
# constant.
#
# Why this exists: the constant — not the header — is what activation writes
# into the options table for schema/version bookkeeping (e.g. G2ABA_VERSION ->
# g2aba_db_version). When the two drift, WordPress shows the new version on the
# Plugins screen while the database records the old one, so a version-gated
# upgrade routine either re-runs forever or never runs at all. That is silent:
# nothing errors, the numbers just disagree.
#
# The header is treated as authoritative because it is what WordPress and the
# release tooling read. A mismatch is reported as "bump the constant".
#
# Themes are skipped: they declare a version in style.css and have no runtime
# constant to disagree with.
#
# Usage: bin/check-plugin-versions.sh
# Exits non-zero on any mismatch.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

fail=0
checked=0

for dir in plugins/*/; do
  name="$(basename "$dir")"

  # The plugin's main file is the one carrying the "Plugin Name:" header.
  main=""
  for f in "$dir"*.php; do
    [ -f "$f" ] || continue
    if grep -q '^[[:space:]]*\*[[:space:]]*Plugin Name:' "$f"; then
      main="$f"
      break
    fi
  done

  if [ -z "$main" ]; then
    echo "WARN: $name has no file with a 'Plugin Name:' header — skipping."
    continue
  fi

  header="$(grep -m1 -oP '^\s*\*?\s*Version:\s*\K[0-9][0-9a-zA-Z.\-]*' "$main" || true)"
  constant="$(grep -m1 -oP "define\(\s*'[A-Z0-9_]*VERSION'\s*,\s*'\K[0-9][0-9a-zA-Z.\-]*" "$main" || true)"

  if [ -z "$header" ]; then
    echo "FAIL: $name — $main has no 'Version:' header."
    fail=1
    continue
  fi

  # Not every plugin defines a version constant. That is allowed; there is
  # simply nothing that can drift.
  if [ -z "$constant" ]; then
    printf '  ok   %-40s %s (no version constant)\n' "$name" "$header"
    checked=$((checked + 1))
    continue
  fi

  if [ "$header" != "$constant" ]; then
    echo "FAIL: $name — header says $header but the runtime constant says $constant."
    echo "      Fix: bump the constant in $main to '$header'."
    fail=1
    continue
  fi

  printf '  ok   %-40s %s\n' "$name" "$header"
  checked=$((checked + 1))
done

if [ "$fail" -ne 0 ]; then
  echo
  echo "RESULT: plugin version headers and runtime constants disagree."
  exit 1
fi

echo
echo "OK: $checked plugin versions consistent (header == runtime constant)."
