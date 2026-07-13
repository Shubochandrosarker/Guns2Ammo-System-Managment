<?php
/**
 * G2A — WooCommerce order status → FFL transfer status bridge.
 *
 * Keeps the WC order lifecycle and the FFL transfer lifecycle aligned so
 * admins don't have to touch two systems for every order. The mapping is
 * conservative — we never move a transfer backwards, and we never override
 * a manually-set terminal status.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Status_Bridge {

	/**
	 * Statuses we consider "terminal" — once a transfer is here, the bridge
	 * leaves it alone so admin action cannot be silently undone by a WC event.
	 */
	const TERMINAL = [ 'transferred', 'cancelled', 'denied' ];

	public function __construct() {
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );
		add_action( 'woocommerce_order_refunded',       [ $this, 'on_order_refunded' ], 10, 2 );
	}

	/**
	 * @param int       $order_id
	 * @param string    $old_status  WC status WITHOUT the "wc-" prefix.
	 * @param string    $new_status
	 * @param \WC_Order $order
	 */
	public function on_order_status_changed( int $order_id, string $old_status, string $new_status, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$mapped = self::map_wc_to_transfer( $new_status );
		if ( ! $mapped ) {
			return;
		}

		foreach ( self::transfer_ids_for_order( $order_id ) as $transfer_id ) {
			self::advance_transfer( $transfer_id, $mapped, sprintf( 'WC order #%d → %s', $order_id, $new_status ) );
		}
	}

	public function on_order_refunded( int $order_id, int $refund_id ): void {
		foreach ( self::transfer_ids_for_order( $order_id ) as $transfer_id ) {
			self::advance_transfer( $transfer_id, 'cancelled', sprintf( 'WC order #%d refunded (refund #%d)', $order_id, $refund_id ) );
		}
	}

	/**
	 * Every transfer tied to this order — an order can now own several
	 * (one per FFL unit, see Checkout::get_ffl_items()). Queried straight
	 * from the DB rather than order meta, since the DB is authoritative and
	 * this also picks up transfers created outside the checkout flow (e.g.
	 * admin-created, or the theme-bridge path) that share the order_id.
	 *
	 * @return int[]
	 */
	private static function transfer_ids_for_order( int $order_id ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id FROM ' . DB::table( 'transfers' ) . ' WHERE order_id = %d',
			$order_id
		) ) );
	}

	/**
	 * Map a WooCommerce status to a transfer status.
	 * Returns null when no mapping applies (transfer left as-is).
	 */
	public static function map_wc_to_transfer( string $wc_status ): ?string {
		switch ( $wc_status ) {
			case 'processing':
			case 'on-hold':
				return 'payment_confirmed';
			case 'completed':
				return 'transferred';
			case 'cancelled':
			case 'failed':
				return 'cancelled';
			case 'refunded':
				return 'cancelled';
		}
		return null;
	}

	/**
	 * Apply the new status to the transfer if (a) the transfer exists,
	 * (b) the current status is not terminal, (c) the new status is not the same.
	 * Fires the shared wpistic_ffl_transfer_status_changed action so emails +
	 * dashboard react identically to a manual REST update.
	 */
	public static function advance_transfer( int $transfer_id, string $new_status, string $reason ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, status FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$transfer_id
		) );
		if ( ! $row ) {
			return;
		}
		if ( in_array( $row->status, self::TERMINAL, true ) ) {
			return;
		}
		if ( $row->status === $new_status ) {
			return;
		}

		// Re-check status in the WHERE clause, not just the earlier PHP read —
		// a WooCommerce order-status webhook retry or an admin edit racing this
		// same call can only advance the row once; whichever call's UPDATE
		// actually matches the row still being at $row->status wins, so the
		// event log + hook below only fire for that one, not for both.
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB
			DB::table( 'transfers' ),
			[ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $transfer_id, 'status' => $row->status ]
		);
		if ( ! $updated ) {
			return;
		}

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'status_change',
			'old_status'  => $row->status,
			'new_status'  => $new_status,
			'notes'       => $reason,
			'actor'       => 'wc-bridge',
			'actor_ip'    => '',
		] );

		do_action( 'wpistic_ffl_transfer_status_changed', $transfer_id, $row->status, $new_status );
	}
}
