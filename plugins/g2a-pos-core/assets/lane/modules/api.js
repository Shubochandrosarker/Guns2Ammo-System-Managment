export class Api {
  constructor(config) {
    this.base = config.base || '/wp-json/g2a-pos/v1';
    this.token = sessionStorage.getItem('g2a_pos_token') || '';
    localStorage.removeItem('g2a_pos_token');
  }
  setToken(t) {
    this.token = t;
    if (t) sessionStorage.setItem('g2a_pos_token', t);
    else sessionStorage.removeItem('g2a_pos_token');
  }

  async request(method, path, body, qs) {
    let url = this.base + path;
    if (qs) {
      const p = new URLSearchParams(qs);
      url += (url.includes('?') ? '&' : '?') + p.toString();
    }
    const headers = { 'Content-Type': 'application/json' };
    if (this.token) headers['Authorization'] = 'Bearer ' + this.token;
    const res = await fetch(url, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
    });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (_) { data = { error: text }; }
    if (!res.ok) {
      const msg = (data && (data.message || data.error)) || ('HTTP ' + res.status);
      const err = new Error(msg);
      err.status = res.status;
      err.response = data;
      throw err;
    }
    return data;
  }
  get(path, qs) { return this.request('GET', path, null, qs); }
  post(path, body) { return this.request('POST', path, body); }
  del(path) { return this.request('DELETE', path); }
}
