# Self-hosted GitHub Actions runner

## Why

These repos are **private, on a personal account**, so Actions minutes are metered.
When the monthly allowance runs out, jobs are killed before a runner is ever
assigned. The failure looks like this:

```
runner_id: 0      runner_name: ""      steps: (absent)      logs: HTTP 404
started 10:32:46Z → completed 10:32:58Z
```

No checkout, no setup, nothing — which reads like a broken workflow but is
purely billing. Every PR merged during the 2026-07-20 → 07-31 window went in
with red checks for this reason.

A self-hosted runner has **no minute metering**, so CI keeps working regardless
of the allowance. The box already exists (`server.wpistic.cloud`, serving the
dashboard), so this costs nothing extra.

## Read this first

A self-hosted runner executes whatever a workflow tells it to. That is fine for
a **private** repo, where only trusted people can push.

It is **not** fine for a public one. On a public repo, anyone can open a pull
request from a fork, and that PR's workflow would run their code on your
server — with access to the deployed dashboard, any credentials on the box, and
your network.

> **Never make one of these repos public while it has a self-hosted runner.**
> If a repo is ever going public, remove its runner first.

The installer reduces blast radius but does not eliminate it:

- runs as a dedicated unprivileged `gha-runner` user, not root
- refuses to proceed if that user is in `www-data`, so a job cannot write the
  deployed dashboard under `/var/www/g2a-dashboard`
- grants a **narrow** passwordless sudo allowlist (`apt-get`, `install`,
  `update-alternatives`, `tee`) rather than `NOPASSWD: ALL` — `setup-php` and
  the gitleaks step genuinely need package installs

## Install

A personal account cannot have organisation-level runners, so **each repository
needs its own registration**. Run the script once per repo; they coexist on the
same box with separate directories, services and names.

```bash
# on server.wpistic.cloud, as a sudo-capable NON-root user
git clone https://github.com/Shubochandrosarker/G2A-POS-Solutions.git
cd G2A-POS-Solutions

./scripts/setup-actions-runner.sh Shubochandrosarker/G2A-POS-Solutions
./scripts/setup-actions-runner.sh Shubochandrosarker/Guns2Ammo-System-Managment
```

Each run prompts for a registration token, generated at:

```
https://github.com/<owner>/<repo>/settings/actions/runners/new
```

The token is short-lived (~1 hour) and single-use. The prompt reads it without
echoing, so it stays out of your shell history — don't pass it as an argument.

## Switching CI over

The workflows read a repository variable:

```yaml
runs-on: ${{ vars.RUNNER_LABEL || 'ubuntu-latest' }}
```

So switching is a settings change, not a code change:

| `RUNNER_LABEL` | Effect |
|---|---|
| unset | GitHub-hosted `ubuntu-latest` — metered minutes |
| `g2a` | The self-hosted runner |

Set it under **Settings → Secrets and variables → Actions → Variables** in each
repo. Delete it to switch straight back — useful if the VPS is down.

`ubuntu-latest` is the default on purpose. If `runs-on` named a self-hosted
label directly and the runner were offline, every job would **queue forever**
rather than fail — much harder to notice than a red check.

## What the runner has to provide

A self-hosted runner is a bare machine; none of GitHub's preinstalled image is
there. The installer provides:

| Need | Provided by |
|---|---|
| PHP 8.1 / 8.2 / 8.3 matrix | `ondrej/php` PPA, then `shivammathur/setup-php` per job |
| PHP build prerequisites | `libonig`, `libxml2`, `libsodium`, `libzip`, `build-essential` |
| Composer | installed to `/usr/local/bin`, checksum-verified |
| Node 24 | `actions/setup-node` per job |
| gitleaks (secret-scan) | downloaded per job — needs the `install` sudo entry |
| git, curl, tar, unzip, jq | apt |

## Operating it

```bash
sudo journalctl -u actions-runner-<slug> -f     # live logs
sudo /opt/actions-runners/<slug>/svc.sh status  # service status
sudo /opt/actions-runners/<slug>/svc.sh stop    # stop taking jobs
```

Where `<slug>` is the lowercased `owner-repo`, e.g.
`shubochandrosarker-g2a-pos-solutions`.

**Disk**: jobs accumulate under `_work`. `npm ci` and `composer install` are not
small, and nothing prunes them automatically. Watch it, and clear the checkout
directories if the volume gets tight.

**Persistent, not ephemeral**: the runner reuses its working directory between
jobs, so a job can in principle leave state behind that a later job sees. For a
private repo with trusted pushers that is an acceptable trade for speed. If you
later want isolation, re-register with `--ephemeral` and have systemd restart
it, at the cost of a fresh setup per job.

## Removing it

Do this **before** making a repo public, and when decommissioning:

```bash
sudo /opt/actions-runners/<slug>/svc.sh stop
sudo /opt/actions-runners/<slug>/svc.sh uninstall
# fresh removal token from the same Settings -> Actions -> Runners page
sudo -u gha-runner /opt/actions-runners/<slug>/config.sh remove --token <token>
```

Then unset `RUNNER_LABEL` so CI falls back to GitHub-hosted runners.

## Alternatives

If you'd rather not host a runner:

- **Set a spending limit** — Settings → Billing → Budgets and alerts. It is $0
  by default, which is exactly why jobs die instantly. Any non-zero budget lets
  Actions bill past the included minutes.
- **Upgrade the plan** — raises the included monthly minutes.
- **Wait for the reset** — the allowance restores on your billing cycle date.

Confirm current pricing and limits on your billing page rather than from
documentation, including this page.
