<?php
/**
 * BridGistic action queue.
 *
 * Actions BridGistic classifies as `action` are appended to a bounded queue
 * in the `g2aba_bridgistic_queue` option. The owner reviews each one and
 * either approves (marks executed + calls the handler) or rejects (marks
 * rejected). The queue is intentionally small; anything above the cap
 * displaces the oldest resolved entry.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\BridGistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Action_Queue {
	private const OPTION      = 'g2aba_bridgistic_queue';
	private const MAX_ENTRIES = 200;

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * @return array<int, array>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	public function pending(): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( $entry ) => ( $entry['status'] ?? '' ) === self::STATUS_PENDING
			)
		);
	}

	/**
	 * Insert a new action. Returns the created entry with its assigned id.
	 */
	public function enqueue( string $query, string $intent, int $requester_id ): array {
		$id    = self::generate_id();
		$entry = array(
			'id'          => $id,
			'query'       => $query,
			'intent'      => $intent,
			'status'      => self::STATUS_PENDING,
			'requesterId' => $requester_id,
			'createdAt'   => gmdate( 'c' ),
			'resolvedAt'  => null,
			'result'      => null,
		);

		$all = $this->all();
		array_unshift( $all, $entry );

		if ( count( $all ) > self::MAX_ENTRIES ) {
			// Drop the oldest already-resolved entry so pending items are never
			// silently evicted.
			$all = self::trim_resolved( $all, self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $all, false );
		return $entry;
	}

	public function resolve( string $id, string $status, ?string $result = null ): ?array {
		if ( ! in_array( $status, array( self::STATUS_APPROVED, self::STATUS_REJECTED ), true ) ) {
			return null;
		}

		$all      = $this->all();
		$updated  = null;
		$replaced = false;
		foreach ( $all as $i => $entry ) {
			if ( ( $entry['id'] ?? '' ) !== $id ) {
				continue;
			}
			if ( ( $entry['status'] ?? '' ) !== self::STATUS_PENDING ) {
				return null; // Already resolved — don't allow double-resolve.
			}
			$entry['status']     = $status;
			$entry['resolvedAt'] = gmdate( 'c' );
			$entry['result']     = $result;
			$all[ $i ]           = $entry;
			$updated             = $entry;
			$replaced            = true;
			break;
		}
		if ( ! $replaced ) {
			return null;
		}
		update_option( self::OPTION, $all, false );
		return $updated;
	}

	public function find( string $id ): ?array {
		foreach ( $this->all() as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Overwrite an entry's `result` string in place. Used by Executor after
	 * it processes an approved action so the owner can see what actually
	 * happened without re-resolving the entry.
	 */
	public function attach_result( string $id, string $result ): ?array {
		$all      = $this->all();
		$replaced = null;
		foreach ( $all as $i => $entry ) {
			if ( ( $entry['id'] ?? '' ) !== $id ) {
				continue;
			}
			$entry['result'] = $result;
			$all[ $i ]       = $entry;
			$replaced        = $entry;
			break;
		}
		if ( null === $replaced ) {
			return null;
		}
		update_option( self::OPTION, $all, false );
		return $replaced;
	}

	private static function trim_resolved( array $all, int $max ): array {
		$resolved_indices = array();
		foreach ( $all as $i => $entry ) {
			if ( ( $entry['status'] ?? '' ) !== self::STATUS_PENDING ) {
				$resolved_indices[] = $i;
			}
		}
		// Drop the tail of resolved entries first — that's the oldest.
		while ( count( $all ) > $max && count( $resolved_indices ) > 0 ) {
			$drop = array_pop( $resolved_indices );
			unset( $all[ $drop ] );
		}
		return array_values( $all );
	}

	public static function generate_id(): string {
		return 'act_' . bin2hex( random_bytes( 6 ) );
	}
}
