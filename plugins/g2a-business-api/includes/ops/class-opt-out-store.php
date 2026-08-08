<?php
/**
 * Opt-out compliance store — category-scoped.
 *
 * Recipients can opt out of a specific category (`marketing`, `renewals`,
 * `transactional`) rather than all mail — often a legal expectation and
 * always a good citizenship move.
 *
 * Storage shape: `{ email => { category => { optedOutAt, reason } } }`.
 *
 * The special category `all` blocks every category. This preserves the
 * Phase-8 semantics and lets us migrate an unadorned "opt-out of X" that
 * we don't have a category for yet without breaking the rules.
 *
 * Backward compatibility: entries persisted by Phase 8 used a flat
 * `{ email => { optedOutAt, reason } }` shape. `all_raw()` detects that on
 * read and lifts each row into the `all` category on the fly. This is
 * safe because Phase-8 opt-outs meant "opt out of everything," which is
 * exactly what the `all` category still means.
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

	public const CATEGORY_ALL           = 'all';
	public const CATEGORY_MARKETING     = 'marketing';
	public const CATEGORY_RENEWALS      = 'renewals';
	public const CATEGORY_TRANSACTIONAL = 'transactional';

	/**
	 * `internal` is deliberately not stored — internal-staff mail bypasses
	 * the opt-out gate entirely (see Email_Sender::send).
	 */
	public const CATEGORY_INTERNAL = 'internal';

	/**
	 * @return array<int, string>
	 */
	public static function categories(): array {
		return array(
			self::CATEGORY_ALL,
			self::CATEGORY_MARKETING,
			self::CATEGORY_RENEWALS,
			self::CATEGORY_TRANSACTIONAL,
		);
	}

	public function record( string $email, string $category = self::CATEGORY_ALL, string $reason = 'user_requested' ): void {
		$key = self::key( $email );
		$cat = self::normalize_category( $category );
		if ( '' === $key || '' === $cat ) {
			return;
		}
		$raw = $this->all_raw();
		if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
			$raw[ $key ] = array();
		}
		$raw[ $key ][ $cat ] = array(
			'optedOutAt' => gmdate( 'c' ),
			'reason'     => $reason,
		);
		if ( count( $raw ) > self::MAX ) {
			$raw = self::trim( $raw, self::MAX );
		}
		update_option( self::OPTION, $raw, false );
	}

	/**
	 * True when the recipient has opted out of the requested category,
	 * OR of `all` — the umbrella `all` always wins.
	 */
	public function opted_out( string $email, string $category = self::CATEGORY_ALL ): bool {
		$key = self::key( $email );
		if ( '' === $key ) {
			return false;
		}
		$raw = $this->all_raw();
		if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
			return false;
		}
		if ( isset( $raw[ $key ][ self::CATEGORY_ALL ] ) ) {
			return true;
		}
		$cat = self::normalize_category( $category );
		if ( '' === $cat || self::CATEGORY_ALL === $cat ) {
			return false;
		}
		return isset( $raw[ $key ][ $cat ] );
	}

	public function restore( string $email, string $category = self::CATEGORY_ALL ): void {
		$key = self::key( $email );
		$cat = self::normalize_category( $category );
		if ( '' === $key ) {
			return;
		}
		$raw = $this->all_raw();
		if ( ! isset( $raw[ $key ] ) ) {
			return;
		}

		if ( self::CATEGORY_ALL === $cat ) {
			// Restore fully — drop every category for this address.
			unset( $raw[ $key ] );
		} else {
			unset( $raw[ $key ][ $cat ] );
			if ( empty( $raw[ $key ] ) ) {
				unset( $raw[ $key ] );
			}
		}
		update_option( self::OPTION, $raw, false );
	}

	public function count(): int {
		return count( $this->all_raw() );
	}

	/**
	 * Return the store in its normalised (category-scoped) shape,
	 * migrating any legacy rows on the fly.
	 *
	 * @return array<string, array<string, array{optedOutAt:string,reason:string}>>
	 */
	public function all_raw(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$normalised = array();
		foreach ( $raw as $email => $row ) {
			$email = (string) $email;
			if ( ! is_array( $row ) ) {
				continue;
			}
			// Legacy shape: { optedOutAt, reason, email } — no category keys.
			if ( self::is_legacy_row( $row ) ) {
				$normalised[ $email ] = array(
					self::CATEGORY_ALL => array(
						'optedOutAt' => (string) ( $row['optedOutAt'] ?? gmdate( 'c' ) ),
						'reason'     => (string) ( $row['reason'] ?? 'legacy' ),
					),
				);
				continue;
			}
			// Already the categorised shape.
			$normalised[ $email ] = $row;
		}
		return $normalised;
	}

	/**
	 * Flatten to a table-friendly view for the WP-admin page.
	 *
	 * @return array<int, array{email:string,category:string,optedOutAt:string,reason:string}>
	 */
	public function all_entries(): array {
		$out = array();
		foreach ( $this->all_raw() as $email => $categories ) {
			foreach ( $categories as $cat => $meta ) {
				$out[] = array(
					'email'      => (string) $email,
					'category'   => (string) $cat,
					'optedOutAt' => (string) ( $meta['optedOutAt'] ?? '' ),
					'reason'     => (string) ( $meta['reason'] ?? '' ),
				);
			}
		}
		usort(
			$out,
			static fn( $a, $b ) => strcmp( (string) $b['optedOutAt'], (string) $a['optedOutAt'] )
		);
		return $out;
	}

	public static function key( string $email ): string {
		$email = trim( strtolower( $email ) );
		return preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email ) ? $email : '';
	}

	public static function normalize_category( string $category ): string {
		$category = strtolower( trim( $category ) );
		if ( '' === $category ) {
			return self::CATEGORY_ALL;
		}
		if ( ! in_array( $category, self::categories(), true ) ) {
			return '';
		}
		return $category;
	}

	private static function is_legacy_row( array $row ): bool {
		// A legacy row has scalar `optedOutAt` at the top level rather than
		// a category → object nested map.
		if ( isset( $row['optedOutAt'] ) && ! is_array( $row['optedOutAt'] ) ) {
			return true;
		}
		return false;
	}

	private static function trim( array $raw, int $max ): array {
		uasort(
			$raw,
			static function ( $a, $b ) {
				$ax = is_array( $a ) ? array_column( $a, 'optedOutAt' ) : array();
				$bx = is_array( $b ) ? array_column( $b, 'optedOutAt' ) : array();
				return strcmp( (string) ( $ax ? min( $ax ) : '' ), (string) ( $bx ? min( $bx ) : '' ) );
			}
		);
		return array_slice( $raw, count( $raw ) - $max, null, true );
	}
}
