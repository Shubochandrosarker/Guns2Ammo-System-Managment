# Component versions

Current shipping versions of every component in this repository.
Installable builds of each row live in [`dist/`](dist/).

_Last updated: 8 August 2026._

## WordPress plugins — `plugins/`

| Component | Version | Purpose | Install order |
| --- | --- | --- | --- |
| **memberistic-membership-solutions** | **1.20.0** | Membership authority — plans, members, entitlement, waivers, corporate groups, Stripe billing | 1 |
| **g2a-booking-engine** | **1.12.0** | Lane/class/event bookings, payments, front desk, calendar, email automation | 2 |
| **g2a-pos-core** | **3.4.0** | Point of sale — counter checkout, membership lookup, receipts, inventory | 3 |
| advanced-ffl-checkout | 1.21.1 | FFL transfer workflow + dealer portal | 4 |
| verifyistic | 1.4.7 | ID / age verification and check-in QR verification | 4 |
| formistic | 2.1.1 | Form builder used by public site forms | 4 |
| messageistic | 0.8.1 | Transactional messaging / SMS bridge | 4 |
| g2a-theme-control | 1.0.1 | Theme-level toggles and presentation control | 4 |
| g2a-business-api | 0.4.3 | Internal business API surface | 4 |

**Install order matters for the first three.** Memberistic must be active before the
booking engine, because the booking engine asks Memberistic for every entitlement
decision. POS reads membership state from Memberistic too.

## Theme — `themes/`

| Component | Version | Purpose |
| --- | --- | --- |
| guns2ammo | 1.27.14 | Public site theme (Elementor-compatible) |

## Applications & workers — `apps/`

| Component | Purpose |
| --- | --- |
| dashboard-app | Staff/owner dashboard front end |
| g2a-chat-worker | Cloudflare Worker — site chat |
| cloudflare-rag-worker | Cloudflare Worker — retrieval/RAG backend for chat |

Workers and the dashboard are deployed independently of WordPress; see
`docs/DASHBOARD_DEPLOYMENT_PLAN.md`.

## Archived — `archives/`

| Path | Contents |
| --- | --- |
| `archives/plugins/guns2ammo-waiver-manager/` | **Retired.** Superseded by Memberistic's waiver system. Self-disables if activated; do not install. |
| `archives/releases-legacy/` | Historical release ZIPs (26 builds) predating this reorganisation |
| `archives/docs/` | Point-in-time audits, incident reports and dated migration notes |
| `archives/release-notes/` | Superseded release notes (2.1.0, 2.5.0) |
| `archives/assets/` | Source documents and design assets (DOCX/PDF/ZIP) |

Nothing in `archives/` is installed or executed. It is retained for reference and
traceability only.

## Requirements

- WordPress 6.2+
- PHP 8.0+ (8.1+ for POS, which requires `>=8.1`)
- WooCommerce 7.0+ (for WooCommerce-routed booking payments)
- Stripe account (test + live keys)

## Membership authority

Memberistic is the **single source of truth** for membership, entitlement, plan and
booking-eligibility decisions across the entire system. Paid Memberships Pro is not
required, supported, or referenced by any runtime code path — see
`plugins/g2a-booking-engine/docs/PMPRO-REMOVAL.md`. This is enforced on every push
by `bin/check-no-pmpro.sh`.
