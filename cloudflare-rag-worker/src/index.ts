/**
 * Guns2Ammo POS — Cloudflare Worker RAG service.
 *
 * The WordPress plugin (g2a-pos-core, BrainService with brain_backend =
 * "cloudflare") pushes pre-chunked knowledge-base text here. The Worker
 * embeds each chunk with Workers AI (@cf/baai/bge-m3, 1024 dims) and stores
 * the vectors in a Vectorize index; queries embed the question and return
 * the top-K chunks with their document metadata.
 *
 * Server-to-server only: every request must carry
 * `Authorization: Bearer <AUTH_TOKEN>` (a wrangler secret). No CORS headers
 * are emitted on purpose — browsers have no business calling this directly.
 *
 * Endpoints (all JSON):
 *   POST /ingest  {chunks:[{doc_id,chunk_index,text,metadata?}]}
 *   POST /query   {query, k?}
 *   POST /delete  {doc_id, chunk_count?}
 *   GET  /stats
 */

export interface Env {
  AI: Ai;
  VECTOR_INDEX: VectorizeIndex;
  AUTH_TOKEN: string;
}

const EMBEDDING_MODEL = '@cf/baai/bge-m3';
/** Vectorize metadata is capped per vector; keep the stored excerpt bounded. */
const MAX_METADATA_TEXT = 4000;
/** bge-m3 batch limit is generous; stay small so one request never times out. */
const EMBED_BATCH = 25;
/** Upper bound of chunk ids swept by /delete when chunk_count is unknown. */
const DELETE_SWEEP = 512;

interface IngestChunk {
  doc_id: number;
  chunk_index: number;
  text: string;
  metadata?: Record<string, string>;
}

interface EmbeddingResponse {
  data: number[][];
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function unauthorized(): Response {
  return json({ ok: false, error: 'unauthorized' }, 401);
}

function timingSafeEqual(a: string, b: string): boolean {
  const enc = new TextEncoder();
  const ab = enc.encode(a);
  const bb = enc.encode(b);
  if (ab.length !== bb.length) return false;
  let diff = 0;
  for (let i = 0; i < ab.length; i++) diff |= ab[i] ^ bb[i];
  return diff === 0;
}

function authorized(request: Request, env: Env): boolean {
  if (!env.AUTH_TOKEN) return false; // refuse to run without a token set
  const header = request.headers.get('Authorization') ?? '';
  if (!header.startsWith('Bearer ')) return false;
  return timingSafeEqual(header.slice('Bearer '.length).trim(), env.AUTH_TOKEN);
}

async function embed(env: Env, texts: string[]): Promise<number[][]> {
  const out: number[][] = [];
  for (let i = 0; i < texts.length; i += EMBED_BATCH) {
    const batch = texts.slice(i, i + EMBED_BATCH);
    const res = (await env.AI.run(EMBEDDING_MODEL as never, {
      text: batch,
    } as never)) as unknown as EmbeddingResponse;
    if (!res || !Array.isArray(res.data) || res.data.length !== batch.length) {
      throw new Error('embedding model returned an unexpected shape');
    }
    out.push(...res.data);
  }
  return out;
}

async function handleIngest(request: Request, env: Env): Promise<Response> {
  const body = (await request.json().catch(() => null)) as { chunks?: IngestChunk[] } | null;
  const chunks = body?.chunks;
  if (!Array.isArray(chunks) || chunks.length === 0) {
    return json({ ok: false, error: 'chunks[] required' }, 400);
  }
  if (chunks.length > 200) {
    return json({ ok: false, error: 'too many chunks in one request (max 200)' }, 400);
  }
  const clean = chunks.filter(
    (c) => c && Number.isFinite(Number(c.doc_id)) && typeof c.text === 'string' && c.text.trim() !== ''
  );
  if (clean.length === 0) {
    return json({ ok: false, error: 'no valid chunks' }, 400);
  }

  const vectors = await embed(env, clean.map((c) => c.text));
  const toUpsert: VectorizeVector[] = clean.map((c, i) => ({
    // Stable id — re-ingesting a document overwrites its vectors in place.
    id: `${Number(c.doc_id)}:${Number(c.chunk_index) || 0}`,
    values: vectors[i],
    metadata: {
      doc_id: Number(c.doc_id),
      chunk_index: Number(c.chunk_index) || 0,
      text: c.text.slice(0, MAX_METADATA_TEXT),
      label: String(c.metadata?.label ?? ''),
      source_type: String(c.metadata?.source_type ?? ''),
      source_uri: String(c.metadata?.source_uri ?? ''),
      tags: String(c.metadata?.tags ?? ''),
      scope: String(c.metadata?.scope ?? 'public'),
    },
  }));

  // Vectorize V2 returns { mutationId }; the legacy binding returns
  // { ids, count } — accept either so the Worker runs on both.
  const mutation = (await env.VECTOR_INDEX.upsert(toUpsert)) as { mutationId?: string };
  return json({ ok: true, upserted: toUpsert.length, mutation_id: mutation.mutationId ?? null });
}

async function handleQuery(request: Request, env: Env): Promise<Response> {
  const body = (await request.json().catch(() => null)) as { query?: string; k?: number } | null;
  const query = typeof body?.query === 'string' ? body.query.trim() : '';
  if (query === '') {
    return json({ ok: false, error: 'query required' }, 400);
  }
  const k = Math.max(1, Math.min(20, Number(body?.k) || 5));

  const [vector] = await embed(env, [query]);
  const matches = await env.VECTOR_INDEX.query(vector, {
    topK: k,
    returnValues: false,
    returnMetadata: 'all',
  });

  const results = matches.matches.map((m) => ({
    id: m.id,
    score: m.score,
    doc_id: Number(m.metadata?.doc_id ?? 0),
    chunk_index: Number(m.metadata?.chunk_index ?? 0),
    text: String(m.metadata?.text ?? ''),
    label: String(m.metadata?.label ?? ''),
    source_type: String(m.metadata?.source_type ?? ''),
    source_uri: String(m.metadata?.source_uri ?? ''),
  }));
  return json({ ok: true, count: results.length, results });
}

async function handleDelete(request: Request, env: Env): Promise<Response> {
  const body = (await request.json().catch(() => null)) as
    | { doc_id?: number; chunk_count?: number }
    | null;
  const docId = Number(body?.doc_id);
  if (!Number.isFinite(docId) || docId <= 0) {
    return json({ ok: false, error: 'doc_id required' }, 400);
  }
  // Ids follow the "{doc_id}:{chunk_index}" prefix scheme, so a document's
  // vectors are the contiguous id range 0..chunk_count-1 (sweep a bounded
  // range when the caller doesn't know the count).
  const count = Math.max(1, Math.min(DELETE_SWEEP, Number(body?.chunk_count) || DELETE_SWEEP));
  const ids: string[] = [];
  for (let i = 0; i < count; i++) ids.push(`${docId}:${i}`);
  const mutation = (await env.VECTOR_INDEX.deleteByIds(ids)) as { mutationId?: string };
  return json({ ok: true, deleted: ids.length, mutation_id: mutation.mutationId ?? null });
}

async function handleStats(env: Env): Promise<Response> {
  // describe() differs between the V2 index ({ vectorCount, dimensions }) and
  // the legacy binding types ({ vectorsCount, config.dimensions }).
  const info = (await env.VECTOR_INDEX.describe()) as {
    vectorCount?: number;
    vectorsCount?: number;
    dimensions?: number;
    config?: { dimensions?: number };
  };
  return json({
    ok: true,
    vectors: info.vectorCount ?? info.vectorsCount ?? 0,
    dimensions: info.dimensions ?? info.config?.dimensions ?? 0,
  });
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    if (!authorized(request, env)) {
      return unauthorized();
    }
    const url = new URL(request.url);
    try {
      if (request.method === 'POST' && url.pathname === '/ingest') {
        return await handleIngest(request, env);
      }
      if (request.method === 'POST' && url.pathname === '/query') {
        return await handleQuery(request, env);
      }
      if (request.method === 'POST' && url.pathname === '/delete') {
        return await handleDelete(request, env);
      }
      if (request.method === 'GET' && url.pathname === '/stats') {
        return await handleStats(env);
      }
      return json({ ok: false, error: 'not_found' }, 404);
    } catch (err) {
      return json({ ok: false, error: err instanceof Error ? err.message : 'internal_error' }, 500);
    }
  },
};
