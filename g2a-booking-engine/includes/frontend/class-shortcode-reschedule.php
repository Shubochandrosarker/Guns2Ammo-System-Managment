<?php
/**
 * Frontend shortcodes: [g2ab_reschedule] and [g2ab_cancel_booking].
 *
 * Both shortcodes read `uuid` + `token` from the query string (the same
 * values the email automation merge tags emit) and post to the
 * /bookings/{uuid}/customer-reschedule or /customer-cancel REST routes.
 *
 * @package G2AB
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Reschedule {

	private static $instance = null;
	private $printed_assets = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2ab_reschedule', array( $this, 'render_reschedule' ) );
		add_shortcode( 'g2ab_cancel_booking', array( $this, 'render_cancel' ) );
	}

	public function render_reschedule( $atts ) {
		$ctx = $this->resolve_context();
		if ( is_string( $ctx ) ) {
			return $ctx; // already-rendered error html
		}
		ob_start();
		?>
		<div class="g2ab-customer-action g2ab-customer-reschedule" data-uuid="<?php echo esc_attr( $ctx['uuid'] ); ?>" data-token="<?php echo esc_attr( $ctx['token'] ); ?>">
			<h2><?php esc_html_e( 'Reschedule your reservation', 'g2a-booking' ); ?></h2>
			<p><?php
				printf(
					/* translators: 1: resource name, 2: original start datetime */
					esc_html__( 'Currently booked for %1$s on %2$s.', 'g2a-booking' ),
					'<strong>' . esc_html( $ctx['resource_name'] ) . '</strong>',
					'<strong>' . esc_html( $ctx['start_at_human'] ) . '</strong>'
				);
			?></p>
			<label><?php esc_html_e( 'New start time:', 'g2a-booking' ); ?>
				<input type="datetime-local" class="g2ab-new-start" step="60" required />
			</label>
			<button type="button" class="g2ab-submit"><?php esc_html_e( 'Confirm new time', 'g2a-booking' ); ?></button>
			<div class="g2ab-msg" role="status" aria-live="polite"></div>
		</div>
		<?php
		$this->print_assets( 'reschedule' );
		return ob_get_clean();
	}

	public function render_cancel( $atts ) {
		$ctx = $this->resolve_context();
		if ( is_string( $ctx ) ) {
			return $ctx;
		}
		ob_start();
		?>
		<div class="g2ab-customer-action g2ab-customer-cancel" data-uuid="<?php echo esc_attr( $ctx['uuid'] ); ?>" data-token="<?php echo esc_attr( $ctx['token'] ); ?>">
			<h2><?php esc_html_e( 'Cancel your reservation', 'g2a-booking' ); ?></h2>
			<p><?php
				printf(
					/* translators: 1: resource name, 2: original start datetime */
					esc_html__( 'You are about to cancel %1$s on %2$s.', 'g2a-booking' ),
					'<strong>' . esc_html( $ctx['resource_name'] ) . '</strong>',
					'<strong>' . esc_html( $ctx['start_at_human'] ) . '</strong>'
				);
			?></p>
			<label><?php esc_html_e( 'Reason (optional):', 'g2a-booking' ); ?>
				<select class="g2ab-reason">
					<option value="customer_request"><?php esc_html_e( 'Schedule conflict', 'g2a-booking' ); ?></option>
					<option value="weather"><?php esc_html_e( 'Weather / travel', 'g2a-booking' ); ?></option>
					<option value="duplicate_booking"><?php esc_html_e( 'Duplicate booking', 'g2a-booking' ); ?></option>
					<option value="other"><?php esc_html_e( 'Other', 'g2a-booking' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Note (optional):', 'g2a-booking' ); ?>
				<input type="text" class="g2ab-reason-text" maxlength="200" />
			</label>
			<button type="button" class="g2ab-submit"><?php esc_html_e( 'Cancel reservation', 'g2a-booking' ); ?></button>
			<div class="g2ab-msg" role="status" aria-live="polite"></div>
		</div>
		<?php
		$this->print_assets( 'cancel' );
		return ob_get_clean();
	}

	/**
	 * Pull uuid + token from the query string and load the booking context.
	 * Returns either an array of context fields or an HTML error string ready
	 * to be returned from the shortcode callback.
	 */
	private function resolve_context() {
		$uuid  = isset( $_GET['uuid'] )  ? sanitize_text_field( wp_unslash( $_GET['uuid'] ) )  : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) || '' === $token ) {
			return $this->error_box( __( 'This link is missing or invalid. Please use the link in your confirmation email.', 'g2a-booking' ) );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.uuid, b.start_at, b.metadata, r.name AS resource_name
			   FROM {$wpdb->prefix}g2ab_bookings b
			   LEFT JOIN {$wpdb->prefix}g2ab_resources r ON r.id = b.resource_id
			  WHERE b.uuid = %s LIMIT 1",
			$uuid
		) );
		if ( ! $row ) {
			return $this->error_box( __( 'Booking not found. It may already have been cancelled.', 'g2a-booking' ) );
		}
		$meta   = json_decode( (string) $row->metadata, true );
		$stored = isset( $meta['confirm_token'] ) ? (string) $meta['confirm_token'] : '';
		if ( '' === $stored || ! hash_equals( $stored, $token ) ) {
			return $this->error_box( __( 'This link has expired. Please call us if you need to make changes.', 'g2a-booking' ) );
		}
		$ts = strtotime( (string) $row->start_at );
		return array(
			'uuid'           => $row->uuid,
			'token'          => $token,
			'resource_name'  => (string) ( $row->resource_name ?? '' ),
			'start_at_human' => $ts ? date_i18n( get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ), $ts ) : (string) $row->start_at,
		);
	}

	private function error_box( $msg ) {
		return '<div class="g2ab-customer-action g2ab-customer-error">' . esc_html( $msg ) . '</div>';
	}

	private function print_assets( $kind ) {
		if ( $this->printed_assets ) {
			return;
		}
		$this->printed_assets = true;
		$rest_root = esc_url_raw( rest_url( G2AB_REST_NAMESPACE ) );
		?>
		<style>
		.g2ab-customer-action{max-width:560px;margin:24px auto;padding:24px;border:1px solid #d9e2ef;border-radius:10px;background:#fff;font-family:Arial,sans-serif;color:#172033;}
		.g2ab-customer-action h2{margin-top:0;color:#0f2044;}
		.g2ab-customer-action label{display:block;margin:14px 0;font-size:14px;}
		.g2ab-customer-action input,.g2ab-customer-action select{display:block;margin-top:6px;width:100%;padding:8px 10px;border:1px solid #c8d1e0;border-radius:6px;font-size:15px;box-sizing:border-box;}
		.g2ab-customer-action button{background:#0f2044;color:#fff;border:0;padding:12px 22px;font-weight:700;border-radius:6px;cursor:pointer;margin-top:8px;}
		.g2ab-customer-action button:hover{background:#1a3a7a;}
		.g2ab-customer-action button:disabled{opacity:.5;cursor:not-allowed;}
		.g2ab-customer-action .g2ab-msg{margin-top:14px;padding:10px 12px;border-radius:6px;font-size:14px;display:none;}
		.g2ab-customer-action .g2ab-msg.is-error{display:block;background:#fee2e2;color:#7f1d1d;}
		.g2ab-customer-action .g2ab-msg.is-success{display:block;background:#d1fae5;color:#065f46;}
		.g2ab-customer-error{background:#fee2e2;color:#7f1d1d;border-color:#fca5a5;}
		</style>
		<script>
		(function () {
			var REST = <?php echo wp_json_encode( $rest_root ); ?>;
			function bind() {
				document.querySelectorAll('.g2ab-customer-reschedule').forEach(function (root) {
					var btn = root.querySelector('.g2ab-submit');
					var msg = root.querySelector('.g2ab-msg');
					var inp = root.querySelector('.g2ab-new-start');
					btn.addEventListener('click', function () {
						msg.className = 'g2ab-msg';
						if (!inp.value) { msg.className = 'g2ab-msg is-error'; msg.textContent = 'Pick a new date and time.'; return; }
						btn.disabled = true;
						var startSql = inp.value.replace('T', ' ');
						if (startSql.length === 16) startSql += ':00';
						fetch(REST + '/bookings/' + root.dataset.uuid + '/customer-reschedule', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
							credentials: 'omit',
							body: JSON.stringify({ confirm_token: root.dataset.token, start_at: startSql })
						}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
						.then(function (res) {
							btn.disabled = false;
							if (res.ok && res.body && res.body.success) {
								msg.className = 'g2ab-msg is-success';
								msg.textContent = 'Rescheduled to ' + res.body.data.start_at;
							} else {
								msg.className = 'g2ab-msg is-error';
								msg.textContent = (res.body && (res.body.message || (res.body.data && res.body.data.message))) || 'Could not reschedule.';
							}
						});
					});
				});
				document.querySelectorAll('.g2ab-customer-cancel').forEach(function (root) {
					var btn = root.querySelector('.g2ab-submit');
					var msg = root.querySelector('.g2ab-msg');
					var sel = root.querySelector('.g2ab-reason');
					var txt = root.querySelector('.g2ab-reason-text');
					btn.addEventListener('click', function () {
						if (!window.confirm('Are you sure you want to cancel?')) return;
						btn.disabled = true;
						fetch(REST + '/bookings/' + root.dataset.uuid + '/customer-cancel', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
							credentials: 'omit',
							body: JSON.stringify({ confirm_token: root.dataset.token, reason: sel.value, reason_text: txt.value })
						}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
						.then(function (res) {
							btn.disabled = false;
							if (res.ok && res.body && res.body.success) {
								msg.className = 'g2ab-msg is-success';
								msg.textContent = 'Your reservation has been cancelled.';
							} else {
								msg.className = 'g2ab-msg is-error';
								msg.textContent = (res.body && (res.body.message || (res.body.data && res.body.data.message))) || 'Could not cancel.';
							}
						});
					});
				});
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', bind);
			} else {
				bind();
			}
		})();
		</script>
		<?php
	}
}
