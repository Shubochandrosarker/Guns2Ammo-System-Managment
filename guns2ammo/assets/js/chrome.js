/* GUNS 2 AMMO  Front-end chrome (lite)
 * Nav/footer are rendered by PHP. This file handles:
 *   - Preloader hand-off
 *   - Sticky nav scroll state
 *   - Mobile drawer toggle
 *   - Profile dropdown (login state via localStorage; replace with WP nonce)
 *   - Live open/closed status pill (auto-updates each minute)
 *   - Reveal-on-scroll for [data-reveal]
 *   - Quick-view modal
 *   - Countdown timers ([data-countdown="ISO"] + [data-product-cd])
 *   - Lazy image fade-in
 */
(function () {
  'use strict';

  /* ===== PRELOADER ===== */
  function dismissPreloader() {
    var pl = document.getElementById('g2a-preloader');
    if (!pl) { document.documentElement.classList.remove('g2a-loading'); return; }
    document.documentElement.classList.remove('g2a-loading');
    pl.classList.add('done');
    setTimeout(function () { pl.remove(); }, 500);
  }
  if (document.readyState === 'complete') dismissPreloader();
  else window.addEventListener('load', function () { setTimeout(dismissPreloader, 200); });
  // Safety net: never hold the page longer than 4s
  setTimeout(dismissPreloader, 4000);

  document.addEventListener('DOMContentLoaded', function () {

    /* ===== NAV scroll state ===== */
    var nav = document.getElementById('g2a-nav');
    if (nav) {
      var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 60); };
      document.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    }

    /* ===== Mobile drawer ===== */
    var burger = document.getElementById('g2a-burger');
    var drawer = document.getElementById('g2a-mobile');
    var mclose = document.getElementById('g2a-mclose');
    var openDrawer = function () {
      if (!drawer) return;
      drawer.classList.add('open');
      document.body.classList.add('g2a-noscroll');
      if (burger) burger.setAttribute('aria-expanded', 'true');
    };
    var closeDrawer = function () {
      if (!drawer) return;
      drawer.classList.remove('open');
      document.body.classList.remove('g2a-noscroll');
      if (burger) burger.setAttribute('aria-expanded', 'false');
    };
    if (burger && drawer) {
      burger.setAttribute('aria-expanded', 'false');
      burger.addEventListener('click', function () {
        drawer.classList.contains('open') ? closeDrawer() : openDrawer();
      });
    }
    if (mclose && drawer) mclose.addEventListener('click', closeDrawer);
    if (drawer) {
      // Close when a drawer link is tapped, or when the backdrop area is tapped.
      drawer.addEventListener('click', function (e) {
        if (e.target.closest('a')) closeDrawer();
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer && drawer.classList.contains('open')) closeDrawer();
    });
    // Safety: never leave the drawer stuck open when resizing up to desktop.
    window.addEventListener('resize', function () {
      if (window.innerWidth > 1100 && drawer && drawer.classList.contains('open')) closeDrawer();
    });

    /* ===== Profile dropdown ===== */
    var profile = document.getElementById('g2a-profile');
    var pBtn    = document.getElementById('g2a-profile-btn');
    if (profile && pBtn) {
      pBtn.addEventListener('click', function (e) { e.stopPropagation(); profile.classList.toggle('open'); });
      document.addEventListener('click', function (e) { if (!profile.contains(e.target)) profile.classList.remove('open'); });
    }
    var logoutLink = document.getElementById('g2a-logout');
    if (logoutLink) logoutLink.addEventListener('click', function (e) {
      e.preventDefault();
      try { localStorage.removeItem('g2a-auth'); } catch (err) {}
      location.reload();
    });
    window.g2aAuth = function (on) {
      try { on ? localStorage.setItem('g2a-auth', '1') : localStorage.removeItem('g2a-auth'); } catch (err) {}
      location.reload();
    };

    /* ===== Live Open/Closed pill ===== */
    var liveEl = document.getElementById('g2a-live-status');
    if (liveEl) {
      var fmt = function (m) { var h = Math.floor(m / 60); var am = h < 12; var h12 = h % 12 || 12; return h12 + (am ? 'am' : 'pm'); };
      var ranges = { 0: [720, 1080], 1: [600, 1080], 2: [600, 1080], 3: [600, 1080], 4: [600, 1080], 5: null, 6: [540, 1200] };
      var update = function () {
        var now = new Date();
        var day = now.getDay();
        var cur = now.getHours() * 60 + now.getMinutes();
        var r = ranges[day];
        var open = r && cur >= r[0] && cur < r[1];
        liveEl.classList.toggle('open', !!open);
        liveEl.classList.toggle('closed', !open);
        var lbl = document.getElementById('g2a-live-label');
        var tm  = document.getElementById('g2a-live-time');
        if (lbl) lbl.textContent = open ? 'Open Now' : 'Closed';
        if (tm)  tm.textContent  = open ? ' Until ' + fmt(r[1]) : (day === 5 ? ' Friday' : (r ? ' Today ' + fmt(r[0]) + '-' + fmt(r[1]) : 'Closed'));
      };
      update();
      setInterval(update, 60000);
    }

    /* ===== Reveal on scroll ===== */
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      document.querySelectorAll('[data-reveal]').forEach(function (el) { io.observe(el); });
    } else {
      document.querySelectorAll('[data-reveal]').forEach(function (el) { el.classList.add('is-in'); });
    }

    /* ===== Lazy image fade-in ===== */
    document.querySelectorAll('img[loading="lazy"]').forEach(function (img) {
      if (img.complete) img.setAttribute('data-loaded', '1');
      else img.addEventListener('load', function () { img.setAttribute('data-loaded', '1'); }, { once: true });
    });

    /* ===== Quick-view modal ===== */
    var qv = document.getElementById('g2a-qv');
    if (qv) {
      var qvClose = document.getElementById('g2a-qv-close');
      qvClose && qvClose.addEventListener('click', function () { qv.classList.remove('open'); });
      qv.addEventListener('click', function (e) { if (e.target === qv) qv.classList.remove('open'); });
      document.body.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-quickview]');
        if (!trigger) return;
        e.preventDefault();
        try {
          var data = JSON.parse(trigger.dataset.quickview);
          var set = function (id, val) { var n = document.getElementById(id); if (n) n.textContent = val || ''; };
          set('g2a-qv-brand', data.brand);
          set('g2a-qv-title', data.title);
          set('g2a-qv-price', data.price);
          set('g2a-qv-was',   data.was);
          set('g2a-qv-desc',  data.desc);
          var img = document.getElementById('g2a-qv-img');
          if (img) {
            img.dataset.pl = data.imgLabel || data.title || 'PRODUCT';
            img.style.backgroundImage = data.img
              ? 'linear-gradient(180deg, rgba(10,10,10,0.3), rgba(10,10,10,0.6)), url("' + data.img + '")'
              : '';
            img.style.backgroundSize = data.img ? 'cover' : '';
            img.style.backgroundPosition = data.img ? 'center' : '';
          }
          var meta = data.meta || {};
          var box = document.getElementById('g2a-qv-meta');
          if (box) box.innerHTML = Object.keys(meta).map(function (k) {
            return '<div class="row"><span class="l">' + k + '</span><span class="v">' + meta[k] + '</span></div>';
          }).join('');
          var cart = document.getElementById('g2a-qv-cart');
          if (cart) {
            cart.setAttribute('href', data.cart_url || '#');
          }
          var detail = document.getElementById('g2a-qv-detail');
          if (detail) {
            detail.setAttribute('href', data.detail_url || '#');
          }
          qv.classList.add('open');
        } catch (err) {}
      });
    }

    /* ===== Countdowns ===== */
    document.querySelectorAll('[data-countdown]').forEach(function (el) {
      var target = new Date(el.dataset.countdown).getTime();
      el.innerHTML =
        '<div class="unit"><span class="n" data-u="d">00</span><div class="l">Days</div></div>' +
        '<div class="unit"><span class="n" data-u="h">00</span><div class="l">Hours</div></div>' +
        '<div class="unit"><span class="n" data-u="m">00</span><div class="l">Min</div></div>' +
        '<div class="unit"><span class="n" data-u="s">00</span><div class="l">Sec</div></div>';
      var prev = { d: '', h: '', m: '', s: '' };
      var tick = function () {
        var diff = Math.max(0, target - Date.now());
        var vals = {
          d: String(Math.floor(diff / 86400000)).padStart(2, '0'),
          h: String(Math.floor((diff / 3600000) % 24)).padStart(2, '0'),
          m: String(Math.floor((diff / 60000) % 60)).padStart(2, '0'),
          s: String(Math.floor((diff / 1000) % 60)).padStart(2, '0')
        };
        el.querySelectorAll('.n').forEach(function (n) {
          var u = n.dataset.u;
          if (vals[u] !== prev[u]) {
            var unit = n.parentElement;
            unit.classList.remove('flip'); void unit.offsetWidth; unit.classList.add('flip');
            n.textContent = vals[u];
            prev[u] = vals[u];
          }
        });
      };
      tick(); setInterval(tick, 1000);
    });
    document.querySelectorAll('[data-product-cd]').forEach(function (el) {
      var target = new Date(el.dataset.productCd).getTime();
      var tick = function () {
        var diff = Math.max(0, target - Date.now());
        var map = {
          h: String(Math.floor(diff / 3600000)).padStart(2, '0'),
          m: String(Math.floor((diff / 60000) % 60)).padStart(2, '0'),
          s: String(Math.floor((diff / 1000) % 60)).padStart(2, '0')
        };
        el.querySelectorAll('.u').forEach(function (u) { u.textContent = map[u.dataset.u]; });
      };
      tick(); setInterval(tick, 1000);
    });
  });
})();
