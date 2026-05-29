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
	// Otter Text "Chat Widget" plugin mount point — the remote chatwidget.js
	// (app.ottertext.com) binds the chatbot + age popup to this empty div.
	// Removing it neutralizes the widget even if the div slips through.
	$html = preg_replace( '#<div\b[^>]*id=["\']otterWebsiteChatWidget["\'][^>]*>\s*</div>#is', '', $html );
	// <iframe ...ottertext...></iframe>
	$html = preg_replace( '#<iframe\b[^>]*(?:' . $alt . ')[^>]*>.*?</iframe>#is', '', $html );
	// <link ...ottertext...>
	$html = preg_replace( '#<link\b[^>]*(?:' . $alt . ')[^>]*/?>#is', '', $html );

	return $html;
}

/* ---------------------------------------------------------------------------
 * 3. Purge the orphaned `otter_text_settings` option.
 *
 * The Otter Text "Chat Widget" plugin ships an empty uninstall.php, so deleting
 * the plugin leaves its `otter_text_settings` (your widget ID) behind in
 * wp_options. We remove it automatically — but ONLY once the plugin files are
 * actually gone, so a temporary *deactivation* keeps the saved widget ID intact
 * (reactivating still works). A WP-CLI command is provided to force it.
 * ------------------------------------------------------------------------- */

/**
 * @return bool True if the Otter Text plugin files are still installed.
 */
function g2a_ottertext_plugin_installed() {
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		return true; // can't tell — assume present and leave the option alone.
	}
	return file_exists( WP_PLUGIN_DIR . '/otter-text-chat-widget/otter-text-chat-widget.php' );
}

/**
 * Delete the orphaned option when the plugin is gone.
 *
 * @param bool $force Remove even if the plugin is still installed.
 * @return bool True when an option was actually deleted.
 */
function g2a_ottertext_purge_orphaned_option( $force = false ) {
	// Cheap short-circuit (the option is autoloaded, so this hits cache).
	if ( false === get_option( 'otter_text_settings', false ) ) {
		return false;
	}
	if ( ! $force && g2a_ottertext_plugin_installed() ) {
		return false; // plugin still present — not orphaned.
	}
	delete_option( 'otter_text_settings' );
	return true;
}

// Auto-purge on admin load (admins only). Self-limiting: once the option is
// gone the first check returns early on every subsequent request.
add_action( 'admin_init', function () {
	if ( ! g2a_ottertext_cleanup_enabled() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	g2a_ottertext_purge_orphaned_option();
} );

// WP-CLI:  wp g2a ottertext-cleanup [--force]
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'g2a ottertext-cleanup', function ( $args, $assoc_args ) {
		$force  = isset( $assoc_args['force'] );
		$purged = g2a_ottertext_purge_orphaned_option( $force );
		if ( $purged ) {
			WP_CLI::success( 'Removed orphaned otter_text_settings option.' );
		} elseif ( g2a_ottertext_plugin_installed() && ! $force ) {
			WP_CLI::warning( 'Otter Text plugin is still installed — pass --force to remove the option anyway.' );
		} else {
			WP_CLI::log( 'Nothing to remove: otter_text_settings option not found.' );
		}
	} );
}

