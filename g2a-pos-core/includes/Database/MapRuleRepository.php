<?php

namespace G2A\POS\Database;

final class MapRuleRepository extends Repository {

	public function upsertFromVendor( array $data ): int {
		global $wpdb;
		$t   = $this->table( 'g2a_map_rules' );
		$now = $this->now();

		$sku   = (string) ( $data['sku'] ?? '' );
		$upc   = (string) ( $data['upc'] ?? '' );
		$price = (float) ( $data['map_price'] ?? 0 );
		if ( $price <= 0 || ( $sku === '' && $upc === '' ) ) {
			return 0;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE source=%s AND ((sku<>'' AND sku=%s) OR (upc<>'' AND upc=%s)) LIMIT 1",
				(string) ( $data['source'] ?? 'vendor' ),
				$sku,
				$upc
			)
		);

		$payload = array(
			'sku'            => $sku !== '' ? $sku : null,
			'upc'            => $upc !== '' ? $upc : null,
			'manufacturer'   => $data['manufacturer'] ?? null,
			'map_price'      => $price,
			'currency'       => 'USD',
			'source'         => (string) ( $data['source'] ?? 'vendor' ),
			'wholesaler_id'  => $data['wholesaler_id'] ?? null,
			'display_mode'   => (string) ( $data['display_mode'] ?? 'click_to_reveal' ),
			'override_label' => $data['override_label'] ?? null,
			'actor_id'       => get_current_user_id(),
			'updated_at'     => $now,
		);

		if ( $existing ) {
			$wpdb->update( $t, $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $t, $payload );
		return (int) $wpdb->insert_id;
	}

	public function setForWcProduct( int $wcProductId, array $data ): int {
		global $wpdb;
		$t     = $this->table( 'g2a_map_rules' );
		$now   = $this->now();
		$price = (float) ( $data['map_price'] ?? 0 );
		if ( $price <= 0 ) {
			return 0;
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE wc_product_id=%d AND source IN ('manual','admin_ui') LIMIT 1",
				$wcProductId
			)
		);

		$payload = array(
			'wc_product_id'  => $wcProductId,
			'map_price'      => $price,
			'source'         => (string) ( $data['source'] ?? 'manual' ),
			'display_mode'   => (string) ( $data['display_mode'] ?? 'click_to_reveal' ),
			'override_label' => $data['override_label'] ?? null,
			'notes'          => $data['notes'] ?? null,
			'effective_at'   => $data['effective_at'] ?? null,
			'expires_at'     => $data['expires_at'] ?? null,
			'actor_id'       => get_current_user_id(),
			'updated_at'     => $now,
		);

		if ( $existing ) {
			$wpdb->update( $t, $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $t, $payload );
		return (int) $wpdb->insert_id;
	}

	public function findForProduct( int $wcProductId, ?string $sku = null, ?string $upc = null ): ?array {
		global $wpdb;
		$t     = $this->table( 'g2a_map_rules' );
		$today = current_time( 'Y-m-d' );

		$sql = "SELECT * FROM {$t} WHERE (
            wc_product_id = %d
            OR (sku IS NOT NULL AND sku = %s)
            OR (upc IS NOT NULL AND upc = %s)
        ) AND (effective_at IS NULL OR effective_at <= %s) AND (expires_at IS NULL OR expires_at >= %s)
        ORDER BY (wc_product_id = %d) DESC, source='manual' DESC, id DESC LIMIT 1";

		$row = $wpdb->get_row(
			$wpdb->prepare(
				$sql,
				$wcProductId,
				(string) $sku,
				(string) $upc,
				$today,
				$today,
				$wcProductId
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function logViolation( array $data ): void {
		global $wpdb;
		$wpdb->insert(
			$this->table( 'g2a_map_violations' ),
			array(
				'wc_product_id'   => $data['wc_product_id'] ?? null,
				'sku'             => $data['sku'] ?? null,
				'channel'         => (string) ( $data['channel'] ?? 'unknown' ),
				'map_price'       => (float) ( $data['map_price'] ?? 0 ),
				'attempted_price' => (float) ( $data['attempted_price'] ?? 0 ),
				'action_taken'    => (string) ( $data['action_taken'] ?? 'logged' ),
				'context'         => isset( $data['context'] ) ? wp_json_encode( $data['context'] ) : null,
				'actor_id'        => get_current_user_id() ?: null,
				'created_at'      => $this->now(),
			)
		);
	}

	public function list( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$t = $this->table( 'g2a_map_rules' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d",
				max( 1, min( 500, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		) ?: array();
	}
}
