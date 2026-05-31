/*
 * G2A Staff Console front-end app. Vanilla JS, no jQuery.
 *
 * v2 flow:
 *   - Dashboard pulls a station token from /staff/station-token and renders
 *     a QR code (via lazy-loaded qrcode-generator) for members to scan.
 *   - Member's phone hits /checkin/initiate which writes a pending request.
 *   - Dashboard polls /staff/pending every 2 seconds when on the station
 *     pane (and at idle intervals from other panes) to surface incoming
 *     check-ins as a popup with lane picker.
 *   - Staff confirms → /staff/finalize-checkin creates a checked_in
 *     booking, emails the member, refreshes the roster + KPIs.
 *   - Staff declines → /staff/decline-checkin flips the request status so
 *     the member's phone shows "see the front desk".
 *
 * The camera-based scanner (where staff scans a member-card QR) is no
 * longer the primary flow but can be re-added under [g2ab_staff_console]
 * if requested. The new pull-style QR is on by default.
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

  // ----------------------- util ------------------------------------
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
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
    var m = node && node.closest ? node.closest('.g2ab-sc__modal') : null;
    if (m) m.hidden = true;
    document.body.style.overflow = '';
  }
  function showFinish(title, body, kind) {
    var t = document.getElementById('g2ab-sc-mr-title');
    var b = document.getElementById('g2ab-sc-mr-body');
    var ic = document.getElementById('g2ab-sc-mr-ic');
    if (t) t.textContent = title || CFG.i18n.doneTitle;
    if (b) b.textContent = body || '';
    if (ic) { ic.className = 'g2ab-sc__finish-ic' + (kind === 'err' ? ' err' : ''); ic.textContent = kind === 'err' ? '!' : '✓'; }
    var m = document.getElementById('g2ab-sc-modal-result');
    if (m) { m.hidden = false; document.body.style.overflow = 'hidden'; }
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
      station:      'Check-In Station',
      waivers:      'Waivers',
      reservations: "Today's Reservations"
    };
    var t = document.getElementById('g2ab-sc-pane-title');
    if (t && titleMap[name]) t.textContent = titleMap[name];
    if (name === 'reservations') loadRoster();
    if (name === 'station')      ensureStation();
    // Pending polling runs continuously; pane switch is independent
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
    api('staff/snapshot').then(renderSnapshot).catch(function (e) { /* silent on background poll */ });
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
    out.innerHTML = rows.map(function (r, i) {
      var canCheckin = r.state === 'current';
      var safeRow = JSON.stringify(r).replace(/"/g, '&quot;');
      return '<div class="g2ab-sc__rcard" data-row="' + i + '">'
        + '<div>'
        +   '<div class="who">' + escapeHtml(r.name) + (r.tier ? ' <span style="font-family:var(--sc-font-mono);font-size:10px;color:var(--sc-brass-bright);">' + escapeHtml(r.tier) + '</span>' : '') + '</div>'
        +   '<div class="meta">' + escapeHtml(r.email_mask || '') + '</div>'
        +   '<div class="dates">' + (r.signed_at ? 'Signed: ' + escapeHtml(r.signed_at) : '—') + (r.expires_at ? ' · Expires: ' + escapeHtml(r.expires_at) : '') + '</div>'
        +   (canCheckin ? '<button class="g2ab-sc__btn" style="margin-top:10px;" data-checkin-row=\'' + safeRow + '\'>Check in →</button>' : '')
        + '</div>'
        + '<div class="status ' + escapeHtml(r.state) + '">' + escapeHtml(r.status) + '</div>'
        + '</div>';
    }).join('');
  }

  // Direct check-in from waiver search (the "type email → confirm" flow)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-checkin-row]');
    if (!b) return;
    try {
      var row = JSON.parse(b.getAttribute('data-checkin-row').replace(/&quot;/g, '"'));
      openConfirmModal({
        request_id: '',                 // no pending — staff is initiating
        name: row.name, email: row.email || '', email_mask: row.email_mask,
        status: row.status, state: row.state, signed_at: row.signed_at,
        expires_at: row.expires_at, tier: row.tier,
        _direct: true                   // bypass pending lookup on confirm
      });
    } catch (err) { toast('Could not parse row', 'err'); }
  });

  // ----------------------- walk-in modal ---------------------------
  var preloadedLanes = null;
  function preloadLanes() {
    api('staff/open-lanes').then(function (r) {
      preloadedLanes = r.lanes || [];
      var sel = document.getElementById('g2ab-sc-walkin-lane');
      if (sel) sel.innerHTML = preloadedLanes.map(function (l) {
        return '<option value="' + l.id + '"' + (l.state === 'in_use' ? ' disabled' : '') + '>' + escapeHtml(l.label) + (l.state === 'in_use' ? ' (in use)' : '') + '</option>';
      }).join('');
      var sel2 = document.getElementById('g2ab-sc-confirm-lane');
      if (sel2) sel2.innerHTML = preloadedLanes.filter(function (l) { return l.state === 'open'; }).map(function (l) {
        return '<option value="' + l.id + '">' + escapeHtml(l.label) + '</option>';
      }).join('') || '<option disabled>No open lanes — choose Decline.</option>';
    }).catch(function () { /* silent */ });
  }
  document.getElementById('g2ab-sc-walkin-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.currentTarget;
    var payload = {
      name: f.name.value, email: f.email.value, phone: f.phone.value,
      lane: parseInt(f.lane.value, 10),
      party_size: parseInt(f.party_size.value, 10) || 1,
      minutes: parseInt(f.minutes.value, 10) || 60
    };
    api('staff/walk-in', { method: 'POST', body: payload }).then(function (r) {
      document.getElementById('g2ab-sc-modal-walkin').hidden = true;
      f.reset();
      showFinish(CFG.i18n.doneTitle, r.message || 'Checked in.', 'ok');
      refreshSnapshot(); loadRoster();
    }).catch(function (e) { showFinish(CFG.i18n.errorTitle, e.message, 'err'); });
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
      toast(r.message || 'Done', 'ok'); loadRoster(); refreshSnapshot();
    }).catch(function (e) { showFinish(CFG.i18n.errorTitle, e.message, 'err'); })
      .finally(function () { b.disabled = false; });
  });

  // ----------------------- check-in station ------------------------
  var STATION = { token: '', url: '', exp: 0 };
  var qrLibPromise = null;
  function loadQRLib() {
    if (typeof window.qrcode === 'function') return Promise.resolve();
    if (qrLibPromise) return qrLibPromise;
    qrLibPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js';
      s.async = true;
      s.crossOrigin = 'anonymous';
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Could not load QR library.')); };
      document.head.appendChild(s);
    });
    return qrLibPromise;
  }
  function ensureStation() {
    if (STATION.token && STATION.exp - 60 > Math.floor(Date.now() / 1000)) {
      paintQR();
      return;
    }
    api('staff/station-token').then(function (r) {
      STATION.token = r.token; STATION.url = r.url; STATION.exp = r.exp;
      paintQR();
      startPendingPoll();
      preloadLanes();
    }).catch(function (e) {
      var el = document.getElementById('g2ab-sc-qr');
      if (el) el.innerHTML = '<div class="g2ab-sc__empty">' + escapeHtml(e.message) + '</div>';
    });
  }
  function paintQR() {
    var el = document.getElementById('g2ab-sc-qr');
    var urlEl = document.getElementById('g2ab-sc-qr-url');
    if (!el) return;
    if (urlEl) urlEl.textContent = STATION.url;
    loadQRLib().then(function () {
      try {
        var qr = window.qrcode(0, 'M');     // version 0 = auto; M = medium ECC
        qr.addData(STATION.url);
        qr.make();
        el.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 1, scalable: true });
      } catch (e) {
        el.innerHTML = '<div class="g2ab-sc__empty">QR render failed: ' + escapeHtml(e.message) + '</div>';
      }
    }).catch(function (e) {
      el.innerHTML = '<div class="g2ab-sc__empty">' + escapeHtml(e.message) + '<br><span style="font-family:var(--sc-font-mono);font-size:11px;">' + escapeHtml(STATION.url) + '</span></div>';
    });
  }

  // Pending poll — kicks in once we have a station token
  var pollTimer = null, currentRid = '';
  function startPendingPoll() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollPending, 2500);
    pollPending();
  }
  function pollPending() {
    if (!STATION.token) return;
    api('staff/pending?station=' + encodeURIComponent(STATION.token)).then(function (r) {
      var rec = r && r.pending;
      if (!rec) { renderPendingList([]); return; }
      renderPendingList([rec]);
      if (rec.request_id !== currentRid) {
        currentRid = rec.request_id;
        openConfirmModal(rec);
      }
    }).catch(function () { /* silent */ });
  }
  function renderPendingList(items) {
    var el = document.getElementById('g2ab-sc-pending');
    if (!el) return;
    if (!items.length) { el.innerHTML = '<div class="g2ab-sc__empty">Waiting for a member to scan…</div>'; return; }
    el.innerHTML = items.map(function (it) {
      return '<div class="g2ab-sc__pending-item">'
        + '<div>'
        +   '<div class="who">' + escapeHtml(it.name) + '</div>'
        +   '<div class="meta">' + escapeHtml(it.status) + ' · ' + escapeHtml((it.email_mask || '')) + '</div>'
        + '</div>'
        + '<button class="g2ab-sc__btn" data-open-confirm="' + escapeHtml(it.request_id) + '">Review</button>'
        + '</div>';
    }).join('');
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-open-confirm]'); if (!b) return;
    var rid = b.getAttribute('data-open-confirm');
    api('staff/pending?station=' + encodeURIComponent(STATION.token)).then(function (r) {
      if (r && r.pending && r.pending.request_id === rid) openConfirmModal(r.pending);
    });
  });

  // ----------------------- confirm modal --------------------------
  var confirmRecord = null;
  function openConfirmModal(rec) {
    confirmRecord = rec;
    var status = document.getElementById('g2ab-sc-mc-status');
    var details = document.getElementById('g2ab-sc-mc-details');
    var state = rec.state || 'missing';
    var label = state === 'current' ? CFG.i18n.waiverCurrent
              : state === 'expired' ? CFG.i18n.waiverExpired
              : CFG.i18n.waiverMissing;
    status.className = 'g2ab-sc__checkin-status ' + state;
    status.textContent = label;
    details.innerHTML = ''
      + '<div class="k">Name</div><div class="v">' + escapeHtml(rec.name || '') + '</div>'
      + '<div class="k">Email</div><div class="v">' + escapeHtml(rec.email_mask || rec.email || '—') + '</div>'
      + (rec.tier ? '<div class="k">Tier</div><div class="v">' + escapeHtml(rec.tier) + '</div>' : '')
      + (rec.signed_at ? '<div class="k">Waiver signed</div><div class="v">' + escapeHtml(rec.signed_at) + '</div>' : '')
      + (rec.expires_at ? '<div class="k">Expires</div><div class="v">' + escapeHtml(rec.expires_at) + '</div>' : '');
    preloadLanes();
    openModal('confirm');
  }
  document.getElementById('g2ab-sc-confirm-form').addEventListener('submit', function (e) {
    e.preventDefault();
    if (!confirmRecord) return;
    var f = e.currentTarget;
    var payload = {
      request_id: confirmRecord.request_id || '',
      lane_id: parseInt(f.lane_id.value, 10),
      minutes: parseInt(f.minutes.value, 10) || 60,
      party_size: parseInt(f.party_size.value, 10) || 1,
      send_email: !!f.send_email.checked
    };
    // Direct-from-search path: there's no pending request_id, so we
    // create a synthetic walk-in based on the resolved member email.
    if (confirmRecord._direct || !payload.request_id) {
      var walkin = {
        name: confirmRecord.name, email: confirmRecord.email || '',
        lane: payload.lane_id, party_size: payload.party_size, minutes: payload.minutes
      };
      api('staff/walk-in', { method: 'POST', body: walkin }).then(function (r) {
        document.getElementById('g2ab-sc-modal-confirm').hidden = true;
        confirmRecord = null;
        showFinish(CFG.i18n.doneTitle, r.message || 'Checked in.', 'ok');
        refreshSnapshot(); loadRoster();
      }).catch(function (e) { showFinish(CFG.i18n.errorTitle, e.message, 'err'); });
      return;
    }
    api('staff/finalize-checkin', { method: 'POST', body: payload }).then(function (r) {
      document.getElementById('g2ab-sc-modal-confirm').hidden = true;
      confirmRecord = null; currentRid = '';
      showFinish(CFG.i18n.doneTitle, r.message || 'Checked in.', 'ok');
      refreshSnapshot(); loadRoster(); pollPending();
    }).catch(function (e) { showFinish(CFG.i18n.errorTitle, e.message, 'err'); });
  });
  document.getElementById('g2ab-sc-confirm-decline').addEventListener('click', function () {
    if (!confirmRecord || !confirmRecord.request_id) {
      document.getElementById('g2ab-sc-modal-confirm').hidden = true;
      confirmRecord = null;
      return;
    }
    var reason = window.prompt(CFG.i18n.requireMember || 'Reason (optional):', '') || '';
    api('staff/decline-checkin', { method: 'POST', body: { request_id: confirmRecord.request_id, reason: reason } }).then(function () {
      document.getElementById('g2ab-sc-modal-confirm').hidden = true;
      confirmRecord = null; currentRid = '';
      toast('Declined', 'err');
      pollPending();
    }).catch(function (e) { showFinish(CFG.i18n.errorTitle, e.message, 'err'); });
  });

  // ----------------------- init -----------------------------------
  refreshSnapshot();
  setInterval(refreshSnapshot, 30000);
  // Pull a token early so the QR is ready when staff clicks the pane
  ensureStation();
})();
