<?php
/**
 * G2A — Personal-data exporter + eraser registration.
 *
 * Hooks into the core WP Tools → Export / Erase Personal Data workflow so
 * a customer who submits a privacy request automatically gets every FFL
 * transfer record tied to their email + every saved-dealer entry. Erasure
 * anonymizes the customer name/email/phone fields on transfers but keeps
 * the dealer + audit trail for ATF compliance (cannot legally be removed).
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Gdpr {

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers',   [ $this, 'register_eraser' ] );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['wpistic-ffl-transfers'] = [
			'exporter_friendly_name' => __( 'FFL Transfers', 'advanced-ffl-checkout' ),
			'callback'               => [ __CLASS__, 'export_transfers' ],
		];
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['wpistic-ffl-transfers'] = [
			'eraser_friendly_name' => __( 'FFL Transfers (anonymize)', 'advanced-ffl-checkout' ),
			'callback'             => [ __CLASS__, 'erase_transfers' ],
		];
		return $erasers;
	}

	public static function export_transfers( string $email, int $page = 1 ): array {
		global $wpdb;
		$per_page = 50;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.transfer_ref, t.status, t.item_description, t.item_make, t.item_model, t.item_caliber,
			        t.shipment_carrier, t.shipment_tracking, t.shipped_date, t.dealer_received_date,
			        t.nics_check_date, t.transfer_date, t.created_at,
			        d.business_name AS dealer_name
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.customer_email = %s
			 ORDER BY t.created_at DESC
			 LIMIT %d OFFSET %d',
			$email, $per_page, ( $page - 1 ) * $per_page
		), ARRAY_A );

		$data = [];
		foreach ( (array) $rows as $row ) {
			$item_data = [];
			foreach ( $row as $k => $v ) {
				if ( null === $v || '' === $v ) continue;
				$item_data[] = [ 'name' => str_replace( '_', ' ', $k ), 'value' => (string) $v ];
			}
			$data[] = [
				'group_id'    => 'wpistic-ffl-transfers',
				'group_label' => __( 'FFL Transfers', 'advanced-ffl-checkout' ),
				'item_id'     => 'transfer-' . $row['transfer_ref'],
				'data'        => $item_data,
			];
		}

		// Also: saved dealers, if any.
		$uid = get_user_by( 'email', $email );
		if ( $uid && 1 === $page && class_exists( '\WpisticFFL\G2A_Saved_Dealers' ) ) {
			$saved = G2A_Saved_Dealers::for_user( $uid->ID );
			foreach ( $saved as $d ) {
				$data[] = [
					'group_id'    => 'wpistic-ffl-saved-dealers',
					'group_label' => __( 'Saved FFL Dealers', 'advanced-ffl-checkout' ),
					'item_id'     => 'saved-' . $d['id'],
					'data'        => [
						[ 'name' => 'Dealer', 'value' => $d['business_name'] ],
						[ 'name' => 'Last used', 'value' => (string) ( $d['last_used_at'] ?? '—' ) ],
						[ 'name' => 'Default', 'value' => $d['pinned'] ? 'yes' : 'no' ],
					],
				];
			}
		}

		return [
			'data' => $data,
			'done' => count( $rows ) < $per_page,
		];
	}

	public static function erase_transfers( string $email, int $page = 1 ): array {
		global $wpdb;
		$per_page = 50;

		// Get IDs first so we can count + audit-log.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, transfer_ref FROM ' . DB::table( 'transfers' ) . ' WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d',
			$email, $per_page, ( $page - 1 ) * $per_page
		) );

		$removed = 0;
		foreach ( (array) $rows as $r ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				DB::table( 'transfers' ),
				[
					'customer_name'  => 'REDACTED',
					'customer_email' => 'redacted@example.invalid',
					'customer_phone' => '',
					'customer_id'    => null,
				],
				[ 'id' => (int) $r->id ]
			);
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => (int) $r->id,
				'event_type'  => 'gdpr_anonymized',
				'notes'       => sprintf( 'Customer fields anonymized in response to privacy request for %s', $email ),
				'actor'       => 'wp-privacy',
				'actor_ip'    => '',
			] );
			$removed++;
		}

		// Remove the saved-dealers user meta entirely.
		$uid = get_user_by( 'email', $email );
		if ( $uid && 1 === $page ) {
			delete_user_meta( $uid->ID, '_wpistic_ffl_saved_dealers' );
			delete_user_meta( $uid->ID, '_wpistic_ffl_pinned_dealer' );
		}

		return [
			'items_removed'  => 0,
			'items_retained' => $removed, // we keep the row, just anonymize
			'messages'       => $removed > 0
				? [ __( 'FFL transfer rows kept for ATF compliance but customer fields anonymized.', 'advanced-ffl-checkout' ) ]
				: [],
			'done'           => count( (array) $rows ) < $per_page,
		];
	}
}
