<?php
/**
 * Performance health-check endpoint.
 *
 * Hit  https://your-site.com/?g2a_perf_check=1  while signed in as an
 * administrator (via /g2a-admin-login/ if needed). Reports the handful of
 * server-side factors that decide how fast every page on this site renders:
 *
 *   - PHP version + OPcache state (OPcache OFF means WordPress, WooCommerce
 *     and every plugin are re-compiled from source on EVERY request — the
 *     single biggest slowdown a WP host can have)
 *   - persistent object cache (Redis/Memcached) presence
 *   - page-cache drop-in presence
 *   - WP-Cron mode + overdue-job backlog (distributor syncs etc. piggyback
 *     on visitor requests when no real cron is configured)
 *   - a timed database round-trip
 *
 * plus a plain-English verdict list ordered by expected impact.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'g2a_perf_check_endpoint' );
function g2a_perf_check_endpoint() {
	if ( empty( $_GET['g2a_perf_check'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		status_header( 403 );
		wp_send_json( array( 'error' => 'forbidden — sign in as an administrator first (via /g2a-admin-login/), then reload this URL' ), 403 );
	}
	nocache_headers();

	global $wpdb;

	// OPcache state.
	$opcache_on = function_exists( 'opcache_get_status' )
		&& filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );

	// Timed DB round-trip (median of 3 trivial queries).
	$samples = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$t0 = microtime( true );
		$wpdb->get_var( 'SELECT 1' );
		$samples[] = ( microtime( true ) - $t0 ) * 1000;
	}
	sort( $samples );
	$db_ms = round( $samples[1], 2 );

	// Cron backlog: events whose scheduled time is already in the past.
	$overdue = 0;
	$crons   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
	$now     = time();
	foreach ( (array) $crons as $timestamp => $hooks ) {
		if ( $timestamp < $now ) {
			$overdue += count( (array) $hooks );
		}
	}

	$object_cache = (bool) wp_using_ext_object_cache();
	$page_cache   = defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	$real_cron    = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$plugins      = (array) get_option( 'active_plugins', array() );

	$verdict = array();
	if ( ! $opcache_on ) {
		$verdict[] = 'PROBLEM (biggest win): PHP OPcache is DISABLED — WordPress + WooCommerce + every plugin are re-compiled from scratch on every single request, typically 3-10x slower page starts. Ask the host to enable OPcache (keep opcache.validate_timestamps=1 so plugin updates still apply). It was likely turned off while debugging stale deploys; the ?g2ab_version_check / ?g2a_login_check endpoints make that debugging safe now.';
	}
	if ( ! $object_cache ) {
		$verdict[] = 'IMPROVE: no persistent object cache (Redis/Memcached). Logged-in pages (member account, staff, wp-admin) re-run every option/menu query per view. If the host offers Redis, enable it + the Redis Object Cache drop-in.';
	}
	if ( ! $page_cache ) {
		$verdict[] = 'IMPROVE: no page-cache drop-in detected at the origin. Anonymous page views are rendered by PHP every time. A page cache (e.g. the host\'s, or WP Super Cache/LiteSpeed) is safe now: the login/account/booking surfaces send DONOTCACHEPAGE + no-cache headers, which well-behaved page caches honour.';
	}
	if ( ! $real_cron ) {
		$verdict[] = 'IMPROVE: WP-Cron runs piggybacked on visitor traffic. Heavy background jobs (distributor catalog syncs, email queues) can add random multi-second stalls. Set DISABLE_WP_CRON to true in wp-config.php and add a server cron hitting wp-cron.php every 5 minutes.';
	}
	if ( $overdue > 10 ) {
		$verdict[] = sprintf( 'WARNING: %d overdue cron jobs are queued — background work is starving, and whichever request finally triggers it pays the bill. Reinforces the real-cron item above.', $overdue );
	}
	if ( $db_ms > 20 ) {
		$verdict[] = sprintf( 'WARNING: a trivial database query takes %sms (expected <5ms on local MySQL). The DB may be remote, overloaded, or undersized for this plugin count.', $db_ms );
	}
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		$verdict[] = 'IMPROVE: PHP ' . PHP_VERSION . ' is old — PHP 8.1+ is measurably faster for WordPress.';
	}
	if ( empty( $verdict ) ) {
		$verdict[] = 'OK — server-side fundamentals look healthy. If loading still feels slow, the delay is likely front-end (theme preloader hand-off, large images, third-party scripts) — worth a PageSpeed Insights run next.';
	}

	wp_send_json( array(
		'php_version'          => PHP_VERSION,
		'opcache_enabled'      => $opcache_on,
		'persistent_object_cache' => $object_cache,
		'page_cache_dropin'    => $page_cache,
		'real_cron_configured' => $real_cron,
		'overdue_cron_jobs'    => $overdue,
		'db_roundtrip_ms'      => $db_ms,
		'active_plugins'       => count( $plugins ),
		'memory_limit'         => (string) ini_get( 'memory_limit' ),
		'verdict'              => $verdict,
	) );
}
