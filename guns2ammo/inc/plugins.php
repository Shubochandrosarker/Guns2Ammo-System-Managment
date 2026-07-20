<?php
/**
 * Plugin integration layer  wires the Guns 2 Ammo theme to the
 * Memberistic Membership Solutions and G2A Booking Engine plugins.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* Which page template hosts which plugin shortcode. */
function g2a_plugin_template_map() {
	return [
		'page-templates/template-memberships.php'    => 'memberistic_plans',
		'page-templates/template-checkout.php'       => 'memberistic_checkout',
		'page-templates/template-account.php'        => 'memberistic_account',
		'page-templates/template-login.php'          => 'memberistic_login',
		'page-templates/template-renewal.php'        => 'memberistic_renewal',
		'page-templates/template-thank-you.php'      => 'memberistic_thank_you',
		'page-templates/template-payment-failed.php' => 'memberistic_payment_failed',
		'page-templates/template-staff.php'          => 'memberistic_staff_dashboard',
		'page-templates/template-book-a-lane.php'    => 'g2a_lane_booking',
		'page-templates/template-ccw.php'            => 'g2a_event_booking',
		'page-templates/template-ladies-tuesday.php' => 'g2a_event_booking',
	];
}

/**
 * Render a plugin shortcode, or a branded fallback when the plugin is inactive.
 */
function g2a_plugin_section( $shortcode, $atts = '', $fallback = '' ) {
	if ( shortcode_exists( $shortcode ) ) {
		return do_shortcode( '[' . $shortcode . $atts . ']' );
	}
	if ( '' !== $fallback ) {
		return $fallback;
	}
	// Single source of truth for NAP — see inc/business-info.php.
	$g2a_biz   = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
	$g2a_phone = $g2a_biz['phone'] ?? '(602) 715-2677';
	return '<div class="g2a-plugin-missing"><strong>Coming online shortly.</strong> '
		. 'This section is powered by a Guns&nbsp;2&nbsp;Ammo system that activates once the '
		. 'site is fully configured. Please check back soon or call ' . str_replace( ' ', '&nbsp;', $g2a_phone ) . '.</div>';
}

/** True when Memberistic is active. */
function g2a_has_memberistic() {
	return shortcode_exists( 'memberistic_plans' ) || defined( 'MEMBERISTIC_VERSION' );
}

/** True when the G2A Booking Engine is active. */
function g2a_has_booking() {
	return shortcode_exists( 'g2a_lane_booking' ) || defined( 'G2AB_VERSION' );
}

/**
 * THE INTEGRATION FIX.
 *
 * Memberistic and the G2A Booking Engine only load their frontend CSS/JS when
 * the active shortcode is found in the page's *post_content*. Our branded
 * templates render the shortcode from the *template file*, so the page content
 * is empty and the plugins never load their assets  the shortcodes render
 * completely unstyled.
 *
 * We fix it at the source: on a plugin-host template we append the shortcode
 * tag to the in-memory $post->post_content. The plugins' own detection then
 * passes and they enqueue + localize their assets exactly as on their native
 * pages. Nothing renders twice  these templates never call the_content().
 */
add_action( 'wp', function () {
	if ( ! is_page() && ! is_front_page() ) return;
	global $post;
	if ( ! $post instanceof WP_Post ) return;

	$inject = [];
	$tpl    = get_page_template_slug( $post->ID );
	$map    = g2a_plugin_template_map();
	if ( $tpl && isset( $map[ $tpl ] ) ) {
		$inject[] = $map[ $tpl ];
	}
	if ( is_front_page() ) {
		$inject[] = 'g2a_event_banner';
	}
	foreach ( $inject as $sc ) {
		if ( false === strpos( (string) $post->post_content, '[' . $sc ) ) {
			$post->post_content .= "\n<!-- g2a:[{$sc}] -->";
		}
	}
} );

/* ---------- Force-enqueue plugin assets (belt-and-suspenders) ---------- */
add_action( 'wp_enqueue_scripts', function () {
	$tpl = is_page() ? get_page_template_slug( get_queried_object_id() ) : '';
	$map = g2a_plugin_template_map();
	$sc  = $tpl && isset( $map[ $tpl ] ) ? $map[ $tpl ] : '';
	$is_memberistic = $sc && 0 === strpos( $sc, 'memberistic_' );
	$is_booking     = ( $sc && 0 === strpos( $sc, 'g2a_' ) ) || is_front_page();

	if ( $is_memberistic && defined( 'MEMBERISTIC_URL' ) ) {
		wp_enqueue_style( 'memberistic-frontend', MEMBERISTIC_URL . 'assets/frontend.css', [], MEMBERISTIC_VERSION );
		wp_enqueue_script( 'memberistic-frontend', MEMBERISTIC_URL . 'assets/frontend.js', [], MEMBERISTIC_VERSION, true );
	}
	if ( $is_booking && defined( 'G2AB_URL' ) ) {
		wp_enqueue_style( 'g2ab-frontend', G2AB_URL . 'assets/css/frontend.css', [], G2AB_VERSION );
		wp_enqueue_script( 'g2ab-frontend', G2AB_URL . 'assets/js/frontend.js', [], G2AB_VERSION, true );
	}
}, 100 );

/* ---------- Dark-theme styling bridge for plugin output ---------- */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! g2a_has_memberistic() && ! g2a_has_booking() ) return;
	wp_register_style( 'g2a-plugin-bridge', false, [ 'g2a-tokens' ], G2A_VERSION );
	wp_enqueue_style( 'g2a-plugin-bridge' );
	wp_add_inline_style( 'g2a-plugin-bridge', g2a_plugin_bridge_css() );
}, 110 );

/** True when the Verifyistic age-verification plugin is active. */
function g2a_has_verifyistic() {
	return class_exists( 'Verifyistic_Frontend' ) || defined( 'VERIFYISTIC_VERSION' );
}

/* ---------- Guns 2 Ammo skin for the Verifyistic age popup ---------- */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! g2a_has_verifyistic() ) return;
	// The popup enqueues 'verifyistic-frontend'; attach our skin after it so it
	// wins. Falls back to a registered handle if the plugin didn't enqueue.
	$handle = wp_style_is( 'verifyistic-frontend', 'enqueued' ) ? 'verifyistic-frontend' : 'g2a-tokens';
	wp_add_inline_style( $handle, g2a_verifyistic_skin_css() );
}, 120 );

/**
 * Re-skin the Verifyistic popup to the Guns 2 Ammo tactical dark/brass look
 * (fonts, brass accents, sharp edges, ember CTA) so the age gate matches the
 * site. Uses the theme design tokens with safe fallbacks.
 */
function g2a_verifyistic_skin_css() {
	return '
	#verifyistic-overlay{background:rgba(10,9,12,.92)!important;backdrop-filter:blur(6px);}
	#verifyistic-popup{background:var(--color-gunmetal,#15141a)!important;border:1px solid var(--color-brass-dim,#6b5a23)!important;border-radius:2px!important;box-shadow:0 30px 80px rgba(0,0,0,.6)!important;}
	#verifyistic-popup .vfy-shield-icon{color:var(--color-brass-bright,#e3c25a)!important;}
	#verifyistic-popup .vfy-age-badge{background:rgba(201,168,76,.08)!important;border:1px solid var(--color-brass-dim,#6b5a23)!important;color:var(--color-brass-bright,#e3c25a)!important;font-family:var(--font-mono,monospace)!important;letter-spacing:.22em;text-transform:uppercase;border-radius:0!important;}
	#verifyistic-popup .vfy-heading{font-family:var(--font-display,Bebas Neue,sans-serif)!important;font-weight:400;letter-spacing:.02em;color:var(--color-white,#fff)!important;text-transform:uppercase;}
	#verifyistic-popup .vfy-message,#verifyistic-popup .vfy-form-label,#verifyistic-popup .vfy-remember-label,#verifyistic-popup .vfy-privacy{color:var(--color-fog,#b8b4ad)!important;}
	#verifyistic-popup .vfy-form-label{font-family:var(--font-condensed,Barlow Condensed,sans-serif)!important;text-transform:uppercase;letter-spacing:.06em;}
	#verifyistic-popup .vfy-divider{background:var(--color-hairline,rgba(255,255,255,.08))!important;}
	#verifyistic-popup .vfy-form-input,#verifyistic-popup select.vfy-dob-part{background:rgba(0,0,0,.25)!important;border:1px solid var(--color-hairline-bright,rgba(255,255,255,.14))!important;border-radius:0!important;color:var(--color-white,#fff)!important;font-family:var(--font-body,Barlow,sans-serif)!important;}
	#verifyistic-popup .vfy-form-input:focus,#verifyistic-popup select.vfy-dob-part:focus{border-color:var(--color-brass,#c9a84c)!important;box-shadow:0 0 0 2px rgba(201,168,76,.25)!important;background:rgba(201,168,76,.05)!important;}
	#verifyistic-popup select.vfy-dob-part option{color:#15141a;}
	#verifyistic-popup .vfy-btn-submit,#verifyistic-popup .vfy-btn-yes{background:var(--color-ember,#c2491f)!important;color:var(--color-ink,#111114)!important;border:0!important;border-radius:0!important;font-family:var(--font-condensed,Barlow Condensed,sans-serif)!important;font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
	#verifyistic-popup .vfy-btn-submit:hover,#verifyistic-popup .vfy-btn-yes:hover{filter:brightness(1.08);}
	#verifyistic-popup .vfy-btn-no{background:transparent!important;border:1px solid var(--color-hairline-bright,rgba(255,255,255,.18))!important;color:var(--color-silver,#9a958c)!important;border-radius:0!important;text-transform:uppercase;letter-spacing:.06em;font-family:var(--font-condensed,Barlow Condensed,sans-serif)!important;}
	#verifyistic-popup .vfy-success-title{font-family:var(--font-display,Bebas Neue,sans-serif)!important;color:var(--color-white,#fff)!important;text-transform:uppercase;}
	#verifyistic-popup .vfy-powered{color:var(--color-silver,#7a766e)!important;}
	';
}

/**
 * Recolour Memberistic + G2A Booking Engine frontend output to the tactical
 * dark/brass theme so plugin forms match the website.
 */
function g2a_plugin_bridge_css() {
	return '
	.g2a-plugin-missing{max-width:760px;margin:0 auto;padding:28px 32px;background:var(--color-gunmetal);border:1px solid var(--color-brass-dim);color:var(--color-fog);border-radius:2px;}
	.g2a-plugin-missing strong{color:var(--color-white);}
	.g2a-plugin-host{background:var(--color-void);}

	/* When Memberistic is live, hide the theme demo plan cards/toggles to avoid duplicates */
	body.g2a-memberistic-on .toggle-row,
	body.g2a-memberistic-on .toggle-state,
	body.g2a-memberistic-on .lin{display:none!important;}

	/* ============ MEMBERISTIC  recolour the light plugin UI to the dark theme ============ */
	/* Every plugin element: light text by default (kills inherited #111827 navy text) */
	[class^="memberistic-"],[class*=" memberistic-"]{color:var(--color-fog)!important;}
	.memberistic-frontend,.memberistic-plans-grid-wrap,.memberistic-account,
	.memberistic-checkout,.memberistic-staff-dashboard{--memberistic-brand:#C9A84C;background:transparent!important;}
	/* Cards / panels / alerts that ship white or light-grey -> dark gunmetal */
	.memberistic-plan-card,.memberistic-placeholder,.memberistic-account-summary,
	.memberistic-checkout-form,.memberistic-result,.memberistic-secondary-button,
	.memberistic-staff-panel,.memberistic-staff-empty,.memberistic-toast,
	.memberistic-status-none,.memberistic-expiring-notice,.memberistic-login-card,
	.memberistic-account-nav,.memberistic-people-table,
	[class^="memberistic-"][class*="card"],[class^="memberistic-"][class*="panel"],
	[class^="memberistic-"][class*="alert"],[class^="memberistic-"][class*="notice"],
	[class^="memberistic-"][class*="kpi"],[class^="memberistic-"][class*="summary"]{
		background:var(--color-gunmetal)!important;border:1px solid var(--color-hairline)!important;
		border-radius:2px;}
	/* Headings + emphasis -> white */
	[class^="memberistic-"] h1,[class^="memberistic-"] h2,[class^="memberistic-"] h3,
	[class^="memberistic-"] h4,[class^="memberistic-"] strong,[class^="memberistic-"] th,
	.memberistic-plan-card-name,.memberistic-account-summary-tier{color:var(--color-white)!important;}
	/* Prices -> brass */
	.memberistic-price,.memberistic-summary-price,.memberistic-plan-card-price,
	[class^="memberistic-"][class*="price"]{color:var(--color-brass-bright)!important;}
	/* Inputs -> dark */
	[class^="memberistic-"] input,[class^="memberistic-"] select,[class^="memberistic-"] textarea{
		background:var(--color-void)!important;border:1px solid var(--color-steel)!important;
		color:var(--color-white)!important;border-radius:2px;}
	/* Buttons -> ember / brass (placed last so they win the colour cascade) */
	.memberistic-plan-button,.memberistic-primary-button,.memberistic-checkout-form button[type=submit],
	.memberistic-login-card button[type=submit],.memberistic-staff-form button[type=submit],
	[class^="memberistic-"][class*="button"]:not(.memberistic-secondary-button){
		background:var(--color-ember)!important;color:#fff!important;border:none!important;border-radius:2px;
		font-family:var(--font-condensed)!important;font-weight:600;letter-spacing:0.08em;
		text-transform:uppercase;cursor:pointer;}
	.memberistic-secondary-button{background:transparent!important;border:1px solid var(--color-brass)!important;color:var(--color-brass-bright)!important;}
	.memberistic-billing-toggle button{background:var(--color-gunmetal)!important;border:1px solid var(--color-hairline-bright)!important;color:var(--color-fog)!important;}
	.memberistic-billing-toggle button.is-active,.memberistic-billing-toggle .is-active{background:var(--color-brass)!important;color:#111!important;}
	.memberistic-plan-card.is-popular,.memberistic-plan-card--featured{border-color:var(--color-brass)!important;}
	.memberistic-account-tabs a,.memberistic-account-nav a{color:var(--color-white)!important;}

	/* --- Membership package layout: match the polished /pricing/ plan cards --- */
	.memberistic-plan-grid,.memberistic-plans-grid,.memberistic-plans{
		display:grid!important;grid-template-columns:repeat(3,1fr)!important;gap:24px!important;
		max-width:1280px!important;margin:0 auto!important;align-items:stretch!important;}
	.memberistic-plan-card{
		padding:36px 32px!important;display:flex!important;flex-direction:column!important;gap:16px!important;
		position:relative!important;transition:transform 250ms var(--ease-std),border-color 250ms var(--ease-std);}
	.memberistic-plan-card:hover{transform:translateY(-4px);border-color:var(--color-hairline-bright)!important;}
	.memberistic-plan-card.is-popular,.memberistic-plan-card--featured{
		transform:scale(1.02);box-shadow:0 12px 48px rgba(201,168,76,0.08);}
	.memberistic-plan-card.is-popular:hover,.memberistic-plan-card--featured:hover{transform:scale(1.02) translateY(-4px);}
	.memberistic-plan-card-name,.memberistic-plan-name{
		font-family:var(--font-display)!important;font-size:36px!important;line-height:1!important;letter-spacing:0.04em!important;}
	.memberistic-plan-card-price,.memberistic-price{
		font-family:var(--font-display)!important;font-size:56px!important;line-height:0.9!important;}
	.memberistic-plan-card .memberistic-plan-features li,.memberistic-plan-card li{
		font-size:14px!important;line-height:1.5!important;}
	.memberistic-plan-button,.memberistic-plan-card [class*="button"]{margin-top:auto!important;width:100%!important;text-align:center!important;padding:14px 18px!important;}
	@media (max-width:980px){
		.memberistic-plan-grid,.memberistic-plans-grid,.memberistic-plans{
			grid-template-columns:1fr!important;max-width:480px!important;}
		.memberistic-plan-card.is-popular,.memberistic-plan-card--featured{transform:none;}
	}

	/* Native theme fallback plan cards on the memberships page */
	.plans-wrap{align-items:stretch;}
	.plans-wrap .plan{height:100%;}

	/* --- Contrast fixes: elements the blanket recolour leaves unreadable --- */
	/* Plan-card copy on the dark card */
	.memberistic-plan-description{color:var(--color-fog)!important;}
	.memberistic-included-people{color:var(--color-white)!important;}
	.memberistic-price-cycle,.memberistic-plan-card small{color:var(--color-silver)!important;}
	.memberistic-price-value{color:var(--color-brass-bright)!important;}
	.memberistic-checkout-form label,
	.memberistic-login-card label,
	.memberistic-checkout-form .memberistic-field-label,
	.memberistic-login-card .memberistic-field-label,
	.memberistic-checkout-form p,
	.memberistic-login-card p,
	.memberistic-checkout-form small,
	.memberistic-login-card small{color:var(--color-fog)!important;}
	.memberistic-checkout-form [type="checkbox"] + span,
	.memberistic-login-card [type="checkbox"] + span{color:var(--color-fog)!important;}
	.memberistic-checkout-form ::placeholder,
	.memberistic-login-card ::placeholder{color:var(--color-silver)!important;}
	.memberistic-benefits li{color:var(--color-fog)!important;}
	.memberistic-benefits li::before{color:var(--color-brass-bright)!important;}
	/* "Save $.. / year" badge  was light-on-light, now readable on dark */
	.memberistic-annual-savings{
		background:rgba(74,222,128,0.14)!important;color:#7BE49A!important;
		border:1px solid rgba(74,222,128,0.35)!important;}
	/* "Most Popular" featured badge */
	.memberistic-featured-badge{
		background:var(--color-brass)!important;color:#111!important;}

	/* ============ STAFF DASHBOARD  native light theme ============
	   The Memberistic staff dashboard ships a complete, polished light UI.
	   The dark recolour above turns it dark-on-dark and unreadable, so for
	   this one surface we restore the native light theme from the plugin:
	   a light panel of white cards sitting inside the dark site shell. */
	.memberistic-staff-dashboard{
		background:#eef1f6!important;padding:24px!important;border-radius:14px;
		border:1px solid var(--color-hairline)!important;}
	.memberistic-staff-dashboard [class^="memberistic-"],
	.memberistic-staff-dashboard [class*=" memberistic-"]{color:#1f2937!important;}
	/* White cards / panels / rows */
	.memberistic-staff-dashboard .memberistic-staff-panel,
	.memberistic-staff-dashboard .memberistic-staff-kpis>div,
	.memberistic-staff-dashboard .memberistic-staff-member,
	.memberistic-staff-dashboard .memberistic-staff-table-wrap{
		background:#fff!important;border:1px solid #d8dee8!important;border-radius:8px;}
	.memberistic-staff-dashboard .memberistic-staff-empty{
		background:#f8fafc!important;border:1px solid #e2e8f0!important;color:#64748b!important;}
	/* Navy header bar keeps light text */
	.memberistic-staff-dashboard .memberistic-staff-header{
		background:#0f2044!important;border:0!important;border-radius:10px;}
	.memberistic-staff-dashboard .memberistic-staff-header h2{color:#fff!important;}
	.memberistic-staff-dashboard .memberistic-staff-header p{color:#00c857!important;}
	.memberistic-staff-dashboard .memberistic-staff-user{color:#fff!important;}
	/* KPI cards */
	.memberistic-staff-dashboard .memberistic-staff-kpis>div span{color:#64748b!important;}
	.memberistic-staff-dashboard .memberistic-staff-kpis>div strong{color:#0f2044!important;}
	/* Headings, table headers, member names */
	.memberistic-staff-dashboard h2,.memberistic-staff-dashboard h3,
	.memberistic-staff-dashboard h4,
	.memberistic-staff-dashboard .memberistic-staff-panel th,
	.memberistic-staff-dashboard .memberistic-staff-member strong{color:#0f2044!important;}
	/* Form + search labels */
	.memberistic-staff-dashboard .memberistic-staff-form span,
	.memberistic-staff-dashboard label span,
	.memberistic-staff-dashboard .memberistic-staff-search label{color:#334155!important;}
	.memberistic-staff-dashboard .memberistic-staff-member span,
	.memberistic-staff-dashboard .memberistic-staff-member small{color:#64748b!important;}
	/* Inputs -> white */
	.memberistic-staff-dashboard input,
	.memberistic-staff-dashboard select,
	.memberistic-staff-dashboard textarea{
		background:#fff!important;border:1px solid #cbd5e1!important;color:#111827!important;
		border-radius:6px;}
	.memberistic-staff-dashboard input::placeholder,
	.memberistic-staff-dashboard textarea::placeholder{color:#94a3b8!important;}
	/* Buttons -> native navy */
	.memberistic-staff-dashboard button,
	.memberistic-staff-dashboard .memberistic-staff-form button,
	.memberistic-staff-dashboard .memberistic-staff-search button,
	.memberistic-staff-dashboard .memberistic-staff-member button{
		background:#0f2044!important;color:#fff!important;border:0!important;border-radius:6px;
		font-family:inherit!important;letter-spacing:0!important;text-transform:none!important;
		font-weight:700;cursor:pointer;}
	.memberistic-staff-dashboard button:hover,
	.memberistic-staff-dashboard .memberistic-staff-member button:hover{background:#1b3260!important;}
	.memberistic-staff-dashboard .memberistic-staff-notice--success{
		background:#d1fae5!important;color:#065f46!important;border:0!important;}
	.memberistic-staff-dashboard .memberistic-staff-notice--error{
		background:#fee2e2!important;color:#991b1b!important;border:0!important;}
	@media (max-width:600px){
		.memberistic-staff-dashboard{padding:14px!important;}
		.memberistic-staff-kpis{grid-template-columns:1fr 1fr!important;}
	}

	/* ============ G2A BOOKING ENGINE ============
	   The booking widget itself now ships a `site` skin (selected via the
	   g2ab_form_design_tokens filter below) that reads the theme tokens
	   directly — including light/dark mode — so the old blanket !important
	   recolour layer is gone. Only pieces rendered OUTSIDE the widget
	   (event cards, list fallbacks) still need a nudge onto the tokens. */
	.g2ab-evt__card{background:var(--color-gunmetal)!important;border:1px solid var(--color-hairline)!important;color:var(--color-fog)!important;border-radius:3px;}
	.g2ab-evt__name{color:var(--color-white)!important;font-family:var(--font-condensed)!important;letter-spacing:0.03em;}
	.g2ab-evt__meta,.g2ab-evt__seats{color:var(--color-silver)!important;}
	.g2ab-evt__price{color:var(--color-brass-bright)!important;}
	.g2ab-evt__tag{background:var(--color-ember)!important;color:#fff!important;}
	.g2ab-evt__cta{background:var(--color-ember)!important;color:#fff!important;border-radius:2px;font-family:var(--font-condensed)!important;letter-spacing:0.1em;text-transform:uppercase;}
	.g2ab-error{color:var(--color-ember)!important;}';
	/* .g2ab-form__error deliberately dropped from the rule above: this
	   !important was winning over the color set in the plugin's own
	   frontend.css (.g2ab-form__error uses var(--g2ab-text) there,
	   chosen specifically because its translucent-red background makes
	   var(--color-ember) text only ~2.7:1 in light mode — fails WCAG AA).
	   .g2ab-error itself is unaffected in practice: its only text lives in
	   a nested <p> with its own more specific color rule. */
}

/* ---------- Booking form skin: follow the site theme (incl. light/dark) ----------
 * The booking engine renders `g2ab-theme-{x}` and exposes the
 * g2ab_form_design_tokens filter. We switch the skin to `site` (inherits
 * the Guns2Ammo tokens), and to the dedicated rose-gold `ladies` skin on
 * the Ladies Tuesday page so that flow gets its own premium look.
 * Form-customizer radius/typography choices are preserved.
 */
add_filter( 'g2ab_form_design_tokens', function ( $tokens ) {
	$tokens['theme'] = 'site';
	if ( is_page_template( 'page-templates/template-ladies-tuesday.php' ) || is_page( 'ladies-tuesday' ) ) {
		$tokens['theme'] = 'ladies';
	}
	return $tokens;
} );

/* ---------- Body class so templates/CSS can adapt when plugins are live ---------- */
add_filter( 'body_class', function ( $classes ) {
	if ( g2a_has_memberistic() ) $classes[] = 'g2a-memberistic-on';
	if ( g2a_has_booking() )     $classes[] = 'g2a-booking-on';
	return $classes;
} );
