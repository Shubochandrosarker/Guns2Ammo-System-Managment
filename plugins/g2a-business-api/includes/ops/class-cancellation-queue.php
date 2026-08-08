<?php
/**
 * Cancellation review queue.
 *
 * BridGistic never auto-cancels a booking. When an owner approves a
 * `cancel_booking` action, the Executor writes the request here for a
 * human to review + finalize in the Booking Engine admin. This class owns
 * the persistence + state machine.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cancellation_Queue {
	private const OPTION     = 'g2aba_cancellations';
	private const LEGACY_OPT = 'g2aba_bridgistic_cancel_queue';
	private const MAX        = 200;

	public const STATUS_AWAITING = 'awaiting_manual_action';
	public const STATUS_DONE     = 'completed';
	public const STATUS_DROPPED  = 'dropped';

	/**
	 * @return array<int, array>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, null );
		if ( null === $raw || false === $raw ) {
			$raw = $this->maybe_migrate_legacy();
		}
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	public function awaiting(): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( $c ) => ( $c['status'] ?? '' ) === self::STATUS_AWAITING
			)
		);
	}

	public function enqueue( ?string $booking_id, string $query ): array {
		$entry = array(
			'id'         => 'cx_' . bin2hex( random_bytes( 6 ) ),
			'bookingId'  => $booking_id,
			'query'      => $query,
			'status'     => self::STATUS_AWAITING,
			'createdAt'  => gmdate( 'c' ),
			'resolvedAt' => null,
			'notes'      => null,
		);

		$all = $this->all();
		array_unshift( $all, $entry );
		if ( count( $all ) > self::MAX ) {
			// Only evict resolved entries so awaiting requests are never lost.
			$out    = array();
			$dropped_a_resolved = false;
			foreach ( $all as $row ) {
				if ( count( $out ) < self::MAX ) {
					$out[] = $row;
					continue;
				}
				// Over cap — drop only if this row is resolved.
				if ( ( $row['status'] ?? '' ) !== self::STATUS_AWAITING ) {
					$dropped_a_resolved = true;
					continue;
				}
				// If we can't drop resolved rows because there aren't any, keep
				// growing rather than losing an awaiting request.
				$out[] = $row;
			}
			if ( $dropped_a_resolved ) {
				$all = $out;
			}
		}
		update_option( self::OPTION, $all, false );
		return $entry;
	}

	public function find( string $id ): ?array {
		foreach ( $this->all() as $row ) {
			if ( ( $row['id'] ?? '' ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	public function mark_completed( string $id, string $notes = '' ): ?array {
		return $this->resolve( $id, self::STATUS_DONE, $notes );
	}

	public function drop( string $id, string $notes = '' ): ?array {
		return $this->resolve( $id, self::STATUS_DROPPED, $notes );
	}

	private function resolve( string $id, string $status, string $notes ): ?array {
		$all      = $this->all();
		$updated  = null;
		foreach ( $all as $i => $row ) {
			if ( ( $row['id'] ?? '' ) !== $id ) {
				continue;
			}
			if ( ( $row['status'] ?? '' ) !== self::STATUS_AWAITING ) {
				return null;
			}
			$row['status']     = $status;
			$row['resolvedAt'] = gmdate( 'c' );
			$row['notes']      = '' !== $notes ? $notes : null;
			$all[ $i ]         = $row;
			$updated           = $row;
			break;
		}
		if ( null === $updated ) {
			return null;
		}
		update_option( self::OPTION, $all, false );
		return $updated;
	}

	private function maybe_migrate_legacy(): array {
		$legacy = get_option( self::LEGACY_OPT, array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			update_option( self::OPTION, array(), false );
			return array();
		}
		$out = array();
		foreach ( $legacy as $row ) {
			$row = is_array( $row ) ? $row : array();
			$out[] = array(
				'id'         => 'cx_' . bin2hex( random_bytes( 6 ) ),
				'bookingId'  => $row['bookingId'] ?? null,
				'query'      => (string) ( $row['query'] ?? '' ),
				'status'     => (string) ( $row['status'] ?? self::STATUS_AWAITING ),
				'createdAt'  => (string) ( $row['createdAt'] ?? gmdate( 'c' ) ),
				'resolvedAt' => null,
				'notes'      => null,
			);
		}
		update_option( self::OPTION, $out, false );
		delete_option( self::LEGACY_OPT );
		return $out;
	}
}
