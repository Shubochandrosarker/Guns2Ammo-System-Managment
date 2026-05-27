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

  /* ===== PRELOADER =====
   * Dismiss as soon as the DOM is parsed — NOT on full window
   * load. Waiting for `load` waits for every image, font, and
   * 3rd-party script (chat widget, analytics, fonts.googleapis,
   * etc.) which can stretch to 5–10s on slow connections, leaving
   * the brand preloader on screen far longer than the user-perceived
   * "site is ready" moment. DOMContentLoaded fires once the HTML +
   * CSS are parsed and the page is meaningfully laid out.
   */
  function dismissPreloader() {
    var pl = document.getElementById('g2a-preloader');
    if (!pl) { document.documentElement.classList.remove('g2a-loading'); return; }
    document.documentElement.classList.remove('g2a-loading');
    pl.classList.add('done');
    setTimeout(function () { if (pl.parentNode) pl.parentNode.removeChild(pl); }, 240);
  }
  if (document.readyState !== 'loading') {
    // DOM already parsed before this script ran — hand off immediately.
    dismissPreloader();
  } else {
    document.addEventListener('DOMContentLoaded', dismissPreloader, { once: true });
  }
  // Safety net: was 4s and visibly stalled the page in incognito on
  // cold caches. 1.2s is plenty for the preloader to register
  // visually without ever blocking the real site for noticeable time.
  setTimeout(dismissPreloader, 1200);

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
    var FOCUSABLE_SEL = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var lastFocus = null;

    var focusableInDrawer = function () {
      if (!drawer) return [];
      return Array.prototype.slice.call(drawer.querySelectorAll(FOCUSABLE_SEL));
    };
    var trapTab = function (e) {
      if (e.key !== 'Tab' || !drawer || !drawer.classList.contains('open')) return;
      var f = focusableInDrawer();
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault(); first.focus();
      }
    };
    var openDrawer = function () {
      if (!drawer) return;
      lastFocus = document.activeElement;
      drawer.classList.add('open');
      drawer.setAttribute('aria-hidden', 'false');
      document.body.classList.add('g2a-noscroll');
      if (burger) burger.setAttribute('aria-expanded', 'true');
      // Hide the rest of the page from assistive tech while the
      // drawer is the modal context.
      Array.prototype.forEach.call(document.body.children, function (el) {
        if (el !== drawer && el.id !== 'g2a-skip-link' && !el.classList.contains('g2a-skip-link')) {
          el.setAttribute('data-g2a-inerted', el.getAttribute('inert') || '');
          el.setAttribute('inert', '');
          el.setAttribute('aria-hidden', 'true');
        }
      });
      // Move focus into the drawer.
      var f = focusableInDrawer();
      if (f.length) f[0].focus(); else drawer.focus();
    };
    var closeDrawer = function () {
      if (!drawer) return;
      drawer.classList.remove('open');
      drawer.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('g2a-noscroll');
      if (burger) burger.setAttribute('aria-expanded', 'false');
      Array.prototype.forEach.call(document.body.children, function (el) {
        if (el.hasAttribute('data-g2a-inerted')) {
          var prev = el.getAttribute('data-g2a-inerted');
          el.removeAttribute('data-g2a-inerted');
          if (prev) el.setAttribute('inert', prev); else el.removeAttribute('inert');
          el.removeAttribute('aria-hidden');
        }
      });
      if (lastFocus && lastFocus.focus) lastFocus.focus();
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
      trapTab(e);
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
      // Let WordPress' real logout handle session teardown; the chrome
      // UI follows the body.logged-in class that WP emits. No
      // localStorage-driven UI fake.
    });

    /* ===== Live Open/Closed pill =====
     * Uses Mesa, AZ local time (America/Phoenix — no DST) so a
     * visitor in any timezone sees the range's real status. The old
     * implementation used `new Date()` directly which is the user's
     * local time, so a Mesa customer in Phoenix saw correct hours
     * but anyone outside Arizona saw the wrong status.
     */
    var liveEl = document.getElementById('g2a-live-status');
    if (liveEl) {
      var fmt = function (m) { var h = Math.floor(m / 60); var am = h < 12; var h12 = h % 12 || 12; return h12 + (am ? 'am' : 'pm'); };
      // Mesa hours (minutes from midnight). Sun(0): 12-6 / Mon-Thu(1-4): 10-6 / Fri(5): 10-7 / Sat(6): 10-7.
      var ranges = { 0: [720, 1080], 1: [600, 1080], 2: [600, 1080], 3: [600, 1080], 4: [600, 1080], 5: [600, 1140], 6: [600, 1140] };
      function mesaNow() {
        // Robust Mesa-time read. Earlier version used
        // hourCycle: 'h23', which Safari + some Firefox builds
        // ignore — formatToParts then returns a 12-hour-clock hour
        // without an AM/PM marker, so 12:55pm parsed as "12" with
        // min 55 and the comparator was fine BUT 1pm parsed as "1"
        // and worked, while edge cases (midnight, single-digit
        // hours) ended up as NaN → comparator always false → pill
        // stuck on "Closed". Using `hour12: false` is universally
        // honored and the source string is HH:MM which we parse
        // with a regex.
        try {
          var d = new Date();
          var time = d.toLocaleString('en-US', {
            timeZone: 'America/Phoenix',
            hour: '2-digit', minute: '2-digit', hour12: false
          });
          // time is "HH:MM" or "HH:MM:SS" or "24:00" — match HH:MM only.
          var m = time.match(/(\d{1,2}):(\d{2})/);
          var hr  = m ? parseInt(m[1], 10) : 0;
          var min = m ? parseInt(m[2], 10) : 0;
          if (hr === 24) hr = 0; // some engines report 24:00 for midnight
          // Weekday separately — short en-US ("Mon", "Tue", …)
          var wd = d.toLocaleString('en-US', {
            timeZone: 'America/Phoenix',
            weekday: 'short'
          });
          var wdMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
          var day = wdMap[wd] != null ? wdMap[wd] : d.getDay();
          return { day: day, mins: hr * 60 + min };
        } catch (e) {
          // Last-resort fallback — visitor's local time
          var now = new Date();
          return { day: now.getDay(), mins: now.getHours() * 60 + now.getMinutes() };
        }
      }
      var update = function () {
        var m = mesaNow();
        var r = ranges[m.day];
        var open = r && m.mins >= r[0] && m.mins < r[1];
        liveEl.classList.toggle('open', !!open);
        liveEl.classList.toggle('closed', !open);
        var lbl = document.getElementById('g2a-live-label');
        var tm  = document.getElementById('g2a-live-time');
        if (lbl) lbl.textContent = open ? 'Open Now' : 'Closed';
        if (tm)  tm.textContent  = open ? ' Until ' + fmt(r[1]) + ' MST' : (r ? ' Today ' + fmt(r[0]) + '-' + fmt(r[1]) + ' MST' : 'Closed');
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
