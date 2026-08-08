<?php

namespace G2A\POS\Database;

final class PoMatchRepository extends Repository {

	public function record_asn( int $po_id, array $data ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table( 'g2a_po_asns' ),
			array(
				'po_id'           => $po_id,
				'asn_number'      => sanitize_text_field( $data['asn_number'] ?? '' ),
				'carrier'         => sanitize_text_field( $data['carrier'] ?? '' ),
				'tracking_number' => sanitize_text_field( $data['tracking_number'] ?? '' ),
				'ship_date'       => $data['ship_date'] ?? null,
				'eta'             => $data['eta'] ?? null,
				'items_payload'   => ! empty( $data['items'] ) ? wp_json_encode( $data['items'] ) : null,
				'source'          => sanitize_key( $data['source'] ?? 'manual' ),
				'created_at'      => $this->now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function record_invoice( int $po_id, array $data ): array {
		global $wpdb;
		$now           = $this->now();
		$po_total      = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT grand_total FROM {$this->table('g2a_purchase_orders')} WHERE id = %d",
				$po_id
			)
		);
		$invoice_total = (float) ( $data['invoice_total'] ?? 0 );
		$variance      = $invoice_total - $po_total;
		$match         = abs( $variance ) < 0.50 ? 'matched' : 'variance';

		$wpdb->insert(
			$this->table( 'g2a_po_invoices' ),
			array(
				'po_id'          => $po_id,
				'invoice_number' => sanitize_text_field( $data['invoice_number'] ?? '' ),
				'invoice_date'   => $data['invoice_date'] ?? date( 'Y-m-d' ),
				'invoice_total'  => $invoice_total,
				'match_status'   => $match,
				'match_variance' => $variance,
				'attachment_url' => esc_url_raw( $data['attachment_url'] ?? '' ),
				'actor_id'       => get_current_user_id(),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		return array(
			'id'           => (int) $wpdb->insert_id,
			'match_status' => $match,
			'variance'     => $variance,
		);
	}

	public function po_bundle( int $po_id ): array {
		global $wpdb;
		$po = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_purchase_orders')} WHERE id = %d",
				$po_id
			),
			ARRAY_A
		);
		if ( ! $po ) {
			return array();
		}
		return array(
			'po'       => $po,
			'asns'     => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table('g2a_po_asns')} WHERE po_id = %d ORDER BY id",
					$po_id
				),
				ARRAY_A
			) ?: array(),
			'invoices' => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table('g2a_po_invoices')} WHERE po_id = %d ORDER BY id",
					$po_id
				),
				ARRAY_A
			) ?: array(),
		);
	}

	public function backorders(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT po.po_number, i.*
             FROM {$this->table('g2a_purchase_order_items')} i
             JOIN {$this->table('g2a_purchase_orders')} po ON po.id = i.po_id
             WHERE po.status IN ('submitted','partial')
             AND i.quantity_received < i.quantity_ordered
             ORDER BY i.id DESC LIMIT 200",
			ARRAY_A
		) ?: array();
	}
}
