<?php
/**
 * G2A — Shipment tracking helpers.
 *
 * When admin saves a transfer with both `shipment_carrier` and
 * `shipment_tracking` set, we automatically:
 *   - Advance the status to shipped_to_dealer if still pre-shipment
 *   - Set shipped_date to today if blank
 *   - Generate a public carrier tracking URL exposed via REST + admin
 *
 * No external API keys required — we build well-known public-tracking URLs
 * for UPS / USPS / FedEx and let the carrier render their own status page.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Carrier {

	public function __construct() {
		// Hooked into the same after-status-update lifecycle Portal uses.
		add_action( 'wpistic_ffl_transfer_updated', [ __CLASS__, 'on_transfer_updated' ], 10, 2 );
	}

	/**
	 * @param int   $transfer_id
	 * @param array $changes  key => new_value
	 */
	public static function on_transfer_updated( int $transfer_id, array $changes ): void {
		// Only react when shipment_tracking was just set/changed.
		if ( ! array_key_exists( 'shipment_tracking', $changes ) || empty( $changes['shipment_tracking'] ) ) {
			return;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, status, shipped_date FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$transfer_id
		) );
		if ( ! $row ) {
			return;
		}

		$updates = [];
		if ( empty( $row->shipped_date ) ) {
			$updates['shipped_date'] = current_time( 'Y-m-d' );
		}

		// Pre-shipment statuses advance to shipped_to_dealer automatically.
		$pre_ship = [ 'payment_confirmed', 'dealer_selected', '' ];
		$advance  = in_array( $row->status, $pre_ship, true );

		if ( ! empty( $updates ) ) {
			$wpdb->update( DB::table( 'transfers' ), $updates, [ 'id' => $transfer_id ] ); // phpcs:ignore WordPress.DB
		}

		if ( $advance ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				DB::table( 'transfers' ),
				[ 'status' => 'shipped_to_dealer', 'updated_at' => current_time( 'mysql' ) ],
				[ 'id' => $transfer_id ]
			);
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => $transfer_id,
				'event_type'  => 'status_change',
				'old_status'  => $row->status,
				'new_status'  => 'shipped_to_dealer',
				'notes'       => 'Auto-advanced on tracking number entry.',
				'actor'       => 'carrier-auto',
				'actor_ip'    => '',
			] );
			do_action( 'wpistic_ffl_transfer_status_changed', $transfer_id, $row->status, 'shipped_to_dealer' );
		}
	}

	/**
	 * Build a public tracking URL for the given carrier + tracking number.
	 * Unknown carriers fall back to a Google search.
	 */
	public static function tracking_url( string $carrier, string $tracking ): string {
		$tracking = trim( $tracking );
		if ( ! $tracking ) {
			return '';
		}
		$c = strtoupper( trim( $carrier ) );
		switch ( $c ) {
			case 'UPS':
				return 'https://www.ups.com/track?tracknum=' . rawurlencode( $tracking );
			case 'USPS':
				return 'https://tools.usps.com/go/TrackConfirmAction?qtc_tLabels1=' . rawurlencode( $tracking );
			case 'FEDEX':
			case 'FEDEX EXPRESS':
				return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode( $tracking );
			case 'DHL':
				return 'https://www.dhl.com/en/express/tracking.html?AWB=' . rawurlencode( $tracking );
			default:
				return 'https://www.google.com/search?q=' . rawurlencode( $carrier . ' tracking ' . $tracking );
		}
	}
}
