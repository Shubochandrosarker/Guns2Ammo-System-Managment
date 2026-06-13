<?php

namespace G2A\POS\Database;

final class BarcodeRepository extends Repository {

	public function find( string $barcode ): ?array {
		global $wpdb;

		$table = $this->table( 'g2a_barcodes' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE barcode_value = %s LIMIT 1", $barcode ), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Idempotent insert keyed on the unique barcode_value. Re-points an
	 * existing barcode at the given product if it moved.
	 */
	public function upsert( int $product_id, string $barcode_value, string $barcode_type = 'code128', bool $is_primary = false ): int {
		global $wpdb;

		$barcode_value = trim( $barcode_value );
		if ( $barcode_value === '' || $product_id <= 0 ) {
			return 0;
		}

		$table    = $this->table( 'g2a_barcodes' );
		$existing = $this->find( $barcode_value );
		$now      = $this->now();

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'product_id'   => $product_id,
					'barcode_type' => $barcode_type,
					'is_primary'   => $is_primary ? 1 : 0,
					'updated_at'   => $now,
				),
				array( 'id' => (int) $existing['id'] )
			);
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$table,
			array(
				'product_id'    => $product_id,
				'variation_id'  => null,
				'barcode_value' => $barcode_value,
				'barcode_type'  => $barcode_type,
				'is_primary'    => $is_primary ? 1 : 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}
}
