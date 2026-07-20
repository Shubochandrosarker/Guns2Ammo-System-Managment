# Guns 2 Ammo System — Release 2.5.0

**Release Date:** July 20, 2026
**Supersedes:** [RELEASE-2.1.0.md](RELEASE-2.1.0.md) (July 15, 2026) — kept as a historical record, not rewritten.

This release closes out the 2026-07-19 Stripe webhook/membership-activation incident, reconciles every plugin's dedicated GitHub repo against this monorepo and what's actually deployed on guns2ammo.com, and fixes a sitewide color-contrast (dark/light mode) audit. It is the first release since 2.1.0 where every component version below has been individually verified against its actual plugin/theme header — not copied from a prior doc.

---

## Component Versions

| Component | 2.1.0 | 2.5.0 | Changed? |
|---|---|---|---|
| **WPistic Theme for G2A** (`guns2ammo/`) | 1.27.13 | **1.27.14** | ✅ contrast fixes |
| **g2a-booking-engine** | 1.9.9.14 | **1.9.9.16** | ✅ Stripe incident fix + contrast fixes |
| **memberistic-membership-solutions** | 1.18.0 | **1.18.4** | ✅ Stripe incident fix + contrast fixes |
| **verifyistic** | 1.4.4 | **1.4.7** | ✅ Stripe incident fix (rate-limit/Cloudflare) + contrast fixes |
| **advanced-ffl-checkout** | 1.15.1 | **1.21.1** | ✅ crossmatched 6 versions of distributor/marketplace/financing features from its dedicated repo, then contrast fix |
| **formistic** | 2.1.0 | **2.1.1** | ✅ contrast fix |
| **g2a-pos-core** | 3.3.0 | **3.3.6** | confirmed in sync with its dedicated repo and live site (no functional change); version bump to align with this release |
| **messageistic** | 0.8.0 | **0.8.1** | no functional change; version bump to align with this release. (Backfilled a missing changelog entry for 0.8.0 itself while here — it shipped in a July 15 commit that never wrote it up: two new SMS lifecycle triggers, `waiver.signed` and `waiver.renewal_due`.) |
| **g2a-theme-control** | 1.0.0 | **1.0.1** | no functional change; version bump to align with this release |
| **g2a-business-api** | (bundled) | **0.4.3** | no functional change; version bump to align with this release |
| **dashboard-app** | (see deployment) | **0.1.1** | no functional change; version bump to align with this release |
| **cloudflare-rag-worker** | — | **1.0.1** | no functional change; version bump to align with this release |
| **guns2ammo-waiver-manager** | — | 1.5.1 | **retired** — superseded by Memberistic's built-in waiver module; the plugin shows its own "deactivate and delete" admin notice. Kept in the repo for historical reference only, not part of the install order. |

No dedicated repo exists for messageistic, g2a-theme-control, g2a-business-api, dashboard-app, cloudflare-rag-worker, or guns2ammo-waiver-manager — those six are monorepo-only.

---

## What's in this release

### 1. Stripe webhook + membership-activation incident fix

Full root-cause writeup: `docs/INCIDENT-AUDIT-2026-07-19-STRIPE-SIGNUP.md`.

- **g2a-booking-engine** — the webhook signature header lookup could never match WordPress's canonicalized REST header keys, so *every* Stripe delivery 400'd; separately, any event the plugin didn't own (including every Memberistic checkout) 422'd instead of being acknowledged, and the retry-dedup consumed event IDs before processing so Stripe's automatic retries could never recover. Fixed all three, plus: durable webhook-event table, deferred paid-booking side effects, PaymentIntent-based refund matching, symmetric signature timestamp tolerance, an expiry-cron gateway pre-check, admin webhook-health notices, and new `wp g2ab stripe-audit`/`stripe-reconcile` WP-CLI commands.
- **memberistic-membership-solutions** — membership activation depended entirely on a *separate* Stripe webhook endpoint that no runbook ever instructed adding to the Stripe dashboard, with no fallback if it was missing — customers were charged and shown "YOU'RE IN." with no account ever created. Fixed with server-side thank-you-page confirmation against live Stripe state, persisted Checkout Session reuse (closing a double-charge trap on signup retry), a namespaced `WP_Error` fatal fix, corrected webhook retry semantics, and a checkout throttle that no longer penalizes legitimate traffic.
- **verifyistic** — the age-gate rate limiter's Cloudflare trusted-proxy allowlist was empty by default, so behind Cloudflare its buckets keyed on the shared edge IP rather than the real visitor — a handful of anonymous page views from unrelated customers could lock out signups sitewide. Fixed with real IPv4/IPv6 CIDR matching, separated limiter buckets, and cached frontend tokens.
- Operator-facing docs (`INSTALL.md`, `docs/SYSTEM_WORKFLOW_v1.12.2.md`) now spell out both required Stripe webhook endpoints, their event lists, and that they take separate signing secrets — the missing second endpoint was a root cause of the outage, not just a code bug.

### 2. Six-plugin crossmatch reconciliation

Every plugin with a dedicated single-plugin GitHub repo was compared three ways — this monorepo, its dedicated repo, and an export of what's actually installed on the live production site — before touching anything.

- **g2a-booking-engine, memberistic-membership-solutions, formistic** — dedicated repos were behind the monorepo with zero unique content of their own; fast-forwarded to match.
- **advanced-ffl-checkout** — the *monorepo* was the stale side here: its dedicated repo and the live site had moved 6 minor versions ahead (5 distributor drop-ship integrations, GunBroker.com marketplace sync, a Credova financing gateway, and per-distributor admin toggles) without ever syncing back. Pulled the newer code into the monorepo.
- **g2a-pos-core** — confirmed genuinely in sync at the code level. Its dedicated repo does carry a standalone `pos-app/` React application with no monorepo equivalent — flagged for a future decision, not acted on here.
- **verifyistic** — its dedicated repo carries ~25 extra files implementing a full customer/e-signature/kiosk waiver platform under the same version number as the lean age-gate plugin tracked here. Investigation traced this to an abandoned, never-merged design fork from 2026-07-15: a competing effort built the equivalent system inside Memberistic instead, shipped it completely, and that is what guns2ammo.com actually runs. Left the dedicated repo alone — merging it in would violate this project's own documented "don't add a fourth waiver store" principle and add untested surface to a system managing legal liability waivers.

### 3. Sitewide dark/light color-contrast audit

Triggered by member-reported near-invisible text on the account dashboard and WooCommerce My Account pages. Root cause, recurring across every plugin touched: components that hardcoded a snapshot of the *then-current* mode's colors instead of staying wired to the theme's live `--color-*`/`--memberistic-*` tokens, so anything only ever visually tested in one mode silently broke in the other — including once in reverse, a widget whose *default* variant is dark, broken in that default rather than in light mode. Fixed across the theme core, Memberistic's account dashboard, WooCommerce integration styling, the booking-engine's customer-facing shortcodes, verifyistic's popup and admin pages, the FFL dealer-search map widget, and formistic's contact form. All previously-failing elements now clear WCAG AA (4.5:1 normal text / 3:1 large text and UI components) in both modes.

### 4. Repository housekeeping

- `releases/` pruned to the two most-recent versions per component (current + previous), per the policy already documented in `INSTALL.md` but not enforced since — it had drifted to as many as 10 zips for a single plugin. Nothing is lost: every pruned zip is reproducible from its corresponding git commit if ever needed.
- Verified no stray `-main`/duplicate plugin folders, no committed backup/swap files, and no accidentally-committed secrets anywhere in the tracked tree.
- `INSTALL.md` and this doc's Component Versions table are now cross-checked directly against each plugin's own version header, not copied forward from the prior release doc.
- The six components with no functional change this release (g2a-pos-core, messageistic, g2a-theme-control, g2a-business-api, dashboard-app, cloudflare-rag-worker) were still given a version bump + rebuilt release archive so every installable artifact in `releases/` genuinely corresponds to this release, rather than leaving some components' zips dated from whenever they last had real code changes. `guns2ammo-waiver-manager` was deliberately excluded — it's retired, and bumping a plugin that tells admins to delete it would be actively misleading.

---

## Install order (fresh site or full upgrade)

Same order as `INSTALL.md`, plugins first, theme last. All artifacts are in `releases/`:

1. `g2a-theme-control-1.0.1.zip`
2. `g2a-booking-engine-1.9.9.16.zip`
3. `memberistic-membership-solutions-1.18.4.zip`
4. `formistic-2.1.1.zip`
5. `verifyistic-1.4.7.zip`
6. `advanced-ffl-checkout-1.21.1.zip`
7. `messageistic-0.8.1.zip`
8. `g2a-pos-core-3.3.6.zip`
9. `WPistic-Theme-For-G2A-Version-1.27.14.zip` (theme, last)

Deactivate/reactivate each plugin once after a zip-overwrite upload — this codebase's DB migrations and cron registration historically only ran on activation, and a manual zip replace doesn't re-fire that hook. Confirm no stray `*-main` plugin folder exists in `wp-content/plugins/` before going live. `guns2ammo-waiver-manager` is retired — do not install it on a new site; if a live site still has it active, deactivate and delete per its own admin notice.

**Stripe dashboard (manual, not part of any zip):** re-enable/create both webhook endpoints per `INSTALL.md`'s install-order steps 2 and 3 before going live, and follow the recovery runbook in `docs/INCIDENT-AUDIT-2026-07-19-STRIPE-SIGNUP.md` §6 for the outage window's backfill.

---

## Verification performed in this session

- `php -l` across every `.php` file in the repo: clean.
- `composer validate --strict` for every composer-managed subproject: clean.
- Every crossmatch sync three-way-diffed (monorepo / dedicated repo / live site) before syncing, not assumed safe.
- Every color-contrast fix computed before/after against WCAG AA, in both modes.
- Not verified from this session (needs a human/staging pass): end-to-end Stripe test-mode checkout → webhook → activation; visual smoke test of the contrast fixes on a live rendered page.
