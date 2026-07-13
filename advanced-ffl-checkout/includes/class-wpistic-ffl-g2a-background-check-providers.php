<?php
/**
 * G2A — Background-check provider registry + result ingestion.
 *
 * Today the NICS outcome (NTN + proceed/delayed/denied) is typed in by hand
 * on every transfer — the single highest-liability step in the whole
 * transfer flow depends entirely on a staff member remembering to update it.
 * There is no public API for the FBI's NICS E-Check system itself (access
 * requires a direct vendor agreement with the FBI, same category of
 * constraint as ATF's FFL eZ Check having no public API) so this class does
 * NOT call anything external. Instead, mirroring the G2A_Carrier_Providers
 * pattern already in this codebase:
 *
 *   - A filterable provider registry (`wpistic_ffl_background_check_providers`)
 *     so a dealer's own NICS E-Check integration, or a licensed background-
 *     check vendor they've contracted with, can push results in.
 *   - A push-only REST webhook (`/nics/webhook`), HMAC-verified against a
 *     rotatable shared secret — exactly the carrier webhook's shape.
 *   - Manual entry (the existing behavior) stays the permanent fallback and
 *     writes into the exact same `nics_response`/`nics_source` columns a
 *     provider push would, so the data model doesn't fork.
 *
 * A "delayed" result auto-starts the federal 3-day clock (a timer, not a
 * compliance decision — the same class of automation the carrier webhook
 * already performs for "delivered"). A "proceed" or "denied" result is
 * recorded and flagged for staff review but never auto-advances the
 * transfer to approved/denied/transferred — that stays a logged, manual
 * staff action, consistent with every other compliance flow in this plugin.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Background_Check_Providers {

	const OPTION_KEY = 'wpistic_ffl_background_check_settings';

	/** Outcomes a provider (or staff) can report. Matches the 4473 worksheet's Section C checkboxes. */
	const RESPONSES = [ 'proceed', 'delayed', 'denied', 'cancelled' ];

	public function __construct() {
		add_action( 'init',          [ $this, 'register_defaults' ], 20 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_menu',    [ $this, 'register_admin_page' ], 32 );

		add_action( 'wp_ajax_wpistic_ffl_bgcheck_save_settings',  [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_bgcheck_rotate_secret',  [ __CLASS__, 'ajax_rotate_secret' ] );
	}

	// ── Provider registry ──────────────────────────────────────────

	/**
	 * [ 'slug' => [ 'label' => '…', 'has_push' => bool ] ]. Only 'manual' ships
	 * built-in — real vendor/dealer NICS E-Check integrations register
	 * themselves via the filter once they exist for a given site.
	 */
	public static function known_providers(): array {
		$default = [
			'manual' => [ 'label' => 'Manual entry (staff types NTN + outcome)', 'has_push' => false ],
		];
		return (array) apply_filters( 'wpistic_ffl_background_check_providers', $default );
	}

	public static function settings(): array {
		$saved    = get_option( self::OPTION_KEY, [] );
		$defaults = [
			'provider'       => 'manual',
			'webhook_secret' => '',
		];
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public function register_defaults(): void {
		if ( false === get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, [
				'provider'       => 'manual',
				'webhook_secret' => wp_generate_password( 32, false ),
			] );
		}
	}

	// ── Admin page ────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Background Check', 'advanced-ffl-checkout' ),
			__( '🔫 Background Check', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-background-check',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$s           = self::settings();
		$providers   = self::known_providers();
		$webhook_url = rest_url( WPISTIC_FFL_REST_NS . '/nics/webhook' );
		$nonce       = wp_create_nonce( 'wpistic_ffl_admin_nonce' );

		$needs_review = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT COUNT(*) FROM ' . DB::table( 'transfers' ) . "
			 WHERE nics_source != 'manual' AND nics_response IN (%s, %s)
			   AND status NOT IN ('approved','denied','transferred','cancelled')",
			'proceed', 'denied'
		) );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🔫</span>
				<?php esc_html_e( 'Background Check Provider', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:780px;">
				<?php esc_html_e( 'There is no public API for the FBI NICS system, so this plugin cannot call one automatically — access requires a direct vendor agreement with the FBI or a licensed background-check vendor. What it CAN do is receive results a dealer\'s own NICS E-Check integration, or a contracted vendor, pushes to it, so the outcome stops living only in a staff member\'s memory. Manual entry always remains available as the fallback.', 'advanced-ffl-checkout' ); ?>
			</p>

			<?php if ( $needs_review > 0 ) : ?>
				<div class="notice notice-warning inline" style="margin:16px 0;padding:10px 14px;">
					<p><strong><?php echo esc_html( $needs_review ); ?></strong>
					<?php esc_html_e( 'transfer(s) have a provider-reported result awaiting a staff decision.', 'advanced-ffl-checkout' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers' ) ); ?>"><?php esc_html_e( 'Review transfers →', 'advanced-ffl-checkout' ); ?></a></p>
				</div>
			<?php endif; ?>

			<form id="wpistic-ffl-bgcheck-form" style="background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:10px;max-width:880px;margin-top:16px;">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="provider"><?php esc_html_e( 'Provider', 'advanced-ffl-checkout' ); ?></label></th>
						<td>
							<select name="provider" id="provider">
								<?php foreach ( $providers as $slug => $meta ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['provider'], $slug ); ?>><?php echo esc_html( $meta['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Only "Manual entry" ships built-in. Additional providers appear here once registered via the wpistic_ffl_background_check_providers filter (e.g. a site-specific NICS E-Check or vendor integration).', 'advanced-ffl-checkout' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 style="margin-top:24px;border-bottom:2px solid #DCB45F;padding-bottom:8px;"><?php esc_html_e( 'Push Webhook (for a connected provider)', 'advanced-ffl-checkout' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'advanced-ffl-checkout' ); ?></th>
						<td>
							<code style="font-size:13px;user-select:all;padding:6px 10px;background:#f4f3f0;border-radius:4px;display:inline-block;"><?php echo esc_html( $webhook_url ); ?></code>
							<p class="description"><?php esc_html_e( 'A connected provider POSTs { transfer_ref, ntn, response } here. HMAC-verified against the secret below. "delayed" auto-starts the federal 3-day clock; "proceed"/"denied" are recorded and flagged for staff review — no result here ever auto-completes a transfer.', 'advanced-ffl-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="webhook_secret"><?php esc_html_e( 'Shared HMAC secret', 'advanced-ffl-checkout' ); ?></label></th>
						<td>
							<input type="text" name="webhook_secret" id="webhook_secret" value="<?php echo esc_attr( $s['webhook_secret'] ); ?>" class="regular-text code" autocomplete="off">
							<button type="button" class="button" id="wpistic-ffl-bgcheck-rotate-secret" style="margin-left:6px;"><?php esc_html_e( '🔄 Rotate', 'advanced-ffl-checkout' ); ?></button>
						</td>
					</tr>
				</table>

				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-bgcheck-result" style="margin-left:12px;font-weight:600;"></span></p>
			</form>

			<script>
			(function(){
				var form   = document.getElementById('wpistic-ffl-bgcheck-form');
				var result = document.getElementById('wpistic-ffl-bgcheck-result');
				var rotate = document.getElementById('wpistic-ffl-bgcheck-rotate-secret');

				form.addEventListener('submit', function(e){
					e.preventDefault();
					result.textContent = 'Saving…';
					result.style.color = '#666';
					var fd = new FormData(form);
					fd.append('action', 'wpistic_ffl_bgcheck_save_settings');
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(r => r.json())
						.then(j => {
							if (j && j.success) {
								result.textContent = '✓ Saved';
								result.style.color = '#16A34A';
							} else {
								result.textContent = '✗ ' + (j && j.data && j.data.message ? j.data.message : 'Save failed');
								result.style.color = '#DC2626';
							}
						})
						.catch(() => { result.textContent = '✗ Network error'; result.style.color = '#DC2626'; });
				});

				rotate.addEventListener('click', function(){
					if (! confirm('<?php echo esc_js( __( 'Rotate the webhook secret? Update your provider config with the new value immediately or webhooks will fail.', 'advanced-ffl-checkout' ) ); ?>')) return;
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_bgcheck_rotate_secret');
					fd.append('nonce', form.querySelector('[name=nonce]').value);
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(r => r.json())
						.then(j => {
							if (j && j.success && j.data && j.data.secret) {
								document.getElementById('webhook_secret').value = j.data.secret;
								result.textContent = '✓ New secret generated — save to commit';
								result.style.color = '#16A34A';
							}
						});
				});
			})();
			</script>
		</div>
		<?php
	}

	public static function ajax_save_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$current  = self::settings();
		$provider = sanitize_key( $_POST['provider'] ?? 'manual' );
		$known    = array_keys( self::known_providers() );
		$next     = [
			'provider'       => in_array( $provider, $known, true ) ? $provider : 'manual',
			'webhook_secret' => sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ?? $current['webhook_secret'] ) ),
		];
		// phpcs:enable
		update_option( self::OPTION_KEY, $next );
		wp_send_json_success( [ 'message' => 'Saved' ] );
	}

	public static function ajax_rotate_secret(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		wp_send_json_success( [ 'secret' => wp_generate_password( 32, false ) ] );
	}

	// ── Push path: webhook receiver ─────────────────────────────

	public function register_routes(): void {
		register_rest_route( WPISTIC_FFL_REST_NS, '/nics/webhook', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // HMAC-verified inside the callback.
			'callback'            => [ $this, 'rest_webhook' ],
		] );
	}

	public function rest_webhook( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$settings = self::settings();
		$secret   = (string) ( $settings['webhook_secret'] ?? '' );
		if ( '' === $secret ) {
			return new \WP_Error( 'webhook_disabled', 'Webhook secret not configured.', [ 'status' => 503 ] );
		}

		$raw_body  = $req->get_body();
		$signature = $req->get_header( 'X-Wpistic-Ffl-Signature' ) ?: '';
		$expected  = hash_hmac( 'sha256', $raw_body, $secret );

		$verified = apply_filters( 'wpistic_ffl_background_check_webhook_verify', null, $signature, $raw_body, $secret );
		if ( null === $verified ) {
			$verified = ( '' !== $signature ) && hash_equals( $expected, (string) $signature );
		}
		if ( ! $verified ) {
			return new \WP_Error( 'bad_signature', 'Invalid webhook signature.', [ 'status' => 401 ] );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'bad_payload', 'Invalid JSON payload.', [ 'status' => 400 ] );
		}

		$transfer_ref = sanitize_text_field( (string) ( $payload['transfer_ref'] ?? '' ) );
		$response     = sanitize_key( (string) ( $payload['response'] ?? '' ) );
		$ntn          = sanitize_text_field( (string) ( $payload['ntn'] ?? '' ) );
		$source       = sanitize_key( (string) ( $payload['provider'] ?? $settings['provider'] ?? 'vendor' ) );

		if ( '' === $transfer_ref || ! in_array( $response, self::RESPONSES, true ) ) {
			return new \WP_Error( 'incomplete_payload', 'Payload requires transfer_ref and a valid response.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, status FROM ' . DB::table( 'transfers' ) . ' WHERE transfer_ref = %s LIMIT 1',
			$transfer_ref
		) );
		if ( ! $transfer ) {
			// Acknowledge gracefully — provider should not retry for unknown transfers.
			return rest_ensure_response( [ 'ok' => true, 'matched' => false ] );
		}

		self::ingest_result( (int) $transfer->id, $response, $ntn, 'manual' === $source ? 'vendor' : $source );

		return rest_ensure_response( [ 'ok' => true, 'matched' => true, 'transfer_id' => (int) $transfer->id ] );
	}

	// ── Canonical ingestion — shared by the webhook and manual entry ────

	/**
	 * Record a background-check result against a transfer. Never sets the
	 * transfer to approved/denied/transferred — only "delayed" advances
	 * status (to start the 3-day clock, a timer not a compliance decision).
	 * "proceed"/"denied"/"cancelled" are recorded + flagged for a staff
	 * decision via the existing transfer status update flow.
	 */
	public static function ingest_result( int $transfer_id, string $response, string $ntn = '', string $source = 'manual' ): void {
		if ( ! in_array( $response, self::RESPONSES, true ) ) {
			return;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, status, transfer_ref, customer_name FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$transfer_id
		) );
		if ( ! $row ) {
			return;
		}

		$update = [
			'nics_response' => $response,
			'nics_source'   => $source,
			'updated_at'    => current_time( 'mysql' ),
		];
		if ( $ntn ) {
			$update['nics_transaction_number'] = $ntn;
		}
		if ( '' === $ntn && 'manual' !== $source ) {
			$update['nics_check_date'] = current_time( 'Y-m-d' );
		}
		$wpdb->update( DB::table( 'transfers' ), $update, [ 'id' => $transfer_id ] ); // phpcs:ignore WordPress.DB

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'background_check_result',
			'notes'       => sprintf( 'Background check result "%s" recorded via %s.', $response, $source ),
			'actor'       => 'manual' === $source ? ( wp_get_current_user()->user_login ?: 'staff' ) : $source,
			'actor_ip'    => 'manual' === $source ? Token::client_ip() : '',
		] );

		// "delayed" is a logistics timer, not a compliance decision — same class
		// of automation the carrier webhook already performs for "delivered".
		if ( 'delayed' === $response && 'manual' !== $source && ! in_array( $row->status, [ 'transferred', 'cancelled', 'denied' ], true ) ) {
			$wpdb->update( DB::table( 'transfers' ), [ 'status' => 'delayed' ], [ 'id' => $transfer_id ] ); // phpcs:ignore WordPress.DB
			do_action( 'wpistic_ffl_transfer_status_changed', $transfer_id, $row->status, 'delayed' );
		}

		// "proceed" / "denied" from a provider always needs a human to actually
		// move the transfer — email the admin instead of silently sitting there.
		if ( 'manual' !== $source && in_array( $response, [ 'proceed', 'denied' ], true ) ) {
			$admin_email = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );
			$subject     = sprintf( '[G2A] 🔫 Background Check Result — Transfer #%s', $row->transfer_ref );
			$body        = '<p>A connected background-check provider reported a result that needs staff review.</p>'
				. '<p><strong>Transfer:</strong> #' . esc_html( $row->transfer_ref ) . '<br>'
				. '<strong>Customer:</strong> ' . esc_html( $row->customer_name ) . '<br>'
				. '<strong>Reported result:</strong> ' . esc_html( ucfirst( $response ) ) . '</p>'
				. '<p>This is a recommendation, not an approval — confirm against the physical Form 4473 before changing the transfer status.</p>'
				. '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . $transfer_id ) ) . '">Review transfer</a></p>';
			wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		}
	}
}
