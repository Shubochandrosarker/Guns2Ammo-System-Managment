#!/usr/bin/env bash
# Rebuild all theme + plugin release zips from the tracked source dirs.
# Output: root-level zips (overwrite the existing ones) + a versioned theme
# archive under releases/ named from style.css's Version: header.
#
# Usage: ./scripts/build-release-zips.sh
set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

# Extract the theme's declared version from style.css for the release archive
# filename. Falls back to "dev" if the header isn't found.
theme_version="$( awk '/^Version:/ { print $2; exit }' guns2ammo/style.css 2>/dev/null || echo dev )"
theme_version="${theme_version:-dev}"

mkdir -p releases

echo "→ Packaging theme as guns2ammo (version $theme_version)"
# Remove the old archive first so files deleted from the source tree don't
# survive inside the zip (zip -r only adds/updates entries, never prunes).
rm -f "WPistic-Theme-For-G2A-Version-1.8.9.zip"
zip -rq "WPistic-Theme-For-G2A-Version-1.8.9.zip" guns2ammo \
    -x "*/.DS_Store" "*/.git/*" "*/node_modules/*"
cp "WPistic-Theme-For-G2A-Version-1.8.9.zip" \
    "releases/WPistic-Theme-For-G2A-Version-${theme_version}.zip"

for plugin in g2a-booking-engine g2a-theme-control memberistic-membership-solutions verifyistic messageistic g2a-pos-core advanced-ffl-checkout formistic; do
  if [ -d "$plugin" ]; then
    # Read the version WordPress itself will display, from the plugin header.
    version="$( grep -m1 -h '^ \* Version:' "$plugin"/*.php 2>/dev/null | sed 's/.*Version: *//' | tr -d ' \r' )"
    version="${version:-dev}"

    echo "→ Packaging $plugin ($version)"
    rm -f "${plugin}.zip"
    zip -rq "${plugin}.zip" "$plugin" -x "*/.DS_Store" "*/.git/*" "*/node_modules/*"

    # Also drop a versioned copy in releases/. Previously only the THEME got
    # one, so releases/ silently fell behind the source: g2a-booking-engine
    # sat at 1.9.9.19 while the source was on .20, and — worse — a
    # g2a-pos-core-3.4.0.zip existed whose contents predated several fixes
    # landed under that same version number. A stale artefact carrying the
    # right version string is undetectable at install time.
    cp "${plugin}.zip" "releases/${plugin}-${version}.zip"
  fi
done

echo
echo "Built:"
ls -la *.zip releases/
