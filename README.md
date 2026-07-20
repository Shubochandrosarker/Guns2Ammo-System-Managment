# Guns 2 Ammo — WordPress System

**Latest Release: [v2.5.0](RELEASE-2.5.0.md)** — July 20, 2026 — Stripe webhook incident fix, six-plugin crossmatch, sitewide contrast fix

The complete source for **Guns 2 Ammo**'s web presence: an indoor shooting
range, FFL firearm store, and NRA-certified training academy in Mesa,
Arizona. This repository holds the WordPress theme, every custom plugin, a
standalone operations dashboard, and a small Cloudflare Worker — together
they run the public site, bookings, memberships, point-of-sale, staff
tooling, and an internal AI "brain" for support automation.

This is not a generic WordPress starter kit. Every plugin here was
purpose-built for this business — a shooting range and licensed firearms
retailer — and the code reflects that: age verification, FFL transfer
compliance, NICS wait-time math, waiver management, and range check-in are
first-class concerns throughout, not bolted on.

> **For clients:** See `RELEASE-2.5.0.md` for complete release notes, component versions, and deployment instructions (`RELEASE-2.1.0.md` is kept as a historical record of the July 15 release). The `releases/` folder contains all installable packages ready for production deployment.

---

## Architecture at a glance

```
                          ┌─────────────────────────┐
                          │   guns2ammo.com (WP)     │
                          │                          │
  Visitors ───────────────▶  guns2ammo theme         │
                          │  + WooCommerce           │
                          │  + booking/membership/   │
                          │    POS/SMS/age-gate/     │
                          │    contact-form plugins  │
                          └───────────┬──────────────┘
                                      │ REST API (g2a/v1, formistic/v1, …)
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                                    ▼
      ┌───────────────────────────┐        ┌───────────────────────────┐
      │ app.guns2ammo.com          │        │ Cloudflare Worker          │
      │ (dashboard-app, React SPA) │        │ (cloudflare-rag-worker)    │
      │ staff ops control center   │        │ vector store for the AI   │
      └───────────────────────────┘        │ knowledge base (Vectorize)│
                                             └───────────────────────────┘
```

WordPress is the single source of truth for every piece of business data
(bookings, memberships, orders, waivers, POS sales). The dashboard app and
the Cloudflare Worker are both read/write clients of WordPress's REST API,
not separate systems of record — an outage of either degrades gracefully
without breaking the core site.

---

## Repository layout

| Path | What it is |
|---|---|
| `guns2ammo/` | The WordPress theme — every public page template, the design-token CSS system, SEO/AEO/JSON-LD, and the single source of truth for business info (NAP, hours, founding year) that every plugin reads from. |
| `g2a-booking-engine/` | Lane bookings, classes/events, payments (Stripe/PayPal/Authorize.Net/Fortis), front-desk check-in, waivers, automated transactional email, PDF invoices, and a full REST + hook system. |
| `memberistic-membership-solutions/` | Membership plans, signup/renewal, family/linked members, waivers, corporate group check-in, staff dashboard, Stripe billing, WooCommerce member discounts, POS bridge. |
| `formistic/` | Contact forms — a visual form builder, unified submission inbox with per-sender threads, spam/GDPR/webhooks, a newsletter list with one-click unsubscribe, and AI-assisted auto-reply. The site's sole contact-form/inbox/newsletter solution (see [`docs/FORMISTIC_G2A_SETUP.md`](docs/FORMISTIC_G2A_SETUP.md)). |
| `advanced-ffl-checkout/` | FFL dealer search at WooCommerce checkout, the full ATF dealer database (~80,000 dealers), transfer-lifecycle tracking, NICS 3-business-day automation (federal-holiday-aware), and a dealer confirmation portal. |
| `verifyistic/` | Age verification popup (COPPA-aware, multiple verification modes) gating checkout and bookings for age-restricted products/classes. |
| `messageistic/` | Provider-independent SMS/communication engine (Twilio, self-hosted Android gateway, Jasmin) — campaigns, automations, conversations, multi-location support. |
| `g2a-pos-core/` | Point-of-sale for in-store firearm/ammo/accessory sales, ATF-compliant bound-book logging, audit-chain integrity, and the AI knowledge base ingestion this system's support "brain" reads from. |
| `g2a-business-api/` | REST API (`/wp-json/g2a/v1/*`) that powers the staff dashboard — aggregates data from every other plugin behind permission-checked, versioned endpoints. |
| `g2a-theme-control/` | Small companion plugin giving the theme admin-editable repeater fields (e.g. the machine-gun inventory grid) without hardcoding content. |
| `guns2ammo-waiver-manager/` | Legacy waiver/kiosk-user plugin (integrates ApproveMe + Paid Memberships Pro). Superseded by Memberistic's built-in waiver module for day-to-day use — kept in the repo but not part of the current install path in `INSTALL.md`. |
| `dashboard-app/` | React + TypeScript SPA — the staff-facing "Business Control Center" at `app.guns2ammo.com`. Talks to WordPress only through `g2a-business-api`'s REST namespace; carries no secrets of its own. |
| `cloudflare-rag-worker/` | Cloudflare Worker providing the vector-search backend (Workers AI embeddings + Vectorize) for the POS's AI support assistant. WordPress remains the source of truth; this Worker is a cache/index that can be rebuilt from WordPress at any time. |
| `docs/` | Setup runbooks, architecture references, and the dated audit/incident history (see below). |
| `scripts/` | Release tooling — `build-release-zips.sh` rebuilds every installable zip from the tracked source directories. |
| `releases/` | Versioned, installable zips (current + previous version of each, for easy rollback). This — not the root-level zips — is the canonical distribution artifact. |

---

## Getting started

**Installing on a WordPress site:** see [`INSTALL.md`](INSTALL.md) for the
full artifact table, install order, and post-install setup checklist
(Customizer business info, membership plan pages, AI provider config, etc.).

**Deploying the staff dashboard:** see [`DEPLOYMENT.md`](DEPLOYMENT.md) for
standing up `g2a-business-api` (WordPress side) and `dashboard-app` (the
React SPA) as the two independently-deployable halves of `app.guns2ammo.com`.

**Local development on an individual component:**
- PHP plugins/theme: no build step — edit in place, `php -l` before
  committing, then run `scripts/build-release-zips.sh` when a version is
  ready to package.
- `dashboard-app/`: `npm install && npm run dev`; `npx tsc --noEmit` for a
  type-check.
- `g2a-pos-core/` and `g2a-business-api/` ship PHPUnit suites under
  `vendor/bin/phpunit` (composer dependencies required — see each plugin's
  own `README.md`/`composer.json`).

---

## Documentation index

`docs/` holds two kinds of files — living references (kept current) and
dated historical records (a permanent log, not rewritten after the fact):

**Living references**
- [`docs/FEATURES.md`](docs/FEATURES.md) — capability snapshot across the whole system, organized by plugin.
- [`docs/ROADMAP.md`](docs/ROADMAP.md) — what's built vs. planned, plus standing architectural decisions.
- [`docs/SEO_AEO_PLAYBOOK.md`](docs/SEO_AEO_PLAYBOOK.md) — the site's SEO/AEO (answer-engine-optimization) strategy and structured-data conventions.
- [`docs/FORMISTIC_G2A_SETUP.md`](docs/FORMISTIC_G2A_SETUP.md) — the contact-form/inbox/newsletter plugin's setup and migration runbook.
- [`docs/VERIFYISTIC_SETUP_G2A.md`](docs/VERIFYISTIC_SETUP_G2A.md), [`docs/MEMBERISTIC_INTEGRATIONS_AND_VERIFYISTIC.md`](docs/MEMBERISTIC_INTEGRATIONS_AND_VERIFYISTIC.md) — age-verification setup and its cross-plugin integrations.
- [`docs/MEMBERS_AND_USERS_EXPLAINED.md`](docs/MEMBERS_AND_USERS_EXPLAINED.md) — how WP users, Memberistic members, and linked family profiles relate.
- [`docs/STAFF_GUIDE_CORPORATE_GROUPS.md`](docs/STAFF_GUIDE_CORPORATE_GROUPS.md), [`docs/CORPORATE_GROUPS_PLAN.md`](docs/CORPORATE_GROUPS_PLAN.md) — corporate/group membership operations.
- [`docs/LADIES_TUESDAY_BOOKING.md`](docs/LADIES_TUESDAY_BOOKING.md) — a specific recurring-event booking feature.
- [`docs/WAIVER_IMPORT.md`](docs/WAIVER_IMPORT.md), [`docs/CHECKIN_QR_AND_DEPLOY_2026-05-29.md`](docs/CHECKIN_QR_AND_DEPLOY_2026-05-29.md) — waiver data import and QR check-in mechanics.
- [`docs/CORESTORE-INTEGRATION-PLAN.md`](docs/CORESTORE-INTEGRATION-PLAN.md) — the coreSTORE POS bridge integration.

**Dated historical records** (audits, incidents, releases — read newest-first, never rewritten)
- `docs/AUDIT_*.md` — a series of full-system and targeted audits, each ending with a changelog of exactly what was fixed and the resulting version bumps. [`docs/AUDIT_2026-07-11_SEO_FUNCTION_UI_RESPONSIVE.md`](docs/AUDIT_2026-07-11_SEO_FUNCTION_UI_RESPONSIVE.md) is the most recent and largest, now spanning 7 rounds of fixes.
- `docs/INCIDENT*.md`, `docs/MIGRATION_RECONCILIATION_2026-05-28.md`, `docs/MEMBERS_DIFF_2026-05-29.md` — specific incident postmortems and data-migration records.
- `docs/RELEASE_*.md` — early release notes (superseded by the `AUDIT_*.md` series' own changelogs for anything recent).
- [`docs/WORK_LOG.md`](docs/WORK_LOG.md) — a running log of engineering work across the project's history.
- [`docs/OTTERTEXT_REMOVAL.md`](docs/OTTERTEXT_REMOVAL.md) — record of migrating off a prior SMS provider.

---

## The single-source-of-truth pattern

A theme you'll see throughout this codebase: business facts (name, address,
phone, hours, founding year, review count) live in **one place** —
`guns2ammo/inc/business-info.php`'s `g2a_biz()` function, backed by
Customizer theme_mods — and every plugin reads from it via small helper
functions rather than keeping its own copy. This isn't incidental; several
rounds of this project's audit history exist specifically because plugins
that *didn't* follow this pattern drifted out of sync with the real business
data (a wrong address in an email footer, a stale founding year in page
copy, an AI knowledge base with facts frozen at activation time). If you add
a plugin or template that needs NAP/business data, read it from `g2a_biz()`
(or the plugin-level fallback-helper pattern used in `g2a-booking-engine`
and `formistic`) rather than hardcoding it.

---

## Release process

1. Make your change; run `php -l` (PHP) or `npx tsc --noEmit` (dashboard-app) before committing.
2. Bump the version in the plugin's main file header **and** its `define('..._VERSION', ...)` constant (they must match), and in `readme.txt`'s `Stable tag` / `README.md`'s version field if present.
3. Run `scripts/build-release-zips.sh` to rebuild every zip from the tracked source directories.
4. Copy the freshly built plugin zip into `releases/` with its version in the filename, then prune so only the current and previous version of each artifact remain.
5. Update `INSTALL.md`'s version table to match.
6. Record what changed in the relevant `docs/AUDIT_*.md` (or start a new one) with a short per-item changelog table.

## Tech stack

- **WordPress** (theme + plugins) — PHP 7.4–8.1 depending on the plugin, WooCommerce for e-commerce, Stripe/PayPal/Authorize.Net/Fortis for payments.
- **dashboard-app** — React + TypeScript + Vite, Tailwind CSS.
- **cloudflare-rag-worker** — Cloudflare Workers + Workers AI + Vectorize.
- No build step for the WordPress side — plugins and the theme ship as plain PHP/CSS/JS, installed as zips (see `INSTALL.md`).
