/**
 * Guns 2 Ammo — live offer countdown.
 *
 * Drives every `[data-g2a-countdown]` block. Each digit is a two-layer cell
 * (current + incoming); when a digit changes, the pair rolls — the old value
 * slides up and out while the new one slides up into its place — so only the
 * digits that actually moved animate. Seconds roll once a second, minutes
 * once a minute, and the day cell sits perfectly still, which is what makes
 * it read as a clock rather than a flickering number.
 *
 * The deadline arrives as an absolute UNIX timestamp in `data-ends`, so a
 * page served from cache still counts down to the right moment.
 *
 * Notes:
 * - Ticks are aligned to the wall clock, not to a fixed 1000ms interval, so
 *   the display cannot drift or skip a second on a busy main thread.
 * - Ticking pauses while the tab is hidden and resyncs on return; a
 *   background tab throttled to once a minute would otherwise animate a
 *   burst of stale digits when it wakes.
 * - `prefers-reduced-motion` swaps digits instantly. The CSS handles the
 *   visual side; this file simply skips the roll bookkeeping.
 *
 * No dependencies — this file must stay framework-free.
 */
(function () {
	'use strict';

	var SECOND = 1000;
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function pad(value) {
		return value < 10 ? '0' + value : String(value);
	}

	/**
	 * Break a remaining-milliseconds figure into padded display units.
	 * Days are not padded to a fixed width — a 3-digit countdown should show
	 * three digits rather than silently truncate.
	 */
	function split(remaining) {
		var total = Math.max(0, Math.floor(remaining / SECOND));
		var days = Math.floor(total / 86400);
		var hours = Math.floor((total % 86400) / 3600);
		var minutes = Math.floor((total % 3600) / 60);
		var seconds = total % 60;

		return {
			days: days < 100 ? pad(days) : String(days),
			hours: pad(hours),
			minutes: pad(minutes),
			seconds: pad(seconds)
		};
	}

	function initCountdown(root) {
		var ends = parseInt(root.getAttribute('data-ends'), 10);
		if (!ends || isNaN(ends)) {
			return;
		}

		var deadline = ends * SECOND;
		var cells = {};
		var timer = null;

		Array.prototype.forEach.call(root.querySelectorAll('[data-unit]'), function (node) {
			cells[node.getAttribute('data-unit')] = node;
		});

		/** Repaint one unit, growing the digit count if the value needs it. */
		function paint(unit, text) {
			var node = cells[unit];
			if (!node) {
				return;
			}

			var digits = node.querySelectorAll('.g2a-count__digit');

			// A countdown that crosses into three-digit days needs another cell.
			while (digits.length < text.length) {
				node.insertAdjacentHTML(
					'afterbegin',
					'<span class="g2a-count__digit"><span class="g2a-count__cur">0</span><span class="g2a-count__next" aria-hidden="true">0</span></span>'
				);
				digits = node.querySelectorAll('.g2a-count__digit');
			}

			for (var i = 0; i < digits.length; i++) {
				var cell = digits[i];
				var cur = cell.querySelector('.g2a-count__cur');
				var next = cell.querySelector('.g2a-count__next');
				var value = text.charAt(i) || '0';

				if (!cur || !next || cur.textContent === value) {
					continue;
				}

				if (reduceMotion) {
					cur.textContent = value;
					next.textContent = value;
					continue;
				}

				next.textContent = value;
				// Restart the animation even if this cell is mid-roll: drop the
				// class, force a reflow, then re-add. Without the reflow the
				// browser coalesces both writes and the roll never replays.
				cell.classList.remove('is-rolling');
				void cell.offsetWidth;
				cell.classList.add('is-rolling');

				(function (cellRef, curRef, value) {
					var settle = function () {
						curRef.textContent = value;
						cellRef.classList.remove('is-rolling');
						cellRef.removeEventListener('animationend', settle);
					};
					cellRef.addEventListener('animationend', settle);
					// Belt and braces: if the animation never fires (cell hidden,
					// tab backgrounded mid-roll) the digit must still land.
					window.setTimeout(settle, 700);
				})(cell, cur, value);
			}
		}

		function finish() {
			window.clearTimeout(timer);
			root.classList.add('is-ended');
			root.classList.remove('is-urgent');
		}

		function tick() {
			var remaining = deadline - Date.now();

			if (remaining <= 0) {
				paint('days', '00');
				paint('hours', '00');
				paint('minutes', '00');
				paint('seconds', '00');
				finish();
				return;
			}

			var parts = split(remaining);
			paint('days', parts.days);
			paint('hours', parts.hours);
			paint('minutes', parts.minutes);
			paint('seconds', parts.seconds);

			// Under an hour the banner starts pressing — CSS picks this up.
			root.classList.toggle('is-urgent', remaining < 3600 * SECOND);

			// Land on the next whole second rather than drifting by however
			// long this frame took.
			timer = window.setTimeout(tick, SECOND - (Date.now() % SECOND));
		}

		function start() {
			window.clearTimeout(timer);
			tick();
		}

		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				window.clearTimeout(timer);
			} else {
				start();
			}
		});

		root.classList.add('is-live');
		start();
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-g2a-countdown]'), initCountdown);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
