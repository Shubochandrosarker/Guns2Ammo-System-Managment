/*
 * Member self-check-in client. Talks only to the public /checkin/* routes.
 * Submits identity, then polls /checkin/status until staff confirms or
 * declines. Phone-first, no jQuery.
 */
(function () {
  'use strict';
  if (typeof window.g2abCheckin !== 'object') return;
  var CFG = window.g2abCheckin;
  var ROOT = document.getElementById('g2ab-mc');
  if (!ROOT || !CFG.valid) return;

  function $(sel) { return ROOT.querySelector(sel); }

  function api(path, opts) {
    opts = opts || {};
    var url = CFG.root.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    var body = opts.body;
    var headers = { 'X-WP-Nonce': CFG.nonce, 'Accept': 'application/json' };
    if (body && typeof body === 'object') {
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

  function step(name) {
    Array.prototype.forEach.call(ROOT.querySelectorAll('[data-step]'), function (el) {
      el.hidden = el.getAttribute('data-step') !== name;
    });
  }

  function startWaiting(req) {
    step('waiting');
    pollStatus(req.request_id);
  }

  function pollStatus(reqId) {
    var attempts = 0;
    var maxAttempts = 240; // ~8 minutes at 2-second intervals
    function tick() {
      attempts++;
      api('checkin/status?rid=' + encodeURIComponent(reqId)).then(function (r) {
        if (r.status === 'pending') {
          if (attempts < maxAttempts) setTimeout(tick, 2000);
          return;
        }
        if (r.status === 'confirmed') {
          $('#g2ab-mc-done-title').textContent = CFG.i18n.confirmed;
          $('#g2ab-mc-done-msg').textContent = r.message || '';
          step('done');
          return;
        }
        if (r.status === 'declined') {
          $('#g2ab-mc-done-title').textContent = CFG.i18n.declined;
          $('#g2ab-mc-done-msg').textContent = r.message || '';
          step('done');
        }
      }).catch(function () {
        if (attempts < maxAttempts) setTimeout(tick, 4000);
      });
    }
    setTimeout(tick, 1200);
  }

  // ----------- submit paths -----------
  var loggedIn = $('#g2ab-mc-go-loggedin');
  if (loggedIn) {
    loggedIn.addEventListener('click', function () {
      loggedIn.disabled = true;
      loggedIn.textContent = CFG.i18n.sending;
      api('checkin/initiate', { method: 'POST', body: { station: CFG.station, mode: 'user' } })
        .then(startWaiting)
        .catch(function (e) { loggedIn.disabled = false; loggedIn.textContent = e.message || 'Try again'; });
    });
  }
  var useForm = $('#g2ab-mc-use-form');
  if (useForm) {
    useForm.addEventListener('click', function () {
      if (loggedIn) loggedIn.hidden = true;
      useForm.hidden = true;
      $('#g2ab-mc-form').hidden = false;
    });
  }
  $('#g2ab-mc-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.currentTarget;
    var btn = f.querySelector('.g2ab-mc__btn');
    btn.disabled = true; btn.textContent = CFG.i18n.sending;
    api('checkin/initiate', {
      method: 'POST',
      body: { station: CFG.station, mode: 'guest', email: f.email.value, last_name: f.last_name.value }
    })
      .then(startWaiting)
      .catch(function (e) { btn.disabled = false; btn.textContent = e.message || 'Try again'; });
  });
})();
