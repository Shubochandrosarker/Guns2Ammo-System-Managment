#!/usr/bin/env bash
# Rebuild every installable plugin/theme ZIP into dist/.
#
# Output: dist/<component>-<version>.zip, versioned from the component's own
# header (plugin "Version:" header, theme style.css). Each archive has the
# component directory at its ROOT, which is what WordPress requires:
#
#     g2a-booking-engine/g2a-booking-engine.php     correct
#     plugins/g2a-booking-engine/...                installs a "plugins" folder
#
# Builds are production-clean: development tooling is excluded, runtime
# dependencies are kept (g2a-pos-core needs vendor/ for FPDI/FPDF receipt PDFs;
# the booking engine needs assets/vendor/ for FullCalendar and qrcode). See
# dist/README.md.
#
# Usage: scripts/build-release-zips.sh [component ...]
#        With no arguments, builds everything.
set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

command -v zip >/dev/null || { echo "FATAL: 'zip' is not installed." >&2; exit 2; }

OUT="$ROOT/dist"
mkdir -p "$OUT"

# Development-only paths. Kept deliberately narrow: anything not listed here
# ships, so a new runtime directory is included by default rather than silently
# dropped from a release.
EXCLUDES=(
  '*/.git/*' '*/.github/*' '*/.DS_Store'
  '*/node_modules/*' '*/tests/*' '*/.phpunit.cache/*'
  '*/phpunit.xml' '*/phpunit.xml.dist'
  '*/composer.json' '*/composer.lock'
  '*/package.json' '*/package-lock.json'
  '*/.gitignore' '*/.gitattributes' '*/.editorconfig'
  '*/phpcs.xml' '*/phpcs.xml.dist' '*/.eslintrc*'
  # The booking engine has NO Composer runtime dependency — its root vendor/
  # only ever holds PHPUnit from `composer install`. It must not ship: the
  # PDF-invoice module require_once's G2AB_PATH . 'vendor/autoload.php' when
  # the file exists (includes/modules/pdf-invoices/class-invoice-engine.php),
  # so a dev install left on disk would load PHPUnit on a live site. Scoped to
  # this one component because POS genuinely ships vendor/ (FPDI/FPDF), and
  # deliberately NOT matching the booking engine's assets/vendor/, which holds
  # FullCalendar and qrcode and must keep shipping.
  'g2a-booking-engine/vendor/*'
)

# Read the version a component declares for itself.
#   plugin -> the "Version:" line in the file carrying "Plugin Name:"
#   theme  -> the "Version:" line in style.css
component_version() {
  local dir="$1" f v=''
  if [ -f "$dir/style.css" ] && grep -q '^[[:space:]]*Theme Name:' "$dir/style.css" 2>/dev/null; then
    v="$( grep -m1 -oP '^\s*Version:\s*\K[0-9][0-9a-zA-Z.\-]*' "$dir/style.css" || true )"
  else
    for f in "$dir"/*.php; do
      [ -f "$f" ] || continue
      grep -q '^[[:space:]]*\*[[:space:]]*Plugin Name:' "$f" || continue
      v="$( grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9][0-9a-zA-Z.\-]*' "$f" || true )"
      break
    done
  fi
  printf '%s' "$v"
}

build_one() {
  local path="$1"                 # e.g. plugins/g2a-booking-engine
  local parent name version zipfile
  parent="$( dirname "$path" )"   # plugins
  name="$( basename "$path" )"    # g2a-booking-engine

  version="$( component_version "$path" )"
  if [ -z "$version" ]; then
    echo "FAIL: $path declares no version — refusing to build an unversioned artifact." >&2
    return 1
  fi

  zipfile="$OUT/${name}-${version}.zip"

  # Remove first: `zip -r` adds and updates entries but never prunes, so
  # rebuilding over an existing archive keeps files that were deleted from the
  # source tree. A stale artifact carrying the right version string is
  # undetectable at install time.
  rm -f "$zipfile"

  # Zip from the PARENT directory so the archive root is the component folder.
  ( cd "$parent" && zip -rq "$zipfile" "$name" -x "${EXCLUDES[@]}" )

  # An archive whose root is not exactly the component directory will not
  # install. Verify rather than assume.
  local roots
  roots="$( unzip -Z1 "$zipfile" | cut -d/ -f1 | sort -u )"
  if [ "$roots" != "$name" ]; then
    echo "FAIL: $zipfile root is '$roots', expected '$name'." >&2
    return 1
  fi

  printf '  built  %-46s %s\n' "$(basename "$zipfile")" "$( du -h "$zipfile" | cut -f1 )"
}

targets=()
if [ "$#" -gt 0 ]; then
  for arg in "$@"; do targets+=( "$arg" ); done
else
  for d in plugins/*/; do targets+=( "${d%/}" ); done
  for d in themes/*/; do targets+=( "${d%/}" ); done
fi

fail=0
for t in "${targets[@]}"; do
  if [ ! -d "$t" ]; then
    echo "FAIL: $t does not exist." >&2
    fail=1
    continue
  fi
  build_one "$t" || fail=1
done

echo
if [ "$fail" -ne 0 ]; then
  echo "RESULT: one or more components failed to build."
  exit 1
fi
echo "Built $( ls -1 "$OUT"/*.zip | wc -l ) archives into dist/."
