<?php
/**
 * Redirects — clean 404 → permanent map.
 *
 * Built from the 27 May 2026 broken-links audit. Old URLs that
 * routinely 404 across the site are 301'd to the closest live
 * equivalent so we keep their accumulated SEO equity and stop
 * hitting visitors with dead-ends.
 *
 * Pattern: array of [ 'from' => regex|literal path, 'to' => path,
 *                     'code' => 301, 'exact' => true|false ].
 * - Literal paths are matched as exact request URIs.
 * - Regex paths are matched against REQUEST_URI (path only).
 *
 * Adds two more entries for the namespaced membership URLs from
 * the old Paid Memberships Pro plugin that customers still have
 * bookmarked.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'g2a_redirects_handle', 1 );
function g2a_redirects_handle() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	// www → bare-host canonical redirect.
	// The site is configured with https://guns2ammo.com (no www).
	// Anyone landing on https://www.guns2ammo.com/anything gets a
	// hard 301 to the same path on the canonical host, preserving
	// query string. SEO audit was logging www URLs as 404 — this
	// also fixes that for AI crawlers and shared/bookmarked links.
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	if ( $host && 0 === strpos( $host, 'www.' ) ) {
		// Never reflect the request's Host header into the redirect target —
		// a forged Host header would 301 visitors (and poison any full-page
		// cache) to an attacker-chosen domain. Anchor on the configured host.
		$canonical_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme = is_ssl() ? 'https' : 'http';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$target = $scheme . '://' . $canonical_host . $uri;
		// 301 because this is a canonical-host redirect — search
		// engines should consolidate signal on the bare host.
		wp_redirect( $target, 301 );
		exit;
	}

	$path = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
		: '';
	if ( '' === $path || '/' === $path ) {
		return;
	}
	$path = '/' . trim( $path, '/' ) . '/';

	$map = g2a_redirect_map();

	if ( isset( $map[ $path ] ) ) {
		$target = home_url( $map[ $path ] );
		// Preserve the original query string (e.g. ?memberistic_plan=defender)
		// so a legacy /memberistic-checkout/?memberistic_plan=… link still
		// lands on the right plan after the redirect.
		$qs = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_QUERY ) : '';
		if ( '' !== $qs ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $qs;
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	// Pattern matches — e.g. legacy /membership-checkout/ to /memberships/
	foreach ( g2a_redirect_patterns() as $rule ) {
		if ( preg_match( $rule['pattern'], $path ) ) {
			wp_safe_redirect( home_url( $rule['to'] ), 301 );
			exit;
		}
	}
}

/**
 * Literal path → target map. Sourced from the May 2026 audit:
 *   /church-security-training-mesa-packages/ → existing /church-security-training-packages/
 *   /get-support/                            → existing /contact/
 *   /faqs/                                   → new FAQs page (this drop)
 *   /membership-checkout/                    → /memberships/ (legacy)
 *   /machine-gun-packages/, /machine-gun-rentals/  → /machine-gun/
 *   /firearms-transfer-service/              → /transfers/
 *   /price-and-fees/                         → /pricing/
 *   /handgun-course/                         → /training/
 *   /contact-us/                             → /contact/
 *   /about-our-range/                        → /about/
 *
 * Keys MUST start and end with a slash.
 */
function g2a_redirect_map() {
	return apply_filters( 'g2a_redirect_map', array(
		'/church-security-training-mesa-packages/' => '/church-security-training-packages/',
		'/get-support/'              => '/contact/',
		'/contact-us/'               => '/contact/',
		'/firearms-transfer-service/'=> '/transfers/',
		'/machine-gun-packages/'     => '/machine-gun/',
		'/machine-gun-rentals/'      => '/machine-gun/',
		'/handgun-course/'           => '/training/',
		'/price-and-fees/'           => '/pricing/',
		'/about-our-range/'          => '/about/',
		'/membership-checkout/'      => '/memberships/',
		'/membership-levels/'        => '/memberships/',
		'/membership-account/'       => '/account/',
		'/membership-billing/'       => '/account/',
		'/membership-invoice/'       => '/account/',
		'/membership-cancel/'        => '/account/',
		'/membership-confirmation/'  => '/account/',
		'/memberistic-account/'      => '/account/',
		'/memberistic-memberships/'  => '/memberships/',
		// SEO audit June 2026: 301 the 2023 post that cannibalized the new
		// "best indoor shooting range in Mesa" asset, legacy static-HTML
		// leftovers, and common guessed URLs (404 → closest money page).
		'/explore-the-best-indoor-shooting-range-in-mesa-a-shooters-haven/' => '/shooting-range/best-indoor-shooting-range-in-mesa-az/',
		'/contact.html/'             => '/contact/',
		'/shop.html/'                => '/shop/',
		'/index.html/'               => '/',
		'/membership/'               => '/memberships/',
		'/booking/'                  => '/book-a-lane/',
		'/book/'                     => '/book-a-lane/',
		'/reserve/'                  => '/book-a-lane/',
		'/range/'                    => '/book-a-lane/',
		'/lanes/'                    => '/book-a-lane/',
		'/ccw/'                      => '/arizona-ccw-certification/',
		'/ccw-class/'                => '/arizona-ccw-certification/',
		'/free-ccw-class/'           => '/arizona-ccw-certification/',
		'/classes/'                  => '/training/',
		'/courses/'                  => '/training/',
		'/lessons/'                  => '/training/',
		'/ffl/'                      => '/transfers/',
		'/ffl-transfer/'             => '/transfers/',
		'/gun-transfers/'            => '/transfers/',
		'/full-auto/'                => '/machine-gun/',
		'/rentals/'                  => '/book-a-lane/',
		'/hours/'                    => '/contact/',
		'/location/'                 => '/contact/',
		'/directions/'               => '/contact/',
		'/memberistic-checkout/'     => '/checkout/',
		'/memberistic-login/'        => '/login/',
		'/memberistic-thank-you/'    => '/thank-you/',
		'/memberistic-renewal/'      => '/renewal/',
	) );
}

/**
 * Regex pattern rules — matched in order; first hit wins.
 */
function g2a_redirect_patterns() {
	return apply_filters( 'g2a_redirect_patterns', array(
		// Legacy membership checkout URL from the previous membership plugin.
		array(
			'pattern' => '#^/membership-checkout/?#i',
			'to'      => '/memberships/',
		),
		// Legacy account/billing URLs found in old renewal and card-update emails.
		array(
			'pattern' => '#^/membership-(account|billing|invoice|cancel|confirmation)/?#i',
			'to'      => '/account/',
		),
		array(
			'pattern' => '#^/membership-levels/?#i',
			'to'      => '/memberships/',
		),
		// "hello world" old WP starter post
		array(
			'pattern' => '#^/hello-world(-\d+)?/?$#i',
			'to'      => '/blog/',
		),
	) );
}


/*
 * NOTE: this file used to register filters against the legacy membership
 * plugin's expiration-warning emails, to stop its cron sending stale notices
 * during the migration window. Those filters are gone: Memberistic is now the
 * only membership system, that plugin is no longer installed, and a theme
 * carrying live hooks for it kept a runtime dependency alive that the system
 * is not supposed to have.
 *
 * The legacy URL redirects above are deliberately KEPT — they are pure SEO /
 * inbox cleanup for links already out in the wild (old renewal and
 * card-update emails), and they resolve to Memberistic pages.
 */
