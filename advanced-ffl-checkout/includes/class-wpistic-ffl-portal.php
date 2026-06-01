<?php
/**
 * Dealer confirmation portal — public-facing landing page.
 *
 * Flow:
 *   1. Admin ships a transfer → Portal::issue_and_send() creates a signed token + emails the dealer.
 *   2. Dealer clicks the link → /ffl-confirm/{token}/
 *   3. Portal::handle_request() matches the rewrite, verifies the token, renders templates/dealer-portal.php.
 *   4. Dealer submits action form → Portal::handle_submission() verifies 2FA, consumes token, updates transfer.
 *
 * Security:
 *   - HMAC-signed token (Token class)
 *   - Single-use enforcement
 *   - Rate-limit per IP (transient-based)
 *   - Optional 2FA: last-4 of FFL license / email OTP / none
 *   - Honeypot field in form
 *   - Nonce on form submission
 *   - Analytics::log() on every meaningful event
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Portal {

	public function __construct() {
		add_action( 'init',               [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars',          [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect',  [ $this, 'handle_request' ] );

		// Form submission handler — posts to admin-post.php
		add_action( 'admin_post_nopriv_wpistic_ffl_portal_submit', [ $this, 'handle_submission' ] );
		add_action( 'admin_post_wpistic_ffl_portal_submit',        [ $this, 'handle_submission' ] );
	}

	// ── Rewrite registration ──────────────────────────────────────────────────

	public function register_rewrites(): void {
		$slug = Settings::portal( 'portal_slug', WPISTIC_FFL_PORTAL_SLUG );

		add_rewrite_tag( '%ffl_confirm_token%', '([^&]+)' );
		add_rewrite_rule(
			'^' . preg_quote( $slug, '/' ) . '/([^/]+)/?$',
			'index.php?ffl_confirm_token=$matches[1]',
			'top'
		);
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = 'ffl_confirm_token';
		return $vars;
	}

	// ── Public page handler ───────────────────────────────────────────────────

	public function handle_request(): void {
		$token = get_query_var( 'ffl_confirm_token' );
		if ( empty( $token ) ) {
			return;
		}

		// Admin-only preview mode: /ffl-confirm/PREVIEW/ shows a demo page with mock data.
		// Useful for previewing theme changes without sending a real dealer email.
		if ( 'PREVIEW' === $token || 'preview' === strtolower( $token ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				$redir_to = home_url( '/' );
				if ( isset( $_SERVER['REQUEST_URI'] ) ) {
					$candidate = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
					$validated = wp_validate_redirect( home_url( $candidate ), home_url( '/' ) );
					if ( $validated ) {
						$redir_to = $validated;
					}
				}
				wp_safe_redirect( wp_login_url( $redir_to ) );
				exit;
			}
			self::render_preview();
			exit;
		}

		// Force HTTPS if admin has enabled that option.
		if ( ! empty( Settings::portal( 'require_https', 0 ) ) && ! is_ssl() ) {
			wp_safe_redirect( set_url_scheme( ( isset( $_SERVER['REQUEST_URI'] ) ? home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : home_url() ), 'https' ) );
			exit;
		}

		$token   = rawurldecode( $token );
		$verify  = Token::verify( $token );

		$view = [
			'token_raw'   => $token,
			'error'       => null,
			'already'     => null,
			'transfer'    => null,
			'dealer'      => null,
			'action_done' => $_GET['ok'] ?? null,
			'two_factor'  => Settings::portal( 'two_factor_method', 'last4_ffl' ),
			'theme'       => Theming::settings(),
			'slug'        => Settings::portal( 'portal_slug', WPISTIC_FFL_PORTAL_SLUG ),
		];

		if ( is_wp_error( $verify ) ) {
			$data            = $verify->get_error_data();
			$already         = ! empty( $data['already_used'] );
			$view['error']   = [
				'code'    => $verify->get_error_code(),
				'message' => $verify->get_error_message(),
				'status'  => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400,
			];
			$view['already'] = $already ? $data : null;

			// If token was already used but replay view is allowed, fetch transfer info for context.
			if ( $already && ! empty( $data['transfer_id'] ) && (int) Settings::portal( 'allow_replay_view', 1 ) === 1 ) {
				$view['transfer'] = self::get_transfer_with_dealer( (int) $data['transfer_id'] );
				if ( $view['transfer'] ) {
					$view['dealer'] = self::extract_dealer( $view['transfer'] );
				}
			}
		} else {
			$row      = $verify['row'];
			$transfer = self::get_transfer_with_dealer( (int) $row->transfer_id );
			if ( ! $transfer ) {
				$view['error'] = [
					'code'    => 'transfer_missing',
					'message' => __( 'The transfer linked to this confirmation is no longer available.', 'advanced-ffl-checkout' ),
					'status'  => 410,
				];
			} else {
				$view['transfer'] = $transfer;
				$view['dealer']   = self::extract_dealer( $transfer );
				$view['token']    = $row;

				Analytics::log( 'portal_viewed', [
					'transfer_id' => (int) $row->transfer_id,
					'dealer_id'   => (int) $row->dealer_id,
					'token_id'    => (int) $row->id,
				] );
			}
		}

		self::render_page( $view );
		exit;
	}

	// ── Form submission ───────────────────────────────────────────────────────

	public function handle_submission(): void {
		// Nonce check
		if ( ! isset( $_POST['wpistic_ffl_portal_nonce'] ) || ! wp_verify_nonce( (string) wp_unslash( $_POST['wpistic_ffl_portal_nonce'] ), 'wpistic_ffl_portal_action' ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'advanced-ffl-checkout' ), 403 );
		}

		// Honeypot
		if ( ! empty( $_POST['website'] ) ) {
			// Silent drop — spam bot
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$action    = isset( $_POST['portal_action'] ) ? sanitize_key( wp_unslash( $_POST['portal_action'] ) ) : '';
		$last4     = isset( $_POST['last4'] ) ? preg_replace( '/[^0-9A-Za-z]/', '', (string) wp_unslash( $_POST['last4'] ) ) : '';
		$notes     = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$valid_actions = [ 'mark_received', 'report_issue', 'not_my_shipment' ];
		if ( ! in_array( $action, $valid_actions, true ) ) {
			wp_die( esc_html__( 'Invalid action.', 'advanced-ffl-checkout' ), 400 );
		}

		// Rate limit per IP
		if ( ! self::rate_limit_ok() ) {
			wp_die( esc_html__( 'Too many attempts. Please wait a minute and try again.', 'advanced-ffl-checkout' ), 429 );
		}

		// Verify token
		$verify = Token::verify( $raw_token );
		if ( is_wp_error( $verify ) ) {
			self::redirect_with_error( $raw_token, $verify->get_error_code() );
			exit;
		}

		$row   = $verify['row'];
		$tid   = (int) $row->transfer_id;
		$did   = (int) $row->dealer_id;
		$token_id = (int) $row->id;

		// 2FA check
		$method = Settings::portal( 'two_factor_method', 'last4_ffl' );
		if ( 'last4_ffl' === $method ) {
			if ( ! self::verify_last4( $did, $last4 ) ) {
				Analytics::log( 'two_factor_failed', [ 'transfer_id' => $tid, 'dealer_id' => $did, 'token_id' => $token_id ] );
				self::redirect_with_error( $raw_token, 'two_factor_failed' );
				exit;
			}
		}
		// email_otp method reserved for v1.2.0 — currently behaves as "none" if selected without implementation.

		// Consume token (single-use)
		Token::consume( $token_id, $action );

		// Update transfer status based on action
		$new_status = match ( $action ) {
			'mark_received'   => 'received_by_dealer',
			'report_issue'    => 'issue_reported',
			'not_my_shipment' => 'issue_reported',
			default           => 'received_by_dealer',
		};

		self::update_transfer_status( $tid, $new_status, $action, $notes );

		// Analytics
		Analytics::log( $action === 'mark_received' ? 'confirmed' : $action, [
			'transfer_id' => $tid,
			'dealer_id'   => $did,
			'token_id'    => $token_id,
			'metadata'    => [ 'notes' => $notes ],
		] );

		// Notify admin
		$email_settings = get_option( 'wpistic_ffl_email_settings', [] );
		if ( 'mark_received' === $action && ! empty( $email_settings['admin_notify_on_confirm'] ) ) {
			self::notify_admin( $tid, 'confirmed', $notes );
		}
		if ( in_array( $action, [ 'report_issue', 'not_my_shipment' ], true ) && ! empty( $email_settings['admin_notify_on_issue'] ) ) {
			self::notify_admin( $tid, $action, $notes );
		}

		// Redirect to confirmation screen
		wp_safe_redirect( add_query_arg( 'ok', rawurlencode( $action ), Token::build_url( $raw_token ) ) );
		exit;
	}

	// ── Token issuance + email ────────────────────────────────────────────────

	/**
	 * Called from the bootstrap when a transfer enters "shipped_to_dealer".
	 * Generates a fresh token + sends the confirmation email.
	 */
	public static function issue_and_send( int $transfer_id ): bool|\WP_Error {
		global $wpdb;
		$transfer = self::get_transfer_with_dealer( $transfer_id );
		if ( ! $transfer ) {
			return new \WP_Error( 'not_found', 'Transfer not found.' );
		}
		if ( empty( $transfer->dealer_id ) ) {
			return new \WP_Error( 'no_dealer', 'Transfer has no dealer assigned.' );
		}

		$token = Token::generate( $transfer_id, (int) $transfer->dealer_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		Analytics::log( 'token_issued', [
			'transfer_id' => $transfer_id,
			'dealer_id'   => (int) $transfer->dealer_id,
		] );

		$sent = Mailer::send_dealer_confirmation_email( $transfer_id, $token );
		if ( $sent ) {
			Analytics::log( 'email_sent', [
				'transfer_id' => $transfer_id,
				'dealer_id'   => (int) $transfer->dealer_id,
			] );
		}

		return (bool) $sent;
	}

	// ── Cron runner — reminders + cleanup ─────────────────────────────────────

	public static function cron_runner(): void {
		global $wpdb;
		$portal = get_option( 'wpistic_ffl_portal_settings', [] );

		// 1. Send reminders (Pro feature — gated)
		if ( License::can( 'auto_reminders' ) ) {
			$days = array_filter( array_map( 'intval', explode( ',', (string) ( $portal['reminder_days'] ?? '3,7,14' ) ) ) );
			sort( $days );

			foreach ( $days as $day_threshold ) {
				$tokens = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
					'SELECT * FROM ' . DB::table( 'dealer_tokens' ) . '
					 WHERE status = %s
					   AND expires_at > NOW()
					   AND reminder_count < %d
					   AND TIMESTAMPDIFF( DAY, created_at, NOW() ) >= %d
					   AND ( last_reminder_at IS NULL OR TIMESTAMPDIFF( DAY, last_reminder_at, NOW() ) >= 1 )
					 LIMIT 50',
					'active',
					count( $days ),
					$day_threshold
				) );

				foreach ( (array) $tokens as $row ) {
					$transfer = self::get_transfer_with_dealer( (int) $row->transfer_id );
					if ( ! $transfer ) continue;

					// Reconstruct URL to resend — we stored the hash, not the raw token.
					// So we issue a fresh token for reminders (invalidates the old one intentionally).
					$new_token = Token::generate( (int) $row->transfer_id, (int) $row->dealer_id );
					if ( is_wp_error( $new_token ) ) continue;

					Mailer::send_dealer_reminder_email( (int) $row->transfer_id, $new_token, $day_threshold );
					Analytics::log( 'reminder_sent', [
						'transfer_id' => (int) $row->transfer_id,
						'dealer_id'   => (int) $row->dealer_id,
						'token_id'    => (int) $row->id,
						'metadata'    => [ 'day_threshold' => $day_threshold ],
					] );

					$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
						'UPDATE ' . DB::table( 'dealer_tokens' ) . '
						 SET reminder_count = reminder_count + 1, last_reminder_at = NOW()
						 WHERE id = %d',
						(int) $row->id
					) );
				}
			}
		}

		// 2. Mark expired tokens
		$expired = (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'UPDATE ' . DB::table( 'dealer_tokens' ) . '
			 SET status = %s
			 WHERE status = %s AND expires_at <= NOW()',
			'expired',
			'active'
		) );
		if ( $expired > 0 ) {
			Analytics::log( 'token_expired', [ 'metadata' => [ 'count' => $expired ] ] );
		}

		// 3. Cleanup old analytics rows beyond retention
		Analytics::cleanup_old_rows();
	}

	// ── Rendering ─────────────────────────────────────────────────────────────

	private static function render_page( array $view ): void {
		$template = WPISTIC_FFL_PATH . 'templates/dealer-portal.php';
		if ( ! file_exists( $template ) ) {
			wp_die( esc_html__( 'Portal template missing.', 'advanced-ffl-checkout' ), 500 );
		}

		// Don't let theme or cache plugins interfere with the full-page takeover.
		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );

		include $template;
	}

	/**
	 * Render the portal page with mock data for admin-only theme preview.
	 * No DB writes, no analytics, no email. Pure visual preview.
	 */
	public static function render_preview(): void {
		$mock_transfer = (object) [
			'id'                 => 999999,
			'transfer_ref'       => 'G2A-PREVIEW',
			'order_id'           => 12345,
			'customer_name'      => 'John Smith',
			'customer_email'     => 'preview@example.com',
			'customer_phone'     => '+1-555-0100',
			'dealer_id'          => 1,
			'status'             => 'shipped_to_dealer',
			'item_description'   => 'Glock 19 Gen 5 (9mm)',
			'item_make'          => 'Glock',
			'item_model'         => '19 Gen 5',
			'item_caliber'       => '9mm',
			'item_sku'           => 'GLK-19-G5',
			'shipment_carrier'   => 'UPS',
			'shipment_tracking'  => '1Z999AA10123456784',
			'shipped_date'       => current_time( 'Y-m-d' ),
			// Dealer join fields
			'd_id'               => 1,
			'dealer_name'        => 'Sample Dealer LLC',
			'dealer_license'     => '9-86-999-01-1A-12345',
			'dealer_street'      => '1234 Demo Ave',
			'dealer_city'        => 'Phoenix',
			'dealer_state'       => 'AZ',
			'dealer_zip'         => '85001',
			'dealer_phone'       => '+1-480-555-0100',
		];

		$dealer = [
			'id'             => 1,
			'name'           => 'Sample Dealer LLC',
			'license_number' => '9-86-999-01-1A-12345',
			'city'           => 'Phoenix',
			'state'          => 'AZ',
			'street'         => '1234 Demo Ave',
			'zip'            => '85001',
			'phone'          => '+1-480-555-0100',
		];

		$view = [
			'token_raw'   => 'PREVIEW',
			'error'       => null,
			'already'     => null,
			'transfer'    => $mock_transfer,
			'dealer'      => $dealer,
			'action_done' => null,
			'two_factor'  => Settings::portal( 'two_factor_method', 'last4_ffl' ),
			'theme'       => Theming::settings(),
			'slug'        => Settings::portal( 'portal_slug', WPISTIC_FFL_PORTAL_SLUG ),
			'token'       => (object) [
				'id'          => 0,
				'transfer_id' => 999999,
				'dealer_id'   => 1,
				'action'      => 'mark_received',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) ),
				'status'      => 'active',
				'used_at'     => null,
			],
			'is_preview'  => true,
		];

		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// Inject preview banner at the top of the portal template
		add_action( 'wpistic_ffl_portal_before', [ self::class, 'render_preview_banner' ] );

		include WPISTIC_FFL_PATH . 'templates/dealer-portal.php';
	}

	public static function render_preview_banner(): void {
		?>
		<div style="position:sticky;top:0;z-index:9999;background:linear-gradient(90deg,#FBBF24,#F59E0B);color:#78350F;padding:12px 20px;text-align:center;font-weight:700;font-family:system-ui,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,0.2);">
			⚠ PREVIEW MODE — This is a demo page with mock data. No emails, no DB writes, no real transfer. Admin-only.
		</div>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function get_transfer_with_dealer( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.*,
			        d.id AS d_id, d.business_name AS dealer_name, d.license_number AS dealer_license,
			        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
			        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d',
			$id
		) );
		return $row ?: null;
	}

	private static function extract_dealer( object $transfer ): array {
		return [
			'id'             => (int) ( $transfer->d_id ?? $transfer->dealer_id ),
			'name'           => $transfer->dealer_name ?? '',
			'license_number' => $transfer->dealer_license ?? '',
			'city'           => $transfer->dealer_city ?? '',
			'state'          => $transfer->dealer_state ?? '',
			'street'         => $transfer->dealer_street ?? '',
			'zip'            => $transfer->dealer_zip ?? '',
			'phone'          => $transfer->dealer_phone ?? '',
		];
	}

	private static function verify_last4( int $dealer_id, string $last4 ): bool {
		global $wpdb;
		if ( strlen( $last4 ) < 4 ) return false;

		$license = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT license_number FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d LIMIT 1',
			$dealer_id
		) );
		if ( ! $license ) return false;

		$digits      = preg_replace( '/[^0-9A-Za-z]/', '', (string) $license );
		$expected    = strtoupper( substr( $digits, -4 ) );
		$provided    = strtoupper( substr( $last4, -4 ) );
		return hash_equals( $expected, $provided );
	}

	private static function rate_limit_ok(): bool {
		$limit = (int) Settings::portal( 'rate_limit_per_min', 5 );
		if ( $limit <= 0 ) return true;

		$ip   = Token::client_ip();
		$key  = 'wpistic_ffl_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return false;
		}

		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Update transfer status + log event via the existing events table.
	 * Mirrors the API class logic so the dashboard sees it identically.
	 */
	private static function update_transfer_status( int $transfer_id, string $new_status, string $action, string $notes ): void {
		global $wpdb;

		$existing = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT status FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$transfer_id
		) );
		if ( ! $existing ) return;

		$update = [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ];
		if ( 'received_by_dealer' === $new_status ) {
			$update['dealer_received_date'] = current_time( 'Y-m-d' );
		}

		$wpdb->update( DB::table( 'transfers' ), $update, [ 'id' => $transfer_id ] ); // phpcs:ignore WordPress.DB

		$actor_label = sprintf( 'dealer-portal (%s)', $action );
		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'status_change',
			'old_status'  => $existing->status,
			'new_status'  => $new_status,
			'notes'       => $notes ?: sprintf( 'Dealer confirmed via portal: %s', $action ),
			'actor'       => $actor_label,
			'actor_ip'    => Token::client_ip(),
		] );

		// Fire the shared action so Mailer + dashboard stay in sync
		do_action( 'wpistic_ffl_transfer_status_changed', $transfer_id, $existing->status, $new_status );
	}

	private static function notify_admin( int $transfer_id, string $event, string $notes ): void {
		$transfer = self::get_transfer_with_dealer( $transfer_id );
		if ( ! $transfer ) return;
		Mailer::send_admin_portal_notification( $transfer, $event, $notes );
	}

	private static function redirect_with_error( string $token, string $error_code ): void {
		$url = Token::build_url( $token );
		$url = add_query_arg( 'error', rawurlencode( $error_code ), $url );
		wp_safe_redirect( $url );
	}
}
