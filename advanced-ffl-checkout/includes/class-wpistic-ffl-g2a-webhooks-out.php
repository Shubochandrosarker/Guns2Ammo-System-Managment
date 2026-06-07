<?php
/**
 * G2A — Generic outbound webhook dispatcher.
 *
 * Generalizes the Verifyistic-only SMS dispatch path. Admin configures one
 * or more endpoint URLs; every transfer status change is POSTed as JSON,
 * HMAC-signed with the per-endpoint secret. Lets the store wire FFL events
 * into Zapier, Make.com, n8n, a custom CRM, Slack via a relay, etc.
 *
 * Storage: a single option (wpistic_ffl_webhooks_out) holding an array of
 * endpoint configs. Failed deliveries are retried via G2A_Scheduler with
 * exponential backoff (1m, 5m, 30m, 2h, 12h — 5 attempts max).
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Webhooks_Out {

	const OPTION_KEY   = 'wpistic_ffl_webhooks_out';
	const RETRY_HOOK   = 'wpistic_ffl_webhook_retry';
	const BACKOFF      = [ 60, 300, 1800, 7200, 43200 ]; // 1m, 5m, 30m, 2h, 12h

	public function __construct() {
		add_action( 'wpistic_ffl_transfer_status_changed', [ __CLASS__, 'dispatch_status_change' ], 30, 3 );
		add_action( 'wpistic_ffl_transfer_created',        [ __CLASS__, 'dispatch_created' ], 30, 2 );
		add_action( self::RETRY_HOOK,                      [ __CLASS__, 'do_retry' ], 10, 2 );
		add_action( 'admin_menu',                          [ $this, 'register_admin_page' ], 35 );
		add_action( 'wp_ajax_wpistic_ffl_webhooks_save',   [ __CLASS__, 'ajax_save' ] );
	}

	public static function endpoints(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		return is_array( $raw ) ? $raw : [];
	}

	// ── Dispatch entry points ───────────────────────────────────────────────

	public static function dispatch_status_change( int $transfer_id, string $old_status, string $new_status ): void {
		$payload = self::payload_for( $transfer_id, [
			'event'      => 'transfer.status_changed',
			'old_status' => $old_status,
			'new_status' => $new_status,
		] );
		self::send_to_all( $payload );
	}

	public static function dispatch_created( int $transfer_id, int $order_id ): void {
		$payload = self::payload_for( $transfer_id, [
			'event'    => 'transfer.created',
			'order_id' => $order_id,
		] );
		self::send_to_all( $payload );
	}

	// ── Payload builder ─────────────────────────────────────────────────────

	private static function payload_for( int $transfer_id, array $extra ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.*, d.business_name AS dealer_name, d.license_number AS dealer_license,
			        d.premise_city AS dealer_city, d.premise_state AS dealer_state, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d', $transfer_id
		), ARRAY_A );
		if ( ! $row ) {
			return [];
		}
		return array_merge( $extra, [
			'site'         => home_url( '/' ),
			'occurred_at'  => gmdate( 'c' ),
			'transfer'     => $row,
			'tracking_url' => class_exists( '\WpisticFFL\G2A_Customer_Tracking' )
				? G2A_Customer_Tracking::url_for( (string) ( $row['transfer_ref'] ?? '' ) )
				: '',
		] );
	}

	private static function send_to_all( array $payload ): void {
		if ( empty( $payload ) ) {
			return;
		}
		foreach ( self::endpoints() as $idx => $endpoint ) {
			if ( empty( $endpoint['enabled'] ) || empty( $endpoint['url'] ) ) {
				continue;
			}
			$events = (array) ( $endpoint['events'] ?? [] );
			if ( ! empty( $events ) && ! in_array( $payload['event'] ?? '', $events, true ) ) {
				continue;
			}
			self::deliver( (int) $idx, $endpoint, $payload, 0 );
		}
	}

	/**
	 * Single delivery attempt. On non-2xx or transport error, schedules a
	 * retry via G2A_Scheduler at the next BACKOFF[$attempt] offset.
	 */
	public static function deliver( int $endpoint_idx, array $endpoint, array $payload, int $attempt ): void {
		$body      = wp_json_encode( $payload );
		$secret    = (string) ( $endpoint['secret'] ?? '' );
		$signature = $secret ? hash_hmac( 'sha256', $body, $secret ) : '';

		$resp = wp_remote_post( (string) $endpoint['url'], [
			'timeout' => 8,
			'headers' => array_filter( [
				'Content-Type'              => 'application/json',
				'User-Agent'                => 'WpisticFFL/' . WPISTIC_FFL_VERSION,
				'X-Wpistic-Ffl-Event'       => (string) ( $payload['event'] ?? '' ),
				'X-Wpistic-Ffl-Signature'   => $signature ?: null,
				'X-Wpistic-Ffl-Attempt'     => (string) $attempt,
			] ),
			'body' => $body,
		] );

		$code = ( is_wp_error( $resp ) ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
		$ok   = ( $code >= 200 && $code < 300 );

		Analytics::log( 'webhook_out', [
			'metadata' => [
				'endpoint_idx' => $endpoint_idx,
				'event'        => $payload['event'] ?? '',
				'attempt'      => $attempt,
				'code'         => $code,
				'ok'           => $ok,
			],
		] );

		if ( ! $ok && $attempt < count( self::BACKOFF ) ) {
			$delay = self::BACKOFF[ $attempt ];
			G2A_Scheduler::single( time() + $delay, self::RETRY_HOOK, [ $endpoint_idx, $payload, $attempt + 1 ] );
		}
	}

	public static function do_retry( int $endpoint_idx, array $payload, int $attempt ): void {
		$endpoints = self::endpoints();
		if ( ! isset( $endpoints[ $endpoint_idx ] ) || empty( $endpoints[ $endpoint_idx ]['enabled'] ) ) {
			return;
		}
		self::deliver( $endpoint_idx, $endpoints[ $endpoint_idx ], $payload, $attempt );
	}

	// ── Admin page ──────────────────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Outbound Webhooks', 'advanced-ffl-checkout' ),
			__( '🔌 Webhooks Out', 'advanced-ffl-checkout' ),
			'manage_options',
			'wpistic-ffl-webhooks-out',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		$endpoints = self::endpoints();
		if ( empty( $endpoints ) ) {
			$endpoints = [ [ 'url' => '', 'secret' => wp_generate_password( 32, false ), 'events' => [], 'enabled' => 0 ] ];
		}
		$all_events = [
			'transfer.created'         => 'Transfer created',
			'transfer.status_changed'  => 'Status changed (any → any)',
		];
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;"><span style="font-size:24px;">🔌</span><?php esc_html_e( 'Outbound Webhooks', 'advanced-ffl-checkout' ); ?></h1>
			<p class="description" style="max-width:780px;">
				<?php esc_html_e( 'POST FFL transfer events to any URL — Zapier, Make.com, n8n, a custom CRM, a Slack incoming-webhook relay. Each delivery is HMAC-signed via X-Wpistic-Ffl-Signature and retried with exponential backoff on failure (1m → 5m → 30m → 2h → 12h).', 'advanced-ffl-checkout' ); ?>
			</p>

			<form id="wpistic-ffl-webhooks-out-form" style="max-width:880px;">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpistic_ffl_admin_nonce' ) ); ?>">

				<div id="endpoints-list" style="display:grid;gap:14px;">
				<?php foreach ( $endpoints as $i => $e ) : ?>
					<fieldset style="border:1px solid #ccd0d4;padding:18px;border-radius:10px;background:#fff;">
						<legend style="padding:0 8px;font-weight:700;">Endpoint #<?php echo (int) $i + 1; ?></legend>
						<table class="form-table"><tbody>
							<tr><th><label>URL</label></th><td>
								<input type="url" name="endpoints[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $e['url'] ?? '' ); ?>" class="regular-text code" placeholder="https://hooks.zapier.com/…" style="width:100%;">
							</td></tr>
							<tr><th><label>Secret (HMAC SHA-256)</label></th><td>
								<input type="text" name="endpoints[<?php echo (int) $i; ?>][secret]" value="<?php echo esc_attr( $e['secret'] ?? '' ); ?>" class="regular-text code" style="width:100%;">
							</td></tr>
							<tr><th><?php esc_html_e( 'Events', 'advanced-ffl-checkout' ); ?></th><td>
								<?php foreach ( $all_events as $slug => $label ) :
									$checked = in_array( $slug, (array) ( $e['events'] ?? [] ), true ); ?>
									<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="endpoints[<?php echo (int) $i; ?>][events][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>> <?php echo esc_html( $label ); ?> · <code><?php echo esc_html( $slug ); ?></code></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Leave all unchecked to send every event.', 'advanced-ffl-checkout' ); ?></p>
							</td></tr>
							<tr><th></th><td>
								<label><input type="checkbox" name="endpoints[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $e['enabled'] ) ); ?>> <?php esc_html_e( 'Enabled', 'advanced-ffl-checkout' ); ?></label>
							</td></tr>
						</tbody></table>
					</fieldset>
				<?php endforeach; ?>
				</div>

				<p style="margin-top:14px;">
					<button type="button" id="add-endpoint" class="button">+ <?php esc_html_e( 'Add endpoint', 'advanced-ffl-checkout' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save webhooks', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-webhooks-result" style="margin-left:12px;font-weight:600;"></span>
				</p>
			</form>

			<script>
			(function(){
				var form = document.getElementById('wpistic-ffl-webhooks-out-form');
				var list = document.getElementById('endpoints-list');
				var result = document.getElementById('wpistic-ffl-webhooks-result');

				document.getElementById('add-endpoint').addEventListener('click', function(){
					var idx = list.querySelectorAll('fieldset').length;
					var t = list.querySelector('fieldset').cloneNode(true);
					t.querySelectorAll('input, label').forEach(function(el){
						['name','for','id'].forEach(function(a){
							if (el.getAttribute(a)) el.setAttribute(a, el.getAttribute(a).replace(/endpoints\[\d+\]/, 'endpoints['+idx+']'));
						});
						if (el.type === 'url' || el.type === 'text') el.value = '';
						if (el.type === 'checkbox') el.checked = false;
					});
					t.querySelector('legend').textContent = 'Endpoint #' + (idx + 1);
					list.appendChild(t);
				});

				form.addEventListener('submit', function(e){
					e.preventDefault();
					result.textContent = 'Saving…'; result.style.color = '#666';
					var fd = new FormData(form);
					fd.append('action', 'wpistic_ffl_webhooks_save');
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(r => r.json()).then(j => {
							if (j && j.success) { result.textContent = '✓ Saved'; result.style.color = '#16A34A'; }
							else { result.textContent = '✗ ' + (j && j.data && j.data.message ? j.data.message : 'Save failed'); result.style.color = '#DC2626'; }
						});
				});
			})();
			</script>
		</div>
		<?php
	}

	public static function ajax_save(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$raw = isset( $_POST['endpoints'] ) && is_array( $_POST['endpoints'] ) ? wp_unslash( $_POST['endpoints'] ) : [];
		// phpcs:enable

		$clean = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = esc_url_raw( (string) ( $r['url'] ?? '' ) );
			if ( ! $url ) continue;
			$clean[] = [
				'url'     => $url,
				'secret'  => sanitize_text_field( (string) ( $r['secret'] ?? '' ) ),
				'events'  => array_map( 'sanitize_key', (array) ( $r['events'] ?? [] ) ),
				'enabled' => ! empty( $r['enabled'] ) ? 1 : 0,
			];
		}
		update_option( self::OPTION_KEY, $clean );
		wp_send_json_success( [ 'message' => 'Saved' ] );
	}
}
