<?php
/**
 * Dealer portal tokens — HMAC-signed single-use magic links.
 *
 * Responsibilities:
 *   - generate()  → create + persist a token; returns the raw string (only ever shown to dealer)
 *   - verify()    → validate signature, expiry, single-use, transfer match
 *   - consume()   → mark token as used, log IP / UA
 *   - revoke()    → invalidate a token (admin action, reissue, etc.)
 *   - find_by_transfer() → active token for a transfer (used by admin UI)
 *
 * Security:
 *   - HMAC-SHA256 signed with WPISTIC_FFL_TOKEN_SECRET (constant) or DB option (fallback).
 *   - Only SHA-256 hash of the token is stored — raw token never persists in DB.
 *   - Constant-time comparison via hash_equals() prevents timing attacks.
 *   - Expiry + single-use enforced in DB, not just in the signed payload.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Token {

	const DEFAULT_ACTION     = 'mark_received';
	const DEFAULT_EXPIRY_DAYS = 30;
	const TOKEN_VERSION      = 'v1';

	/**
	 * Retrieve the HMAC signing secret.
	 * Prefers the wp-config.php constant; falls back to an auto-generated option.
	 */
	public static function secret(): string {
		if ( defined( 'WPISTIC_FFL_TOKEN_SECRET' ) && WPISTIC_FFL_TOKEN_SECRET ) {
			return (string) WPISTIC_FFL_TOKEN_SECRET;
		}
		$opt = get_option( 'wpistic_ffl_token_secret' );
		if ( ! $opt ) {
			$opt = wp_generate_password( 64, true, true );
			update_option( 'wpistic_ffl_token_secret', $opt, false );
		}
		return (string) $opt;
	}

	/**
	 * Generate a new signed token and persist its hash.
	 *
	 * @param int    $transfer_id  Transfer this token authorizes.
	 * @param int    $dealer_id    Dealer expected to use this token.
	 * @param string $action       Action this token authorizes (mark_received / report_issue).
	 * @param int    $expiry_days  Override default expiry window.
	 * @return string|\WP_Error    Raw token for the outgoing email, or error.
	 */
	public static function generate( int $transfer_id, int $dealer_id, string $action = self::DEFAULT_ACTION, int $expiry_days = 0 ): string|\WP_Error {
		global $wpdb;

		if ( $transfer_id <= 0 || $dealer_id <= 0 ) {
			return new \WP_Error( 'invalid_args', 'transfer_id and dealer_id are required.' );
		}

		$settings   = get_option( 'wpistic_ffl_portal_settings', [] );
		$expiry_days = $expiry_days ?: (int) ( $settings['token_expiry_days'] ?? self::DEFAULT_EXPIRY_DAYS );
		if ( $expiry_days < 1 ) {
			$expiry_days = self::DEFAULT_EXPIRY_DAYS;
		}

		$expires_ts = time() + ( $expiry_days * DAY_IN_SECONDS );
		$nonce      = bin2hex( random_bytes( 8 ) );

		// Payload format: v1.tid.did.action.exp.nonce
		$payload   = implode( '.', [ self::TOKEN_VERSION, $transfer_id, $dealer_id, $action, $expires_ts, $nonce ] );
		$signature = self::sign( $payload );
		$raw_token = self::base64url_encode( $payload . '.' . $signature );

		$hash    = hash( 'sha256', $raw_token );
		$ip_bin  = self::ip_to_binary( self::client_ip() );

		// Invalidate any other active tokens for this transfer + action (one active at a time).
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'UPDATE ' . DB::table( 'dealer_tokens' ) . '
			 SET status = %s
			 WHERE transfer_id = %d AND action = %s AND status = %s',
			'revoked',
			$transfer_id,
			$action,
			'active'
		) );

		$inserted = $wpdb->insert( DB::table( 'dealer_tokens' ), [ // phpcs:ignore WordPress.DB
			'token_hash'  => $hash,
			'transfer_id' => $transfer_id,
			'dealer_id'   => $dealer_id,
			'action'      => $action,
			'expires_at'  => gmdate( 'Y-m-d H:i:s', $expires_ts ),
			'status'      => 'active',
			'created_ip'  => $ip_bin,
		], [ '%s', '%d', '%d', '%s', '%s', '%s', '%s' ] );

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'Could not persist token.' );
		}

		return $raw_token;
	}

	/**
	 * Verify a token. Returns the DB row + decoded payload on success, WP_Error on failure.
	 * Does NOT mark the token as used — call consume() after the action succeeds.
	 *
	 * @param string $raw_token
	 * @return array|\WP_Error  [ 'row' => object, 'payload' => array ]
	 */
	public static function verify( string $raw_token ): array|\WP_Error {
		global $wpdb;

		$raw_token = trim( $raw_token );
		if ( '' === $raw_token ) {
			return new \WP_Error( 'missing_token', __( 'Missing confirmation token.', 'advanced-ffl-checkout' ), [ 'status' => 400 ] );
		}

		// Decode + split payload
		$decoded = self::base64url_decode( $raw_token );
		if ( false === $decoded ) {
			return new \WP_Error( 'invalid_token', __( 'Invalid confirmation token.', 'advanced-ffl-checkout' ), [ 'status' => 400 ] );
		}

		$parts = explode( '.', $decoded );
		if ( 7 !== count( $parts ) ) {
			return new \WP_Error( 'invalid_token', __( 'Malformed confirmation token.', 'advanced-ffl-checkout' ), [ 'status' => 400 ] );
		}

		[ $ver, $tid, $did, $action, $exp, $nonce, $sig ] = $parts;

		if ( self::TOKEN_VERSION !== $ver ) {
			return new \WP_Error( 'invalid_token', __( 'Unsupported token version.', 'advanced-ffl-checkout' ), [ 'status' => 400 ] );
		}

		// Verify signature on the payload portion (everything except the trailing sig)
		$payload   = implode( '.', [ $ver, $tid, $did, $action, $exp, $nonce ] );
		$expected  = self::sign( $payload );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new \WP_Error( 'invalid_signature', __( 'Invalid token signature.', 'advanced-ffl-checkout' ), [ 'status' => 403 ] );
		}

		// Expiry check (defence in depth — DB also enforces)
		if ( (int) $exp < time() ) {
			return new \WP_Error( 'token_expired', __( 'This confirmation link has expired. Please request a new one.', 'advanced-ffl-checkout' ), [ 'status' => 410 ] );
		}

		// Lookup DB row
		$hash = hash( 'sha256', $raw_token );
		$row  = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'dealer_tokens' ) . ' WHERE token_hash = %s LIMIT 1',
			$hash
		) );

		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'Confirmation link is not recognised.', 'advanced-ffl-checkout' ), [ 'status' => 404 ] );
		}

		if ( 'revoked' === $row->status ) {
			return new \WP_Error( 'token_revoked', __( 'This confirmation link has been revoked.', 'advanced-ffl-checkout' ), [ 'status' => 410 ] );
		}

		if ( 'used' === $row->status || ! empty( $row->used_at ) ) {
			return new \WP_Error( 'token_used', __( 'This link has already been used.', 'advanced-ffl-checkout' ), [
				'status'        => 409,
				'already_used'  => true,
				'used_at'       => $row->used_at,
				'used_action'   => $row->used_action,
				'transfer_id'   => (int) $row->transfer_id,
			] );
		}

		return [
			'row'     => $row,
			'payload' => [
				'version'     => $ver,
				'transfer_id' => (int) $tid,
				'dealer_id'   => (int) $did,
				'action'      => $action,
				'expires_at'  => (int) $exp,
				'nonce'       => $nonce,
			],
		];
	}

	/**
	 * Mark token as used + log actor metadata.
	 *
	 * @param int    $token_id
	 * @param string $used_action  Action actually taken (may differ from generated action, e.g., report_issue instead of mark_received).
	 */
	public static function consume( int $token_id, string $used_action ): bool {
		global $wpdb;

		$single_use = (int) ( get_option( 'wpistic_ffl_portal_settings', [] )['single_use'] ?? 1 );

		$data = [
			'used_at'          => current_time( 'mysql', true ),
			'used_action'      => $used_action,
			'used_ip'          => self::ip_to_binary( self::client_ip() ),
			'used_user_agent'  => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
			'status'           => $single_use ? 'used' : 'active',
		];

		$fmt  = [ '%s', '%s', '%s', '%s', '%s' ];
		$ok   = $wpdb->update( DB::table( 'dealer_tokens' ), $data, [ 'id' => $token_id ], $fmt, [ '%d' ] ); // phpcs:ignore WordPress.DB
		return false !== $ok;
	}

	/**
	 * Admin-side revocation (e.g., on dealer change, transfer cancel).
	 */
	public static function revoke_all_for_transfer( int $transfer_id ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'UPDATE ' . DB::table( 'dealer_tokens' ) . ' SET status = %s WHERE transfer_id = %d AND status = %s',
			'revoked',
			$transfer_id,
			'active'
		) );
	}

	/**
	 * Active token row for a given transfer (used to resend email without re-issuing).
	 */
	public static function active_for_transfer( int $transfer_id, string $action = self::DEFAULT_ACTION ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'dealer_tokens' ) . '
			 WHERE transfer_id = %d AND action = %s AND status = %s
			 ORDER BY created_at DESC LIMIT 1',
			$transfer_id,
			$action,
			'active'
		) );
		return $row ?: null;
	}

	/**
	 * Build the public portal URL for a raw token.
	 */
	public static function build_url( string $raw_token ): string {
		$slug = (string) ( get_option( 'wpistic_ffl_portal_settings', [] )['portal_slug'] ?? WPISTIC_FFL_PORTAL_SLUG );
		$slug = trim( $slug, '/' );
		return trailingslashit( home_url( '/' . $slug . '/' ) ) . rawurlencode( $raw_token ) . '/';
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, self::secret() );
	}

	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function base64url_decode( string $data ): string|false {
		$data = strtr( $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data, true );
	}

	/**
	 * Resolve the client IP, but only honor X-Forwarded-For / CF-Connecting-IP
	 * when the immediate REMOTE_ADDR is a trusted proxy. Defaults to "none
	 * trusted" so an attacker cannot spoof rate-limit or audit-log entries.
	 * Sites behind Cloudflare / a load balancer can extend the trust list via
	 * the wpistic_ffl_trusted_proxies filter (CIDR array, or '*' to trust all).
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$trusted     = (array) apply_filters( 'wpistic_ffl_trusted_proxies', [] );
		$trust_proxy = in_array( '*', $trusted, true ) || self::ip_in_cidr_list( $remote, $trusted );

		if ( $trust_proxy ) {
			foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ] as $key ) {
				if ( ! empty( $_SERVER[ $key ] ) ) {
					$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
					if ( false !== strpos( $ip, ',' ) ) {
						$ip = trim( explode( ',', $ip )[0] );
					}
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
		}

		if ( $remote && filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}
		return '0.0.0.0';
	}

	/**
	 * Test whether $ip belongs to any of the given CIDR strings (IPv4 or IPv6).
	 */
	private static function ip_in_cidr_list( string $ip, array $cidrs ): bool {
		if ( ! $ip || empty( $cidrs ) ) {
			return false;
		}
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		foreach ( $cidrs as $cidr ) {
			if ( '*' === $cidr ) {
				return true;
			}
			if ( false === strpos( $cidr, '/' ) ) {
				if ( $packed === @inet_pton( $cidr ) ) {
					return true;
				}
				continue;
			}
			[ $subnet, $bits ] = explode( '/', $cidr, 2 );
			$subnet_packed     = @inet_pton( $subnet );
			if ( false === $subnet_packed ) {
				continue;
			}
			$bits       = (int) $bits;
			$byte_count = intdiv( $bits, 8 );
			$remaining  = $bits % 8;
			if ( substr( $packed, 0, $byte_count ) !== substr( $subnet_packed, 0, $byte_count ) ) {
				continue;
			}
			if ( 0 === $remaining ) {
				return true;
			}
			$mask = chr( 0xFF << ( 8 - $remaining ) & 0xFF );
			if ( ( $packed[ $byte_count ] & $mask ) === ( $subnet_packed[ $byte_count ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	private static function ip_to_binary( string $ip ): ?string {
		$packed = @inet_pton( $ip );
		return $packed === false ? null : $packed;
	}
}
