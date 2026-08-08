# Release 2026-07-13 — Full Plugin Crossmatch & Unified Release Versions

Every plugin shipped by the Guns 2 Ammo system was crossmatched against its
dedicated plugin repository. All version drift and functionality gaps were
resolved: for each plugin, the newest working code from both lines was merged
file-by-file (direction decided from git history, not version headers), the
two copies were made **byte-identical**, and a unified release version was
stamped. New release zips are in `releases/`.

## Final release versions

| Plugin | Monorepo before | Dedicated repo before | Unified release | Release zip |
|---|---|---|---|---|
| Advanced FFL Checkout | 1.9.4 | 1.14.0 (`ffl-checkout--solutions`) | **1.15.0** | `advanced-ffl-checkout-1.15.0.zip` |
| G2A Booking Engine | 1.9.9.11 | 1.9.9.4 (`g2a-booking-engine`) | **1.9.9.12** (DB 1.5.2) | `g2a-booking-engine-1.9.9.12.zip` |
| G2A POS Core | 3.1.9 | 3.1.7 (`G2A-POS-Solutions`) | **3.2.0** | `g2a-pos-core-3.2.0.zip` |
| Memberistic | 1.10.7 | 1.11.0 (`memberistic-membership-solutions`) | **1.12.0** (DB 1.5.0) | `memberistic-membership-solutions-1.12.0.zip` |
| Messageistic | 0.5.3 | 0.6.0 (`Messageistic`) | **0.7.0** (DB 1.3.0) | `messageistic-0.7.0.zip` |
| Formistic | 2.1.0 | *(repo was empty)* (`Formistic`) | **2.1.0** (seeded) | `formistic-2.1.0.zip` (unchanged) |

Monorepo-only components, unaffected (no dedicated repo in scope):
Verifyistic 1.4.4, Guns2Ammo theme 1.27.13, G2A Theme Control 1.0.0,
Waiver Manager 1.4, dashboard-app, g2a-business-api, cloudflare-rag-worker.

## Per-plugin crossmatch results

### Advanced FFL Checkout — 1.15.0
- **Gap:** the monorepo copy was 10 releases behind. The dedicated repo's
  1.10.0–1.14.0 line added 14 feature classes (A&D ledger, background-check
  providers, booking bridge, dealer login, excise tax, fraud score, NMI
  gateway, ID verification, Lipsey's, multi-sale watcher, regulatory watch,
  state-rules admin, Verification Hub Phase B), vendored FPDF/charts, a test
  suite, real license activation, multi-firearm transfer creation, and 4473
  PDF generation. It had already backported the monorepo's July audit fixes.
- **Ported monorepo → dedicated:** dealer-scorecard Print button glyph fix
  (U+1F5B6 → standard 🖨️ U+1F5A8).
- **Verified:** every monorepo audit fix confirmed present in the unified
  tree; `php -l` clean on all 71 PHP files.

### G2A Booking Engine — 1.9.9.12
- **Gap (dedicated → monorepo):** the `includes/modules/ffl-checkout/`
  integration module existed only in the dedicated repo. Ported; the module
  loader auto-discovers and auto-activates it on upgrade, and its hook and
  schema dependencies were verified against the newer monorepo code.
- **Gap (monorepo → dedicated):** the full 1.9.9.4 → 1.9.9.11 audit line
  (52 files: payments, REST controllers, admin CRUD, modules, installer,
  rate-limiter/atomicity fixes). Zero hunk conflicts — drift was one-sided.

### G2A POS Core — 3.2.0
- **Taken from dedicated repo:** migration-stampede safety (atomic
  `add_option` lock, front-end gate), FFL Checkout bridge integration,
  `queueMicrotask` fixes across 33 admin views, dependency bumps
  (incl. eslint-plugin-react-hooks 4→7), integration tests, lint configs.
- **Taken from monorepo:** July 11–12 audit rounds (membership billing,
  knowledge pack, audit-log and bound-book repositories), wholesaler-cron
  fix (no `last_sync_at` stamp on failed sync).
- **3-way merged:** `includes/Core/Plugin.php` (both fixes preserved;
  `Roles::register_caps()` now runs only inside the locked migration path —
  intentional stampede fix from the dedicated repo).
- **Follow-up:** `assets/admin/admin.js` bundle predates the full
  queueMicrotask source sweep — run `npm run build` in `g2a-pos-core/admin`
  before the next release.

### Memberistic — 1.12.0
- **Gap (dedicated → monorepo):** FFL Checkout bridge
  (`class-ffl-checkout-bridge.php` + registry card + plugin wiring); hook
  points verified against the newer templates.
- **Gap (monorepo → dedicated):** seven releases (1.10.1–1.10.7): Stripe
  cancel-propagation, token-bridge re-skin (`assets/token-bridge.css` +
  enqueue), admin/import/settings updates, capabilities, scheduler,
  corporate module, POS/Verifyistic bridges, REST controllers.
- Both the FFL bridge registration and the token-bridge enqueue confirmed
  present in the unified `class-plugin.php`.

### Messageistic — 0.7.0
Real fixes existed on **both** sides:
- **From monorepo (July security audit):** webhook signature validation
  fails closed (Jasmin/SMS-Gate), Twilio signature verified against raw
  `$_POST`, webhook routes gated to the active provider, atomic GET_LOCK
  around every send, booking/membership integrations rewritten against real
  `g2ab_*`/Memberistic hooks (dedicated repo still had placeholder hooks
  that never fired), root `index.php` guard.
- **From dedicated repo:** the complete Advanced-FFL-Checkout integration
  fix (correct hook, `\WpisticFFL\DB::table()`, workflow-pack statuses
  remapped to the FFL plugin's real status set — the monorepo had only half
  of this fix, so its FFL automations could still never fire), plus test
  suite, tooling, and SECURITY-AUDIT.md.
- Dedicated repo's compliance test suite passes (27 checks).
- **Release note:** webhook provider gating will 403 late delivery reports
  from a previously active provider unless widened via the
  `messageistic_webhook_allowed_providers` filter.

### Formistic — 2.1.0 (dedicated repo seeded)
- The `Formistic` repo contained no plugin code at all (stale pre-rebrand
  README only). Seeded with the full 2.1.0 tree (38 files), merged README,
  and reconstructed the missing 2.0.7 / 2.1.0 changelog entries in
  readme.txt. No version bump — no functional change.

## Cross-plugin dependency check
- Advanced FFL Checkout 1.15.0 wants Booking Engine ≥ 1.9.9.4 for real
  pickup scheduling — satisfied by 1.9.9.12. ✔
- Booking Engine's ffl-checkout module writes to `wpistic_ffl_*` tables —
  schema verified against Advanced FFL Checkout 1.15.0. ✔
- Memberistic / Messageistic / POS FFL bridges all self-gate on
  `WPISTIC_FFL_VERSION` / class existence — inert when the FFL plugin is
  absent. ✔

## Verification performed
- `diff -rq` between each monorepo plugin dir and its dedicated repo
  (excluding repo-level `.git*`/`.github`): **empty for all six plugins**.
- `php -l` on every changed/added PHP file across all plugins: clean.
- Messageistic compliance suite: 27/27 pass.

## Deployment order (recommended)
1. Advanced FFL Checkout 1.15.0 (others integrate against it)
2. G2A POS Core 3.2.0
3. G2A Booking Engine 1.9.9.12
4. Memberistic 1.12.0
5. Messageistic 0.7.0
6. Formistic 2.1.0 (no site change; repo sync only)
