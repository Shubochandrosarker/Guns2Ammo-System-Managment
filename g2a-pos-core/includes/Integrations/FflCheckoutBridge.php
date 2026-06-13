<?php

namespace G2A\POS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced FFL Checkout (advanced-ffl-checkout plugin) bridge — read-only v1.
 *
 * That plugin tracks WooCommerce-order FFL transfers in its own tables
 * (wp_wpistic_ffl_transfers / wp_wpistic_ffl_dealers). This bridge lists
 * them for the POS "FFL Transfers" view and counts them per customer for
 * the CRM detail panel. No writes.
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
		$t      = $wpdb->prefix . 'wpistic_ffl_transfers';
		$d      = $wpdb->prefix . 'wpistic_ffl_dealers';
		$where  = array( '1=1' );
		$args   = array();
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
}
