// Premium cashier lane. Vanilla JS keeps the production lane dependency-free.
import { Cart } from './modules/cart.js';
import { Api } from './modules/api.js';
import { Scanner } from './modules/scanner.js';

const root = document.getElementById('root');
const api = new Api(window.G2A_POS_CONFIG || { base: '/wp-json/g2a-pos/v1' });
const cart = new Cart();
const state = { results: [], searching: false, parked: readJson('g2a_pos_parked_sales', []) };

renderShell();
bindShell();
renderResults([]);
renderCart();
updateNetwork();
if (!api.token) openLogin();

function renderShell() {
  root.innerHTML = `
    <div class="app">
      <header class="topbar">
        <div class="brand"><div class="brand-mark" aria-hidden="true">G2A</div><div class="brand-copy"><div class="eyebrow">Guns 2 Ammo</div><div class="brand-title">Cashier Lane</div></div></div>
        <div class="topbar-center"><div class="eyebrow">Active register</div><div class="register-name">${escapeText(cart.registerCode || 'Register not assigned')} · Location ${cart.locationId}</div></div>
        <div class="topbar-actions">
          <span id="network-status" class="status-pill" role="status"><span class="status-dot"></span><span class="status-label">Online</span></span>
          <button id="login-button" class="btn btn-ghost" type="button">${api.token ? 'Switch user' : 'Sign in'}</button>
        </div>
      </header>
      <main class="workspace">
        <section class="catalog" aria-labelledby="catalog-title">
          <form id="search-form" class="search-shell" role="search">
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input id="product-search" class="search-input" autofocus autocomplete="off" placeholder="Scan barcode, enter SKU, or search products…" aria-label="Search products" />
            <button id="search-button" class="btn btn-primary" type="submit">Search</button>
          </form>
          <div class="quick-row">
            <div><h1 id="catalog-title" class="section-title">Product lookup</h1><div id="result-summary" class="hint">Scan an item or search the catalog</div></div>
            <div class="quick-actions" aria-label="Quick searches">
              <button class="chip quick-search" data-query="ammo" type="button">Ammo</button>
              <button class="chip quick-search" data-query="accessory" type="button">Accessories</button>
              <button class="chip quick-search" data-query="firearm" type="button">Firearms</button>
            </div>
          </div>
          <div id="results" class="results" aria-live="polite"></div>
        </section>
        <aside class="checkout" aria-labelledby="cart-title">
          <div class="customer-bar"><button id="customer-button" class="customer-button btn btn-ghost" type="button"><span><span class="eyebrow">Customer</span><span id="customer-name" class="customer-name">Guest checkout</span><span id="customer-meta" class="customer-meta">Add customer for loyalty and history</span></span><span aria-hidden="true">›</span></button></div>
          <div class="checkout-head"><div><div class="eyebrow">Current sale</div><h2 id="cart-title" class="section-title">Order summary</h2></div><span id="cart-count" class="cart-count">0</span></div>
          <div id="cart-items" class="cart-list"></div>
          <div class="checkout-foot">
            <div class="payment-tabs" aria-label="Payment method">
              <button class="payment-tab" data-method="cash" type="button">Cash</button>
              <button class="payment-tab" data-method="card" type="button">Card</button>
              <button class="payment-tab" data-method="split" type="button">Split</button>
            </div>
            <div class="totals"><div class="total-row"><span>Subtotal</span><strong id="subtotal">$0.00</strong></div><div class="total-row"><span>Estimated tax</span><strong id="tax">$0.00</strong></div><div class="total-row grand"><span>Total</span><strong id="grand-total">$0.00</strong></div></div>
            <div class="checkout-actions"><button id="charge-button" class="btn btn-primary charge-btn" type="button">Complete sale</button><button id="more-button" class="btn" type="button" aria-label="Add order note">•••</button></div>
            <div class="secondary-actions"><button id="park-button" class="btn btn-ghost" type="button">Park sale</button><button id="recall-button" class="btn btn-ghost" type="button">Recall (${state.parked.length})</button><button id="clear-button" class="btn btn-ghost btn-danger" type="button">Clear</button></div>
            <div id="banner" class="banner" role="status" aria-live="polite"></div>
          </div>
        </aside>
      </main>
      <div id="modal-root"></div><div id="toast-region" class="toast-region" aria-live="polite"></div>
    </div>`;
}

function bindShell() {
  byId('search-form').addEventListener('submit', event => { event.preventDefault(); lookup(); });
  byId('login-button').addEventListener('click', openLogin);
  byId('customer-button').addEventListener('click', openCustomerSearch);
  byId('charge-button').addEventListener('click', charge);
  byId('clear-button').addEventListener('click', confirmClear);
  byId('park-button').addEventListener('click', parkSale);
  byId('recall-button').addEventListener('click', openRecall);
  byId('more-button').addEventListener('click', openOrderNote);
  document.querySelectorAll('.quick-search').forEach(button => button.addEventListener('click', () => {
    byId('product-search').value = button.dataset.query;
    lookup();
  }));
  document.querySelectorAll('.payment-tab').forEach(button => button.addEventListener('click', () => {
    cart.setPaymentMethod(button.dataset.method);
    renderPayment();
  }));
  window.addEventListener('online', updateNetwork);
  window.addEventListener('offline', updateNetwork);
  new Scanner(payload => { byId('product-search').value = payload; lookup(); });
}

async function lookup() {
  const query = byId('product-search').value.trim();
  if (!query || state.searching) return;
  state.searching = true;
  setBanner('');
  byId('search-button').disabled = true;
  byId('search-button').textContent = 'Searching…';
  byId('result-summary').textContent = `Searching for “${query}”…`;
  renderLoading();
  try {
    let barcode = null;
    try {
      barcode = await api.get('/barcode/find', { code: query });
    } catch (error) {
      if (error.status && error.status !== 404) throw error;
    }
    if (barcode?.product) {
      cart.add(barcode.product, 1);
      renderCart();
      renderResults([barcode.product]);
      toast(`${barcode.product.name || 'Item'} added to cart`);
      byId('product-search').select();
      return;
    }
    const search = await api.get('/products/search', { q: query });
    state.results = search?.items || [];
    renderResults(state.results, query);
  } catch (error) {
    renderResults([]);
    setBanner(error.message || 'Product lookup failed.', 'error');
  } finally {
    state.searching = false;
    byId('search-button').disabled = false;
    byId('search-button').textContent = 'Search';
  }
}

function renderResults(items, query = '') {
  const container = byId('results');
  container.replaceChildren();
  byId('result-summary').textContent = items.length
    ? `${items.length} result${items.length === 1 ? '' : 's'}${query ? ` for “${query}”` : ''}`
    : (query ? `No matches for “${query}”` : 'Scan an item or search the catalog');
  if (!items.length) {
    container.append(el('div', { className: 'empty-state' },
      el('div', { className: 'empty-icon', textContent: query ? '⌕' : '▦' }),
      el('strong', { textContent: query ? 'No products found' : 'Ready for the next item' }),
      el('div', { className: 'hint', textContent: query ? 'Try a UPC, SKU, manufacturer, or shorter product name.' : 'Use the scanner or search field to start a sale.' })
    ));
    return;
  }
  items.forEach(product => {
    const type = product.item_type || (product.is_firearm ? 'firearm' : 'accessory');
    const add = el('button', { className: 'btn btn-primary', type: 'button', textContent: 'Add to cart' });
    add.addEventListener('click', () => { cart.add(product, 1); renderCart(); toast(`${product.name || 'Item'} added`); });
    container.append(el('article', { className: 'product-card' },
      el('div', { className: 'product-top' },
        el('div', {}, el('div', { className: 'product-name', textContent: product.name || 'Unnamed item' }), el('div', { className: 'product-sku', textContent: product.sku || `Product #${product.id}` })),
        el('span', { className: `type-badge ${type === 'firearm' ? 'firearm' : ''}`, textContent: type })
      ),
      el('div', { className: 'product-price', textContent: money(product.price) }), add
    ));
  });
}

function renderLoading() {
  const container = byId('results');
  container.replaceChildren(...Array.from({ length: 4 }, () => el('div', { className: 'product-card', ariaHidden: 'true' }, el('div', { className: 'hint', textContent: 'Loading product…' }))));
}

function renderCart() {
  const list = byId('cart-items');
  list.replaceChildren();
  const itemCount = cart.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
  byId('cart-count').textContent = String(itemCount);
  if (!cart.items.length) {
    list.append(el('div', { className: 'cart-empty' }, el('div', {}, el('div', { className: 'empty-icon', textContent: '🛒' }), el('strong', { textContent: 'Cart is empty' }), el('div', { className: 'hint', textContent: 'Scanned and selected products will appear here.' }))));
  }
  cart.items.forEach(item => {
    const minus = el('button', { className: 'qty-btn', type: 'button', textContent: '−', ariaLabel: `Decrease ${item.name} quantity`, disabled: item.item_type === 'firearm' });
    const plus = el('button', { className: 'qty-btn', type: 'button', textContent: '+', ariaLabel: `Increase ${item.name} quantity`, disabled: item.item_type === 'firearm' });
    const remove = el('button', { className: 'icon-btn remove', type: 'button', textContent: '×', ariaLabel: `Remove ${item.name}` });
    minus.addEventListener('click', () => { item.quantity <= 1 ? cart.remove(item.line_id) : cart.setQuantity(item.line_id, item.quantity - 1); renderCart(); });
    plus.addEventListener('click', () => { cart.setQuantity(item.line_id, item.quantity + 1); renderCart(); });
    remove.addEventListener('click', () => { cart.remove(item.line_id); renderCart(); });
    list.append(el('div', { className: 'cart-line' },
      el('div', {}, el('div', { className: 'cart-line-name', textContent: item.name }), el('div', { className: 'cart-line-meta', textContent: [item.sku, item.serial_number ? `SN ${item.serial_number}` : '', `${money(item.unit_price)} each`].filter(Boolean).join(' · ') }), el('div', { className: 'line-actions' }, minus, el('span', { className: 'qty-value', textContent: String(item.quantity) }), plus, remove)),
      el('div', { className: 'cart-line-total', textContent: money(item.line_total) })
    ));
  });
  const totals = cart.totals();
  byId('subtotal').textContent = money(totals.subtotal);
  byId('tax').textContent = money(totals.tax);
  byId('grand-total').textContent = money(totals.grand);
  byId('charge-button').disabled = !cart.items.length;
  byId('charge-button').textContent = cart.hasFirearm() ? `Continue compliance · ${money(totals.grand)}` : `Complete sale · ${money(totals.grand)}`;
  byId('customer-name').textContent = cart.customer?.name || 'Guest checkout';
  byId('customer-meta').textContent = cart.customer ? [cart.customer.email, cart.customer.phone].filter(Boolean).join(' · ') : 'Add customer for loyalty and history';
  renderPayment();
}

function renderPayment() {
  document.querySelectorAll('.payment-tab').forEach(button => button.classList.toggle('active', button.dataset.method === cart.paymentMethod));
}

async function charge() {
  if (!cart.items.length) return setBanner('Add at least one item before checkout.', 'warning');
  if (!navigator.onLine) return setBanner(cart.hasFirearm() ? 'Firearm transactions require an online connection.' : 'Offline checkout is not enabled for this register.', 'error');
  const button = byId('charge-button');
  button.disabled = true;
  button.textContent = 'Creating order…';
  setBanner('');
  try {
    const order = await api.post('/orders', cart.toOrderPayload());
    if (!order?.pos_order_id) throw new Error('The order was created without a confirmation number.');
    setBanner(`Order #${order.pos_order_id} created successfully.`, 'success');
    toast(`Sale #${order.pos_order_id} completed`);
    cart.clear();
    renderCart();
    byId('product-search').focus();
  } catch (error) {
    setBanner(error.message || 'Order creation failed.', 'error');
  } finally {
    renderCart();
  }
}

function openLogin() {
  const username = el('input', { className: 'input', autocomplete: 'username', placeholder: 'Username' });
  const password = el('input', { className: 'input', type: 'password', autocomplete: 'current-password', placeholder: 'Password' });
  const error = el('div', { className: 'banner error' });
  const submit = el('button', { className: 'btn btn-primary', type: 'submit', textContent: 'Sign in to lane' });
  const form = el('form', {}, field('Username', username), field('Password', password), error, submit);
  form.addEventListener('submit', async event => {
    event.preventDefault(); submit.disabled = true; submit.textContent = 'Signing in…'; error.textContent = '';
    try {
      const response = await api.post('/auth/token', { username: username.value, password: password.value });
      api.setToken(response.token); closeModal(); byId('login-button').textContent = 'Switch user'; toast('Lane signed in'); byId('product-search').focus();
    } catch (e) { error.textContent = e.message || 'Sign in failed.'; }
    finally { submit.disabled = false; submit.textContent = 'Sign in to lane'; }
  });
  openModal('Secure lane sign in', form, { dismissible: Boolean(api.token) });
  username.focus();
}

function openCustomerSearch() {
  const search = el('input', { className: 'input', placeholder: 'Name, email, or phone', ariaLabel: 'Search customers' });
  const results = el('div', { className: 'customer-results' });
  const form = el('form', {}, field('Find customer', search), results);
  let timer;
  search.addEventListener('input', () => {
    clearTimeout(timer); timer = setTimeout(async () => {
      const query = search.value.trim(); results.replaceChildren(); if (query.length < 2) return;
      try {
        const response = await api.get('/customers/search', { q: query, limit: 10 });
        (response.items || []).forEach(customer => {
          const button = el('button', { className: 'customer-result', type: 'button' }, el('strong', { textContent: customer.name }), el('div', { className: 'hint', textContent: [customer.email, customer.phone].filter(Boolean).join(' · ') }));
          button.addEventListener('click', () => { cart.setCustomer(customer); closeModal(); renderCart(); toast(`${customer.name} attached to sale`); }); results.append(button);
        });
        if (!(response.items || []).length) results.append(el('div', { className: 'hint', textContent: 'No customers found.' }));
      } catch (e) { results.append(el('div', { className: 'banner error', textContent: e.message || 'Customer search failed.' })); }
    }, 280);
  });
  const guest = el('button', { className: 'btn btn-ghost', type: 'button', textContent: 'Continue as guest' });
  guest.addEventListener('click', () => { cart.setCustomer(null); closeModal(); renderCart(); });
  form.append(guest); openModal('Select customer', form); search.focus();
}

function openOrderNote() {
  const note = el('textarea', { className: 'input', rows: 4, placeholder: 'Internal note for this transaction…' }); note.value = cart.note;
  const save = el('button', { className: 'btn btn-primary', type: 'button', textContent: 'Save note' });
  save.addEventListener('click', () => { cart.setNote(note.value); closeModal(); toast('Order note saved'); });
  openModal('Order note', el('div', {}, field('Cashier note', note), save)); note.focus();
}

function parkSale() {
  if (!cart.items.length) return setBanner('There is no sale to park.', 'warning');
  state.parked.unshift({ id: crypto.randomUUID(), ...cart.snapshot() });
  state.parked = state.parked.slice(0, 20); localStorage.setItem('g2a_pos_parked_sales', JSON.stringify(state.parked));
  cart.clear(); renderCart(); byId('recall-button').textContent = `Recall (${state.parked.length})`; toast('Sale parked');
}

function openRecall() {
  const list = el('div', { className: 'customer-results' });
  if (!state.parked.length) list.append(el('div', { className: 'empty-state' }, el('strong', { textContent: 'No parked sales' })));
  state.parked.forEach(sale => {
    const total = sale.items.reduce((sum, item) => sum + Number(item.line_total || 0), 0);
    const button = el('button', { className: 'customer-result', type: 'button' }, el('strong', { textContent: sale.customer?.name || 'Guest sale' }), el('div', { className: 'hint', textContent: `${sale.items.length} lines · ${money(total)} · ${new Date(sale.savedAt).toLocaleString()}` }));
    button.addEventListener('click', () => { if (cart.items.length && !confirm('Replace the current cart with this parked sale?')) return; cart.restore(sale); state.parked = state.parked.filter(item => item.id !== sale.id); localStorage.setItem('g2a_pos_parked_sales', JSON.stringify(state.parked)); closeModal(); renderCart(); byId('recall-button').textContent = `Recall (${state.parked.length})`; }); list.append(button);
  });
  openModal('Recall parked sale', list);
}

function confirmClear() {
  if (!cart.items.length || confirm('Clear every item from this sale?')) { cart.clear(); renderCart(); setBanner(''); }
}

function openModal(title, content, options = {}) {
  const close = el('button', { className: 'icon-btn', type: 'button', textContent: '×', ariaLabel: 'Close dialog' }); close.addEventListener('click', closeModal);
  const dialog = el('div', { className: 'modal', role: 'dialog', ariaModal: 'true', ariaLabelledby: 'modal-title' }, el('div', { className: 'modal-head' }, el('h2', { id: 'modal-title', className: 'modal-title', textContent: title }), close), content);
  const backdrop = el('div', { className: 'modal-backdrop' }, dialog);
  if (options.dismissible !== false) backdrop.addEventListener('click', event => { if (event.target === backdrop) closeModal(); }); else close.remove();
  byId('modal-root').replaceChildren(backdrop);
  document.addEventListener('keydown', escapeModal);
}
function closeModal() { byId('modal-root').replaceChildren(); document.removeEventListener('keydown', escapeModal); }
function escapeModal(event) { if (event.key === 'Escape') closeModal(); }
function updateNetwork() { const node = byId('network-status'); node.classList.toggle('offline', !navigator.onLine); node.querySelector('.status-label').textContent = navigator.onLine ? 'Online' : 'Offline'; }
function setBanner(message, kind = '') { const node = byId('banner'); node.textContent = message; node.className = `banner ${kind}`.trim(); }
function toast(message) { const node = el('div', { className: 'toast', textContent: message }); byId('toast-region').append(node); setTimeout(() => node.remove(), 2800); }
function field(label, control) { const id = `field-${crypto.randomUUID()}`; control.id = id; return el('div', { className: 'field' }, el('label', { htmlFor: id, textContent: label }), control); }
function money(value) { return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(value || 0)); }
function byId(id) { return document.getElementById(id); }
function escapeText(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' })[char]); }
function readJson(key, fallback) { try { return JSON.parse(localStorage.getItem(key) || JSON.stringify(fallback)); } catch (_) { return fallback; } }
function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  Object.entries(props).forEach(([key, value]) => {
    if (value === undefined || value === null || value === false) return;
    if (key === 'className') node.className = value;
    else if (key === 'textContent') node.textContent = value;
    else if (key === 'htmlFor') node.htmlFor = value;
    else if (key === 'ariaLabel') node.setAttribute('aria-label', value);
    else if (key === 'ariaLabelledby') node.setAttribute('aria-labelledby', value);
    else if (key === 'ariaModal') node.setAttribute('aria-modal', String(value));
    else if (key === 'ariaHidden') node.setAttribute('aria-hidden', String(value));
    else if (key in node) node[key] = value;
    else node.setAttribute(key, value);
  });
  children.flat().filter(Boolean).forEach(child => node.append(child));
  return node;
}
