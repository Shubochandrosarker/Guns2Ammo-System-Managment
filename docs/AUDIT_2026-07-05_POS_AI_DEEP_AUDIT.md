# Deep Audit — POS, Lipsey's integration, AI models (2026-07-05)

Scope: the Lipsey's wholesaler sync failures shown in the admin
(accounts 666340 / 800075), a full audit of `g2a-pos-core`, the
"AI models showing 404" report, and the move to OpenRouter +
Guns2Ammo knowledge training with an optional Cloudflare Worker
RAG backend. All fixes in this document shipped on branch
`claude/g2a-pos-ai-audit-e5fffd` (PR #50).

---

## 1. Lipsey's sync errors — root causes

The wire protocol was already correct (POST
`/api/Integration/Authentication/Login` with `{Email, Password}`,
`Token` header on feeds). The failures were around it:

| Symptom | Root cause | Fix |
|---|---|---|
| `lipseys_auth_failed: HTTP 200 — authorized=false — Login Failed` (666340) | Re-saving a wholesaler in the React admin sent blank credentials and the REST upsert **overwrote the stored password with the blank** (the old PHP form had a keep-if-blank guard; the REST path didn't). Credentials were also never trimmed. | Guard + trim moved into `WholesalerRepository::upsert()` so every save path is safe; row **Edit** now updates by id; blank email/password keep stored values. |
| `lipseys_api_rejected` (800075) | Auth **succeeds** for this account; the PricingQuantityFeed then rejects it — and the UI discarded the `detail` field so the real reason was invisible. Also, Lipsey's soft-rejects expired tokens with HTTP 200 + `authorized:false`, which never triggered re-auth (only HTTP 401 did). | Sync errors now return the full Lipsey's detail with proper HTTP codes (400/502); soft rejection forces one re-auth + retry; a per-row **Test credentials** action isolates login from feed entitlement. |

Other wholesaler fixes: registered the previously dead
`catalog/api-sync` route; catalog import now processes in batches
of 200 with a 120s fetch timeout; UI help note explains that API
credentials ≠ dealer portal login and feed access is a separate
Lipsey's entitlement.

**Operator actions still required**
1. Re-enter the Lipsey's *API integration* credentials for
   666340, then use **Test credentials**.
2. For 800075: auth works — ask the Lipsey's rep to enable
   API catalog/pricing feed entitlement on that account.

## 2. POS security & money integrity

| Severity | Finding | Fix |
|---|---|---|
| Critical | Any clerk could mint store credit / loyalty points (`loyalty/{id}/adjust` required only `g2a_pos_access`) | New `g2a_pos_manage_finance` capability (admins + `g2a_pos_manager`, self-healing on boot) required on loyalty adjust + gift-card issue; every mint is written to the hash-chained audit log |
| Critical | Any clerk could issue arbitrary-value gift cards | Same capability gate + audit |
| High | Inventory adjust trusted client `before_qty` (race + tampering; bad values synced to WooCommerce) | Server reads latest ledger qty under `SELECT … FOR UPDATE` in a transaction; client before/after ignored |
| High | Cart/checkout hardcoded `tax_total = 0`; `/orders` accepted client tax totals | New `TaxService` (option `g2a_pos_tax`: enabled, default rate 8.3% Mesa AZ combined TPT, per-class overrides, taxable flag, discount proration); tax always computed server-side; discounts clamped to `[0, subtotal]`; new `GET/POST settings/tax` REST |
| High | 11 of 14 cron hooks (incl. NICS 3-business-day default-proceed) scheduled only at activation — silently died after plugin updates | `schedule_all_crons()` self-heal runs from both `activate()` and `maybe_upgrade()` |
| Medium | Layaway payments raced (unlocked read-modify-write) | Locked transaction, mirroring the gift-card pattern |
| Medium | POS sales left WC orders `pending` → stock never decremented (oversell risk) | `WooBridge::complete_payment()` on paid POS orders and on split-tender finalization → WC reduces stock |
| Medium | Voiding a gift-card tender didn't restore the card balance | `GiftCardRepository::credit()` restores value under lock; audited. (Legacy tender rows without a stored card id still need manual re-issue.) |

## 3. AI model 404 errors — three causes, all fixed

1. **The dashboard-selected model was never sent.**
   `Anthropic_Client` hardcoded a fallback model and ignored the
   per-connection `modelName`. Now `resolve_model()` reads the
   connection record; fallback updated to `claude-opus-4-8`.
2. **Provider "Test" probes used retired models** —
   `claude-3-haiku-20240307` (retired 2026-04) and
   `gemini-1.5-flash`. Defaults now `claude-opus-4-8` /
   `gemini-2.5-flash`; OpenAI/OpenRouter probes verify the
   configured model exists in the provider catalog instead of
   green-lighting any key.
3. **Model routing was write-only.** The purpose→model map the
   dashboard edits is now consulted by `Agent_Runner` (per
   department) and `Insight_Generator`, with safe fallbacks.

Dashboard: live provider model catalog (new
`model-connections/{id}/catalog` endpoint, 10-min transient
cache) feeds a datalist on the model field; mock data uses real
model ids; the fabricated "RAG stores" card (fake "pgvector ·
1,204 docs") was replaced with an honest empty state.

## 4. OpenRouter + Guns2Ammo knowledge training

`g2a-pos-core` AI gateway now has provider presets:
**OpenRouter** (recommended; endpoint, attribution headers,
hosted default `anthropic/claude-sonnet-4.5`), generic
OpenAI-compatible, or Ollama (local tags only there — the old
defaults 404'd on hosted endpoints). Embeddings select their own
provider (OpenAI `text-embedding-3-small`, Ollama, Cloudflare, or
none → keyword fallback). AI Settings has provider pickers and a
live connection test.

Training pipeline (existing Brain: normalize → chunk → embed →
cosine retrieve):
- **Default knowledge pack** (`DefaultKnowledgePack`) — curated
  facts: identity/NAP/hours (6030 E Main St Suite 103, Mesa AZ,
  (602) 715-2677, since 2014), memberships (Defender
  $29.99/mo · Patriot $39.99/mo · Guardian $59.99/mo + annual
  rates, guest pricing), training (AZ CCW classroom $85 / live
  fire $149.99, FFL $35, NFA $95/$295), instructor bios (Alen
  Olson, Nicholas Steigert), facility (6 lanes, online booking,
  QR check-in). Seeded idempotently (hash-deduped) from the brain
  refresh cron, `POST ai/brain/seed-defaults`, or the AiBrain UI.
- **Website + business data** — ingests the theme's
  `llms-full.txt`, published pages/posts, WooCommerce products
  (WP_Query + `_price` meta, capped, hash-gated), and live
  membership plan rows.
- **Brain stats** — `GET ai/brain/stats` (docs/chunks/embedded %
  by source) shown in AiBrain.
- **Grounding** — agent system prompt states it is the Guns2Ammo
  business AI and must ground + cite retrieved knowledge.

## 5. Cloudflare Worker RAG (optional backend)

New `cloudflare-rag-worker/`: TypeScript Worker with bearer-auth
`POST /ingest`, `POST /query`, `POST /delete`, `GET /stats`;
Workers AI `@cf/baai/bge-m3` embeddings (1024-dim) + Vectorize
(cosine). WP side: `brain_backend` = `local` | `cloudflare`
(`CloudflareBrain` driver; documents stay locally as source of
truth; graceful fallback to local keyword search on Worker
errors), plus `POST ai/brain/migrate-cloudflare` batched
migration and AiSettings fields/test button. Deploy steps are in
`cloudflare-rag-worker/README.md` (`wrangler vectorize create
g2a-brain --dimensions=1024 --metric=cosine`, secret AUTH_TOKEN,
`wrangler deploy`).

## 6. Verification

- `g2a-pos-core` PHPUnit: **262 tests, 958 assertions — OK**
  (≈39 new behavioral tests: tax math, locked inventory,
  layaway, gift-card void restore, credential retention, Lipsey's
  soft-rejection retry, knowledge pack, gateway presets, brain
  backends).
- `g2a-business-api` PHPUnit: **301 tests, 709 assertions — OK**
  (22 new: model resolution, routing, probes, catalog parsing).
- `php -l` clean on every touched file; `tsc --noEmit` clean for
  POS admin, dashboard-app, and the Worker; POS admin bundle
  rebuilt (Vite); dashboard-app builds.

## 7. Known limitations / follow-ups

- Model routing executes only Anthropic connections in
  `g2a-business-api` (its only executor); non-Anthropic routes
  fall back safely. Unifying it onto the POS OpenRouter gateway
  is a candidate next step.
- WC order line-level tax lines aren't synthesized (order totals
  are mirrored); WC-native tax reports would need WC tax tables.
- No React UI yet for the tax option (REST + sane defaults only).
- The Wholesalers (API) and Distributors (CSV) screens remain
  separate; consider merging to reduce operator confusion.
