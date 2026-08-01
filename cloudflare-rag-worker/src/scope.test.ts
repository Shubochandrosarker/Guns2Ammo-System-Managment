/**
 * Scope isolation tests.
 *
 * The failure these guard against is quiet: a public chatbot retrieving an
 * internal document and answering a customer with the shop's gross margin or
 * its open NICS count. Nothing errors, nothing logs, and the answer is
 * accurate — which is what makes it bad.
 */

import worker, { type Env } from './index';

let pass = 0;
let fail = 0;
function check(name: string, cond: boolean, detail = '') {
	if (cond) { pass++; console.log(`  ok   ${name}`); }
	else { fail++; console.log(`  FAIL ${name} ${detail}`); }
}

const FULL = 'full-token-aaaaaaaaaaaaaaaaaaaa';
const PUBLIC = 'public-token-bbbbbbbbbbbbbbbb';

/** Every call records the options Vectorize was handed, so the filter is observable. */
let lastQueryOptions: Record<string, unknown> | null = null;

const CORPUS = [
	{ id: '1:0', score: 0.9, metadata: { doc_id: 1, text: 'Range hours are 9am to 8pm.', label: 'Hours', scope: 'public' } },
	{ id: '2:0', score: 0.8, metadata: { doc_id: 2, text: 'Gross margin last 30 days: 41.2%.', label: 'Business state — trading', scope: 'internal' } },
];

function makeEnv(over: Partial<Env> = {}, indexHonoursFilter = true): Env {
	return {
		AI: {
			run: async () => ({ data: [[0.1, 0.2, 0.3]] }),
		},
		VECTOR_INDEX: {
			query: async (_v: number[], opts: Record<string, unknown>) => {
				lastQueryOptions = opts;
				const f = opts.filter as { scope?: string } | undefined;
				// indexHonoursFilter=false models the real hazard: no metadata
				// index on `scope`, so Vectorize ignores the filter entirely.
				const matches = indexHonoursFilter && f?.scope
					? CORPUS.filter((c) => c.metadata.scope === f.scope)
					: CORPUS;
				return { matches };
			},
			describe: async () => ({ vectorCount: 2, dimensions: 1024 }),
			upsert: async () => ({ mutationId: 'm' }),
			deleteByIds: async () => ({ mutationId: 'm' }),
		},
		AUTH_TOKEN: FULL,
		PUBLIC_AUTH_TOKEN: PUBLIC,
		...over,
	} as unknown as Env;
}

const post = (path: string, token: string, body: unknown) =>
	new Request(`https://rag.test${path}`, {
		method: 'POST',
		headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	});

interface QueryBody { ok: boolean; count?: number; scope?: string; error?: string; results?: Array<{ scope: string; text: string }> }

async function main() {
	console.log('\nrestricted token is pinned to public scope');
	{
		lastQueryOptions = null;
		const r = await worker.fetch(post('/query', PUBLIC, { query: 'hours' }), makeEnv());
		const b = (await r.json()) as QueryBody;
		check('query succeeds', r.status === 200 && b.ok === true, String(r.status));
		check('filter was sent to Vectorize', JSON.stringify(lastQueryOptions?.filter) === '{"scope":"public"}', JSON.stringify(lastQueryOptions?.filter));
		check('only public passages returned', b.results?.every((x) => x.scope === 'public') === true);
		check('the internal margin figure is absent', !JSON.stringify(b).includes('41.2%'));
	}
	{
		// The attack: a restricted caller asking for internal content outright.
		lastQueryOptions = null;
		const r = await worker.fetch(post('/query', PUBLIC, { query: 'margin', scope: 'internal' }), makeEnv());
		const b = (await r.json()) as QueryBody;
		check('a restricted caller cannot request internal scope', JSON.stringify(lastQueryOptions?.filter) === '{"scope":"public"}', JSON.stringify(lastQueryOptions?.filter));
		check('still no internal content', !JSON.stringify(b).includes('41.2%'));
	}
	{
		// The realistic misconfiguration: metadata index missing, so Vectorize
		// silently returns everything. The post-filter has to catch it.
		const r = await worker.fetch(post('/query', PUBLIC, { query: 'margin' }), makeEnv({}, false));
		const b = (await r.json()) as QueryBody;
		check('no metadata index => post-filter still blocks the leak', !JSON.stringify(b).includes('41.2%'));
		check('and only public survives', b.results?.length === 1 && b.results[0].scope === 'public');
	}

	console.log('\nrestricted token cannot write, delete or introspect');
	for (const [name, req] of [
		['ingest', post('/ingest', PUBLIC, { chunks: [{ doc_id: 1, chunk_index: 0, text: 'x' }] })],
		['delete', post('/delete', PUBLIC, { doc_id: 1 })],
	] as Array<[string, Request]>) {
		const r = await worker.fetch(req, makeEnv());
		const b = (await r.json()) as QueryBody;
		check(`${name} is forbidden`, r.status === 403 && b.error === 'forbidden_for_restricted_token', String(r.status));
	}
	{
		const r = await worker.fetch(
			new Request('https://rag.test/stats', { headers: { Authorization: `Bearer ${PUBLIC}` } }),
			makeEnv(),
		);
		check('stats is forbidden', r.status === 403, String(r.status));
	}

	console.log('\nfull token is unchanged — the POS plugin must keep working');
	{
		lastQueryOptions = null;
		const r = await worker.fetch(post('/query', FULL, { query: 'anything' }), makeEnv());
		const b = (await r.json()) as QueryBody;
		check('no filter when scope is omitted', lastQueryOptions?.filter === undefined, JSON.stringify(lastQueryOptions?.filter));
		check('searches everything', b.count === 2, String(b.count));
		check('reports scope as all', b.scope === 'all', String(b.scope));
	}
	{
		lastQueryOptions = null;
		const r = await worker.fetch(post('/query', FULL, { query: 'margin', scope: 'internal' }), makeEnv());
		const b = (await r.json()) as QueryBody;
		check('a full caller may narrow to internal', JSON.stringify(lastQueryOptions?.filter) === '{"scope":"internal"}');
		check('and gets the internal passage', JSON.stringify(b).includes('41.2%'));
	}
	{
		const r = await worker.fetch(post('/ingest', FULL, { chunks: [{ doc_id: 1, chunk_index: 0, text: 'x' }] }), makeEnv());
		check('full token can still ingest', r.status === 200, String(r.status));
	}

	console.log('\nauth');
	{
		const r = await worker.fetch(post('/query', 'wrong-token-cccccccccccccccc', { query: 'x' }), makeEnv());
		check('unknown token rejected', r.status === 401, String(r.status));
	}
	{
		// Backwards compatibility: an install that never sets the public secret
		// must behave exactly as before.
		const r = await worker.fetch(post('/query', PUBLIC, { query: 'x' }), makeEnv({ PUBLIC_AUTH_TOKEN: undefined }));
		check('public token unset => that token is just wrong', r.status === 401, String(r.status));
	}
	{
		const r = await worker.fetch(post('/query', FULL, { query: 'x' }), makeEnv({ AUTH_TOKEN: '' }));
		check('no AUTH_TOKEN configured => refuse everything', r.status === 401, String(r.status));
	}
	{
		// If both secrets are set to the same string, full access must win —
		// otherwise the POS plugin silently loses internal retrieval.
		const same = makeEnv({ PUBLIC_AUTH_TOKEN: FULL });
		lastQueryOptions = null;
		const r = await worker.fetch(post('/query', FULL, { query: 'x' }), same);
		check('identical secrets resolve to full, not public', r.status === 200 && lastQueryOptions?.filter === undefined);
	}

	console.log(`\n${pass} passed, ${fail} failed\n`);
	if (fail) (globalThis as unknown as { process: { exit(c: number): void } }).process.exit(1);
}

void main();
