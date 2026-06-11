// USB HID scanners send keystrokes as a burst followed by Enter.
// We watch for rapid keypresses (< 30 ms inter-key) terminating in Enter,
// and route the payload to a handler. Doesn't capture if user is typing in an input.
export class Scanner {
  constructor(handler, opts = {}) {
    this.handler = handler;
    this.maxGap = opts.maxGap || 35;
    this.buffer = '';
    this.lastKey = 0;
    document.addEventListener('keydown', e => this.onKey(e));
  }

  onKey(e) {
    const t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) {
      if (e.key !== 'Enter') return;
    }
    const now = performance.now();
    const gap = now - this.lastKey;
    this.lastKey = now;
    if (e.key === 'Enter') {
      if (this.buffer.length >= 4 && gap < 500) {
        const payload = this.buffer;
        this.buffer = '';
        this.handler(payload);
      } else {
        this.buffer = '';
      }
      return;
    }
    if (gap > this.maxGap && this.buffer.length > 0) this.buffer = '';
    if (e.key.length === 1) this.buffer += e.key;
  }
}
