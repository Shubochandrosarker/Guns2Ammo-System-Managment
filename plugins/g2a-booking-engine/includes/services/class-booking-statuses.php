<?php
/**
 * Canonical booking status set for v1.3.0+.
 *
 * Adds an explicit allow-list for the new statuses without dropping the
 * existing values — anything legacy still validates and renders.
 *
 *   pending    Booking started but not confirmed.
 *   reserved   Slot held temporarily (waiting on payment / staff).
 *   confirmed  Booking confirmed (paid in full or no payment required).
 *   paid       Payment completed (online).
 *   completed  Customer attended.
 *   cancelled  Booking cancelled.
 *   no_show    Customer did not attend.
 *   refunded   Payment refunded after the fact.
 *   expired    Hold expired / payment never completed.
 *
 * @package G2AB\Services
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Booking_Statuses {

	const PENDING            = 'pending';
	const RESERVED           = 'reserved';
	const CONFIRMED          = 'confirmed';
	const PAID               = 'paid';
	const COMPLETED          = 'completed';
	const CANCELLED          = 'cancelled';
	const NO_SHOW            = 'no_show';
	const REFUNDED           = 'refunded';
	const PARTIALLY_REFUNDED = 'partially_refunded';
	const PAYMENT_FAILED     = 'payment_failed';
	const EXPIRED            = 'expired';

	/**
	 * All statuses, including legacy values that still appear in pre-1.3.0 data.
	 *
	 * @return array<string,string> slug → human label
	 */
	public static function all() {
		return array(
			self::PENDING            => __( 'Pending',   'g2a-booking' ),
			self::RESERVED           => __( 'Reserved',  'g2a-booking' ),
			self::CONFIRMED          => __( 'Confirmed', 'g2a-booking' ),
			self::PAID               => __( 'Paid',      'g2a-booking' ),
			self::COMPLETED          => __( 'Completed', 'g2a-booking' ),
			self::CANCELLED          => __( 'Cancelled', 'g2a-booking' ),
			self::NO_SHOW            => __( 'No Show',   'g2a-booking' ),
			self::REFUNDED           => __( 'Refunded',  'g2a-booking' ),
			self::PARTIALLY_REFUNDED => __( 'Partially Refunded', 'g2a-booking' ),
			self::PAYMENT_FAILED     => __( 'Payment Failed', 'g2a-booking' ),
			self::EXPIRED            => __( 'Expired',   'g2a-booking' ),
		);
	}

	/**
	 * Customer/staff-facing label that names the PAYMENT state explicitly.
	 *
	 * `pending` is stored for historical compatibility but always means
	 * "checkout hold, payment required" — never a confirmed reservation — so
	 * every human-facing surface says so.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function payment_label( $status ) {
		$map = array(
			self::PENDING        => __( 'Pending Payment', 'g2a-booking' ),
			self::PAYMENT_FAILED => __( 'Payment Failed', 'g2a-booking' ),
			self::EXPIRED        => __( 'Expired (unpaid)', 'g2a-booking' ),
		);
		$all = self::all();
		return $map[ (string) $status ] ?? ( $all[ (string) $status ] ?? ucwords( str_replace( '_', ' ', (string) $status ) ) );
	}

	/**
	 * Statuses that should still occupy a calendar slot (block the lane).
	 * PARTIALLY_REFUNDED bookings are still attending — the seat stays taken.
	 */
	public static function blocking() {
		return array( self::PENDING, self::RESERVED, self::CONFIRMED, self::PAID, self::PARTIALLY_REFUNDED );
	}

	/**
	 * Statuses considered "done" — booking won't move further on its own.
	 */
	public static function terminal() {
		return array( self::COMPLETED, self::CANCELLED, self::NO_SHOW, self::REFUNDED, self::EXPIRED );
	}

	/**
	 * Statuses that mean "payment is required and has not succeeded".
	 * These never occupy an operational roster.
	 *
	 * @return string[]
	 */
	public static function unpaid() {
		return array( self::PENDING, self::PAYMENT_FAILED, self::EXPIRED );
	}

	public static function is_valid( $status ) {
		return array_key_exists( (string) $status, self::all() );
	}

	public static function is_blocking( $status ) {
		return in_array( (string) $status, self::blocking(), true );
	}

	public static function is_terminal( $status ) {
		return in_array( (string) $status, self::terminal(), true );
	}

	/**
	 * Map a status to a calendar/admin badge color (CSS hex).
	 *
	 * These are always painted as a solid fill with white text on top (see
	 * .g2ab-fd-row__status in class-frontdesk-view.php, the front-desk /
	 * staff-console roster badge). PENDING/RESERVED/CONFIRMED/PAID used to be
	 * light/mid-tone swatches (gray-400, amber-500, blue-500, emerald-500)
	 * that only gave white text ~2.1-3.7:1 contrast, failing WCAG AA's 4.5:1.
	 * Darkened one step each (same hue family, still visually distinct) so
	 * every status clears 4.5:1 with white text; the other five were already
	 * dark enough.
	 */
	public static function color( $status ) {
		$map = array(
			self::PENDING            => '#4B5563', // slate gray (was #9CA3AF — 2.54:1 with white text)
			self::RESERVED           => '#B45309', // amber, darkened (was #F59E0B — 2.15:1 with white text)
			self::CONFIRMED          => '#2563EB', // blue, darkened (was #3B82F6 — 3.68:1 with white text)
			self::PAID               => '#047857', // green, darkened (was #10B981 — 2.54:1 with white text)
			self::COMPLETED          => '#0F2044', // navy
			self::CANCELLED          => '#6B7280', // dark gray
			self::NO_SHOW            => '#C62828', // red
			self::REFUNDED           => '#7C3AED', // purple
			self::PARTIALLY_REFUNDED => '#6D28D9', // deep purple — still attending, money partially returned
			self::PAYMENT_FAILED     => '#B91C1C', // dark red — payment attempted and declined
			self::EXPIRED            => '#92400E', // brown
		);
		return $map[ (string) $status ] ?? '#6B7280';
	}
}
