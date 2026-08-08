<?php

namespace G2A\POS\Database;

use G2A\POS\Security\Crypto;

final class DistributorRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$t     = $this->table( 'g2a_distributors' );
		$now   = $this->now();
		$creds = ! empty( $data['credentials'] ) ? Crypto::encrypt( wp_json_encode( $data['credentials'] ) ) : null;
		$wpdb->insert(
			$t,
			array(
				'adapter'               => sanitize_key( (string) $data['adapter'] ),
				'name'                  => sanitize_text_field( (string) $data['name'] ),
				'source_type'           => sanitize_key( (string) ( $data['source_type'] ?? 'http' ) ),
				'endpoint_url'          => isset( $data['endpoint_url'] ) ? esc_url_raw( (string) $data['endpoint_url'] ) : null,
				'credentials_encrypted' => $creds,
				'schedule'              => sanitize_key( (string) ( $data['schedule'] ?? 'daily' ) ),
				'default_markup_pct'    => (float) ( $data['default_markup_pct'] ?? 0 ),
				'auto_publish'          => ! empty( $data['auto_publish'] ) ? 1 : 0,
				'enabled'               => isset( $data['enabled'] ) ? ( (int) (bool) $data['enabled'] ) : 1,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		$t      = $this->table( 'g2a_distributors' );
		$fields = array( 'updated_at' => $this->now() );
		foreach ( array( 'adapter', 'name', 'source_type', 'schedule' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$fields[ $k ] = sanitize_key( (string) $data[ $k ] );
			}
		}
		if ( isset( $data['endpoint_url'] ) ) {
			$fields['endpoint_url'] = esc_url_raw( (string) $data['endpoint_url'] );
		}
		if ( isset( $data['default_markup_pct'] ) ) {
			$fields['default_markup_pct'] = (float) $data['default_markup_pct'];
		}
		if ( isset( $data['auto_publish'] ) ) {
			$fields['auto_publish'] = ! empty( $data['auto_publish'] ) ? 1 : 0;
		}
		if ( isset( $data['enabled'] ) ) {
			$fields['enabled'] = ! empty( $data['enabled'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'credentials', $data ) ) {
			$fields['credentials_encrypted'] = $data['credentials'] ? Crypto::encrypt( wp_json_encode( $data['credentials'] ) ) : null;
		}
		if ( isset( $data['last_synced_at'] ) ) {
			$fields['last_synced_at'] = (string) $data['last_synced_at'];
		}
		return (bool) $wpdb->update( $t, $fields, array( 'id' => $id ) );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_distributors' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ? $this->expand( $row ) : null;
	}

	public function all(): array {
		global $wpdb;
		$t    = $this->table( 'g2a_distributors' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY name ASC", ARRAY_A ) ?: array();
		return array_map( array( $this, 'expand' ), $rows );
	}

	public function enabled_due( string $schedule ): array {
		global $wpdb;
		$t    = $this->table( 'g2a_distributors' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE enabled = 1 AND schedule = %s",
				$schedule
			),
			ARRAY_A
		) ?: array();
		return array_map( array( $this, 'expand' ), $rows );
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table( 'g2a_distributors' ), array( 'id' => $id ) );
	}

	private function expand( array $row ): array {
		$row['credentials'] = null;
		if ( ! empty( $row['credentials_encrypted'] ) ) {
			$plain              = Crypto::decrypt( (string) $row['credentials_encrypted'] );
			$row['credentials'] = $plain ? ( json_decode( $plain, true ) ?: null ) : null;
		}
		unset( $row['credentials_encrypted'] );
		return $row;
	}
}
