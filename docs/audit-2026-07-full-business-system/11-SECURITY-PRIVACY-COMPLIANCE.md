# Security, Privacy, and Compliance

**Scope honesty statement:** this was a *sampling* pass, not an exhaustive line-by-line security audit of ~1,000 PHP files. It concentrated on the areas the audit brief flags as highest-risk (REST permission callbacks, SQLi-shaped queries, waiver PDF access control, webhook authentication) and on components directly relevant to the client's priorities. `g2a-pos-core` (316 PHP files, the largest and most compliance-sensitive component — POS transactions, ATF/4473 bound-book records) was sampled for REST/auth patterns only, not functionally security-reviewed end-to-end. Treat this as a strong first pass, not a certification.

## REST permission callback audit

A repo-wide search found 30 routes registered with `permission_callback => '__return_true'`. Every one spot-checked resolved to a legitimate design, not an oversight:

| Route pattern | Plugin | Why `__return_true` is correct here |
|---|---|---|
| `/availability`, `/resources`, `/payment-methods`, `/events`, `/events/{id}/occurrences`, `/bookings/{uuid}/status`, `/nonce` | Booking Engine | Read-only public data (availability grid, resource list, gateway list, event list) or a cryptographically unguessable UUID lookup (`[a-f0-9-]{36}` — a real UUID, not a sequential id) or an explicitly-reasoned public nonce endpoint ("a nonce is not a secret," per the code's own comment) |
| `/bookings/{uuid}/confirm-payment` | Booking Engine | Fallback payment confirmation, keyed by the same unguessable UUID |
| `/auth/token` | POS Core | The login/token-issuance endpoint itself — must be reachable pre-authentication by definition. `/auth/revoke` and `/auth/refresh` correctly require `is_user_logged_in()` |
| `/compliance/state-rules` (GET) | Advanced FFL Checkout | Public reference data (state firearm compliance rules) — genuinely public-interest content, not customer data |
| `/newsletter/unsubscribe` | Formistic | Explicitly reasoned in-code: the HMAC token in the URL **is** the authentication, not a nonce — correct pattern for an email-clicked unsubscribe link days after any session nonce would have expired |
| Webhook receivers (Stripe, WooCommerce) | Memberistic, Booking Engine | Correctly `__return_true` at the REST-routing layer, with HMAC signature verification performed **inside** the callback body — this is the standard, correct pattern (WordPress's `permission_callback` runs before body access, so webhook signature checks that need the raw payload must happen in the callback, not the permission gate) |

**No open holes found among the sampled routes.** State-mutating routes (`POST /bookings`, `POST /events/book`) use dedicated permission callbacks (`permission_create_booking`), not `__return_true`.

**Not exhaustively checked:** the remaining ~450+ REST routes across all plugins (this repo has 7 REST namespaces per `01-SYSTEM-INVENTORY.md`) were not individually inspected. Recommend a scripted sweep (grep every `register_rest_route` call, extract its `permission_callback`, flag anything that isn't a recognized-safe pattern or a capability/login check) as a fast, high-value Phase 0/1 follow-up — the pattern above gives a template for what "safe" looks like in this codebase.

## SQL injection

One specific candidate flagged during initial recon (`verifyistic/includes/class-verifyistic-db.php:134`) was investigated and **confirmed safe**: it uses `sanitize_sql_orderby()` (WordPress core, purpose-built for safely handling dynamic `ORDER BY` clauses that `$wpdb->prepare()` cannot parameterize) for the sort direction/column, and `$wpdb->prepare()` for all value placeholders. The `phpcs:ignore` comments present suppress a phpcs static-analysis false positive (the linter can't always follow that a string was already the output of `->prepare()`), not a real gap.

Not exhaustively swept — recommend `grep -rn "\$wpdb->query\|\$wpdb->get_results\|\$wpdb->get_row\|\$wpdb->get_var" --include=*.php | grep -v "prepare("` repo-wide as a fast follow-up to catch any unprepared query this sampling missed.

## Waiver PDF and file access (PII-sensitive)

**Verified strong, defense-in-depth:**
1. Storage directory (`wp-content/uploads/memberistic-waivers/`) is hardened at creation with `.htaccess` (`Require all denied` for Apache 2.4+, plus a legacy `Order allow,deny / Deny from all` fallback) and a silent `index.php` — blocks direct URL access even if a filename is guessed.
2. The only in-application route that serves these files (`class-waivers.php`'s export/print/poster handlers, `maybe_handle_export()`) additionally requires `memberistic_current_user_can(self::CAP)` before serving content.
3. Minor gap (`G2A-MED-001`, Low severity): the export/print URLs are nonce-URLs (`wp_nonce_url()`) but the receiving handler never actually calls `wp_verify_nonce()`/`check_admin_referer()` — the capability check alone gates access. CSRF-hygiene issue, not an access-control bypass (an attacker still needs the victim to already be an authenticated, capability-holding staff member).

## Webhook authentication

- **Stripe:** HMAC signature verification with a 5-minute replay window (`verify_webhook_signature()`, `class-stripe-service.php:596-625`) — correctly implemented, uses `hash_equals()` for timing-safe comparison.
- **WooCommerce:** HMAC SHA-256 signature verification (`class-memberships-controller.php:1533-1564`) — the code's own comments note a **previously-fixed** issue where an empty configured secret used to short-circuit signature checking entirely; confirmed the current code guards against that (an empty secret no longer bypasses verification).
- **Idempotency:** Stripe webhook event processing is idempotent via persisted processed-event-ids plus a MySQL advisory lock specifically engineered (per the code's own comments) to survive both retried deliveries and an object-cache flush — a genuinely above-average implementation of this pattern.

## Rate limiting

Confirmed present on at least the Memberistic public checkout endpoint (`handle_checkout_request()`): an atomic (MySQL-advisory-locked, not a plain get/set-transient race) rate limiter, 8 attempts per 10 minutes per IP, specifically engineered to prevent a scripted burst from seeding pending memberships and triggering arbitrary-address email sends. Not confirmed present on other public-facing endpoints (login, other form submissions) this pass.

## Authentication

- **POS Core:** JWT-based (`AuthController`), `/auth/token` public (correct, it's the login endpoint), `/auth/revoke` and `/auth/refresh` require `is_user_logged_in()`. Signature verification confirmed to happen before decode, not trusting an algorithm field from the token itself (a common JWT vulnerability class this code avoids) — verified during initial recon, not re-derived line-by-line this session; treat as high-confidence but not re-verified to the same standard as the Critical findings elsewhere in this audit.
- **dashboard-app token storage:** not independently verified this session which mechanism (localStorage/sessionStorage/httpOnly cookie) is used — flagged as an open item given the audit brief's specific interest in this.

## PII exposure / bulk export / low-privilege staff access

- Waiver PDF/export access is capability-gated (see above).
- Whether `g2a_cashier` (the lowest POS role) or `g2ab_staff`/`g2ab_instructor` (the lowest Booking roles) have access to bulk customer exports, full member directories, or FFL/ATF compliance records was **not independently traced this pass** for every relevant screen — recommend a dedicated capability-matrix review (which capability gates which data-export screen, cross-referenced against which role has which capability) as a Phase 1 follow-up, given this is explicitly called out in the audit brief as a priority area and this pass could not cover the full surface (POS Core's 316 files in particular) to the same depth as the client-priority items.

## Secrets

Confirmed structurally: wholesaler credentials (`WholesalerRepository`) are encrypted at rest via a dedicated `CredentialCipher` class before storage, not stored plaintext. POS AI gateway API keys are "sealed" via a `SecretStore` class (`AiController::save_settings()`) before persisting to options, with logic to re-seal legacy unsealed values on next save. **No secret values were read, printed, or exposed during this audit** — only the presence and structure of the protection mechanisms was confirmed, per the audit brief's instruction.

## Dependency vulnerabilities

`npm audit` (run as part of the dashboard-app build verification, `12-PERFORMANCE-RELIABILITY-OBSERVABILITY.md`) reported **2 vulnerabilities (1 moderate, 1 high)** in dashboard-app's dependency tree — not itemized/triaged this pass; recommend `npm audit` (without `--force`) be reviewed and addressed as a standard hygiene task. PHP dependency vulnerability scanning (e.g., `composer audit`) was not run this session.

## Compliance-adjacent controls (technical/operational only — not legal advice)

Per the audit brief's instruction, this section audits technical and operational controls only; it does not constitute legal or compliance advice, and specialist review is recommended for age verification, NICS-related timing, and FFL recordkeeping specifically.

- **Age verification:** Verifyistic is a dedicated plugin for this, separate from the general age-gate popup concern the client raised about "the chatbot" — confirmed these are different systems (`G2A-CRIT-006`'s investigation).
- **FFL/ATF records:** `g2a-pos-core` has dedicated tables (`g2a_form_4473`, `g2a_bound_book`, `g2a_atf_reports`) — existence confirmed via schema inventory; **content/correctness of the compliance logic itself was not audited this pass** and should be a dedicated specialist review given the regulatory stakes, well beyond what a general software audit can certify.
- **Messaging consent (SMS/email):** Messageistic has dedicated `Consent_Event_Repository` and `Privacy_Manager` classes (confirmed present via file inventory); the actual consent-capture UX and STOP-keyword handling were not independently traced this pass.

## Score: Security — 71/100

Evidence-based, not inflated by feature count: the *sampled* surface (REST permissions, SQLi candidate, webhook auth, PDF access control, secret storage) shows a genuinely disciplined security posture with correct, non-trivial patterns (HMAC + replay windows, advisory-locked idempotency, encrypted credential storage). The score is not higher because roughly 70% of the codebase's REST surface and the entirety of the low-privilege-staff-access-to-sensitive-data question were not exhaustively verified this pass — this is an honest reflection of sampling coverage, not a claim that unaudited code is unsafe.
