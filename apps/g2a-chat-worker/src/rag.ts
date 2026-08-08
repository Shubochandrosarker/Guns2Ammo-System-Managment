/**
 * Retrieval against the existing g2a-rag-worker.
 *
 * That Worker is already deployed and already owns the embedding model
 * (@cf/baai/bge-m3, 1024 dims) and the g2a-brain Vectorize index. Embedding
 * here as well would be a second implementation of the same thing that could
 * silently drift to a different model — and a query embedded with a different
 * model than the corpus returns confident nonsense, because the vectors still
 * have the right shape. So this calls it rather than reimplementing it.
 */

export interface RagResult {
	id: string;
	score: number;
	doc_id: number;
	chunk_index: number;
	text: string;
	label: string;
	source_type: string;
	source_uri: string;
}

interface RagQueryResponse {
	ok: boolean;
	count?: number;
	results?: RagResult[];
	error?: string;
}

/**
 * Retrieval must never take the whole answer down with it. If the brain is
 * unreachable, we return nothing and let the prompt say "I don't have that",
 * which is the honest outcome — rather than 500ing at someone mid-question.
 */
export async function retrieve(
	env: { RAG_URL?: string; RAG_TOKEN?: string },
	query: string,
	k = 6,
	timeoutMs = 8000,
): Promise<{ results: RagResult[]; degraded: boolean; reason?: string }> {
	if (!env.RAG_URL || !env.RAG_TOKEN) {
		return { results: [], degraded: true, reason: 'rag_not_configured' };
	}

	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);

	try {
		const res = await fetch(`${env.RAG_URL.replace(/\/$/, '')}/query`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${env.RAG_TOKEN}`,
			},
			body: JSON.stringify({ query, k }),
			signal: controller.signal,
		});

		if (!res.ok) {
			return { results: [], degraded: true, reason: `rag_http_${res.status}` };
		}

		const body = (await res.json()) as RagQueryResponse;
		if (!body.ok || !Array.isArray(body.results)) {
			return { results: [], degraded: true, reason: body.error || 'rag_bad_payload' };
		}

		return { results: body.results, degraded: false };
	} catch (err) {
		const reason = err instanceof Error && err.name === 'AbortError' ? 'rag_timeout' : 'rag_unreachable';
		return { results: [], degraded: true, reason };
	} finally {
		clearTimeout(timer);
	}
}
