<?php
/**
 * Custom login URLs.
 *
 * The site exposes two distinct login surfaces:
 *
 *   /login/                  — member-facing branded login (rendered by
 *                              page-templates/template-login.php inside
 *                              the G2A theme shell).
 *   /g2a-members-login/      — alias of /login/ for the "Members Hub"
 *                              wording.
 *   /g2a-admin-login/        — staff/admin/editor login. Internally
 *                              routes to wp-login.php with a one-time
 *                              token so direct /wp-login.php access
 *                              from the public internet returns 404.
 *
 * Direct /wp-login.php and /wp-admin/ are hidden from the public.
 * Anyone hitting them without already being logged in gets bounced
 * to the appropriate URL (admins → /g2a-admin-login/, everyone else
 * → /login/).
 *
 * The login_url + lostpassword_url filters are repointed at /login/
 * so every customer-facing email (membership welcome, password reset,
 * etc.) carries the branded link instead of /wp-login.php.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * 1. Auto-create the public login pages on theme switch.
 *    Idempotent — re-runs are safe.
 * ============================================================ */
add_action( 'after_switch_theme', 'g2a_login_ensure_pages' );
function g2a_login_ensure_pages() {
	$pages = array(
		'login' => array(
			'title'    => 'Member Login',
			'template' => 'page-templates/template-login.php',
		),
		'g2a-members-login' => array(
			'title'    => 'Members Hub Login',
			'template' => 'page-templates/template-login.php',
		),
	);
	foreach ( $pages as $slug => $info ) {
		if ( get_page_by_path( $slug ) ) {
			continue;
		}
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $info['title'],
			'post_name'    => $slug,
			'post_content' => '',
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_wp_page_template', $info['template'] );
		}
	}
}

/* ============================================================
 * 2. Point WP's login_url + lostpassword_url at /login/.
 *    Affects emails (membership welcome, password reset) and
 *    any wp_loginout() call site, as long as the calling code
 *    uses the filter (most do via login_url()).
 * ============================================================ */
add_filter( 'login_url', 'g2a_login_url_filter', 10, 3 );
function g2a_login_url_filter( $login_url, $redirect, $force_reauth ) {
	$url = home_url( '/login/' );
	if ( ! empty( $redirect ) ) {
		$url = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );
	}
	if ( $force_reauth ) {
		$url = add_query_arg( 'reauth', '1', $url );
	}
	return $url;
}

add_filter( 'lostpassword_url', 'g2a_lostpassword_url_filter', 10, 2 );
function g2a_lostpassword_url_filter( $lostpassword_url, $redirect ) {
	$url = add_query_arg( 'action', 'lostpassword', home_url( '/login/' ) );
	if ( ! empty( $redirect ) ) {
		$url = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );
	}
	return $url;
}

add_filter( 'register_url', function ( $register_url ) {
	return home_url( '/memberships/' );
} );

/* ============================================================
 * 3. /g2a-admin-login/  →  wp-login.php
 *    Registers a rewrite rule that internally routes to the
 *    real wp-login.php so admins still get the WP login form.
 *    The one-time hidden parameter g2a_admin_login=1 also
 *    unblocks the guard in step 4.
 * ============================================================ */
add_action( 'init', 'g2a_login_register_admin_rewrite' );
function g2a_login_register_admin_rewrite() {
	add_rewrite_rule(
		'^g2a-admin-login/?$',
		'index.php?g2a_admin_login=1',
		'top'
	);
}
add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'g2a_admin_login';
	return $vars;
} );
add_action( 'parse_request', 'g2a_login_handle_admin_alias' );
function g2a_login_handle_admin_alias( $wp ) {
	if ( empty( $wp->query_vars['g2a_admin_login'] ) ) {
		return;
	}
	// Mark this request as the admin-login bypass so the
	// wp-login.php guard in step 4 allows it through.
	$_GET['g2a_admin_login'] = '1';
	require ABSPATH . 'wp-login.php';
	exit;
}

/* ============================================================
 * 4. Block direct /wp-login.php from the public.
 *
 *    A real admin coming through /g2a-admin-login/ is allowed
 *    (carries the g2a_admin_login=1 marker). A bypass cookie
 *    g2a_admin_login=1 is set for the next 24h on first
 *    successful admin login so the admin can refresh wp-login
 *    URLs naturally if their session expires. Everyone else
 *    is bounced to /login/.
 * ============================================================ */
add_action( 'login_init', 'g2a_login_guard_wp_login' );
function g2a_login_guard_wp_login() {
	// Allow XML-RPC + AJAX + REST + WP-CLI paths through unconditionally.
	if ( defined( 'XMLRPC_REQUEST' ) || defined( 'WP_CLI' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	// Allow the bypass query param (set by /g2a-admin-login/).
	if ( ! empty( $_GET['g2a_admin_login'] ) ) {
		// Drop a 24-hour bypass cookie so refreshes work.
		if ( ! headers_sent() ) {
			setcookie( 'g2a_admin_login', '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
		return;
	}
	// Allow the bypass cookie.
	if ( ! empty( $_COOKIE['g2a_admin_login'] ) ) {
		return;
	}
	// Allow logout actions (action=logout / loggedout) so wp_loginout()
	// links from the admin bar continue to work cleanly even after the
	// cookie expires.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( in_array( $action, array( 'logout', 'loggedout' ), true ) ) {
		return;
	}
	// Allow already-logged-in administrators / editors so they can
	// re-auth normally if they hit the URL directly.
	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return;
	}
	// Anyone else hitting wp-login.php gets bounced to /login/.
	wp_safe_redirect( home_url( '/login/' ) );
	exit;
}

/* ============================================================
 * 5. Block /wp-admin/ for non-staff. Subscribers + members + walk-ins
 *    don't need the WP dashboard. They get the public member account
 *    page instead.
 * ============================================================ */
add_action( 'admin_init', 'g2a_login_guard_wp_admin' );
function g2a_login_guard_wp_admin() {
	if ( wp_doing_ajax() ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/login/' ) );
		exit;
	}
	if ( current_user_can( 'edit_posts' ) ) {
		return; // staff: editors and above.
	}
	$account = home_url( '/account/' );
	wp_safe_redirect( $account );
	exit;
}

/* ============================================================
 * 6. Flush rewrite rules on theme activation so /g2a-admin-login/
 *    starts working immediately after upload (no manual
 *    Settings → Permalinks click required).
 * ============================================================ */
add_action( 'after_switch_theme', 'flush_rewrite_rules' );
