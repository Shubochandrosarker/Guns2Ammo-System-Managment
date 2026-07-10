<?php
/**
 * Plugin Name: Verifyistic - Advanced Age Verification
 * Plugin URI:  https://wordpressistic.com/verifyistic
 * Description: Advanced age verification system for firearms, alcohol, cannabis, and adult websites. Modern popup with DOB, Yes/No, and ID & Face verification modes.
 * Version:     1.4.1
 * Author:      WordPressistic
 * Author URI:  https://wordpressistic.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: verifyistic
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'VERIFYISTIC_VERSION',       '1.4.1' );
define( 'VERIFYISTIC_PLUGIN_FILE',   __FILE__ );
define( 'VERIFYISTIC_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'VERIFYISTIC_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'VERIFYISTIC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ─── Includes ─────────────────────────────────────────────────────────────────
require_once VERIFYISTIC_PLUGIN_DIR . 'includes/class-verifyistic-db.php';
require_once VERIFYISTIC_PLUGIN_DIR . 'includes/class-verifyistic-security.php';
require_once VERIFYISTIC_PLUGIN_DIR . 'includes/class-verifyistic-webhook.php';
require_once VERIFYISTIC_PLUGIN_DIR . 'includes/class-verifyistic-webhooks.php';
require_once VERIFYISTIC_PLUGIN_DIR . 'includes/class-verifyistic-admin.php';
require_once VERIFYISTIC_PLUGIN_DIR . 'includes/class-verifyistic-frontend.php';
require_once VERIFYISTIC_PLUGIN_DIR . 'includes/class-verifyistic-ajax.php';

// ─── Activation & Deactivation ───────────────────────────────────────────────
register_activation_hook( __FILE__, 'verifyistic_activate' );
function verifyistic_activate() {
    Verifyistic_DB::create_tables();
    Verifyistic_Webhooks::create_table();
    verifyistic_set_defaults();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'verifyistic_deactivate' );
function verifyistic_deactivate() {
    flush_rewrite_rules();
}

// ─── Default Options ─────────────────────────────────────────────────────────
function verifyistic_set_defaults() {
    $defaults = array(
        'verifyistic_enabled'           => '1',
        'verifyistic_min_age'           => '21',
        'verifyistic_mode'              => 'dob',
        'verifyistic_id_verification'   => '0',
        'verifyistic_logo'              => '',
        'verifyistic_logo_max_width'    => '160',
        'verifyistic_heading_text'      => 'Age Verification Required',
        'verifyistic_message_text'      => 'You must be 21 years of age or older to enter this website. Please verify your age to continue.',
        'verifyistic_btn_yes_text'      => 'Yes, I am 21+',
        'verifyistic_btn_no_text'       => 'No, Exit',
        'verifyistic_btn_yes_color'     => '#14b8a6',
        'verifyistic_btn_no_color'      => '#475569',
        'verifyistic_btn_yes_text_color'=> '#ffffff',
        'verifyistic_btn_no_text_color' => '#ffffff',
        'verifyistic_btn_style'         => 'rounded',
        'verifyistic_font_color'        => '#f8fafc',
        'verifyistic_popup_bg_color'    => '#0f172a',
        'verifyistic_overlay_color'     => '#000000',
        'verifyistic_overlay_opacity'   => '80',
        'verifyistic_popup_width'       => '480',
        'verifyistic_accent_color'      => '#14b8a6',
        'verifyistic_remember_me'       => '1',
        'verifyistic_cookie_days'       => '30',
        'verifyistic_webhook_url'       => '',
        'verifyistic_webhook_enabled'   => '0',
        'verifyistic_webhook_secret'    => '',
        'verifyistic_exclude_pages'     => '',
        'verifyistic_redirect_url'      => '',
        'verifyistic_custom_css'        => '',
        'verifyistic_privacy_text'      => 'By verifying your age, you agree to our <a href="/privacy-policy">Privacy Policy</a> and Terms of Use.',
        'verifyistic_id_upload_label'   => 'Upload a valid government-issued ID',
        'verifyistic_selfie_label'      => 'Upload a selfie holding your ID',
    );
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value );
        }
    }
}

// ─── Plugin Action Links ──────────────────────────────────────────────────────
add_filter( 'plugin_action_links_' . VERIFYISTIC_PLUGIN_BASENAME, 'verifyistic_action_links' );
function verifyistic_action_links( $links ) {
    $custom = array(
        '<a href="' . admin_url( 'admin.php?page=verifyistic-settings' ) . '">' . __( 'Settings', 'verifyistic' ) . '</a>',
        '<a href="' . admin_url( 'admin.php?page=verifyistic' ) . '">' . __( 'Dashboard', 'verifyistic' ) . '</a>',
    );
    return array_merge( $custom, $links );
}

// ─── Boot ────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'verifyistic_init' );
function verifyistic_init() {
    load_plugin_textdomain( 'verifyistic', false, dirname( VERIFYISTIC_PLUGIN_BASENAME ) . '/languages' );

    // Multi-webhook delivery retry handler + ensure schema exists for sites
    // that updated without re-activating.
    Verifyistic_Webhooks::init();
    if ( version_compare( (string) get_option( 'verifyistic_db_version', '0' ), VERIFYISTIC_VERSION, '<' ) ) {
        Verifyistic_DB::create_tables();
        Verifyistic_Webhooks::create_table();
        update_option( 'verifyistic_db_version', VERIFYISTIC_VERSION );
    }

    if ( is_admin() ) {
        new Verifyistic_Admin();
    }
    new Verifyistic_Frontend();
    new Verifyistic_Ajax();
}
