<?php

namespace G2A\POS\Database;

final class AuditLogRepository extends Repository {

	public function add( string $action, string $object_type, string $object_id, ?array $before, ?array $after ): void {
		global $wpdb;

		$prev            = $this->last_row_hash();
		$row             = array(
			'actor_id'    => get_current_user_id(),
			'action'      => $action,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'before_data' => $before ? wp_json_encode( $before ) : null,
			'after_data'  => $after ? wp_json_encode( $after ) : null,
			'ip_address'  => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'user_agent'  => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
			'prev_hash'   => $prev,
			'created_at'  => $this->now(),
		);
		$row['row_hash'] = $this->hash_row( $row, $prev );

		$wpdb->insert( $this->table( 'g2a_audit_logs' ), $row );
	}

	public function verify_chain( int $limit = 5000 ): array {
		global $wpdb;
		$t      = $this->table( 'g2a_audit_logs' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev   = null;
		$broken = array();
		foreach ( $rows as $row ) {
			$check = $row;
			unset( $check['row_hash'] );
			$expected = $this->hash_row( $check, $prev );
			if ( ! hash_equals( (string) ( $row['row_hash'] ?? '' ), $expected ) || (string) ( $row['prev_hash'] ?? '' ) !== (string) ( $prev ?? '' ) ) {
				$broken[] = (int) $row['id'];
			}
			$prev = $row['row_hash'];
		}
		return array(
			'rows_checked' => count( $rows ),
			'broken'       => $broken,
			'ok'           => empty( $broken ),
		);
	}

	private function last_row_hash(): ?string {
		global $wpdb;
		$t   = $this->table( 'g2a_audit_logs' );
		$val = $wpdb->get_var( "SELECT row_hash FROM {$t} ORDER BY id DESC LIMIT 1" );
		return $val ? (string) $val : null;
	}

	private function hash_row( array $row, ?string $prev ): string {
		$copy = $row;
		unset( $copy['row_hash'] );
		ksort( $copy );
		$payload = ( $prev ?? '' ) . '|' . wp_json_encode( $copy );
		return hash_hmac( 'sha256', $payload, wp_salt( 'logged_in' ) );
	}
}
