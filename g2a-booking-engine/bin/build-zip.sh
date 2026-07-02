#!/usr/bin/env bash
#
# Build a distributable plugin zip.
# Uses `git archive` so files marked `export-ignore` in .gitattributes
# (docs, dev configs, this script) are stripped from the package.
#
# Usage:
#   bin/build-zip.sh                 -> dist/g2a-booking-engine-<version>.zip
#   bin/build-zip.sh /path/to/dir    -> writes the zip into the given dir
#
set -euo pipefail

PLUGIN_SLUG="g2a-booking-engine"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${1:-${ROOT_DIR}/dist}"

# Pull the version out of the plugin header. Single source of truth.
VERSION="$(grep -m1 -E '^\s*\*\s*Version:\s*' "${ROOT_DIR}/${PLUGIN_SLUG}.php" | sed -E 's/.*Version:\s*([0-9.]+).*/\1/')"
if [[ -z "${VERSION}" ]]; then
  echo "Could not detect plugin version from ${PLUGIN_SLUG}.php" >&2
  exit 1
fi

mkdir -p "${DIST_DIR}"

OUT_FILE="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

# `git archive` honors .gitattributes export-ignore rules, so docs/, bin/,
# and other dev-only paths are excluded. The --prefix wraps the contents in
# the plugin folder so it unzips correctly into wp-content/plugins/.
git -C "${ROOT_DIR}" archive \
  --format=zip \
  --prefix="${PLUGIN_SLUG}/" \
  --output="${OUT_FILE}" \
  HEAD

echo "Built ${OUT_FILE}"
