const CART_KEY = 'g2a_pos_cart';
const CONTEXT_KEY = 'g2a_pos_cart_context';

export class Cart {
  constructor() {
    this.items = readJson(CART_KEY, []);
    const context = readJson(CONTEXT_KEY, {});
    this.taxRate = number(context.taxRate, parseFloat(localStorage.getItem('g2a_pos_tax_rate') || '0'));
    this.registerCode = context.registerCode || localStorage.getItem('g2a_pos_register') || '';
    this.locationId = number(context.locationId, parseInt(localStorage.getItem('g2a_pos_location') || '1', 10));
    this.customer = context.customer || null;
    this.paymentMethod = context.paymentMethod || 'cash';
    this.note = context.note || '';
  }

  _persist() {
    localStorage.setItem(CART_KEY, JSON.stringify(this.items));
    localStorage.setItem(CONTEXT_KEY, JSON.stringify({
      taxRate: this.taxRate,
      registerCode: this.registerCode,
      locationId: this.locationId,
      customer: this.customer,
      paymentMethod: this.paymentMethod,
      note: this.note,
    }));
  }

  add(product, qty = 1) {
    const productId = product.id || product.product_id;
    const serial = product.serial_number || null;
    const existing = this.items.find(item =>
      item.product_id === productId && item.variation_id === (product.variation_id || null) &&
      item.serial_number === serial && item.item_type !== 'firearm'
    );
    if (existing) {
      this.setQuantity(existing.line_id, existing.quantity + qty);
      return existing;
    }
    const line = {
      line_id: crypto.randomUUID(),
      product_id: productId,
      variation_id: product.variation_id || null,
      name: product.name || 'Unnamed item',
      sku: product.sku || '',
      item_type: product.item_type || (product.is_firearm ? 'firearm' : 'accessory'),
      serial_number: serial,
      quantity: Math.max(1, number(qty, 1)),
      unit_price: number(product.price ?? product.unit_price, 0),
    };
    this._recalculate(line);
    this.items.push(line);
    this._persist();
    return line;
  }

  setQuantity(lineId, quantity) {
    const line = this.items.find(item => item.line_id === lineId);
    if (!line) return;
    line.quantity = line.item_type === 'firearm' ? 1 : Math.max(1, number(quantity, 1));
    this._recalculate(line);
    this._persist();
  }

  remove(lineId) {
    this.items = this.items.filter(item => item.line_id !== lineId);
    this._persist();
  }

  setCustomer(customer) { this.customer = customer || null; this._persist(); }
  setPaymentMethod(method) { this.paymentMethod = method || 'cash'; this._persist(); }
  setNote(note) { this.note = String(note || ''); this._persist(); }

  clear() {
    this.items = [];
    this.customer = null;
    this.note = '';
    this._persist();
  }

  snapshot() {
    return {
      items: structuredClone(this.items),
      customer: this.customer ? structuredClone(this.customer) : null,
      paymentMethod: this.paymentMethod,
      note: this.note,
      savedAt: new Date().toISOString(),
    };
  }

  restore(snapshot) {
    this.items = Array.isArray(snapshot?.items) ? snapshot.items : [];
    this.customer = snapshot?.customer || null;
    this.paymentMethod = snapshot?.paymentMethod || 'cash';
    this.note = snapshot?.note || '';
    this._persist();
  }

  hasFirearm() { return this.items.some(item => item.item_type === 'firearm'); }

  totals() {
    const subtotal = this.items.reduce((sum, item) => sum + number(item.line_total, 0), 0);
    const tax = +(subtotal * this.taxRate).toFixed(2);
    return { subtotal: +subtotal.toFixed(2), tax, grand: +(subtotal + tax).toFixed(2) };
  }

  toOrderPayload() {
    const totals = this.totals();
    return {
      register_code: this.registerCode,
      location_id: this.locationId,
      customer_id: this.customer?.id || null,
      subtotal: totals.subtotal,
      tax_total: totals.tax,
      grand_total: totals.grand,
      payment_method: this.paymentMethod,
      metadata: this.note ? { cashier_note: this.note } : undefined,
      items: this.items.map(item => ({
        product_id: item.product_id,
        variation_id: item.variation_id,
        sku: item.sku,
        item_type: item.item_type,
        serial_number: item.serial_number,
        quantity: item.quantity,
        unit_price: item.unit_price,
        line_total: item.line_total,
      })),
    };
  }

  _recalculate(line) {
    line.line_total = +(number(line.quantity, 0) * number(line.unit_price, 0)).toFixed(2);
  }
}

function readJson(key, fallback) {
  try { return JSON.parse(localStorage.getItem(key) || JSON.stringify(fallback)); }
  catch (_) { return fallback; }
}

function number(value, fallback = 0) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}
