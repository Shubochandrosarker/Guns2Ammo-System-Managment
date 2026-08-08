<?php

namespace G2A\POS\Ai\Tools;

use G2A\POS\Ai\ActionPolicy;
use G2A\POS\Ai\ToolRegistry;

/**
 * Read-only scan over the Memberistic membership tables.
 *
 * Lets the agent answer "what is John's membership?", "show active members",
 * "whose plan renews this month?" by querying memberistic_memberships joined
 * to memberistic_people + memberistic_plans. A no-op (empty result, never a
 * fatal) when Memberistic isn't installed.
 */
final class MembershipLookupTool {

	public static function register(): void {
		ToolRegistry::register(
			'membership.lookup',
			array(
				'description' => 'Look up Memberistic memberships by member name, email or phone, or list members by status. Returns plan, status, billing cycle, renewal/expiry date and member-since. Use this to scan membership details.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'q'      => array(
							'type'        => 'string',
							'description' => 'Name, email or phone fragment. Leave blank (with an optional status) to list members.',
						),
						'status' => array(
							'type'        => 'string',
							'description' => 'Optional status filter: active | trial | expired | cancelled | pending | comped.',
						),
						'limit'  => array(
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

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		if ( ! self::table( $memberships ) ) {
			return array(
				'ok'      => false,
				'error'   => 'memberistic_not_installed',
				'count'   => 0,
				'matches' => array(),
			);
		}

		$people     = $wpdb->prefix . 'memberistic_people';
		$plans      = $wpdb->prefix . 'memberistic_plans';
		$has_people = self::table( $people );
		$has_plans  = self::table( $plans );

		$q      = trim( (string) ( $args['q'] ?? '' ) );
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$limit  = max( 1, min( 50, (int) ( $args['limit'] ?? 15 ) ) );

		$select  = 'SELECT m.id, m.status, m.billing_cycle, m.start_date, m.renewal_date, m.end_date, m.created_at';
		$select .= $has_plans ? ', pl.name AS plan_name' : ', m.plan_id AS plan_name';
		$select .= $has_people ? ', p.full_name, p.email, p.phone' : '';

		$from = " FROM {$memberships} m";
		if ( $has_plans ) {
			$from .= " LEFT JOIN {$plans} pl ON pl.id = m.plan_id";
		}
		if ( $has_people ) {
			$from .= " LEFT JOIN {$people} p ON p.membership_id = m.id AND p.role = 'primary'";
		}

		$where  = array();
		$params = array();
		if ( '' !== $q && $has_people ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(p.full_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s)';
			array_push( $params, $like, $like, $like );
		}
		if ( '' !== $status ) {
			$where[]  = 'm.status = %s';
			$params[] = $status;
		}

		$sql = $select . $from;
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql     .= ' ORDER BY m.id DESC LIMIT %d';
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
