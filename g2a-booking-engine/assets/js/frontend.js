/**
 * G2A Booking Engine — payment return handler.
 *
 * The booking flow itself is implemented as a per-instance inline IIFE inside
 * `class-frontend.php` (so page-builders and caching can't break the
 * binding). This external file is loaded site-wide on pages that get
 * `?g2ab_paid=…`, `?g2ab_cancel=…`, or `?g2ab_pay=…` query params and
 * confirms / displays the result of an off-site payment redirect.
 *
 * Vanilla JS, no dependencies.
 */
(function () {
	if (typeof window === 'undefined' || !window.G2AB_DATA) return;

	function qs(key) {
		var match = new RegExp('[?&]' + key + '=([^&]*)').exec(window.location.search);
		return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
	}

	function showInlineNotice(html, kind) {
		var existing = document.getElementById('g2ab-return-notice');
		if (existing) existing.remove();
		var box = document.createElement('div');
		box.id = 'g2ab-return-notice';
		box.setAttribute('role', 'status');
		box.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:99999;max-width:480px;width:calc(100% - 32px);background:#26252C;color:#fff;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:18px 22px;box-shadow:0 20px 60px rgba(0,0,0,.4);font-family:"Inter","Segoe UI",sans-serif;font-size:14px;line-height:1.5;';
		if (kind === 'success') box.style.borderColor = '#C9A84C';
		if (kind === 'error') box.style.borderColor = '#FF5577';
		box.innerHTML = html;
		document.body.appendChild(box);
		setTimeout(function () { box.style.transition = 'opacity .4s ease'; box.style.opacity = '0'; setTimeout(function () { box.remove(); }, 500); }, 8000);
	}

	function checkBookingStatus(uuid) {
		var url = window.G2AB_DATA.rest_url + 'bookings/' + encodeURIComponent(uuid) + '/status';
		var sessionId = qs('session_id');
		var confirmToken = qs('g2ab_token');
		return fetch(url, { headers: { 'X-WP-Nonce': window.G2AB_DATA.nonce || '' } })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				var data = json && json.data ? json.data : null;
				if (data && (data.status === 'paid' || data.status === 'confirmed')) {
					return data;
				}
				if (!sessionId) return data;
				// Fallback: ask the server to confirm via gateway API. The
				// confirm_token from the return URL is required — without it
				// the endpoint refuses with 403 and the booking stays unpaid
				// until the webhook lands (if it ever does).
				return fetch(window.G2AB_DATA.rest_url + 'bookings/' + encodeURIComponent(uuid) + '/confirm-payment', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.G2AB_DATA.nonce || '' },
					body: JSON.stringify({ session_id: sessionId, confirm_token: confirmToken || '' })
				})
					.then(function (r) { return r.json(); })
					.then(function (j) { return j && j.data ? j.data : data; });
			});
	}

	function handlePaid() {
		var uuid = qs('g2ab_paid');
		if (!uuid) return;
		checkBookingStatus(uuid).then(function (data) {
			if (data && (data.status === 'paid' || data.status === 'confirmed')) {
				showInlineNotice('<strong>Booking confirmed.</strong><br>Your payment was received. Confirmation: <code style="background:rgba(255,255,255,.1);padding:2px 6px;border-radius:4px;font-size:12px;">' + uuid + '</code>', 'success');
			} else {
				showInlineNotice('<strong>Payment received.</strong><br>Your booking is finalising. You\'ll get a confirmation email shortly.', 'success');
			}
			// Clean the query params so a refresh doesn't re-trigger.
			if (window.history && window.history.replaceState) {
				var url = window.location.pathname + (window.location.hash || '');
				window.history.replaceState({}, document.title, url);
			}
		}).catch(function () {
			showInlineNotice('<strong>Payment processed.</strong><br>We could not verify status automatically. Please check your email.', 'success');
		});
	}

	function handleCancel() {
		if (!qs('g2ab_cancel')) return;
		showInlineNotice('<strong>Payment cancelled.</strong><br>Your booking was not charged. You can try again any time.', 'error');
		if (window.history && window.history.replaceState) {
			var url = window.location.pathname + (window.location.hash || '');
			window.history.replaceState({}, document.title, url);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { handlePaid(); handleCancel(); });
	} else {
		handlePaid();
		handleCancel();
	}
})();
