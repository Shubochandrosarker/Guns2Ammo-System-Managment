<?php

namespace G2A\POS\Database;

final class BarcodeRepository extends Repository {

	public function find( string $barcode ): ?array {
		global $wpdb;

		$table = $this->table( 'g2a_barcodes' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE barcode_value = %s LIMIT 1", $barcode ), ARRAY_A );

		return $row ?: null;
	}
}
