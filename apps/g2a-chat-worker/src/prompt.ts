/**
 * System prompt construction.
 *
 * The single most important property of this file is that the model is told,
 * in unambiguous terms, to answer from the retrieved context and to say when
 * it cannot. A chatbot in a gun shop's operations dashboard that invents a
 * bound-book entry, a stock level or a compliance state is worse than no
 * chatbot: the answer is delivered in the same confident voice as a true one,
 * and it concerns money, federal records and firearms.
 */

import type { Agent } from './agents';
import type { RagResult } from './rag';

const BASE = `You are the WPISTIC AI Helper working inside the Guns2Ammo business operations dashboard.
Guns2Ammo is a firearms retailer with an indoor shooting range, a training school, memberships and an online store.
You are speaking to the owner or a member of staff, not to a customer.

HOW TO ANSWER
- Answer from the CONTEXT below. The context is retrieved from Guns2Ammo's own records.
- If the context does not contain the answer, say so directly — "I don't have that in the knowledge base" — and say what you would need. Never fill a gap with a plausible guess.
- Never invent a number, a date, a serial number, a stock level, an order id or a compliance state. A number you did not read in the context does not go in your answer.
- When you use a figure, say where it came from and what period it covers.
- If two pieces of context disagree, say so rather than silently choosing one.
- Be brief and concrete. Staff read this between customers.

FIREARMS AND COMPLIANCE
- You do not authorise transfers, approve background checks, or decide whether a sale may legally proceed. You report what the records show and what is missing; a licensed person decides.
- Never suggest a workaround to a compliance control, a waiting period, or an identity or waiver requirement, even if asked directly.

You have no ability to change anything. You read and advise; staff act.`;

export interface PromptInput {
	agent?: Agent;
	results: RagResult[];
	/** Live business facts passed in by the caller (WordPress), if any. */
	facts?: string;
}

export function buildSystemPrompt({ agent, results, facts }: PromptInput): string {
	const parts: string[] = [BASE];

	if (agent) {
		parts.push(`\nYOUR ROLE — ${agent.title}\n${agent.brief}`);
	}

	if (facts && facts.trim() !== '') {
		// Live figures from the dashboard's own providers. Ranked above the
		// vector store deliberately: retrieval returns documents, which age;
		// this is the state of the business right now.
		parts.push(`\nLIVE FIGURES (current, from the dashboard)\n${facts.trim().slice(0, 8000)}`);
	}

	if (results.length === 0) {
		parts.push(
			'\nCONTEXT\nNothing was retrieved from the knowledge base for this question. ' +
				'Say that you do not have it rather than answering from general knowledge — unless the ' +
				'question is small talk or about how to use the dashboard itself.',
		);
	} else {
		const context = results
			.map((r, i) => {
				const label = r.label || r.source_uri || `document ${r.doc_id}`;
				return `[${i + 1}] ${label}\n${r.text}`;
			})
			.join('\n\n');
		parts.push(
			`\nCONTEXT (${results.length} passage${results.length === 1 ? '' : 's'} from the Guns2Ammo knowledge base)\n${context}\n\n` +
				'Cite the passage number in square brackets when a statement rests on one.',
		);
	}

	return parts.join('\n');
}
