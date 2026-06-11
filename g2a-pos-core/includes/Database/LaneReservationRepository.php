<?php

namespace G2A\POS\Database;

final class LaneReservationRepository extends Repository {

	public function reserve( array $data ): array {
		global $wpdb;
		$lane     = sanitize_text_field( $data['lane_code'] ?? '' );
		$location = (int) ( $data['location_id'] ?? 1 );
		$starts   = sanitize_text_field( $data['starts_at'] ?? '' );
		$ends     = sanitize_text_field( $data['ends_at'] ?? '' );

		if ( ! $lane || ! $starts || ! $ends ) {
			return array(
				'ok'    => false,
				'error' => 'lane_code, starts_at, ends_at required',
			);
		}

		$t        = $this->table( 'g2a_lane_reservations' );
		$conflict = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t}
             WHERE lane_code = %s AND location_id = %d AND status IN ('reserved','checked_in')
             AND NOT (ends_at <= %s OR starts_at >= %s)",
				$lane,
				$location,
				$starts,
				$ends
			)
		);
		if ( $conflict > 0 ) {
			return array(
				'ok'    => false,
				'error' => 'lane_conflict',
			);
		}

		$now = $this->now();
		$wpdb->insert(
			$t,
			array(
				'lane_code'      => $lane,
				'location_id'    => $location,
				'customer_id'    => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'waiver_id'      => isset( $data['waiver_id'] ) ? (int) $data['waiver_id'] : null,
				'customer_name'  => sanitize_text_field( $data['customer_name'] ?? '' ),
				'customer_phone' => sanitize_text_field( $data['customer_phone'] ?? '' ),
				'customer_email' => sanitize_email( $data['customer_email'] ?? '' ),
				'party_size'     => max( 1, (int) ( $data['party_size'] ?? 1 ) ),
				'starts_at'      => $starts,
				'ends_at'        => $ends,
				'status'         => 'reserved',
				'source'         => sanitize_key( $data['source'] ?? 'pos' ),
				'actor_id'       => get_current_user_id(),
				'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		return array(
			'ok' => true,
			'id' => (int) $wpdb->insert_id,
		);
	}

	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		$set = array(
			'status'     => sanitize_key( $status ),
			'updated_at' => $this->now(),
		);
		if ( $status === 'checked_in' ) {
			$set['checked_in_at'] = $this->now();
		}
		if ( $status === 'checked_out' ) {
			$set['checked_out_at'] = $this->now();
		}
		return (bool) $wpdb->update( $this->table( 'g2a_lane_reservations' ), $set, array( 'id' => $id ) );
	}

	public function list_for_day( string $date, int $location_id = 0 ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_lane_reservations' );
		$start = $date . ' 00:00:00';
		$end   = $date . ' 23:59:59';
		if ( $location_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t} WHERE location_id = %d AND starts_at BETWEEN %s AND %s ORDER BY starts_at ASC",
					$location_id,
					$start,
					$end
				),
				ARRAY_A
			) ?: array();
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE starts_at BETWEEN %s AND %s ORDER BY starts_at ASC",
				$start,
				$end
			),
			ARRAY_A
		) ?: array();
	}
}
