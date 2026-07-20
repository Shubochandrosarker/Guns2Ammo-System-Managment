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
 * Business NAP (name/address/phone) for outbound emails, PDFs, front-desk
 * screens, and the AI autoreply engine.
 *
 * This plugin has always kept its own separate `g2ab_business_*` options,
 * disconnected from the theme's single source of truth
 * (guns2ammo/inc/business-info.php's g2a_biz()). The activator seeded
 * `g2ab_business_address` to the placeholder "Mesa, Arizona" — never a real
 * street address — so on every site that never visited this plugin's own
 * settings screen to fill it in by hand, every automated email footer, PDF
 * invoice, and front-desk screen has been showing that placeholder instead
 * of the real address (currently 6030 E Main St, Suite 103, Mesa, AZ 85205).
 *
 * These helpers prefer an explicit value saved in this plugin's own
 * settings (so a site owner who deliberately typed something different here
 * — e.g. running this plugin without the guns2ammo theme — keeps it), and
 * otherwise fall through to the theme's live NAP data. The stored
 * "Mesa, Arizona" placeholder is treated as "not configured" (not as a
 * deliberate value) so already-activated sites get upgraded automatically,
 * without needing anyone to notice and re-save the settings page.
 */
function g2ab_business_name() {
	$v = trim( (string) get_option( 'g2ab_business_name', '' ) );
	if ( '' === $v && function_exists( 'g2a_biz' ) ) {
		$biz = g2a_biz();
		$v   = (string) ( $biz['name'] ?? '' );
	}
	return '' !== $v ? $v : get_bloginfo( 'name' );
}

function g2ab_business_phone() {
	$v = trim( (string) get_option( 'g2ab_business_phone', '' ) );
	if ( '' === $v && function_exists( 'g2a_biz_phone' ) ) {
		$v = (string) g2a_biz_phone();
	}
	return $v;
}

function g2ab_business_address() {
	$v = trim( (string) get_option( 'g2ab_business_address', '' ) );
	if ( ( '' === $v || 'Mesa, Arizona' === $v ) && function_exists( 'g2a_biz_addr_line' ) ) {
		$theme_addr = trim( (string) g2a_biz_addr_line() );
		if ( '' !== $theme_addr ) {
			return $theme_addr;
		}
	}
	return $v;
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
 * Get the client IP safely.
 *
 * Forwarded headers are honored only when the immediate peer is a trusted
 * proxy. This prevents direct-origin spoofing while still splitting real
 * visitors behind Cloudflare into separate rate-limit buckets.
 *
 * @return string
 */
function g2ab_get_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
		return '0.0.0.0';
	}

	if ( g2ab_remote_is_trusted_proxy( $remote ) ) {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			if ( false !== strpos( $ip, ',' ) ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}

	return $remote;
}

function g2ab_remote_is_trusted_proxy( $remote ) {
	$list = array();
	if ( defined( 'G2AB_TRUSTED_PROXIES' ) && is_string( G2AB_TRUSTED_PROXIES ) ) {
		$list = array_merge( $list, array_filter( array_map( 'trim', explode( ',', G2AB_TRUSTED_PROXIES ) ) ) );
	}
	$opt = (string) get_option( 'g2ab_trusted_proxies', '' );
	if ( '' !== $opt ) {
		$list = array_merge( $list, array_filter( array_map( 'trim', explode( ',', $opt ) ) ) );
	}
	$list = array_merge( $list, g2ab_default_trusted_proxy_ranges() );
	$list = (array) apply_filters( 'g2ab_trusted_proxies', array_unique( $list ) );

	foreach ( $list as $range ) {
		if ( g2ab_ip_in_cidr( $remote, (string) $range ) ) {
			return true;
		}
	}
	return false;
}

function g2ab_default_trusted_proxy_ranges() {
	return array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
		'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
		'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);
}

function g2ab_ip_in_cidr( $ip, $range ) {
	if ( '' === $ip || '' === $range ) {
		return false;
	}
	if ( '*' === $range ) {
		return true;
	}
	if ( false === strpos( $range, '/' ) ) {
		return $ip === $range;
	}
	list( $subnet, $bits ) = explode( '/', $range, 2 );
	$ip_bin     = @inet_pton( $ip );
	$subnet_bin = @inet_pton( $subnet );
	if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
		return false;
	}
	$bits  = max( 0, min( strlen( $ip_bin ) * 8, (int) $bits ) );
	$bytes = intdiv( $bits, 8 );
	$rem   = $bits % 8;
	if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
		return false;
	}
	if ( 0 === $rem ) {
		return true;
	}
	$mask = ( 0xff << ( 8 - $rem ) ) & 0xff;
	return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
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

function g2ab_webhook_event_claim( $gateway, $event_id, $event_type = '', $payload = '' ) {
	global $wpdb;
	$gateway    = sanitize_key( (string) $gateway );
	$event_id   = sanitize_text_field( (string) $event_id );
	$event_type = sanitize_text_field( (string) $event_type );
	if ( '' === $gateway || '' === $event_id ) {
		return 'process';
	}

	$table = $wpdb->prefix . 'g2ab_webhook_events';
	if ( ! g2ab_table_exists( $table ) ) {
		return g2ab_webhook_event_was_processed_legacy( $gateway, $event_id ) ? 'duplicate' : 'process';
	}

	$lock_name = 'g2ab_wh_' . md5( $gateway . '|' . $event_id );
	if ( ! g2ab_mysql_lock( $lock_name, 3 ) ) {
		return new WP_Error( 'g2ab_webhook_locked', __( 'Webhook event is already being processed.', 'g2a-booking' ), array( 'status' => 503 ) );
	}

	try {
		$now = current_time( 'mysql' );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, attempts, updated_at FROM {$table} WHERE gateway = %s AND event_id = %s LIMIT 1", $gateway, $event_id ),
			ARRAY_A
		);
		if ( $row && 'processed' === (string) $row['status'] ) {
			return 'duplicate';
		}
		if ( $row && 'processing' === (string) $row['status'] && ! empty( $row['updated_at'] ) && strtotime( (string) $row['updated_at'] ) > ( time() - 120 ) ) {
			return new WP_Error( 'g2ab_webhook_processing', __( 'Webhook event is still processing.', 'g2a-booking' ), array( 'status' => 503 ) );
		}

		$data = array(
			'event_type'   => $event_type,
			'status'       => 'processing',
			'attempts'     => $row ? ( (int) $row['attempts'] + 1 ) : 1,
			'payload_hash' => is_string( $payload ) && '' !== $payload ? hash( 'sha256', $payload ) : null,
			'last_error'   => '',
			'received_at'  => $now,
			'updated_at'   => $now,
		);

		if ( $row ) {
			$wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$data['gateway']    = $gateway;
			$data['event_id']   = $event_id;
			$data['created_at'] = $now;
			$wpdb->insert(
				$table,
				$data,
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	} finally {
		g2ab_mysql_unlock( $lock_name );
	}

	return 'process';
}

function g2ab_webhook_event_mark_processed( $gateway, $event_id ) {
	global $wpdb;
	$gateway  = sanitize_key( (string) $gateway );
	$event_id = sanitize_text_field( (string) $event_id );
	if ( '' === $gateway || '' === $event_id ) {
		return;
	}
	$table = $wpdb->prefix . 'g2ab_webhook_events';
	if ( ! g2ab_table_exists( $table ) ) {
		set_transient( 'g2ab_wh_' . md5( $gateway . '|' . $event_id ), 1, DAY_IN_SECONDS );
		return;
	}
	$now = current_time( 'mysql' );
	$wpdb->update(
		$table,
		array(
			'status'       => 'processed',
			'processed_at' => $now,
			'last_error'   => '',
			'updated_at'   => $now,
		),
		array(
			'gateway'  => $gateway,
			'event_id' => $event_id,
		),
		array( '%s', '%s', '%s', '%s' ),
		array( '%s', '%s' )
	);
}

function g2ab_webhook_event_mark_failed( $gateway, $event_id, $message ) {
	global $wpdb;
	$gateway  = sanitize_key( (string) $gateway );
	$event_id = sanitize_text_field( (string) $event_id );
	if ( '' === $gateway || '' === $event_id ) {
		return;
	}
	$table = $wpdb->prefix . 'g2ab_webhook_events';
	if ( ! g2ab_table_exists( $table ) ) {
		return;
	}
	$wpdb->update(
		$table,
		array(
			'status'     => 'failed',
			'last_error' => substr( sanitize_textarea_field( (string) $message ), 0, 1000 ),
			'updated_at' => current_time( 'mysql' ),
		),
		array(
			'gateway'  => $gateway,
			'event_id' => $event_id,
		),
		array( '%s', '%s', '%s' ),
		array( '%s', '%s' )
	);
}

function g2ab_webhook_event_was_processed_legacy( $gateway, $event_id ) {
	return (bool) get_transient( 'g2ab_wh_' . md5( sanitize_key( (string) $gateway ) . '|' . sanitize_text_field( (string) $event_id ) ) );
}

function g2ab_table_exists( $table ) {
	global $wpdb;
	static $cache = array();
	$table = (string) $table;
	if ( isset( $cache[ $table ] ) ) {
		return $cache[ $table ];
	}
	$cache[ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $cache[ $table ];
}

function g2ab_mysql_lock( $key, $timeout = 3 ) {
	global $wpdb;
	if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
		return true;
	}
	return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', (string) $key, max( 0, (int) $timeout ) ) );
}

function g2ab_mysql_unlock( $key ) {
	global $wpdb;
	if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
		return;
	}
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $key ) );
}

function g2ab_queue_booking_paid_side_effects( $booking_id, $gateway, $previous_status, $payload = array() ) {
	$booking_id = absint( $booking_id );
	if ( ! $booking_id ) {
		return false;
	}
	$gateway = sanitize_key( (string) $gateway );
	$args    = array(
		$booking_id,
		$gateway,
		sanitize_key( (string) $previous_status ),
		array(
			'session_id'     => isset( $payload['id'] ) ? sanitize_text_field( (string) $payload['id'] ) : '',
			'payment_intent' => isset( $payload['payment_intent'] ) ? sanitize_text_field( (string) $payload['payment_intent'] ) : '',
			'event_source'   => 'webhook',
		),
	);

	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'g2ab_run_booking_paid_side_effects', $args, 'g2a-booking' );
		return true;
	}

	if ( ! wp_next_scheduled( 'g2ab_run_booking_paid_side_effects', $args ) ) {
		return (bool) wp_schedule_single_event( time() + 1, 'g2ab_run_booking_paid_side_effects', $args );
	}

	return true;
}

function g2ab_run_booking_paid_side_effects( $booking_id, $gateway = 'stripe', $previous_status = '', $context = array() ) {
	global $wpdb;
	$booking_id = absint( $booking_id );
	if ( ! $booking_id ) {
		return;
	}
	$gateway = sanitize_key( (string) $gateway );
	$token   = md5( $gateway . '|' . $booking_id . '|' . (string) ( $context['payment_intent'] ?? '' ) . '|' . (string) ( $context['session_id'] ?? '' ) );
	$key     = 'g2ab_paid_hooks_' . $token;

	if ( get_transient( $key ) ) {
		return;
	}

	$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1", $booking_id ) );
	if ( ! $booking ) {
		return;
	}

	do_action( 'g2ab_payment_succeeded', $booking_id, $gateway, $context );
	do_action( 'g2ab_booking_paid', $booking, $context );
	do_action( 'g2ab_booking_status_changed', $booking_id, 'paid', $previous_status );

	set_transient( $key, 1, 30 * DAY_IN_SECONDS );
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
 * Insert a g2ab_payments row, recovering safely if a concurrent webhook
 * delivery already inserted a row for the same (gateway, transaction_id)
 * — the table has a UNIQUE KEY on that pair specifically to catch this.
 *
 * Each gateway's mark_booking_paid() already does its own SELECT-then-
 * insert-or-update to avoid duplicates in the common case (matching by
 * transaction_id, a pending-row heuristic, etc.), but that check is not
 * atomic with the insert — two near-simultaneous webhook deliveries for
 * the same charge can both pass the SELECT and both attempt an INSERT.
 * Without this helper the second INSERT would just fail silently (no
 * unique key existed before) or, once the key exists, fail loudly and
 * lose that delivery's data. This makes the failure recoverable: on a
 * unique-key collision, fetch the row that won the race and UPDATE it
 * with this call's data instead, so the payment is never dropped.
 *
 * @param string        $table   Full g2ab_payments table name.
 * @param array         $data    Row data for INSERT. Must include 'gateway' and
 *                               'transaction_id' (transaction_id may be empty/null —
 *                               in that case no recovery is possible on collision,
 *                               matching the column's NULL-is-distinct semantics).
 * @param string[]|null $formats wpdb format specifiers, SAME LENGTH AND ORDER as $data,
 *                               or null to let $wpdb auto-detect (matches $wpdb->insert()'s
 *                               own optional-formats behavior).
 * @return int|false Row id (new or recovered), or false on a non-recoverable failure.
 */
function g2ab_insert_or_update_payment( $table, array $data, $formats = null ) {
	global $wpdb;

	$inserted = $wpdb->insert( $table, $data, $formats );
	if ( false !== $inserted ) {
		return (int) $wpdb->insert_id;
	}

	$is_duplicate = false !== stripos( (string) $wpdb->last_error, 'uniq_gateway_txn' )
		|| false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' );
	if ( ! $is_duplicate || empty( $data['transaction_id'] ) || empty( $data['gateway'] ) ) {
		return false;
	}

	$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE gateway = %s AND transaction_id = %s LIMIT 1",
		$data['gateway'],
		$data['transaction_id']
	) );
	if ( ! $existing_id ) {
		return false;
	}

	$update_data = $data;
	unset( $update_data['booking_id'], $update_data['gateway'], $update_data['transaction_id'], $update_data['created_at'] );

	$update_formats = null;
	if ( is_array( $formats ) && count( $formats ) === count( $data ) ) {
		// Map field => format so we can safely drop identity fields from the
		// update payload without breaking positional format-array alignment.
		$field_formats  = array_combine( array_keys( $data ), $formats );
		$update_formats = array();
		foreach ( array_keys( $update_data ) as $key ) {
			$update_formats[] = $field_formats[ $key ];
		}
	}

	$wpdb->update( $table, $update_data, array( 'id' => $existing_id ), $update_formats, array( '%d' ) );
	return $existing_id;
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
