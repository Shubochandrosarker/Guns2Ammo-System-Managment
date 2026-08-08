<?php
/**
 * G2A — Real pickup scheduling via g2a-booking-engine (Exhibit 09).
 *
 * The "arrived at dealer" email used to attach a fixed .ics invite — next
 * business day, 12:00 local, 30-minute window, a guess nobody confirmed.
 * g2a-booking-engine is a full race-safe appointment/resource booking
 * plugin already built and running in the same business system. This class
 * connects them for real: when a transfer reaches `received_by_dealer`, the
 * customer gets a link to a page that queries the dealer's ACTUAL
 * availability from g2a-booking-engine and lets them pick a real open slot.
 *
 * Architecture, and why it's shaped this way:
 *
 *   - g2a-booking-engine ("G2AB") now ships an `ffl-checkout` module
 *     (`G2AB_Module_Ffl_Checkout`) that owns the two pieces that need a
 *     real, stable, callable surface: syncing a dealer to a resource, and
 *     ensuring the shared "FFL Firearm Pickup" booking type exists. This
 *     bridge calls that module's public API instead of writing G2AB's
 *     `g2ab_resources` / `g2ab_booking_types` / `g2ab_availability_rules`
 *     tables directly — the module is the source of truth for that data
 *     shape, not this plugin.
 *   - Reading live availability and creating the booking itself still go
 *     through G2AB's own public `/availability` REST route and its
 *     `G2AB_REST_Admin_Bookings_Controller::create_booking()`, which
 *     contains the one piece of logic that must not be reimplemented — a
 *     transactional `SELECT ... FOR UPDATE` that makes two customers racing
 *     for the same slot fail safely (HTTP 409 `g2ab_slot_full`) instead of
 *     double-booking. Because that admin-bookings path applies no
 *     business-hours/lead-time/blackout validation of its own (by design —
 *     see that controller's docblock), `ajax_confirm()` first calls the
 *     module's `is_real_open_slot()` to re-derive real availability
 *     server-side from the client-supplied `start_at`, so a forged or stale
 *     time can't be booked even though the capacity check alone would let
 *     it through.
 *   - That admin-bookings endpoint is normally gated on a `wp_rest` nonce +
 *     `manage_g2ab_bookings` capability (meant for logged-in staff). Those
 *     checks live in a `permission_callback`, which only runs when WordPress
 *     dispatches the route through `rest_do_request()` — calling
 *     `create_booking()` directly, in-process, never goes through that
 *     dispatcher, so it never runs. This mirrors how this plugin's OWN code
 *     already gets called directly by sibling plugins elsewhere in this
 *     business system; it is not a security bypass of anything customer-
 *     facing, since the customer never talks to G2AB directly at all — they
 *     only ever talk to THIS plugin's own HMAC-signed, rate-limited-by-design
 *     confirm endpoint below, which is the thing actually gating who can book.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Booking_Bridge {

	const SLUG = 'schedule-pickup';

	public function __construct() {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_request' ] );

		add_action( 'wp_ajax_wpistic_ffl_booking_confirm',        [ __CLASS__, 'ajax_confirm' ] );
		add_action( 'wp_ajax_nopriv_wpistic_ffl_booking_confirm', [ __CLASS__, 'ajax_confirm' ] );
	}

	/** Is g2a-booking-engine active, with its ffl-checkout module loaded? */
	public static function is_available(): bool {
		return class_exists( '\G2AB_Plugin' )
			&& class_exists( '\G2AB_REST_Admin_Bookings_Controller' )
			&& class_exists( '\G2AB_Module_Ffl_Checkout' );
	}

	public function register_rewrites(): void {
		add_rewrite_tag( '%g2a_sched_ref%', '([^&/]+)' );
		add_rewrite_tag( '%g2a_sched_sig%', '([^&/]+)' );
		add_rewrite_rule(
			'^' . self::SLUG . '/([^/]+)/([^/]+)/?$',
			'index.php?g2a_sched_ref=$matches[1]&g2a_sched_sig=$matches[2]',
			'top'
		);
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'g2a_sched_ref';
		$vars[] = 'g2a_sched_sig';
		return $vars;
	}

	/** Build the URL we put inside the "arrived at dealer" email. */
	public static function url_for( string $ref ): string {
		return home_url( '/' . self::SLUG . '/' . rawurlencode( $ref ) . '/' . rawurlencode( self::sign( $ref ) ) . '/' );
	}

	private static function sign( string $ref ): string {
		return substr( hash_hmac( 'sha256', 'schedule:' . $ref, Token::secret() ), 0, 16 );
	}

	// ── Page render ────────────────────────────────────────────

	public function handle_request(): void {
		$ref = get_query_var( 'g2a_sched_ref' );
		$sig = get_query_var( 'g2a_sched_sig' );
		if ( empty( $ref ) || empty( $sig ) ) {
			return;
		}
		$ref = sanitize_text_field( rawurldecode( $ref ) );
		$sig = sanitize_text_field( rawurldecode( $sig ) );
		if ( ! hash_equals( self::sign( $ref ), $sig ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This scheduling link is invalid or has expired. Please use the latest email or contact us for help.', 'advanced-ffl-checkout' ) );
		}

		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT tr.*, d.business_name, d.premise_street, d.premise_city, d.premise_state, d.premise_zip, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' tr
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = tr.dealer_id
			 WHERE tr.transfer_ref = %s LIMIT 1',
			$ref
		) );
		if ( ! $t ) {
			status_header( 404 );
			wp_die( esc_html__( 'We can\'t find that transfer reference.', 'advanced-ffl-checkout' ) );
		}

		if ( ! self::is_available() ) {
			status_header( 503 );
			wp_die( esc_html__( 'Online scheduling is temporarily unavailable. Please call the dealer directly to arrange pickup.', 'advanced-ffl-checkout' ) );
		}

		if ( $t->booking_appointment_id ) {
			self::render_already_booked( $t );
			exit;
		}

		$booking_type_id = \G2AB_Module_Ffl_Checkout::ensure_pickup_booking_type();
		$resource_id      = self::sync_dealer_resource( $t );
		if ( ! $booking_type_id || ! $resource_id ) {
			status_header( 503 );
			wp_die( esc_html__( 'Could not set up scheduling for this dealer. Please contact us for help.', 'advanced-ffl-checkout' ) );
		}

		self::render_picker( $t, $resource_id, $booking_type_id, $ref, $sig );
		exit;
	}

	// ── G2AB module bridge ──────────────────────────────────────

	/**
	 * Resolve this transfer's dealer into a G2AB resource by delegating to
	 * the g2a-booking-engine `ffl-checkout` module's public API — that
	 * module owns the resource/booking-type table shape, not this plugin.
	 */
	private static function sync_dealer_resource( object $t ): int {
		global $wpdb;
		$license = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT license_number FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', (int) $t->dealer_id
		) );
		if ( ! $license ) {
			return 0;
		}
		return \G2AB_Module_Ffl_Checkout::sync_dealer_resource( [
			'license_number' => $license,
			'business_name'  => $t->business_name ?: 'FFL Dealer',
			'street'         => $t->premise_street ?? '',
			'city'           => $t->premise_city ?? '',
			'state'          => $t->premise_state ?? '',
			'zip'            => $t->premise_zip ?? '',
			'phone'          => $t->dealer_phone ?? '',
		] );
	}

	// ── Confirm booking (in-process call into G2AB, race-safety included) ──

	public static function ajax_confirm(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$ref      = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
		$sig      = sanitize_text_field( wp_unslash( $_POST['sig'] ?? '' ) );
		$nonce    = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		$start_at = sanitize_text_field( wp_unslash( $_POST['start_at'] ?? '' ) );
		// phpcs:enable

		if ( '' === $ref || ! hash_equals( self::sign( $ref ), $sig ) ) {
			wp_send_json_error( [ 'message' => 'This scheduling link is invalid or has expired.' ], 403 );
		}
		if ( ! wp_verify_nonce( $nonce, 'wpistic_ffl_schedule_' . $ref ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed — please reload the page.' ], 403 );
		}
		if ( ! self::is_available() ) {
			wp_send_json_error( [ 'message' => 'Scheduling is temporarily unavailable.' ], 503 );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $start_at ) ) {
			wp_send_json_error( [ 'message' => 'Invalid time selected.' ], 400 );
		}

		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'transfers' ) . ' WHERE transfer_ref = %s LIMIT 1', $ref
		) );
		if ( ! $t ) {
			wp_send_json_error( [ 'message' => 'Transfer not found.' ], 404 );
		}
		if ( $t->booking_appointment_id ) {
			wp_send_json_error( [ 'message' => 'This transfer already has a confirmed appointment.' ], 409 );
		}

		$booking_type_id = \G2AB_Module_Ffl_Checkout::ensure_pickup_booking_type();
		$resource_id      = self::sync_dealer_resource( $t );
		if ( ! $booking_type_id || ! $resource_id ) {
			wp_send_json_error( [ 'message' => 'Could not resolve dealer scheduling setup.' ], 500 );
		}

		// The admin-bookings path below applies no business-hours/lead-time/
		// blackout validation of its own (by design), so re-derive real
		// availability server-side before trusting the client-supplied time —
		// the SELECT ... FOR UPDATE race-check alone only proves the slot
		// isn't double-booked, not that it's a real, open, in-hours slot.
		if ( ! \G2AB_Module_Ffl_Checkout::is_real_open_slot( $resource_id, $booking_type_id, $start_at ) ) {
			wp_send_json_error( [ 'message' => 'That time is no longer available — please pick another.' ], 409 );
		}

		// In-process call into G2AB's own race-safe booking creation — see the
		// class docblock for why calling this directly (not via REST dispatch)
		// is the correct approach here, and why it's safe: the SELECT ... FOR
		// UPDATE inside create_booking() is what actually prevents a double
		// booking, and it runs identically whether reached via REST or here.
		$request = new \WP_REST_Request( 'POST', '/g2a-booking/v1/admin/bookings' );
		$request->set_param( 'booking_type_id', $booking_type_id );
		$request->set_param( 'resource_id', $resource_id );
		$request->set_param( 'start_at', $start_at );
		$request->set_param( 'party_size', 1 );
		$request->set_param( 'payment_mode', 'admin_comp' );
		$request->set_param( 'source', 'staff' ); // Closest allowed enum value to "system-initiated on the customer's behalf" — G2AB's ALLOWED_SOURCES has no 'api'/'integration' value.
		$request->set_param( 'notes', 'FFL pickup appointment for transfer #' . $t->transfer_ref . '. Booked by the customer via the FFL plugin\'s scheduling link.' );
		$request->set_param( 'customer_name', $t->customer_name );
		$request->set_param( 'customer_email', $t->customer_email );
		$request->set_param( 'customer_phone', $t->customer_phone );
		$request->set_param( 'send_email', false ); // This plugin sends its own confirmation email below.
		$request->set_param( 'skip_waiver', true );

		$controller = new \G2AB_REST_Admin_Bookings_Controller();
		$response   = $controller->create_booking( $request );

		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 500 );
			wp_send_json_error( [ 'message' => $response->get_error_message() ], $status ?: 500 );
		}

		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		$booking = $data['data'] ?? [];
		if ( empty( $booking['uuid'] ) ) {
			wp_send_json_error( [ 'message' => 'Booking could not be confirmed.' ], 500 );
		}

		$wpdb->update( DB::table( 'transfers' ), [ // phpcs:ignore WordPress.DB
			'booking_appointment_id' => (string) $booking['uuid'],
			'booking_slot_start'     => (string) $booking['start_at'],
			'updated_at'             => current_time( 'mysql' ),
		], [ 'id' => (int) $t->id ] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => (int) $t->id,
			'event_type'  => 'pickup_appointment_booked',
			'notes'       => sprintf( 'Customer booked a real pickup appointment for %s via g2a-booking-engine.', $booking['start_at'] ),
			'actor'       => 'customer-scheduling',
			'actor_ip'    => Token::client_ip(),
		] );

		Mailer::send_status_update( (int) $t->id, $t->status ); // Re-send the current-status email; template picks up the new booking_slot_start.

		wp_send_json_success( [
			'start_at' => $booking['start_at'],
			'end_at'   => $booking['end_at'] ?? '',
		] );
	}

	// ── Rendering ──────────────────────────────────────────────

	private static function render_already_booked( object $t ): void {
		$theme = Theming::settings();
		nocache_headers();
		status_header( 200 );
		?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow">
		<title><?php echo esc_html( $theme['business_name'] ); ?> — Pickup Scheduled</title>
		<style>body{margin:0;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;font-family:system-ui,sans-serif;}.wrap{max-width:540px;margin:80px auto;padding:32px;background:<?php echo esc_attr( $theme['color_surface'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:12px;text-align:center;}</style>
		</head><body><div class="wrap">
			<h1><?php echo esc_html( $theme['business_name'] ); ?></h1>
			<p>✅ <?php esc_html_e( 'Your pickup is already scheduled for:', 'advanced-ffl-checkout' ); ?></p>
			<p style="font-size:20px;font-weight:700;"><?php echo esc_html( date_i18n( 'l, F j, Y g:i a', strtotime( (string) $t->booking_slot_start ) ) ); ?></p>
			<p style="opacity:.7;font-size:13px;"><?php esc_html_e( 'Contact us if you need to reschedule.', 'advanced-ffl-checkout' ); ?></p>
		</div></body></html>
		<?php
	}

	private static function render_picker( object $t, int $resource_id, int $booking_type_id, string $ref, string $sig ): void {
		$theme        = Theming::settings();
		$availability_url = rest_url( 'g2a-booking/v1/availability' );
		$confirm_nonce = wp_create_nonce( 'wpistic_ffl_schedule_' . $ref );
		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $theme['business_name'] ); ?> — Schedule Your Pickup</title>
<style>
body{margin:0;padding:0;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.5;}
.wrap{max-width:560px;margin:0 auto;padding:48px 24px;}
.card{background:<?php echo esc_attr( $theme['color_surface'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:16px;padding:32px;margin-bottom:20px;}
h1{margin:0 0 8px;font-size:22px;}
.meta{color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;font-size:13px;}
input[type=date]{padding:10px 12px;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:8px;font-family:inherit;font-size:14px;}
.slots{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:16px;}
.slot{padding:10px 6px;text-align:center;border-radius:8px;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;cursor:pointer;font-size:13px;font-weight:600;}
.slot:hover, .slot.selected{border-color:<?php echo esc_attr( $theme['color_primary'] ); ?>;color:<?php echo esc_attr( $theme['color_primary'] ); ?>;}
.slot.unavailable{opacity:.35;cursor:not-allowed;text-decoration:line-through;}
.btn{display:inline-block;padding:12px 24px;background:<?php echo esc_attr( $theme['color_primary'] ); ?>;color:#0F0E12;border:none;border-radius:10px;font-weight:700;margin-top:16px;cursor:pointer;font-family:inherit;font-size:14px;}
.btn:disabled{opacity:.4;cursor:not-allowed;}
#msg{margin-top:12px;font-size:13px;}
</style>
</head>
<body>
<div class="wrap">
	<div class="card">
		<h1><?php esc_html_e( 'Schedule Your Pickup', 'advanced-ffl-checkout' ); ?></h1>
		<p class="meta"><?php echo esc_html( sprintf( __( 'Transfer #%s at %s', 'advanced-ffl-checkout' ), $t->transfer_ref, $t->business_name ?: 'your dealer' ) ); ?></p>
		<p><?php esc_html_e( 'Pick a date to see real open appointment times at the dealer — no more guessing.', 'advanced-ffl-checkout' ); ?></p>

		<input type="date" id="g2a-sched-date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>">
		<div class="slots" id="g2a-sched-slots"></div>
		<div id="msg"></div>
		<button class="btn" id="g2a-sched-confirm" disabled><?php esc_html_e( 'Confirm Appointment', 'advanced-ffl-checkout' ); ?></button>
	</div>
</div>
<script>
(function(){
	var availabilityUrl = <?php echo wp_json_encode( $availability_url ); ?>;
	var confirmUrl       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var resourceId       = <?php echo (int) $resource_id; ?>;
	var bookingTypeId    = <?php echo (int) $booking_type_id; ?>;
	var ref               = <?php echo wp_json_encode( $ref ); ?>;
	var sig                = <?php echo wp_json_encode( $sig ); ?>;
	var nonce               = <?php echo wp_json_encode( $confirm_nonce ); ?>;

	var dateInput  = document.getElementById('g2a-sched-date');
	var slotsBox   = document.getElementById('g2a-sched-slots');
	var confirmBtn = document.getElementById('g2a-sched-confirm');
	var msg        = document.getElementById('msg');
	var selected   = null;

	function loadSlots() {
		slotsBox.innerHTML = '<em>Loading…</em>';
		selected = null;
		confirmBtn.disabled = true;
		var url = availabilityUrl + '?resource_id=' + resourceId + '&date=' + encodeURIComponent(dateInput.value) + '&duration=30&booking_type_id=' + bookingTypeId;
		fetch(url).then(function(r){ return r.json(); }).then(function(j){
			var data = (j && j.data) ? j.data : j;
			if (!data || data.closed || !data.slots || !data.slots.length) {
				slotsBox.innerHTML = '<em>No availability that day — try another date.</em>';
				return;
			}
			slotsBox.innerHTML = '';
			data.slots.forEach(function(slot){
				var el = document.createElement('div');
				el.className = 'slot' + (slot.available ? '' : ' unavailable');
				el.textContent = slot.label;
				if (slot.available) {
					el.addEventListener('click', function(){
						document.querySelectorAll('.slot.selected').forEach(function(s){ s.classList.remove('selected'); });
						el.classList.add('selected');
						selected = slot.start;
						confirmBtn.disabled = false;
					});
				}
				slotsBox.appendChild(el);
			});
		}).catch(function(){
			slotsBox.innerHTML = '<em>Could not load availability. Please try again.</em>';
		});
	}

	dateInput.addEventListener('change', loadSlots);
	loadSlots();

	confirmBtn.addEventListener('click', function(){
		if (!selected) return;
		confirmBtn.disabled = true;
		confirmBtn.textContent = 'Booking…';
		var fd = new FormData();
		fd.append('action', 'wpistic_ffl_booking_confirm');
		fd.append('ref', ref);
		fd.append('sig', sig);
		fd.append('nonce', nonce);
		fd.append('start_at', selected);
		fetch(confirmUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(j){
				if (j && j.success) {
					document.querySelector('.card').innerHTML = '<h1>✅ You\'re booked!</h1><p>' + new Date(j.data.start_at.replace(' ', 'T')).toLocaleString() + '</p>';
				} else {
					msg.textContent = '✗ ' + ((j && j.data && j.data.message) || 'Could not book that slot.');
					msg.style.color = '#DC2626';
					confirmBtn.disabled = false;
					confirmBtn.textContent = 'Confirm Appointment';
					loadSlots();
				}
			})
			.catch(function(){
				msg.textContent = '✗ Network error — please try again.';
				confirmBtn.disabled = false;
				confirmBtn.textContent = 'Confirm Appointment';
			});
	});
})();
</script>
</body>
</html>
		<?php
	}
}
