<?php
/**
 * Range Guest customer classification.
 *
 * A non-member who pays for lane time is a "Range Guest": a real customer
 * record (WP user + booking history + lifetime stats) with NO membership row.
 * Booking a lane never creates a Memberistic membership — the retired
 * auto-Guest-Pass enrollment is replaced by this segment.
 *
 * Segments (user meta `g2ab_customer_segment`):
 *   range_guest — paid lane/event customer with no active membership
 *   member      — derived live from the membership plugin; when a Range Guest
 *                 later buys a real membership their segment resolves as
 *                 member WITHOUT losing historical booking stats.
 *
 * Stats tracked (user meta): first booking, last booking, successful booking
 * count, lifetime paid amount.
 *
 * @package G2AB\Services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Range_Guest_Service {

	const SEGMENT_META        = 'g2ab_customer_segment';
	const FIRST_BOOKING_META  = 'g2ab_first_booking_at';
	const LAST_BOOKING_META   = 'g2ab_last_booking_at';
	const BOOKING_COUNT_META  = 'g2ab_paid_booking_count';
	const LIFETIME_PAID_META  = 'g2ab_lifetime_paid_amount';

	const SEGMENT_RANGE_GUEST = 'range_guest';
	const SEGMENT_MEMBER      = 'member';

	public static function register() {
		// Runs from the queued paid side effects — i.e. only after a VERIFIED
		// successful payment. Abandoned checkouts never reach this.
		add_action( 'g2ab_payment_succeeded', array( __CLASS__, 'classify_after_payment' ), 20, 3 );
		// $0 member-included lanes count toward booking stats too (no segment
		// change — they are members).
		add_action( 'g2ab_booking_status_changed', array( __CLASS__, 'maybe_track_confirmed_free' ), 20, 3 );
	}

	/**
	 * Current segment for a user, derived live: an active eligible membership
	 * always wins; otherwise the stored segment (range_guest) applies.
	 *
	 * @param int $user_id User id.
	 * @return string Segment slug ('' when unclassified).
	 */
	public static function segment_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return '';
		}
		if ( class_exists( 'G2AB_Checkout_Policy' ) ) {
			$entitlement = G2AB_Checkout_Policy::lane_entitlement( $user_id );
			if ( ! empty( $entitlement['eligible'] ) ) {
				return self::SEGMENT_MEMBER;
			}
		}
		return sanitize_key( (string) get_user_meta( $user_id, self::SEGMENT_META, true ) );
	}

	/**
	 * After a verified successful payment: provision/associate the customer
	 * account (deferred-provisioning helper), classify as range_guest when no
	 * eligible membership exists, and update lifetime stats.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $gateway    Gateway id.
	 * @param array  $context    Payment context.
	 */
	public static function classify_after_payment( $booking_id, $gateway = '', $context = array() ) {
		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1", absint( $booking_id ) ) );
		if ( ! $booking ) {
			return;
		}

		$user_id = (int) $booking->user_id;
		if ( ! $user_id && function_exists( 'g2ab_provision_booking_customer_account' ) ) {
			$user_id = (int) g2ab_provision_booking_customer_account( (int) $booking->id );
		}
		if ( ! $user_id ) {
			return;
		}

		self::track_booking( $user_id, $booking, (float) $booking->paid_amount );

		// Segment: only when the customer has no eligible membership. A member
		// paying for something extra stays a member.
		$eligible = false;
		if ( class_exists( 'G2AB_Checkout_Policy' ) ) {
			$entitlement = G2AB_Checkout_Policy::lane_entitlement( $user_id );
			$eligible    = ! empty( $entitlement['eligible'] );
		}
		if ( ! $eligible ) {
			update_user_meta( $user_id, self::SEGMENT_META, self::SEGMENT_RANGE_GUEST );
		}
	}

	/**
	 * Track $0 member-included confirmations in the same stats series.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $new_status New status.
	 * @param string $old_status Previous status.
	 */
	public static function maybe_track_confirmed_free( $booking_id, $new_status, $old_status = '' ) {
		if ( 'confirmed' !== (string) $new_status ) {
			return;
		}
		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1", absint( $booking_id ) ) );
		if ( ! $booking || (float) $booking->total_amount > 0 || ! (int) $booking->user_id ) {
			return;
		}
		self::track_booking( (int) $booking->user_id, $booking, 0.0 );
	}

	/**
	 * Update first/last/count/lifetime metas idempotently per booking.
	 *
	 * @param int    $user_id User id.
	 * @param object $booking Booking row.
	 * @param float  $paid    Amount paid for this booking.
	 */
	private static function track_booking( $user_id, $booking, $paid ) {
		$tracked = (array) get_user_meta( $user_id, 'g2ab_tracked_booking_ids', true );
		if ( in_array( (int) $booking->id, array_map( 'intval', $tracked ), true ) ) {
			return; // webhook retry — already counted.
		}
		$tracked[] = (int) $booking->id;
		update_user_meta( $user_id, 'g2ab_tracked_booking_ids', array_slice( $tracked, -500 ) );

		$now = current_time( 'mysql' );
		if ( '' === (string) get_user_meta( $user_id, self::FIRST_BOOKING_META, true ) ) {
			update_user_meta( $user_id, self::FIRST_BOOKING_META, $now );
		}
		update_user_meta( $user_id, self::LAST_BOOKING_META, $now );
		update_user_meta( $user_id, self::BOOKING_COUNT_META, 1 + (int) get_user_meta( $user_id, self::BOOKING_COUNT_META, true ) );
		update_user_meta( $user_id, self::LIFETIME_PAID_META, round( (float) get_user_meta( $user_id, self::LIFETIME_PAID_META, true ) + max( 0, (float) $paid ), 2 ) );
	}

	/**
	 * Query Range Guest users (for the admin filter/export).
	 *
	 * @param array $args ['number'=>int,'offset'=>int,'search'=>string]
	 * @return WP_User[]
	 */
	public static function get_range_guests( array $args = array() ) {
		$query = new WP_User_Query( array(
			'number'     => max( 1, min( 500, (int) ( $args['number'] ?? 100 ) ) ),
			'offset'     => max( 0, (int) ( $args['offset'] ?? 0 ) ),
			'search'     => ! empty( $args['search'] ) ? '*' . sanitize_text_field( $args['search'] ) . '*' : '',
			'meta_key'   => self::SEGMENT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => self::SEGMENT_RANGE_GUEST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'orderby'    => 'registered',
			'order'      => 'DESC',
		) );
		return $query->get_results();
	}

	/**
	 * CSV rows for the Range Guests export.
	 *
	 * @return array[] Header row + data rows.
	 */
	public static function export_rows() {
		$rows   = array();
		$rows[] = array( 'User ID', 'Name', 'Email', 'Segment', 'First Booking', 'Last Booking', 'Paid Bookings', 'Lifetime Paid' );
		foreach ( self::get_range_guests( array( 'number' => 500 ) ) as $user ) {
			$rows[] = array(
				(int) $user->ID,
				$user->display_name,
				$user->user_email,
				self::segment_for_user( $user->ID ),
				(string) get_user_meta( $user->ID, self::FIRST_BOOKING_META, true ),
				(string) get_user_meta( $user->ID, self::LAST_BOOKING_META, true ),
				(int) get_user_meta( $user->ID, self::BOOKING_COUNT_META, true ),
				number_format( (float) get_user_meta( $user->ID, self::LIFETIME_PAID_META, true ), 2, '.', '' ),
			);
		}
		return $rows;
	}
}
