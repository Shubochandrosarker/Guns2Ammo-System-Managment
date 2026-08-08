/**
 * G2A Booking Engine — payment return handler.
 *
 * The booking flow itself is implemented as a per-instance inline IIFE inside
 * `class-frontend.php` (so page-builders and caching can't break the
 * binding). This external file is loaded site-wide on pages that get
 * `?g2ab_paid=…`, `?g2ab_cancel=…`, or `?g2ab_pay=…` query params and
 * verifies / displays the result of an off-site payment redirect.
 *
 * State rules (mirrors the server's checkout policy):
 *   - "Booking confirmed" is shown ONLY after /status polling (or the
 *     confirm-payment fallback) reports a verified paid/confirmed booking.
 *   - An unverified ?g2ab_paid return shows "We're confirming your payment…"
 *     and keeps polling; if verification never lands it shows an explicit
 *     failure state with a "Try payment again" action (resume-payment).
 *   - A ?g2ab_cancel return is a clear "not reserved" banner, with resume
 *     offered when the confirm token survived in sessionStorage.
 *
 * Vanilla JS, no dependencies. All notice content is built with
 * textContent (never innerHTML) so URL-derived strings can't inject markup.
 */
(function () {
	if (typeof window === 'undefined' || !window.G2AB_DATA) return;

	var D = window.G2AB_DATA;
	var I = D.i18n || {};

	function t(key, fallback) {
		return I[key] || fallback;
	}

	function qs(key) {
		var match = new RegExp('[?&]' + key + '=([^&]*)').exec(window.location.search);
		return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
	}

	function cleanUrl() {
		if (window.history && window.history.replaceState) {
			var url = window.location.pathname + (window.location.hash || '');
			window.history.replaceState({}, document.title, url);
		}
	}

	// The inline booking bootstraps store uuid → confirm_token here right
	// before redirecting to the gateway, because the cancel return URL does
	// not carry the token.
	function payCtx(uuid) {
		try {
			var raw = window.sessionStorage.getItem('g2abPayCtx');
			var map = raw ? JSON.parse(raw) : {};
			return map[uuid] || null;
		} catch (e) {
			return null;
		}
	}

	function fetchJson(url, init) {
		return fetch(url, init).then(function (r) {
			return r.json().then(
				function (j) { return { ok: r.ok, status: r.status, body: j }; },
				function () { return { ok: r.ok, status: r.status, body: null }; }
			);
		});
	}

	/**
	 * Render the floating return notice. DOM-built (textContent only).
	 *
	 * @param {Object} opts kind ('success'|'error'|'info'), title, body,
	 *                      code (confirmation code), actions ([{label,onClick}]),
	 *                      sticky (no auto-hide), noClose (hide dismiss button).
	 */
	function showNotice(opts) {
		var existing = document.getElementById('g2ab-return-notice');
		if (existing) existing.remove();

		var kind = opts.kind || 'info';
		var box = document.createElement('div');
		box.id = 'g2ab-return-notice';
		box.className = 'g2ab-return-notice g2ab-return-notice--' + kind;
		box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		box.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');

		var title = document.createElement('strong');
		title.className = 'g2ab-return-notice__title';
		title.textContent = opts.title || '';
		box.appendChild(title);

		if (opts.body) {
			var body = document.createElement('p');
			body.className = 'g2ab-return-notice__body';
			body.textContent = opts.body;
			box.appendChild(body);
		}

		if (opts.code) {
			var codeLine = document.createElement('p');
			codeLine.className = 'g2ab-return-notice__code';
			codeLine.appendChild(document.createTextNode(t('confirmation', 'Confirmation:') + ' '));
			var code = document.createElement('code');
			code.textContent = opts.code;
			codeLine.appendChild(code);
			box.appendChild(codeLine);
		}

		if (opts.actions && opts.actions.length) {
			var row = document.createElement('div');
			row.className = 'g2ab-return-notice__actions';
			opts.actions.forEach(function (action) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'g2ab-return-notice__btn';
				btn.textContent = action.label;
				btn.addEventListener('click', action.onClick);
				row.appendChild(btn);
			});
			box.appendChild(row);
		}

		if (!opts.noClose) {
			var close = document.createElement('button');
			close.type = 'button';
			close.className = 'g2ab-return-notice__close';
			close.setAttribute('aria-label', t('dismiss', 'Dismiss'));
			close.textContent = '×';
			close.addEventListener('click', function () { box.remove(); });
			box.appendChild(close);
		}

		document.body.appendChild(box);

		if (!opts.sticky) {
			window.setTimeout(function () {
				box.style.opacity = '0';
				window.setTimeout(function () { box.remove(); }, 500);
			}, 8000);
		}
		return box;
	}

	/**
	 * GET resume-payment and follow the fresh checkout URL. Handles the
	 * "inventory is gone" family of errors with released-slot messaging.
	 */
	function resumePayment(uuid, token) {
		showNotice({ kind: 'info', sticky: true, noClose: true, title: t('resuming', 'Preparing secure checkout…') });
		fetchJson(
			D.rest_url + 'bookings/' + encodeURIComponent(uuid) + '/resume-payment?confirm_token=' + encodeURIComponent(token),
			{ headers: { 'X-WP-Nonce': D.nonce || '' } }
		)
			.then(function (res) {
				var body = res.body || {};
				var data = body.data || {};
				if (res.ok && body.success && data.redirect_url) {
					window.location.href = data.redirect_url;
					return;
				}
				if (res.ok && body.success && data.payment_required === false) {
					// Nothing left to pay — this booking is already settled.
					showNotice({
						kind: 'success', sticky: true,
						title: t('confirmed_title', 'Booking confirmed.'),
						body: t('already_paid', "This booking is already paid — you're all set."),
						code: uuid
					});
					return;
				}
				var code = body.code || '';
				if (code === 'g2ab_slot_taken' || code === 'g2ab_seats_taken' || code === 'g2ab_slot_past' || code === 'g2ab_not_resumable') {
					showNotice({
						kind: 'error', sticky: true,
						title: t('slot_released_title', 'That time is no longer available.'),
						body: t('slot_released_body', 'Your hold was released and the slot has since been taken or expired. Please pick a new time and book again.'),
						actions: [{
							label: t('pick_new_time', 'Pick a new time'),
							onClick: function () { window.location.href = window.location.pathname; }
						}]
					});
					return;
				}
				showNotice({
					kind: 'error', sticky: true,
					title: t('resume_failed', 'We could not restart checkout.'),
					body: (body && body.message) || t('try_later', 'Please try again in a moment.'),
					actions: [{
						label: t('try_again', 'Try payment again'),
						onClick: function () { resumePayment(uuid, token); }
					}]
				});
			})
			.catch(function () {
				showNotice({
					kind: 'error', sticky: true,
					title: t('resume_failed', 'We could not restart checkout.'),
					body: t('try_later', 'Please try again in a moment.'),
					actions: [{
						label: t('try_again', 'Try payment again'),
						onClick: function () { resumePayment(uuid, token); }
					}]
				});
			});
	}

	/**
	 * Poll /status (and the confirm-payment gateway fallback) until the
	 * booking is verified paid/confirmed — or give up with an explicit,
	 * recoverable failure state. Never claims "confirmed" on hope alone.
	 */
	function verifyPayment(uuid, token, sessionId) {
		var attempts = 0;
		var maxAttempts = 6;
		var settled = false;

		showNotice({
			kind: 'info', sticky: true, noClose: true,
			title: t('confirming', "We're confirming your payment…"),
			body: t('confirming_body', 'Hold tight — this usually only takes a few seconds.')
		});

		function succeed() {
			if (settled) return;
			settled = true;
			cleanUrl();
			showNotice({
				kind: 'success', sticky: true,
				title: t('confirmed_title', 'Booking confirmed.'),
				body: t('confirmed_body', 'Your payment was received. A confirmation email is on its way.'),
				code: uuid
			});
		}

		function fail() {
			if (settled) return;
			settled = true;
			cleanUrl();
			var actions = [];
			if (token) {
				actions.push({
					label: t('try_again', 'Try payment again'),
					onClick: function () { resumePayment(uuid, token); }
				});
			}
			showNotice({
				kind: 'error', sticky: true,
				title: t('not_verified_title', "We couldn't confirm your payment yet."),
				body: t('not_verified_body', "Your reservation is not confirmed until payment is verified. If you completed checkout you'll get an email as soon as it settles — otherwise you can try again below."),
				actions: actions
			});
		}

		function statusUrl() {
			var url = D.rest_url + 'bookings/' + encodeURIComponent(uuid) + '/status';
			if (token) url += '?confirm_token=' + encodeURIComponent(token);
			return url;
		}

		function tick() {
			attempts++;
			fetchJson(statusUrl(), { headers: { 'X-WP-Nonce': D.nonce || '' } })
				.then(function (res) {
					var data = res.body && res.body.data ? res.body.data : null;
					var status = data && data.status ? String(data.status) : '';
					if (status === 'paid' || status === 'confirmed' || status === 'completed') {
						succeed();
						return null;
					}
					if (status === 'cancelled' || status === 'refunded' || status === 'failed') {
						fail();
						return null;
					}
					// Not settled yet — ask the server to verify against the
					// gateway directly (requires the session id from the return
					// URL; the confirm token gates any state change).
					if (sessionId) {
						return fetchJson(D.rest_url + 'bookings/' + encodeURIComponent(uuid) + '/confirm-payment', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce || '' },
							body: JSON.stringify({ session_id: sessionId, confirm_token: token || '' })
						});
					}
					return { ok: true, status: 200, body: null };
				})
				.then(function (res) {
					if (settled || res === null) return;
					var data = res.body && res.body.data ? res.body.data : null;
					var verified = data && data.verified === true
						&& (data.status === 'paid' || data.status === 'confirmed' || data.status === 'completed');
					if (verified) {
						succeed();
						return;
					}
					if (attempts >= maxAttempts) {
						fail();
						return;
					}
					window.setTimeout(tick, 3000);
				})
				.catch(function () {
					if (settled) return;
					if (attempts >= maxAttempts) {
						fail();
						return;
					}
					window.setTimeout(tick, 3000);
				});
		}

		tick();
	}

	function handlePaid() {
		var uuid = qs('g2ab_paid');
		if (!uuid || !/^[a-f0-9-]{36}$/.test(uuid)) return;
		var token = qs('g2ab_token');
		if (!token) {
			var ctx = payCtx(uuid);
			if (ctx && ctx.t) token = ctx.t;
		}
		verifyPayment(uuid, token, qs('session_id'));
	}

	function handleCancel() {
		var uuid = qs('g2ab_cancel');
		if (!uuid || !/^[a-f0-9-]{36}$/.test(uuid)) return;
		cleanUrl();
		var ctx = payCtx(uuid);
		var token = ctx && ctx.t ? ctx.t : '';
		var actions = [];
		if (token) {
			actions.push({
				label: t('try_again', 'Try payment again'),
				onClick: function () { resumePayment(uuid, token); }
			});
		}
		showNotice({
			kind: 'error', sticky: true,
			title: t('cancelled_title', 'Payment was cancelled — your lane is not reserved.'),
			body: t('cancelled_body', 'No charge was made. Any held time or seats will be released shortly unless you complete payment.'),
			actions: actions
		});
	}

	// Best-effort "Pay Now" deep link (?g2ab_pay={uuid}, e.g. from an
	// invoice). resume-payment requires the confirm token, so this can only
	// work in the browser that started the checkout (sessionStorage kept it).
	function handlePayLink() {
		var uuid = qs('g2ab_pay');
		if (!uuid || !/^[a-f0-9-]{36}$/.test(uuid)) return;
		var ctx = payCtx(uuid);
		if (!ctx || !ctx.t) return; // no token — nothing safe we can do client-side.
		cleanUrl();
		resumePayment(uuid, ctx.t);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { handlePaid(); handleCancel(); handlePayLink(); });
	} else {
		handlePaid();
		handleCancel();
		handlePayLink();
	}
})();
