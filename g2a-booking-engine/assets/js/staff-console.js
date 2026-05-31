/*
 * G2A Staff Console front-end app.
 * Pure-vanilla; one file. Talks to /wp-json/g2a-booking/v1/staff/*.
 * The server is the source of truth — never edit local DOM after a
 * server failure; show the toast/modal and reload affected widgets.
 */
(function () {
  'use strict';
  if (typeof window.g2abStaff !== 'object') return;
  var CFG = window.g2abStaff;
  var ROOT = document.getElementById('g2ab-sc');
  if (!ROOT) return;

  // ----------------------- fetch wrapper ---------------------------
  function api(path, opts) {
    opts = opts || {};
    var url = CFG.root.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    var headers = Object.assign({ 'X-WP-Nonce': CFG.nonce, 'Accept': 'application/json' }, opts.headers || {});
    var body = opts.body;
    if (body && typeof body === 'object' && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    return fetch(url, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: body || null
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) { var e = new Error((j && (j.message || j.code)) || ('HTTP ' + r.status)); e.payload = j; throw e; }
        return j;
      });
    });
  }

  // ----------------------- toasts ----------------------------------
  function toast(msg, kind) {
    var c = document.getElementById('g2ab-sc-toasts');
    if (!c) return;
    var el = document.createElement('div');
    el.className = 'g2ab-sc__toast' + (kind ? ' ' + kind : '');
    el.textContent = msg;
    c.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transform = 'translateY(20px)'; }, 3500);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 3900);
  }

  // ----------------------- modal helpers ---------------------------
  function openModal(id) {
    var m = document.getElementById('g2ab-sc-modal-' + id);
    if (!m) return;
    m.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeModal(node) {
    var m = node.closest ? node.closest('.g2ab-sc__modal') : null;
    if (m) m.hidden = true;
    document.body.style.overflow = '';
  }
  function showFinish(title, body, kind) {
    var m = document.getElementById('g2ab-sc-modal-result');
    document.getElementById('g2ab-sc-mr-title').textContent = title || CFG.i18n.doneTitle;
    document.getElementById('g2ab-sc-mr-body').textContent = body || '';
    var ic = document.getElementById('g2ab-sc-mr-ic');
    ic.className = 'g2ab-sc__finish-ic' + (kind === 'err' ? ' err' : '');
    ic.textContent = kind === 'err' ? '!' : '✓';
    m.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  // ----------------------- pane router -----------------------------
  function switchPane(name) {
    ROOT.querySelectorAll('[data-pane]').forEach(function (el) {
      if (el.classList.contains('g2ab-sc__navi')) {
        el.classList.toggle('is-cur', el.getAttribute('data-pane') === name);
      } else if (el.classList.contains('g2ab-sc__pane')) {
        var on = el.getAttribute('data-pane') === name;
        el.hidden = !on;
        el.classList.toggle('is-cur', on);
      }
    });
    var titleMap = {
      dashboard:    'Range Status',
      waivers:      'Waivers',
      scan:         'Member Card Scan',
      reservations: "Today's Reservations"
    };
    var t = document.getElementById('g2ab-sc-pane-title');
    if (t && titleMap[name]) t.textContent = titleMap[name];
    if (name === 'reservations') loadRoster();
    if (name === 'scan')         ensureScanIdle();
    if (name !== 'scan')         stopScan();
  }
  ROOT.addEventListener('click', function (e) {
    var nav = e.target.closest('[data-pane]');
    if (nav && nav.classList.contains('g2ab-sc__navi')) { e.preventDefault(); switchPane(nav.getAttribute('data-pane')); return; }
    var openBtn = e.target.closest('[data-open-modal]');
    if (openBtn) { e.preventDefault(); openModal(openBtn.getAttribute('data-open-modal')); preloadLanes(); return; }
    var closeBtn = e.target.closest('[data-close]');
    if (closeBtn) { e.preventDefault(); closeModal(closeBtn); return; }
  });

  // ----------------------- snapshot render -------------------------
  function renderSnapshot(data) {
    var kpis = document.getElementById('g2ab-sc-kpis');
    var k = data.kpi || {};
    kpis.innerHTML = ''
      + '<div class="g2ab-sc__kpi brass"><div class="g2ab-sc__kpi-l">Lanes In Use</div><div class="g2ab-sc__kpi-v">' + (k.lanes_in_use || 0) + ' / ' + (k.lanes_total || 0) + '</div></div>'
      + '<div class="g2ab-sc__kpi"><div class="g2ab-sc__kpi-l">Today\'s Revenue</div><div class="g2ab-sc__kpi-v">$' + Number(k.today_revenue || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</div></div>'
      + '<div class="g2ab-sc__kpi"><div class="g2ab-sc__kpi-l">Active Members</div><div class="g2ab-sc__kpi-v">' + (k.active_members || 0) + '</div></div>'
      + '<div class="g2ab-sc__kpi"><div class="g2ab-sc__kpi-l">Live Lanes</div><div class="g2ab-sc__kpi-v">' + (k.lanes_total || 0) + '</div></div>';

    var lanesEl = document.getElementById('g2ab-sc-lanes');
    lanesEl.innerHTML = (data.lanes || []).map(function (l) {
      return '<div class="g2ab-sc__lane ' + l.state + '"><span class="lbl">' + escapeHtml(l.label) + '</span>' + (l.state === 'in_use' ? '●' : l.state === 'reserved' ? '◆' : '○') + '</div>';
    }).join('') || '<div class="g2ab-sc__empty">No lanes configured.</div>';

    var feedEl = document.getElementById('g2ab-sc-feed');
    feedEl.innerHTML = (data.feed || []).map(function (f) {
      var cls = f.severity === 'warning' ? 'alert' : (f.kind === 'checked_in' || f.kind === 'payment_succeeded' || f.kind === 'waiver_verified' ? 'ok' : '');
      var ic = f.kind === 'checked_in' ? '✓' : f.kind === 'payment_succeeded' ? '$' : f.kind === 'walk_in_created' ? '+' : '•';
      return '<div class="g2ab-sc__feed-row ' + cls + '">'
        + '<div class="t">' + escapeHtml(f.when) + '</div>'
        + '<div class="ic">' + ic + '</div>'
        + '<div><div class="nm">' + escapeHtml(f.message || '') + '</div>'
        + (f.who ? '<div class="d">' + escapeHtml(f.who) + '</div>' : '')
        + '</div></div>';
    }).join('') || '<div class="g2ab-sc__empty">No activity yet.</div>';
  }

  function refreshSnapshot() {
    api('staff/snapshot').then(renderSnapshot).catch(function (e) { toast(e.message, 'err'); });
  }

  // ----------------------- waiver search ---------------------------
  document.getElementById('g2ab-sc-waiver-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var q = document.getElementById('g2ab-sc-waiver-q').value.trim();
    var out = document.getElementById('g2ab-sc-waiver-results');
    if (q.length < 2) { out.innerHTML = '<div class="g2ab-sc__empty">Enter at least 2 characters.</div>'; return; }
    out.innerHTML = '<div class="g2ab-sc__empty">Searching…</div>';
    api('staff/waiver-lookup?q=' + encodeURIComponent(q)).then(function (r) {
      renderWaiverResults(r.results || []);
    }).catch(function (e) {
      out.innerHTML = '<div class="g2ab-sc__empty">Search failed: ' + escapeHtml(e.message) + '</div>';
    });
  });
  function renderWaiverResults(rows) {
    var out = document.getElementById('g2ab-sc-waiver-results');
    if (!rows.length) { out.innerHTML = '<div class="g2ab-sc__empty">No matches.</div>'; return; }
    out.innerHTML = rows.map(function (r) {
      return '<div class="g2ab-sc__rcard">'
        + '<div>'
        +   '<div class="who">' + escapeHtml(r.name) + (r.tier ? ' <span style="font-family:var(--sc-font-mono);font-size:10px;color:var(--sc-brass-bright);">' + escapeHtml(r.tier) + '</span>' : '') + '</div>'
        +   '<div class="meta">' + escapeHtml(r.email_mask || '') + '</div>'
        +   '<div class="dates">' + (r.signed_at ? 'Signed: ' + escapeHtml(r.signed_at) : '—') + (r.expires_at ? ' · Expires: ' + escapeHtml(r.expires_at) : '') + '</div>'
        + '</div>'
        + '<div class="status ' + escapeHtml(r.state) + '">' + escapeHtml(r.status) + '</div>'
        + '</div>';
    }).join('');
  }

  // ----------------------- walk-in modal ---------------------------
  var preloadedLanes = null;
  function preloadLanes() {
    if (preloadedLanes) return;
    api('staff/snapshot').then(function (s) {
      preloadedLanes = s.lanes || [];
      var sel = document.getElementById('g2ab-sc-walkin-lane');
      sel.innerHTML = preloadedLanes.map(function (l) {
        return '<option value="' + l.id + '"' + (l.state === 'in_use' ? ' disabled' : '') + '>' + escapeHtml(l.label) + (l.state === 'in_use' ? ' (in use)' : '') + '</option>';
      }).join('');
    }).catch(function () { /* swallow */ });
  }
  document.getElementById('g2ab-sc-walkin-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.currentTarget;
    var payload = {
      name: f.name.value,
      email: f.email.value,
      phone: f.phone.value,
      lane: parseInt(f.lane.value, 10),
      party_size: parseInt(f.party_size.value, 10) || 1,
      minutes: parseInt(f.minutes.value, 10) || 60
    };
    api('staff/walk-in', { method: 'POST', body: payload }).then(function (r) {
      document.getElementById('g2ab-sc-modal-walkin').hidden = true;
      f.reset();
      showFinish(CFG.i18n.doneTitle, r.message || 'Checked in.', 'ok');
      refreshSnapshot();
    }).catch(function (e) {
      showFinish(CFG.i18n.errorTitle, e.message, 'err');
    });
  });

  // ----------------------- roster ----------------------------------
  function loadRoster() {
    var el = document.getElementById('g2ab-sc-roster');
    el.innerHTML = '<div class="g2ab-sc__empty">Loading…</div>';
    api('frontdesk/today').then(function (r) {
      var rows = (r && r.bookings) || [];
      if (!rows.length) { el.innerHTML = '<div class="g2ab-sc__empty">Nothing on the books for today.</div>'; return; }
      el.innerHTML = rows.map(function (b) {
        var t = (b.start_at || '').split(' ')[1] || '';
        return '<div class="g2ab-sc__rrow" data-id="' + b.id + '">'
          + '<div class="time">' + escapeHtml(t.substring(0, 5)) + '</div>'
          + '<div class="name">' + escapeHtml(b.customer_name || 'Walk-in') + '</div>'
          + '<div class="resource">' + escapeHtml(b.resource_name || '—') + '</div>'
          + '<div class="resource">' + escapeHtml(String(b.party_size || 1)) + 'p</div>'
          + '<div class="pill">' + escapeHtml((b.status || '').toUpperCase()) + '</div>'
          + '<div class="actions">'
          +   '<button class="g2ab-sc__act-btn" data-act="check_in" title="Check in">✓</button>'
          +   '<button class="g2ab-sc__act-btn" data-act="no_show" title="No-show">✕</button>'
          +   '<button class="g2ab-sc__act-btn" data-act="mark_paid" title="Mark paid">$</button>'
          + '</div>'
        + '</div>';
      }).join('');
    }).catch(function (e) {
      el.innerHTML = '<div class="g2ab-sc__empty">Could not load roster: ' + escapeHtml(e.message) + '</div>';
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.g2ab-sc__act-btn');
    if (!b) return;
    var row = b.closest('.g2ab-sc__rrow');
    var id = parseInt(row.getAttribute('data-id'), 10);
    var act = b.getAttribute('data-act');
    if (!id || !act) return;
    b.disabled = true;
    api('staff/booking-action', { method: 'POST', body: { booking_id: id, action: act } }).then(function (r) {
      toast(r.message || 'Done', 'ok');
      loadRoster();
      refreshSnapshot();
    }).catch(function (e) {
      showFinish(CFG.i18n.errorTitle, e.message, 'err');
    }).finally(function () { b.disabled = false; });
  });

  // ----------------------- QR scanner ------------------------------
  // Prefers the native BarcodeDetector (Chrome/Edge/Safari 17+/iOS 17+).
  // Falls back to lazy-loading jsQR from cdn.jsdelivr.net only when the
  // browser lacks native support — no bytes shipped to modern users.
  var stream = null, scanRaf = null, lastDecodedAt = 0, detector = null, jsqrPromise = null;
  function ensureScanIdle() {
    document.getElementById('g2ab-sc-scan-stop').hidden = true;
    document.getElementById('g2ab-sc-scan-start').hidden = false;
  }
  function loadJsQR() {
    if (typeof window.jsQR === 'function') return Promise.resolve();
    if (jsqrPromise) return jsqrPromise;
    jsqrPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
      s.async = true;
      s.crossOrigin = 'anonymous';
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Could not load QR library.')); };
      document.head.appendChild(s);
    });
    return jsqrPromise;
  }
  function startScan() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      document.getElementById('g2ab-sc-scan-status').textContent = CFG.i18n.noCamera;
      return;
    }
    var prep = ('BarcodeDetector' in window)
      ? Promise.resolve(detector = detector || new window.BarcodeDetector({ formats: ['qr_code'] }))
      : loadJsQR();
    prep.then(function () {
      return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    }).then(function (s) {
      stream = s;
      var v = document.getElementById('g2ab-sc-video');
      v.srcObject = s;
      v.play();
      document.getElementById('g2ab-sc-scan-start').hidden = true;
      document.getElementById('g2ab-sc-scan-stop').hidden = false;
      document.getElementById('g2ab-sc-scan-status').textContent = CFG.i18n.scanHint;
      tickScan();
    }).catch(function (e) {
      document.getElementById('g2ab-sc-scan-status').textContent = (e && e.message) || CFG.i18n.noCamera;
    });
  }
  function stopScan() {
    if (scanRaf) cancelAnimationFrame(scanRaf);
    scanRaf = null;
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    var v = document.getElementById('g2ab-sc-video'); if (v) v.srcObject = null;
    ensureScanIdle();
  }
  function tickScan() {
    scanRaf = requestAnimationFrame(tickScan);
    var v = document.getElementById('g2ab-sc-video');
    if (!v || v.readyState !== v.HAVE_ENOUGH_DATA) return;
    if (detector) {
      detector.detect(v).then(function (codes) {
        if (codes && codes.length && codes[0].rawValue && Date.now() - lastDecodedAt > 1500) {
          lastDecodedAt = Date.now();
          resolveScannedPayload(codes[0].rawValue);
        }
      }).catch(function () { /* swallow per-frame errors */ });
      return;
    }
    if (typeof window.jsQR !== 'function') return;
    var c = document.getElementById('g2ab-sc-canvas');
    c.width = v.videoWidth; c.height = v.videoHeight;
    var ctx = c.getContext('2d');
    ctx.drawImage(v, 0, 0, c.width, c.height);
    var imgData = ctx.getImageData(0, 0, c.width, c.height);
    var code = window.jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'dontInvert' });
    if (code && code.data && Date.now() - lastDecodedAt > 1500) {
      lastDecodedAt = Date.now();
      resolveScannedPayload(code.data);
    }
  }
  function resolveScannedPayload(data) {
    document.getElementById('g2ab-sc-scan-status').textContent = 'Verifying…';
    api('staff/qr-resolve', { method: 'POST', body: { payload: data } }).then(function (m) {
      stopScan();
      switchPane('waivers');
      // Show the resolved member as if it were a waiver-search result.
      renderWaiverResults([m]);
      toast(CFG.i18n.scanSuccess + ' · ' + (m.name || ''), 'ok');
    }).catch(function (e) {
      document.getElementById('g2ab-sc-scan-status').textContent = e.message;
    });
  }
  document.getElementById('g2ab-sc-scan-start').addEventListener('click', startScan);
  document.getElementById('g2ab-sc-scan-stop').addEventListener('click', stopScan);
  window.addEventListener('beforeunload', stopScan);

  // ----------------------- util ------------------------------------
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ----------------------- init -----------------------------------
  refreshSnapshot();
  setInterval(refreshSnapshot, 30000);
})();
