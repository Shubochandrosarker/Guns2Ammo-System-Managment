<?php
/**
 * Procedural helper functions.
 *
 * Loaded eagerly from the bootstrap. Keep this file thin — anything bigger
 * belongs in a dedicated class. Use these helpers from templates and external
 * code that doesn't want to instantiate models directly.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the absolute path to a plugin file.
 *
 * @param string $relative Relative path inside the plugin folder.
 * @return string
 */
function g2ab_path( $relative = '' ) {
	return G2AB_PATH . ltrim( $relative, '/' );
}

/**
 * Get the URL to a plugin asset.
 *
 * @param string $relative Relative path inside the plugin folder.
 * @return string
 */
function g2ab_url( $relative = '' ) {
	return G2AB_URL . ltrim( $relative, '/' );
}

/**
 * Generate a v4 UUID.
 *
 * Used for public-facing booking identifiers so we don't expose auto-increment
 * IDs in URLs or emails.
 *
 * @return string
 */
function g2ab_generate_uuid() {
	return wp_generate_uuid4();
}

/**
 * Get the full table name (with WP prefix) for a g2ab table.
 *
 * @param string $name Short table name (e.g., 'bookings').
 * @return string
 */
function g2ab_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'g2ab_' . $name;
}

/**
 * Get a plugin option with a default fallback.
 *
 * @param string $key     Option key (without prefix — auto-prefixed with g2ab_).
 * @param mixed  $default Default value if missing.
 * @return mixed
 */
function g2ab_get_option( $key, $default = false ) {
	$key = 0 === strpos( $key, 'g2ab_' ) ? $key : 'g2ab_' . $key;
	return get_option( $key, $default );
}

/**
 * Update a plugin option.
 *
 * @param string $key   Option key (auto-prefixed).
 * @param mixed  $value Value.
 * @return bool
 */
function g2ab_update_option( $key, $value ) {
	$key = 0 === strpos( $key, 'g2ab_' ) ? $key : 'g2ab_' . $key;
	return update_option( $key, $value );
}

/**
 * Check if the current user can perform a g2ab capability.
 *
 * Wrapper to keep capability strings consistent across the codebase.
 *
 * @param string $cap Capability without prefix (e.g., 'manage_bookings').
 * @return bool
 */
function g2ab_current_user_can( $cap ) {
	$cap = 0 === strpos( $cap, 'g2ab_' ) || 0 === strpos( $cap, 'manage_g2ab' ) || 0 === strpos( $cap, 'view_g2ab' ) || 0 === strpos( $cap, 'delete_g2ab' )
		? $cap
		: 'manage_g2ab_' . $cap;
	return current_user_can( $cap );
}

/**
 * Build a signed member-card QR payload for the staff console scanner.
 *
 * Format: g2ab:<person_id>:<exp_unix>:<hmac>
 * The staff console verifies this server-side. Validity defaults to 7
 * days so members can re-print their card monthly without expiring it
 * between visits. Caller passes a Memberistic person id (NOT a WP user
 * id) — that's what the staff lookup keys on.
 *
 * @param int $person_id    Memberistic persons.id
 * @param int $ttl_seconds  Validity window. Default 7 days.
 * @return string
 */
function g2ab_member_qr_payload( $person_id, $ttl_seconds = 0 ) {
	$person_id = (int) $person_id;
	if ( $person_id <= 0 ) return '';
	$ttl = max( 60, (int) ( $ttl_seconds ?: 7 * DAY_IN_SECONDS ) );
	$exp = time() + $ttl;
	$sig = hash_hmac( 'sha256', 'card|' . $person_id . '|' . $exp, wp_salt( 'auth' ) );
	return 'g2ab:' . $person_id . ':' . $exp . ':' . $sig;
}

/**
 * Get the IP of the current request.
 *
 * Forwarded headers (CF-Connecting-IP, X-Forwarded-For, X-Real-IP) are honored
 * ONLY when REMOTE_ADDR is in a configured trusted-proxy list. This prevents
 * rate-limit + audit-log spoofing by clients that fabricate the header.
 *
 * Trusted proxies are derived from:
 *   - constant G2AB_TRUSTED_PROXIES (comma-separated CIDRs or IPs), OR
 *   - option g2ab_trusted_proxies (same format), OR
 *   - Cloudflare's published ranges when option g2ab_trust_cloudflare = 1.
 *
 * @return string
 */
function g2ab_get_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( '' === $remote || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	if ( ! g2ab_request_is_from_trusted_proxy( $remote ) ) {
		return $remote;
	}

	$forwarded_headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );
	foreach ( $forwarded_headers as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		if ( false !== strpos( $raw, ',' ) ) {
			$raw = trim( explode( ',', $raw )[0] );
		}
		if ( filter_var( $raw, FILTER_VALIDATE_IP ) ) {
			return $raw;
		}
	}

	return $remote;
}

/**
 * Whether REMOTE_ADDR belongs to a configured trusted-proxy range.
 *
 * @param string $remote REMOTE_ADDR.
 * @return bool
 */
function g2ab_request_is_from_trusted_proxy( $remote ) {
	$proxies = array();

	if ( defined( 'G2AB_TRUSTED_PROXIES' ) && is_string( G2AB_TRUSTED_PROXIES ) ) {
		$proxies = array_merge( $proxies, array_filter( array_map( 'trim', explode( ',', G2AB_TRUSTED_PROXIES ) ) ) );
	}
	$opt = (string) get_option( 'g2ab_trusted_proxies', '' );
	if ( '' !== $opt ) {
		$proxies = array_merge( $proxies, array_filter( array_map( 'trim', explode( ',', $opt ) ) ) );
	}
	if ( (int) get_option( 'g2ab_trust_cloudflare', 1 ) === 1 ) {
		$proxies = array_merge( $proxies, g2ab_cloudflare_ranges() );
	}

	if ( empty( $proxies ) ) {
		return false;
	}

	foreach ( $proxies as $range ) {
		if ( g2ab_ip_in_cidr( $remote, $range ) ) {
			return true;
		}
	}
	return false;
}

/**
 * IPv4/IPv6 CIDR membership test. Falls back to exact match if no /N.
 *
 * @param string $ip    IP to test.
 * @param string $range CIDR or single IP.
 * @return bool
 */
function g2ab_ip_in_cidr( $ip, $range ) {
	if ( false === strpos( $range, '/' ) ) {
		return $ip === $range;
	}
	list( $subnet, $bits ) = explode( '/', $range, 2 );
	$bits = (int) $bits;
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$ip_l    = ip2long( $ip );
		$net_l   = ip2long( $subnet );
		if ( $bits < 0 || $bits > 32 ) return false;
		$mask    = $bits === 0 ? 0 : ( ~0 << ( 32 - $bits ) );
		return ( $ip_l & $mask ) === ( $net_l & $mask );
	}
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$ip_bin  = inet_pton( $ip );
		$net_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $net_bin ) return false;
		if ( $bits < 0 || $bits > 128 ) return false;
		$bytes_full = intdiv( $bits, 8 );
		$bits_rem   = $bits % 8;
		if ( $bytes_full > 0 && substr( $ip_bin, 0, $bytes_full ) !== substr( $net_bin, 0, $bytes_full ) ) {
			return false;
		}
		if ( 0 === $bits_rem ) {
			return true;
		}
		$mask = chr( 0xFF << ( 8 - $bits_rem ) & 0xFF );
		return ( ord( $ip_bin[ $bytes_full ] ) & ord( $mask ) ) === ( ord( $net_bin[ $bytes_full ] ) & ord( $mask ) );
	}
	return false;
}

/**
 * Cloudflare's published edge IP ranges (https://www.cloudflare.com/ips/).
 *
 * Trusted by default (g2ab_trust_cloudflare = 1) so sites behind Cloudflare
 * resolve the real visitor IP via CF-Connecting-IP — otherwise every visitor
 * shares Cloudflare's edge IP and the per-IP REST rate limits collapse onto one
 * bucket. A hardcoded fallback guarantees a usable list even when the remote
 * fetch fails, and the result (including the fallback) is ALWAYS cached so the
 * 10s remote fetch can never run on every request on the rate-limit hot path.
 *
 * @return array
 */
function g2ab_cloudflare_ranges() {
	$cached = get_transient( 'g2ab_cf_ranges' );
	if ( is_array( $cached ) && ! empty( $cached ) ) {
		return $cached;
	}

	$ranges = array();
	foreach ( array( 'https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6' ) as $url ) {
		$resp = wp_remote_get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			continue;
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		foreach ( preg_split( '/\r?\n/', $body ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) $ranges[] = $line;
		}
	}

	if ( empty( $ranges ) ) {
		// Static fallback (current Cloudflare ranges) so a server without
		// outbound HTTP still resolves real client IPs behind Cloudflare.
		$ranges = array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
			'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
			'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
			'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
		);
	}

	// Always cache (live list or fallback) so the remote fetch never re-runs on
	// the hot path; the live list refreshes daily on cache expiry.
	set_transient( 'g2ab_cf_ranges', $ranges, DAY_IN_SECONDS );
	return $ranges;
}

/**
 * Idempotency check for webhook events.
 *
 * Returns true the first time the (gateway, event_id) pair is seen and false on
 * every subsequent call. Stored in a transient keyed by hash; cleaned up
 * automatically after 24h. Webhook processors must call this BEFORE applying
 * any side effects.
 *
 * @param string $gateway  Gateway id (e.g. 'stripe', 'paypal', 'authnet', 'fortis').
 * @param string $event_id Provider-supplied event identifier.
 * @return bool True if event is new (process it), false if duplicate (skip).
 */
/**
 * Race-safe payment-row upsert.
 *
 * Two paths trigger this: a happy-path SELECT-then-INSERT, and the
 * recovery path when a concurrent webhook delivery has already
 * inserted the row in the gap between our SELECT and our INSERT.
 * With the UNIQUE KEY (gateway, transaction_id) added in DB v1.5.0,
 * a duplicate INSERT now hits a constraint error instead of silently
 * creating a second row; we catch that and fall through to UPDATE.
 *
 * @param string $gateway        Gateway id (stripe, paypal, fortis, authnet).
 * @param string $transaction_id Provider transaction id.
 * @param array  $data           Column data for INSERT (omit gateway/transaction_id).
 * @return int|false             Payment row id, or false on error.
 */
function g2ab_payments_upsert_by_transaction( $gateway, $transaction_id, array $data ) {
	global $wpdb;
	$pt = $wpdb->prefix . 'g2ab_payments';
	$gateway = sanitize_key( (string) $gateway );
	$transaction_id = (string) $transaction_id;
	if ( '' === $gateway || '' === $transaction_id ) {
		return false;
	}

	$existing = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$pt} WHERE gateway = %s AND transaction_id = %s LIMIT 1",
		$gateway,
		$transaction_id
	) );

	if ( $existing > 0 ) {
		$update = $data;
		unset( $update['booking_id'], $update['gateway'], $update['transaction_id'], $update['created_at'] );
		$update['processed_at'] = $update['processed_at'] ?? current_time( 'mysql' );
		$wpdb->update( $pt, $update, array( 'id' => $existing ) );
		return $existing;
	}

	$insert = array_merge(
		array(
			'gateway'        => $gateway,
			'transaction_id' => $transaction_id,
			'created_at'     => current_time( 'mysql' ),
		),
		$data
	);
	// Suppress wpdb errors so a UNIQUE KEY collision (concurrent insert)
	// is not surfaced as a PHP warning during webhook handling.
	$prev_suppress = $wpdb->suppress_errors( true );
	$ok = $wpdb->insert( $pt, $insert );
	$wpdb->suppress_errors( $prev_suppress );

	if ( $ok ) {
		return (int) $wpdb->insert_id;
	}

	// INSERT lost the race: another worker beat us to the unique key.
	// Re-select to find the winner row, then UPDATE it with our data.
	$existing = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$pt} WHERE gateway = %s AND transaction_id = %s LIMIT 1",
		$gateway,
		$transaction_id
	) );
	if ( $existing > 0 ) {
		$update = $data;
		unset( $update['booking_id'], $update['gateway'], $update['transaction_id'], $update['created_at'] );
		$update['processed_at'] = $update['processed_at'] ?? current_time( 'mysql' );
		$wpdb->update( $pt, $update, array( 'id' => $existing ) );
		return $existing;
	}
	return false;
}

function g2ab_webhook_event_is_new( $gateway, $event_id ) {
	$gateway  = sanitize_key( (string) $gateway );
	$event_id = sanitize_text_field( (string) $event_id );
	if ( '' === $gateway || '' === $event_id ) {
		// Defensive: if either is missing, treat as new and let downstream code decide.
		return true;
	}
	$key = 'g2ab_wh_' . md5( $gateway . '|' . $event_id );
	if ( get_transient( $key ) ) {
		return false;
	}
	set_transient( $key, 1, DAY_IN_SECONDS );
	return true;
}

/**
 * Cross-check a gateway-reported amount against the booking total.
 *
 * Returns true when the paid amount is within 1¢ tolerance of either the full
 * booking total or the deposit amount stored in metadata. Webhook processors
 * call this before flipping a booking to "paid" to reject undercharge or
 * misconfigured-price scenarios.
 *
 * @param object $booking      Booking row (must include total_amount, metadata).
 * @param float  $amount_paid  Amount reported by the gateway.
 * @return bool
 */
function g2ab_validate_payment_amount( $booking, $amount_paid ) {
	if ( ! is_object( $booking ) ) {
		return false;
	}
	$amount_paid = round( (float) $amount_paid, 2 );
	$total       = round( (float) ( $booking->total_amount ?? 0 ), 2 );
	$tolerance   = 0.01;

	if ( abs( $amount_paid - $total ) <= $tolerance ) {
		return true;
	}

	// Accept the configured deposit amount as a valid partial payment.
	$meta = json_decode( (string) ( $booking->metadata ?? '' ), true );
	if ( is_array( $meta ) && isset( $meta['due_now'] ) ) {
		$due_now = round( (float) $meta['due_now'], 2 );
		if ( $due_now > 0 && abs( $amount_paid - $due_now ) <= $tolerance ) {
			return true;
		}
	}

	return false;
}

/**
 * Build a signed access token for an invoice.
 *
 * Signature is HMAC-SHA256( uuid + '|' + exp, secret ). Used so invoice URLs
 * can be shared by customers without exposing PII to anyone who guesses a UUID.
 *
 * @param string $uuid       Booking UUID.
 * @param int    $expires_in Seconds until expiry (default 30 days).
 * @return string `<exp>.<sig>` token.
 */
function g2ab_invoice_sign_token( $uuid, $expires_in = null ) {
	$expires_in = (int) ( $expires_in ?? 30 * DAY_IN_SECONDS );
	$exp        = time() + max( 60, $expires_in );
	$secret     = g2ab_invoice_signing_secret();
	$sig        = hash_hmac( 'sha256', $uuid . '|' . $exp, $secret );
	return $exp . '.' . $sig;
}

/**
 * Verify a signed invoice token. Returns true if the token matches and isn't expired.
 *
 * @param string $uuid  Booking UUID claimed by the URL.
 * @param string $token Token from the URL (format: `<exp>.<sig>`).
 * @return bool
 */
function g2ab_invoice_verify_token( $uuid, $token ) {
	if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) {
		return false;
	}
	[ $exp, $sig ] = explode( '.', $token, 2 );
	$exp = (int) $exp;
	if ( $exp < time() ) {
		return false;
	}
	$secret   = g2ab_invoice_signing_secret();
	$expected = hash_hmac( 'sha256', $uuid . '|' . $exp, $secret );
	return hash_equals( $expected, (string) $sig );
}

/**
 * Get (or lazily create) the secret used to sign invoice tokens.
 * Stored in wp_options, autoload no, so it's never sent on every page load.
 *
 * @return string
 */
function g2ab_invoice_signing_secret() {
	$secret = get_option( 'g2ab_invoice_signing_secret', '' );
	if ( ! $secret ) {
		$secret = wp_generate_password( 64, true, true );
		add_option( 'g2ab_invoice_signing_secret', $secret, '', 'no' );
	}
	return $secret;
}

/**
 * Centralized pricing engine for all booking contexts.
 *
 * Context keys:
 * - booking_type (object|null)
 * - party_size (int)
 * - duration_min (int)
 * - is_member (bool)
 * - context_type (lane|ladies_tuesday|event|resource)
 *
 * @param int|WP_User|null $user    User object or user id.
 * @param array            $context Pricing context.
 * @return array
 */
function g2ab_get_price( $user, array $context = array() ) {
	$user_id = 0;
	if ( $user instanceof WP_User ) {
		$user_id = (int) $user->ID;
	} elseif ( is_numeric( $user ) ) {
		$user_id = (int) $user;
	}

	$booking_type = isset( $context['booking_type'] ) && is_object( $context['booking_type'] ) ? $context['booking_type'] : null;
	$party_size   = max( 1, (int) ( $context['party_size'] ?? 1 ) );
	$duration_min = max( 1, (int) ( $context['duration_min'] ?? ( $booking_type ? (int) $booking_type->duration_min : 60 ) ) );
	$is_member    = ! empty( $context['is_member'] );
	$context_type = sanitize_key( (string) ( $context['context_type'] ?? 'resource' ) );
	$hours        = max( 1, $duration_min / 60 );

	$lane_hourly_price      = (float) g2ab_get_option( 'lane_hourly_price', 20 );
	$lane_extra_shooter_fee = (float) g2ab_get_option( 'lane_extra_shooter_fee', 15 );
	$ladies_tuesday_price   = (float) g2ab_get_option( 'ladies_tuesday_lane_price', 0 );

	$subtotal        = 0.0;
	$discount_amount = 0.0;
	$discount_label  = '';

	if ( 'ladies_tuesday' === $context_type ) {
		$subtotal = round( $ladies_tuesday_price * $hours, 2 );
	} elseif ( 'lane' === $context_type ) {
		$lane_base  = $lane_hourly_price * $hours;
		$extra_base = max( 0, $party_size - 1 ) * $lane_extra_shooter_fee * $hours;
		$subtotal   = round( $lane_base + $extra_base, 2 );
		if ( $is_member ) {
			$discount_amount = $subtotal;
			$discount_label  = 'Member lane benefit';
		}
	} else {
		$base_price = $booking_type ? (float) $booking_type->base_price : 0.0;
		$subtotal   = round( $base_price * $party_size, 2 );
		if ( $subtotal > 0 && $booking_type && (float) $booking_type->member_discount > 0 && $is_member ) {
			$discount_pct    = min( 100, (float) $booking_type->member_discount );
			$discount_amount = round( $subtotal * $discount_pct / 100, 2 );
			$discount_label  = 'Member discount';
		}
	}

	$pricing = array(
		'subtotal'                => max( 0, round( $subtotal, 2 ) ),
		'discount_amount'         => max( 0, round( $discount_amount, 2 ) ),
		'total'                   => max( 0, round( $subtotal - $discount_amount, 2 ) ),
		'member_discount_percent' => $subtotal > 0 ? round( ( $discount_amount / $subtotal ) * 100, 2 ) : 0,
		'membership_level_id'     => 0,
		'membership_source'       => $discount_amount > 0 ? 'pricing_engine' : '',
		'discount_label'          => $discount_label,
		'pricing_context'         => $context_type,
		'user_id'                 => $user_id,
	);

	return apply_filters( 'g2ab_central_pricing', $pricing, $user_id, $context );
}

/**
 * Backward-compatible alias requested by project spec.
 *
 * @param int|WP_User|null $user    User.
 * @param array            $context Context.
 * @return array
 */
function g2a_get_price( $user, array $context = array() ) {
	return g2ab_get_price( $user, $context );
}
