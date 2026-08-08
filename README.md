# Guns 2 Ammo — System Repository

Monorepo for the Guns 2 Ammo indoor shooting range platform: WordPress plugins,
the public theme, staff applications and edge workers.

**[Component versions](VERSIONS.md)** · **[Full feature list](FEATURES.md)** ·
**[Install guide](INSTALL.md)** · **[Deployment](DEPLOYMENT.md)**

---

## Repository layout

```
plugins/     WordPress plugins (installable)
themes/      Public site theme
apps/        Dashboard app + Cloudflare workers
dist/        Built, installable ZIPs of the current versions
docs/        Current documentation
archives/    Superseded code, releases, docs and assets — never installed
bin/         Repo guard scripts (run locally and in CI)
scripts/     Build and maintenance scripts
```

`plugins/g2a-booking-engine/` and `plugins/memberistic-membership-solutions/` are
byte-identical copies of their standalone repositories. CI fails if they diverge —
edit in the standalone repo, then mirror here.

---

## Installing on the site

Ready-to-install ZIPs are in [`dist/`](dist/). Upload via
**WordPress → Plugins → Add New → Upload Plugin**.

**Install in this order** — the booking engine and POS both ask Memberistic for
every membership decision, so Memberistic must be active first:

| Order | Package | Version |
| --- | --- | --- |
| 1 | `memberistic-membership-solutions-1.20.0.zip` | 1.20.0 |
| 2 | `g2a-booking-engine-1.12.0.zip` | 1.12.0 |
| 3 | `g2a-pos-core-3.4.0.zip` | 3.4.0 |
| 4 | `advanced-ffl-checkout`, `verifyistic`, `formistic`, `messageistic`, `g2a-theme-control` | see VERSIONS.md |
| 5 | `g2a-business-api-0.4.3.zip` | 0.4.3 |
| 6 | `guns2ammo-1.27.14.zip` (theme) | 1.27.14 |

`g2a-business-api` serves the staff dashboard at **app.guns2ammo.com** and must
be installed before the dashboard SPA is deployed — the app has no other
backend. Deploy order and the same-origin session contract are in
[DEPLOYMENT.md](DEPLOYMENT.md).

**Do not install** `archives/plugins/guns2ammo-waiver-manager` — it is retired,
superseded by Memberistic's waiver system, and self-disables if activated.

### Before going live

1. **Back up the database.**
2. If migrating from Paid Memberships Pro, run the CSV importer first
   (**Memberistic → Import**). Members not imported are treated as non-members.
3. Review the Guest Pass audit in dry-run mode:
   `wp memberistic guest-pass-audit` (dry-run is the default).
4. Test with Stripe test keys and signed test webhooks before switching to live.
5. Work through the staging QA scenarios in
   `plugins/g2a-booking-engine/docs/DEPLOYMENT-1.10.0.md`.

---

## How the system fits together

**Memberistic is the single source of truth** for membership, entitlement, plans
and booking eligibility. Nothing else makes its own membership assumptions.

```
                    ┌──────────────────────────┐
                    │  Memberistic             │
                    │  Membership_Service      │  ← the only authority
                    │  Entitlement_Service     │
                    └────────────┬─────────────┘
                                 │ memberistic_can_user_book()
                                 │ memberistic_user_has_active_membership()
              ┌──────────────────┼──────────────────┐
              ▼                  ▼                  ▼
     ┌────────────────┐  ┌──────────────┐  ┌────────────────┐
     │ Booking Engine │  │ POS Core     │  │ Theme / Apps   │
     │ checkout policy│  │ counter      │  │ member views   │
     │ state machine  │  │ lookup       │  │                │
     └────────────────┘  └──────────────┘  └────────────────┘
```

**Booking payment rule.** Members on Defender/Patriot/Guardian reserve lanes for
$0. Everyone else pays the full server-calculated price online before the lane is
held — a non-member booking is a *checkout hold*, never a confirmed reservation,
until payment verifies.

Paid Memberships Pro is not required, supported or referenced by any runtime code
path anywhere in this repository.

---

## Development

```bash
# Prove no Paid Memberships Pro dependency anywhere in the system
bin/check-no-pmpro.sh

# Prove the monorepo plugin copies match their standalone repos
bin/check-plugin-sync.sh ../g2a-booking-engine ../memberistic-membership-solutions

# Lint every PHP file in the repo
scripts/lint-php.sh

# Rebuild installable ZIPs into dist/
scripts/build-release-zips.sh
```

Plugin unit tests live in the standalone repos:

```bash
cd ../g2a-booking-engine              && vendor/bin/phpunit -c phpunit.xml   # 68 tests
cd ../memberistic-membership-solutions && vendor/bin/phpunit -c phpunit.xml   # 38 tests
```

### CI gates

Every push runs: whole-repo PMPro check, plugin-sync verification, PHP lint
(8.1 + 8.3), PHPUnit, JS syntax check, PHPCS PSR-12 on new services, and composer
validation. See [FEATURES.md](FEATURES.md#6-quality-gates-ci).

---

## Archives

`archives/` holds everything superseded — retired plugins, historical release
ZIPs, point-in-time audits and incident reports, old release notes, and source
design assets. None of it is installed or executed; it exists for reference and
traceability.
