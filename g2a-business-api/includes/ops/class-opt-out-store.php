<?php
/**
 * Opt-out compliance store.
 *
 * Owners of the plugin can (and legally must, in most jurisdictions)
 * respect recipient opt-outs. This class is the single source of truth:
 * every send path checks `opted_out()` before dispatch, and the public
 * unsubscribe route writes here via `record()`.
 *
 * Keyed by the *lowercased* email — WordPress user tables preserve case
 * but SMTP + practically every marketing rule treats addresses as
 * case-insensitive.
 *
 * Value stored: `{ optedOutAt: ISO8601, reason: string }`. Reason lets us
 * distinguish a customer-initiated opt-out from an operator-driven one.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opt_Out_Store {
	private const OPTION = 'g2aba_opt_outs';
	private const MAX    = 5000;

	public function record( string $email, string $reason = 'user_requested' ): void {
		$key = self::key( $email );
		if ( '' === $key ) {
			return;
		}
		$raw          = $this->all_raw();
		$raw[ $key ]  = array(
			'email'      => $key,
			'optedOutAt' => gmdate( 'c' ),
			'reason'     => $reason,
		);
		if ( count( $raw ) > self::MAX ) {
			// Ring-buffer semantics: drop the oldest entries.
			uasort( $raw, static fn( $a, $b ) => strcmp( (string) $a['optedOutAt'], (string) $b['optedOutAt'] ) );
			$raw = array_slice( $raw, count( $raw ) - self::MAX, null, true );
		}
		update_option( self::OPTION, $raw, false );
	}

	public function opted_out( string $email ): bool {
		$key = self::key( $email );
		return '' !== $key && isset( $this->all_raw()[ $key ] );
	}

	public function restore( string $email ): void {
		$key = self::key( $email );
		if ( '' === $key ) {
			return;
		}
		$raw = $this->all_raw();
		unset( $raw[ $key ] );
		update_option( self::OPTION, $raw, false );
	}

	public function count(): int {
		return count( $this->all_raw() );
	}

	/**
	 * @return array<string, array{email:string,optedOutAt:string,reason:string}>
	 */
	public function all_raw(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @internal Exposed for tests. Deterministically normalises an email
	 * for use as a key.
	 */
	public static function key( string $email ): string {
		$email = trim( strtolower( $email ) );
		return preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email ) ? $email : '';
	}
}
