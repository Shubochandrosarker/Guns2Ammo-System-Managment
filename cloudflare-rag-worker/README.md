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

## Scopes: keeping internal data out of a public chatbot

Every chunk is stored with a `scope` (`public` by default; the POS business
collectors write `internal`). `/query` can now filter on it, and **the filter
is decided by which token you present, not by a request parameter**:

| Token | Can read | Can ingest / delete / stats |
|---|---|---|
| `AUTH_TOKEN` | any scope — omit `scope` for all, or pass one to narrow | yes |
| `PUBLIC_AUTH_TOKEN` | **`public` only, always** | no — 403 |

A `scope` field in the request body would have been simpler, and wrong: the
caller sets it, so a bug or a compromise in a public-facing chatbot would just
omit it and read the shop's margin, its open NICS count and its dead stock.
Binding the restriction to the credential makes it unbypassable from outside.

`PUBLIC_AUTH_TOKEN` is optional. Leave it unset and nothing changes — there is
simply no restricted caller.

### Required before the filter works

Vectorize only applies a metadata filter when a **metadata index** exists for
that property. Create it once:

```bash
wrangler vectorize create-metadata-index g2a-brain --property-name=scope --type=string
```

**Without this index Vectorize silently ignores the filter and returns
everything** — no error, no warning. That is precisely the leak this feature
exists to prevent, so `/query` also re-checks the `scope` on every returned
match and drops any that disagree. The index makes it efficient; the re-check
makes it safe. A test covers the missing-index case specifically.

Note the index only covers vectors written *after* it is created. Re-ingest
existing documents (`POST /ai/brain/refresh-business` and the site refresh in
the POS plugin) so everything is indexed.

### Setting the public token

```bash
openssl rand -base64 32
wrangler secret put PUBLIC_AUTH_TOKEN
```

Give that value only to public-facing callers. It cannot write to the corpus
and cannot read anything marked internal.

## Deploy (one time, ~5 minutes)

```bash
# 1. From this directory, install the pinned dev dependencies
cd cloudflare-rag-worker
npm ci

# 2. Log in to your Cloudflare account
npx wrangler login

# 3. Create the Vectorize index (bge-m3 = 1024 dims, cosine metric)
npx wrangler vectorize create g2a-brain --dimensions=1024 --metric=cosine

# 4. Set the shared bearer token (generate something long and random,
#    e.g. `openssl rand -hex 32`) — you will paste the same value into the POS
npx wrangler secret put AUTH_TOKEN

# 5. Deploy
npm run deploy:check
npm run deploy
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

## Tests

```bash
npm install
npm test     # 21 assertions covering scope isolation
```

Covers: a restricted token pinned to public even when it asks for internal; the
missing-metadata-index case still blocking the leak; write/delete/stats refused
for restricted callers; and the full token behaving exactly as before, which is
what the POS plugin depends on.

Verified the suite can fail — removing the credential pin trips exactly the
seven leak assertions and leaves the other fourteen passing.

## Costs

At this scale (a few thousand chunks, tens of queries/day) usage sits well
within the **Cloudflare free tier**: Workers free plan includes 100k
requests/day, Vectorize includes 30M queried + 5M stored vector dimensions
per month, and Workers AI includes a daily free allocation of neurons that
covers bge-m3 embedding of a corpus this size. No paid plan is required.

## Local development

```bash
npm run dev        # wrangler dev (local Vectorize; Workers AI runs remotely)
npm run deploy:check # validate and bundle without publishing
npm run cf-typegen # regenerate Cloudflare binding/runtime types
npm run typecheck  # tsc --noEmit
```
