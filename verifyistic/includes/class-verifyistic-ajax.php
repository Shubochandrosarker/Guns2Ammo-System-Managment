<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_verifyistic_verify',        array( $this, 'handle_verification' ) );
        add_action( 'wp_ajax_nopriv_verifyistic_verify', array( $this, 'handle_verification' ) );
        add_action( 'wp_ajax_verifyistic_decline',        array( $this, 'handle_decline' ) );
        add_action( 'wp_ajax_nopriv_verifyistic_decline', array( $this, 'handle_decline' ) );
        // Fresh-token endpoint: bypasses page caching by minting tokens at
        // request time instead of at HTML render time.
        add_action( 'wp_ajax_verifyistic_token',        array( $this, 'handle_token' ) );
        add_action( 'wp_ajax_nopriv_verifyistic_token', array( $this, 'handle_token' ) );
    }

    /**
     * Issue a fresh form token. Called by the popup JS on show + after each
     * failed submission so users behind page caches (WP Rocket, LiteSpeed,
     * Cloudflare, etc.) and users retrying after a typo aren't blocked by a
     * stale or already-burned token baked into cached HTML.
     */
    public function handle_token() {
        check_ajax_referer( 'verifyistic_verify_nonce', 'nonce' );
        wp_send_json_success( array(
            'token' => Verifyistic_Security::issue_form_token(),
        ) );
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
        // The jti is NOT burned here; only after the full verification
        // succeeds (below), so a user who corrects a DOB typo can retry
        // without hitting a false "complete the form" error.
        $token_jti = Verifyistic_Security::verify_form_token( sanitize_text_field( wp_unslash( $_POST['vfy_token'] ?? '' ) ) );
        if ( false === $token_jti ) {
            Verifyistic_Security::register_attempt( $ip );
            wp_send_json_error( array( 'message' => __( 'Please take a moment to complete the form and try again.', 'verifyistic' ) ) );
            return;
        }

        $mode    = sanitize_text_field( $_POST['mode'] ?? 'dob' );
        $min_age = max( 18, (int) get_option( 'verifyistic_min_age', 21 ) ); // hard floor 18

        // COMPLIANCE: when strict mode is enabled (option verifyistic_strict_mode = 1
        // or constant VERIFYISTIC_STRICT_MODE), refuse the click-Yes/click-No path.
        // Only DOB or ID+selfie carry any evidentiary weight for an FFL/firearms
        // site. yes_no is documented as UX-only.
        $strict = ( defined( 'VERIFYISTIC_STRICT_MODE' ) && VERIFYISTIC_STRICT_MODE )
            || 1 === (int) get_option( 'verifyistic_strict_mode', 0 );
        if ( $strict && 'yes_no' === $mode ) {
            wp_send_json_error( array( 'message' => __( 'This site requires date-of-birth verification.', 'verifyistic' ) ) );
            return;
        }

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

        // Verification fully passed — now burn the token's jti so it can't
        // be replayed by a bot. Failed attempts (above) intentionally do
        // NOT burn, so honest users can retry after a typo.
        Verifyistic_Security::burn_form_token( $token_jti );

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

        $redirect = (string) get_option( 'verifyistic_redirect_url', '' );
        // SECURITY: validate against site allow-list so a hostile option
        // value can't drive an open redirect.
        $safe = $redirect ? wp_validate_redirect( $redirect, '' ) : '';
        wp_send_json_success( array( 'redirect' => esc_url( $safe ) ) );
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
    // SECURITY: PII files (driver licenses, selfies) are stored OUTSIDE the
    // web-readable uploads tree (wp-content/uploads/private/verifyistic-ids/).
    // We also drop a deny-all .htaccess as belt-and-suspenders. The DB stores
    // an opaque file key; admins download via a signed admin-only endpoint
    // (see Verifyistic_Admin::serve_protected_file()).
    private function handle_upload( $field ) {
        if ( ! function_exists( 'wp_handle_upload' ) && ! function_exists( 'wp_check_filetype_and_ext' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ( empty( $_FILES[ $field ]['tmp_name'] ) || ! is_uploaded_file( $_FILES[ $field ]['tmp_name'] ) ) {
            return new WP_Error( 'no_file', __( 'No file uploaded.', 'verifyistic' ) );
        }

        // Server-side MIME sniff (do NOT trust $_FILES['type'] from the browser).
        $tmp_name = $_FILES[ $field ]['tmp_name'];
        $orig_name = isset( $_FILES[ $field ]['name'] ) ? sanitize_file_name( $_FILES[ $field ]['name'] ) : 'upload.bin';
        $size = (int) ( $_FILES[ $field ]['size'] ?? 0 );

        if ( $size <= 0 || $size > 10 * 1024 * 1024 ) { // 10 MB cap
            return new WP_Error( 'file_too_big', __( 'File must be 10 MB or smaller.', 'verifyistic' ) );
        }

        $check = wp_check_filetype_and_ext( $tmp_name, $orig_name );
        $real_mime = $check['type'] ?? '';
        // Compliance: image-only by default. PDFs disallowed because they can
        // carry JS/font payloads that are awkward to render in the admin viewer
        // and are not normal for ID/selfie uploads.
        $allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        if ( ! in_array( $real_mime, $allowed, true ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid file type. Please upload JPG, PNG, GIF, or WEBP.', 'verifyistic' ) );
        }

        $dir = self::private_upload_dir();
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }

        // Opaque random filename — never expose the original name.
        $ext      = pathinfo( $check['proper_filename'] ?: $orig_name, PATHINFO_EXTENSION );
        $ext      = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $ext ) ?: 'jpg' );
        $basename = wp_generate_password( 32, false, false ) . '.' . $ext;
        $dest     = trailingslashit( $dir['path'] ) . $basename;

        if ( ! @move_uploaded_file( $tmp_name, $dest ) ) {
            return new WP_Error( 'move_failed', __( 'Could not save file.', 'verifyistic' ) );
        }
        @chmod( $dest, 0640 );

        // Return an opaque key — NOT a URL. Stored in DB. Admins resolve via
        // Verifyistic_Admin::serve_protected_file() which validates capability
        // + a signed nonce, sets X-Content-Type-Options nosniff, and streams.
        $key = trailingslashit( $dir['rel'] ) . $basename;
        return 'private:' . $key;
    }

    /**
     * Resolve and prepare the private uploads directory.
     * Creates an .htaccess deny rule + index.php stub on first run.
     *
     * @return array|WP_Error  { path: absolute path, url: '', rel: relative-to-uploads }
     */
    public static function private_upload_dir() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'upload_dir', $uploads['error'] );
        }
        $base = trailingslashit( $uploads['basedir'] ) . 'private/verifyistic-ids';
        $rel  = 'private/verifyistic-ids/' . date( 'Y/m' );
        $path = trailingslashit( $uploads['basedir'] ) . $rel;
        if ( ! wp_mkdir_p( $path ) ) {
            return new WP_Error( 'mkdir', __( 'Could not create private upload directory.', 'verifyistic' ) );
        }

        // Drop deny-all .htaccess (Apache) + empty index.php at the root of
        // the private tree on first run. nginx hosts need a server-block rule
        // (documented in the plugin readme).
        $ht = trailingslashit( $base ) . '.htaccess';
        if ( ! file_exists( $ht ) ) {
            @file_put_contents( $ht, "Order Allow,Deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        $idx = trailingslashit( $base ) . 'index.php';
        if ( ! file_exists( $idx ) ) {
            @file_put_contents( $idx, "<?php // Silence is golden.\n" );
        }

        return array( 'path' => $path, 'url' => '', 'rel' => $rel );
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
