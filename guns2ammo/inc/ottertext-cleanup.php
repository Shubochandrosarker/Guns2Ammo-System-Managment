<?php
/**
 * Ottertext decommission / cleanup layer.
 *
 * Guns 2 Ammo has retired Ottertext. Age verification + visitor data capture
 * is now handled by the in-house Verifyistic plugin (with multi-webhook
 * delivery), and the liability waiver is handled separately. Ottertext is no
 * longer used for the on-site chatbot or the age-gate popup.
 *
 * Ottertext injects its chatbot/age-popup as an EXTERNAL script — it is not
 * part of this theme or any bundled plugin. Depending on how it was added it
 * may still load from one of:
 *
 *   • A "header/footer scripts" plugin (e.g. WPCode, Insert Headers & Footers)
 *   • Google Tag Manager / GA custom HTML tag
 *   • A theme-options "custom scripts" box
 *   • A leftover Ottertext companion plugin
 *   • The Ottertext dashboard embed snippet pasted into the site
 *
 * The single source of truth is to remove the snippet at that source AND turn
 * the integration off inside the Ottertext dashboard so no webhook/data link
 * remains. See docs/OTTERTEXT_REMOVAL.md for the full checklist.
 *
 * As a belt-and-suspenders safety net (so the ugly widget can't reappear while
 * the source is being cleaned up), this file:
 *   1. Dequeues any enqueued script/style whose handle or src references
 *      ottertext.
 *   2. Strips any inline <script>/<iframe>/widget markup that references
 *      ottertext from the rendered HTML.
 *
 * The net is intentionally narrow: it only matches the literal token
 * "ottertext", so nothing else on the site is affected. Remove this file once
 * the source embed is confirmed gone.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Master switch. Filterable so it can be disabled from a child theme / mu-plugin
 * once Ottertext is fully gone:  add_filter( 'g2a_ottertext_cleanup', '__return_false' );
 */
function g2a_ottertext_cleanup_enabled() {
	return (bool) apply_filters( 'g2a_ottertext_cleanup', true );
}

/**
 * The token(s) that identify Ottertext assets. Filterable in case the vendor
 * uses an alternate CDN host.
 *
 * @return string[]
 */
function g2a_ottertext_tokens() {
	return (array) apply_filters( 'g2a_ottertext_tokens', array( 'ottertext', 'otter-text', 'ottertext.com', 'ottertext.io' ) );
}

/* ---------------------------------------------------------------------------
 * 1. Dequeue enqueued Ottertext scripts/styles (handle- or src-matched).
 * ------------------------------------------------------------------------- */
add_action( 'wp_print_scripts', 'g2a_ottertext_dequeue_assets', 100 );
add_action( 'wp_print_styles', 'g2a_ottertext_dequeue_assets', 100 );
add_action( 'wp_footer', 'g2a_ottertext_dequeue_assets', 1 );
function g2a_ottertext_dequeue_assets() {
	if ( ! g2a_ottertext_cleanup_enabled() ) {
		return;
	}
	$tokens = g2a_ottertext_tokens();

	foreach ( array( wp_scripts(), wp_styles() ) as $registry ) {
		if ( ! $registry || empty( $registry->registered ) ) {
			continue;
		}
		foreach ( $registry->registered as $handle => $dep ) {
			$src   = isset( $dep->src ) ? strtolower( (string) $dep->src ) : '';
			$lhand = strtolower( (string) $handle );
			foreach ( $tokens as $token ) {
				$token = strtolower( $token );
				if ( ( $src && false !== strpos( $src, $token ) ) || false !== strpos( $lhand, $token ) ) {
					$registry->dequeue( $handle );
					$registry->deregister( $handle );
					break;
				}
			}
		}
	}
}

/* ---------------------------------------------------------------------------
 * 2. Strip inline Ottertext markup from the final HTML.
 *
 * Uses a whole-page output buffer started late on template_redirect and closed
 * on shutdown. Only <script>, <iframe>, <link>, <div> blocks that literally
 * reference an Ottertext token are removed; everything else passes through
 * untouched. Skipped in admin, REST, AJAX, feeds, and for logged-in editors
 * previewing (so it can't interfere with the block editor).
 * ------------------------------------------------------------------------- */
add_action( 'template_redirect', 'g2a_ottertext_start_buffer', 1 );
function g2a_ottertext_start_buffer() {
	if ( ! g2a_ottertext_cleanup_enabled() ) {
		return;
	}
	if ( is_admin() || wp_doing_ajax() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( 'g2a_ottertext_filter_html' );
}

/**
 * Output-buffer callback. Removes Ottertext snippets.
 *
 * @param string $html Full page HTML.
 * @return string
 */
function g2a_ottertext_filter_html( $html ) {
	if ( '' === $html || ! is_string( $html ) ) {
		return $html;
	}
	$tokens = array_map( 'preg_quote', g2a_ottertext_tokens() );
	$alt    = implode( '|', $tokens );
	if ( '' === $alt ) {
		return $html;
	}

	// <script ...ottertext...>...</script>  (with or without src)
	$html = preg_replace( '#<script\b[^>]*>(?:(?!</script>).)*?(?:' . $alt . ')(?:(?!</script>).)*?</script>#is', '', $html );
	// <script src="...ottertext..."></script> self-referencing src only
	$html = preg_replace( '#<script\b[^>]*\b(?:src|data-[a-z-]+)=["\'][^"\']*(?:' . $alt . ')[^"\']*["\'][^>]*>\s*</script>#is', '', $html );
	// <iframe ...ottertext...></iframe>
	$html = preg_replace( '#<iframe\b[^>]*(?:' . $alt . ')[^>]*>.*?</iframe>#is', '', $html );
	// <link ...ottertext...>
	$html = preg_replace( '#<link\b[^>]*(?:' . $alt . ')[^>]*/?>#is', '', $html );

	return $html;
}
