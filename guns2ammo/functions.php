<?php
/**
 * Guns 2 Ammo  child theme functions
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'G2A_VERSION', '1.27.5' );
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

/* ---------- Body classes ---------- */
add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'g2a-body';
	if ( is_front_page() ) $classes[] = 'g2a-home';
	return $classes;
} );

/* ---------- SEO + Schema ---------- */
require_once G2A_DIR . '/inc/business-info.php';
require_once G2A_DIR . '/inc/instructors.php';
require_once G2A_DIR . '/inc/seo.php';

/* ---------- WooCommerce tweaks ---------- */
require_once G2A_DIR . '/inc/woocommerce.php';

/* ---------- Customizer image fields (light) ---------- */
require_once G2A_DIR . '/inc/customizer.php';

/* ---------- Client content editing (Appearance → Site Content) ---------- */
require_once G2A_DIR . '/inc/site-content.php';

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

/* ---------- Ottertext decommission (retired in favour of Verifyistic) ---------- */
require_once G2A_DIR . '/inc/ottertext-cleanup.php';

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

/* ---------- Live Google review count ----------
 * Hardcoded review numbers drift (449 vs 500 vs the real 556). This layer
 * keeps ONE live number: a twice-daily cron pulls rating + count from the
 * Google Places Details API when an API key is saved in the Customizer
 * (g2a_places_api_key); the result transparently overrides the
 * g2a_review_count / g2a_review_rating theme mods via their core
 * `theme_mod_*` filters — so the footer, JSON-LD schema, and every
 * template that already reads g2a_biz() updates automatically, no manual
 * hardcode edits ever again. Without an API key the Customizer numbers
 * remain the single manual source of truth.
 */
function g2a_reviews_live() {
	// Only honor cached live values while the integration is actually
	// configured. If the Places API key is removed, the Customizer numbers
	// immediately become authoritative again — no stale Google data.
	if ( '' === trim( (string) get_theme_mod( 'g2a_places_api_key', '' ) ) ) {
		return array();
	}
	$d = get_option( 'g2a_google_reviews_live' );
	return is_array( $d ) ? $d : array();
}
function g2a_reviews_count() {
	$live = g2a_reviews_live();
	return ! empty( $live['count'] ) ? (int) $live['count'] : (int) get_theme_mod( 'g2a_review_count', 556 );
}
function g2a_reviews_rating() {
	$live = g2a_reviews_live();
	return ! empty( $live['rating'] ) ? (float) $live['rating'] : (float) get_theme_mod( 'g2a_review_rating', 4.7 );
}
function g2a_reviews_label() {
	return number_format( g2a_reviews_rating(), 1 ) . '★ · ' . g2a_reviews_count() . ' Google reviews';
}
add_filter( 'theme_mod_g2a_review_count', function ( $value ) {
	$live = g2a_reviews_live();
	return ! empty( $live['count'] ) ? (int) $live['count'] : $value;
} );
add_filter( 'theme_mod_g2a_review_rating', function ( $value ) {
	$live = g2a_reviews_live();
	return ! empty( $live['rating'] ) ? (float) $live['rating'] : $value;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'g2a_refresh_google_reviews' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'g2a_refresh_google_reviews' );
	}
} );
add_action( 'g2a_refresh_google_reviews', function () {
	$key      = trim( (string) get_theme_mod( 'g2a_places_api_key', '' ) );
	$place_id = trim( (string) get_theme_mod( 'g2a_place_id', 'ChIJaSRMhpGvK4cR4kE_E-jZvKE' ) );
	if ( '' === $key || '' === $place_id ) {
		// Integration disabled — drop any cached live values so the
		// Customizer numbers become authoritative again (otherwise stale
		// Google data would keep overriding them indefinitely).
		delete_option( 'g2a_google_reviews_live' );
		return;
	}
	$url = add_query_arg( array(
		'place_id' => rawurlencode( $place_id ),
		'fields'   => 'rating,user_ratings_total',
		'key'      => rawurlencode( $key ),
	), 'https://maps.googleapis.com/maps/api/place/details/json' );
	$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return;
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['result'] ) || ! is_array( $body['result'] ) ) {
		return;
	}
	$rating = isset( $body['result']['rating'] ) ? (float) $body['result']['rating'] : 0;
	$count  = isset( $body['result']['user_ratings_total'] ) ? (int) $body['result']['user_ratings_total'] : 0;
	if ( $rating > 0 && $count > 0 ) {
		update_option( 'g2a_google_reviews_live', array(
			'rating'  => $rating,
			'count'   => $count,
			'updated' => time(),
		), false );
	}
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
/**
 * Verify a theme-form submission, tolerating the stale nonces that full-page
 * caches and CDNs inevitably serve.
 *
 * A public marketing site behind a page cache serves ONE cached copy of the
 * form (and its embedded nonce) to every visitor. WordPress nonces expire
 * after ~24h, but the cached HTML lives on — so once the baked-in nonce
 * lapses, every legitimate submission fails wp_verify_nonce() and the visitor
 * hits a hard "Security check failed" wall. That is the single most common
 * cause of "the contact form suddenly stopped working for everyone."
 *
 * To keep the forms working we treat the nonce as the FIRST line of CSRF
 * defense, not the only one: a fresh valid nonce is accepted outright; a
 * missing/expired one is accepted UNLESS the request advertises a foreign
 * Origin/Referer host (a clear cross-site POST), which is rejected. The
 * caller's honeypot and the WPistic Contact Form spam stack (per-IP rate
 * limit, blocklist, Akismet) remain the active bot/abuse defenses on capture.
 *
 * @param string $action Nonce action the form was created with.
 * @return bool True when the submission should be processed.
 */
function g2a_verify_form_request( $action ) {
	$nonce = isset( $_POST['g2a_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['g2a_nonce'] ) ) : '';
	if ( $nonce && wp_verify_nonce( $nonce, $action ) ) {
		return true; // Fresh, valid nonce — best case.
	}

	// Cache-stale / missing nonce: reject only an explicit cross-site POST.
	$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$origins   = array(
		wp_get_referer(),
		isset( $_SERVER['HTTP_ORIGIN'] ) ? wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '',
	);
	foreach ( $origins as $candidate ) {
		if ( ! $candidate ) {
			continue;
		}
		$host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
		if ( $host && $host !== $home_host ) {
			return false; // Foreign origin/referer → genuine cross-site request.
		}
	}
	return true;
}

function g2a_handle_reservation() {
	// Honeypot  silent drop for bots.
	if ( ! empty( $_POST['g2a_hp'] ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	if ( ! g2a_verify_form_request( 'g2a_reservation' ) ) {
		$back = wp_get_referer() ?: home_url( '/' );
		wp_safe_redirect( add_query_arg( 'g2a_sent', 'error', remove_query_arg( 'g2a_sent', $back ) ) . '#reserve' );
		exit;
	}

	$subject = sanitize_text_field( wp_unslash( $_POST['g2a_subject'] ?? 'Reservation Request' ) );
	$fields  = [
		'Name'             => sanitize_text_field( wp_unslash( $_POST['g2a_name'] ?? '' ) ),
		'Email'            => sanitize_email( wp_unslash( $_POST['g2a_email'] ?? '' ) ),
		'Phone'            => sanitize_text_field( wp_unslash( $_POST['g2a_phone'] ?? '' ) ),
		// Read the selected course server-side so the choice is captured even
		// if the form's JS (which copies it into the subject) is blocked.
		'Course'           => sanitize_text_field( wp_unslash( $_POST['g2a_course'] ?? '' ) ),
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
	if ( ! empty( $fields['Email'] ) && is_email( $fields['Email'] ) ) {
		// SECURITY: strip CRLF + any control chars from the display name to
		// defeat header injection via a malicious "Name" field.
		$safe_name = preg_replace( '/[\r\n\t]+/', ' ', (string) $fields['Name'] );
		$safe_name = trim( wp_strip_all_tags( $safe_name ) );
		$safe_name = substr( $safe_name, 0, 80 );
		$headers[] = 'Reply-To: ' . ( $safe_name ? $safe_name . ' <' . $fields['Email'] . '>' : $fields['Email'] );
	}
	wp_mail( $to, 'Website Request: ' . $subject, implode( "\n", $lines ), $headers );

	// Also log the submission into the Formistic (or legacy WPistic CF)
	// dashboard so staff can review + reply from one place. Silent no-op if
	// neither plugin is active. Passes notify=false because we already
	// mailed the admin above and don't want a duplicate notification.
	g2a_capture_to_formistic( 'Reservation: ' . $subject, $fields );

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
	if ( ! g2a_verify_form_request( 'g2a_request' ) ) {
		$back = wp_get_referer() ?: home_url( '/' );
		wp_safe_redirect( add_query_arg( 'g2a_sent', 'error', remove_query_arg( 'g2a_sent', $back ) ) . '#request' );
		exit;
	}

	$subject = sanitize_text_field( wp_unslash( $_POST['g2a_subject'] ?? 'Website Request' ) );
	$lines   = [ 'New request from the Guns 2 Ammo website.', '', 'Request: ' . $subject, '' ];
	$reply   = '';
	$fields  = [];

	$_field_count = 0;
	foreach ( $_POST as $key => $value ) {
		if ( 0 !== strpos( $key, 'g2a_f_' ) ) continue;
		if ( ++$_field_count > 40 ) break; // cap to deter unbounded-field abuse
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
	$headers = ( $reply && is_email( $reply ) ) ? [ 'Reply-To: ' . $reply ] : [];
	wp_mail( $to, 'Website Request: ' . $subject, implode( "\n", $lines ), $headers );

	// Also log into the Formistic (or legacy WPistic CF) unified inbox.
	g2a_capture_to_formistic( $subject, $fields );

	$back = wp_get_referer() ?: home_url( '/' );
	wp_safe_redirect( add_query_arg( 'g2a_sent', '1', remove_query_arg( 'g2a_sent', $back ) ) . '#request' );
	exit;
}
add_action( 'admin_post_g2a_request', 'g2a_handle_request' );
add_action( 'admin_post_nopriv_g2a_request', 'g2a_handle_request' );

/**
 * URL that public theme forms POST to.
 *
 * Deliberately a FRONT-END url (the site home), NOT
 * /wp-admin/admin-post.php. Many security plugins, WAFs (Cloudflare), and
 * managed hosts block all /wp-admin/ requests for logged-OUT visitors. That
 * silently broke every public form for anyone who wasn't logged in — the form
 * submitted fine for admins (who pass the wp-admin gate) but errored for
 * everyone else. Posting to the front end and handling the submission on
 * template_redirect (see g2a_route_frontend_form) sidesteps the gate entirely.
 *
 * @return string
 */
function g2a_form_action_url() {
	return home_url( '/' );
}

/**
 * Process public theme form submissions on the FRONT END.
 *
 * Forms post to g2a_form_action_url() (the site home) instead of
 * admin-post.php. We catch the POST early on template_redirect — before
 * redirect_canonical (priority 10) and before the template renders — and
 * dispatch to the same handlers the admin_post_* hooks use. Those hooks stay
 * registered so any page still cached with the old admin-post.php action keeps
 * working for logged-in users.
 *
 * @return void
 */
function g2a_route_frontend_form() {
	if ( is_admin() || empty( $_POST['action'] ) ) {
		return;
	}
	$action = sanitize_key( wp_unslash( $_POST['action'] ) );
	if ( 'g2a_request' === $action ) {
		g2a_handle_request();
	} elseif ( 'g2a_reservation' === $action ) {
		g2a_handle_reservation();
	}
}
add_action( 'template_redirect', 'g2a_route_frontend_form', 0 );

/**
 * Front-end newsletter subscribe endpoint.
 *
 * The footer + blog "range updates" forms used to fetch() /wp-admin/admin-ajax.php,
 * which — like admin-post.php — is blocked for logged-OUT visitors on sites
 * behind a wp-admin firewall, so newsletter signups failed for everyone not
 * logged in. This posts to the site home instead and reuses the Formistic
 * newsletter routine (falling back to the legacy WPistic Contact Form one),
 * both of which carry their own per-IP throttle. Returns JSON so the inline
 * status keeps working; same cache-resilient verification as the other theme
 * forms.
 *
 * @return void
 */
function g2a_route_frontend_newsletter() {
	if ( is_admin() || empty( $_POST['g2a_newsletter'] ) ) {
		return;
	}
	$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'footer';

	if ( ! g2a_verify_form_request( 'g2a_newsletter' ) ) {
		wp_send_json( array( 'status' => 'error', 'message' => __( 'Could not verify your request. Please reload the page and try again.', 'guns2ammo' ) ), 403 );
	}
	// Preferred: Formistic newsletter (same return shape as the legacy
	// plugin, own per-IP throttle, dedicated subscribers table).
	if ( class_exists( 'Wpistic_Formistic_Newsletter' ) && is_callable( array( 'Wpistic_Formistic_Newsletter', 'process' ) ) ) {
		$res = Wpistic_Formistic_Newsletter::process( $email, $source );
		$ok  = in_array( ( $res['status'] ?? '' ), array( 'ok', 'duplicate' ), true );
		wp_send_json( $res, $ok ? 200 : 400 );
	}
	// Legacy fallback: original WPistic Contact Form newsletter.
	if ( class_exists( 'WPISTIC_CF_Newsletter' ) && is_callable( array( 'WPISTIC_CF_Newsletter', 'process' ) ) ) {
		$res = WPISTIC_CF_Newsletter::process( $email, $source );
		$ok  = in_array( ( $res['status'] ?? '' ), array( 'ok', 'duplicate' ), true );
		wp_send_json( $res, $ok ? 200 : 400 );
	}
	wp_send_json( array( 'status' => 'error', 'message' => __( 'Newsletter signup is temporarily unavailable.', 'guns2ammo' ) ), 503 );
}
add_action( 'template_redirect', 'g2a_route_frontend_newsletter', 0 );

/**
 * Capture a theme form submission into the site's contact-inbox plugin.
 *
 * Single bridge so every Guns 2 Ammo theme form (reservation, sell-your-gun,
 * transfer-request, get-support, contact, etc.) lands in one submissions
 * list and can be replied to from one inbox. wp_mail to the admin already
 * fires separately in the calling handler — passing notify=false here
 * prevents the plugin from sending its own duplicate notification.
 *
 * Prefers Formistic (the v2 rebrand of WPistic Contact Form); falls back to
 * the legacy WPISTIC_CF_Capture path so nothing breaks whichever plugin is
 * active during the transition. Silent no-op when neither is active.
 *
 * @param string $form_name Human-readable label shown in the inbox list.
 * @param array  $fields    Label => value pairs from the form.
 * @return int Submission ID, or 0 when not stored.
 */
function g2a_capture_to_formistic( $form_name, array $fields ) {
	try {
		// Preferred: Formistic public helper (sanitizes + spam stack).
		if ( function_exists( 'formistic_capture_contact' ) ) {
			return (int) formistic_capture_contact( $fields, array(
				'form_name' => (string) $form_name,
				'notify'    => false,
			) );
		}
		// Formistic loaded but helper unavailable — use the class directly.
		if ( class_exists( 'Wpistic_Formistic_Capture' ) ) {
			return (int) ( new Wpistic_Formistic_Capture() )->store( (string) $form_name, $fields, false );
		}
		// Legacy fallback: original WPistic Contact Form plugin.
		if ( class_exists( 'WPISTIC_CF_Capture' ) ) {
			return (int) ( new WPISTIC_CF_Capture() )->store( (string) $form_name, $fields, false );
		}
	} catch ( \Throwable $e ) {
		// Never let a logging side-effect break the form post.
		if ( function_exists( 'error_log' ) ) {
			error_log( '[g2a_capture_to_formistic] ' . $e->getMessage() );
		}
	}
	return 0;
}

/**
 * Back-compat alias for the old bridge name, in case custom code outside the
 * theme still calls it.
 *
 * @deprecated 1.27.4 Use g2a_capture_to_formistic() instead.
 *
 * @param string $form_name Human-readable label shown in the inbox list.
 * @param array  $fields    Label => value pairs from the form.
 * @return int Submission ID, or 0 when not stored.
 */
function g2a_capture_to_wpcf( $form_name, array $fields ) {
	return g2a_capture_to_formistic( $form_name, $fields );
}
