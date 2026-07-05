# Guns2Ammo RAG Worker (Cloudflare)

A tiny, self-contained Cloudflare Worker that gives the G2A POS "AI Brain" a
proper vector backend:

- **Embeddings**: Workers AI `@cf/baai/bge-m3` (1024 dimensions) — no OpenAI
  key needed for embeddings.
- **Storage/search**: Cloudflare **Vectorize** (cosine similarity).
- **Auth**: every request needs `Authorization: Bearer <AUTH_TOKEN>`.
  Server-to-server only; no CORS is emitted on purpose.

WordPress keeps every document + chunk locally (source of truth and
substring-search fallback) and pushes chunks here for embedding + retrieval,
so an outage of this Worker degrades gracefully instead of breaking the agent.

## API

| Method | Path      | Body                                                    | Returns |
| ------ | --------- | ------------------------------------------------------- | ------- |
| POST   | `/ingest` | `{chunks:[{doc_id,chunk_index,text,metadata?}]}` (≤200) | `{ok, upserted}` |
| POST   | `/query`  | `{query, k?}` (k ≤ 20)                                  | `{ok, results:[{text,score,label,doc_id,source_type,source_uri}]}` |
| POST   | `/delete` | `{doc_id, chunk_count?}`                                | `{ok, deleted}` |
| GET    | `/stats`  | —                                                       | `{ok, vectors, dimensions}` |

Vector ids are `"{doc_id}:{chunk_index}"`, so re-ingesting a document
overwrites its vectors in place and `/delete` removes the id range.

## Deploy (one time, ~5 minutes)

```bash
# 1. Install wrangler and log in to your Cloudflare account
npm i -g wrangler
wrangler login

# 2. From this directory, install dev deps
cd cloudflare-rag-worker
npm install

# 3. Create the Vectorize index (bge-m3 = 1024 dims, cosine metric)
wrangler vectorize create g2a-brain --dimensions=1024 --metric=cosine

# 4. Set the shared bearer token (generate something long and random,
#    e.g. `openssl rand -hex 32`) — you will paste the same value into the POS
wrangler secret put AUTH_TOKEN

# 5. Deploy
wrangler deploy
```

`wrangler deploy` prints the Worker URL, e.g.
`https://g2a-rag-worker.<your-account>.workers.dev`.

## Connect the POS

In **WP Admin → G2A POS → AI Settings**:

1. Set **Brain backend** to `Cloudflare Worker`.
2. Paste the **Worker URL** and the **AUTH_TOKEN** value.
3. Click **Test Cloudflare Worker** — it round-trips `/stats`.
4. Click **Migrate brain to Cloudflare** to push all existing local chunks
   (batched; safe to re-run). New ingests and the nightly website refresh
   push automatically from then on.

## Verify from the command line

```bash
TOKEN=...   # the AUTH_TOKEN value
URL=https://g2a-rag-worker.<account>.workers.dev

curl -s -H "Authorization: Bearer $TOKEN" $URL/stats
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"query":"what are the range hours?","k":3}' $URL/query
```

## Costs

At this scale (a few thousand chunks, tens of queries/day) usage sits well
within the **Cloudflare free tier**: Workers free plan includes 100k
requests/day, Vectorize includes 30M queried + 5M stored vector dimensions
per month, and Workers AI includes a daily free allocation of neurons that
covers bge-m3 embedding of a corpus this size. No paid plan is required.

## Local development

```bash
npm run dev        # wrangler dev (uses your remote Vectorize index)
npm run typecheck  # tsc --noEmit
```
