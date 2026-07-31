#!/usr/bin/env bash
# G2A dashboard deploy — atomic release to the VPS (server.wpistic.cloud)
#
# Run from dashboard-app/ on a build machine (laptop or CI):
#   VITE_G2A_API_BASE=/wp-json/g2a/v1 ./deploy/deploy.sh deploy
#   ./deploy/deploy.sh rollback        # instant rollback to previous release
#
# Requirements on the VPS: nginx configured per deploy/nginx-app.guns2ammo.com.conf,
# layout /var/www/g2a-dashboard/{releases,shared}, SSH access for $DEPLOY_USER.

set -euo pipefail

DEPLOY_HOST="${DEPLOY_HOST:-server.wpistic.cloud}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_ROOT="${DEPLOY_ROOT:-/var/www/g2a-dashboard}"
HEALTH_URL="${HEALTH_URL:-https://app.guns2ammo.com/healthz}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

# Same-origin production environment: API base is a relative path through
# the nginx proxy; mocks are hard-off.
export VITE_G2A_API_BASE="${VITE_G2A_API_BASE:-/wp-json/g2a/v1}"
export VITE_G2A_USE_MOCKS=0

ssh_run() { ssh -o StrictHostKeyChecking=accept-new "${DEPLOY_USER}@${DEPLOY_HOST}" "$@"; }

cmd_deploy() {
    local release_id
    release_id="$(date -u +%Y%m%d%H%M%S)"
    local release_dir="${DEPLOY_ROOT}/releases/${release_id}"

    echo "==> [1/9] Installing locked dependencies"
    npm ci --no-audit --no-fund

    echo "==> [2/9] Typecheck"
    npm run typecheck

    echo "==> [3/9] Lint"
    npm run lint --if-present

    echo "==> [4/9] Tests"
    npm test --if-present -- --run 2>/dev/null || npm test --if-present

    echo "==> [5/9] Production build (mocks forced off)"
    npm run build

    echo "==> [6/9] Post-build safety checks"
    if compgen -G "dist/**/*.map" > /dev/null || compgen -G "dist/assets/*.map" > /dev/null; then
        echo "FATAL: source maps present in dist/ — aborting." >&2; exit 1
    fi
    # Prove the dev fixtures were tree-shaken out, via a tripwire token that
    # exists ONLY in src/mocks/data.ts (written as a globalThis assignment there
    # so it survives minification whenever that module is reachable).
    #
    # The previous check was /g2a\.mocks|USE_MOCKS *= *(1|true)/, which matched
    # env.ts's own "VITE_G2A_USE_MOCKS=1 is ignored in production builds"
    # warning string. That string is in every production bundle, so this step
    # aborted EVERY deploy while mocks were in fact correctly absent.
    #
    # Catches: any static import of the fixtures reaching the bundle — the way
    # this realistically regresses. The existing dynamic import in api.ts sits
    # behind a literal import.meta.env.DEV guard and a hard useMocks=false, so
    # Rollup eliminates that path outright; it cannot leak and so does not trip
    # this. Verified in both directions before shipping.
    if grep -RqF "G2A_MOCK_FIXTURES_PRESENT_DO_NOT_SHIP" dist/assets/*.js 2>/dev/null; then
        echo "FATAL: dev mock fixtures are present in the production bundle — aborting." >&2; exit 1
    fi
    printf '{"release":"%s","builtAt":"%s"}\n' "${release_id}" "$(date -u +%FT%TZ)" > dist/healthz.json

    echo "==> [7/9] Uploading release ${release_id}"
    ssh_run "mkdir -p '${release_dir}'"
    tar -czf - dist | ssh_run "tar -xzf - -C '${release_dir}'"

    echo "==> [8/9] Atomic switch"
    ssh_run "ln -sfn '${release_dir}' '${DEPLOY_ROOT}/current.new' && mv -T '${DEPLOY_ROOT}/current.new' '${DEPLOY_ROOT}/current'"

    echo "==> [9/9] Health check"
    sleep 2
    if ! curl -fsS "${HEALTH_URL}" | grep -q "${release_id}"; then
        echo "Health check FAILED — rolling back automatically." >&2
        cmd_rollback
        exit 1
    fi

    ssh_run "cd '${DEPLOY_ROOT}/releases' && ls -1t | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf --"
    echo "Deployed ${release_id} ✔"
}

cmd_rollback() {
    echo "==> Rolling back to previous release"
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
