<?php

namespace G2A\POS\Database;

final class ExternalRefRepository extends Repository {

	/** Returns product_id if a matching ref exists, otherwise null. */
	public function find_product_id( string $source, ?string $source_ref, ?string $upc, ?string $manufacturer_sku ): ?int {
		global $wpdb;
		$t = $this->table( 'g2a_inventory_external_refs' );

		if ( $source_ref ) {
			$pid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT product_id FROM {$t} WHERE source = %s AND source_ref = %s LIMIT 1",
					$source,
					$source_ref
				)
			);
			if ( $pid ) {
				return (int) $pid;
			}
		}
		if ( $upc ) {
			$pid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT product_id FROM {$t} WHERE upc = %s LIMIT 1",
					$upc
				)
			);
			if ( $pid ) {
				return (int) $pid;
			}
		}
		if ( $manufacturer_sku ) {
			$pid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT product_id FROM {$t} WHERE manufacturer_sku = %s LIMIT 1",
					$manufacturer_sku
				)
			);
			if ( $pid ) {
				return (int) $pid;
			}
		}
		return null;
	}

	public function upsert( int $product_id, string $source, ?string $source_ref, ?string $upc, ?string $manufacturer_sku, array $metadata = array() ): void {
		global $wpdb;
		$t      = $this->table( 'g2a_inventory_external_refs' );
		$now    = $this->now();
		$fields = array(
			'product_id'       => $product_id,
			'source'           => $source,
			'source_ref'       => $source_ref,
			'upc'              => $upc,
			'manufacturer_sku' => $manufacturer_sku,
			'last_seen_at'     => $now,
			'metadata'         => $metadata ? wp_json_encode( $metadata ) : null,
		);

		// Try to locate an existing row by source+source_ref or source+upc.
		$existing_id = null;
		if ( $source_ref ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE source = %s AND source_ref = %s LIMIT 1",
					$source,
					$source_ref
				)
			);
		}
		if ( ! $existing_id && $upc ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE source = %s AND upc = %s LIMIT 1",
					$source,
					$upc
				)
			);
		}

		if ( $existing_id ) {
			$wpdb->update( $t, $fields, array( 'id' => $existing_id ) );
		} else {
			$wpdb->insert( $t, $fields );
		}
	}
}
