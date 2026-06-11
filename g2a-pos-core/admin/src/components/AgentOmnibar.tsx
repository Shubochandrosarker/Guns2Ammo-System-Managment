import { useEffect, useRef, useState } from 'react';
import { errorMessage, post } from '../api';

interface ToolResult { ok?: boolean; error?: string; count?: number }
interface Citation { source_label: string; source_uri?: string; score?: number; text_content: string }
interface Pending { id: number; tool_name: string; preview: string; arguments: Record<string, unknown> }
interface Outcome { name: string; ok: boolean; result?: ToolResult }
interface Response {
  message_id: number;
  content: string;
  tool_outcomes: Outcome[];
  pending_actions: Pending[];
  citations: Citation[];
  latency_ms?: number;
  model?: string;
}

interface Msg { role: string; content: string; pending?: Pending[]; outcomes?: Outcome[]; citations?: Citation[]; id?: number }

/**
 * Press ⌘K / Ctrl-K to open. Streams natural-language requests through
 * /ai/conversations/{id}/messages. Renders confirmation popups for any
 * tool the action policy flagged `confirm`.
 */
export default function AgentOmnibar() {
  const [open, setOpen] = useState(false);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [messages, setMessages] = useState<Msg[]>([]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const scrollerRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen((o) => !o);
      }
      if (e.key === 'Escape') setOpen(false);
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  useEffect(() => {
    if (open) {
      setTimeout(() => inputRef.current?.focus(), 50);
      if (!conversationId) startConversation();
    }
    // Runs on open/close; startConversation only fires once per fresh chat.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => { scrollerRef.current?.scrollTo({ top: 99999 }); }, [messages]);

  const startConversation = async () => {
    try {
      const r = await post<{ conversation_id: number }>('/ai/conversations', { channel: 'pos' });
      setConversationId(r.conversation_id);
    } catch { /* keep silent — surface on first send */ }
  };

  const send = async () => {
    if (!input.trim() || busy) return;
    const text = input.trim();
    setInput('');
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setBusy(true);
    try {
      let cid = conversationId;
      if (!cid) {
        const r = await post<{ conversation_id: number }>('/ai/conversations', {});
        cid = r.conversation_id;
        setConversationId(cid);
      }
      const res = await post<Response>(`/ai/conversations/${cid}/messages`, { message: text });
      setMessages((m) => [...m, {
        role: 'assistant',
        content: res.content,
        pending: res.pending_actions,
        outcomes: res.tool_outcomes,
        citations: res.citations,
        id: res.message_id,
      }]);
    } catch (e) {
      setMessages((m) => [...m, { role: 'assistant', content: 'Error: ' + errorMessage(e, 'failed') }]);
    } finally {
      setBusy(false);
    }
  };

  const decide = async (pendingId: number, decision: 'approve' | 'deny') => {
    try {
      const res = await post<{ result?: ToolResult }>(`/ai/pending-actions/${pendingId}/decide`, { decision });
      setMessages((m) => [...m, { role: 'system', content: decision === 'approve'
        ? `✓ Approved — ${res?.result?.ok ? 'executed' : 'failed: ' + (res?.result?.error ?? '?')}`
        : '✗ Denied' }]);
      setMessages((m) => m.map((msg) => msg.pending ? { ...msg, pending: msg.pending.filter((p) => p.id !== pendingId) } : msg));
    } catch (e) {
      alert('Decision failed: ' + errorMessage(e, '?'));
    }
  };

  const rate = async (messageId: number, rating: 1 | -1) => {
    await post('/ai/feedback', { message_id: messageId, rating });
  };

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        title="Ask the agent (⌘K)"
        className="fixed bottom-6 right-6 z-40 grid h-14 w-14 place-items-center rounded-full bg-brand text-white shadow-lg hover:bg-brand-dark"
      >
        🤖
      </button>
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-zinc-900/40 p-4 pt-20" role="presentation" onClick={() => setOpen(false)}>
      <div onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-labelledby="g2a-agent-title"
        className="w-full max-w-2xl rounded-xl bg-white shadow-2xl dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 flex flex-col"
        style={{ maxHeight: '80vh' }}>
        <div className="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 px-4 py-3">
          <div className="flex items-center gap-2"><span className="text-lg">🤖</span><strong id="g2a-agent-title">G2A Agent</strong>
            <span className="text-xs text-zinc-500">⌘K to toggle · Esc to close</span></div>
          <button className="btn-sm" onClick={() => { setMessages([]); setConversationId(null); startConversation(); }}>New chat</button>
        </div>
        <div ref={scrollerRef} className="flex-1 overflow-auto px-4 py-3 space-y-3" style={{ minHeight: 200 }}>
          {messages.length === 0 && (
            <div className="text-sm text-zinc-500">
              Try: <em>&ldquo;Find 9mm Federal HST in stock&rdquo;</em> · <em>&ldquo;Look up customer John&rdquo;</em> ·
              <em>&ldquo;Email the invoice for order 42 to john@example.com&rdquo;</em> ·
              <em>&ldquo;How are sales today?&rdquo;</em>
            </div>
          )}
          {messages.map((m, i) => (
            <div key={i} className={m.role === 'user' ? 'text-right' : ''}>
              <div className={`inline-block max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap ${
                m.role === 'user' ? 'bg-brand/10 text-brand'
                : m.role === 'system' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
                : 'bg-zinc-100 dark:bg-zinc-800'
              }`}>
                {m.content}
                {m.outcomes && m.outcomes.length > 0 && (
                  <div className="mt-2 space-y-1 text-xs">
                    {m.outcomes.map((o, j) => (
                      <div key={j} className={o.ok ? 'text-emerald-600' : 'text-rose-600'}>
                        {o.ok ? '✓' : '✗'} {o.name}{o.result?.count !== undefined && ` (${o.result.count} result${o.result.count === 1 ? '' : 's'})`}
                      </div>
                    ))}
                  </div>
                )}
                {m.citations && m.citations.length > 0 && (
                  <div className="mt-2 text-xs text-zinc-500">
                    Sources: {m.citations.map((c) => c.source_label).join(' · ')}
                  </div>
                )}
                {m.pending && m.pending.length > 0 && (
                  <div className="mt-3 space-y-2">
                    {m.pending.map((p) => (
                      <div key={p.id} className="rounded-md border border-amber-300 bg-amber-50 p-3 dark:bg-amber-900/20">
                        <div className="text-xs font-semibold text-amber-700 mb-1">Confirmation required</div>
                        <div className="text-sm font-medium">{p.preview}</div>
                        <pre className="text-xs mt-1 text-zinc-600 overflow-auto">{JSON.stringify(p.arguments, null, 2)}</pre>
                        <div className="mt-2 flex gap-2">
                          <button className="btn-primary" onClick={() => decide(p.id, 'approve')}>Approve</button>
                          <button className="btn-sm" onClick={() => decide(p.id, 'deny')}>Deny</button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              {m.role === 'assistant' && m.id && (
                <div className="mt-1 text-xs space-x-2">
                  <button className="text-zinc-400 hover:text-emerald-600" aria-label="Rate response helpful" onClick={() => rate(m.id!, 1)}>👍</button>
                  <button className="text-zinc-400 hover:text-rose-600" aria-label="Rate response unhelpful" onClick={() => rate(m.id!, -1)}>👎</button>
                </div>
              )}
            </div>
          ))}
          {busy && <div className="text-xs text-zinc-500">Agent thinking…</div>}
        </div>
        <form className="flex gap-2 border-t border-zinc-200 dark:border-zinc-800 px-4 py-3"
          onSubmit={(e) => { e.preventDefault(); send(); }}>
          <input ref={inputRef} className="input flex-1" placeholder="Ask anything…"
            value={input} onChange={(e) => setInput(e.target.value)} disabled={busy} />
          <button className="btn-primary" type="submit" disabled={busy || !input.trim()}>Send</button>
        </form>
      </div>
    </div>
  );
}
