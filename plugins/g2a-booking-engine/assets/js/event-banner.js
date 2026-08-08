(function (window, document) {
	'use strict';

	var timers = new WeakMap();
	var config = window.G2ABEventBannerConfig || {};

	function pad(num) {
		num = Math.max(0, parseInt(num, 10) || 0);
		return num < 10 ? '0' + num : String(num);
	}

	function setText(root, selector, value) {
		var el = root.querySelector(selector);
		if (el) {
			el.textContent = value;
		}
	}

	function initCountdown(el) {
		if (!el) {
			return;
		}
		if (timers.has(el)) {
			window.clearInterval(timers.get(el));
		}
		var rawMs = el.getAttribute('data-g2ab-countdown-ms');
		var rawSec = el.getAttribute('data-g2ab-countdown');
		var target = rawMs ? parseInt(rawMs, 10) : parseInt(rawSec || '0', 10) * 1000;
		if (!target || Number.isNaN(target)) {
			el.hidden = true;
			return;
		}

		function tick() {
			var diff = target - Date.now();
			if (diff <= 0) {
				diff = 0;
				var label = el.querySelector('[data-g2ab-countdown-label]');
				if (label) {
					label.textContent = config.startedLabel || 'EVENT STARTED';
				}
				if (timers.has(el)) {
					window.clearInterval(timers.get(el));
					timers.delete(el);
				}
			}
			setText(el, '[data-d]', pad(Math.floor(diff / 86400000)));
			setText(el, '[data-h]', pad(Math.floor((diff % 86400000) / 3600000)));
			setText(el, '[data-m]', pad(Math.floor((diff % 3600000) / 60000)));
			setText(el, '[data-s]', pad(Math.floor((diff % 60000) / 1000)));
		}

		tick();
		if (target > Date.now()) {
			timers.set(el, window.setInterval(tick, 1000));
		}
	}

	function parseOccurrence(data, occurrenceId) {
		var list = data && data.success && data.data ? data.data.occurrences || [] : [];
		for (var i = 0; i < list.length; i++) {
			if (parseInt(list[i].id, 10) === occurrenceId) {
				return list[i];
			}
		}
		return null;
	}

	function updateSeatText(root, occ) {
		var left = Math.max(0, parseInt(occ.seats_left, 10) || 0);
		var total = Math.max(0, parseInt(occ.seats_total, 10) || 0);
		var reserved = Math.max(0, parseInt(occ.seats_reserved, 10) || (total - left));
		var pct = total > 0 ? Math.min(100, Math.round((reserved / total) * 100)) : 0;
		var isOut = !!occ.sold_out || left <= 0;

		root.querySelectorAll('[data-g2ab-seats-left]').forEach(function (el) {
			el.textContent = String(left);
		});
		root.querySelectorAll('[data-g2ab-seats-total]').forEach(function (el) {
			el.textContent = String(total);
		});
		root.querySelectorAll('[data-g2ab-seats-reserved]').forEach(function (el) {
			el.textContent = String(reserved);
		});
		root.querySelectorAll('[data-g2ab-seats-fill]').forEach(function (el) {
			el.style.width = pct + '%';
		});
		root.querySelectorAll('[data-g2ab-seats-left-text]').forEach(function (el) {
			el.classList.toggle('is-out', isOut);
			el.classList.toggle('is-green', !isOut);
			if (isOut) {
				el.textContent = config.soldOutLabel || 'Sold out';
			} else if (!el.querySelector('[data-g2ab-seats-left]')) {
				el.textContent = '';
				var count = document.createElement('span');
				count.setAttribute('data-g2ab-seats-left', '');
				count.textContent = String(left);
				el.appendChild(count);
				el.appendChild(document.createTextNode(' ' + (config.leftLabel || 'left')));
			}
		});
		root.classList.toggle('is-sold-out', isOut);
		root.querySelectorAll('.g2ab-banr__cta,.g2ab-strip__cta,.g2ab-ticket__cta,[data-g2ab-book-cta]').forEach(function (cta) {
			cta.classList.toggle('is-disabled', isOut);
			if (isOut) {
				cta.setAttribute('aria-disabled', 'true');
			} else {
				cta.removeAttribute('aria-disabled');
			}
		});
	}

	function refreshBanner(root) {
		var eventId = parseInt(root.getAttribute('data-g2ab-event-id'), 10);
		var occurrenceId = parseInt(root.getAttribute('data-g2ab-occurrence-id'), 10);
		if (!eventId || !occurrenceId || !config.restUrl || root.dataset.g2abAvailabilityLoading === '1') {
			return;
		}
		root.dataset.g2abAvailabilityLoading = '1';
		window.fetch(config.restUrl + 'events/' + eventId + '/occurrences?_=' + Date.now(), {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'Cache-Control': 'no-store' },
			cache: 'no-store'
		}).then(function (res) {
			return res.json();
		}).then(function (json) {
			var occ = parseOccurrence(json, occurrenceId);
			if (occ) {
				updateSeatText(root, occ);
			}
		}).catch(function () {
		}).finally(function () {
			delete root.dataset.g2abAvailabilityLoading;
		});
	}

	function initAll(scope) {
		var root = scope || document;
		root.querySelectorAll('[data-g2ab-countdown],[data-g2ab-countdown-ms]').forEach(initCountdown);
		root.querySelectorAll('.g2ab-banr[data-g2ab-event-id][data-g2ab-occurrence-id]').forEach(refreshBanner);
	}

	window.G2ABEventBanner = {
		initAll: initAll,
		refreshBanner: refreshBanner
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { initAll(document); });
	} else {
		initAll(document);
	}
	document.addEventListener('g2ab:content-ready', function (event) {
		initAll(event.detail && event.detail.root ? event.detail.root : document);
	});
})(window, document);
