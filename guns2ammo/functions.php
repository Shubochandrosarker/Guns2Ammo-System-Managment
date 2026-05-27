<?php
/**
 * Guns 2 Ammo  child theme functions
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'G2A_VERSION', '1.8.9' );
define( 'G2A_DIR', get_stylesheet_directory() );
define( 'G2A_URI', get_stylesheet_directory_uri() );

/* ---------- Theme support ---------- */
add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style' ] );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( [
		'primary' => __( 'Primary Navigation', 'guns2ammo' ),
		'footer'  => __( 'Footer Navigation', 'guns2ammo' ),
	] );
} );

/* ---------- Strip WP/plugin bloat (perf) ---------- */
add_action( 'wp_enqueue_scripts', function () {
	// WP emoji
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );

	$is_wc_flow = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );

	// Block library bloat, except WooCommerce cart/checkout/account screens that may use block markup.
	if ( ! $is_wc_flow ) {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'classic-theme-styles' );
		wp_dequeue_style( 'global-styles' );
	}

	// jQuery migrate
	if ( ! is_admin() ) {
		$wp_scripts = wp_scripts();
		if ( isset( $wp_scripts->registered['jquery'] ) ) {
			$wp_scripts->registered['jquery']->deps = array_diff(
				$wp_scripts->registered['jquery']->deps,
				[ 'jquery-migrate' ]
			);
		}
	}
}, 99 );

/* Remove unused header links */
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
remove_action( 'wp_head', 'feed_links_extra', 3 );
remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );

/* ---------- Enqueue theme assets ---------- */
add_action( 'wp_enqueue_scripts', function () {
	// Self-hosted brand fonts (Bebas Neue, Barlow + Condensed, DM Sans,
	// Space Mono — latin subset only). WOFF2 files live in
	// assets/fonts/, regenerated via scripts/fetch-fonts.sh. Local
	// hosting drops a third-party DNS hop from every page load AND
	// keeps visitor IPs out of Google's logs.
	wp_enqueue_style( 'g2a-fonts', G2A_URI . '/assets/css/fonts.css', [], G2A_VERSION );

	wp_enqueue_style( 'g2a-tokens', G2A_URI . '/assets/css/tokens.css', [], G2A_VERSION );
	wp_enqueue_style( 'g2a-app',    G2A_URI . '/assets/css/app.css',    [ 'g2a-tokens' ], G2A_VERSION );
	// Loaded last so WooCommerce + responsive overrides win on
	// specificity ties against earlier rules in tokens.css/app.css.
	wp_enqueue_style( 'g2a-wc-fixes', G2A_URI . '/assets/css/wc-fixes.css', [ 'g2a-app' ], G2A_VERSION );

	// Front-page hero CSS is loaded only where it's needed. Was a 270-line
	// inline <style> block in front-page.php — non-cacheable + bloated
	// every home-page response. Now a normal static asset so browsers can
	// cache it between visits and across pages once it's hit.
	if ( is_front_page() ) {
		wp_enqueue_style( 'g2a-front-page', G2A_URI . '/assets/css/front-page.css', [ 'g2a-app' ], G2A_VERSION );
	}

	// Per-template page CSS — same pattern as the front-page hero. Each
	// CSS file is the verbatim <style> block previously inlined in its
	// template (machine-gun.php / ccw.php / book-a-lane.php), now
	// cacheable and only loaded on the matching page template.
	if ( is_page_template( 'page-templates/template-machine-gun.php' ) ) {
		wp_enqueue_style( 'g2a-machine-gun', G2A_URI . '/assets/css/machine-gun.css', [ 'g2a-app' ], G2A_VERSION );
	}
	if ( is_page_template( 'page-templates/template-ccw.php' ) ) {
		wp_enqueue_style( 'g2a-ccw', G2A_URI . '/assets/css/ccw.css', [ 'g2a-app' ], G2A_VERSION );
	}
	if ( is_page_template( 'page-templates/template-book-a-lane.php' ) ) {
		wp_enqueue_style( 'g2a-book-a-lane', G2A_URI . '/assets/css/book-a-lane.css', [ 'g2a-app' ], G2A_VERSION );
	}

	wp_enqueue_script( 'g2a-chrome', G2A_URI . '/assets/js/chrome.js', [], G2A_VERSION, true );
}, 20 );

/* Add `defer` to g2a-chrome.js so the script downloads in parallel
   with HTML parsing instead of being discovered and downloaded at
   end-of-body. Combined with in_footer=true above, this gives the
   best of both: not blocking the parser, but executing in document
   order with other deferred scripts. Real-world TTI win of
   ~100-250ms on cold-cache loads. */
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( 'g2a-chrome' === $handle ) {
		return str_replace( ' src=', ' defer src=', $tag );
	}
	return $tag;
}, 10, 2 );

/* No third-party font hosts since assets/css/fonts.css is local. The
 * preconnect/dns-prefetch hints we used to print for fonts.gstatic are
 * intentionally removed — they would slow first paint with a useless
 * DNS lookup and leak the visitor's IP to Google. */

/* Add defer to non-essential scripts */
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( in_array( $handle, [ 'g2a-chrome' ], true ) ) {
		return str_replace( ' src', ' defer src', $tag );
	}
	return $tag;
}, 10, 2 );

/* ---------- Body classes ---------- */
add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'g2a-body';
	if ( is_front_page() ) $classes[] = 'g2a-home';
	return $classes;
} );

/* ---------- SEO + Schema ---------- */
require_once G2A_DIR . '/inc/seo.php';

/* ---------- WooCommerce tweaks ---------- */
require_once G2A_DIR . '/inc/woocommerce.php';

/* ---------- Customizer image fields (light) ---------- */
require_once G2A_DIR . '/inc/customizer.php';

/* ---------- AEO / GEO layer (schema + llms.txt) ---------- */
require_once G2A_DIR . '/inc/aeo.php';

/* ---------- Plugin integration (Memberistic + G2A Booking Engine) ---------- */
require_once G2A_DIR . '/inc/plugins.php';
require_once G2A_DIR . '/inc/login.php';
require_once G2A_DIR . '/inc/redirects.php';
require_once G2A_DIR . '/inc/faqs.php';
require_once G2A_DIR . '/inc/sitemap.php';
require_once G2A_DIR . '/inc/llms.php';
require_once G2A_DIR . '/inc/robots.php';

/* ---------- Compat: repair malformed plugin-update transient ----------
 * Some plugins push entries into the `update_plugins` transient without the
 * `plugin` property WordPress core expects. When core later runs
 * wp_list_pluck() / wp_list_filter() over that data it emits
 * "Undefined property: stdClass::$plugin" notices in wp-admin. We normalise
 * every response / no_update entry so each one carries a valid `plugin` key.
 */
add_filter( 'site_transient_update_plugins', function ( $value ) {
	if ( ! is_object( $value ) ) {
		return $value;
	}
	foreach ( [ 'response', 'no_update' ] as $bucket ) {
		if ( empty( $value->$bucket ) || ! is_array( $value->$bucket ) ) {
			continue;
		}
		foreach ( $value->$bucket as $file => $item ) {
			if ( is_array( $item ) ) {
				$item = (object) $item;
			}
			if ( ! is_object( $item ) ) {
				$item = new stdClass();
			}
			if ( empty( $item->plugin ) ) {
				$item->plugin = $file;
			}
			$value->$bucket[ $file ] = $item;
		}
	}
	return $value;
} );

/* ---------- Helpers ---------- */
function g2a_asset( $path ) {
	return G2A_URI . '/assets/' . ltrim( $path, '/' );
}

/**
 * Pull a hero image with sensible fallback to the staging CDN.
 */
function g2a_image( $key, $fallback = '' ) {
	$v = get_theme_mod( 'g2a_img_' . $key, '' );
	return $v ? esc_url( $v ) : esc_url( $fallback );
}

/**
 * Render a section partial from /template-parts/sections/.
 */
function g2a_section( $name, $args = [] ) {
	get_template_part( 'template-parts/sections/' . $name, null, $args );
}

/* ---------- Reservation / request form handler ----------
 * Powers the course, private-instruction and arsenal reservation forms.
 * Emails the business inbox and redirects back with a success flag.
 */
function g2a_handle_reservation() {
	// Honeypot  silent drop for bots.
	if ( ! empty( $_POST['g2a_hp'] ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	if ( ! isset( $_POST['g2a_nonce'] ) || ! wp_verify_nonce( $_POST['g2a_nonce'], 'g2a_reservation' ) ) {
		wp_die( 'Security check failed. Please go back and submit the form again.' );
	}

	$subject = sanitize_text_field( wp_unslash( $_POST['g2a_subject'] ?? 'Reservation Request' ) );
	$fields  = [
		'Name'             => sanitize_text_field( wp_unslash( $_POST['g2a_name'] ?? '' ) ),
		'Email'            => sanitize_email( wp_unslash( $_POST['g2a_email'] ?? '' ) ),
		'Phone'            => sanitize_text_field( wp_unslash( $_POST['g2a_phone'] ?? '' ) ),
		'Preferred Date'   => sanitize_text_field( wp_unslash( $_POST['g2a_date'] ?? '' ) ),
		'Participants'     => sanitize_text_field( wp_unslash( $_POST['g2a_count'] ?? '' ) ),
		'Experience Level' => sanitize_text_field( wp_unslash( $_POST['g2a_experience'] ?? '' ) ),
		'Notes'            => sanitize_textarea_field( wp_unslash( $_POST['g2a_notes'] ?? '' ) ),
	];

	$lines = [ 'New request from the Guns 2 Ammo website.', '', 'Request: ' . $subject, '' ];
	foreach ( $fields as $label => $value ) {
		if ( '' !== $value ) $lines[] = $label . ': ' . $value;
	}

	$to      = get_theme_mod( 'g2a_email', get_option( 'admin_email' ) );
	$headers = [];
	if ( ! empty( $fields['Email'] ) ) {
		$headers[] = 'Reply-To: ' . $fields['Name'] . ' <' . $fields['Email'] . '>';
	}
	wp_mail( $to, 'Website Request: ' . $subject, implode( "\n", $lines ), $headers );

	// Also log the submission into the WPistic Contact Form dashboard so
	// staff can review + reply from one place. Silent no-op if the plugin
	// isn't active. Passes notify_admin=false because we already mailed
	// the admin above and don't want a duplicate notification.
	g2a_capture_to_wpcf( 'Reservation: ' . $subject, $fields );

	$back = wp_get_referer() ?: home_url( '/' );
	wp_safe_redirect( add_query_arg( 'g2a_sent', '1', remove_query_arg( 'g2a_sent', $back ) ) . '#reserve' );
	exit;
}
add_action( 'admin_post_g2a_reservation', 'g2a_handle_reservation' );
add_action( 'admin_post_nopriv_g2a_reservation', 'g2a_handle_reservation' );

/* ---------- Generic request handler ----------
 * Powers variable-field forms (firearm transfer requests, etc.). Any input
 * named g2a_f_<slug> is captured, labelled, and emailed to the business inbox.
 */
function g2a_handle_request() {
	if ( ! empty( $_POST['g2a_hp'] ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	if ( ! isset( $_POST['g2a_nonce'] ) || ! wp_verify_nonce( $_POST['g2a_nonce'], 'g2a_request' ) ) {
		wp_die( 'Security check failed. Please go back and submit the form again.' );
	}

	$subject = sanitize_text_field( wp_unslash( $_POST['g2a_subject'] ?? 'Website Request' ) );
	$lines   = [ 'New request from the Guns 2 Ammo website.', '', 'Request: ' . $subject, '' ];
	$reply   = '';
	$fields  = [];

	foreach ( $_POST as $key => $value ) {
		if ( 0 !== strpos( $key, 'g2a_f_' ) ) continue;
		$label = ucwords( str_replace( [ 'g2a_f_', '_', '-' ], [ '', ' ', ' ' ], $key ) );
		// Array-valued fields (e.g. multi-checkbox)  flatten before sanitizing
		// so sanitize_textarea_field() never receives an array.
		if ( is_array( $value ) ) {
			$clean = implode( ', ', array_map( 'sanitize_text_field', wp_unslash( $value ) ) );
		} else {
			$clean = sanitize_textarea_field( wp_unslash( $value ) );
		}
		if ( '' === $clean ) continue;
		if ( 'Email' === $label ) $reply = sanitize_email( $clean );
		$lines[] = $label . ': ' . $clean;
		$fields[ $label ] = $clean;
	}

	$to      = get_theme_mod( 'g2a_email', get_option( 'admin_email' ) );
	$headers = $reply ? [ 'Reply-To: ' . $reply ] : [];
	wp_mail( $to, 'Website Request: ' . $subject, implode( "\n", $lines ), $headers );

	// Also log into the WPistic Contact Form dashboard for unified inbox.
	g2a_capture_to_wpcf( $subject, $fields );

	$back = wp_get_referer() ?: home_url( '/' );
	wp_safe_redirect( add_query_arg( 'g2a_sent', '1', remove_query_arg( 'g2a_sent', $back ) ) . '#request' );
	exit;
}
add_action( 'admin_post_g2a_request', 'g2a_handle_request' );
add_action( 'admin_post_nopriv_g2a_request', 'g2a_handle_request' );

/**
 * Capture a theme form submission into the WPistic Contact Form dashboard.
 *
 * Single bridge so every Guns 2 Ammo theme form (reservation, sell-your-gun,
 * transfer-request, get-support, contact, etc.) lands in WPCF's submissions
 * list and can be replied to from one inbox. wp_mail to the admin already
 * fires separately in the calling handler — passing notify_admin=false here
 * prevents WPCF from sending its own duplicate notification.
 *
 * Silent no-op when WPistic Contact Form is not active.
 *
 * @param string $form_name Human-readable label shown in the WPCF list.
 * @param array  $fields    Label => value pairs from the form.
 */
function g2a_capture_to_wpcf( $form_name, array $fields ) {
	if ( ! class_exists( 'WPISTIC_CF_Capture' ) ) {
		return 0;
	}
	try {
		return ( new WPISTIC_CF_Capture() )->store( (string) $form_name, $fields, false );
	} catch ( \Throwable $e ) {
		// Never let a logging side-effect break the form post.
		if ( function_exists( 'error_log' ) ) {
			error_log( '[g2a_capture_to_wpcf] ' . $e->getMessage() );
		}
		return 0;
	}
}
