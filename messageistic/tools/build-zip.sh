#!/usr/bin/env bash
#
# Build an installable WordPress plugin ZIP.
#
# WordPress identifies a plugin by its folder name. GitHub's "Download ZIP"
# archives unpack as Messageistic-<branch>/, which WP Admin installs as a
# NEW plugin next to any existing messageistic/ install instead of
# upgrading it. This script packages the repo under the canonical
# messageistic/ folder so uploading the ZIP always replaces the previous
# version in place.
#
# Dev-only paths (tests, tools, CI config) are excluded via the
# export-ignore rules in .gitattributes.
#
# Usage:  bash tools/build-zip.sh [ref]   (default: HEAD)
# Output: dist/messageistic.zip

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ref="${1:-HEAD}"
version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$repo_root/messageistic.php" | head -1)"

mkdir -p "$repo_root/dist"
git -C "$repo_root" archive --format=zip --prefix=messageistic/ \
    -o "$repo_root/dist/messageistic.zip" "$ref"

echo "Built dist/messageistic.zip from ${ref} (version ${version:-unknown})"
