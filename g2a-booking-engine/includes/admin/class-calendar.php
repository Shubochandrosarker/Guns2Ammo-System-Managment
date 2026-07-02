<?php
/**
 * Admin: FullCalendar booking calendar.
 *
 * Day / week / month views, lane/category/status filters, drag-and-drop
 * reschedule, click-for-detail panel with cancel / no-show / complete /
 * reschedule actions. Talks to G2AB_REST_Calendar_Controller.
 *
 * FullCalendar is loaded from a CDN to keep the plugin footprint small.
 * Override the URL with the `g2ab_fullcalendar_cdn_url` filter if you need
 * to vendor it for an offline install.
 *
 * @package G2AB
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Calendar {

	const SLUG = 'g2ab-calendar';
	const CAP  = 'manage_g2ab_bookings';
	const FULLCALENDAR_VERSION = '6.1.15';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Calendar', 'g2a-booking' ),
			__( 'Calendar', 'g2a-booking' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		// Prefer the copy bundled with the plugin so the calendar never depends on
		// an external CDN (which a security plugin / firewall can block — the most
		// common cause of a blank calendar). Falls back to the CDN only if the
		// bundled file is somehow missing, and stays filterable for offline vendoring.
		$local = G2AB_PATH . 'assets/vendor/fullcalendar/index.global.min.js';
		$src   = file_exists( $local )
			? G2AB_URL . 'assets/vendor/fullcalendar/index.global.min.js'
			: 'https://cdn.jsdelivr.net/npm/fullcalendar@' . self::FULLCALENDAR_VERSION . '/index.global.min.js';
		$src   = apply_filters( 'g2ab_fullcalendar_cdn_url', $src );

		wp_enqueue_script(
			'g2ab-fullcalendar',
			esc_url( $src ),
			array(),
			self::FULLCALENDAR_VERSION,
			true
		);
		// Attach the init directly to the FullCalendar handle (which has a real src
		// and is therefore reliably printed). Adding the inline to a SEPARATE
		// empty-src handle did not emit the script in some setups, which left
		// FullCalendar loaded but never initialised — a permanently blank calendar.
		wp_add_inline_script( 'g2ab-fullcalendar', $this->inline_script() );

		wp_register_style( 'g2ab-calendar-admin', false, array(), G2AB_VERSION );
		wp_enqueue_style( 'g2ab-calendar-admin' );
		wp_add_inline_style( 'g2ab-calendar-admin', $this->inline_css() );
	}

	private function inline_css() {
		return '
		.g2ab-cal-shell{display:grid;grid-template-columns:240px 1fr;gap:18px;margin-top:14px;}
		.g2ab-cal-side{background:#fff;border:1px solid #d9e2ef;border-radius:8px;padding:16px;}
		.g2ab-cal-side h3{margin:0 0 8px;color:#0f2044;font-size:13px;letter-spacing:.04em;text-transform:uppercase;}
		.g2ab-cal-side select,.g2ab-cal-side input{width:100%;}
		.g2ab-cal-side fieldset{border:0;padding:0;margin:0 0 14px;}
		.g2ab-cal-main{background:#fff;border:1px solid #d9e2ef;border-radius:8px;padding:14px;min-height:640px;}
		.g2ab-cal-legend{display:flex;flex-wrap:wrap;gap:8px;font-size:11px;margin:8px 0 0;}
		.g2ab-cal-legend span{display:inline-flex;align-items:center;gap:6px;background:#f5f7fb;padding:3px 8px;border-radius:999px;}
		.g2ab-cal-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;}
		.g2ab-cal-modal-backdrop{position:fixed;inset:0;background:rgba(15,32,68,.55);display:none;align-items:center;justify-content:center;z-index:9999;}
		.g2ab-cal-modal-backdrop.is-open{display:flex;}
		.g2ab-cal-modal{background:#fff;border-radius:10px;width:min(560px,92vw);max-height:88vh;overflow:auto;box-shadow:0 30px 80px rgba(15,32,68,.25);}
		.g2ab-cal-modal-head{padding:16px 20px;border-bottom:1px solid #e6ecf5;display:flex;justify-content:space-between;align-items:center;}
		.g2ab-cal-modal-head h2{margin:0;color:#0f2044;font-size:18px;}
		.g2ab-cal-modal-body{padding:18px 20px;font-size:13px;color:#172033;}
		.g2ab-cal-modal-body table{width:100%;border-collapse:collapse;margin:8px 0 12px;}
		.g2ab-cal-modal-body th,.g2ab-cal-modal-body td{padding:6px 0;vertical-align:top;text-align:left;border-bottom:1px solid #f0f3f9;}
		.g2ab-cal-modal-body th{width:130px;color:#526078;font-weight:500;}
		.g2ab-cal-modal-actions{display:flex;flex-wrap:wrap;gap:8px;padding:12px 20px 18px;border-top:1px solid #e6ecf5;}
		.g2ab-cal-modal-actions .button{font-weight:600;}
		.g2ab-cal-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;color:#fff;letter-spacing:.04em;text-transform:uppercase;}
		.g2ab-cal-payment-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;border:1px solid currentColor;}
		.g2ab-cal-payment-badge.paid{color:#10B981;}
		.g2ab-cal-payment-badge.partial{color:#F59E0B;}
		.g2ab-cal-payment-badge.unpaid{color:#C62828;}
		.g2ab-cal-payment-badge.no_charge{color:#6B7280;}
		.g2ab-cal-msg{margin:8px 0;padding:8px 12px;border-radius:6px;font-size:13px;}
		.g2ab-cal-msg.is-error{background:#FEE2E2;color:#7F1D1D;}
		.g2ab-cal-msg.is-success{background:#D1FAE5;color:#065F46;}
		.fc .fc-event{border:0;padding:2px 4px;font-size:12px;cursor:pointer;}
		.fc .fc-event-title{font-weight:600;}
		';
	}

	private function inline_script() {
		$rest_root = esc_url_raw( rest_url( G2AB_REST_NAMESPACE ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		$tz        = wp_timezone_string();
		ob_start();
		?>
		(function () {
			var REST    = <?php echo wp_json_encode( $rest_root ); ?>;
			var NONCE   = <?php echo wp_json_encode( $nonce ); ?>;
			var TZ      = <?php echo wp_json_encode( $tz ); ?>;
			var calendarEl, calendar, currentEvent;

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
				var box = document.getElementById('g2ab-cal-modal-msg');
				if (!box) return;
				box.textContent = text;
				box.className = 'g2ab-cal-msg is-' + (type || 'success');
			}

			function clearMsg() {
				var box = document.getElementById('g2ab-cal-modal-msg');
				if (box) { box.textContent = ''; box.className = ''; }
			}

			function refresh() {
				if (calendar) calendar.refetchEvents();
			}

			function openModal(event) {
				currentEvent = event;
				clearMsg();
				var props = event.extendedProps || {};
				document.getElementById('g2ab-cal-modal-title').textContent = props.customer_name || 'Booking';
				var html = '<table>'
					+ row('Status', '<span class="g2ab-cal-badge" style="background:' + event.backgroundColor + ';">' + (props.status_label || props.status) + '</span>')
					+ row('Payment', '<span class="g2ab-cal-payment-badge ' + props.payment_badge + '">' + props.payment_badge.replace('_',' ') + '</span> ' + formatMoney(props.paid_amount) + ' / ' + formatMoney(props.total_amount))
					+ row('When', formatRange(event.start, event.end))
					+ row('Resource', props.resource_name + ' (' + props.resource_type + ')')
					+ row('Type', (props.booking_type || '') + ' [' + (props.category || '') + ']')
					+ row('Party', props.party_size)
					+ row('Customer', (props.customer_email ? '<a href="mailto:' + props.customer_email + '">' + props.customer_email + '</a><br>' : '') + (props.customer_name || ''))
					+ row('Source', props.source || '')
					+ (props.cancel_reason ? row('Cancel reason', escapeHtml(props.cancel_reason)) : '')
					+ (props.notes ? row('Staff notes', '<em>' + escapeHtml(props.notes) + '</em>') : '')
					+ '</table>';
				document.getElementById('g2ab-cal-modal-body-content').innerHTML = html;
				document.getElementById('g2ab-cal-modal').setAttribute('data-uuid', event.id);
				document.getElementById('g2ab-cal-backdrop').classList.add('is-open');
				toggleActionButtons(props.status);
			}

			function closeModal() {
				document.getElementById('g2ab-cal-backdrop').classList.remove('is-open');
				currentEvent = null;
			}

			function toggleActionButtons(status) {
				var terminal = ['completed','cancelled','no_show','refunded','expired'];
				var disable  = terminal.indexOf(status) !== -1;
				document.querySelectorAll('.g2ab-cal-action').forEach(function (btn) {
					btn.disabled = disable;
				});
			}

			function row(label, value) {
				return '<tr><th>' + label + '</th><td>' + value + '</td></tr>';
			}

			function formatRange(start, end) {
				if (!start) return '';
				var opt = { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' };
				var s = start.toLocaleString(undefined, opt);
				var e = end ? end.toLocaleString(undefined, { hour:'2-digit', minute:'2-digit' }) : '';
				return s + (e ? ' → ' + e : '');
			}

			function formatMoney(n) {
				if (n === null || n === undefined) return '$0.00';
				return '$' + Number(n).toFixed(2);
			}

			function escapeHtml(s) {
				return String(s).replace(/[&<>"]/g, function (c) {
					return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c];
				});
			}

			function reschedulePrompt() {
				if (!currentEvent) return;
				var current = currentEvent.start ? toLocalInput(currentEvent.start) : '';
				var next = window.prompt('New start time (YYYY-MM-DD HH:MM):', current);
				if (!next) return;
				doReschedule(parseInt(currentEvent.id), next, null);
			}

			function doReschedule(bookingId, startAt, resourceId) {
				api('POST', '/calendar/reschedule', {
					booking_id: bookingId,
					start_at:   startAt,
					resource_id: resourceId || 0
				}).then(function (res) {
					if (res.ok && res.body && res.body.success) {
						showMsg('Rescheduled.', 'success');
						refresh();
					} else {
						showMsg(extractError(res.body) || 'Reschedule failed.', 'error');
						refresh();
					}
				});
			}

			function cancelPrompt() {
				if (!currentEvent) return;
				var reason = window.prompt('Cancellation reason (customer_request, no_payment, duplicate, staff_unavailable, weather, other):', 'customer_request');
				if (reason === null) return;
				var refunded = window.confirm('Mark as refunded too?');
				api('POST', '/calendar/cancel', {
					booking_id: parseInt(currentEvent.id),
					reason: reason,
					mark_refunded: refunded,
					send_email: true
				}).then(function (res) {
					if (res.ok && res.body && res.body.success) {
						showMsg('Cancelled.', 'success');
						closeModal();
						refresh();
					} else {
						showMsg(extractError(res.body) || 'Cancel failed.', 'error');
					}
				});
			}

			function noShowAction() {
				if (!currentEvent) return;
				api('POST', '/calendar/no-show', { booking_id: parseInt(currentEvent.id) })
					.then(handleSimple('Marked no-show.'));
			}

			function completeAction() {
				if (!currentEvent) return;
				api('POST', '/calendar/complete', { booking_id: parseInt(currentEvent.id) })
					.then(handleSimple('Marked completed.'));
			}

			function handleSimple(successMsg) {
				return function (res) {
					if (res.ok && res.body && res.body.success) {
						showMsg(successMsg, 'success');
						closeModal();
						refresh();
					} else {
						showMsg(extractError(res.body) || 'Action failed.', 'error');
					}
				};
			}

			function extractError(body) {
				if (!body) return null;
				return body.message || (body.data && body.data.message) || null;
			}

			function toLocalInput(d) {
				var pad = function (n) { return (n<10?'0':'') + n; };
				return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
					+ ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
			}

			function toSqlLocal(d) {
				var pad = function (n) { return (n<10?'0':'') + n; };
				return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
					+ ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
			}

			function fetchEvents(info, success, failure) {
				var resourceId = document.getElementById('g2ab-cal-filter-resource').value;
				var category   = document.getElementById('g2ab-cal-filter-category').value;
				var status     = document.getElementById('g2ab-cal-filter-status').value;
				var qs = new URLSearchParams({
					start: info.startStr,
					end:   info.endStr
				});
				if (resourceId) qs.append('resource_id', resourceId);
				if (category)   qs.append('category',    category);
				if (status)     qs.append('status',      status);
				api('GET', '/calendar/events?' + qs.toString())
					.then(function (res) {
						if (res.ok && res.body && res.body.success) success(res.body.data);
						else failure(new Error(extractError(res.body) || 'Failed to load events.'));
					})
					.catch(failure);
			}

			function init() {
				calendarEl = document.getElementById('g2ab-cal-main');
				if (!calendarEl) return;
				if (typeof FullCalendar === 'undefined') {
					calendarEl.innerHTML = '<div style="padding:48px;text-align:center;color:#7f1d1d;font-size:14px;">The calendar library did not load. A security plugin, firewall, or content-blocker may be preventing it. The library is bundled with the plugin at /assets/vendor/fullcalendar/ — re-run the plugin update or allow that script, then reload.</div>';
					return;
				}

				calendar = new FullCalendar.Calendar(calendarEl, {
					initialView: 'dayGridMonth',
					headerToolbar: {
						left:   'prev,next today',
						center: 'title',
						right:  'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
					},
					dayMaxEventRows: 4,
					timeZone: TZ,
					nowIndicator: true,
					editable: true,
					eventDurationEditable: false,
					selectable: false,
					eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
					height: 'auto',
					events: fetchEvents,
					eventClick: function (info) {
						info.jsEvent.preventDefault();
						openModal(info.event);
					},
					eventDrop: function (info) {
						doReschedule(parseInt(info.event.id), toSqlLocal(info.event.start), null);
					}
				});
				calendar.render();

				['g2ab-cal-filter-resource','g2ab-cal-filter-category','g2ab-cal-filter-status'].forEach(function (id) {
					document.getElementById(id).addEventListener('change', refresh);
				});

				document.getElementById('g2ab-cal-backdrop').addEventListener('click', function (e) {
					if (e.target.id === 'g2ab-cal-backdrop') closeModal();
				});
				document.getElementById('g2ab-cal-modal-close').addEventListener('click', closeModal);
				document.getElementById('g2ab-cal-action-reschedule').addEventListener('click', reschedulePrompt);
				document.getElementById('g2ab-cal-action-cancel').addEventListener('click', cancelPrompt);
				document.getElementById('g2ab-cal-action-no-show').addEventListener('click', noShowAction);
				document.getElementById('g2ab-cal-action-complete').addEventListener('click', completeAction);
			}

			// This inline runs in the footer, where DOMContentLoaded may have already
			// fired — in that case the listener never calls init(). Guard for it.
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		<?php
		return ob_get_clean();
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		global $wpdb;
		$resources = $wpdb->get_results( "SELECT id, name, type FROM {$wpdb->prefix}g2ab_resources WHERE is_active = 1 ORDER BY sort_order ASC, name ASC" );
		$categories = $wpdb->get_col( "SELECT DISTINCT category FROM {$wpdb->prefix}g2ab_booking_types WHERE is_active = 1 ORDER BY category ASC" );
		?>
		<div class="wrap g2ab-wrap g2ab-admin">
			<h1><?php esc_html_e( 'Calendar', 'g2a-booking' ); ?></h1>
			<p><?php esc_html_e( 'Day / week / month view of every booking. Drag to reschedule, click for quick actions.', 'g2a-booking' ); ?></p>

			<div class="g2ab-cal-shell">
				<aside class="g2ab-cal-side">
					<fieldset>
						<h3><?php esc_html_e( 'Resource / Lane', 'g2a-booking' ); ?></h3>
						<select id="g2ab-cal-filter-resource">
							<option value=""><?php esc_html_e( 'All resources', 'g2a-booking' ); ?></option>
							<?php foreach ( $resources as $r ) : ?>
								<option value="<?php echo (int) $r->id; ?>"><?php echo esc_html( $r->name ); ?> (<?php echo esc_html( $r->type ); ?>)</option>
							<?php endforeach; ?>
						</select>
					</fieldset>
					<fieldset>
						<h3><?php esc_html_e( 'Category', 'g2a-booking' ); ?></h3>
						<select id="g2ab-cal-filter-category">
							<option value=""><?php esc_html_e( 'All categories', 'g2a-booking' ); ?></option>
							<?php foreach ( $categories as $cat ) : if ( ! $cat ) continue; ?>
								<option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( ucfirst( $cat ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</fieldset>
					<fieldset>
						<h3><?php esc_html_e( 'Status', 'g2a-booking' ); ?></h3>
						<select id="g2ab-cal-filter-status">
							<option value=""><?php esc_html_e( 'All statuses', 'g2a-booking' ); ?></option>
							<?php foreach ( G2AB_Booking_Statuses::all() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</fieldset>
					<div class="g2ab-cal-legend">
						<?php foreach ( G2AB_Booking_Statuses::all() as $key => $label ) : ?>
							<span><i style="background:<?php echo esc_attr( G2AB_Booking_Statuses::color( $key ) ); ?>;"></i><?php echo esc_html( $label ); ?></span>
						<?php endforeach; ?>
					</div>
				</aside>

				<main class="g2ab-cal-main" id="g2ab-cal-main"></main>
			</div>
		</div>

		<div id="g2ab-cal-backdrop" class="g2ab-cal-modal-backdrop" aria-hidden="true">
			<div id="g2ab-cal-modal" class="g2ab-cal-modal" role="dialog" aria-modal="true">
				<div class="g2ab-cal-modal-head">
					<h2 id="g2ab-cal-modal-title"><?php esc_html_e( 'Booking', 'g2a-booking' ); ?></h2>
					<button type="button" id="g2ab-cal-modal-close" class="button"><?php esc_html_e( 'Close', 'g2a-booking' ); ?></button>
				</div>
				<div class="g2ab-cal-modal-body">
					<div id="g2ab-cal-modal-body-content"></div>
					<div id="g2ab-cal-modal-msg"></div>
				</div>
				<div class="g2ab-cal-modal-actions">
					<button type="button" id="g2ab-cal-action-reschedule" class="button g2ab-cal-action"><?php esc_html_e( 'Reschedule', 'g2a-booking' ); ?></button>
					<button type="button" id="g2ab-cal-action-complete"   class="button g2ab-cal-action"><?php esc_html_e( 'Mark Completed', 'g2a-booking' ); ?></button>
					<button type="button" id="g2ab-cal-action-no-show"    class="button g2ab-cal-action"><?php esc_html_e( 'Mark No-Show', 'g2a-booking' ); ?></button>
					<button type="button" id="g2ab-cal-action-cancel"     class="button button-link-delete g2ab-cal-action"><?php esc_html_e( 'Cancel Booking', 'g2a-booking' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
