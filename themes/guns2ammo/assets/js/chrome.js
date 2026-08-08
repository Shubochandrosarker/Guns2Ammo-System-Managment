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
  // Safety net only — the inline header script owns the 400ms ceiling;
  // this catches the rare case where that script was stripped by an
  // optimizer plugin.
  setTimeout(dismissPreloader, 800);

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
      // iOS Safari ignores `overflow:hidden` on <body> for background
      // touch-scroll, so pin the body in place with position:fixed and
      // remember the scroll offset to restore it on close.
      document.body.style.top = (-window.scrollY) + 'px';
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
      var scrollY = -parseInt(document.body.style.top || '0', 10);
      document.body.classList.remove('g2a-noscroll');
      document.body.style.top = '';
      window.scrollTo(0, scrollY);
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

    /* ===== Theme mode toggle (light / dark) =====
     * header.php stamps <html data-theme> before first paint (saved
     * choice → OS preference → dark). This button flips it manually
     * and persists the choice; while no manual choice exists, OS-level
     * scheme changes are mirrored live. */
    var modeBtn = document.getElementById('g2a-mode-toggle');
    var applyTheme = function (t, persist) {
      document.documentElement.setAttribute('data-theme', t);
      if (persist) {
        document.documentElement.removeAttribute('data-theme-auto');
        try { localStorage.setItem('g2a-theme', t); } catch (e) {}
      }
      var meta = document.getElementById('g2a-theme-color');
      if (meta) meta.setAttribute('content', t === 'light' ? '#F4F2ED' : '#1A191E');
    };
    if (modeBtn) {
      modeBtn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        applyTheme(next, true);
      });
    }
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: light)');
      var onScheme = function (e) {
        if (document.documentElement.hasAttribute('data-theme-auto')) {
          applyTheme(e.matches ? 'light' : 'dark', false);
        }
      };
      if (mq.addEventListener) mq.addEventListener('change', onScheme);
      else if (mq.addListener) mq.addListener(onScheme);
    }

    /* ===== Profile dropdown ===== */
    var profile = document.getElementById('g2a-profile');
    var pBtn    = document.getElementById('g2a-profile-btn');
    if (profile && pBtn) {
      pBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = profile.classList.toggle('open');
        pBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', function (e) {
        if (!profile.contains(e.target)) {
          profile.classList.remove('open');
          pBtn.setAttribute('aria-expanded', 'false');
        }
      });
    }
    var logoutLink = document.getElementById('g2a-logout');
    if (logoutLink) logoutLink.addEventListener('click', function (e) {
      // Let WordPress' real logout handle session teardown; the chrome
      // UI follows the body.logged-in class that WP emits. No
      // localStorage-driven UI fake.
    });

    /* ===== Live Open/Closed pill =====
     * Reads from window.g2aBiz (localized by inc/business-info.php)
     * so the schema JSON-LD, the footer hours block, the contact
     * page, and this pill all draw from one source of truth. No
     * more hard-coded "Today Tue" or 449/500 drift.
     *
     * Uses Mesa, AZ local time (America/Phoenix — no DST) so a
     * visitor in any timezone sees the range's real status.
     */
    var liveEl = document.getElementById('g2a-live-status');
    if (liveEl) {
      var BIZ = (window.g2aBiz && typeof window.g2aBiz === 'object') ? window.g2aBiz : null;
      var TZ  = (BIZ && BIZ.tz)  ? BIZ.tz  : 'America/Phoenix';
      var fmt = function (m) { var h = Math.floor(m / 60); var am = h < 12; var h12 = h % 12 || 12; return h12 + (am ? 'am' : 'pm'); };
      var weekdayName = function (d) { return ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][d]; };
      var weekdayShort = function (d) { return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d]; };
      // Build ranges from the localized BIZ.hours map. Each entry
      // is either { open, close } in minutes-from-midnight or null
      // for closed-on-this-day. Falls back to the historical
      // hardcoded schedule only if BIZ is missing entirely (which
      // would mean the localizer didn't run — broken install).
      var ranges = {};
      if (BIZ && BIZ.hours) {
        for (var k in BIZ.hours) {
          if (Object.prototype.hasOwnProperty.call(BIZ.hours, k)) {
            var h = BIZ.hours[k];
            if (h && typeof h.open === 'number' && typeof h.close === 'number' && h.close > h.open) {
              ranges[k] = [h.open, h.close];
            }
          }
        }
      }
      if (Object.keys(ranges).length === 0) {
        ranges = { 0:[720,1080], 1:[600,1080], 2:[600,1080], 3:[600,1080], 4:[600,1080], 5:[600,1140], 6:[600,1140] };
      }
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
            timeZone: TZ,
            hour: '2-digit', minute: '2-digit', hour12: false
          });
          // time is "HH:MM" or "HH:MM:SS" or "24:00" — match HH:MM only.
          var m = time.match(/(\d{1,2}):(\d{2})/);
          var hr  = m ? parseInt(m[1], 10) : 0;
          var min = m ? parseInt(m[2], 10) : 0;
          if (hr === 24) hr = 0; // some engines report 24:00 for midnight
          // Weekday separately — short en-US ("Mon", "Tue", …)
          var wd = d.toLocaleString('en-US', {
            timeZone: TZ,
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
        var closed_today = !r; // entire day flagged closed in settings
        var before_open = r && m.mins < r[0];
        liveEl.classList.toggle('open', !!open);
        liveEl.classList.toggle('closed', !open);
        var lbl = document.getElementById('g2a-live-label');
        var tm  = document.getElementById('g2a-live-time');
        // Real day name (instead of literal "Today Tue" hard-coded
        // earlier). When closed today, surface the NEXT open day
        // and its hours so a visitor at 9pm Sunday isn't left
        // staring at "Closed" with no next-step info.
        var todayShort = weekdayShort(m.day);
        if (lbl) lbl.textContent = open ? 'Open Now' : 'Closed';
        if (tm) {
          if (open) {
            tm.textContent = ' Until ' + fmt(r[1]) + ' MST';
          } else if (before_open) {
            tm.textContent = ' Opens ' + fmt(r[0]) + ' MST';
          } else if (closed_today) {
            // Find next day with hours
            var nextDay = m.day, hops = 0;
            while (hops < 7) {
              nextDay = (nextDay + 1) % 7; hops++;
              if (ranges[nextDay]) {
                tm.textContent = ' Opens ' + weekdayShort(nextDay) + ' ' + fmt(ranges[nextDay][0]) + ' MST';
                return;
              }
            }
            tm.textContent = ' Hours unavailable';
          } else {
            // After close — show today's hours and next day's open
            var nextDay2 = (m.day + 1) % 7;
            var nr = ranges[nextDay2];
            tm.textContent = nr
              ? ' Opens ' + weekdayShort(nextDay2) + ' ' + fmt(nr[0]) + ' MST'
              : ' Closed today  ' + todayShort;
          }
        }
      };
      update();
      setInterval(update, 60000);
    }

    /* ===== Reveal on scroll (unified) =====
     * Supports both conventions used across the theme:
     *   [data-reveal]            fade/slide in (variants left|right|scale|fade via CSS)
     *   [data-reveal-group]      children [data-reveal] cascade with a stagger delay
     *   [data-reveal-stagger]    container whose direct children stagger (CSS-driven)
     *   [data-countup]           animate a number up to data-countup when revealed
     * All honor prefers-reduced-motion. After entrance, the inline delay is
     * cleared and .did-reveal restores snappy hover physics.
     */
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var REVEAL_STEP = 90, REVEAL_CAP = 5;
    if (!reduceMotion) {
      document.querySelectorAll('[data-reveal-group]').forEach(function (group) {
        group.querySelectorAll('[data-reveal]').forEach(function (el, i) {
          el.style.transitionDelay = (Math.min(i, REVEAL_CAP) * REVEAL_STEP) + 'ms';
        });
      });
    }
    var startCountup = function (el) {
      var target = parseFloat(el.dataset.countup);
      if (isNaN(target) || el.dataset.countupDone) return;
      el.dataset.countupDone = '1';
      if (reduceMotion) { el.textContent = (el.dataset.countupSuffix ? target + el.dataset.countupSuffix : target); return; }
      var suffix = el.dataset.countupSuffix || '';
      var decimals = (String(el.dataset.countup).split('.')[1] || '').length;
      var dur = 1400, t0 = null;
      var step = function (ts) {
        if (!t0) t0 = ts;
        var p = Math.min(1, (ts - t0) / dur);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = (target * eased).toFixed(decimals) + suffix;
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    };
    var revealDone = function (el) {
      var delay = parseInt(el.style.transitionDelay, 10) || 0;
      setTimeout(function () { el.style.transitionDelay = ''; el.classList.add('did-reveal'); }, delay + 750);
    };
    var REVEAL_SEL = '[data-reveal], [data-reveal-stagger], [data-countup]';
    if ('IntersectionObserver' in window && !reduceMotion) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          en.target.classList.add('is-in');
          if (en.target.hasAttribute('data-countup')) startCountup(en.target);
          revealDone(en.target);
          io.unobserve(en.target);
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      document.querySelectorAll(REVEAL_SEL).forEach(function (el) { io.observe(el); });
    } else {
      document.querySelectorAll(REVEAL_SEL).forEach(function (el) { el.classList.add('is-in', 'did-reveal'); });
      document.querySelectorAll('[data-countup]').forEach(startCountup);
    }

    /* ===== FAQ accordion (homepage "Common Questions") =====
     * Delegated click on [data-faq-toggle] buttons — keyboard accessible
     * for free (real <button>s), aria-expanded kept in sync, one item
     * open at a time per list. Height animates via grid-template-rows
     * (see .home-faq .faq-a in front-page.css).
     */
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-faq-toggle]');
      if (!btn) return;
      var item = btn.closest('.faq-item');
      if (!item) return;
      var list = item.parentElement;
      var wasOpen = item.classList.contains('open');
      if (list) {
        list.querySelectorAll('.faq-item.open').forEach(function (other) {
          if (other === item) return;
          other.classList.remove('open');
          var b = other.querySelector('[data-faq-toggle]');
          if (b) b.setAttribute('aria-expanded', 'false');
        });
      }
      item.classList.toggle('open', !wasOpen);
      btn.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
    });

    /* ===== Lazy image fade-in ===== */
    document.querySelectorAll('img[loading="lazy"]').forEach(function (img) {
      if (img.complete) img.setAttribute('data-loaded', '1');
      else img.addEventListener('load', function () { img.setAttribute('data-loaded', '1'); }, { once: true });
    });

    /* ===== Quick-view modal ===== */
    var qv = document.getElementById('g2a-qv');
    if (qv) {
      var qvClose = document.getElementById('g2a-qv-close');
      var qvLastFocus = null;
      // The dialog ships aria-hidden + inert (closed). Both must be lifted
      // while open and restored on close, or the a11y tree is malformed:
      // aria-hidden content must never contain focusable elements.
      var qvOpen = function () {
        qvLastFocus = document.activeElement;
        qv.removeAttribute('aria-hidden');
        qv.removeAttribute('inert');
        qv.classList.add('open');
        if (qvClose) qvClose.focus();
      };
      var qvDismiss = function () {
        if (!qv.classList.contains('open')) return;
        qv.classList.remove('open');
        qv.setAttribute('aria-hidden', 'true');
        qv.setAttribute('inert', '');
        if (qvLastFocus && document.contains(qvLastFocus)) qvLastFocus.focus();
        qvLastFocus = null;
      };
      qvClose && qvClose.addEventListener('click', qvDismiss);
      qv.addEventListener('click', function (e) { if (e.target === qv) qvDismiss(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') qvDismiss();
        // Minimal focus trap while open (mirrors the drawer's).
        if (e.key === 'Tab' && qv.classList.contains('open')) {
          var f = qv.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
          if (!f.length) return;
          var first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      });
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
          qvOpen();
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

    /* ===== Reservation package pre-select (e.g. Machine Gun tiers) =====
     * Buttons with [data-g2a-package] jump to the shared reservation form
     * (#reserve) via their normal href; this just pre-selects that form's
     * Package field so the captured request records which tier the visitor
     * clicked, without needing a dedicated form per tier. */
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-g2a-package]');
      if (!trigger) return;
      var sel = document.getElementById('rf-package');
      if (sel) sel.value = trigger.getAttribute('data-g2a-package');
    });

    /* ===== FFL shipping quote calculator =====
     * Keeps the visible + submitted "Estimated Total" (FFL Services page) in
     * sync with the selected service tier — price lives on each
     * <option data-price>, read fresh on change so it can never go stale. */
    var shipService    = document.getElementById('g2a-ship-service');
    var shipTotal      = document.getElementById('g2a-ship-total');
    var shipTotalInput = document.getElementById('g2a-ship-total-input');
    if (shipService && shipTotal && shipTotalInput) {
      var syncShipTotal = function () {
        var opt   = shipService.options[shipService.selectedIndex];
        var price = opt ? parseFloat(opt.getAttribute('data-price')) : NaN;
        var text  = '$' + (isNaN(price) ? '0.00' : price.toFixed(2));
        shipTotal.textContent = text;
        shipTotalInput.value = text;
      };
      shipService.addEventListener('change', syncShipTotal);
      syncShipTotal();
    }
  });
})();
