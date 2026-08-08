# g2a-chat-worker — WPISTIC AI Helper for Guns2Ammo

The chat and agent layer for the Guns2Ammo dashboard. It answers questions
about the business from the business's own records, and runs named operational
agents on demand.

## Why this is a separate Worker

The existing `llm-chat-app` Worker (WPISTIC AI, `chat.wpistic.cloud`) is a
**commercial product**: a paid agent marketplace with Stripe checkout, lead
capture, Turnstile intake and a `selectOffer()` that pushes a purchase into the
conversation. Three problems with embedding it in a client's operations
dashboard:

1. It would try to **sell WPistic services to Guns2Ammo staff** mid-conversation.
2. Its `/api/chat` **filters out caller-supplied system messages**
   (`m.role !== "system"`), so it cannot be grounded in Guns2Ammo data from
   outside — it builds its own prompt from its own intent classifier.
3. `/api/chat` there has **no authentication**, only IP rate limiting. Fine for
   a public marketing site; not for a bot that discusses revenue, customers and
   ATF records.

So this Worker exists, and the WPistic product stays untouched.

## What was already built

`g2a-rag-worker` is deployed and does the retrieval half properly:

| | |
|---|---|
| Embedding | `@cf/baai/bge-m3`, 1024 dims |
| Index | `g2a-brain` (Vectorize, 1024 dims, cosine — dimensions match) |
| Routes | `POST /ingest`, `POST /query`, `POST /delete`, `GET /stats` |
| Auth | `Authorization: Bearer <AUTH_TOKEN>`, timing-safe compare |

It has no LLM call — retrieval only. This Worker supplies the generation half
and **calls that Worker rather than re-embedding**. That is deliberate: a query
embedded with a different model than the corpus still produces a
correctly-shaped vector, so the failure looks like plausible-but-wrong results
rather than an error. One embedding implementation, one model.

## Architecture

```
dashboard (browser)
  └─> g2a-business-api (WordPress — holds the API key)
        └─> g2a-chat-worker            Bearer API_KEY
              ├─> g2a-rag-worker       Bearer RAG_TOKEN   → g2a-brain
              └─> Workers AI           via AI Gateway 42069
```

**The browser never holds the API key.** `ALLOWED_ORIGINS` exists for a direct
browser path, but leave it empty unless you accept the key being readable in
the JS bundle by anyone who opens devtools. A key that reaches a browser is a
public key.

## API

All routes except `/health` require `Authorization: Bearer <API_KEY>`.

### `POST /api/chat`

```jsonc
{
  "messages": [{ "role": "user", "content": "which lanes are out of service?" }],
  "agent": "range-operations",   // optional
  "facts": "Lanes booked today: 14/20"  // optional, live figures from the dashboard
}
```

Returns an **SSE stream**, plus:

| Header | Meaning |
|---|---|
| `x-g2a-agent` | which agent answered (empty for general chat) |
| `x-g2a-passages` | how many knowledge-base passages backed the answer |
| `x-g2a-degraded` | non-empty when retrieval failed — `rag_timeout`, `rag_unreachable`, `rag_http_500`, `rag_not_configured` |

`x-g2a-degraded` matters: it tells the bubble the answer was produced **without**
the knowledge base, which changes how much an operator should trust it. Surface
it in the UI rather than swallowing it.

A `system` message in `messages` is dropped. The guardrails are not negotiable
by the caller.

### `GET /api/agents`

Lists the nine agents with `slug`, `title`, `description`, `task_label`,
`sources`. No prices — this is not a marketplace.

### `POST /api/agents/{slug}/run`

```jsonc
{ "task": "Which lanes need maintenance this week?", "facts": "..." }
```

Same SSE response. An agent run is a chat with one turn and a forced agent —
same code path, so the two cannot drift apart.

### `GET /health`

No auth. Reports **whether** each secret is configured, never any value.

## Agents

`range-operations`, `compliance-officer`, `inventory-buyer`, `sales-analyst`,
`membership-growth`, `customer-care`, `marketing-seo`, `training-classes`,
`daily-briefing`.

Each is a role with a focused brief. Two briefs carry hard rules that exist for
legal reasons, not stylistic ones:

- **Compliance Officer** never states a sale is cleared unless the compliance
  state says so, and always defers the decision to a licensed person.
- **Range Operations** never advises issuing a rental firearm without a
  verified photo ID and a signed waiver.

## Grounding

`src/prompt.ts` is the safety-critical file. The model is told to answer from
retrieved context, to say *"I don't have that in the knowledge base"* when it
cannot, and never to invent a number, date, serial, stock level, order id or
compliance state.

This is why the honest answer to "it must give a perfect answer" is: it gives a
**grounded** answer, and says so when it can't. A bot that always produces an
answer is a bot that invents them, and here that means inventing bound-book
entries.

## Deploy

```bash
cd g2a-chat-worker
npm install

wrangler secret put API_KEY      # what g2a-business-api will present
wrangler secret put RAG_URL      # https://g2a-rag-worker.<subdomain>.workers.dev
wrangler secret put RAG_TOKEN    # the existing g2a-rag-worker AUTH_TOKEN

wrangler deploy
curl https://g2a-chat-worker.<subdomain>.workers.dev/health
```

`AI_GATEWAY_ID` is set to `42069` in `wrangler.toml` so generation is logged and
cached alongside everything else in the gateway.

**I have not deployed this.** Deploying needs your Cloudflare credentials, which
I don't handle. The commands above are what you run.

## Tests

```bash
npm install     # required first — the runner needs this package's own esbuild
npm test        # 35 assertions, no framework
npm run typecheck
npm run build   # wrangler dry-run: bundles and prints the bindings, deploys nothing
```

`npm run build` is a safety net, not a build step — a Worker needs no compile,
`wrangler deploy` bundles on the fly. Running it first tells you the code
bundles and the bindings resolve without shipping anything.

Covers: fail-closed auth (an unconfigured Worker refuses everything), that
`/health` never echoes a secret, that a caller-supplied system turn is dropped,
that retrieval failure degrades visibly instead of silently, and that the
grounding rules are present in the built prompt.

Verified the suite can fail: flipping the unconfigured-auth branch to allow, and
removing the "never invent" rule, trips exactly those two assertions and nothing
else.

## Not done yet

- **Not deployed**, so never exercised against real Workers AI or the real index.
- **The business collectors have never run against live data.**
  `G2A\POS\Ai\BusinessKnowledgeCollector` now feeds the brain the operational
  picture — trading, compliance, range, inventory, membership, suppliers — on an
  hourly cron, and `POST /ai/brain/refresh-business` triggers it by hand. But it
  has only been exercised against a `$wpdb` double in tests. Until it runs on
  the real database, `g2a-brain` still holds website content only, and the
  Helper will correctly answer "I don't have that" to operational questions.
- **Deliberately excluded from the brain: customer PII and firearm serials.**
  The collectors emit aggregates and operational state only. See the class
  docblock for why — the short version is that `/query` does not filter on
  `scope`, so anything ingested is readable by anyone who can chat.
- **No dashboard bubble yet** — this is the backend it will call.
- `g2a-rag-worker` stores a `scope` on each chunk at ingest but `/query` does
  not filter on it, so a chunk marked internal is retrievable by any caller
  holding the token. Worth closing before ingesting anything sensitive.
