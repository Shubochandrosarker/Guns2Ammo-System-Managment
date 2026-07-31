<?php

namespace G2A\POS\Database;

/**
 * Rental of a physical, serialised firearm for on-premises range use.
 *
 * Separate from AmmoRentalRepository on purpose — see the g2a_firearm_rentals
 * comment in Migrator for why the two lifecycles cannot share a record.
 *
 * This tracks the loan only. A rental for use on the premises is not a
 * transfer, so nothing here is an acquisition/disposition entry.
 */
final class FirearmRentalRepository extends Repository {

	public const STATUS_OUT       = 'out';
	public const STATUS_RETURNED  = 'returned';
	public const STATUS_OVERDUE   = 'overdue';
	public const STATUS_INCIDENT  = 'incident';

	/**
	 * Statuses a rental can hold. 'incident' covers a firearm that came back
	 * damaged or did not come back at all — it stays open so it can't quietly
	 * disappear from the outstanding list.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( self::STATUS_OUT, self::STATUS_RETURNED, self::STATUS_OVERDUE, self::STATUS_INCIDENT );
	}

	/**
	 * Issue a firearm to a customer.
	 *
	 * @param array<string,mixed> $data Rental fields.
	 * @return int|\WP_Error New rental id, or an error when the firearm is
	 *                       already out or the required checks are missing.
	 */
	public function issue( array $data ) {
		global $wpdb;

		$serial = trim( sanitize_text_field( (string) ( $data['serial_number'] ?? '' ) ) );
		if ( '' === $serial ) {
			return new \WP_Error( 'invalid_input', 'serial_number is required.', array( 'status' => 400 ) );
		}

		$customer = trim( sanitize_text_field( (string) ( $data['customer_name'] ?? '' ) ) );
		if ( '' === $customer ) {
			return new \WP_Error( 'invalid_input', 'customer_name is required.', array( 'status' => 400 ) );
		}

		// Handing a gun over without the identity check recorded is the whole
		// risk this feature exists to manage, so it is enforced here rather than
		// left to the UI.
		if ( empty( $data['id_verified'] ) ) {
			return new \WP_Error(
				'id_check_required',
				'Photo ID must be verified before a firearm is issued.',
				array( 'status' => 422 )
			);
		}

		if ( empty( $data['waiver_verified'] ) ) {
			return new \WP_Error(
				'waiver_required',
				'A signed range waiver must be verified before a firearm is issued.',
				array( 'status' => 422 )
			);
		}

		// The same physical firearm cannot be in two hands at once. Checked
		// against the serial because that is what staff scan/type at the
		// counter, and an inventory id may not exist for older rental stock.
		$open = $this->open_for_serial( $serial );
		if ( $open ) {
			return new \WP_Error(
				'already_out',
				sprintf( 'Serial %s is already checked out (rental #%d).', $serial, (int) $open['id'] ),
				array( 'status' => 409 )
			);
		}

		$now = $this->now();

		$inserted = $wpdb->insert(
			$this->table( 'g2a_firearm_rentals' ),
			array(
				'reservation_id'      => isset( $data['reservation_id'] ) ? (int) $data['reservation_id'] : null,
				'lane_id'             => isset( $data['lane_id'] ) ? (int) $data['lane_id'] : null,
				'inventory_item_id'   => isset( $data['inventory_item_id'] ) ? (int) $data['inventory_item_id'] : null,
				'serial_number'       => $serial,
				'manufacturer'        => sanitize_text_field( (string) ( $data['manufacturer'] ?? '' ) ),
				'model'               => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
				'caliber'             => sanitize_text_field( (string) ( $data['caliber'] ?? '' ) ),
				'customer_name'       => $customer,
				'customer_id'         => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'id_type'             => sanitize_text_field( (string) ( $data['id_type'] ?? '' ) ),
				// Only the last few characters are kept — enough for staff to
				// match the document back to the record, without storing a
				// full government ID number.
				'id_last4'            => substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $data['id_last4'] ?? '' ) ), -4 ),
				'id_verified'         => 1,
				'waiver_id'           => isset( $data['waiver_id'] ) ? (int) $data['waiver_id'] : null,
				'waiver_verified'     => 1,
				'rate_cents'          => max( 0, (int) ( $data['rate_cents'] ?? 0 ) ),
				'deposit_cents'       => max( 0, (int) ( $data['deposit_cents'] ?? 0 ) ),
				'total_charged_cents' => max( 0, (int) ( $data['rate_cents'] ?? 0 ) ),
				'status'              => self::STATUS_OUT,
				'issued_by'           => get_current_user_id(),
				'issued_at'           => $now,
				'due_back_at'         => ! empty( $data['due_back_at'] ) ? sanitize_text_field( (string) $data['due_back_at'] ) : null,
				'condition_out'       => sanitize_textarea_field( (string) ( $data['condition_out'] ?? '' ) ),
				'notes'               => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_error', 'Could not record the rental.', array( 'status' => 500 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Take a firearm back.
	 *
	 * @param int                 $id   Rental id.
	 * @param array<string,mixed> $data Return details.
	 * @return true|\WP_Error
	 */
	public function return_firearm( int $id, array $data = array() ) {
		global $wpdb;

		$rental = $this->get( $id );
		if ( ! $rental ) {
			return new \WP_Error( 'not_found', 'Rental not found.', array( 'status' => 404 ) );
		}

		if ( self::STATUS_RETURNED === $rental['status'] ) {
			return new \WP_Error( 'already_returned', 'This rental is already closed.', array( 'status' => 409 ) );
		}

		$status = sanitize_key( (string) ( $data['status'] ?? self::STATUS_RETURNED ) );
		if ( ! in_array( $status, array( self::STATUS_RETURNED, self::STATUS_INCIDENT ), true ) ) {
			$status = self::STATUS_RETURNED;
		}

		$now = $this->now();

		$updated = $wpdb->update(
			$this->table( 'g2a_firearm_rentals' ),
			array(
				'status'              => $status,
				'returned_by'         => get_current_user_id(),
				'returned_at'         => $now,
				'condition_in'        => sanitize_textarea_field( (string) ( $data['condition_in'] ?? '' ) ),
				'total_charged_cents' => isset( $data['total_charged_cents'] )
					? max( 0, (int) $data['total_charged_cents'] )
					: (int) $rental['total_charged_cents'],
				'pos_order_id'        => isset( $data['pos_order_id'] ) ? (int) $data['pos_order_id'] : $rental['pos_order_id'],
				'updated_at'          => $now,
			),
			array(
				'id'     => $id,
				// Optimistic lock: two staff closing the same rental at once must
				// not both succeed and double-charge.
				'status' => $rental['status'],
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'not_updated', 'Rental was modified by someone else — reload and retry.', array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table('g2a_firearm_rentals')} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * The still-open rental for a serial, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function open_for_serial( string $serial ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_firearm_rentals')}
				 WHERE serial_number = %s AND status IN ( %s, %s ) ORDER BY id DESC LIMIT 1",
				$serial,
				self::STATUS_OUT,
				self::STATUS_OVERDUE
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Every firearm currently off the rack, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_open(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_firearm_rentals')}
				 WHERE status IN ( %s, %s ) ORDER BY issued_at DESC",
				self::STATUS_OUT,
				self::STATUS_OVERDUE
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_firearm_rentals')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Flag rentals that have passed their due-back time so they surface as
	 * overdue rather than sitting in the open list looking normal. Returns the
	 * number of rows moved.
	 */
	public function mark_overdue(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table('g2a_firearm_rentals')}
				 SET status = %s, updated_at = %s
				 WHERE status = %s AND due_back_at IS NOT NULL AND due_back_at < %s",
				self::STATUS_OVERDUE,
				$this->now(),
				self::STATUS_OUT,
				$this->now()
			)
		);
	}
}
