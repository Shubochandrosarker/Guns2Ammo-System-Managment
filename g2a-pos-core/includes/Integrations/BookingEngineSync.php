<?php

namespace G2A\POS\Integrations;

use G2A\POS\Database\CustomerProfileRepository;
use G2A\POS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * G2A Booking Engine (g2a-booking-engine plugin) bridge.
 *
 * Read-only view of the engine's bookings (wp_g2ab_bookings joined to
 * wp_g2ab_resources) so the POS Lane Reservations screen shows web bookings
 * alongside POS-native reservations, plus a listener on g2ab_booking_created
 * that makes sure the booking customer exists in the POS CRM profile table.
 *
 * The engine's tables are never written to from here.
 */
final class BookingEngineSync {

	public static function boot(): void {
		add_action( 'g2ab_booking_created', array( self::class, 'on_booking_created' ), 20, 2 );
	}

	public static function is_active(): bool {
		static $active = null;
		if ( $active === null ) {
			if ( defined( 'G2AB_VERSION' ) || class_exists( '\\G2AB_Plugin' ) ) {
				$active = true;
			} else {
				global $wpdb;
				$table  = $wpdb->prefix . 'g2ab_bookings';
				$active = isset( $wpdb ) && (bool) $wpdb->get_var(
					$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
				);
			}
		}
		return $active;
	}

	/**
	 * Engine bookings overlapping a calendar day, normalised to the same
	 * shape as POS lane reservations (read-only, source 'booking-engine').
	 */
	public static function bookings_for_day( string $date ): array {
		global $wpdb;
		if ( ! self::is_active() || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array();
		}
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$resource = $wpdb->prefix . 'g2ab_resources';
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.uuid, b.customer_name, b.customer_email, b.customer_phone,
				        b.start_at, b.end_at, b.party_size, b.status, b.waiver_signed,
				        r.name AS resource_name, r.type AS resource_type
				 FROM {$bookings} b
				 LEFT JOIN {$resource} r ON r.id = b.resource_id
				 WHERE b.start_at BETWEEN %s AND %s
				 ORDER BY b.start_at ASC
				 LIMIT 500",
				$date . ' 00:00:00',
				$date . ' 23:59:59'
			),
			ARRAY_A
		) ?: array();

		return array_map(
			static fn( array $r ): array => array(
				'id'            => (int) $r['id'],
				'uuid'          => (string) $r['uuid'],
				'lane_code'     => (string) ( $r['resource_name'] ?: '—' ),
				'resource_type' => (string) ( $r['resource_type'] ?? '' ),
				'customer_name' => (string) $r['customer_name'],
				'party_size'    => (int) $r['party_size'],
				'starts_at'     => (string) $r['start_at'],
				'ends_at'       => (string) $r['end_at'],
				'status'        => (string) $r['status'],
				'waiver_signed' => (bool) $r['waiver_signed'],
				'source'        => 'booking-engine',
				'read_only'     => true,
			),
			$rows
		);
	}

	/** Upcoming engine bookings for one customer (CRM detail panel). */
	public static function bookings_for_customer( ?int $user_id, ?string $email, int $days_ahead = 30, int $limit = 10 ): array {
		global $wpdb;
		if ( ! self::is_active() || ( ! $user_id && ! $email ) ) {
			return array();
		}
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$resource = $wpdb->prefix . 'g2ab_resources';
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.start_at, b.end_at, b.status, b.party_size, r.name AS resource_name
				 FROM {$bookings} b
				 LEFT JOIN {$resource} r ON r.id = b.resource_id
				 WHERE ( b.user_id = %d OR ( %s <> '' AND b.customer_email = %s ) )
				   AND b.status NOT IN ('cancelled','no_show','expired')
				   AND b.start_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
				   AND b.start_at <= DATE_ADD(NOW(), INTERVAL %d DAY)
				 ORDER BY b.start_at ASC
				 LIMIT %d",
				(int) $user_id,
				(string) $email,
				(string) $email,
				max( 1, min( 365, $days_ahead ) ),
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		) ?: array();

		return array_map(
			static fn( array $r ): array => array(
				'id'        => (int) $r['id'],
				'title'     => (string) ( $r['resource_name'] ?: 'Booking' ),
				'starts_at' => (string) $r['start_at'],
				'ends_at'   => (string) $r['end_at'],
				'status'    => (string) $r['status'],
				'party'     => (int) $r['party_size'],
				'source'    => 'booking-engine',
			),
			$rows
		);
	}

	/**
	 * g2ab_booking_created listener — make sure the booking customer is
	 * represented in the POS CRM (g2a_customer_profiles keyed by WP user id).
	 * We never auto-create WP accounts; if the booking is from a guest with
	 * no matching user we leave it alone.
	 */
	public static function on_booking_created( $booking_id, $context = array() ): void {
		global $wpdb;
		try {
			if ( ! self::is_active() ) {
				return;
			}
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT user_id, customer_email FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d",
					(int) $booking_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				return;
			}
			$user_id = (int) ( $row['user_id'] ?? 0 );
			if ( ! $user_id && ! empty( $row['customer_email'] ) && function_exists( 'get_user_by' ) ) {
				$user = get_user_by( 'email', (string) $row['customer_email'] );
				if ( $user ) {
					$user_id = (int) $user->ID;
				}
			}
			if ( $user_id <= 0 ) {
				return;
			}
			$profiles = new CustomerProfileRepository();
			if ( ! $profiles->find( $user_id ) ) {
				$profiles->recompute_lifetime( $user_id );
			}
		} catch ( \Throwable $e ) {
			Logger::exception( 'Booking engine CRM upsert failed', $e, array( 'booking_id' => (int) $booking_id ) );
		}
	}
}
