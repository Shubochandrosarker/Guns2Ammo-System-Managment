#!/usr/bin/env bash
# G2A dashboard deploy — atomic release to the VPS (server.wpistic.cloud)
#
# Run from dashboard-app/ on a build machine (laptop or CI):
#   ./deploy/deploy.sh deploy
#   ./deploy/deploy.sh rollback        # instant rollback to previous release
#
# Requirements on the VPS: nginx configured per deploy/nginx-app.guns2ammo.com.conf,
# layout /var/www/g2a-dashboard/{releases,shared}, SSH access for $DEPLOY_USER.
#
# ─── HOW THIS DIFFERS FROM THE POS DEPLOY ────────────────────────────────────
#
# The dashboard authenticates with a same-origin session cookie, so nginx
# proxies /wp-json/g2a/v1/ through to guns2ammo.com and the app talks to a
# RELATIVE API base. That is the whole reason the proxy exists: a cross-origin
# cookie would not be sent. The consequence is that the single most likely way
# for this deploy to "succeed" onto a dead app is a missing or broken proxy —
# so the proxy is verified explicitly below rather than assumed.

set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-server.wpistic.cloud}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_ROOT="${DEPLOY_ROOT:-/var/www/g2a-dashboard}"
APP_ORIGIN="${APP_ORIGIN:-https://app.guns2ammo.com}"
HEALTH_URL="${HEALTH_URL:-${APP_ORIGIN}/healthz}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

# Same-origin production environment: the API base is a relative path through
# the nginx proxy, and mocks are hard-off.
export VITE_G2A_API_BASE="${VITE_G2A_API_BASE:-/wp-json/g2a/v1}"
export VITE_G2A_USE_MOCKS=0

die()  { echo "FATAL: $*" >&2; exit 1; }
info() { echo "==> $*"; }

ssh_run() { ssh -o StrictHostKeyChecking=accept-new "${DEPLOY_USER}@${DEPLOY_HOST}" "$@"; }

cmd_deploy() {
    # ─── Preflight: checked before anything is built or shipped ──────────────
    info "[1/10] Preflight"

    command -v npm >/dev/null || die "npm not found"
    [ -f package-lock.json ] || die "package-lock.json missing — refusing to deploy an unlocked build"
    [ -n "${VITE_G2A_API_BASE}" ] || die "VITE_G2A_API_BASE is empty"

    case "$VITE_G2A_API_BASE" in
        /*)
            echo "    API base is relative (${VITE_G2A_API_BASE}) — served through the nginx proxy."
            ;;
        https://*)
            echo "    API base is absolute (${VITE_G2A_API_BASE})."
            echo "    NOTE: the session cookie is same-origin. Pointing the dashboard at another"
            echo "    origin means the browser will not send it and every request will be"
            echo "    unauthenticated. Only do this if the API genuinely shares this origin."
            ;;
        *)
            die "VITE_G2A_API_BASE must be a relative path or an https URL (got '${VITE_G2A_API_BASE}')."
            ;;
    esac

    local release_id release_dir
    release_id="$(date -u +%Y%m%d%H%M%S)"
    release_dir="${DEPLOY_ROOT}/releases/${release_id}"

    info "[2/10] Installing locked dependencies"
    npm ci --no-audit --no-fund

    info "[3/10] Typecheck"
    npm run typecheck

    info "[4/10] Lint"
    # Invoked directly, not with --if-present: that form silently skips a
    # missing lint script, and a lint config that disappears should fail the
    # deploy rather than pass it quietly.
    npm run lint

    info "[5/10] Tests"
    # Same reasoning. The runner refuses to report success on zero test files.
    npm test

    info "[6/10] Production build (mocks forced off)"
    npm run build

    info "[7/10] Post-build safety checks"
    [ -f dist/index.html ] || die "dist/index.html missing — build produced nothing"
    if compgen -G "dist/**/*.map" > /dev/null || compgen -G "dist/assets/*.map" > /dev/null; then
        die "source maps present in dist/ — aborting."
    fi
    # Prove the dev fixtures were tree-shaken out, via a tripwire token that
    # exists ONLY in src/mocks/data.ts (written as a globalThis assignment there
    # so it survives minification whenever that module is reachable).
    #
    # The original check was /g2a\.mocks|USE_MOCKS *= *(1|true)/, which matched
    # env.ts's own "VITE_G2A_USE_MOCKS=1 is ignored in production builds"
    # warning string. That string is in every production bundle, so that step
    # aborted EVERY deploy while mocks were in fact correctly absent.
    #
    # Catches: any static import of the fixtures reaching the bundle — the way
    # this realistically regresses. The existing dynamic import in api.ts sits
    # behind a literal import.meta.env.DEV guard and a hard useMocks=false, so
    # Rollup eliminates that path outright; it cannot leak and so does not trip
    # this. Verified in both directions before shipping.
    if grep -RqF "G2A_MOCK_FIXTURES_PRESENT_DO_NOT_SHIP" dist/assets/*.js 2>/dev/null; then
        die "dev mock fixtures are present in the production bundle — aborting."
    fi
    # The API base is compiled in at build time. If it did not make it into the
    # bundle the app points somewhere else entirely, with no runtime error to
    # reveal it.
    if ! grep -rqF "$VITE_G2A_API_BASE" dist/assets/*.js; then
        die "built bundle does not contain VITE_G2A_API_BASE (${VITE_G2A_API_BASE}) — the build did not pick it up."
    fi
    printf '{"release":"%s","builtAt":"%s","api":"%s"}\n' \
        "${release_id}" "$(date -u +%FT%TZ)" "$VITE_G2A_API_BASE" > dist/healthz.json

    info "[8/10] Uploading release ${release_id}"
    ssh_run "mkdir -p '${release_dir}'"
    tar -czf - dist | ssh_run "tar -xzf - -C '${release_dir}'"

    info "[9/10] Atomic switch"
    ssh_run "ln -sfn '${release_dir}' '${DEPLOY_ROOT}/current.new' && mv -T '${DEPLOY_ROOT}/current.new' '${DEPLOY_ROOT}/current'"

    info "[10/10] Health check"
    sleep 2
    if ! curl -fsS "${HEALTH_URL}" | grep -q "${release_id}"; then
        echo "Health check FAILED — rolling back automatically." >&2
        cmd_rollback
        exit 1
    fi

    # The files are being served. Now check the thing a static health check
    # cannot see: whether the API proxy is actually wired up. 401 is a pass —
    # it means the request reached WordPress and auth was enforced. 404 means
    # the proxy is missing and every screen in the app will be empty.
    case "$VITE_G2A_API_BASE" in
        /*)
            local api_status
            api_status="$(curl -s -o /dev/null -w '%{http_code}' "${APP_ORIGIN}${VITE_G2A_API_BASE}/system/health" || echo 000)"
            case "$api_status" in
                200|401|403)
                    echo "    API proxy reachable (HTTP ${api_status})."
                    ;;
                *)
                    echo "WARNING: ${APP_ORIGIN}${VITE_G2A_API_BASE}/system/health returned HTTP ${api_status}." >&2
                    echo "         The bundle is live but the API proxy looks wrong — check the" >&2
                    echo "         'location ^~ /wp-json/g2a/v1/' block in the nginx vhost." >&2
                    echo "         Not rolling back: the release itself is fine, the proxy is not." >&2
                    ;;
            esac
            ;;
    esac

    ssh_run "cd '${DEPLOY_ROOT}/releases' && ls -1t | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf --"
    echo "Deployed ${release_id} ✔"
    echo
    echo "Verify by signing in at ${APP_ORIGIN} and loading one real page. A green"
    echo "health check only proves the files are being served."
}

cmd_rollback() {
    info "Rolling back to previous release"
    ssh_run "
        set -e
        cd '${DEPLOY_ROOT}/releases'
        current=\$(basename \"\$(readlink '${DEPLOY_ROOT}/current')\")
        prev=\$(ls -1t | grep -v \"^\${current}\$\" | head -1)
        [ -n \"\$prev\" ] || { echo 'No previous release to roll back to.' >&2; exit 1; }
        ln -sfn \"${DEPLOY_ROOT}/releases/\${prev}\" '${DEPLOY_ROOT}/current.new'
        mv -T '${DEPLOY_ROOT}/current.new' '${DEPLOY_ROOT}/current'
        echo \"Rolled back to \${prev}\"
    "
    curl -fsS "${HEALTH_URL}" >/dev/null && echo "Health OK after rollback ✔"
}

case "${1:-deploy}" in
    deploy)   cmd_deploy ;;
    rollback) cmd_rollback ;;
    *) echo "Usage: $0 [deploy|rollback]" >&2; exit 1 ;;
esac
