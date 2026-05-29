<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_verifyistic_verify',        array( $this, 'handle_verification' ) );
        add_action( 'wp_ajax_nopriv_verifyistic_verify', array( $this, 'handle_verification' ) );
        add_action( 'wp_ajax_verifyistic_decline',        array( $this, 'handle_decline' ) );
        add_action( 'wp_ajax_nopriv_verifyistic_decline', array( $this, 'handle_decline' ) );
    }

    /**
     * Main verification handler.
     */
    public function handle_verification() {
        check_ajax_referer( 'verifyistic_verify_nonce', 'nonce' );

        // ── Hardening layer ──────────────────────────────────────────────
        $ip = Verifyistic_Security::client_ip();

        // 1. Rate limit (per IP) — blunts automated age-guessing.
        if ( ! Verifyistic_Security::check_rate_limit( $ip ) ) {
            wp_send_json_error( array( 'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'verifyistic' ) ) );
            return;
        }
        // 2. Honeypot — real users never fill this hidden field.
        if ( ! empty( $_POST['vfy_hp'] ) ) {
            Verifyistic_Security::register_attempt( $ip );
            wp_send_json_error( array( 'message' => __( 'Verification could not be completed.', 'verifyistic' ) ) );
            return;
        }
        // 3. Signed timing token — rejects instant bot posts + stale replays.
        if ( ! Verifyistic_Security::verify_form_token( sanitize_text_field( wp_unslash( $_POST['vfy_token'] ?? '' ) ) ) ) {
            Verifyistic_Security::register_attempt( $ip );
            wp_send_json_error( array( 'message' => __( 'Please take a moment to complete the form and try again.', 'verifyistic' ) ) );
            return;
        }

        $mode    = sanitize_text_field( $_POST['mode'] ?? 'dob' );
        $min_age = max( 18, (int) get_option( 'verifyistic_min_age', 21 ) ); // hard floor 18

        $log_data = array(
            'verify_type' => $mode,
            'status'      => 'passed',
            'page_url'    => esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) ),
        );

        switch ( $mode ) {
            case 'dob':
                $result = $this->verify_dob( $min_age, $log_data );
                break;
            case 'yes_no':
                $result = $this->verify_yes_no( $log_data );
                break;
            case 'id_face':
                $result = $this->verify_id_face( $log_data );
                break;
            default:
                wp_send_json_error( array( 'message' => __( 'Invalid verification mode.', 'verifyistic' ) ) );
                return;
        }

        if ( is_wp_error( $result ) ) {
            // Log failed attempt + count it toward the rate limit.
            $log_data['status'] = 'failed';
            $log_id = Verifyistic_DB::insert_log( $log_data );
            Verifyistic_Security::register_attempt( $ip );
            $this->dispatch_webhook( 'failed', $log_data, $log_id );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        // Save to DB
        $log_id = Verifyistic_DB::insert_log( $log_data );

        // Fan out to every enabled webhook connection subscribed to "passed".
        $this->dispatch_webhook( 'passed', $log_data, $log_id );

        wp_send_json_success( array(
            'message'     => __( 'Age verified. Welcome!', 'verifyistic' ),
            'token'       => $log_data['verify_token'] ?? '',
            'cookie_days' => (int) get_option( 'verifyistic_cookie_days', 30 ),
        ) );
    }

    /**
     * Handle Yes/No Decline.
     */
    public function handle_decline() {
        check_ajax_referer( 'verifyistic_verify_nonce', 'nonce' );

        $log_data = array(
            'verify_type' => 'yes_no',
            'status'      => 'declined',
            'page_url'    => esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) ),
        );
        $log_id = Verifyistic_DB::insert_log( $log_data );

        // Declines are dispatched too, so a CRM / complaint layer can log the
        // under-age / opt-out event.
        $this->dispatch_webhook( 'declined', $log_data, $log_id );

        $redirect = get_option( 'verifyistic_redirect_url', '' );
        wp_send_json_success( array( 'redirect' => esc_url( $redirect ) ) );
    }

    // ─── DOB Verification ────────────────────────────────────────────────────
    private function verify_dob( $min_age, &$log_data ) {
        $dob = sanitize_text_field( $_POST['dob'] ?? '' );
        if ( empty( $dob ) ) {
            return new WP_Error( 'dob_required', __( 'Please enter your date of birth.', 'verifyistic' ) );
        }

        $dob_ts = strtotime( $dob );
        if ( ! $dob_ts || $dob_ts > time() ) {
            return new WP_Error( 'dob_invalid', __( 'Please enter a valid date of birth.', 'verifyistic' ) );
        }

        $age = $this->calculate_age( $dob );
        // Sanity ceiling — a DOB implying an impossible age is a typo or a bot.
        if ( $age > 119 ) {
            return new WP_Error( 'dob_invalid', __( 'Please enter a valid date of birth.', 'verifyistic' ) );
        }
        if ( $age < $min_age ) {
            return new WP_Error( 'age_fail', sprintf(
                __( 'You must be at least %d years old to access this site.', 'verifyistic' ),
                $min_age
            ) );
        }

        $log_data['dob']           = date( 'Y-m-d', $dob_ts );
        $log_data['age_at_verify'] = $age;
        $log_data['first_name']    = sanitize_text_field( $_POST['first_name'] ?? '' );
        $log_data['last_name']     = sanitize_text_field( $_POST['last_name'] ?? '' );
        $log_data['verify_token']  = wp_generate_password( 32, false );

        return true;
    }

    // ─── Yes/No Verification ─────────────────────────────────────────────────
    private function verify_yes_no( &$log_data ) {
        $log_data['verify_type']  = 'yes_no';
        $log_data['verify_token'] = wp_generate_password( 32, false );
        return true;
    }

    // ─── ID & Face Verification ──────────────────────────────────────────────
    private function verify_id_face( &$log_data ) {
        if ( empty( $_FILES['id_file'] ) || empty( $_FILES['selfie_file'] ) ) {
            return new WP_Error( 'files_required', __( 'Please upload both your ID and selfie.', 'verifyistic' ) );
        }

        $id_url     = $this->handle_upload( 'id_file' );
        $selfie_url = $this->handle_upload( 'selfie_file' );

        if ( is_wp_error( $id_url ) )     return $id_url;
        if ( is_wp_error( $selfie_url ) ) return $selfie_url;

        $log_data['id_file']       = $id_url;
        $log_data['selfie_file']   = $selfie_url;
        $log_data['first_name']    = sanitize_text_field( $_POST['first_name'] ?? '' );
        $log_data['last_name']     = sanitize_text_field( $_POST['last_name'] ?? '' );
        $log_data['verify_type']   = 'id_face';
        $log_data['verify_token']  = wp_generate_password( 32, false );

        return true;
    }

    // ─── Handle File Upload ──────────────────────────────────────────────────
    private function handle_upload( $field ) {
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'application/pdf' );
        $mime    = $_FILES[ $field ]['type'] ?? '';

        if ( ! in_array( $mime, $allowed, true ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid file type. Please upload JPG, PNG, GIF, or PDF.', 'verifyistic' ) );
        }

        // Override upload directory to keep uploads organized
        add_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );
        $upload = wp_handle_upload( $_FILES[ $field ], array( 'test_form' => false ) );
        remove_filter( 'upload_dir', array( $this, 'custom_upload_dir' ) );

        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'upload_error', $upload['error'] );
        }

        return $upload['url'] ?? '';
    }

    /**
     * Custom upload directory for ID files.
     */
    public function custom_upload_dir( $dirs ) {
        $subdir         = '/verifyistic-ids/' . date( 'Y/m' );
        $dirs['subdir'] = $subdir;
        $dirs['path']   = $dirs['basedir'] . $subdir;
        $dirs['url']    = $dirs['baseurl'] . $subdir;
        return $dirs;
    }

    // ─── Calculate Age ────────────────────────────────────────────────────────
    private function calculate_age( $dob ) {
        try {
            $birthDate = new DateTime( $dob );
            $today     = new DateTime( 'today' );
            return (int) $birthDate->diff( $today )->y;
        } catch ( Exception $e ) {
            return 0;
        }
    }

    // ─── Dispatch Webhooks (multi-connection) ──────────────────────────────────
    /**
     * Build the standard payload and fan it out to every enabled webhook
     * connection subscribed to $event_key (passed|failed|declined).
     *
     * @param string $event_key
     * @param array  $log_data
     * @param int    $log_id
     */
    private function dispatch_webhook( $event_key, $log_data, $log_id ) {
        if ( ! class_exists( 'Verifyistic_Webhooks' ) ) {
            return;
        }

        $type_labels = array(
            'dob'     => 'DOB',
            'yes_no'  => 'YES/NO',
            'id_face' => 'ID & FACE',
        );
        $raw_type = $log_data['verify_type'] ?? '';

        $payload = array(
            'event'       => 'Age Verification',
            'id'          => (int) $log_id,
            'timestamp'   => current_time( 'c' ),
            'site_url'    => get_site_url(),
            'verify_type' => $type_labels[ $raw_type ] ?? strtoupper( $raw_type ),
            'first_name'  => $log_data['first_name'] ?? '',
            'last_name'   => $log_data['last_name'] ?? '',
            'dob'         => $log_data['dob'] ?? '',
            'age'         => $log_data['age_at_verify'] ?? 0,
            'status'      => $log_data['status'] ?? $event_key,
            'verify_token'=> $log_data['verify_token'] ?? '',
            'ip_address'  => $log_data['ip_address'] ?? Verifyistic_Security::client_ip(),
            'page_url'    => $log_data['page_url'] ?? '',
        );

        Verifyistic_Webhooks::dispatch( $event_key, $payload, (int) $log_id );
    }
}
