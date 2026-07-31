#!/usr/bin/env bash
# Install a GitHub Actions self-hosted runner on the G2A VPS.
#
# WHY: these repos are private on a personal account, so Actions minutes are
# metered. When the monthly allowance runs out, jobs are killed before a runner
# is ever assigned — they fail in ~2 seconds with no logs and `runner_id: 0`,
# which looks like a broken workflow but is billing. A self-hosted runner has
# no minute metering at all, so CI keeps working regardless of the allowance.
#
# SCOPE: a personal account cannot have organisation-level runners, so each
# repository needs its own registration. This script registers ONE repo per
# invocation and can be run repeatedly for each of them, side by side on the
# same box — each gets its own directory, service and runner name.
#
# Usage, as a sudo-capable user on server.wpistic.cloud:
#
#   ./setup-actions-runner.sh Shubochandrosarker/G2A-POS-Solutions
#   ./setup-actions-runner.sh Shubochandrosarker/Guns2Ammo-System-Managment
#
# It will prompt for a registration token, which you generate here (it is
# short-lived, ~1 hour, and single-use):
#
#   https://github.com/<owner>/<repo>/settings/actions/runners/new
#
# Never paste that token into a shell argument or a file — the prompt reads it
# without echoing so it stays out of your shell history.
#
# ─── SECURITY, READ BEFORE RUNNING ───────────────────────────────────────────
#
# A self-hosted runner executes whatever the workflow says. That is safe for a
# PRIVATE repo, where only people you trust can push. It is NOT safe for a
# public one: anyone can open a pull request from a fork, and on a public repo
# that PR's workflow would run arbitrary code on this server.
#
#   *** DO NOT make any repo public while it has a self-hosted runner ***
#
# If a repo is ever made public, remove its runner FIRST.
#
# This script reduces blast radius by running the runner as a dedicated,
# unprivileged user that is not in the web-server group and cannot write to
# /var/www. It still needs passwordless sudo for a narrow allowlist, because
# shivammathur/setup-php and the gitleaks step install packages.

set -euo pipefail

REPO="${1:-}"
RUNNER_USER="${RUNNER_USER:-gha-runner}"
RUNNER_HOME="${RUNNER_HOME:-/opt/actions-runners}"
# Custom label the workflows select on, so this runner only picks up G2A jobs.
RUNNER_LABELS="${RUNNER_LABELS:-self-hosted,linux,x64,g2a}"

die() { echo "error: $*" >&2; exit 1; }
info() { echo "==> $*"; }

[ -n "$REPO" ] || die "usage: $0 <owner/repo>   e.g. $0 Shubochandrosarker/G2A-POS-Solutions"
[[ "$REPO" == */* ]] || die "repo must be in owner/repo form, got: $REPO"
[ "$(id -u)" -ne 0 ] || die "run as a normal sudo-capable user, not root — the runner must not be root-owned"
command -v sudo >/dev/null || die "sudo is required"

SLUG="$(echo "$REPO" | tr '/[:upper:]' '-[:lower:]')"
INSTALL_DIR="${RUNNER_HOME}/${SLUG}"
SERVICE="actions-runner-${SLUG}"

# ─── 1. Build dependencies ───────────────────────────────────────────────────
# A self-hosted runner is a bare machine: none of the software GitHub's hosted
# images preinstall is here. These are what this repo's workflows actually
# reach for. Node and the PHP versions themselves are installed per-job by
# actions/setup-node and shivammathur/setup-php; these are their prerequisites.
info "Installing build dependencies"
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    curl tar unzip zip git jq acl ca-certificates gnupg \
    software-properties-common lsb-release \
    build-essential pkg-config libonig-dev libxml2-dev libsodium-dev libzip-dev \
    >/dev/null

# setup-php pulls PHP builds from ondrej/php; without this PPA the 8.1/8.2/8.3
# matrix has nothing to install from.
if ! grep -rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
    info "Adding ondrej/php PPA (needed for the 8.1/8.2/8.3 matrix)"
    sudo add-apt-repository -y ppa:ondrej/php >/dev/null
    sudo apt-get update -qq
fi

if ! command -v composer >/dev/null; then
    info "Installing Composer"
    EXPECTED="$(curl -sS https://composer.github.io/installer.sig)"
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');" 2>/dev/null || echo missing)"
    [ "$EXPECTED" = "$ACTUAL" ] || die "Composer installer checksum mismatch — refusing to run it"
    sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

# ─── 2. Dedicated unprivileged user ──────────────────────────────────────────
if ! id -u "$RUNNER_USER" >/dev/null 2>&1; then
    info "Creating unprivileged user ${RUNNER_USER}"
    sudo useradd --system --create-home --shell /bin/bash "$RUNNER_USER"
fi

# Deliberately NOT added to www-data. A job must not be able to write the
# deployed dashboard under /var/www/g2a-dashboard.
if id -nG "$RUNNER_USER" | tr ' ' '\n' | grep -qx "www-data"; then
    die "${RUNNER_USER} is in the www-data group — remove it, a CI job must not be able to write /var/www"
fi

# Narrow sudo allowlist: enough for setup-php and the gitleaks install step,
# not a blanket NOPASSWD:ALL.
SUDOERS=/etc/sudoers.d/90-actions-runner
if [ ! -f "$SUDOERS" ]; then
    info "Granting ${RUNNER_USER} a narrow passwordless sudo allowlist"
    sudo tee "$SUDOERS" >/dev/null <<EOF
# Actions runner needs these for shivammathur/setup-php and the gitleaks step.
# Intentionally NOT ALL=(ALL) NOPASSWD:ALL.
${RUNNER_USER} ALL=(root) NOPASSWD: /usr/bin/apt-get, /usr/bin/apt, /usr/bin/add-apt-repository, /usr/bin/install, /usr/bin/update-alternatives, /usr/sbin/update-alternatives, /usr/bin/tee
EOF
    sudo chmod 0440 "$SUDOERS"
    sudo visudo -cf "$SUDOERS" >/dev/null || { sudo rm -f "$SUDOERS"; die "generated sudoers file was invalid; removed"; }
fi

# ─── 3. Runner package ───────────────────────────────────────────────────────
if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/.runner" ]; then
    info "A runner for ${REPO} is already configured at ${INSTALL_DIR}"
    info "To re-register, remove it first:  sudo systemctl stop ${SERVICE} && sudo -u ${RUNNER_USER} ${INSTALL_DIR}/config.sh remove"
    exit 0
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

# ─── 4. Register ─────────────────────────────────────────────────────────────
echo
echo "Generate a registration token now at:"
echo "  https://github.com/${REPO}/settings/actions/runners/new"
echo "It expires in about an hour and can only be used once."
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

# ─── 5. Run as a service ─────────────────────────────────────────────────────
info "Installing systemd service ${SERVICE}"
sudo "${INSTALL_DIR}/svc.sh" install "$RUNNER_USER" >/dev/null
sudo "${INSTALL_DIR}/svc.sh" start >/dev/null

sleep 3
if sudo "${INSTALL_DIR}/svc.sh" status | grep -qiE "active \(running\)|Active: active"; then
    info "Runner is running"
else
    die "runner service did not come up — check: sudo journalctl -u ${SERVICE} -n 50"
fi

cat <<EOF

Runner installed for ${REPO}
  labels:    ${RUNNER_LABELS}
  directory: ${INSTALL_DIR}
  user:      ${RUNNER_USER}
  service:   ${SERVICE}

Next: point the workflows at it by setting a repository variable
(Settings -> Secrets and variables -> Actions -> Variables):

  RUNNER_LABEL = g2a

The workflows read \${{ vars.RUNNER_LABEL || 'ubuntu-latest' }}, so until that
variable exists they keep using GitHub-hosted runners. Setting it switches them
over with no workflow edit; deleting it switches back.

Useful commands:
  sudo journalctl -u ${SERVICE} -f        # live logs
  sudo ${INSTALL_DIR}/svc.sh status       # service status
  sudo ${INSTALL_DIR}/svc.sh stop         # stop taking jobs

To remove it entirely (do this BEFORE ever making the repo public):
  sudo ${INSTALL_DIR}/svc.sh stop && sudo ${INSTALL_DIR}/svc.sh uninstall
  sudo -u ${RUNNER_USER} ${INSTALL_DIR}/config.sh remove --token <a fresh removal token>

EOF
