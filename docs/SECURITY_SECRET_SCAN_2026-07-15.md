# Repo-Wide Secret Scan — 2026-07-15

Read-only audit. No secret values are reproduced in this document — masked previews only (first 3 characters).

## Scope

| Target | Coverage |
|---|---|
| `/home/user/Guns2Ammo-System-Managment` | Working tree **and full git history** (135 commits, all refs via `git rev-list --all` + `git grep` sweeps and `git log --diff-filter=A` filename analysis) |
| `/home/user/ffl-checkout--solutions`, `g2a-booking-engine`, `G2A-POS-Solutions`, `memberistic-membership-solutions`, `Messageistic`, `Formistic` | Working tree only (as tasked) |
| `Guns2Ammo-System-Managment/releases/*.zip` | Archive listings + targeted content checks |

Patterns swept (working tree and, for the main repo, every commit): Stripe (`sk_live_`, `sk_test_`, `pk_live_`, `pk_test_`, `rk_live_`, `whsec_`), Twilio (`AC[0-9a-f]{32}`, `SK[0-9a-f]{32}`, auth-token-style literals), Anthropic (`sk-ant-`), OpenRouter (`sk-or-v1-`), Google (`AIza…`), GitHub (`ghp_`, `github_pat_`), Slack (`xox[baprs]-`), SendGrid (`SG.`), Mailgun, AWS (`AKIA…`), private key blocks (`BEGIN … PRIVATE KEY`), `Authorization: Basic/Bearer <literal>`, WordPress application-password format (`xxxx xxxx xxxx xxxx xxxx xxxx`), generic `password/secret/token/api_key = '<literal>'` assignments, `define()` constants with inline secrets, hardcoded account numbers, email-address census across all history blobs, sensitive filenames ever committed (`.env*`, `wp-config*`, `*postman*`, `*.pem`, `*.key`, `*.sql`, `*.csv`, `credentials*`, `secrets*`).

## Headline verdict

**No live payment, messaging, or API credentials were found in any working tree or anywhere in git history.** No Stripe keys, no Twilio SIDs/tokens, no Lipsey's passwords, no private keys, no `.env` files with real values, no Authorization headers with embedded credentials, no application passwords, no hardcoded account numbers.

**However, one serious data exposure was found: a real customer PII dataset (1,922 range-waiver records) is committed to git and shipped inside release zips.** Details below — this is a privacy/breach issue rather than a credential leak, and it drives the remediation order.

## Findings table

| # | Location | Type | Masked preview | Live vs. placeholder | Rotation required |
|---|---|---|---|---|---|
| 1 | `Guns2Ammo-System-Managment/g2a-pos-core/tests/fixtures/range_waivers_otterwaiver.csv` (846 KB, 1,922 rows; in git history since commit `fb5194e`, 2026-06-14; also present in `/home/user/G2A-POS-Solutions/g2a-pos-core/tests/fixtures/` working tree; also packaged inside `releases/g2a-pos-core-3.1.8.zip`, `-3.1.9.zip`, `-3.2.0.zip`) | **Customer PII** — real OtterWaiver export: full names, DOB (1,846 rows), email (1,922), phone (1,922), street address, emergency contacts, **76 minors' names/ages**, signed-waiver Document/Certificate URLs | e.g. email `dor***`, DOB `03***` (sample row) | **LIVE** — real customers (cross-checked: the same personal Gmail/Yahoo/Juno addresses recur hundreds of times across history blobs) | N/A (not a credential) — requires **removal + history purge + possible breach assessment**; the embedded OtterWaiver document URLs should be treated as bearer links and reviewed for public accessibility |
| 2 | `Guns2Ammo-System-Managment/docs/MEMBERS_DIFF_2026-05-29.md` | Customer PII — 8 personal member email addresses in a migration diff doc | `wha***@gmail.com` et al. | LIVE (real members) | N/A — redact from doc and history |
| 3 | `INSTALL.md:192-194` (main repo) | Stripe key references | `sk_test_...`, `sk_live_...`, `whsec_...` | **Placeholder** — literal `...` documentation stubs for `MEMBERISTIC_STRIPE_*` constants | No |
| 4 | `g2a-business-api/tests/unit/SecretsTest.php` (working tree + history) | Anthropic-style key | `sk-***` (`sk-ant-super-secret-value-xyz`) | **Placeholder** — obvious test fixture string | No |
| 5 | `g2a-pos-core/tests/Unit/GatewayProviderTest.php:93` | OpenRouter-style key | `sk-***` (`sk-or-test`) | **Placeholder** — test value | No |
| 6 | `/home/user/G2A-POS-Solutions/docs/Lipseys.postman_collection.json` | Postman collection (Lipsey's API) | `email` var: empty; `password` var: empty; `token` var: empty; sample item `RU1***` (public catalog SKU), sample UPC `736***` | **Clean** — credential variables are blank `{{email}}`/`{{password}}` placeholders; no captured tokens or saved responses | No |
| 7 | `dashboard-app/.env.example`, `.env.production.example`; `/home/user/G2A-POS-Solutions/pos-app/.env.example` | `.env` files | Only public URLs (`https://guns2ammo.com`) and mock toggles | **Placeholder/example** — no secrets; no real `.env` was ever committed (verified against all-history filename list) | No |
| 8 | advanced-ffl-checkout `class-wpistic-ffl-g2a-lipseys.php` (`wpistic_ffl_lipseys_settings` option) | Lipsey's dealer email+password **storage design** | n/a (values live only in the WP database, not the repo) | Design finding: stored **plaintext in wp_options** at runtime | **Conditional** — if this plugin was ever used in production, treat the Lipsey's dealer password as exposed to anyone with DB/backup access and rotate it; also see LIPSEYS_CURRENT_STATE.md §3 |
| 9 | `g2a-pos-core` `Security/Crypto.php:84-89` | Encryption KEK **storage design** | option `g2a_pos_kek_v1` | Design finding: when `G2A_POS_KEK` is unset, the key-encryption key is auto-generated and stored **in the same database** as the ciphertext | No rotation of a repo secret needed; set `G2A_POS_KEK` outside the DB in production |

Additional negative results worth recording:

- **Git history email census** (all blobs, all commits): apart from `@example.com` fixtures and public business addresses (`sales@`/`owner@`/`staff@guns2ammo.com`, vendor library authors from `composer.lock`), all real personal addresses trace back to finding #1 (the waiver CSV) and finding #2 (members diff doc). `faisal@ottertext.com` appears only as a vendor contact in OtterText-removal docs.
- Phone number `+1602715…` in theme templates is the business's public phone (intentional).
- Release zips: no `.env`, `wp-config`, `.pem`, or Postman files inside any zip; the only sensitive payload is the waiver CSV in the three `g2a-pos-core-3.1.x` zips (finding #1).
- Six sibling repos: no key-pattern hits, no env/credential files, no inline credential assignments beyond settings-form field names. `Messageistic/SECURITY-AUDIT.md` documents (does not contain) prior credential-handling fixes.
- Test/demo phone numbers (`+1555…`, `+1480555…`) and mock tokens (`mock-token`, `test-token`, `fresh-token`) are all placeholders.

## Recommended remediation order

1. **Remove the waiver CSV (finding #1) everywhere — highest priority.**
   a. Replace `g2a-pos-core/tests/fixtures/range_waivers_otterwaiver.csv` with a small synthetic fixture (the test `OtterWaiverCsvImporterTest.php:97` needs only the column layout, not real people).
   b. Do the same in `/home/user/G2A-POS-Solutions`.
   c. Rebuild and replace `releases/g2a-pos-core-3.1.8/3.1.9/3.2.0.zip`; if any of those zips were distributed or uploaded to a public/staging site (`/wp-content/plugins/g2a-pos-core/tests/fixtures/...` would be web-readable on a deployed install), verify the file is not currently reachable over HTTP on guns2ammo.com and delete it from deployed installs.
   d. Purge the blob from git history (`git filter-repo --path g2a-pos-core/tests/fixtures/range_waivers_otterwaiver.csv --invert-paths`) on every remote that carries these commits, then force-push and invalidate forks/clones per policy.
   e. Assess notification obligations: 1,922 data subjects incl. 76 minors, with DOB+contact data — check applicable state breach statutes if the repo or zips were ever public.
2. **Redact finding #2** (8 member emails in `docs/MEMBERS_DIFF_2026-05-29.md`) and include the file in the same history rewrite.
3. **Rotate the Lipsey's dealer password if advanced-ffl-checkout ran in production** (finding #8), then encrypt or retire that settings store.
4. **Set `G2A_POS_KEK` as an environment constant in production** so the distributor-credential KEK leaves the database (finding #9); rotate stored distributor creds after the move.
5. **Add prevention:** commit a `.gitignore` rule for `tests/fixtures/*real*`/exports, add a pre-commit secret+PII scanner (gitleaks with a custom rule for CSVs containing `Birthday`/`Email`/`Phone` headers), and add `tests/` to `.distignore`/build excludes so fixtures can never ship in release zips again.
6. No credential rotation is required for findings #3-#7 (placeholders/clean).
