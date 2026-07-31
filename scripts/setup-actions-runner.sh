#!/usr/bin/env bash
# Install a GitHub Actions self-hosted runner.
#
# ─── READ THIS FIRST: THIS IS PROBABLY NOT THE FIX YOU WANT ──────────────────
#
# A job that dies in seconds with runner_id 0, no steps and 404 logs can mean
# either of two very different things, and they look identical from the API:
#
#   (a) the account's included Actions minutes are exhausted, or
#   (b) a PAYMENT METHOD HAS FAILED / the spending limit needs raising.
#
# The Actions run page shows which — (b) reads "recent account payments have
# failed or your spending limit needs to be increased". If it is (b), fix the
# card in GitHub billing: CI comes back on GitHub-hosted runners in minutes,
# with no new attack surface on your server. Do that FIRST and then decide
# whether you still want a self-hosted runner at all.
#
# This script was originally written on the assumption of (a). That assumption
# was wrong for this project, and a self-hosted runner is a large, permanent
# change to work around what may be a two-minute billing fix.
#
# ─── HONEST STATEMENT OF PRIVILEGE ───────────────────────────────────────────
#
# An earlier version of this script claimed to grant a "narrow" sudo allowlist.
# That claim was false and has been removed. The runner needs to install
# packages (shivammathur/setup-php builds the 8.1/8.2/8.3 matrix; the gitleaks
# step installs a binary), and ANY of the entries that permits is root:
#
#   sudo tee /etc/sudoers.d/anything          -> write arbitrary root-owned files
#   sudo apt-get -o APT::Update::Pre-Invoke:: -> run arbitrary commands as root
#
# So: a runner configured this way is effectively root on the machine. Sudoers
# cannot fix that — the requirement itself is the problem. Accept it only for a
# PRIVATE repo you fully trust, on a box you are willing to treat as
# compromisable by CI.
#
# If that is not acceptable — and on a host that also serves production, it
# reasonably might not be — run the runner inside a container instead, so root
# is root-in-a-container rather than root on the host. This script does not do
# that; it is the better design for a Docker-based host and is not yet built.
#
# ─── PUBLIC REPOS ────────────────────────────────────────────────────────────
#
#   *** NEVER attach a self-hosted runner to a PUBLIC repo. ***
#
# On a public repo anyone can open a pull request from a fork, and that PR's
# workflow would run their code here, as root per the above. If a repo is ever
# going public, remove its runner first.
#
# ─── USAGE ───────────────────────────────────────────────────────────────────
#
#   ./setup-actions-runner.sh --preflight <owner/repo>   # check only, no changes
#   ./setup-actions-runner.sh <owner/repo>               # check, then install
#
# Every check runs BEFORE anything is mutated, so a box that fails a
# prerequisite is left exactly as it was. Run --preflight first.
#
# Registration token (short-lived, single-use) comes from:
#   https://github.com/<owner>/<repo>/settings/actions/runners/new
# It is read from a hidden prompt — never pass it as an argument.

set -euo pipefail

PREFLIGHT_ONLY=0
if [ "${1:-}" = "--preflight" ]; then PREFLIGHT_ONLY=1; shift; fi

REPO="${1:-}"
RUNNER_USER="${RUNNER_USER:-gha-runner}"
RUNNER_HOME="${RUNNER_HOME:-/opt/actions-runners}"
RUNNER_LABELS="${RUNNER_LABELS:-self-hosted,linux,x64,g2a}"

die()  { echo "error: $*" >&2; exit 1; }
info() { echo "==> $*"; }
warn() { echo "warning: $*" >&2; }

[ -n "$REPO" ] || die "usage: $0 [--preflight] <owner/repo>"
[[ "$REPO" == */* ]] || die "repo must be owner/repo, got: $REPO"

SLUG="$(echo "$REPO" | tr '/[:upper:]' '-[:lower:]')"
INSTALL_DIR="${RUNNER_HOME}/${SLUG}"
SERVICE="actions-runner-${SLUG}"

# ═══ PREFLIGHT — no mutations below this line until it passes ════════════════
# The previous version interleaved checks with apt installs, so a box missing a
# prerequisite got apt packages and a PPA applied and THEN aborted, leaving
# half-applied state on a production host. Everything is checked up front now.
FAILED=0
check_fail() { echo "  FAIL  $*"; FAILED=1; }
check_ok()   { echo "  ok    $*"; }

echo "Preflight for ${REPO} on $(hostname -s):"

# 1. Must not be root — the runner must not be root-owned.
if [ "$(id -u)" -eq 0 ]; then
    check_fail "running as root. The runner must run as an unprivileged user."
    echo "        This host may only have root SSH (common on Hostinger et al)."
    echo "        Create a sudo-capable user first, e.g.:"
    echo "          adduser --gecos '' deploy && usermod -aG sudo deploy"
    echo "        then re-run this as that user. Creating users and editing"
    echo "        sudoers is an access-control change and is deliberately NOT"
    echo "        done automatically here."
else
    check_ok "running as non-root ($(id -un))"
fi

# 2. sudo must exist and actually work for this user.
if ! command -v sudo >/dev/null 2>&1; then
    check_fail "sudo is not installed"
elif ! sudo -n true 2>/dev/null && ! sudo -v 2>/dev/null; then
    check_fail "this user cannot sudo"
else
    check_ok "sudo available"
fi

# 3. A stale runner package from a previous attempt is worth knowing about.
for stale in /home/*/actions-runner /root/actions-runner; do
    [ -d "$stale" ] || continue
    if [ -f "${stale}/.runner" ]; then
        warn "configured runner already exists at ${stale} — check it is not a duplicate"
    else
        warn "unconfigured runner package left at ${stale} (from an earlier attempt) — consider removing it"
    fi
done

# 4. Docker group membership is root-equivalent; flag it rather than assume.
if getent group docker >/dev/null 2>&1; then
    members="$(getent group docker | cut -d: -f4)"
    [ -n "$members" ] && warn "users in the docker group (root-equivalent): ${members}"
fi

# 5. Already configured for this repo?
if [ -f "${INSTALL_DIR}/.runner" ]; then
    check_fail "a runner for ${REPO} is already configured at ${INSTALL_DIR}"
    echo "        remove it first: sudo ${INSTALL_DIR}/svc.sh stop && sudo ${INSTALL_DIR}/svc.sh uninstall"
else
    check_ok "no existing runner for ${REPO}"
fi

# 6. Tools this script itself needs before it can do anything useful.
for t in curl tar jq; do
    command -v "$t" >/dev/null 2>&1 && check_ok "$t present" || check_fail "$t missing (apt-get install $t)"
done

# 7. Disk. _work grows without bound; npm ci and composer install are not small.
avail_kb="$(df -Pk /opt 2>/dev/null | awk 'NR==2 {print $4}')"
if [ -n "${avail_kb:-}" ] && [ "$avail_kb" -lt 5242880 ]; then
    warn "$(( avail_kb / 1024 ))MB free on /opt — jobs accumulate under _work; 5GB+ recommended"
else
    check_ok "disk space on /opt looks adequate"
fi

echo
if [ "$FAILED" -ne 0 ]; then
    die "preflight failed — nothing was changed on this host."
fi
echo "Preflight passed. Nothing has been changed yet."

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
    echo "(--preflight given; stopping here.)"
    exit 0
fi

echo
echo "Installing will grant ${RUNNER_USER} effectively-root package-install"
echo "rights on this host, and CI jobs will run with them. See the header."
read -rp "Type 'yes' to continue: " CONFIRM
[ "$CONFIRM" = "yes" ] || die "aborted by operator — nothing changed."

# ═══ MUTATIONS START HERE ════════════════════════════════════════════════════

# Build prerequisites. Note there is deliberately NO system Composer install:
# shivammathur/setup-php provides Composer per job, so installing it here was
# redundant — and its checksum step shelled out to `php -r`, which on a box
# without system PHP produced a misleading "checksum mismatch" abort AFTER apt
# had already run.
info "Installing build dependencies"
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    curl tar unzip zip git jq acl ca-certificates gnupg \
    software-properties-common lsb-release \
    build-essential pkg-config libonig-dev libxml2-dev libsodium-dev libzip-dev \
    >/dev/null

if ! grep -rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
    info "Adding ondrej/php PPA (setup-php builds the 8.1/8.2/8.3 matrix from it)"
    sudo add-apt-repository -y ppa:ondrej/php >/dev/null
    sudo apt-get update -qq
fi

if ! id -u "$RUNNER_USER" >/dev/null 2>&1; then
    info "Creating unprivileged user ${RUNNER_USER}"
    sudo useradd --system --create-home --shell /bin/bash "$RUNNER_USER"
fi

# Refuse to give a CI user write access to served web content, where such a
# directory exists. This box may not have one — the dashboard runs in Docker —
# so it is a conditional check, not an assumed layout.
for webroot in /var/www /srv/www; do
    [ -d "$webroot" ] || continue
    if sudo -u "$RUNNER_USER" test -w "$webroot" 2>/dev/null; then
        die "${RUNNER_USER} can write ${webroot} — a CI job must not be able to modify served content"
    fi
done

SUDOERS=/etc/sudoers.d/90-actions-runner
if [ ! -f "$SUDOERS" ]; then
    info "Granting ${RUNNER_USER} package-install rights (see header: effectively root)"
    sudo tee "$SUDOERS" >/dev/null <<EOF
# Actions runner: shivammathur/setup-php and the gitleaks step install packages.
#
# NOT a security boundary. apt-get and tee each permit trivial escalation to
# full root; this list documents intent, it does not constrain capability.
${RUNNER_USER} ALL=(root) NOPASSWD: /usr/bin/apt-get, /usr/bin/apt, /usr/bin/add-apt-repository, /usr/bin/install, /usr/bin/update-alternatives, /usr/sbin/update-alternatives, /usr/bin/tee
EOF
    sudo chmod 0440 "$SUDOERS"
    sudo visudo -cf "$SUDOERS" >/dev/null || { sudo rm -f "$SUDOERS"; die "generated sudoers file was invalid; removed"; }
fi

info "Resolving the latest runner release"
RUNNER_VERSION="$(curl -sS https://api.github.com/repos/actions/runner/releases/latest | jq -r .tag_name | sed 's/^v//')"
[ -n "$RUNNER_VERSION" ] && [ "$RUNNER_VERSION" != "null" ] || die "could not resolve the latest runner version"
info "Runner version ${RUNNER_VERSION}"

sudo mkdir -p "$INSTALL_DIR"
sudo chown "$RUNNER_USER:$RUNNER_USER" "$INSTALL_DIR"

TARBALL="actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
info "Downloading ${TARBALL}"
sudo -u "$RUNNER_USER" curl -sSL -o "${INSTALL_DIR}/${TARBALL}" \
    "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${TARBALL}"
sudo -u "$RUNNER_USER" tar -xzf "${INSTALL_DIR}/${TARBALL}" -C "$INSTALL_DIR"
sudo -u "$RUNNER_USER" rm -f "${INSTALL_DIR}/${TARBALL}"
sudo "${INSTALL_DIR}/bin/installdependencies.sh" >/dev/null

echo
echo "Generate a registration token at:"
echo "  https://github.com/${REPO}/settings/actions/runners/new"
echo
read -rsp "Paste the registration token (input hidden): " REG_TOKEN
echo
[ -n "$REG_TOKEN" ] || die "no token supplied"

info "Registering runner for ${REPO}"
sudo -u "$RUNNER_USER" "${INSTALL_DIR}/config.sh" \
    --unattended \
    --url "https://github.com/${REPO}" \
    --token "$REG_TOKEN" \
    --name "wpistic-$(hostname -s)-${SLUG}" \
    --labels "$RUNNER_LABELS" \
    --work "_work" \
    --replace
unset REG_TOKEN

info "Installing systemd service ${SERVICE}"
sudo "${INSTALL_DIR}/svc.sh" install "$RUNNER_USER" >/dev/null
sudo "${INSTALL_DIR}/svc.sh" start >/dev/null

sleep 3
sudo "${INSTALL_DIR}/svc.sh" status | grep -qiE "active \(running\)|Active: active" \
    || die "runner service did not come up — check: sudo journalctl -u ${SERVICE} -n 50"

cat <<EOF

Runner installed for ${REPO}
  labels:    ${RUNNER_LABELS}
  directory: ${INSTALL_DIR}
  user:      ${RUNNER_USER}
  service:   ${SERVICE}

ORDER MATTERS for the next step. Wait until this runner shows "Idle" under
Settings -> Actions -> Runners BEFORE setting the repository variable:

  RUNNER_LABEL = g2a

The workflows read \${{ vars.RUNNER_LABEL || 'ubuntu-latest' }}. Setting it
while no runner is online makes every job QUEUE INDEFINITELY rather than fail,
which is harder to notice than a red check. Deleting the variable switches
straight back to GitHub-hosted runners.

  sudo journalctl -u ${SERVICE} -f        # live logs
  sudo ${INSTALL_DIR}/svc.sh status       # status
  sudo ${INSTALL_DIR}/svc.sh stop         # stop taking jobs

Remove it (do this BEFORE making the repo public):
  sudo ${INSTALL_DIR}/svc.sh stop && sudo ${INSTALL_DIR}/svc.sh uninstall
  sudo -u ${RUNNER_USER} ${INSTALL_DIR}/config.sh remove --token <fresh removal token>

EOF
