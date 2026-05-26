<?php
/**
 * G2AB_Frontdesk_View — shared HTML / JS / CSS for the Front Desk UI.
 *
 * Used by both the admin Front Desk page and the `[g2ab_frontdesk]`
 * shortcode so a desk terminal mounted on the public site behaves
 * identically to the wp-admin version.
 *
 * @package G2AB\Services
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontdesk_View {

	/**
	 * Print the desk markup. Call after enqueue() so the JS handle exists.
	 *
	 * @param string $context 'admin' or 'frontend' — affects styling shell only.
	 */
	public static function markup( $context = 'admin' ) {
		$today = current_time( 'Y-m-d' );
		?>
		<div class="g2ab-fd <?php echo esc_attr( 'g2ab-fd--' . $context ); ?>" data-context="<?php echo esc_attr( $context ); ?>">
			<header class="g2ab-fd__bar">
				<div class="g2ab-fd__date">
					<label><?php esc_html_e( 'Date', 'g2a-booking' ); ?>
						<input type="date" id="g2ab-fd-date" value="<?php echo esc_attr( $today ); ?>" />
					</label>
				</div>
				<div class="g2ab-fd__search">
					<label><?php esc_html_e( 'Search bookings', 'g2a-booking' ); ?>
						<input type="search" id="g2ab-fd-search" placeholder="<?php esc_attr_e( 'Name, email, phone, or UUID', 'g2a-booking' ); ?>" />
					</label>
				</div>
				<div class="g2ab-fd__totals" id="g2ab-fd-totals"></div>
			</header>

			<div class="g2ab-fd__msg" id="g2ab-fd-msg" role="status" aria-live="polite"></div>

			<div class="g2ab-fd__roster" id="g2ab-fd-roster"></div>

			<!-- per-booking action template -->
			<template id="g2ab-fd-row-tpl">
				<article class="g2ab-fd-row" data-id="">
					<div class="g2ab-fd-row__head">
						<span class="g2ab-fd-row__time"></span>
						<span class="g2ab-fd-row__status"></span>
						<span class="g2ab-fd-row__pay"></span>
					</div>
					<div class="g2ab-fd-row__body">
						<div class="g2ab-fd-row__who">
							<strong class="g2ab-fd-row__name"></strong>
							<span class="g2ab-fd-row__contact"></span>
						</div>
						<div class="g2ab-fd-row__what">
							<span class="g2ab-fd-row__type"></span>
							<span class="g2ab-fd-row__resource"></span>
							<span class="g2ab-fd-row__party"></span>
						</div>
						<div class="g2ab-fd-row__money">
							<span class="g2ab-fd-row__total"></span>
							<span class="g2ab-fd-row__balance"></span>
						</div>
					</div>
					<div class="g2ab-fd-row__actions">
						<button type="button" class="g2ab-fd-act g2ab-fd-act--checkin"      data-action="checkin"><?php esc_html_e( 'Check in', 'g2a-booking' ); ?></button>
						<button type="button" class="g2ab-fd-act g2ab-fd-act--waiver"       data-action="verify-waiver"><?php esc_html_e( 'Verify waiver', 'g2a-booking' ); ?></button>
						<button type="button" class="g2ab-fd-act g2ab-fd-act--pay"          data-action="collect-payment"><?php esc_html_e( 'Collect payment', 'g2a-booking' ); ?></button>
						<button type="button" class="g2ab-fd-act g2ab-fd-act--note"         data-action="note"><?php esc_html_e( 'Add note', 'g2a-booking' ); ?></button>
						<button type="button" class="g2ab-fd-act g2ab-fd-act--noshow"       data-action="no-show"><?php esc_html_e( 'No-show', 'g2a-booking' ); ?></button>
						<button type="button" class="g2ab-fd-act g2ab-fd-act--receipt"      data-action="receipt"><?php esc_html_e( 'Print receipt', 'g2a-booking' ); ?></button>
					</div>
					<div class="g2ab-fd-row__notes"></div>
				</article>
			</template>
		</div>
		<?php
	}

	/**
	 * Register and enqueue the inline JS + CSS.
	 */
	public static function enqueue() {
		wp_register_style( 'g2ab-frontdesk', false, array(), G2AB_VERSION );
		wp_enqueue_style( 'g2ab-frontdesk' );
		wp_add_inline_style( 'g2ab-frontdesk', self::css() );

		wp_register_script( 'g2ab-frontdesk', '', array(), G2AB_VERSION, true );
		wp_enqueue_script( 'g2ab-frontdesk' );
		wp_add_inline_script( 'g2ab-frontdesk', self::js() );
	}

	private static function css() {
		return '
		.g2ab-fd{margin-top:18px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#172033;}
		.g2ab-fd--frontend{max-width:1180px;margin:30px auto;}
		.g2ab-fd__bar{display:grid;grid-template-columns:180px 1fr auto;gap:14px;align-items:end;background:#fff;border:1px solid #d9e2ef;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
		.g2ab-fd__bar label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#526078;}
		.g2ab-fd__bar input{display:block;margin-top:4px;padding:8px 10px;border:1px solid #c8d1e0;border-radius:6px;width:100%;font-size:15px;box-sizing:border-box;}
		.g2ab-fd__totals{display:flex;gap:14px;font-size:12px;color:#0f2044;}
		.g2ab-fd__totals span{background:#f0f3f9;padding:6px 12px;border-radius:999px;font-weight:600;}
		.g2ab-fd__totals span.checked{background:#dcfce7;color:#166534;}
		.g2ab-fd__totals span.no_show{background:#fee2e2;color:#991b1b;}
		.g2ab-fd__msg{display:none;padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:14px;}
		.g2ab-fd__msg.is-success{display:block;background:#d1fae5;color:#065f46;}
		.g2ab-fd__msg.is-error{display:block;background:#fee2e2;color:#7f1d1d;}
		.g2ab-fd__roster{display:grid;gap:10px;}
		.g2ab-fd-row{background:#fff;border:1px solid #d9e2ef;border-left:5px solid #9CA3AF;border-radius:10px;padding:14px 18px;display:grid;gap:8px;transition:box-shadow .15s ease;}
		.g2ab-fd-row:hover{box-shadow:0 6px 18px rgba(15,32,68,.08);}
		.g2ab-fd-row.is-checked-in{background:#f0fdf4;border-left-color:#16a34a;}
		.g2ab-fd-row.is-noshow{background:#fef2f2;border-left-color:#dc2626;opacity:.85;}
		.g2ab-fd-row.is-cancelled{opacity:.55;}
		.g2ab-fd-row__head{display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
		.g2ab-fd-row__time{font-weight:700;font-size:18px;color:#0f2044;}
		.g2ab-fd-row__status{display:inline-block;padding:3px 10px;border-radius:999px;color:#fff;font-size:11px;letter-spacing:.05em;text-transform:uppercase;}
		.g2ab-fd-row__pay{display:inline-block;padding:3px 10px;border-radius:6px;border:1px solid currentColor;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
		.g2ab-fd-row__pay.paid{color:#10B981;}
		.g2ab-fd-row__pay.partial{color:#F59E0B;}
		.g2ab-fd-row__pay.unpaid{color:#C62828;}
		.g2ab-fd-row__pay.no_charge{color:#6B7280;}
		.g2ab-fd-row__body{display:grid;grid-template-columns:2fr 2fr 1fr;gap:14px;font-size:14px;}
		.g2ab-fd-row__who strong{display:block;font-size:15px;}
		.g2ab-fd-row__contact{color:#526078;font-size:13px;}
		.g2ab-fd-row__what{color:#526078;font-size:13px;display:flex;flex-direction:column;}
		.g2ab-fd-row__money{text-align:right;}
		.g2ab-fd-row__total{display:block;color:#526078;font-size:13px;}
		.g2ab-fd-row__balance{display:block;font-size:18px;font-weight:700;color:#0f2044;}
		.g2ab-fd-row__balance.is-zero{color:#16a34a;}
		.g2ab-fd-row__actions{display:flex;flex-wrap:wrap;gap:6px;}
		.g2ab-fd-act{padding:7px 12px;font-size:12px;font-weight:600;background:#fff;color:#0f2044;border:1px solid #c8d1e0;border-radius:6px;cursor:pointer;transition:background .12s ease,color .12s ease;}
		.g2ab-fd-act:hover{background:#0f2044;color:#fff;border-color:#0f2044;}
		.g2ab-fd-act--checkin{background:#0f2044;color:#fff;border-color:#0f2044;}
		.g2ab-fd-act--checkin:hover{background:#1a3a7a;border-color:#1a3a7a;}
		.g2ab-fd-act--noshow:hover{background:#dc2626;border-color:#dc2626;color:#fff;}
		.g2ab-fd-act:disabled{opacity:.4;cursor:not-allowed;background:#fff;color:#0f2044;border-color:#c8d1e0;}
		.g2ab-fd-row__notes{font-size:12px;color:#526078;white-space:pre-wrap;background:#f7f8fb;padding:8px 10px;border-radius:6px;display:none;}
		.g2ab-fd-row__notes.has-notes{display:block;}
		.g2ab-fd__empty{padding:40px;text-align:center;color:#526078;background:#fff;border:1px dashed #d9e2ef;border-radius:10px;}
		';
	}

	private static function js() {
		$rest_root = esc_url_raw( rest_url( G2AB_REST_NAMESPACE ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		ob_start();
		?>
		(function () {
			var REST  = <?php echo wp_json_encode( $rest_root ); ?>;
			var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			var DEBOUNCE_MS = 250;
			var rosterEl, dateEl, searchEl, totalsEl, msgEl, tpl, mode = 'today';
			var debounceTimer = null;

			function api(method, path, body) {
				var opts = {
					method: method,
					headers: { 'X-WP-Nonce': NONCE, 'Accept': 'application/json' },
					credentials: 'same-origin'
				};
				if (body) {
					opts.headers['Content-Type'] = 'application/json';
					opts.body = JSON.stringify(body);
				}
				return fetch(REST + path, opts).then(function (r) {
					return r.json().then(function (j) { return { ok: r.ok, body: j }; });
				});
			}

			function showMsg(text, type) {
				msgEl.textContent = text;
				msgEl.className = 'g2ab-fd__msg is-' + (type || 'success');
				setTimeout(function () { msgEl.className = 'g2ab-fd__msg'; }, 4000);
			}

			function fmtMoney(n) { return '$' + Number(n || 0).toFixed(2); }

			function fmtTime(sql) {
				if (!sql) return '';
				var d = new Date(sql.replace(' ', 'T'));
				if (isNaN(d.getTime())) return sql;
				return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
			}

			function payClass(b) {
				if (b.total_amount <= 0) return 'no_charge';
				if (b.paid_amount >= b.total_amount) return 'paid';
				if (b.paid_amount > 0) return 'partial';
				return 'unpaid';
			}

			function load() {
				var q = (searchEl.value || '').trim();
				if (q.length >= 2) {
					mode = 'search';
					api('GET', '/frontdesk/search?q=' + encodeURIComponent(q) + '&limit=50')
						.then(function (res) {
							if (res.ok && res.body && res.body.success) {
								renderRoster(res.body.data, null);
							} else {
								showMsg(extractError(res.body) || 'Search failed.', 'error');
							}
						});
				} else {
					mode = 'today';
					api('GET', '/frontdesk/today?date=' + encodeURIComponent(dateEl.value))
						.then(function (res) {
							if (res.ok && res.body && res.body.success) {
								renderRoster(res.body.data.bookings, res.body.data.totals);
							} else {
								showMsg(extractError(res.body) || 'Could not load today.', 'error');
							}
						});
				}
			}

			function renderRoster(rows, totals) {
				rosterEl.innerHTML = '';
				if (totalsEl && totals) {
					totalsEl.innerHTML =
						'<span>' + totals.total + ' booked</span>'
						+ '<span class="checked">' + totals.checked_in + ' checked in</span>'
						+ '<span>' + totals.pending + ' pending</span>'
						+ (totals.no_show ? '<span class="no_show">' + totals.no_show + ' no-show</span>' : '');
				} else if (totalsEl) {
					totalsEl.innerHTML = '';
				}
				if (!rows || !rows.length) {
					rosterEl.innerHTML = '<div class="g2ab-fd__empty">' + (mode === 'search' ? 'No matches.' : 'Nothing on the books for this date.') + '</div>';
					return;
				}
				rows.forEach(function (b) { rosterEl.appendChild(buildRow(b)); });
			}

			function buildRow(b) {
				var node = tpl.content.firstElementChild.cloneNode(true);
				node.dataset.id = b.id;
				if (b.is_checked_in)             node.classList.add('is-checked-in');
				if (b.status === 'no_show')      node.classList.add('is-noshow');
				if (b.status === 'cancelled' || b.status === 'expired') node.classList.add('is-cancelled');

				node.querySelector('.g2ab-fd-row__time').textContent  = fmtTime(b.start_at);
				var stEl = node.querySelector('.g2ab-fd-row__status');
				stEl.textContent     = b.status_label || b.status;
				stEl.style.background = b.status_color || '#9CA3AF';
				var pay = payClass(b);
				var payEl = node.querySelector('.g2ab-fd-row__pay');
				payEl.textContent = pay.replace('_',' ');
				payEl.classList.add(pay);

				node.querySelector('.g2ab-fd-row__name').textContent     = b.customer_name || 'Guest';
				node.querySelector('.g2ab-fd-row__contact').textContent  = [b.customer_phone, b.customer_email].filter(Boolean).join(' • ');
				node.querySelector('.g2ab-fd-row__type').textContent     = (b.booking_type_name || '') + (b.category ? ' (' + b.category + ')' : '');
				node.querySelector('.g2ab-fd-row__resource').textContent = b.resource_name + (b.resource_type ? ' — ' + b.resource_type : '');
				node.querySelector('.g2ab-fd-row__party').textContent    = 'Party of ' + b.party_size;
				node.querySelector('.g2ab-fd-row__total').textContent    = fmtMoney(b.total_amount) + ' total / ' + fmtMoney(b.paid_amount) + ' paid';
				var balEl = node.querySelector('.g2ab-fd-row__balance');
				balEl.textContent = b.balance > 0 ? 'Balance ' + fmtMoney(b.balance) : 'Settled';
				if (b.balance <= 0) balEl.classList.add('is-zero');

				if (b.notes) {
					var n = node.querySelector('.g2ab-fd-row__notes');
					n.textContent = b.notes;
					n.classList.add('has-notes');
				}

				// Disable buttons that no longer apply.
				var terminal = ['cancelled','expired','refunded','no_show'];
				if (terminal.indexOf(b.status) !== -1) {
					node.querySelectorAll('.g2ab-fd-act').forEach(function (btn) {
						if (btn.dataset.action !== 'receipt' && btn.dataset.action !== 'note') btn.disabled = true;
					});
				}
				if (b.is_checked_in) {
					var cInBtn = node.querySelector('.g2ab-fd-act--checkin');
					if (cInBtn) { cInBtn.textContent = 'Checked in'; cInBtn.disabled = true; }
				}
				if (b.waiver_verified) {
					var wBtn = node.querySelector('.g2ab-fd-act--waiver');
					if (wBtn) { wBtn.textContent = 'Waiver OK'; wBtn.disabled = true; }
				}
				if (b.balance <= 0) {
					var pBtn = node.querySelector('.g2ab-fd-act--pay');
					if (pBtn) pBtn.disabled = true;
				}

				node.querySelectorAll('.g2ab-fd-act').forEach(function (btn) {
					btn.addEventListener('click', function () { handleAction(btn.dataset.action, b, node); });
				});
				return node;
			}

			function handleAction(action, b, node) {
				if (action === 'checkin') {
					api('POST', '/frontdesk/checkin', { booking_id: b.id })
						.then(handleSimple('Checked in.'));
				} else if (action === 'verify-waiver') {
					api('POST', '/frontdesk/verify-waiver', { booking_id: b.id })
						.then(handleSimple('Waiver verified.'));
				} else if (action === 'collect-payment') {
					var amount = window.prompt('Amount to collect (USD):', b.balance.toFixed(2));
					if (amount === null) return;
					var method = window.prompt('Payment method (cash, card_terminal, admin_comp, other):', 'cash');
					if (method === null) return;
					api('POST', '/frontdesk/collect-payment', { booking_id: b.id, amount: parseFloat(amount), method: method })
						.then(handleSimple('Payment recorded.'));
				} else if (action === 'note') {
					var note = window.prompt('Staff note:', '');
					if (!note) return;
					api('POST', '/frontdesk/note', { booking_id: b.id, note: note })
						.then(handleSimple('Note added.'));
				} else if (action === 'no-show') {
					if (!window.confirm('Mark this booking as no-show?')) return;
					api('POST', '/frontdesk/no-show', { booking_id: b.id })
						.then(handleSimple('Marked no-show.'));
				} else if (action === 'receipt') {
					var url = REST + '/frontdesk/receipt/' + b.id + '?_wpnonce=' + encodeURIComponent(NONCE);
					var w = window.open(url, '_blank');
					if (!w) showMsg('Pop-up was blocked — allow pop-ups for this site.', 'error');
				}
			}

			function handleSimple(successMsg) {
				return function (res) {
					if (res.ok && res.body && res.body.success) {
						showMsg(successMsg, 'success');
						load();
					} else {
						showMsg(extractError(res.body) || 'Action failed.', 'error');
					}
				};
			}

			function extractError(body) {
				if (!body) return null;
				return body.message || (body.data && body.data.message) || null;
			}

			function debounceLoad() {
				clearTimeout(debounceTimer);
				debounceTimer = setTimeout(load, DEBOUNCE_MS);
			}

			function init() {
				var root = document.querySelector('.g2ab-fd');
				if (!root) return;
				rosterEl = root.querySelector('#g2ab-fd-roster');
				dateEl   = root.querySelector('#g2ab-fd-date');
				searchEl = root.querySelector('#g2ab-fd-search');
				totalsEl = root.querySelector('#g2ab-fd-totals');
				msgEl    = root.querySelector('#g2ab-fd-msg');
				tpl      = root.querySelector('#g2ab-fd-row-tpl');

				dateEl.addEventListener('change', load);
				searchEl.addEventListener('input', debounceLoad);

				load();
				// Auto-refresh today\'s roster every 60s when search box is empty.
				setInterval(function () { if (mode === \'today\') load(); }, 60000);
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		<?php
		return ob_get_clean();
	}
}
