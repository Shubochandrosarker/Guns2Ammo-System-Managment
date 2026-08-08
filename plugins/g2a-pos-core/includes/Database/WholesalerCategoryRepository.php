<?php

namespace G2A\POS\Database;

final class WholesalerCategoryRepository extends Repository {

	public function touch( int $wholesalerId, string $vendorCategory, ?string $itemType, int $count ): void {
		global $wpdb;
		$t        = $this->table( 'g2a_wholesaler_categories' );
		$now      = $this->now();
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, product_count FROM {$t} WHERE wholesaler_id=%d AND vendor_category=%s",
				$wholesalerId,
				$vendorCategory
			),
			ARRAY_A
		);

		if ( $existing ) {
			$wpdb->update(
				$t,
				array(
					'product_count' => $count,
					'item_type'     => $itemType,
					'updated_at'    => $now,
				),
				array( 'id' => (int) $existing['id'] )
			);
			return;
		}
		$wpdb->insert(
			$t,
			array(
				'wholesaler_id'    => $wholesalerId,
				'vendor_category'  => $vendorCategory,
				'item_type'        => $itemType,
				'display_label'    => $vendorCategory,
				'import_enabled'   => 1,
				'dropship_enabled' => 1,
				'product_count'    => $count,
				'updated_at'       => $now,
			)
		);
	}

	public function all( int $wholesalerId ): array {
		global $wpdb;
		$t = $this->table( 'g2a_wholesaler_categories' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE wholesaler_id=%d ORDER BY vendor_category ASC",
				$wholesalerId
			),
			ARRAY_A
		) ?: array();
	}

	public function setFlags( int $id, array $flags ): void {
		global $wpdb;
		$allowed = array( 'import_enabled', 'dropship_enabled', 'markup_percent', 'wc_category_id', 'display_label' );
		$update  = array( 'updated_at' => $this->now() );
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $flags ) ) {
				$update[ $k ] = $flags[ $k ];
			}
		}
		$wpdb->update( $this->table( 'g2a_wholesaler_categories' ), $update, array( 'id' => $id ) );
	}

	public function isImportEnabled( int $wholesalerId, string $vendorCategory ): bool {
		global $wpdb;
		$t = $this->table( 'g2a_wholesaler_categories' );
		$v = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT import_enabled FROM {$t} WHERE wholesaler_id=%d AND vendor_category=%s",
				$wholesalerId,
				$vendorCategory
			)
		);
		return $v === null ? true : (bool) (int) $v;
	}
}
