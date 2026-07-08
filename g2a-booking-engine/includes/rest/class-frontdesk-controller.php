<?php
/**
 * REST: front-desk endpoints for v1.4.0.
 *
 * All endpoints require `manage_g2ab_bookings` (so the dedicated `g2ab_staff`
 * role can use them) and a verified nonce.
 *
 *   GET  /frontdesk/today                    Today's roster.
 *   GET  /frontdesk/search?q=&limit=         Search by name / email / phone / uuid.
 *   POST /frontdesk/checkin                  Mark booking checked in (idempotent).
 *   POST /frontdesk/verify-waiver            Flip the waiver_verified flag.
 *   POST /frontdesk/collect-payment          Record a cash / terminal / comp payment.
 *   POST /frontdesk/no-show                  Convenience wrapper around the calendar no-show.
 *   POST /frontdesk/note                     Append a staff note.
 *   GET  /frontdesk/receipt/{booking_id}     HTML receipt suitable for window.print().
 *
 * @package G2AB
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Frontdesk_Controller {

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/today', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'today' ),
			'permission_callback' => array( $this, 'permission_read' ),
			'args'                => array(
				'date' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
			),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/search', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'search' ),
			'permission_callback' => array( $this, 'permission_read' ),
			'args'                => array(
				'q'     => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'limit' => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 30 ),
			),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/checkin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'checkin' ),
			'permission_callback' => array( $this, 'permission_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/verify-waiver', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'verify_waiver' ),
			'permission_callback' => array( $this, 'permission_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/collect-payment', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'collect_payment' ),
			'permission_callback' => array( $this, 'permission_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/no-show', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'no_show' ),
			'permission_callback' => array( $this, 'permission_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/note', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'note' ),
			'permission_callback' => array( $this, 'permission_write' ),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/frontdesk/receipt/(?P<booking_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'receipt' ),
			'permission_callback' => array( $this, 'permission_read' ),
		) );
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------- */

	/**
	 * The nonce arrives in the X-WP-Nonce header on fetch() calls, but the
	 * receipt opens in a NEW BROWSER TAB (window.open) which cannot send
	 * custom headers — there it rides as the standard `_wpnonce` query
	 * param. Accept both, exactly like WP core's REST cookie auth does;
	 * header-only checking made every "Print receipt" click die with a 403.
	 */
	private function request_nonce( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		return $nonce;
	}

	public function permission_read( WP_REST_Request $request ) {
		$nonce = $this->request_nonce( $request );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid or missing nonce.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'Forbidden.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function permission_write( WP_REST_Request $request ) {
		$nonce = $this->request_nonce( $request );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'g2ab_invalid_nonce', __( 'Invalid or missing nonce.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return new WP_Error( 'g2ab_forbidden', __( 'Forbidden.', 'g2a-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Read endpoints
	 * ------------------------------------------------------------------- */

	public function today( WP_REST_Request $request ) {
		global $wpdb;
		$date_param = (string) $request->get_param( 'date' );
		$date       = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_param ) ? $date_param : current_time( 'Y-m-d' );

		$start_sql = $date . ' 00:00:00';
		$end_sql   = $date . ' 23:59:59';

		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$bt       = $wpdb->prefix . 'g2ab_booking_types';
		$rt       = $wpdb->prefix . 'g2ab_resources';
		$ci       = $wpdb->prefix . 'g2ab_checkins';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, bt.name AS booking_type_name, bt.category AS booking_type_category,
			        r.name AS resource_name, r.type AS resource_type,
			        c.id AS checkin_id, c.checked_in_at AS checkin_at,
			        c.waiver_verified AS waiver_verified, c.payment_verified AS payment_verified,
			        c.note AS checkin_note
			   FROM {$bookings} b
			   LEFT JOIN {$bt} bt ON bt.id = b.booking_type_id
			   LEFT JOIN {$rt} r  ON r.id  = b.resource_id
			   LEFT JOIN {$ci} c  ON c.booking_id = b.id
			  WHERE b.start_at BETWEEN %s AND %s
			  ORDER BY b.start_at ASC, r.sort_order ASC
			  LIMIT 500",
			$start_sql,
			$end_sql
		) );

		$totals = array(
			'total'       => 0,
			'checked_in'  => 0,
			'pending'     => 0,
			'no_show'     => 0,
			'cancelled'   => 0,
			'completed'   => 0,
		);
		$payload = array();
		foreach ( $rows as $row ) {
			$totals['total']++;
			if ( ! empty( $row->checkin_id ) )                     $totals['checked_in']++;
			if ( in_array( $row->status, array( 'pending', 'reserved', 'confirmed', 'paid' ), true ) ) $totals['pending']++;
			if ( 'no_show' === $row->status )                      $totals['no_show']++;
			if ( 'cancelled' === $row->status )                    $totals['cancelled']++;
			if ( 'completed' === $row->status )                    $totals['completed']++;
			$payload[] = $this->shape_booking( $row );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'date'     => $date,
				'totals'   => $totals,
				'bookings' => $payload,
			),
		) );
	}

	public function search( WP_REST_Request $request ) {
		global $wpdb;
		$q     = trim( (string) $request->get_param( 'q' ) );
		$limit = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		if ( '' === $q ) {
			return rest_ensure_response( array( 'success' => true, 'data' => array() ) );
		}

		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$bt       = $wpdb->prefix . 'g2ab_booking_types';
		$rt       = $wpdb->prefix . 'g2ab_resources';
		$ci       = $wpdb->prefix . 'g2ab_checkins';

		$like = '%' . $wpdb->esc_like( $q ) . '%';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.*, bt.name AS booking_type_name, bt.category AS booking_type_category,
			        r.name AS resource_name, r.type AS resource_type,
			        c.id AS checkin_id, c.checked_in_at AS checkin_at,
			        c.waiver_verified AS waiver_verified, c.payment_verified AS payment_verified,
			        c.note AS checkin_note
			   FROM {$bookings} b
			   LEFT JOIN {$bt} bt ON bt.id = b.booking_type_id
			   LEFT JOIN {$rt} r  ON r.id  = b.resource_id
			   LEFT JOIN {$ci} c  ON c.booking_id = b.id
			  WHERE b.customer_name  LIKE %s
			     OR b.customer_email LIKE %s
			     OR b.customer_phone LIKE %s
			     OR b.uuid           = %s
			  ORDER BY b.start_at DESC
			  LIMIT %d",
			$like, $like, $like, $q, $limit
		) );

		$payload = array();
		foreach ( $rows as $row ) {
			$payload[] = $this->shape_booking( $row );
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $payload ) );
	}

	/**
	 * Branded, print-ready HTML receipt, opened in its own tab.
	 *
	 * Emitted RAW (echo + exit) on purpose: returning the markup through
	 * WP_REST_Response would run it through the REST server's JSON encoder
	 * — the tab would render one giant quoted string instead of a page,
	 * which is exactly the bug this replaces. The print dialog opens
	 * automatically once the page (logo included) finishes loading.
	 */
	public function receipt( WP_REST_Request $request ) {
		global $wpdb;
		$booking_id = (int) $request['booking_id'];
		if ( $booking_id < 1 ) {
			return new WP_Error( 'g2ab_bad_id', __( 'Invalid booking.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$bt       = $wpdb->prefix . 'g2ab_booking_types';
		$rt       = $wpdb->prefix . 'g2ab_resources';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.*, bt.name AS booking_type_name, r.name AS resource_name
			   FROM {$bookings} b
			   LEFT JOIN {$bt} bt ON bt.id = b.booking_type_id
			   LEFT JOIN {$rt} r  ON r.id  = b.resource_id
			  WHERE b.id = %d",
			$booking_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}

		$biz_name  = get_option( 'g2ab_business_name', get_bloginfo( 'name' ) );
		$biz_phone = get_option( 'g2ab_business_phone', '' );
		$biz_addr  = get_option( 'g2ab_business_address', '' );
		$currency  = $row->currency ?: get_option( 'g2ab_currency', 'USD' );
		$dt_format = get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' );
		$balance   = max( 0, (float) $row->total_amount - (float) $row->paid_amount );

		// Brand logo: theme custom logo first, then a filter for overrides.
		$logo_url = '';
		if ( function_exists( 'get_theme_mod' ) ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$logo_url = (string) wp_get_attachment_image_url( $logo_id, 'medium' );
			}
		}
		/** Override the receipt logo (e.g. a print-optimized black version). */
		$logo_url = (string) apply_filters( 'g2ab_receipt_logo_url', $logo_url );
		/** Override the receipt footer line. */
		$footer   = (string) apply_filters( 'g2ab_receipt_footer_text', __( 'Thank you — shoot smart, carry safe.', 'g2a-booking' ) );
		$site     = wp_parse_url( home_url(), PHP_URL_HOST );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt — <?php echo esc_html( substr( (string) $row->uuid, 0, 8 ) ); ?></title>
<style>
*{box-sizing:border-box;}
body{font-family:-apple-system,'Segoe UI',Arial,sans-serif;font-size:14px;color:#141317;background:#f2f0eb;margin:0;padding:24px 12px;}
.receipt{max-width:380px;margin:0 auto;background:#fff;border:1px solid #d8d4cb;}
.brand{background:#141317;color:#fff;text-align:center;padding:20px 16px 16px;}
.brand img{max-width:150px;max-height:64px;display:block;margin:0 auto 8px;}
.brand h1{font-size:20px;margin:0;text-transform:uppercase;letter-spacing:.14em;font-weight:800;}
.brand .tag{color:#C9A84C;font-size:10px;letter-spacing:.24em;text-transform:uppercase;margin-top:4px;}
.brand .muted{color:#b9b7bf;font-size:11px;margin-top:6px;line-height:1.5;}
.body{padding:16px;}
.rule{border-top:2px solid #C9A84C;margin:12px 0;}
.dash{border-top:1px dashed #9a968c;margin:10px 0;}
.row{display:flex;justify-content:space-between;gap:12px;margin:5px 0;}
.row .l{color:#5d5b64;}
.mono{font-family:'Courier New',monospace;}
.total{font-size:17px;font-weight:800;}
.paid-pill{display:inline-block;border:2px solid #141317;padding:3px 12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;font-size:11px;margin-top:6px;}
.foot{text-align:center;color:#5d5b64;font-size:11px;padding:0 16px 18px;line-height:1.6;}
.foot .thanks{color:#141317;font-weight:700;font-size:12px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px;}
.actions{text-align:center;margin:20px auto;max-width:380px;}
.btn{display:inline-block;padding:10px 22px;background:#141317;color:#fff;text-decoration:none;border:none;border-radius:3px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;font-size:12px;cursor:pointer;}
.btn--ghost{background:transparent;color:#141317;border:1px solid #141317;margin-left:8px;}
@media print{
  body{background:#fff;padding:0;}
  .receipt{border:none;max-width:none;}
  .brand{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .actions{display:none;}
  @page{margin:6mm;}
}
</style>
</head><body>
<div class="receipt">
  <div class="brand">
    <?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $biz_name ); ?>"><?php endif; ?>
    <h1><?php echo esc_html( $biz_name ); ?></h1>
    <div class="tag"><?php esc_html_e( 'Indoor Range · FFL · Training', 'g2a-booking' ); ?></div>
    <div class="muted">
      <?php if ( $biz_addr )  : ?><?php echo esc_html( $biz_addr ); ?><br><?php endif; ?>
      <?php if ( $biz_phone ) : ?><?php echo esc_html( $biz_phone ); ?> · <?php endif; ?><?php echo esc_html( $site ); ?>
    </div>
  </div>
  <div class="body">
    <div class="row"><span class="l"><?php esc_html_e( 'Receipt', 'g2a-booking' ); ?></span><span class="mono">#<?php echo esc_html( strtoupper( substr( (string) $row->uuid, 0, 8 ) ) ); ?></span></div>
    <div class="row"><span class="l"><?php esc_html_e( 'Issued', 'g2a-booking' ); ?></span><span><?php echo esc_html( date_i18n( $dt_format ) ); ?></span></div>
    <div class="row"><span class="l"><?php esc_html_e( 'Customer', 'g2a-booking' ); ?></span><span><?php echo esc_html( $row->customer_name ); ?></span></div>
    <?php if ( $row->customer_phone ) : ?><div class="row"><span class="l"><?php esc_html_e( 'Phone', 'g2a-booking' ); ?></span><span class="mono"><?php echo esc_html( $row->customer_phone ); ?></span></div><?php endif; ?>
    <div class="rule"></div>
    <div class="row"><strong><?php echo esc_html( $row->booking_type_name ?: __( 'Booking', 'g2a-booking' ) ); ?></strong><strong class="mono">$<?php echo esc_html( number_format( (float) $row->total_amount, 2 ) ); ?></strong></div>
    <?php if ( $row->resource_name ) : ?><div class="row"><span class="l"><?php echo esc_html( $row->resource_name ); ?></span><span class="l">×<?php echo (int) $row->party_size; ?></span></div><?php endif; ?>
    <div class="row"><span class="l"><?php echo esc_html( date_i18n( $dt_format, strtotime( (string) $row->start_at ) ) ); ?></span><span class="l"><?php echo (int) $row->duration_min; ?> <?php esc_html_e( 'min', 'g2a-booking' ); ?></span></div>
    <div class="dash"></div>
    <div class="row total"><span><?php esc_html_e( 'Total', 'g2a-booking' ); ?></span><span class="mono">$<?php echo esc_html( number_format( (float) $row->total_amount, 2 ) ); ?> <?php echo esc_html( $currency ); ?></span></div>
    <div class="row"><span class="l"><?php esc_html_e( 'Paid', 'g2a-booking' ); ?></span><span class="mono">$<?php echo esc_html( number_format( (float) $row->paid_amount, 2 ) ); ?></span></div>
    <div class="row"><span class="l"><?php esc_html_e( 'Balance due', 'g2a-booking' ); ?></span><span class="mono">$<?php echo esc_html( number_format( $balance, 2 ) ); ?></span></div>
    <?php if ( $balance <= 0 && (float) $row->total_amount > 0 ) : ?>
      <div style="text-align:center;"><span class="paid-pill"><?php esc_html_e( 'Paid in full', 'g2a-booking' ); ?></span></div>
    <?php endif; ?>
  </div>
  <div class="foot">
    <div class="thanks"><?php echo esc_html( $footer ); ?></div>
    <?php esc_html_e( 'Booking ref:', 'g2a-booking' ); ?> <span class="mono"><?php echo esc_html( (string) $row->uuid ); ?></span>
  </div>
</div>
<div class="actions">
  <button class="btn" onclick="window.print()"><?php esc_html_e( 'Print', 'g2a-booking' ); ?></button>
  <button class="btn btn--ghost" onclick="window.close()"><?php esc_html_e( 'Close', 'g2a-booking' ); ?></button>
</div>
<script>
/* Pop the print dialog automatically once everything (logo included) has
   loaded. The page stays open afterwards so staff can reprint or close. */
window.addEventListener('load', function () {
  setTimeout(function () { window.focus(); window.print(); }, 250);
});
</script>
</body></html>
		<?php
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Write endpoints — thin wrappers over G2AB_Checkin_Service.
	 * ------------------------------------------------------------------- */

	public function checkin( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$result = G2AB_Checkin_Service::check_in( $booking_id, array(
			'waiver_verified'  => (bool) $request->get_param( 'waiver_verified' ),
			'payment_verified' => (bool) $request->get_param( 'payment_verified' ),
			'note'             => (string) $request->get_param( 'note' ),
		), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	public function verify_waiver( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$result = G2AB_Checkin_Service::check_in( $booking_id, array(
			'waiver_verified' => true,
			'note'            => (string) $request->get_param( 'note' ),
		), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	public function collect_payment( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$amount     = (float) $request->get_param( 'amount' );
		$method     = sanitize_key( (string) $request->get_param( 'method' ) );
		$note       = (string) $request->get_param( 'note' );
		$result     = G2AB_Checkin_Service::collect_payment( $booking_id, $amount, $method, $note, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	public function no_show( WP_REST_Request $request ) {
		global $wpdb;
		$booking_id = (int) $request->get_param( 'booking_id' );
		if ( $booking_id < 1 ) {
			return new WP_Error( 'g2ab_bad_id', __( 'Invalid booking.', 'g2a-booking' ), array( 'status' => 400 ) );
		}
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d", $booking_id ) );
		if ( ! $booking ) {
			return new WP_Error( 'g2ab_not_found', __( 'Booking not found.', 'g2a-booking' ), array( 'status' => 404 ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array( 'status' => 'no_show', 'updated_at' => $now ),
			array( 'id' => $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( class_exists( 'G2AB_Booking_Activity' ) ) {
			G2AB_Booking_Activity::log( $booking_id, 'no_show', array( 'status' => $booking->status ), array( 'status' => 'no_show' ), 'front desk', get_current_user_id() );
		}
		do_action( 'g2ab_booking_status_changed', $booking_id, 'no_show', $booking->status );
		do_action( 'g2ab_booking_no_show', $booking_id );
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'id' => $booking_id, 'status' => 'no_show' ) ) );
	}

	public function note( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$note       = (string) $request->get_param( 'note' );
		$result     = G2AB_Checkin_Service::add_note( $booking_id, $note, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $result ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private function shape_booking( $row ) {
		$status_label = '';
		if ( class_exists( 'G2AB_Booking_Statuses' ) ) {
			$labels       = G2AB_Booking_Statuses::all();
			$status_label = $labels[ $row->status ] ?? $row->status;
		}
		$balance = max( 0, round( (float) $row->total_amount - (float) $row->paid_amount, 2 ) );
		return array(
			'id'                => (int) $row->id,
			'uuid'              => $row->uuid,
			'status'            => $row->status,
			'status_label'      => $status_label ?: $row->status,
			'status_color'      => class_exists( 'G2AB_Booking_Statuses' ) ? G2AB_Booking_Statuses::color( $row->status ) : '#6B7280',
			'customer_name'     => $row->customer_name,
			'customer_email'    => $row->customer_email,
			'customer_phone'    => $row->customer_phone,
			'party_size'        => (int) $row->party_size,
			'start_at'          => $row->start_at,
			'end_at'            => $row->end_at,
			'duration_min'      => (int) $row->duration_min,
			'resource_id'       => (int) $row->resource_id,
			'resource_name'     => $row->resource_name,
			'resource_type'     => $row->resource_type,
			'booking_type_name' => $row->booking_type_name,
			'category'          => $row->booking_type_category,
			'total_amount'      => (float) $row->total_amount,
			'paid_amount'       => (float) $row->paid_amount,
			'balance'           => $balance,
			'currency'          => $row->currency,
			'payment_mode'      => $row->payment_mode,
			'waiver_signed'     => (bool) $row->waiver_signed,
			'waiver_verified'   => isset( $row->waiver_verified ) ? (bool) $row->waiver_verified : false,
			'payment_verified'  => isset( $row->payment_verified ) ? (bool) $row->payment_verified : false,
			'is_checked_in'     => ! empty( $row->checkin_id ),
			'checkin_at'        => $row->checkin_at ?? null,
			'checkin_note'      => $row->checkin_note ?? '',
			'notes'             => $row->notes,
			'source'            => $row->source,
		);
	}
}
