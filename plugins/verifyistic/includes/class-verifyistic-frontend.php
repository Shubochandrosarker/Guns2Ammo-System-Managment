<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_Frontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer',          array( $this, 'render_popup' ) );
    }

    /**
     * Check if popup should be displayed on current page.
     */
    private function should_show() {
        // Must be enabled
        if ( ! get_option( 'verifyistic_enabled', '1' ) ) return false;

        // Don't show to bots / search crawlers
        if ( $this->is_bot() ) return false;

        // Don't show in admin
        if ( is_admin() ) return false;

        // Already verified — skip rendering the popup entirely so cached HTML
        // doesn't contain it for the verified visitor.
        if ( ! empty( $_COOKIE['verifyistic_verified'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_COOKIE['verifyistic_verified'] ) );
            if ( strlen( $token ) >= 16 && preg_match( '/^[A-Za-z0-9_\-]+$/', $token ) ) {
                return false;
            }
        }

        // Check excluded pages
        $excluded = get_option( 'verifyistic_exclude_pages', '' );
        if ( ! empty( $excluded ) ) {
            $excluded_ids = array_map( 'absint', explode( ',', $excluded ) );
            global $post;
            if ( $post && in_array( (int) $post->ID, $excluded_ids, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect common search engine crawlers. UA-only by design so SEO crawlers
     * see content without the popup. NOTE: a malicious user can spoof their
     * UA to bypass the popup — the AJAX endpoint independently enforces the
     * verification, so spoofing only hides the popup, not the gate. Disable
     * via option verifyistic_skip_bot_detection = 1 if that tradeoff is not
     * acceptable for your compliance posture.
     */
    private function is_bot() {
        if ( 1 === (int) get_option( 'verifyistic_skip_bot_detection', 0 ) ) {
            return false;
        }
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
        if ( '' === $user_agent ) return false;
        $bots = array( 'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp', 'applebot', 'semrushbot', 'ahrefs', 'mj12bot' );
        foreach ( $bots as $bot ) {
            if ( strpos( $user_agent, $bot ) !== false ) return true;
        }
        return false;
    }

    /**
     * Enqueue frontend scripts and styles.
     */
    public function enqueue_assets() {
        if ( ! $this->should_show() ) return;

        wp_enqueue_style(
            'verifyistic-frontend',
            VERIFYISTIC_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            VERIFYISTIC_VERSION
        );

        wp_enqueue_script(
            'verifyistic-frontend',
            VERIFYISTIC_PLUGIN_URL . 'assets/js/frontend.js',
            array( 'jquery' ),
            VERIFYISTIC_VERSION,
            true
        );

        $min_age     = (int) get_option( 'verifyistic_min_age', 21 );
        $mode        = get_option( 'verifyistic_mode', 'dob' );
        $id_mode     = get_option( 'verifyistic_id_verification', '0' );
        $remember_me = get_option( 'verifyistic_remember_me', '1' );
        $cookie_days = (int) get_option( 'verifyistic_cookie_days', 30 );
        $redirect    = get_option( 'verifyistic_redirect_url', '' );

        // If ID verification is toggled on, override mode
        if ( $id_mode ) {
            $mode = 'id_face';
        }

        wp_localize_script( 'verifyistic-frontend', 'verifyisticData', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'verifyistic_verify_nonce' ),
            'minAge'      => $min_age,
            'mode'        => $mode,
            'rememberMe'  => $remember_me,
            'cookieDays'  => $cookie_days,
            'cookieDomain' => (string) get_option( 'verifyistic_cookie_domain', '' ),
            'redirectUrl' => esc_url( $redirect ),
            'strings'     => array(
                'ageError'    => sprintf( __( 'You must be at least %d years old to access this site.', 'verifyistic' ), $min_age ),
                'dobRequired' => __( 'Please enter your date of birth.', 'verifyistic' ),
                'dobInvalid'  => __( 'Please enter a valid date of birth.', 'verifyistic' ),
                'idRequired'  => __( 'Please upload your ID document.', 'verifyistic' ),
                'selfieReq'   => __( 'Please upload a selfie with your ID.', 'verifyistic' ),
                'verifying'   => __( 'Verifying…', 'verifyistic' ),
            ),
        ) );

        // Output dynamic CSS from settings
        $this->output_dynamic_css();
    }

    /**
     * Output inline CSS with admin-configured values.
     *
     * Color defaults below are var(--color-*) references into the theme's
     * own design tokens (guns2ammo/assets/css/tokens.css), not literal hex
     * — this file's own `:root` block already declared that intent
     * (--vfy-popup-bg: var(--color-gunmetal) etc.) but every one of those
     * properties was unconditionally re-declared here with a fixed hex
     * fallback ('#0f172a' popup, '#14b8a6' teal accent/yes-button, …),
     * which — having equal ':root' specificity and loading after
     * frontend.css via wp_add_inline_style() — always won the cascade.
     * Net effect: the popup never followed the site's light/dark toggle at
     * all, and (independently) white text on the default #14b8a6 button
     * fill was ~2.5:1, failing WCAG AA. Mirrors the same var()-aware
     * default already used by g2a-booking-engine's get_design_tokens().
     */
    private function output_dynamic_css() {
        // Re-validate every stored value at output time: options are
        // sanitized on save, but legacy/imported values bypass that, and an
        // unvalidated string here breaks out of the CSS context.
        $popup_bg     = $this->sanitize_color_or_var( get_option( 'verifyistic_popup_bg_color', 'var(--color-gunmetal)' ), '#26252C' );
        $overlay_clr  = sanitize_hex_color( get_option( 'verifyistic_overlay_color', '#000000' ) ) ?: '#000000';
        $opacity      = min( 100, max( 0, (int) get_option( 'verifyistic_overlay_opacity', 80 ) ) );
        $font_clr     = $this->sanitize_color_or_var( get_option( 'verifyistic_font_color', 'var(--color-white)' ), '#F7F7F9' );
        $btn_yes      = $this->sanitize_color_or_var( get_option( 'verifyistic_btn_yes_color', 'var(--color-brass)' ), '#C9A84C' );
        $btn_no       = $this->sanitize_color_or_var( get_option( 'verifyistic_btn_no_color', 'var(--color-steel)' ), '#38363F' );
        // var(--color-ink) is the theme's dedicated "text placed ON an
        // accent fill" token (dark in both modes, since brass/ember stay
        // mid-tone rather than inverting) — the fixed '#ffffff' default
        // this replaced gave ~2.5:1 white-on-teal, failing WCAG AA 4.5:1.
        $btn_yes_txt  = $this->sanitize_color_or_var( get_option( 'verifyistic_btn_yes_text_color', 'var(--color-ink)' ), '#111114' );
        $btn_no_txt   = $this->sanitize_color_or_var( get_option( 'verifyistic_btn_no_text_color', 'var(--color-white)' ), '#F7F7F9' );
        $btn_style    = get_option( 'verifyistic_btn_style', 'rounded' );
        $popup_width  = min( 2000, max( 200, (int) get_option( 'verifyistic_popup_width', 480 ) ) );
        $logo_width   = min( 1000, max( 40, (int) get_option( 'verifyistic_logo_max_width', 160 ) ) );
        // --color-brass-bright, not --color-brass: --vfy-accent is used as
        // actual TEXT color in a few spots (.vfy-age-badge, the upload
        // dropzone prompt/filename), and plain --color-brass only reaches
        // ~3.5-3.8:1 there in light mode (fails WCAG AA); brass-bright
        // clears ~4.6-5:1 in light mode and stays comfortably passing (and
        // is if anything more vivid) in dark mode.
        $accent       = $this->sanitize_color_or_var( get_option( 'verifyistic_accent_color', 'var(--color-brass-bright)' ), '#DCB45F' );

        $overlay_rgba = $this->hex_to_rgba( $overlay_clr, $opacity / 100 );

        $border_radius = '8px';
        $btn_radius    = '8px';
        if ( $btn_style === 'pill' )    { $btn_radius = '50px'; }
        if ( $btn_style === 'sharp' )   { $btn_radius = '0px'; }
        if ( $btn_style === 'rounded' ) { $btn_radius = '8px'; }

        $css = "
        :root {
            --vfy-popup-bg: {$popup_bg};
            --vfy-overlay:  {$overlay_rgba};
            --vfy-font-clr: {$font_clr};
            --vfy-btn-yes:  {$btn_yes};
            --vfy-btn-no:   {$btn_no};
            --vfy-btn-yes-txt: {$btn_yes_txt};
            --vfy-btn-no-txt:  {$btn_no_txt};
            --vfy-btn-radius:  {$btn_radius};
            --vfy-popup-width: {$popup_width}px;
            --vfy-logo-width:  {$logo_width}px;
            --vfy-accent:      {$accent};
        }";

        // Custom CSS — strip anything that could escape the style context.
        $custom = (string) get_option( 'verifyistic_custom_css', '' );
        if ( $custom ) {
            $custom = wp_strip_all_tags( $custom );
            $custom = str_replace( array( '</', '<', 'expression(' ), '', $custom );
            $css   .= "\n/* Verifyistic Custom CSS */\n" . $custom;
        }

        wp_add_inline_style( 'verifyistic-frontend', $css );
    }

    /**
     * Validate a color option that may be a literal color OR a reference
     * into the theme's own CSS custom properties (so an out-of-the-box,
     * never-customized popup still follows the site's light/dark toggle
     * instead of being pinned to a fixed color forever). Mirrors
     * G2AB_Frontend::sanitize_color()'s allow-list so this can never become
     * a CSS-injection vector: strict hex, strict rgb()/rgba(), or a bare
     * var(--name) / var(--name, #hex|keyword) reference. Anything else
     * (including a stray unclosed var() or embedded `;`/`}` that could
     * break out of the :root block) falls through to $fallback_hex.
     */
    private function sanitize_color_or_var( $value, $fallback_hex ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
            return $value;
        }
        if ( preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(\s*,\s*[\d.]+)?\s*\)$/', $value ) ) {
            return $value;
        }
        if ( preg_match( '/^var\(--[a-zA-Z0-9-]+(?:,\s*(?:#[0-9a-fA-F]{3,8}|[a-zA-Z]+))?\)$/', $value ) ) {
            return $value;
        }
        return $fallback_hex;
    }

    /**
     * Convert hex color + alpha to rgba string.
     */
    private function hex_to_rgba( $hex, $alpha = 1 ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        return "rgba({$r},{$g},{$b},{$alpha})";
    }

    /**
     * Render the popup HTML in footer.
     */
    public function render_popup() {
        if ( ! $this->should_show() ) return;
        require VERIFYISTIC_PLUGIN_DIR . 'templates/frontend/popup.php';
    }
}
