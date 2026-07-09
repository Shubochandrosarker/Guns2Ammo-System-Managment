<?php

namespace G2A\POS\API;

use G2A\POS\Database\CustomerProfileRepository;
use G2A\POS\Database\CustomerRepository;
use G2A\POS\Database\LoyaltyRepository;
use G2A\POS\Database\RangeWaiverRepository;
use G2A\POS\Integrations\BookingEngineSync;
use G2A\POS\Integrations\FflCheckoutBridge;
use G2A\POS\Integrations\RangeMembership;
use G2A\POS\Integrations\VerifyisticWaivers;
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
			'id'           => $id,
			'name'         => $user->display_name,
			'email'        => $user->user_email,
			'phone'        => (string) get_user_meta( $id, 'billing_phone', true ),
			'address'      => array(
				'address_1' => (string) get_user_meta( $id, 'billing_address_1', true ),
				'city'      => (string) get_user_meta( $id, 'billing_city', true ),
				'state'     => (string) get_user_meta( $id, 'billing_state', true ),
				'postcode'  => (string) get_user_meta( $id, 'billing_postcode', true ),
			),
			'profile'      => $profile,
			'loyalty'      => $loyalty,
			'history'      => $profile_repo->purchase_history( $id, 25 ),
			'integrations' => self::integrations( $id, (string) $user->user_email, (string) $user->display_name ),
		);
	}

	/**
	 * Cross-plugin context for the CRM detail panel: membership (resolved via
	 * RangeMembership), waiver evidence (POS waivers + Verifyistic/Memberistic),
	 * upcoming booking-engine bookings, and FFL-checkout transfer count.
	 * Every lookup is best-effort — a broken sibling plugin must never 500 the
	 * customer screen.
	 */
	private static function integrations( int $id, string $email, string $name ): array {
		$out = array(
			'membership'         => null,
			'waiver'             => null,
			'bookings'           => array(),
			'ffl_transfer_count' => 0,
		);
		try {
			$out['membership'] = RangeMembership::status_for_customer( $id );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		try {
			$waiver = ( new RangeWaiverRepository() )->findLatestForCustomer( $email ?: null, null );
			if ( $waiver ) {
				$out['waiver'] = array(
					'source'      => (string) ( $waiver['source'] ?? 'pos' ),
					'status'      => (string) ( $waiver['status'] ?? '' ),
					'waiver_date' => $waiver['waiver_date'] ?? null,
					'valid_until' => $waiver['valid_until'] ?? null,
				);
			} else {
				$external = VerifyisticWaivers::status_for_customer( $email ?: null, $name ?: null );
				if ( $external ) {
					$out['waiver'] = array(
						'source'      => (string) $external['source'],
						'status'      => (string) $external['status'],
						'waiver_date' => $external['waiver_date'] ?? null,
						'valid_until' => $external['valid_until'] ?? null,
					);
				}
			}
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		try {
			$out['bookings'] = BookingEngineSync::bookings_for_customer( $id, $email ?: null );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		try {
			$out['ffl_transfer_count'] = FflCheckoutBridge::count_for_customer( $id, $email ?: null );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		return $out;
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
	 * GET /crm/export.csv — stream every customer with profile fields.
	 * Cells are formula-escaped (leading = + - @ get a ' prefix) so the file
	 * is safe to open in Excel/Sheets.
	 */
	public static function export_csv( WP_REST_Request $request ): void {
		global $wpdb;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="g2a-customers-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'customer_id',
				'name',
				'email',
				'phone',
				'lifetime_spend',
				'lifetime_orders',
				'loyalty_points',
				'store_credit',
				'email_optin',
				'sms_optin',
				'do_not_sell',
				'denied_buyer',
				'membership_plan',
				'membership_status',
				'membership_expires',
				'waiver_status',
				'waiver_source',
				'registered_at',
			)
		);

		$waivers   = new RangeWaiverRepository();
		$last_id   = 0;
		$batch     = 500;
		$profiles  = $wpdb->prefix . 'g2a_customer_profiles';
		$loyalty   = $wpdb->prefix . 'g2a_loyalty_accounts';
		$usermeta  = $wpdb->usermeta;
		$users_tbl = $wpdb->users;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.display_name, u.user_email, u.user_registered,
					        p.lifetime_spend, p.lifetime_orders, p.marketing_email_optin,
					        p.marketing_sms_optin, p.do_not_sell, p.denied_buyer,
					        l.balance_points, l.store_credit_cents,
					        (SELECT meta_value FROM {$usermeta} um WHERE um.user_id = u.ID AND um.meta_key = 'billing_phone' LIMIT 1) AS phone
					 FROM {$users_tbl} u
					 LEFT JOIN {$profiles} p ON p.customer_id = u.ID
					 LEFT JOIN {$loyalty} l ON l.customer_id = u.ID
					 WHERE u.ID > %d
					 ORDER BY u.ID ASC
					 LIMIT %d",
					$last_id,
					$batch
				),
				ARRAY_A
			) ?: array();

			foreach ( $rows as $r ) {
				$last_id = (int) $r['ID'];

				$membership = null;
				try {
					$membership = RangeMembership::status_for_customer( (int) $r['ID'] );
				} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				}
				$waiver = null;
				try {
					$waiver = $waivers->findLatestForCustomer( (string) $r['user_email'] ?: null, null )
						?? VerifyisticWaivers::status_for_customer( (string) $r['user_email'] ?: null, (string) $r['display_name'] ?: null );
				} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				}

				fputcsv(
					$out,
					array_map(
						array( self::class, 'csv_cell' ),
						array(
							(int) $r['ID'],
							(string) $r['display_name'],
							(string) $r['user_email'],
							(string) ( $r['phone'] ?? '' ),
							number_format( (float) ( $r['lifetime_spend'] ?? 0 ), 2, '.', '' ),
							(int) ( $r['lifetime_orders'] ?? 0 ),
							(int) ( $r['balance_points'] ?? 0 ),
							number_format( (int) ( $r['store_credit_cents'] ?? 0 ) / 100, 2, '.', '' ),
							(int) ( $r['marketing_email_optin'] ?? 0 ) ? 'yes' : 'no',
							(int) ( $r['marketing_sms_optin'] ?? 0 ) ? 'yes' : 'no',
							(int) ( $r['do_not_sell'] ?? 0 ) ? 'yes' : 'no',
							(int) ( $r['denied_buyer'] ?? 0 ) ? 'yes' : 'no',
							$membership ? (string) $membership['plan'] : '',
							$membership ? ( $membership['active'] ? 'active' : 'inactive' ) : '',
							$membership ? (string) ( $membership['expires_at'] ?? '' ) : '',
							$waiver ? (string) ( $waiver['status'] ?? '' ) : '',
							$waiver ? (string) ( $waiver['source'] ?? '' ) : '',
							(string) $r['user_registered'],
						)
					)
				);
			}
			if ( function_exists( 'flush' ) ) {
				flush();
			}
			$fetched = count( $rows );
		} while ( $fetched === $batch );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Neutralise spreadsheet formula injection. */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
