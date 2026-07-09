<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;

/**
 * Read-only scan over the G2A Booking Engine reservations.
 *
 * Lets the agent answer "today's lane bookings", "find bookings for
 * jane@x.com", "what's coming up?" by querying g2ab_bookings joined to
 * resources + booking types. A no-op (empty result, never a fatal) when the
 * Booking Engine isn't installed.
 */
final class BookingLookupTool {

	public static function register(): void {
		ToolRegistry::register(
			'booking.lookup',
			array(
				'description' => 'Look up G2A Booking Engine reservations by customer name/email, or list upcoming/today/past bookings. Returns customer, resource (lane), booking type, start/end time, party size, status and payment status. Use this to scan booking details.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'q'     => array(
							'type'        => 'string',
							'description' => 'Customer name or email fragment. Leave blank to list bookings in the chosen window.',
						),
						'when'  => array(
							'type'        => 'string',
							'description' => 'Time window: upcoming (default), today, or past.',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max rows (default 15, max 50).',
						),
					),
					'required'   => array(),
				),
			),
			array( self::class, 'run' ),
			'g2a_pos_access',
			ActionPolicy::SILENT
		);
	}

	public static function run( array $args ): array {
		global $wpdb;

		$bookings = $wpdb->prefix . 'g2ab_bookings';
		if ( ! self::table( $bookings ) ) {
			return array(
				'ok'      => false,
				'error'   => 'booking_engine_not_installed',
				'count'   => 0,
				'matches' => array(),
			);
		}

		$resources = $wpdb->prefix . 'g2ab_resources';
		$types     = $wpdb->prefix . 'g2ab_booking_types';

		$q     = trim( (string) ( $args['q'] ?? '' ) );
		$when  = sanitize_key( (string) ( $args['when'] ?? 'upcoming' ) );
		$limit = max( 1, min( 50, (int) ( $args['limit'] ?? 15 ) ) );
		$now   = current_time( 'mysql' );

		$sql    = "SELECT b.*, r.name AS resource_name, bt.name AS booking_type_name
			FROM {$bookings} b
			LEFT JOIN {$resources} r ON r.id = b.resource_id
			LEFT JOIN {$types} bt ON bt.id = b.booking_type_id";
		$where  = array();
		$params = array();

		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(b.customer_name LIKE %s OR b.customer_email LIKE %s)';
			array_push( $params, $like, $like );
		}

		$order = 'b.start_at ASC';
		if ( 'today' === $when ) {
			$where[]  = 'DATE(b.start_at) = %s';
			$params[] = current_time( 'Y-m-d' );
		} elseif ( 'past' === $when ) {
			$where[]  = 'b.start_at < %s';
			$params[] = $now;
			$order    = 'b.start_at DESC';
		} else {
			$where[]  = 'b.start_at >= %s';
			$params[] = $now;
		}

		// $where always has at least one clause (the when-branch above is
		// exhaustive), so this is never empty — no guard needed.
		$sql     .= ' WHERE ' . implode( ' AND ', $where );
		$sql     .= " ORDER BY {$order} LIMIT %d";
		$params[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();

		return array(
			'ok'      => true,
			'count'   => count( $rows ),
			'matches' => $rows,
		);
	}

	private static function table( string $full ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) );
	}
}
