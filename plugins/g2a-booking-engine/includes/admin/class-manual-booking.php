<?php
/**
 * Admin: Manual Booking foundation.
 *
 * Adds a "Manual Booking" submenu and a thin form that posts to
 * POST /g2a-booking/v1/admin/bookings (G2AB_REST_Admin_Bookings_Controller).
 * The full drag-and-drop calendar quick-add lands in v1.3.0; this is the
 * foundation it'll reuse.
 *
 * @package G2AB
 * @since   1.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Manual_Booking {

	const SLUG = 'g2ab-manual-booking';
	const CAP  = 'manage_g2ab_bookings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Manual Booking', 'g2a-booking' ),
			__( 'Manual Booking', 'g2a-booking' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_register_script(
			'g2ab-manual-booking',
			'',
			array(),
			G2AB_VERSION,
			true
		);
		wp_enqueue_script( 'g2ab-manual-booking' );
		wp_add_inline_script( 'g2ab-manual-booking', $this->inline_script() );
	}

	private function inline_script() {
		$rest_root = esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/admin/bookings' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		ob_start();
		?>
		(function () {
			var endpoint = <?php echo wp_json_encode( $rest_root ); ?>;
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			document.addEventListener('DOMContentLoaded', function () {
				var form = document.getElementById('g2ab-manual-booking-form');
				if (!form) return;
				var notice = document.getElementById('g2ab-manual-booking-notice');
				form.addEventListener('submit', function (ev) {
					ev.preventDefault();
					notice.textContent = '';
					notice.className   = '';
					var data = {};
					new FormData(form).forEach(function (v, k) {
						if (k === 'send_email' || k === 'skip_waiver') {
							data[k] = true;
						} else {
							data[k] = v;
						}
					});
					if (!form.elements['send_email'].checked) data.send_email = false;
					if (!form.elements['skip_waiver'].checked) data.skip_waiver = false;
					form.querySelector('button[type=submit]').disabled = true;
					fetch(endpoint, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': nonce
						},
						credentials: 'same-origin',
						body: JSON.stringify(data)
					}).then(function (r) {
						return r.json().then(function (j) { return { ok: r.ok, body: j }; });
					}).then(function (res) {
						if (res.ok && res.body && res.body.success) {
							notice.className = 'notice notice-success';
							notice.textContent = 'Booking created (#' + res.body.data.id + ', ' + res.body.data.status + ').';
							form.reset();
						} else {
							var msg = res.body && (res.body.message || (res.body.data && res.body.data.message)) || 'Could not save booking.';
							notice.className = 'notice notice-error';
							notice.textContent = msg;
						}
					}).catch(function (err) {
						notice.className = 'notice notice-error';
						notice.textContent = 'Network error: ' + err.message;
					}).finally(function () {
						form.querySelector('button[type=submit]').disabled = false;
					});
				});
			});
		})();
		<?php
		return ob_get_clean();
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		global $wpdb;
		$booking_types = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}g2ab_booking_types WHERE is_active = 1 ORDER BY name ASC" );
		$resources     = $wpdb->get_results( "SELECT id, name, type FROM {$wpdb->prefix}g2ab_resources WHERE is_active = 1 ORDER BY sort_order ASC, name ASC" );
		?>
		<div class="wrap g2ab-wrap g2ab-admin">
			<h1><?php esc_html_e( 'Manual Booking', 'g2a-booking' ); ?></h1>
			<p><?php esc_html_e( 'Create a booking on behalf of a phone, walk-in, or staff customer. Foundation for the full v1.3.0 calendar quick-add.', 'g2a-booking' ); ?></p>
			<div id="g2ab-manual-booking-notice" style="margin:12px 0;"></div>
			<div class="g2ab-card" style="max-width:760px;">
				<form id="g2ab-manual-booking-form">
					<table class="form-table">
						<tr>
							<th><label for="g2ab-mb-type"><?php esc_html_e( 'Booking Type', 'g2a-booking' ); ?></label></th>
							<td>
								<select id="g2ab-mb-type" name="booking_type_id" required>
									<option value=""><?php esc_html_e( '— choose —', 'g2a-booking' ); ?></option>
									<?php foreach ( $booking_types as $bt ) : ?>
										<option value="<?php echo (int) $bt->id; ?>"><?php echo esc_html( $bt->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-resource"><?php esc_html_e( 'Resource / Lane', 'g2a-booking' ); ?></label></th>
							<td>
								<select id="g2ab-mb-resource" name="resource_id" required>
									<option value=""><?php esc_html_e( '— choose —', 'g2a-booking' ); ?></option>
									<?php foreach ( $resources as $r ) : ?>
										<option value="<?php echo (int) $r->id; ?>"><?php echo esc_html( $r->name ); ?> (<?php echo esc_html( $r->type ); ?>)</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-start"><?php esc_html_e( 'Start (site time)', 'g2a-booking' ); ?></label></th>
							<td>
								<input id="g2ab-mb-start" type="datetime-local" name="start_at" step="60" required />
								<p class="description"><?php esc_html_e( 'Format: YYYY-MM-DD HH:MM. End is computed from booking-type duration.', 'g2a-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-party"><?php esc_html_e( 'Party Size', 'g2a-booking' ); ?></label></th>
							<td><input id="g2ab-mb-party" type="number" name="party_size" min="1" max="50" value="1" required /></td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-name"><?php esc_html_e( 'Customer Name', 'g2a-booking' ); ?></label></th>
							<td><input id="g2ab-mb-name" type="text" name="customer_name" class="regular-text" required /></td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-email"><?php esc_html_e( 'Customer Email', 'g2a-booking' ); ?></label></th>
							<td><input id="g2ab-mb-email" type="email" name="customer_email" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-phone"><?php esc_html_e( 'Customer Phone', 'g2a-booking' ); ?></label></th>
							<td><input id="g2ab-mb-phone" type="tel" name="customer_phone" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-source"><?php esc_html_e( 'Source', 'g2a-booking' ); ?></label></th>
							<td>
								<select id="g2ab-mb-source" name="source">
									<option value="phone"><?php esc_html_e( 'Phone Booking', 'g2a-booking' ); ?></option>
									<option value="walk_in"><?php esc_html_e( 'Walk-in', 'g2a-booking' ); ?></option>
									<option value="staff" selected><?php esc_html_e( 'Staff-created', 'g2a-booking' ); ?></option>
									<option value="admin"><?php esc_html_e( 'Admin', 'g2a-booking' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-payment"><?php esc_html_e( 'Payment', 'g2a-booking' ); ?></label></th>
							<td>
								<select id="g2ab-mb-payment" name="payment_mode">
									<option value="in_store"><?php esc_html_e( 'Pay in Store', 'g2a-booking' ); ?></option>
									<option value="cash"><?php esc_html_e( 'Cash (paid)', 'g2a-booking' ); ?></option>
									<option value="card_terminal"><?php esc_html_e( 'Card at terminal (paid)', 'g2a-booking' ); ?></option>
									<option value="admin_comp"><?php esc_html_e( 'Admin comp (free)', 'g2a-booking' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="g2ab-mb-notes"><?php esc_html_e( 'Staff Notes', 'g2a-booking' ); ?></label></th>
							<td><textarea id="g2ab-mb-notes" name="notes" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Optional internal notes (not shown to customer).', 'g2a-booking' ); ?>"></textarea></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Options', 'g2a-booking' ); ?></th>
							<td>
								<label><input type="checkbox" name="send_email" /> <?php esc_html_e( 'Email customer a confirmation', 'g2a-booking' ); ?></label><br />
								<label><input type="checkbox" name="skip_waiver" /> <?php esc_html_e( 'Waiver already on file (skip waiver requirement)', 'g2a-booking' ); ?></label>
							</td>
						</tr>
					</table>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Booking', 'g2a-booking' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
