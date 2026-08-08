<?php

namespace G2A\POS\Database;

final class RegisterSessionRepository extends Repository {

	public function get_open_session( string $register_code, int $location_id ): ?array {
		global $wpdb;

		$table = $this->table( 'g2a_register_sessions' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE register_code = %s AND location_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1",
				sanitize_text_field( $register_code ),
				$location_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function list_open_sessions( int $location_id = 0 ): array {
		global $wpdb;

		$table = $this->table( 'g2a_register_sessions' );
		if ( $location_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'open' AND location_id = %d ORDER BY id DESC",
					$location_id
				),
				ARRAY_A
			) ?: array();
		}

		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' ORDER BY id DESC", ARRAY_A ) ?: array();
	}

	public function open( string $register_code, int $location_id, float $opening_cash ): int {
		global $wpdb;

		$code      = sanitize_text_field( $register_code );
		$lock_name = 'g2a_pos_register_open_' . md5( $code . '|' . $location_id );
		$locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name ) );
		if ( $locked !== 1 ) {
			return 0;
		}

		try {
			$existing = $this->get_open_session( $code, $location_id );
			if ( $existing ) {
				return (int) $existing['id'];
			}

			$wpdb->insert(
				$this->table( 'g2a_register_sessions' ),
				array(
					'register_code' => $code,
					'location_id'   => $location_id,
					'opened_by'     => get_current_user_id(),
					'opening_cash'  => $opening_cash,
					'status'        => 'open',
					'opened_at'     => $this->now(),
				)
			);

			return (int) $wpdb->insert_id;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	public function close( int $session_id, float $closing_cash, int $actor_id, bool $force_close = false, ?string $notes = null ): bool {
		global $wpdb;

		$table   = $this->table( 'g2a_register_sessions' );
		$session = $wpdb->get_row( $wpdb->prepare( "SELECT opening_cash, opened_by FROM {$table} WHERE id = %d", $session_id ), ARRAY_A );
		if ( ! $session ) {
			return false;
		}

		if ( ! $force_close && (int) $session['opened_by'] !== $actor_id ) {
			return false;
		}

		$opening  = (float) $session['opening_cash'];
		$variance = $closing_cash - $opening;

		return (bool) $wpdb->update(
			$table,
			array(
				'closed_by'       => $actor_id,
				'closing_cash'    => $closing_cash,
				'expected_cash'   => $opening,
				'variance_amount' => $variance,
				'status'          => 'closed',
				'closed_at'       => $this->now(),
				'notes'           => $notes,
			),
			array(
				'id'     => $session_id,
				'status' => 'open',
			)
		);
	}
}
