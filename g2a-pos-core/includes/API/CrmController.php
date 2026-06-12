<?php

namespace G2A\POS\API;

use G2A\POS\Database\CustomerProfileRepository;
use G2A\POS\Database\CustomerRepository;
use G2A\POS\Database\LoyaltyRepository;
use G2A\POS\Integrations\RangeMembership;
use WP_REST_Request;

final class CrmController {

	public static function search( WP_REST_Request $request ) {
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		return array( 'items' => ( new CustomerRepository() )->search( $q, 25 ) );
	}

	public static function profile( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id <= 0 ) {
			return new \WP_Error( 'invalid_id', 'Customer ID required', array( 'status' => 400 ) );
		}
		$user = get_userdata( $id );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', 'Customer not found', array( 'status' => 404 ) );
		}
		$profile_repo = new CustomerProfileRepository();
		$profile      = $profile_repo->find( $id ) ?? $profile_repo->recompute_lifetime( $id );
		$loyalty      = ( new LoyaltyRepository() )->balance( $id );

		return array(
			'id'            => $id,
			'name'          => $user->display_name,
			'email'         => $user->user_email,
			'phone'         => (string) get_user_meta( $id, 'billing_phone', true ),
			'address'       => array(
				'address_1' => (string) get_user_meta( $id, 'billing_address_1', true ),
				'city'      => (string) get_user_meta( $id, 'billing_city', true ),
				'state'     => (string) get_user_meta( $id, 'billing_state', true ),
				'postcode'  => (string) get_user_meta( $id, 'billing_postcode', true ),
			),
			'profile'       => $profile,
			'loyalty'       => $loyalty,
			'history'       => $profile_repo->purchase_history( $id, 25 ),
			'membership'    => RangeMembership::status_for_customer( $id ),
			'bookings'      => RangeMembership::bookings_for_customer( $id ),
			'waiver'        => self::waiver_for_user( $id ),
			'ffl_transfers' => self::ffl_transfers_for_user( $id, (string) $user->user_email ),
		);
	}

	public static function update_profile( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$body    = $request->get_json_params() ?: array();
		$repo    = new CustomerProfileRepository();
		$profile = $repo->upsert( $id, $body );
		return array( 'profile' => $profile );
	}

	public static function recompute( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return array( 'profile' => ( new CustomerProfileRepository() )->recompute_lifetime( $id ) );
	}

	/**
	 * Latest waiver state from the Memberistic people table, matched on the
	 * WP user id. Null when the plugin (or its table) is absent or the user
	 * has no waiver on file.
	 */
	private static function waiver_for_user( int $user_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'memberistic_people';
		if ( ! self::table_exists( $table ) ) {
			return null;
		}
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT waiver_status, waiver_signed_at, waiver_expires_at
                     FROM {$table}
                     WHERE wp_user_id = %d AND waiver_status IS NOT NULL AND waiver_status <> ''
                     ORDER BY waiver_signed_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id
				),
				ARRAY_A
			);
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! $row ) {
			return null;
		}
		return array(
			'status'     => (string) $row['waiver_status'],
			'signed_at'  => $row['waiver_signed_at'] ?? null,
			'expires_at' => $row['waiver_expires_at'] ?? null,
		);
	}

	/**
	 * FFL transfers from the Advanced FFL Checkout plugin, matched by user id
	 * or email. Empty list when that plugin's tables are absent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function ffl_transfers_for_user( int $user_id, string $email ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpistic_ffl_transfers';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, transfer_ref, status, item_description, item_sku, item_make,
                            item_model, item_caliber, item_type, dealer_id, shipped_date,
                            dealer_received_date, transfer_date, created_at
                     FROM {$table}
                     WHERE customer_id = %d OR customer_email = %s
                     ORDER BY created_at DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$email
				),
				ARRAY_A
			) ?: array();
		} catch ( \Throwable ) {
			return array();
		}
		return $rows;
	}

	/**
	 * GET /crm/export — stream a CSV of every customer. Batched (200 users
	 * per round trip with bulk lookups per batch) so it never materializes
	 * the full set in memory.
	 */
	public static function export( WP_REST_Request $request ): void {
		global $wpdb;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="g2a-customers-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'id',
				'name',
				'email',
				'phone',
				'membership_plan',
				'membership_status',
				'membership_expires',
				'waiver_status',
				'lifetime_spend',
				'order_count',
				'loyalty_points',
				'store_credit',
				'bookings_count',
				'ffl_transfers_count',
				'tags',
			)
		);

		$p             = $wpdb->prefix;
		$has_memb      = self::table_exists( $p . 'memberistic_memberships' ) && self::table_exists( $p . 'memberistic_plans' );
		$has_people    = self::table_exists( $p . 'memberistic_people' );
		$has_bookings  = self::table_exists( $p . 'g2ab_bookings' );
		$has_ffl       = self::table_exists( $p . 'wpistic_ffl_transfers' );
		$batch         = 200;
		$last_id       = 0;

		while ( true ) {
			$users = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, display_name, user_email FROM {$wpdb->users}
                     WHERE ID > %d ORDER BY ID ASC LIMIT %d",
					$last_id,
					$batch
				),
				ARRAY_A
			) ?: array();
			if ( ! $users ) {
				break;
			}

			$ids     = array_map( static fn( $u ) => (int) $u['ID'], $users );
			$emails  = array_map( static fn( $u ) => (string) $u['user_email'], $users );
			$last_id = end( $ids );
			$id_in   = implode( ',', $ids );
			$em_ph   = implode( ',', array_fill( 0, count( $emails ), '%s' ) );

			// Bulk lookups for this batch only.
			$phones = self::column_map(
				$wpdb->get_results(
					"SELECT user_id AS k, meta_value AS v FROM {$wpdb->usermeta}
                     WHERE meta_key = 'billing_phone' AND user_id IN ({$id_in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				)
			);

			$profiles = array();
			foreach ( $wpdb->get_results(
				"SELECT customer_id, do_not_sell, denied_buyer, marketing_email_optin, marketing_sms_optin
                 FROM {$p}g2a_customer_profiles WHERE customer_id IN ({$id_in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			) ?: array() as $row ) {
				$profiles[ (int) $row['customer_id'] ] = $row;
			}

			// Order stats — same status filter recompute_lifetime() uses.
			$order_stats = array();
			foreach ( $wpdb->get_results(
				"SELECT customer_id, COUNT(*) AS c, COALESCE(SUM(grand_total),0) AS s
                 FROM {$p}g2a_pos_orders
                 WHERE customer_id IN ({$id_in}) AND status NOT IN ('void','cancelled','refunded')
                 GROUP BY customer_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			) ?: array() as $row ) {
				$order_stats[ (int) $row['customer_id'] ] = $row;
			}

			$loyalty = array();
			foreach ( $wpdb->get_results(
				"SELECT customer_id, balance_points, store_credit_cents
                 FROM {$p}g2a_loyalty_accounts WHERE customer_id IN ({$id_in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			) ?: array() as $row ) {
				$loyalty[ (int) $row['customer_id'] ] = $row;
			}

			$memberships = array();
			if ( $has_memb ) {
				$people_join = $has_people ? "LEFT JOIN {$p}memberistic_people pp ON pp.membership_id = m.id" : '';
				$people_or   = $has_people ? 'OR pp.wp_user_id IN (' . $id_in . ')' : '';
				$rows        = $wpdb->get_results(
					"SELECT m.primary_user_id, " . ( $has_people ? 'pp.wp_user_id' : 'NULL AS wp_user_id' ) . ", m.status,
                            COALESCE(m.end_date, m.renewal_date) AS expires_at, pl.name AS plan_name
                     FROM {$p}memberistic_memberships m
                     LEFT JOIN {$p}memberistic_plans pl ON pl.id = m.plan_id
                     {$people_join}
                     WHERE m.primary_user_id IN ({$id_in}) {$people_or}
                     ORDER BY (m.status = 'active') ASC, m.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				) ?: array();
				// Ordered worst-first so the best (active, newest) row wins the key.
				foreach ( $rows as $row ) {
					foreach ( array( (int) $row['primary_user_id'], (int) ( $row['wp_user_id'] ?? 0 ) ) as $uid ) {
						if ( $uid > 0 ) {
							$memberships[ $uid ] = $row;
						}
					}
				}
			}

			$waivers = array();
			if ( $has_people ) {
				foreach ( $wpdb->get_results(
					"SELECT wp_user_id, waiver_status FROM {$p}memberistic_people
                     WHERE wp_user_id IN ({$id_in}) AND waiver_status IS NOT NULL AND waiver_status <> ''
                     ORDER BY waiver_signed_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				) ?: array() as $row ) {
					$waivers[ (int) $row['wp_user_id'] ] = (string) $row['waiver_status']; // latest signed wins.
				}
			}

			$bookings_by_user  = array();
			$bookings_by_email = array();
			if ( $has_bookings ) {
				foreach ( $wpdb->get_results(
					$wpdb->prepare(
						"SELECT user_id, customer_email, COUNT(*) AS c FROM {$p}g2ab_bookings
                         WHERE (user_id IN ({$id_in}) OR customer_email IN ({$em_ph}))
                         AND status NOT IN ('cancelled','no_show','expired')
                         GROUP BY user_id, customer_email", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$emails
					),
					ARRAY_A
				) ?: array() as $row ) {
					$uid = (int) $row['user_id'];
					$eml = strtolower( (string) $row['customer_email'] );
					if ( $uid > 0 ) {
						$bookings_by_user[ $uid ] = ( $bookings_by_user[ $uid ] ?? 0 ) + (int) $row['c'];
					} elseif ( $eml !== '' ) {
						$bookings_by_email[ $eml ] = ( $bookings_by_email[ $eml ] ?? 0 ) + (int) $row['c'];
					}
				}
			}

			$ffl_by_user  = array();
			$ffl_by_email = array();
			if ( $has_ffl ) {
				foreach ( $wpdb->get_results(
					$wpdb->prepare(
						"SELECT customer_id, customer_email, COUNT(*) AS c FROM {$p}wpistic_ffl_transfers
                         WHERE customer_id IN ({$id_in}) OR customer_email IN ({$em_ph})
                         GROUP BY customer_id, customer_email", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$emails
					),
					ARRAY_A
				) ?: array() as $row ) {
					$uid = (int) $row['customer_id'];
					$eml = strtolower( (string) $row['customer_email'] );
					if ( $uid > 0 ) {
						$ffl_by_user[ $uid ] = ( $ffl_by_user[ $uid ] ?? 0 ) + (int) $row['c'];
					} elseif ( $eml !== '' ) {
						$ffl_by_email[ $eml ] = ( $ffl_by_email[ $eml ] ?? 0 ) + (int) $row['c'];
					}
				}
			}

			foreach ( $users as $user ) {
				$uid   = (int) $user['ID'];
				$email = strtolower( (string) $user['user_email'] );

				$membership = $memberships[ $uid ] ?? null;
				if ( ! $membership && ! $has_memb ) {
					// Memberistic absent: fall back to whatever provider chain is active.
					$norm = RangeMembership::status_for_customer( $uid );
					if ( $norm ) {
						$membership = array(
							'plan_name'  => $norm['plan'],
							'status'     => $norm['active'] ? 'active' : 'inactive',
							'expires_at' => $norm['expires_at'],
						);
					}
				}

				$profile = $profiles[ $uid ] ?? array();
				$tags    = array();
				if ( ! empty( $profile['denied_buyer'] ) ) {
					$tags[] = 'denied-buyer';
				}
				if ( ! empty( $profile['do_not_sell'] ) ) {
					$tags[] = 'do-not-sell';
				}
				if ( ! empty( $profile['marketing_email_optin'] ) ) {
					$tags[] = 'email-optin';
				}
				if ( ! empty( $profile['marketing_sms_optin'] ) ) {
					$tags[] = 'sms-optin';
				}
				if ( $membership && ( $membership['status'] ?? '' ) === 'active' ) {
					$tags[] = 'member';
				}

				fputcsv(
					$out,
					array(
						$uid,
						(string) $user['display_name'],
						(string) $user['user_email'],
						(string) ( $phones[ $uid ] ?? '' ),
						(string) ( $membership['plan_name'] ?? '' ),
						(string) ( $membership['status'] ?? '' ),
						(string) ( $membership['expires_at'] ?? '' ),
						(string) ( $waivers[ $uid ] ?? '' ),
						number_format( (float) ( $order_stats[ $uid ]['s'] ?? 0 ), 2, '.', '' ),
						(int) ( $order_stats[ $uid ]['c'] ?? 0 ),
						(int) ( $loyalty[ $uid ]['balance_points'] ?? 0 ),
						number_format( ( (int) ( $loyalty[ $uid ]['store_credit_cents'] ?? 0 ) ) / 100, 2, '.', '' ),
						(int) ( $bookings_by_user[ $uid ] ?? $bookings_by_email[ $email ] ?? 0 ),
						(int) ( $ffl_by_user[ $uid ] ?? $ffl_by_email[ $email ] ?? 0 ),
						implode( '|', $tags ),
					)
				);
			}

			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		fclose( $out );
		exit;
	}

	/** @return array<int,string> */
	private static function column_map( ?array $rows ): array {
		$map = array();
		foreach ( $rows ?: array() as $row ) {
			$map[ (int) $row['k'] ] = (string) $row['v'];
		}
		return $map;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
