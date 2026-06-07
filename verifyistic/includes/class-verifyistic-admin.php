<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_Admin {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_verifyistic_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_verifyistic_delete_log',    array( $this, 'ajax_delete_log' ) );
        add_action( 'wp_ajax_verifyistic_clear_logs',    array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_verifyistic_export_csv',    array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_verifyistic_test_webhook',  array( $this, 'ajax_test_webhook' ) );
        add_action( 'wp_ajax_verifyistic_protected_file', array( $this, 'serve_protected_file' ) );
    }

    /**
     * Build a one-shot signed URL that an admin can paste into an <img> / <a>
     * to view a stored private ID/selfie. Bound to user, expires in 5 min.
     *
     * @param string $key The 'private:rel/path.jpg' value from the DB.
     * @return string
     */
    public static function build_protected_url( $key ) {
        $key = (string) $key;
        if ( 0 !== strpos( $key, 'private:' ) ) {
            return '';
        }
        $path  = substr( $key, 8 );
        $exp   = time() + 5 * MINUTE_IN_SECONDS;
        $uid   = get_current_user_id();
        $sig   = hash_hmac( 'sha256', $path . '|' . $exp . '|' . $uid, wp_salt( 'auth' ) );
        return add_query_arg(
            array(
                'action' => 'verifyistic_protected_file',
                'k'      => rawurlencode( $path ),
                'e'      => $exp,
                's'      => $sig,
            ),
            admin_url( 'admin-ajax.php' )
        );
    }

    /**
     * Stream a private file to a signed, capability-gated admin request.
     */
    public function serve_protected_file() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            status_header( 403 );
            exit;
        }
        $k   = isset( $_GET['k'] ) ? rawurldecode( wp_unslash( $_GET['k'] ) ) : '';
        $exp = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0;
        $sig = isset( $_GET['s'] ) ? (string) wp_unslash( $_GET['s'] ) : '';

        if ( '' === $k || $exp < time() || '' === $sig ) {
            status_header( 403 ); exit;
        }
        // Path-traversal guard.
        if ( false !== strpos( $k, '..' ) || 0 === strpos( $k, '/' ) ) {
            status_header( 400 ); exit;
        }
        $expected = hash_hmac( 'sha256', $k . '|' . $exp . '|' . get_current_user_id(), wp_salt( 'auth' ) );
        if ( ! hash_equals( $expected, $sig ) ) {
            status_header( 403 ); exit;
        }

        $uploads = wp_upload_dir();
        $abs     = realpath( trailingslashit( $uploads['basedir'] ) . $k );
        $root    = realpath( trailingslashit( $uploads['basedir'] ) . 'private/verifyistic-ids' );
        if ( ! $abs || ! $root || 0 !== strpos( $abs, $root ) || ! is_file( $abs ) ) {
            status_header( 404 ); exit;
        }

        $check = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $abs ) : array( 'type' => 'application/octet-stream' );
        $mime  = $check['type'] ?: 'application/octet-stream';

        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $abs ) );
        header( 'Content-Disposition: inline; filename="' . basename( $abs ) . '"' );
        readfile( $abs );
        exit;
    }

    // ─── Admin Menus ─────────────────────────────────────────────────────────
    public function register_menus() {
        $svg_icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>' );

        add_menu_page(
            __( 'Verifyistic', 'verifyistic' ),
            __( 'Verifyistic', 'verifyistic' ),
            'manage_options',
            'verifyistic',
            array( $this, 'page_dashboard' ),
            $svg_icon,
            56
        );

        add_submenu_page(
            'verifyistic',
            __( 'Dashboard', 'verifyistic' ),
            __( 'Dashboard', 'verifyistic' ),
            'manage_options',
            'verifyistic',
            array( $this, 'page_dashboard' )
        );

        add_submenu_page(
            'verifyistic',
            __( 'Settings', 'verifyistic' ),
            __( 'Settings', 'verifyistic' ),
            'manage_options',
            'verifyistic-settings',
            array( $this, 'page_settings' )
        );

        add_submenu_page(
            'verifyistic',
            __( 'Verification Logs', 'verifyistic' ),
            __( 'Verification Logs', 'verifyistic' ),
            'manage_options',
            'verifyistic-logs',
            array( $this, 'page_logs' )
        );
    }

    // ─── Enqueue Assets ──────────────────────────────────────────────────────
    public function enqueue_assets( $hook ) {
        $pages = array( 'toplevel_page_verifyistic', 'verifyistic_page_verifyistic-settings', 'verifyistic_page_verifyistic-logs' );
        if ( ! in_array( $hook, $pages, true ) ) return;

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_media();

        wp_enqueue_style(
            'verifyistic-admin',
            VERIFYISTIC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            VERIFYISTIC_VERSION
        );

        wp_enqueue_script(
            'verifyistic-admin',
            VERIFYISTIC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'wp-color-picker' ),
            VERIFYISTIC_VERSION,
            true
        );

        wp_localize_script( 'verifyistic-admin', 'verifyisticAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'verifyistic_admin_nonce' ),
            'strings' => array(
                'saved'          => __( 'Settings saved!', 'verifyistic' ),
                'error'          => __( 'Something went wrong. Please try again.', 'verifyistic' ),
                'confirmClear'   => __( 'Are you sure you want to delete ALL verification logs? This cannot be undone.', 'verifyistic' ),
                'confirmDelete'  => __( 'Delete this log entry?', 'verifyistic' ),
                'webhookSuccess' => __( 'Webhook test sent successfully!', 'verifyistic' ),
                'webhookFail'    => __( 'Webhook test failed. Check your URL.', 'verifyistic' ),
                'selectImage'    => __( 'Select Logo', 'verifyistic' ),
                'useImage'       => __( 'Use This Image', 'verifyistic' ),
            ),
        ) );
    }

    // ─── Settings Registration ────────────────────────────────────────────────
    public function register_settings() {
        $options = array(
            'verifyistic_enabled', 'verifyistic_min_age', 'verifyistic_mode',
            'verifyistic_id_verification', 'verifyistic_logo', 'verifyistic_logo_max_width',
            'verifyistic_heading_text', 'verifyistic_message_text',
            'verifyistic_btn_yes_text', 'verifyistic_btn_no_text',
            'verifyistic_btn_yes_color', 'verifyistic_btn_no_color',
            'verifyistic_btn_yes_text_color', 'verifyistic_btn_no_text_color',
            'verifyistic_btn_style', 'verifyistic_font_color', 'verifyistic_popup_bg_color',
            'verifyistic_overlay_color', 'verifyistic_overlay_opacity', 'verifyistic_popup_width',
            'verifyistic_accent_color', 'verifyistic_remember_me', 'verifyistic_cookie_days',
            'verifyistic_webhook_url', 'verifyistic_webhook_enabled', 'verifyistic_webhook_secret',
            'verifyistic_exclude_pages', 'verifyistic_redirect_url', 'verifyistic_custom_css',
            'verifyistic_privacy_text', 'verifyistic_id_upload_label', 'verifyistic_selfie_label',
            'verifyistic_cookie_domain', 'verifyistic_strict_mode', 'verifyistic_skip_bot_detection',
        );
        foreach ( $options as $option ) {
            register_setting( 'verifyistic_settings', $option, array( 'sanitize_callback' => 'sanitize_text_field' ) );
        }
        register_setting( 'verifyistic_settings', 'verifyistic_custom_css', array( 'sanitize_callback' => 'wp_strip_all_tags' ) );
        register_setting( 'verifyistic_settings', 'verifyistic_message_text', array( 'sanitize_callback' => 'wp_kses_post' ) );
        register_setting( 'verifyistic_settings', 'verifyistic_privacy_text', array( 'sanitize_callback' => 'wp_kses_post' ) );
        register_setting( 'verifyistic_settings', 'verifyistic_cookie_domain', array( 'sanitize_callback' => array( $this, 'sanitize_cookie_domain' ) ) );
        register_setting( 'verifyistic_settings', 'verifyistic_strict_mode', array( 'sanitize_callback' => 'intval' ) );
        register_setting( 'verifyistic_settings', 'verifyistic_skip_bot_detection', array( 'sanitize_callback' => 'intval' ) );
    }

    /**
     * Sanitize a cookie Domain attribute. Allows an optional leading dot,
     * followed by valid host characters. Returns empty string on invalid input.
     */
    public function sanitize_cookie_domain( $value ) {
        $value = sanitize_text_field( (string) $value );
        $value = trim( $value );
        if ( '' === $value ) {
            return '';
        }
        // Allow optional leading dot, then label.label characters.
        if ( ! preg_match( '/^\.?[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]*[A-Za-z0-9])?)+$/', $value ) ) {
            return '';
        }
        return $value;
    }

    // ─── Page: Dashboard ─────────────────────────────────────────────────────
    public function page_dashboard() {
        require_once VERIFYISTIC_PLUGIN_DIR . 'templates/admin/page-dashboard.php';
    }

    // ─── Page: Settings ──────────────────────────────────────────────────────
    public function page_settings() {
        if ( isset( $_POST['verifyistic_save_settings'] ) && check_admin_referer( 'verifyistic_save', 'verifyistic_nonce' ) ) {
            $this->save_settings();
            add_settings_error( 'verifyistic', 'saved', __( 'Settings saved successfully.', 'verifyistic' ), 'updated' );
        }
        require_once VERIFYISTIC_PLUGIN_DIR . 'templates/admin/page-settings.php';
    }

    // ─── Page: Logs ──────────────────────────────────────────────────────────
    public function page_logs() {
        // Handle CSV export
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'export_csv' && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'verifyistic_export' ) ) {
            $this->export_csv();
        }
        require_once VERIFYISTIC_PLUGIN_DIR . 'templates/admin/page-logs.php';
    }

    // ─── Save Settings ────────────────────────────────────────────────────────
    private function save_settings() {
        $fields = array(
            'verifyistic_enabled'           => 'intval',
            'verifyistic_min_age'           => 'absint',
            'verifyistic_mode'              => 'sanitize_text_field',
            'verifyistic_id_verification'   => 'intval',
            'verifyistic_logo'              => 'esc_url_raw',
            'verifyistic_logo_max_width'    => 'absint',
            'verifyistic_heading_text'      => 'sanitize_text_field',
            'verifyistic_message_text'      => 'wp_kses_post',
            'verifyistic_btn_yes_text'      => 'sanitize_text_field',
            'verifyistic_btn_no_text'       => 'sanitize_text_field',
            'verifyistic_btn_yes_color'     => 'sanitize_hex_color',
            'verifyistic_btn_no_color'      => 'sanitize_hex_color',
            'verifyistic_btn_yes_text_color'=> 'sanitize_hex_color',
            'verifyistic_btn_no_text_color' => 'sanitize_hex_color',
            'verifyistic_btn_style'         => 'sanitize_text_field',
            'verifyistic_font_color'        => 'sanitize_hex_color',
            'verifyistic_popup_bg_color'    => 'sanitize_hex_color',
            'verifyistic_overlay_color'     => 'sanitize_hex_color',
            'verifyistic_overlay_opacity'   => 'absint',
            'verifyistic_popup_width'       => 'absint',
            'verifyistic_accent_color'      => 'sanitize_hex_color',
            'verifyistic_remember_me'       => 'intval',
            'verifyistic_cookie_days'       => 'absint',
            'verifyistic_cookie_domain'     => array( $this, 'sanitize_cookie_domain' ),
            'verifyistic_webhook_url'       => 'esc_url_raw',
            'verifyistic_webhook_enabled'   => 'intval',
            'verifyistic_webhook_secret'    => 'sanitize_text_field',
            'verifyistic_exclude_pages'     => 'sanitize_text_field',
            'verifyistic_redirect_url'      => 'esc_url_raw',
            'verifyistic_privacy_text'      => 'wp_kses_post',
            'verifyistic_id_upload_label'   => 'sanitize_text_field',
            'verifyistic_selfie_label'      => 'sanitize_text_field',
            'verifyistic_strict_mode'       => 'intval',
            'verifyistic_skip_bot_detection'=> 'intval',
        );

        foreach ( $fields as $key => $sanitize ) {
            $value = isset( $_POST[ $key ] ) ? $_POST[ $key ] : '';
            // Checkboxes
            if ( in_array( $key, array( 'verifyistic_enabled', 'verifyistic_id_verification', 'verifyistic_webhook_enabled', 'verifyistic_remember_me', 'verifyistic_strict_mode', 'verifyistic_skip_bot_detection' ), true ) ) {
                $value = isset( $_POST[ $key ] ) ? 1 : 0;
            }
            update_option( $key, call_user_func( $sanitize, $value ) );
        }

        // Custom CSS separately
        if ( isset( $_POST['verifyistic_custom_css'] ) ) {
            update_option( 'verifyistic_custom_css', wp_strip_all_tags( wp_unslash( $_POST['verifyistic_custom_css'] ) ) );
        }

        // Enforce min age floor
        $min_age = (int) get_option( 'verifyistic_min_age', 18 );
        if ( $min_age < 18 ) {
            update_option( 'verifyistic_min_age', 18 );
        }

        // Multi-webhook connections.
        $this->save_webhook_connections();

        // Mirror the legacy single-webhook fields into the connections list so
        // toggling the legacy "Enable Webhook" checkbox is not dead post-migration.
        if ( class_exists( 'Verifyistic_Webhooks' ) ) {
            $url     = (string) get_option( 'verifyistic_webhook_url', '' );
            $enabled = (int) get_option( 'verifyistic_webhook_enabled', 0 );
            if ( '' !== $url ) {
                $conns   = Verifyistic_Webhooks::get_connections();
                $matched = false;
                foreach ( $conns as $i => $c ) {
                    if ( isset( $c['url'] ) && $c['url'] === $url ) {
                        $conns[ $i ]['enabled'] = $enabled ? 1 : 0;
                        $matched = true;
                        break;
                    }
                }
                if ( ! $matched ) {
                    $conns[] = array(
                        'id'       => 'wh_' . substr( md5( $url . microtime( true ) . wp_rand() ), 0, 12 ),
                        'name'     => __( 'Legacy', 'verifyistic' ),
                        'url'      => $url,
                        'secret'   => (string) get_option( 'verifyistic_webhook_secret', '' ),
                        'platform' => 'legacy',
                        'enabled'  => $enabled ? 1 : 0,
                        'events'   => array( 'passed', 'failed', 'declined' ),
                    );
                }
                Verifyistic_Webhooks::save_connections( $conns );
            }
        }
    }

    // ─── Save multi-webhook connections ────────────────────────────────────────
    private function save_webhook_connections() {
        if ( ! isset( $_POST['vfy_conn_url'] ) || ! is_array( $_POST['vfy_conn_url'] ) ) {
            return;
        }
        $urls     = wp_unslash( $_POST['vfy_conn_url'] );
        $names    = isset( $_POST['vfy_conn_name'] ) ? wp_unslash( $_POST['vfy_conn_name'] ) : array();
        $secrets  = isset( $_POST['vfy_conn_secret'] ) ? wp_unslash( $_POST['vfy_conn_secret'] ) : array();
        $enabled  = isset( $_POST['vfy_conn_enabled'] ) ? wp_unslash( $_POST['vfy_conn_enabled'] ) : array();
        $events   = isset( $_POST['vfy_conn_events'] ) ? wp_unslash( $_POST['vfy_conn_events'] ) : array();
        $platform = isset( $_POST['vfy_conn_platform'] ) ? wp_unslash( $_POST['vfy_conn_platform'] ) : array();
        $ids      = isset( $_POST['vfy_conn_id'] ) ? wp_unslash( $_POST['vfy_conn_id'] ) : array();

        $rows = array();
        foreach ( $urls as $i => $url ) {
            if ( '' === trim( (string) $url ) ) {
                continue; // skip blank rows
            }
            $rows[] = array(
                'id'       => $ids[ $i ] ?? '',
                'name'     => $names[ $i ] ?? '',
                'url'      => $url,
                'secret'   => $secrets[ $i ] ?? '',
                'platform' => $platform[ $i ] ?? '',
                'enabled'  => ! empty( $enabled[ $i ] ) && '0' !== (string) $enabled[ $i ] ? 1 : 0,
                'events'   => $events[ $i ] ?? '',
            );
        }
        Verifyistic_Webhooks::save_connections( $rows );
    }

    // ─── AJAX: Save Settings ─────────────────────────────────────────────────
    public function ajax_save_settings() {
        check_ajax_referer( 'verifyistic_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        $this->save_settings();
        wp_send_json_success( array( 'message' => __( 'Settings saved!', 'verifyistic' ) ) );
    }

    // ─── AJAX: Delete Log ────────────────────────────────────────────────────
    public function ajax_delete_log() {
        check_ajax_referer( 'verifyistic_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        $id = absint( $_POST['log_id'] ?? 0 );
        if ( $id ) {
            Verifyistic_DB::delete_log( $id );
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    // ─── AJAX: Clear All Logs ────────────────────────────────────────────────
    public function ajax_clear_logs() {
        check_ajax_referer( 'verifyistic_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        Verifyistic_DB::delete_all_logs();
        wp_send_json_success();
    }

    // ─── AJAX: Export CSV ────────────────────────────────────────────────────
    public function ajax_export_csv() {
        check_ajax_referer( 'verifyistic_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );
        $csv = Verifyistic_DB::export_csv();
        wp_send_json_success( array( 'csv' => $csv ) );
    }

    // ─── AJAX: Test Webhook ──────────────────────────────────────────────────
    public function ajax_test_webhook() {
        check_ajax_referer( 'verifyistic_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $url    = sanitize_url( $_POST['webhook_url'] ?? '' );
        $secret = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : null;
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => 'No webhook URL provided.' ) );
        }

        $payload = array(
            'event'        => 'verifyistic.test',
            'timestamp'    => current_time( 'c' ),
            'site_url'     => get_site_url(),
            'verify_type'  => 'test',
            'status'       => 'passed',
            'age'          => 21,
        );

        $result = Verifyistic_Webhook::send( $url, $payload, $secret );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => __( 'Webhook test sent!', 'verifyistic' ) ) );
    }

    // ─── Export CSV (direct download) ────────────────────────────────────────
    private function export_csv() {
        $csv = Verifyistic_DB::export_csv();
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="verifyistic-logs-' . current_time( 'Y-m-d' ) . '.csv"' );
        echo $csv; // phpcs:ignore
        exit;
    }
}
