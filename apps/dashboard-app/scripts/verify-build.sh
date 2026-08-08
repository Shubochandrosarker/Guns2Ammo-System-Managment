#!/usr/bin/env bash
# Post-build safety checks for the app.guns2ammo.com bundle.
#
# Extracted from deploy/deploy.sh so CI runs the EXACT same gate the deploy
# runs. Previously these checks lived only inside deploy.sh, which meant a
# change that reintroduced source maps or pulled the dev fixtures into the
# bundle stayed green on every pull request and only blew up at deploy time,
# in front of whoever was shipping. Same reasoning as scripts/lint-php.sh.
#
# Usage:  ./scripts/verify-build.sh <dist-dir> <expected-api-base>
#
# Exits non-zero, loudly, on the first problem.

set -euo pipefail

DIST="${1:?usage: verify-build.sh <dist-dir> <expected-api-base>}"
API_BASE="${2:?usage: verify-build.sh <dist-dir> <expected-api-base>}"

die() { echo "FATAL: $*" >&2; exit 1; }
ok()  { echo "    ok  $*"; }

[ -d "$DIST" ] || die "$DIST does not exist — did the build run?"
[ -f "$DIST/index.html" ] || die "$DIST/index.html missing — build produced nothing"
ok "index.html present"

# Source maps hand an attacker the exact auth implementation (auth plan risk
# R6 / decision D7). vite.config.ts sets sourcemap:false; this proves it.
if compgen -G "$DIST"/**/*.map > /dev/null || compgen -G "$DIST"/assets/*.map > /dev/null; then
    die "source maps present in $DIST/ — aborting."
fi
ok "no source maps"

# Prove the dev fixtures were tree-shaken out, via a tripwire token that
# exists ONLY in src/mocks/data.ts (written as a globalThis assignment there
# so it survives minification whenever that module is reachable).
#
# Catches any STATIC import of the fixtures reaching the bundle — the way this
# realistically regresses. The existing dynamic import in api.ts sits behind a
# literal import.meta.env.DEV guard and a hard useMocks=false, so Rollup
# eliminates that path outright and it cannot leak.
#
# Deliberately NOT the old /g2a\.mocks|USE_MOCKS *= *(1|true)/ pattern: that
# matched env.ts's own "VITE_G2A_USE_MOCKS=1 is ignored in production builds"
# warning string, which is in every production bundle, so it aborted EVERY
# deploy while the mocks were in fact correctly absent.
if grep -RqF "G2A_MOCK_FIXTURES_PRESENT_DO_NOT_SHIP" "$DIST"/assets/*.js 2>/dev/null; then
    die "dev mock fixtures are present in the production bundle — aborting."
fi
ok "dev mock fixtures absent"

# The API base is compiled in at build time. If it did not make it into the
# bundle the app points somewhere else entirely, with no runtime error to
# reveal it.
if ! grep -rqF "$API_BASE" "$DIST"/assets/*.js; then
    die "built bundle does not contain the API base (${API_BASE}) — the build did not pick it up."
fi
ok "API base compiled in (${API_BASE})"

# The app is a private admin surface. index.html must carry the noindex
# directive and the build must emit robots.txt, or a crawler that reaches the
# host can list authenticated screens in a search result.
grep -q 'name="robots"' "$DIST/index.html" \
    || die "index.html is missing its <meta name=\"robots\"> directive."
grep -q 'noindex' "$DIST/index.html" \
    || die "index.html <meta name=\"robots\"> no longer says noindex."
[ -f "$DIST/robots.txt" ] || die "$DIST/robots.txt missing — public/robots.txt is not being emitted."
ok "noindex meta + robots.txt present"

# The CSP served by nginx is script-src 'self' with no 'unsafe-inline' and no
# hashes. An inline <script> in index.html would therefore be silently blocked
# by the browser and the app would not boot at all — a failure that only shows
# up in a browser, never in the build log. node is used for the check because
# it needs a negative lookahead, which grep -E does not have.
node -e '
const fs = require("fs");
const html = fs.readFileSync(process.argv[1], "utf8");
// A <script> tag with no src= attribute, containing anything but whitespace.
const inline = /<script(?![^>]*\ssrc=)[^>]*>\s*\S/i.test(html);
if (inline) {
  console.error("FATAL: index.html contains an inline <script> — the CSP (script-src '\''self'\'') would block it.");
  process.exit(1);
}
' "$DIST/index.html"
ok "no inline <script> (CSP script-src 'self' safe)"

# The pre-paint theme script is referenced by index.html; if the file stops
# being emitted, every dark-mode load flashes white.
if grep -q 'theme-boot.js' "$DIST/index.html"; then
    [ -f "$DIST/theme-boot.js" ] \
        || die "index.html references /theme-boot.js but $DIST/theme-boot.js was not emitted."
    ok "theme-boot.js emitted"
fi

echo "Build verified ✔"
