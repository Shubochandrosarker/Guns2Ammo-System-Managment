<?php
/**
 * Email draft store.
 *
 * Owns the persistence of email drafts that BridGistic creates when a
 * `send_email` action is approved. A draft carries just enough to send it
 * (`to`, `subject`, `body`) plus the natural-language query it came from,
 * so the reviewer can double-check intent before Send.
 *
 * Drafts never carry PII the operator didn't already type. The `to` field
 * is best-effort — if the query names a member, the sender resolves the
 * address at send time (Memberistic is the source of truth).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Draft_Store {
	private const OPTION      = 'g2aba_email_drafts';
	private const LEGACY_OPT  = 'g2aba_bridgistic_email_drafts';
	private const MAX_ENTRIES = 200;

	public const STATUS_PENDING  = 'pending_send';
	public const STATUS_SENT     = 'sent';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_DISCARDED = 'discarded';

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

	/**
	 * @return array<int, array>
	 */
	public function pending(): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( $d ) => ( $d['status'] ?? '' ) === self::STATUS_PENDING
			)
		);
	}

	public function enqueue( string $query, string $to = '', string $subject = '', string $body = '', string $category = Opt_Out_Store::CATEGORY_ALL ): array {
		$normalised_category = Opt_Out_Store::normalize_category( $category );
		if ( '' === $normalised_category && Opt_Out_Store::CATEGORY_INTERNAL === $category ) {
			$normalised_category = Opt_Out_Store::CATEGORY_INTERNAL;
		}
		if ( '' === $normalised_category ) {
			$normalised_category = Opt_Out_Store::CATEGORY_ALL;
		}
		$draft = array(
			'id'        => 'em_' . bin2hex( random_bytes( 6 ) ),
			'query'     => $query,
			'to'        => $to,
			'subject'   => '' !== $subject ? $subject : self::first_sentence( $query ),
			'body'      => '' !== $body ? $body : $query,
			'category'  => $normalised_category,
			'status'    => self::STATUS_PENDING,
			'createdAt' => gmdate( 'c' ),
			'sentAt'    => null,
			'error'     => null,
		);

		$all = $this->all();
		array_unshift( $all, $draft );
		if ( count( $all ) > self::MAX_ENTRIES ) {
			$all = self::trim_non_pending( $all, self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $all, false );
		return $draft;
	}

	public function find( string $id ): ?array {
		foreach ( $this->all() as $draft ) {
			if ( ( $draft['id'] ?? '' ) === $id ) {
				return $draft;
			}
		}
		return null;
	}

	/**
	 * Transition a draft to a new status. Refuses to move away from a
	 * terminal state — a sent draft is not resendable, a discarded draft is
	 * not un-discardable.
	 */
	public function transition( string $id, string $status, ?string $error = null ): ?array {
		if ( ! in_array( $status, array( self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_DISCARDED ), true ) ) {
			return null;
		}

		$all      = $this->all();
		$updated  = null;
		$replaced = false;

		foreach ( $all as $i => $draft ) {
			if ( ( $draft['id'] ?? '' ) !== $id ) {
				continue;
			}
			$current = (string) ( $draft['status'] ?? '' );
			if ( self::STATUS_SENT === $current || self::STATUS_DISCARDED === $current ) {
				return null; // Terminal state.
			}
			$draft['status'] = $status;
			$draft['error']  = $error;
			if ( self::STATUS_SENT === $status ) {
				$draft['sentAt'] = gmdate( 'c' );
			}
			$all[ $i ]  = $draft;
			$updated    = $draft;
			$replaced   = true;
			break;
		}
		if ( ! $replaced ) {
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
				'id'        => 'em_' . bin2hex( random_bytes( 6 ) ),
				'query'     => (string) ( $row['query'] ?? '' ),
				'to'        => '',
				'subject'   => self::first_sentence( (string) ( $row['query'] ?? '' ) ),
				'body'      => (string) ( $row['query'] ?? '' ),
				'status'    => (string) ( $row['status'] ?? self::STATUS_PENDING ),
				'createdAt' => (string) ( $row['createdAt'] ?? gmdate( 'c' ) ),
				'sentAt'    => null,
				'error'     => null,
			);
		}
		update_option( self::OPTION, $out, false );
		delete_option( self::LEGACY_OPT );
		return $out;
	}

	private static function trim_non_pending( array $all, int $max ): array {
		$non_pending = array();
		foreach ( $all as $i => $d ) {
			if ( ( $d['status'] ?? '' ) !== self::STATUS_PENDING ) {
				$non_pending[] = $i;
			}
		}
		while ( count( $all ) > $max && count( $non_pending ) > 0 ) {
			$drop = array_pop( $non_pending );
			unset( $all[ $drop ] );
		}
		return array_values( $all );
	}

	public static function first_sentence( string $s ): string {
		$s = trim( $s );
		if ( '' === $s ) {
			return 'Draft from BridGistic';
		}
		// Truncate at first sentence boundary or 60 chars, whichever wins.
		if ( preg_match( '/^(.{5,60}?[.!?])\s/', $s, $m ) ) {
			return $m[1];
		}
		return mb_strlen( $s ) > 60 ? mb_substr( $s, 0, 60 ) . '…' : $s;
	}
}
