<?php

namespace G2A\POS\Database;

final class WholesalerProductRepository extends Repository {

	public function upsert( array $data ): string {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesaler_products' );
		$now = $this->now();

		$existingId = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE wholesaler_id=%d AND vendor_sku=%s",
				(int) $data['wholesaler_id'],
				(string) $data['vendor_sku']
			)
		);

		$payload = array(
			'wholesaler_id'     => (int) $data['wholesaler_id'],
			'vendor_sku'        => (string) $data['vendor_sku'],
			'upc'               => $data['upc'] ?? null,
			'mfg_part'          => $data['mfg_part'] ?? null,
			'manufacturer'      => $data['manufacturer'] ?? null,
			'model'             => $data['model'] ?? null,
			'family'            => $data['family'] ?? null,
			'item_group'        => $data['item_group'] ?? null,
			'vendor_category'   => $data['vendor_category'] ?? null,
			'vendor_type'       => $data['vendor_type'] ?? null,
			'item_type'         => $data['item_type'] ?? null,
			'name'              => (string) ( $data['name'] ?? 'Unnamed' ),
			'description'       => $data['description'] ?? null,
			'caliber'           => $data['caliber'] ?? null,
			'msrp'              => $data['msrp'] ?? null,
			'wholesale_price'   => $data['wholesale_price'] ?? null,
			'current_price'     => $data['current_price'] ?? null,
			'map_price'         => $data['map_price'] ?? null,
			'stock_qty'         => (int) ( $data['stock_qty'] ?? 0 ),
			'allocated'         => (int) ( $data['allocated'] ?? 0 ),
			'can_dropship'      => (int) ( $data['can_dropship'] ?? 0 ),
			'on_sale'           => (int) ( $data['on_sale'] ?? 0 ),
			'ffl_required'      => (int) ( $data['ffl_required'] ?? 0 ),
			'sot_required'      => (int) ( $data['sot_required'] ?? 0 ),
			'exclusive'         => (int) ( $data['exclusive'] ?? 0 ),
			'country_of_origin' => $data['country_of_origin'] ?? null,
			'shipping_weight'   => $data['shipping_weight'] ?? null,
			'image_filename'    => $data['image_filename'] ?? null,
			'attributes'        => $data['attributes'] ?? null,
			'last_seen_at'      => $data['last_seen_at'] ?? $now,
			'updated_at'        => $now,
		);

		if ( $existingId ) {
			$wpdb->update( $t, $payload, array( 'id' => (int) $existingId ) );
			return 'updated';
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $t, $payload );
		return $wpdb->insert_id > 0 ? 'created' : 'skipped';
	}

	public function updateLive( int $wholesalerId, string $sku, int $qty, ?float $price = null ): void {
		global $wpdb;
		$t    = $this->table( 'g2a_wholesaler_products' );
		$data = array(
			'stock_qty'    => $qty,
			'last_seen_at' => $this->now(),
			'updated_at'   => $this->now(),
		);
		if ( $price !== null ) {
			$data['current_price'] = $price;
		}
		$wpdb->update(
			$t,
			$data,
			array(
				'wholesaler_id' => $wholesalerId,
				'vendor_sku'    => $sku,
			)
		);
	}

	public function find( int $wholesalerId, string $sku ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesaler_products' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE wholesaler_id=%d AND vendor_sku=%s",
				$wholesalerId,
				$sku
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function findByUpc( string $upc ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_wholesaler_products' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE upc=%s ORDER BY stock_qty DESC, current_price ASC LIMIT 1",
				$upc
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function search( array $params ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_wholesaler_products' );
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $params['wholesaler_id'] ) ) {
			$where[] = 'wholesaler_id=%d';
			$args[]  = (int) $params['wholesaler_id'];
		}
		if ( ! empty( $params['term'] ) ) {
			$where[] = '(name LIKE %s OR mfg_part LIKE %s OR upc LIKE %s OR vendor_sku LIKE %s OR manufacturer LIKE %s)';
			$like    = '%' . $wpdb->esc_like( (string) $params['term'] ) . '%';
			array_push( $args, $like, $like, $like, $like, $like );
		}
		if ( ! empty( $params['category'] ) ) {
			$where[] = 'vendor_category=%s';
			$args[]  = (string) $params['category'];
		}
		if ( ! empty( $params['item_type'] ) ) {
			$where[] = 'item_type=%s';
			$args[]  = (string) $params['item_type'];
		}
		if ( ! empty( $params['in_stock'] ) ) {
			$where[] = 'stock_qty > 0';
		}
		if ( ! empty( $params['dropship_only'] ) ) {
			$where[] = 'can_dropship=1';
		}

		$limit  = max( 1, min( 200, (int) ( $params['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $params['offset'] ?? 0 ) );

		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC LIMIT %d OFFSET %d';
		array_push( $args, $limit, $offset );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	/**
	 * Catalog rows that carry an image reference, ordered stably by id so a
	 * paged bulk-mirror walk can't skip or repeat rows between batches.
	 *
	 * @param bool $linkedOnly Only rows already promoted to a WC product.
	 * @return list<array<string,mixed>>
	 */
	public function withImages( int $wholesalerId, int $limit = 50, int $offset = 0, bool $linkedOnly = false ): array {
		global $wpdb;
		$t      = $this->table( 'g2a_wholesaler_products' );
		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );
		$linked = $linkedOnly ? ' AND wc_product_id IS NOT NULL AND wc_product_id > 0' : '';

		$sql = "SELECT * FROM {$t}
			WHERE wholesaler_id=%d AND image_filename IS NOT NULL AND image_filename <> ''{$linked}
			ORDER BY id ASC LIMIT %d OFFSET %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $wholesalerId, $limit, $offset ), ARRAY_A ) ?: array();
	}

	public function countWithImages( int $wholesalerId, bool $linkedOnly = false ): int {
		global $wpdb;
		$t      = $this->table( 'g2a_wholesaler_products' );
		$linked = $linkedOnly ? ' AND wc_product_id IS NOT NULL AND wc_product_id > 0' : '';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE wholesaler_id=%d AND image_filename IS NOT NULL AND image_filename <> ''{$linked}",
				$wholesalerId
			)
		);
	}

	public function countByWholesaler( int $wholesalerId ): int {
		global $wpdb;
		$t = $this->table( 'g2a_wholesaler_products' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE wholesaler_id=%d",
				$wholesalerId
			)
		);
	}

	public function productsTable(): string {
		return $this->table( 'g2a_wholesaler_products' );
	}

	public function linkToWoo( int $vendorProductId, int $wcProductId ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_wholesaler_products' ),
			array(
				'wc_product_id' => $wcProductId,
				'updated_at'    => $this->now(),
			),
			array( 'id' => $vendorProductId )
		);
	}
}
