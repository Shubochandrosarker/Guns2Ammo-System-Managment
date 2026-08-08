<?php

namespace G2A\POS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced FFL Checkout (advanced-ffl-checkout plugin) bridge.
 *
 * That plugin tracks WooCommerce-order FFL transfers in its own tables
 * (wp_wpistic_ffl_transfers / wp_wpistic_ffl_dealers). This bridge lists
 * them for the POS "FFL Transfers" view and counts them per customer for
 * the CRM detail panel.
 *
 * v2 (addon integration): the two domain models don't actually line up
 * well enough to merge — advanced-ffl-checkout's `transfers` table is a
 * WooCommerce-order-driven CUSTOMER pickup record (status vocabulary:
 * payment_confirmed → shipped_to_dealer → received_by_dealer →
 * transferred), while this plugin's `FflTransfer::inbound_consignment()`/
 * `outbound_transfer()` are dealer-to-dealer INVENTORY consignments with
 * no end customer and no WooCommerce order at all. Forcing a consignment
 * into the customer-transfer table (or into that plugin's `ad_ledger`,
 * whose `transfer_id` column is a NOT NULL FK to a real transfer row)
 * would mean inventing a fake customer/transfer row — worse than no
 * integration. Instead this pushes a plain audit-log entry into that
 * plugin's own Activity Log (`wp_wpistic_ffl_events`, `transfer_id = 0` —
 * the same "not tied to a specific transfer" sentinel that plugin's own
 * Verification Hub and State Rules admin already use for site-wide
 * events), so POS-side dealer-to-dealer FFL activity is at least visible
 * in the WooCommerce-facing plugin's unified activity feed. Read path is
 * unchanged and remains the primary integration.
 */
final class FflCheckoutBridge {

	public static function is_active(): bool {
		static $active = null;
		if ( $active === null ) {
			if ( defined( 'WPISTIC_FFL_VERSION' ) ) {
				$active = true;
			} else {
				global $wpdb;
				$table  = $wpdb->prefix . 'wpistic_ffl_transfers';
				$active = isset( $wpdb ) && (bool) $wpdb->get_var(
					$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
				);
			}
		}
		return $active;
	}

	/**
	 * Transfers list (newest first) with dealer name joined in.
	 *
	 * @param array $params q|status|limit|offset supported.
	 */
	public static function transfers( array $params = array() ): array {
		global $wpdb;
		if ( ! self::is_active() ) {
			return array();
		}
		$t     = $wpdb->prefix . 'wpistic_ffl_transfers';
		$d     = $wpdb->prefix . 'wpistic_ffl_dealers';
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $params['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $params['q'] ) . '%';
			$where[] = '(t.customer_name LIKE %s OR t.customer_email LIKE %s OR t.transfer_ref LIKE %s OR t.item_description LIKE %s OR dl.business_name LIKE %s)';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		if ( ! empty( $params['status'] ) ) {
			$where[] = 't.status = %s';
			$args[]  = sanitize_text_field( (string) $params['status'] );
		}
		$limit  = max( 1, min( 500, (int) ( $params['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $params['offset'] ?? 0 ) );

		$sql = "SELECT t.id, t.transfer_ref, t.order_id, t.customer_id, t.customer_name, t.customer_email,
		               t.status, t.item_description, t.item_sku, t.item_make, t.item_model,
		               t.shipment_carrier, t.shipment_tracking, t.transfer_date, t.updated_at,
		               dl.business_name AS dealer_name, dl.premise_city AS dealer_city, dl.premise_state AS dealer_state
		        FROM {$t} t
		        LEFT JOIN {$d} dl ON dl.id = t.dealer_id
		        WHERE " . implode( ' AND ', $where ) . ' ORDER BY t.id DESC LIMIT %d OFFSET %d';
		array_push( $args, $limit, $offset );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
		return array_map(
			static fn( array $r ): array => array(
				'id'           => (int) $r['id'],
				'transfer_ref' => (string) $r['transfer_ref'],
				'order_id'     => (int) $r['order_id'],
				'customer'     => (string) $r['customer_name'],
				'email'        => (string) $r['customer_email'],
				'dealer'       => trim( (string) ( $r['dealer_name'] ?? '' ) ) ?: '—',
				'dealer_city'  => (string) ( $r['dealer_city'] ?? '' ),
				'dealer_state' => (string) ( $r['dealer_state'] ?? '' ),
				'item'         => (string) $r['item_description'],
				'sku'          => (string) $r['item_sku'],
				'tracking'     => trim( (string) $r['shipment_carrier'] . ' ' . (string) $r['shipment_tracking'] ),
				'status'       => (string) $r['status'],
				'updated_at'   => (string) $r['updated_at'],
				'source'       => 'advanced-ffl-checkout',
			),
			$rows
		);
	}

	/** Number of transfers for one customer (by WP user id and/or email). */
	public static function count_for_customer( ?int $user_id, ?string $email ): int {
		global $wpdb;
		if ( ! self::is_active() || ( ! $user_id && ! $email ) ) {
			return 0;
		}
		$t = $wpdb->prefix . 'wpistic_ffl_transfers';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE customer_id = %d OR ( %s <> '' AND customer_email = %s )",
				(int) $user_id,
				(string) $email,
				(string) $email
			)
		);
	}

	/**
	 * Log a POS-side dealer-to-dealer FFL event into advanced-ffl-checkout's
	 * own Activity Log, so staff watching that plugin's dashboard see it too.
	 * `transfer_id = 0` is that plugin's own established sentinel for a
	 * site-wide/non-transfer-specific audit row (its Verification Hub policy
	 * changes and State Rules admin edits already log this way) — safe to
	 * reuse without inventing a fake transfer record.
	 */
	public static function log_activity( string $event_type, string $notes ): void {
		if ( ! self::is_active() ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'wpistic_ffl_events',
			array(
				'transfer_id' => 0,
				'event_type'  => 'pos_' . sanitize_key( $event_type ),
				'notes'       => $notes,
				'actor'       => 'g2a-pos-core',
				'actor_ip'    => '',
			)
		);
	}

	/**
	 * Resolve a synced ATF dealer row in advanced-ffl-checkout's own
	 * `dealers` table by FFL license number, for context in the log line —
	 * best-effort, returns null (not an error) if that dealer isn't in the
	 * ~80k-row monthly ATF sync yet.
	 */
	public static function find_dealer_business_name( string $ffl_number ): ?string {
		if ( ! self::is_active() || '' === $ffl_number ) {
			return null;
		}
		global $wpdb;
		$name = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT business_name FROM ' . $wpdb->prefix . 'wpistic_ffl_dealers WHERE license_number = %s LIMIT 1',
				$ffl_number
			)
		);
		return $name ? (string) $name : null;
	}
}
