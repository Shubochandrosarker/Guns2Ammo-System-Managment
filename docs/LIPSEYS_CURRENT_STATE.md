# Lipsey's Integration — Current State Audit

Date: 2026-07-15
Scope: `g2a-pos-core` (primary integration; `/home/user/G2A-POS-Solutions` mirrors the same content) and `advanced-ffl-checkout` (secondary, standalone integration).
Type: read-only audit. No code was modified.

---

## 1. Architecture map

### 1.1 Two parallel Lipsey's integrations exist

| | g2a-pos-core (primary) | advanced-ffl-checkout (secondary) |
|---|---|---|
| Entry class | `includes/Wholesalers/Lipseys/LipseysProvider.php` | `includes/class-wpistic-ffl-g2a-lipseys.php` |
| Accounts | Multi-row (`g2a_wholesalers` table) | Single account only (one `wp_options` row) |
| Credential storage | AES-256-GCM encrypted LONGTEXT | **Plaintext** in `wp_options` (`wpistic_ffl_lipseys_settings`) |
| Catalog sync | CSV import + full API CatalogFeed | API CatalogFeed, capped at 5,000 rows |
| Ordering | Warehouse + DropShip w/ policy engine | DropShipFirearm only, manual admin click |
| Scheduling | Hourly inventory cron | None (manual buttons only) |

These two integrations do not share tables, credentials, or token caches. If both are active on the same site, the same dealer credentials are stored twice (once encrypted, once plaintext) and two independent token logins race against Lipsey's session endpoint.

### 1.2 g2a-pos-core layering

```
WholesalerRegistry (static provider registry, keyed by provider code)
  ├─ LipseysProvider  (code 'lipseys')          includes/Wholesalers/Lipseys/LipseysProvider.php
  ├─ DavidsonsProvider / RsrProvider / SportsSouthProvider
  └─ filter g2a_pos_register_wholesaler_providers for 3rd-party providers

LipseysProvider
  ├─ LipseysApiClient      auth (Token header, 14-min transient cache keyed BY WHOLESALER ID),
  │                        CatalogFeed, PricingQuantityFeed, ValidateItem, CatalogFeed/Item (UPC),
  │                        APIOrder (warehouse), DropShip, OneDay shipping, OrderStatus
  ├─ LipseysCsvImporter    dealer CSV -> g2a_wholesaler_products (+ categories + MAP rules)
  ├─ LipseysCatalogMapper  CSV/API item -> normalized product row
  ├─ LipseysPayloadBuilder order arrays -> Lipsey's JSON payloads
  ├─ LipseysDropShipPolicy dropship program rules (firearm/accessory split, CA accessory block)
  └─ Media/LipseysImageUrls  IMAGENAME -> https://www.lipseyscloud.com/images/<file>

REST surface: includes/API/WholesalerController.php
  /wholesalers (list, upsert), /{id}/catalog/csv, /{id}/catalog/api-sync,
  /{id}/inventory/sync, /{id}/test-credentials, /{id}/dropship, /{id}/warehouse-order,
  /{id}/validate-item, /{id}/upc, /{id}/mirror-image, /{id}/promote, /route-upc

Admin UI: admin/src/views/Wholesalers.tsx (+ Distributors.tsx, InventoryImport.tsx, MapPricing.tsx)
```

### 1.3 Tables (includes/Database/Migrator.php)

| Table | Purpose | Key lines |
|---|---|---|
| `g2a_wholesalers` | One row per wholesaler **account** — `provider_code`, `account_number`, `credentials` (encrypted LONGTEXT), `api_endpoint`, `settings`, `last_sync_at`. `UNIQUE KEY uniq_provider (provider_code, account_number)` | Migrator.php:384-398 |
| `g2a_wholesaler_products` | Vendor catalog staging (per `wholesaler_id` + `vendor_sku`), incl. `map_price`, `stock_qty`, `image_filename`, `wc_product_id` back-link | Migrator.php:400-441 |
| `g2a_wholesaler_categories` | Vendor categories: `import_enabled`, `dropship_enabled`, `markup_percent`, `wc_category_id` (never populated — see §5) | Migrator.php:443-457 |
| `g2a_wholesaler_orders` | Warehouse/dropship order log with request/response payloads | Migrator.php:459-492 |
| `g2a_wholesaler_sync_runs` | Per-import run stats (created/updated/skipped/failed) | Migrator.php:494-514 |
| `g2a_distributors` / `g2a_distributor_sync_runs` | Legacy v0.6 CSV pipeline (Woo products directly); creds in `credentials_encrypted` (libsodium envelope) | Migrator.php:654-689 |

Note the important MySQL subtlety in `uniq_provider`: `account_number` is nullable, and NULLs never collide in a UNIQUE key, so **multiple `lipseys` rows with NULL account numbers are permitted by the schema**.

### 1.4 Provider resolution

- REST calls resolve accounts **by row id** (`WholesalerRepository::find($id)`), then map `provider_code` → provider object. Per-account safe.
- The **distributor CSV mirror pipeline resolves by provider code**: `WholesalerImportBridge::resolve_wholesaler_id()` → `WholesalerRepository::findByCode()`. This is where the dual-account defect lives (§2).
- Credentials are decoded per row: `LipseysProvider::client()` (LipseysProvider.php:510-524) → `WholesalerRepository::decodeCredentials()` → `CredentialCipher::decrypt()`.

---

## 2. Dual-account defect analysis (CONFIRMED)

The business intent: two Lipsey's accounts — e.g. a firearms account and a second "accessories" account. Two independent bugs make the second account partially unreachable and let its credentials collide with the first account's.

### 2.1 Defect A — provider-code resolution always returns the lowest-ID row

`/home/user/Guns2Ammo-System-Managment/g2a-pos-core/includes/Database/WholesalerRepository.php:22-33`

```php
public function findByCode( string $code ): ?array {
    ...
    "SELECT * FROM {$t} WHERE provider_code=%s ORDER BY id ASC LIMIT 1",
```

`ORDER BY id ASC LIMIT 1` deterministically returns the **first-created** `lipseys` row. Any second account (higher id) can never be selected through this path.

Call path that hits it:

1. `Inventory\SyncService::run_distributor()` (SyncService.php:65-74) — every scheduled or manual distributor CSV sync calls
2. `Wholesalers\WholesalerImportBridge::mirror_csv()` → `resolve_wholesaler_id()` (**WholesalerImportBridge.php:85**):
   ```php
   $existing = $repo->findByCode( $provider->code() );
   if ( $existing ) { return (int) $existing['id']; }
   ```
   The bridge receives `$context['account_number']` from the distributor row (SyncService.php:71) but **only uses it when creating a new row** — it never uses it to disambiguate between existing accounts.

Consequences:

- A distributor feed configured for the accessories account mirrors its entire catalog, MAP rules, categories and sync-run stats **into the firearms account's `wholesaler_id`**, and stamps `last_sync_at` on the wrong row (`markSyncedNow`, WholesalerImportBridge.php:70).
- The accessories row never accumulates catalog rows, so browse/promote/dropship routing (`MultiVendorRouter`) can never route to it — it is effectively invisible.

Fix direction: resolve by `(provider_code, account_number)` — the bridge already has the account number in `$context`; add `findByCodeAndAccount(string $code, string $account)` and fall back to `findByCode` only when exactly one row exists for the code (or error out loudly on ambiguity).

### 2.2 Defect B — blank account numbers let one account's credentials overwrite another

`/home/user/Guns2Ammo-System-Managment/g2a-pos-core/includes/Database/WholesalerRepository.php:51-60` (inside `upsert()`):

```php
if ( ! $existing ) {
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$t} WHERE provider_code=%s AND (account_number=%s
         OR (%s='' AND (account_number IS NULL OR account_number='')))",
        $providerCode, $account, $account
    ) );
}
```

When the caller does not supply a row `id` (the admin form's "new wholesaler" path — `Wholesalers.tsx:101-109` sends `id: form.id || undefined`; and `WholesalerController::upsert()` passes `id: 0` through, WholesalerController.php:49) **and the incoming account number is blank**, the query matches *any existing `lipseys` row whose account number is blank/NULL* and **UPDATEs it in place** (WholesalerRepository.php:84-86) — display name, endpoint, status and, critically, `credentials`.

Concrete failure sequence:

1. Account #1 ("Lipsey's — Firearms") is saved without an account number (the field is optional; the auto-created bridge row from §2.1 is *always* created with `account_number => ''`, WholesalerImportBridge.php:93).
2. Staff opens the form to add Account #2 ("Lipsey's — Accessories"), also leaving account number blank (or not knowing it matters).
3. `upsert()` finds Account #1 via the blank-account branch and **overwrites its credentials with the accessories login**. There is now one row, wrong credentials, and the firearms account's password is gone (the encrypted blob was replaced, not merged — `normalizeCredentials()` only preserves stored values when the *incoming* value is blank, WholesalerRepository.php:118-138).
4. Every sync/order path that used Account #1's id now authenticates as the accessories account.

Aggravating factors:

- `uniq_provider (provider_code, account_number)` does not prevent duplicate NULL-account rows, so depending on insertion order you can get **both** failure modes: silent overwrite (blank matches blank) *and* ambiguous `findByCode` (two blank rows, lowest id wins).
- The comment at WholesalerRepository.php:43-45 shows the id-passthrough was added to fix the *edit* case, but the *create-second-account* case was left unguarded.

Fix direction: require a non-empty, per-account `account_number` for any provider with more than one row; on blank-account match, refuse the implicit update and return a "duplicate account — supply an account number or edit the existing row" error to the UI.

### 2.3 Token cache is per-row (correct)

`LipseysApiClient` caches its auth token in transient `g2a_pos_lipseys_token_%d` keyed by wholesaler id (LipseysApiClient.php:32,45), so two accounts would not share tokens *if* row resolution were fixed. The advanced-ffl-checkout plugin uses a single global transient (`wpistic_ffl_lipseys_token`) — fine there because it is single-account by design.

---

## 3. Credential storage assessment

| Store | Mechanism | Verdict |
|---|---|---|
| `g2a_wholesalers.credentials` | `Wholesalers/Crypto/CredentialCipher` — AES-256-GCM (`enc2:` prefix), 12-byte nonce, 16-byte tag, key = SHA-256("g2a-pos\|credentials\|v2\|" + AUTH_KEY); requires AUTH_KEY ≥ 32 chars (throws otherwise). Legacy `enc1:` AES-CBC and bare base64-JSON are read-only for migration. | **Good.** Authenticated encryption, versioned, fail-closed on weak AUTH_KEY. Caveats: (a) key derived from AUTH_KEY, so a wp-config leak decrypts everything and AUTH_KEY rotation orphans all stored creds with no re-key tool; (b) legacy bare-base64 blobs are still silently accepted on read (CredentialCipher.php:43-44) — effectively plaintext at rest until next save; (c) admin UI copy says "AES-256-CBC" (Wholesalers.tsx:226) — stale, actual is GCM. |
| `g2a_distributors.credentials_encrypted` | `Security/Crypto` — libsodium secretbox envelope (per-row DEK wrapped by KEK from `G2A_POS_KEK` env/constant). | **Good**, with one weak default: if no `G2A_POS_KEK` is set, the KEK itself is generated and stored in `wp_options` (`g2a_pos_kek_v1`, Crypto.php:84-89) — same database as the ciphertext, so a DB dump alone decrypts. |
| `SecretStore` (misc plugin secrets) | Wraps CredentialCipher; returns legacy plaintext values as-is until re-saved. | Acceptable migration shim. |
| advanced-ffl-checkout `wpistic_ffl_lipseys_settings` | **Plaintext dealer email + password in `wp_options`** (class-wpistic-ffl-g2a-lipseys.php:57-62, 280-300). Blank-password-keeps-existing UX is correct, but nothing is encrypted. | **Bad.** Any DB read (backup, SQL export, other plugin) exposes the Lipsey's dealer login. Should reuse CredentialCipher-style sealing or be retired in favor of the pos-core integration. |

No hard-coded Lipsey's credentials, emails, or account numbers were found in any repo or in git history (see the companion secret-scan report).

---

## 4. Sync pipeline behavior

### 4.1 Scheduling (all WP-Cron; no Action Scheduler usage anywhere in the plugin)

`includes/Core/Plugin.php::schedule_all_crons()` (Plugin.php:241-287):

| Hook | Interval | What it does |
|---|---|---|
| `g2a_pos_distributor_sync` | daily | `SyncService::run_due('daily')` — HTTP/local-file CSV pull → `Importer` (Woo products) → `WholesalerImportBridge::mirror_csv` (vendor catalog). **Runs through the defective `findByCode` path.** |
| `g2a_pos_distributor_hourly` | hourly | same, for `schedule='hourly'` distributors |
| `g2a_pos_wholesaler_inventory_sync` (WHOLESALER_INV_HOOK) | hourly | `Plugin::cron_wholesaler_inventory_sync()` (Plugin.php:202-232) — iterates **every active wholesaler row by id** (correct, not affected by Defect A) and calls `syncInventory()`; only stamps `last_sync_at` on real success. |
| `g2a_pos_vendor_price_capture` | weekly | wholesale price history snapshots per wholesaler row |
| `g2a_pos_woo_catalog_sync` | daily | Woo→POS identity-graph backfill (not vendor-facing) |

Full catalog API sync (`importCatalogApi`) and CSV import are **manual-only** (REST buttons); only inventory/pricing deltas are cron'd.

### 4.2 Failure behavior — does anything zero inventory on a failed feed? **No.**

Verified guards in `LipseysProvider`:

- HTTP non-200 → early error return, no writes (LipseysProvider.php:93-98, 228-233).
- Lipsey's HTTP-200-with-failure envelope (`success:false` / `authorized:false`) → hard failure, no writes (LipseysProvider.php:99-111, 234-246).
- **Empty item list from an authorized account → treated as an error** (`empty_catalog_feed` / `empty_pricing_feed`, LipseysProvider.php:119-126, 255-262) rather than "synced 0 rows" — this is the specific guard that prevents a bad feed from zeroing the catalog.
- `syncInventory()` only issues per-SKU `UPDATE`s for rows present in the feed (`WholesalerProductRepository::updateLive`, WholesalerProductRepository.php:63-82). SKUs absent from the feed are left untouched.
- Cron wrapper logs failures and skips `markSyncedNow` on `ok:false` (Plugin.php:212-227).

Flip side (a real gap, opposite direction): **nothing ever marks stale/discontinued vendor SKUs out of stock.** `last_seen_at` is written on every import but no sweep uses it, so an item Lipsey's drops from the feed keeps its last known `stock_qty` forever and remains routable/promotable.

The advanced-ffl-checkout `sync_catalog()` also never zeroes: it upserts only rows present in the response, but it silently processes at most 5,000 items (`wpistic_ffl_lipseys_catalog_sync_limit`, class-wpistic-ffl-g2a-lipseys.php:173-179) — Lipsey's full feed is larger, so that catalog table is chronically incomplete (skips are at least reported in the result).

### 4.3 Product mapping to WooCommerce

Two distinct paths create Woo products:

1. **Distributor CSV path (bulk, optional auto-publish):** `Inventory\Importer::upsert_product()` (Importer.php:167-221) — creates `product` posts (status `draft` unless the distributor row has `auto_publish=1`), sets `_sku`, `_regular_price`(=MSRP or cost×markup), `_wc_cog_cost`, `_g2a_*` compliance meta, `_stock_status` from feed qty, barcode row. Dedupe via `g2a_inventory_external_refs` (source/source_ref/UPC/mfg SKU).
2. **Vendor-catalog promotion path (per-SKU, curated):** `Wholesalers\Promotion\VendorProductPromoter::promote()` (VendorProductPromoter.php:30-152) — draft by default (`publish` only on explicit request), price = explicit override > cost×category-markup > MSRP, MAP rule captured into `g2a_map_rules`, CDN image mirrored, `wc_product_id` back-linked on the vendor row so re-promotes update in place.

Batch/queue behavior: API catalog import chunks 200 items with `wp_cache_flush()` + `set_time_limit(60)` between batches (LipseysProvider.php:19,148-203); CSV importer flushes every 500 rows.

### 4.4 Category mapping — confirmed: none exists

- Schema has `g2a_wholesaler_categories.wc_category_id` (Migrator.php:449).
- The only writer that *could* set it is `WholesalerCategoryRepository::setFlags()` (it's in the allowed-fields list, WholesalerCategoryRepository.php:61), but the REST endpoint `update_category` never passes it (WholesalerController.php:100-115) and the admin UI has no control for it.
- `VendorProductPromoter` never calls `wp_set_object_terms()` / assigns `product_cat` — grep confirms zero `product_cat` writes anywhere in the plugin (only reads in ProductCatalog/WooCatalogSync/theme-adjacent code).
- Net effect: every promoted/imported product lands **uncategorized** in WooCommerce; vendor categories only drive import/dropship toggles and markup lookups.

### 4.5 Image handling

- `LipseysImageUrls::cdnUrl()` — pure mapping `IMAGENAME` → `https://www.lipseyscloud.com/images/<rawurlencoded basename>`; passes through full URLs; strips path traversal (LipseysImageUrls.php:16-31). Unit-tested.
- `VendorImageMirror::mirror()` — side-loads the CDN image into the media library, idempotent by source URL, optional featured-image pin. Exposed via REST `mirror_image` (WholesalerController.php:418-457) and called best-effort during promotion.
- No bulk image backfill job; images are fetched one-by-one at promote time. Lipsey's is the only provider with a CDN mapping (`cdnUrlFor()` match arm, WholesalerController.php:487-492).

---

## 5. Gaps vs. a safe staging pipeline (fetch → normalize → stage → review → publish)

What exists already maps surprisingly well to the first three stages — the gaps are in identity, review, and lifecycle:

| Stage | Current state | Gap |
|---|---|---|
| Fetch | API CatalogFeed / PricingQuantityFeed / dealer CSV, with good failure envelopes | No scheduled full-catalog fetch (manual only); no raw-feed retention for replay/diffing; single-account only in the bridge path (Defect A) |
| Normalize | `LipseysCatalogMapper` (CSV + API shapes), unit-tested | Mapper output goes straight to upsert; no schema-version or row-level validation report |
| Stage | `g2a_wholesaler_products` **is** a staging table, keyed per wholesaler, with sync-run stats | No diff view (what changed vs. last run), no quarantine state for suspicious rows (e.g. price swings, MAP below cost), no stale-row lifecycle (`last_seen_at` unused) |
| Review | Promotion is explicitly manual + draft-by-default (good); category import/dropship toggles exist | No bulk review queue UI (promote is one SKU at a time via REST); `import_enabled` flag is stored but **not enforced** by any bulk publisher because none exists; no approval audit trail beyond sync runs |
| Publish | `VendorProductPromoter` idempotent per SKU, MAP captured, image mirrored | No category assignment (§4.4), no bulk publish with per-category rules, no automatic re-price/re-stock of already-promoted products from later feeds (only `updateLive` touches vendor rows, not the linked Woo product's `_stock`/`_price` — promoted products drift stale until manually re-promoted) |

That last point deserves emphasis: **inventory sync updates `g2a_wholesaler_products.stock_qty` but never propagates to the linked WooCommerce product**, so a storefront item promoted from Lipsey's keeps selling after Lipsey's hits zero stock. The link (`wc_product_id`) exists; the propagation job does not.

---

## 6. Prioritized fix list

1. **P0 — Defect B (credential overwrite):** in `WholesalerRepository::upsert()`, when no `id` is supplied and the blank-account branch matches an existing row, refuse the implicit update (or require the UI to send an explicit id). Enforce non-empty `account_number` when a second row for the same `provider_code` is created. (WholesalerRepository.php:51-60)
2. **P0 — Defect A (lowest-ID resolution):** replace `findByCode()` usage in `WholesalerImportBridge::resolve_wholesaler_id()` with `(provider_code, account_number)` resolution using the `$context['account_number']` already passed by `SyncService`; error loudly when >1 row matches ambiguously. (WholesalerRepository.php:27; WholesalerImportBridge.php:85)
3. **P1 — Stock propagation:** after `syncInventory()`, push qty/price to linked Woo products (`wc_product_id` join) so promoted items can't oversell.
4. **P1 — advanced-ffl-checkout plaintext credentials:** encrypt `wpistic_ffl_lipseys_settings` (reuse CredentialCipher pattern) or deprecate the duplicate integration entirely.
5. **P2 — Stale-SKU lifecycle:** nightly sweep marking vendor rows unseen for N days as out of stock (and their linked Woo products), using the already-written `last_seen_at`.
6. **P2 — Category mapping:** expose `wc_category_id` in the category admin/REST and have `VendorProductPromoter` assign `product_cat` terms on promote.
7. **P2 — Scheduled catalog sync:** optional daily `importCatalogApi()` cron per account (it already has all the batching/failure guards), gated per account via `settings`.
8. **P3 — Credential-key hygiene:** re-key tool for AUTH_KEY rotation; migrate remaining legacy `enc1:`/bare-base64 blobs eagerly instead of on-next-save; fix the "AES-256-CBC" copy in Wholesalers.tsx.
9. **P3 — Bulk review/publish queue:** diff-aware review UI over `g2a_wholesaler_products` honoring `import_enabled` + per-category markup, batching `VendorProductPromoter`.
10. **P3 — ffl-checkout catalog cap:** page the CatalogFeed or raise/paginate past the 5,000-row cap so the secondary catalog table isn't silently partial.
