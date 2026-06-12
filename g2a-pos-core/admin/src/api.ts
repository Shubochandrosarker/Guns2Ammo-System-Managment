// Thin REST client. Runs inside WP admin so cookies + nonce auth are used.
// The nonce is injected by Pages.php into window.G2A_POS_ADMIN.

declare global {
  interface Window {
    G2A_POS_ADMIN?: {
      restBase: string;
      nonce: string;
      currentUser?: { id: number; name: string; roles: string[] };
    };
  }
}

const cfg = () =>
  window.G2A_POS_ADMIN || {
    restBase: '/wp-json/g2a-pos/v1',
    nonce: '',
  };

export class ApiError extends Error {
  status: number;
  payload: unknown;
  constructor(status: number, message: string, payload: unknown) {
    super(message);
    this.status = status;
    this.payload = payload;
  }
}

/** Best-effort, user-facing message for an unknown thrown value. */
export function errorMessage(e: unknown, fallback = 'Something went wrong'): string {
  if (e instanceof ApiError) {
    const p = e.payload as { message?: string; error?: string } | null;
    return p?.message || p?.error || e.message || fallback;
  }
  if (e instanceof Error) return e.message || fallback;
  if (typeof e === 'string' && e) return e;
  return fallback;
}

export type QueryValue = string | number | boolean | null | undefined;

export async function api<T = unknown>(
  method: 'GET' | 'POST' | 'DELETE' | 'PUT',
  path: string,
  opts: { body?: unknown; query?: Record<string, QueryValue> } = {}
): Promise<T> {
  const { restBase, nonce } = cfg();
  let url = restBase + path;
  if (opts.query) {
    const qs = new URLSearchParams();
    Object.entries(opts.query).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    });
    const q = qs.toString();
    if (q) url += (url.includes('?') ? '&' : '?') + q;
  }
  const res = await fetch(url, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    credentials: 'same-origin',
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const text = await res.text();
  let payload: unknown = null;
  try {
    payload = text ? JSON.parse(text) : null;
  } catch {
    payload = { error: text };
  }
  if (!res.ok) {
    const p = payload as { message?: string; error?: string } | null;
    throw new ApiError(res.status, p?.message || p?.error || `HTTP ${res.status}`, payload);
  }
  return payload as T;
}

export const get = <T = unknown>(path: string, query?: Record<string, QueryValue>) =>
  api<T>('GET', path, { query });
export const post = <T = unknown>(path: string, body?: unknown) => api<T>('POST', path, { body });
export const del = <T = unknown>(path: string) => api<T>('DELETE', path);

/** Fetch a file endpoint (CSV export etc.) with nonce auth and trigger a browser download. */
export async function download(path: string, filename: string, query?: Record<string, QueryValue>) {
  const { restBase, nonce } = cfg();
  let url = restBase + path;
  if (query) {
    const qs = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    });
    const q = qs.toString();
    if (q) url += (url.includes('?') ? '&' : '?') + q;
  }
  const res = await fetch(url, {
    method: 'GET',
    headers: { 'X-WP-Nonce': nonce },
    credentials: 'same-origin',
  });
  if (!res.ok) {
    const text = await res.text();
    throw new ApiError(res.status, `Download failed (HTTP ${res.status})`, { error: text });
  }
  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(a.href);
}
