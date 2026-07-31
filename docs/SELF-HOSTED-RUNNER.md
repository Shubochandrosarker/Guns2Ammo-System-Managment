# Self-hosted GitHub Actions runner

## Check the billing error first — you may not need any of this

A job that dies in seconds with `runner_id: 0`, no `steps` array and 404 logs
has **two** possible causes that look identical through the API:

| Cause | Fix |
|---|---|
| Included Actions minutes exhausted | raise the spending limit, upgrade, or wait for the cycle reset |
| **A payment method has failed** | **update the card in GitHub billing** |

The Actions **run page** distinguishes them — a failed payment shows *"recent
account payments have failed or your spending limit needs to be increased"*.
The API does not surface that string, which is how the two get confused.

**Open a failed run and read the annotation before doing anything here.** If it
is a payment problem, fixing the card restores CI on GitHub-hosted runners in
minutes, with no new attack surface on your server. A self-hosted runner is a
large, permanent change; do not make it to work around a declined card.

This document was originally written assuming exhausted minutes. That
assumption was wrong for this project.

## If you still want a runner: what it actually costs you

`server.wpistic.cloud` already exists to serve the dashboard, and a self-hosted
runner is not minute-metered. The trade is privilege, not money — see below.

## The runner is effectively root. Read this properly.

A self-hosted runner executes whatever a workflow says. That is tolerable for a
**private** repo with trusted pushers. It is not tolerable for a public one: on
a public repo anyone can open a fork PR, and that PR's workflow runs their code
here.

> **Never attach a runner to a public repo.** If a repo is going public, remove
> its runner first.

An earlier version of this document claimed the installer granted a "narrow"
sudo allowlist. **That claim was false and has been withdrawn.** The runner has
to install packages — `shivammathur/setup-php` builds the 8.1/8.2/8.3 matrix,
and the gitleaks step installs a binary — and the entries that permits are each
trivially escalatable:

```
sudo tee /etc/sudoers.d/anything            # write any root-owned file
sudo apt-get -o APT::Update::Pre-Invoke::=… # run any command as root
```

So a CI job on this runner — including a compromised or misbehaving
dependency — is **root on the machine serving your dashboard**. Sudoers cannot
fix that; the package-install requirement is the problem.

What the installer does still do:

- runs the runner as an unprivileged `gha-runner` user, not root
- **refuses to run as root**, so it cannot be installed root-owned by accident
- refuses if that user can write a web root, **where one exists** (this box
  serves the dashboard from Docker and has no `/var/www`, so that check is
  conditional rather than assumed)
- checks every prerequisite **before** mutating anything, so a box that fails a
  check is left exactly as it was

**Better design, not yet built:** this host runs Docker. Running the runner
inside a container makes root *root-in-a-container* rather than root on the
host, and sidesteps both the missing system PHP and the missing sudo user. If
you want a runner long-term, that is the version worth building.

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

**Set it last.** Only after the runner reports **Idle** under Settings → Actions
→ Runners. Setting it while no runner is online makes every job queue
indefinitely instead of failing, which is harder to notice than a red check.

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
