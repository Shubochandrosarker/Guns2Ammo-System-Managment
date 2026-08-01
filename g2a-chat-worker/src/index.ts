/**
 * g2a-chat-worker — the WPISTIC AI Helper for the Guns2Ammo dashboard.
 *
 * WHY THIS IS A SEPARATE WORKER
 * -----------------------------
 * The existing llm-chat-app Worker (WPISTIC AI) is a commercial product: a
 * paid agent marketplace with Stripe checkout, lead capture and an offer
 * selector that pushes a purchase into the conversation. Dropping that into a
 * client's internal operations dashboard would try to sell services to the
 * staff using it, and would tie the client's tooling to WPistic's billing
 * surface. It also filters out caller-supplied system messages, so it cannot
 * be grounded in Guns2Ammo's data from outside.
 *
 * This Worker does one job: answer questions about Guns2Ammo from Guns2Ammo's
 * own records, and run named operational agents.
 *
 * ARCHITECTURE
 *   dashboard (browser)
 *     -> g2a-business-api (WordPress, holds the API key)
 *       -> this Worker            [Bearer API_KEY]
 *         -> g2a-rag-worker       [Bearer RAG_TOKEN]  retrieval over g2a-brain
 *         -> Workers AI           [via AI Gateway]    generation
 *
 * The key never reaches the browser. See README for why that matters here.
 */

import { AGENTS, findAgent, type Agent } from './agents';
import { buildSystemPrompt } from './prompt';
import { retrieve } from './rag';

export interface Env {
	AI: {
		run(model: string, input: unknown, options?: unknown): Promise<unknown>;
	};
	/** Shared secret callers must present. `wrangler secret put API_KEY` */
	API_KEY?: string;
	/** https://g2a-rag-worker.<subdomain>.workers.dev */
	RAG_URL?: string;
	/** The g2a-rag-worker AUTH_TOKEN. `wrangler secret put RAG_TOKEN` */
	RAG_TOKEN?: string;
	/** AI Gateway id, e.g. "42069". Optional but recommended. */
	AI_GATEWAY_ID?: string;
	/** Comma-separated origins allowed to call this directly. Usually empty. */
	ALLOWED_ORIGINS?: string;
}

const MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
const MAX_TURNS = 20;
const MAX_CHARS_PER_TURN = 6000;

interface ChatMessage {
	role: 'user' | 'assistant';
	content: string;
}

// ── plumbing ────────────────────────────────────────────────────────────────

function json(body: unknown, status = 200, extra: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'Content-Type': 'application/json', ...extra },
	});
}

/**
 * Constant-time compare. A plain === leaks the shared secret one byte at a
 * time to anyone who can measure response timing — the same reason
 * g2a-rag-worker does this.
 */
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
	// No key configured means refuse everything. An unauthenticated assistant
	// with read access to a firearms business's records is not a soft failure.
	if (!env.API_KEY) return false;
	const header = request.headers.get('Authorization') ?? '';
	if (!header.startsWith('Bearer ')) return false;
	return timingSafeEqual(header.slice('Bearer '.length).trim(), env.API_KEY);
}

function corsHeaders(request: Request, env: Env): Record<string, string> {
	const origin = request.headers.get('Origin');
	if (!origin || !env.ALLOWED_ORIGINS) return {};
	const allowed = env.ALLOWED_ORIGINS.split(',').map((o) => o.trim()).filter(Boolean);
	if (!allowed.includes(origin)) return {};
	return {
		'Access-Control-Allow-Origin': origin,
		'Access-Control-Allow-Headers': 'Authorization, Content-Type',
		'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
		'Access-Control-Expose-Headers': 'x-g2a-agent, x-g2a-passages, x-g2a-degraded',
		Vary: 'Origin',
	};
}

async function readJson(request: Request): Promise<Record<string, unknown>> {
	try {
		const body = await request.json();
		return body && typeof body === 'object' ? (body as Record<string, unknown>) : {};
	} catch {
		return {};
	}
}

function parseMessages(raw: unknown): ChatMessage[] {
	if (!Array.isArray(raw)) return [];
	return raw
		.filter(
			(m): m is ChatMessage =>
				!!m &&
				typeof m === 'object' &&
				typeof (m as ChatMessage).content === 'string' &&
				((m as ChatMessage).role === 'user' || (m as ChatMessage).role === 'assistant'),
		)
		.slice(-MAX_TURNS)
		.map((m) => ({ role: m.role, content: m.content.slice(0, MAX_CHARS_PER_TURN) }));
}

// ── handlers ────────────────────────────────────────────────────────────────

function handleAgents(cors: Record<string, string>): Response {
	return json(
		{
			ok: true,
			agents: AGENTS.map((a) => ({
				slug: a.slug,
				title: a.title,
				description: a.description,
				task_label: a.taskLabel,
				sources: a.sources,
			})),
		},
		200,
		cors,
	);
}

/**
 * Shared path for both conversational chat and a one-shot agent run — an
 * agent run is just a chat with a single user turn and a forced agent, so
 * giving them separate implementations would only let them drift apart.
 */
async function generate(
	env: Env,
	messages: ChatMessage[],
	agent: Agent | undefined,
	facts: string | undefined,
	cors: Record<string, string>,
): Promise<Response> {
	const lastUser = [...messages].reverse().find((m) => m.role === 'user')?.content ?? '';
	const { results, degraded, reason } = await retrieve(env, lastUser);

	const system = buildSystemPrompt({ agent, results, facts });

	const stream = (await env.AI.run(
		MODEL,
		{
			messages: [{ role: 'system', content: system }, ...messages],
			max_tokens: 1024,
			stream: true,
		},
		env.AI_GATEWAY_ID ? { gateway: { id: env.AI_GATEWAY_ID, skipCache: false, cacheTtl: 900 } } : {},
	)) as ReadableStream;

	return new Response(stream, {
		headers: {
			'content-type': 'text/event-stream; charset=utf-8',
			'cache-control': 'no-cache',
			connection: 'keep-alive',
			// Metadata rides on headers so the UI can show which agent answered
			// and how many passages backed it without parsing model prose. The
			// degraded flag lets the bubble tell the operator the answer was
			// produced without the knowledge base, which changes how much they
			// should trust it.
			'x-g2a-agent': agent?.slug ?? '',
			'x-g2a-passages': String(results.length),
			'x-g2a-degraded': degraded ? reason || 'retrieval_unavailable' : '',
			...cors,
		},
	});
}

async function handleChat(request: Request, env: Env, cors: Record<string, string>): Promise<Response> {
	const body = await readJson(request);
	const messages = parseMessages(body.messages);

	if (messages.length === 0) {
		return json({ ok: false, error: 'messages[] required' }, 400, cors);
	}

	const slug = typeof body.agent === 'string' ? body.agent : '';
	const agent = slug ? findAgent(slug) : undefined;
	if (slug && !agent) {
		return json({ ok: false, error: `unknown agent: ${slug}` }, 400, cors);
	}

	const facts = typeof body.facts === 'string' ? body.facts : undefined;
	return generate(env, messages, agent, facts, cors);
}

async function handleAgentRun(
	request: Request,
	env: Env,
	slug: string,
	cors: Record<string, string>,
): Promise<Response> {
	const agent = findAgent(slug);
	if (!agent) {
		return json({ ok: false, error: `unknown agent: ${slug}` }, 404, cors);
	}

	const body = await readJson(request);
	const task = typeof body.task === 'string' ? body.task.trim() : '';
	if (task === '') {
		return json({ ok: false, error: 'task required' }, 400, cors);
	}

	const facts = typeof body.facts === 'string' ? body.facts : undefined;
	return generate(
		env,
		[{ role: 'user', content: task.slice(0, MAX_CHARS_PER_TURN) }],
		agent,
		facts,
		cors,
	);
}

// ── entry point ─────────────────────────────────────────────────────────────

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		const url = new URL(request.url);
		const cors = corsHeaders(request, env);

		if (request.method === 'OPTIONS') {
			return new Response(null, { status: 204, headers: cors });
		}

		// Unauthenticated: reports only whether the Worker is configured, never
		// the values. Useful for a deploy smoke test that must not need the key.
		if (url.pathname === '/health' && request.method === 'GET') {
			return json(
				{
					ok: true,
					configured: {
						api_key: Boolean(env.API_KEY),
						rag: Boolean(env.RAG_URL && env.RAG_TOKEN),
						ai_gateway: Boolean(env.AI_GATEWAY_ID),
					},
					agents: AGENTS.length,
				},
				200,
				cors,
			);
		}

		if (!authorized(request, env)) {
			return json({ ok: false, error: 'unauthorized' }, 401, cors);
		}

		try {
			if (url.pathname === '/api/agents' && request.method === 'GET') {
				return handleAgents(cors);
			}
			if (url.pathname === '/api/chat' && request.method === 'POST') {
				return await handleChat(request, env, cors);
			}
			const run = url.pathname.match(/^\/api\/agents\/([a-z0-9-]+)\/run$/);
			if (run && request.method === 'POST') {
				return await handleAgentRun(request, env, run[1], cors);
			}
			return json({ ok: false, error: 'not_found' }, 404, cors);
		} catch (err) {
			return json(
				{ ok: false, error: err instanceof Error ? err.message : 'internal_error' },
				500,
				cors,
			);
		}
	},
};
