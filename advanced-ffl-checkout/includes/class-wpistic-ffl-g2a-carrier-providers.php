<?php
/**
 * G2A — Carrier provider registry + live tracking sync.
 *
 * Two ingestion paths:
 *
 *   1. PULL — daily cron polls every in-flight transfer (status =
 *      shipped_to_dealer, has tracking number) against the configured
 *      provider. EasyPost is implemented because it proxies UPS / USPS /
 *      FedEx / DHL behind one API + one key — the cheapest way to cover
 *      the carriers FFL stores actually use.
 *
 *   2. PUSH — REST endpoint /wpistic-ffl/v1/carrier/webhook accepts events
 *      from any provider that pushes (Shippo, EasyPost, ShipStation,
 *      Aftership). HMAC-verified against a per-provider shared secret
 *      stored in wpistic_ffl_carrier_settings.
 *
 * Both paths funnel through self::ingest_status() which maps a carrier
 * status code to an FFL transfer status — `delivered` auto-advances the
 * transfer to `received_by_dealer` and fires the same status-changed
 * action the dealer-portal path uses, so emails + SMS stay in sync.
 *
 * Additional providers can register themselves with the
 * wpistic_ffl_carrier_providers filter.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Carrier_Providers {

	const OPTION_KEY     = 'wpistic_ffl_carrier_settings';
	const CRON_HOOK_POLL = 'wpistic_ffl_carrier_poll';

	/** Default parcel sizes by item type — oz / inches — used when the linked WC product has no weight/dimensions set. */
	const DEFAULT_PARCELS = [
		'handgun' => [ 'weight_oz' => 48,  'length' => 13, 'width' => 9,  'height' => 4 ],
		'rifle'   => [ 'weight_oz' => 128, 'length' => 46, 'width' => 10, 'height' => 4 ],
		'shotgun' => [ 'weight_oz' => 128, 'length' => 46, 'width' => 10, 'height' => 4 ],
	];

	public function __construct() {
		add_action( 'init',                  [ $this, 'register_defaults' ], 20 );
		add_action( 'rest_api_init',         [ $this, 'register_routes' ] );
		add_action( self::CRON_HOOK_POLL,    [ __CLASS__, 'cron_poll_in_flight' ] );
		add_action( 'admin_menu',            [ $this, 'register_admin_page' ], 30 );
		add_action( 'wpistic_ffl_transfer_created', [ __CLASS__, 'maybe_rate_shop' ], 20, 1 );

		// AJAX: per-transfer "Check now" button from the admin row.
		add_action( 'wp_ajax_wpistic_ffl_carrier_check_now',    [ __CLASS__, 'ajax_check_now' ] );
		add_action( 'wp_ajax_wpistic_ffl_carrier_save_settings', [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_carrier_rotate_secret', [ __CLASS__, 'ajax_rotate_secret' ] );
		// Exhibit 08: rate-shop + label purchase — same vendor/key EasyPost tracking already uses.
		add_action( 'wp_ajax_wpistic_ffl_carrier_rate_shop', [ __CLASS__, 'ajax_rate_shop' ] );
		add_action( 'wp_ajax_wpistic_ffl_carrier_buy_label', [ __CLASS__, 'ajax_buy_label' ] );
	}

	// ── Admin page ────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Carrier Tracking', 'advanced-ffl-checkout' ),
			__( '📦 Carriers', 'advanced-ffl-checkout' ),
			'manage_options',
			'wpistic-ffl-carriers',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		$s         = self::settings();
		$providers = self::known_providers();
		$webhook_url = rest_url( WPISTIC_FFL_REST_NS . '/carrier/webhook' );
		$nonce     = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$next_poll = wp_next_scheduled( self::CRON_HOOK_POLL );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">📦</span>
				<?php esc_html_e( 'Carrier Tracking & Auto-Advance', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:780px;">
				<?php esc_html_e( 'When a parcel is delivered to the receiving FFL, this layer automatically advances the transfer to "received by dealer" — no manual portal click required from the dealer. Two ingestion paths are supported: a daily pull via EasyPost (covers UPS / USPS / FedEx / DHL behind one key), and a push webhook for any provider that supports outbound notifications (Shippo, EasyPost, AfterShip, ShipStation).', 'advanced-ffl-checkout' ); ?>
			</p>

			<form id="wpistic-ffl-carriers-form" style="background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:10px;max-width:880px;margin-top:16px;">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">Pull Provider (daily polling)</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="provider"><?php esc_html_e( 'Provider', 'advanced-ffl-checkout' ); ?></label></th>
						<td>
							<select name="provider" id="provider">
								<option value="none" <?php selected( $s['provider'], 'none' ); ?>><?php esc_html_e( 'None (manual + webhook only)', 'advanced-ffl-checkout' ); ?></option>
								<?php foreach ( $providers as $slug => $meta ) :
									if ( empty( $meta['has_pull'] ) ) continue; ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['provider'], $slug ); ?>><?php echo esc_html( $meta['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'EasyPost is the only built-in pull provider — it proxies UPS, USPS, FedEx and DHL behind one API key. Add more via the wpistic_ffl_carrier_providers filter.', 'advanced-ffl-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="easypost_api_key">EasyPost API Key</label></th>
						<td>
							<input type="password" name="easypost_api_key" id="easypost_api_key" value="<?php echo esc_attr( $s['easypost_api_key'] ); ?>" class="regular-text" autocomplete="off" placeholder="EZAK•••">
							<p class="description"><?php esc_html_e( 'Get a key at easypost.com — the test key prefix is EZTK, production EZAK. Stored in WP options; only sent over HTTPS to api.easypost.com.', 'advanced-ffl-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Polling enabled', 'advanced-ffl-checkout' ); ?></th>
						<td>
							<label><input type="checkbox" name="poll_enabled" value="1" <?php checked( ! empty( $s['poll_enabled'] ) ); ?>> <?php esc_html_e( 'Run daily background poll of every shipped-but-not-received transfer', 'advanced-ffl-checkout' ); ?></label>
							<p class="description">
								<?php if ( $next_poll ) {
									printf( esc_html__( 'Next scheduled run: %s', 'advanced-ffl-checkout' ), '<code>' . esc_html( date_i18n( 'Y-m-d H:i', $next_poll ) ) . '</code>' );
								} else {
									esc_html_e( 'Not scheduled. Save settings to (re)register the cron.', 'advanced-ffl-checkout' );
								} ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-advance on delivered', 'advanced-ffl-checkout' ); ?></th>
						<td>
							<label><input type="checkbox" name="auto_advance_on_delivered" value="1" <?php checked( ! empty( $s['auto_advance_on_delivered'] ) ); ?>> <?php esc_html_e( "When the carrier reports the parcel as delivered, automatically flip the transfer to 'received by dealer' and notify the customer.", 'advanced-ffl-checkout' ); ?></label>
						</td>
					</tr>
				</table>

				<h2 style="margin-top:32px;border-bottom:2px solid #DCB45F;padding-bottom:8px;">Push Webhook (any provider)</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'advanced-ffl-checkout' ); ?></th>
						<td>
							<code style="font-size:13px;user-select:all;padding:6px 10px;background:#f4f3f0;border-radius:4px;display:inline-block;"><?php echo esc_html( $webhook_url ); ?></code>
							<p class="description"><?php esc_html_e( 'Paste this URL into your carrier provider\'s webhook config. Requests are HMAC-verified against the shared secret below.', 'advanced-ffl-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="webhook_secret"><?php esc_html_e( 'Shared HMAC secret', 'advanced-ffl-checkout' ); ?></label></th>
						<td>
							<input type="text" name="webhook_secret" id="webhook_secret" value="<?php echo esc_attr( $s['webhook_secret'] ); ?>" class="regular-text code" autocomplete="off">
							<button type="button" class="button" id="wpistic-ffl-rotate-secret" style="margin-left:6px;"><?php esc_html_e( '🔄 Rotate', 'advanced-ffl-checkout' ); ?></button>
							<p class="description"><?php esc_html_e( 'Set this same value as the signing secret in your carrier provider. Supported headers: X-Easypost-Hmac-Signature, X-Shippo-Signature, X-Aftership-Hmac-Sha256, or the generic X-Wpistic-Ffl-Signature.', 'advanced-ffl-checkout' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Carrier Settings', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-carriers-result" style="margin-left:12px;font-weight:600;"></span></p>
			</form>

			<h2 style="margin-top:32px;"><?php esc_html_e( 'How it works', 'advanced-ffl-checkout' ); ?></h2>
			<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:880px;">
				<div style="padding:14px;background:#FAF6EC;border:1px solid #DCB45F;border-radius:8px;">
					<strong>1. Pull (daily cron)</strong>
					<p style="margin:6px 0 0;font-size:13px;color:#444;">Every in-flight transfer with a tracking number is checked against EasyPost. Delivered shipments auto-advance.</p>
				</div>
				<div style="padding:14px;background:#FAF6EC;border:1px solid #DCB45F;border-radius:8px;">
					<strong>2. Push (webhook)</strong>
					<p style="margin:6px 0 0;font-size:13px;color:#444;">Providers POST to the URL above the moment status changes. Faster — usually within seconds of delivery scan.</p>
				</div>
				<div style="padding:14px;background:#FAF6EC;border:1px solid #DCB45F;border-radius:8px;">
					<strong>3. Manual</strong>
					<p style="margin:6px 0 0;font-size:13px;color:#444;">"Check now" button on every transfer hits the provider on demand.</p>
				</div>
			</div>

			<script>
			(function(){
				var form   = document.getElementById('wpistic-ffl-carriers-form');
				var result = document.getElementById('wpistic-ffl-carriers-result');
				var rotate = document.getElementById('wpistic-ffl-rotate-secret');

				form.addEventListener('submit', function(e){
					e.preventDefault();
					result.textContent = 'Saving…';
					result.style.color = '#666';
					var fd = new FormData(form);
					fd.append('action', 'wpistic_ffl_carrier_save_settings');
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
					fd.append('action', 'wpistic_ffl_carrier_rotate_secret');
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$current = self::settings();
		$next    = [
			'provider'                  => sanitize_key( $_POST['provider'] ?? 'none' ),
			'easypost_api_key'          => sanitize_text_field( wp_unslash( $_POST['easypost_api_key'] ?? '' ) ),
			'webhook_secret'            => sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ?? $current['webhook_secret'] ) ),
			'poll_enabled'              => ! empty( $_POST['poll_enabled'] ) ? 1 : 0,
			'auto_advance_on_delivered' => ! empty( $_POST['auto_advance_on_delivered'] ) ? 1 : 0,
		];
		// phpcs:enable
		update_option( self::OPTION_KEY, $next );

		// Re-sync the polling cron based on the new toggle.
		if ( empty( $next['poll_enabled'] ) ) {
			G2A_Scheduler::unschedule_all( self::CRON_HOOK_POLL );
		} else {
			G2A_Scheduler::recurring( DAY_IN_SECONDS, self::CRON_HOOK_POLL, [], strtotime( 'tomorrow 04:00' ) );
		}

		wp_send_json_success( [ 'message' => 'Saved' ] );
	}

	public static function ajax_rotate_secret(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$secret  = wp_generate_password( 32, false );
		// Don't persist yet — return so the admin can confirm by saving.
		wp_send_json_success( [ 'secret' => $secret ] );
	}

	/**
	 * Defaults — ensure the option exists so settings save smoothly + the
	 * carrier-poll cron is scheduled.
	 */
	public function register_defaults(): void {
		if ( false === get_option( self::OPTION_KEY ) ) {
			update_option( self::OPTION_KEY, [
				'provider'             => 'none', // 'none' | 'easypost' | 'webhook_only'
				'easypost_api_key'     => '',
				'webhook_secret'       => wp_generate_password( 32, false ),
				'poll_enabled'         => 1,
				'auto_advance_on_delivered' => 1,
				// Exhibit 08 — rate-shop + label purchase.
				'rate_shop_enabled'    => 1,
				'require_adult_signature' => 1,
				'ship_from_name'       => '',
				'ship_from_street'     => '',
				'ship_from_city'       => '',
				'ship_from_state'      => '',
				'ship_from_zip'        => '',
				'ship_from_phone'      => '',
			] );
		}
		G2A_Scheduler::recurring( DAY_IN_SECONDS, self::CRON_HOOK_POLL, [], strtotime( 'tomorrow 04:00' ) );
	}

	// ── Provider registry ──────────────────────────────────────────

	/**
	 * Get the list of providers known to the plugin. Returns
	 * [ 'slug' => [ 'label' => '…', 'has_pull' => bool, 'has_push' => bool ] ].
	 */
	public static function known_providers(): array {
		$default = [
			'easypost'     => [ 'label' => 'EasyPost', 'has_pull' => true, 'has_push' => true ],
			'shippo'       => [ 'label' => 'Shippo (webhook only)', 'has_pull' => false, 'has_push' => true ],
			'shipstation'  => [ 'label' => 'ShipStation (webhook only)', 'has_pull' => false, 'has_push' => true ],
			'aftership'    => [ 'label' => 'AfterShip (webhook only)', 'has_pull' => false, 'has_push' => true ],
		];
		return (array) apply_filters( 'wpistic_ffl_carrier_providers', $default );
	}

	public static function settings(): array {
		$saved    = get_option( self::OPTION_KEY, [] );
		$defaults = [
			'provider'                  => 'none',
			'easypost_api_key'          => '',
			'webhook_secret'            => '',
			'poll_enabled'              => 1,
			'auto_advance_on_delivered' => 1,
			'rate_shop_enabled'         => 1,
			'require_adult_signature'   => 1,
			'ship_from_name'            => '',
			'ship_from_street'          => '',
			'ship_from_city'            => '',
			'ship_from_state'           => '',
			'ship_from_zip'             => '',
			'ship_from_phone'           => '',
		];
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Ship-from address — the explicit setting above wins; falls back to
	 * WooCommerce's own configured store address so most sites need zero new
	 * data entry to start rate-shopping.
	 */
	private static function ship_from_address(): array {
		$s = self::settings();
		if ( $s['ship_from_street'] && $s['ship_from_zip'] ) {
			return [
				'name'    => $s['ship_from_name'] ?: get_bloginfo( 'name' ),
				'street1' => $s['ship_from_street'],
				'city'    => $s['ship_from_city'],
				'state'   => $s['ship_from_state'],
				'zip'     => $s['ship_from_zip'],
				'country' => 'US',
				'phone'   => $s['ship_from_phone'],
			];
		}
		return [
			'name'    => $s['ship_from_name'] ?: get_bloginfo( 'name' ),
			'street1' => get_option( 'woocommerce_store_address', '' ) . ' ' . get_option( 'woocommerce_store_address_2', '' ),
			'city'    => get_option( 'woocommerce_store_city', '' ),
			'state'   => function_exists( 'wc_get_base_location' ) ? ( wc_get_base_location()['state'] ?? '' ) : '',
			'zip'     => get_option( 'woocommerce_store_postcode', '' ),
			'country' => function_exists( 'wc_get_base_location' ) ? ( wc_get_base_location()['country'] ?? 'US' ) : 'US',
			'phone'   => $s['ship_from_phone'],
		];
	}

	// ── Pull path: daily cron + manual "Check now" ──────────────────────

	/**
	 * Cron handler — scan every in-flight transfer with a tracking number and
	 * ask the configured provider for current status.
	 */
	public static function cron_poll_in_flight(): void {
		$s = self::settings();
		if ( empty( $s['poll_enabled'] ) ) {
			return;
		}
		if ( 'easypost' !== ( $s['provider'] ?? '' ) || empty( $s['easypost_api_key'] ) ) {
			return; // No provider that supports pull is configured.
		}

		global $wpdb;
		// Only transfers actively shipped, not yet received, with tracking data.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, shipment_carrier, shipment_tracking, status FROM ' . DB::table( 'transfers' ) . " 
			 WHERE status IN ('shipped_to_dealer','shipped')
			   AND shipment_tracking != ''
			   AND ( dealer_received_date IS NULL OR dealer_received_date = '0000-00-00' )
			 LIMIT %d",
			100
		) );

		foreach ( (array) $rows as $row ) {
			$status = self::easypost_lookup( (string) $row->shipment_carrier, (string) $row->shipment_tracking, $s['easypost_api_key'] );
			if ( null === $status ) {
				continue;
			}
			self::ingest_status( (int) $row->id, $status['code'], $status['detail'] ?? [] );

			// Be polite to the API — small jitter between requests.
			usleep( 200000 );
		}
	}

	/**
	 * Per-transfer manual check. Wired from the Transfers admin list.
	 */
	public static function ajax_check_now(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$id = isset( $_POST['transfer_id'] ) ? (int) $_POST['transfer_id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing transfer_id' ], 400 );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, shipment_carrier, shipment_tracking, status FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$id
		) );
		if ( ! $row || empty( $row->shipment_tracking ) ) {
			wp_send_json_error( [ 'message' => 'Transfer has no tracking number set.' ], 404 );
		}

		$s = self::settings();
		if ( 'easypost' !== ( $s['provider'] ?? '' ) || empty( $s['easypost_api_key'] ) ) {
			wp_send_json_error( [ 'message' => 'EasyPost provider is not configured. Add an API key in Settings → Carriers.' ], 400 );
		}

		$status = self::easypost_lookup( (string) $row->shipment_carrier, (string) $row->shipment_tracking, $s['easypost_api_key'] );
		if ( null === $status ) {
			wp_send_json_error( [ 'message' => 'No status returned from carrier provider.' ], 502 );
		}

		$advanced = self::ingest_status( (int) $row->id, $status['code'], $status['detail'] ?? [] );

		wp_send_json_success( [
			'carrier_status' => $status['code'],
			'advanced'       => $advanced,
		] );
	}

	// ── EasyPost client ──────────────────────────────────────────

	/**
	 * Minimal EasyPost Tracker fetch — POSTs a Tracker create request which
	 * idempotently returns the latest status for a (carrier, tracking) pair.
	 * Returns ['code' => 'pre_transit|in_transit|out_for_delivery|delivered|failure|unknown', 'detail' => array] or null.
	 *
	 * Docs: https://docs.easypost.com/docs/trackers
	 */
	public static function easypost_lookup( string $carrier, string $tracking, string $api_key ): ?array {
		$tracking = trim( $tracking );
		if ( '' === $tracking || '' === $api_key ) {
			return null;
		}
		$body = [
			'tracker' => [
				'tracking_code' => $tracking,
				'carrier'       => self::easypost_carrier_code( $carrier ),
			],
		];
		$resp = wp_remote_post( 'https://api.easypost.com/v2/trackers', [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $payload ) || empty( $payload['status'] ) ) {
			return null;
		}
		return [
			'code'   => (string) $payload['status'],
			'detail' => [
				'est_delivery_date' => $payload['est_delivery_date'] ?? null,
				'carrier'           => $payload['carrier'] ?? null,
				'tracking_code'     => $payload['tracking_code'] ?? null,
				'tracking_details'  => $payload['tracking_details'] ?? null,
			],
		];
	}

	/**
	 * Translate our human carrier label to EasyPost's slug.
	 * Unknown carriers pass through — EasyPost can auto-detect from the
	 * tracking number format for major carriers.
	 */
	private static function easypost_carrier_code( string $carrier ): ?string {
		$c = strtoupper( trim( $carrier ) );
		return match ( $c ) {
			'UPS'                  => 'UPS',
			'USPS'                 => 'USPS',
			'FEDEX', 'FEDEX EXPRESS' => 'FedEx',
			'DHL'                  => 'DHLExpress',
			'ONTRAC'               => 'OnTrac',
			default                => null,
		};
	}

	// ── Rate shop + label purchase (Exhibit 08) ──────────────────────

	/**
	 * Auto-called when a transfer is created — quotes rates from EasyPost and
	 * caches them on the transfer for staff to review. Never purchases
	 * anything by itself; buying a label is always an explicit admin action
	 * (real money spent), same "auto-quote, human buys" split this class
	 * already uses for tracking ("Check now" is manual too).
	 */
	public static function maybe_rate_shop( int $transfer_id ): void {
		$s = self::settings();
		if ( empty( $s['rate_shop_enabled'] ) || 'easypost' !== ( $s['provider'] ?? '' ) || empty( $s['easypost_api_key'] ) ) {
			return;
		}
		self::rate_shop( $transfer_id );
	}

	/**
	 * Quote shipping rates for a transfer from EasyPost, caching the result.
	 * Docs: https://docs.easypost.com/docs/shipments#create-a-shipment
	 *
	 * @return array|WP_Error rates array on success.
	 */
	public static function rate_shop( int $transfer_id ) {
		$s = self::settings();
		if ( empty( $s['easypost_api_key'] ) ) {
			return new \WP_Error( 'no_api_key', 'EasyPost API key is not configured.' );
		}

		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT tr.*, d.business_name, d.premise_street, d.premise_city, d.premise_state, d.premise_zip, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' tr
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = tr.dealer_id
			 WHERE tr.id = %d', $transfer_id
		) );
		if ( ! $t || ! $t->premise_street ) {
			return new \WP_Error( 'no_dealer_address', 'Transfer has no dealer address to ship to.' );
		}

		$parcel = self::parcel_for_transfer( $t );

		$body = [
			'shipment' => [
				'to_address'   => [
					'name'    => $t->business_name ?: 'Receiving FFL',
					'street1' => $t->premise_street,
					'city'    => $t->premise_city,
					'state'   => $t->premise_state,
					'zip'     => $t->premise_zip,
					'country' => 'US',
					'phone'   => $t->dealer_phone ?: '',
				],
				'from_address' => self::ship_from_address(),
				'parcel'       => [
					'weight' => $parcel['weight_oz'],
					'length' => $parcel['length'],
					'width'  => $parcel['width'],
					'height' => $parcel['height'],
				],
				'options'      => array_filter( [
					// FFL-to-FFL firearm shipments require an adult signature at
					// delivery — this is standard carrier policy, not a plugin
					// invention. Configurable in case a store has its own
					// carrier agreement that already covers it.
					'delivery_confirmation' => ! empty( $s['require_adult_signature'] ) ? 'ADULT_SIGNATURE' : null,
				] ),
			],
		];

		$resp = wp_remote_post( 'https://api.easypost.com/v2/shipments', [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $s['easypost_api_key'] . ':' ),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $payload ) ) {
			return new \WP_Error( 'easypost_error', $payload['error']['message'] ?? 'EasyPost rate request failed.', [ 'status' => $code ] );
		}

		$rates = array_map( static function ( $r ) {
			return [
				'id'            => $r['id'] ?? '',
				'carrier'       => $r['carrier'] ?? '',
				'service'       => $r['service'] ?? '',
				'rate'          => $r['rate'] ?? '',
				'currency'      => $r['currency'] ?? 'USD',
				'delivery_days' => $r['delivery_days'] ?? null,
			];
		}, (array) ( $payload['rates'] ?? [] ) );
		usort( $rates, static fn( $a, $b ) => (float) $a['rate'] <=> (float) $b['rate'] );

		$wpdb->update( DB::table( 'transfers' ), [ // phpcs:ignore WordPress.DB
			'easypost_shipment_id' => (string) ( $payload['id'] ?? '' ),
			'shipping_rates_json'  => wp_json_encode( $rates ),
			'updated_at'           => current_time( 'mysql' ),
		], [ 'id' => $transfer_id ] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'rate_shopped',
			'notes'       => count( $rates ) . ' shipping rate(s) quoted from EasyPost.',
			'actor'       => 'carrier-sync',
			'actor_ip'    => '',
		] );

		return $rates;
	}

	/**
	 * Pick a default parcel size for a transfer: the linked WC product's own
	 * weight/dimensions when set, otherwise a sensible per-item-type default.
	 */
	private static function parcel_for_transfer( object $t ): array {
		$type    = self::DEFAULT_PARCELS[ $t->item_type ] ?? self::DEFAULT_PARCELS['handgun'];
		$product = $t->item_sku && function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product( wc_get_product_id_by_sku( $t->item_sku ) ) : null;
		if ( $product && $product->has_weight() && $product->get_length() && $product->get_width() && $product->get_height() ) {
			$unit_w = get_option( 'woocommerce_weight_unit', 'lbs' );
			$unit_d = get_option( 'woocommerce_dimension_unit', 'in' );
			$weight_oz = 'kg' === $unit_w ? ( (float) $product->get_weight() * 35.274 ) : ( (float) $product->get_weight() * 16 );
			$to_in     = static fn( $v ) => 'cm' === $unit_d ? ( (float) $v / 2.54 ) : (float) $v;
			return [
				'weight_oz' => round( $weight_oz ),
				'length'    => round( $to_in( $product->get_length() ) ),
				'width'     => round( $to_in( $product->get_width() ) ),
				'height'    => round( $to_in( $product->get_height() ) ),
			];
		}
		return $type;
	}

	public static function ajax_rate_shop(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$id = (int) ( $_POST['transfer_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing transfer_id' ], 400 );
		}
		$rates = self::rate_shop( $id );
		if ( is_wp_error( $rates ) ) {
			wp_send_json_error( [ 'message' => $rates->get_error_message() ], 400 );
		}
		wp_send_json_success( [ 'rates' => $rates ] );
	}

	/**
	 * Purchase a specific quoted rate. Real money spent — always an explicit
	 * admin click, never automatic.
	 */
	public static function ajax_buy_label(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$id      = (int) ( $_POST['transfer_id'] ?? 0 );
		$rate_id = sanitize_text_field( wp_unslash( $_POST['rate_id'] ?? '' ) );
		// phpcs:enable
		if ( ! $id || '' === $rate_id ) {
			wp_send_json_error( [ 'message' => 'Missing transfer or rate.' ], 400 );
		}

		$s = self::settings();
		if ( empty( $s['easypost_api_key'] ) ) {
			wp_send_json_error( [ 'message' => 'EasyPost is not configured.' ], 400 );
		}

		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, transfer_ref, status, easypost_shipment_id FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d', $id
		) );
		if ( ! $t || ! $t->easypost_shipment_id ) {
			wp_send_json_error( [ 'message' => 'No rate-shopped shipment on file. Rate-shop again first.' ], 404 );
		}

		$resp = wp_remote_post( 'https://api.easypost.com/v2/shipments/' . rawurlencode( $t->easypost_shipment_id ) . '/buy', [
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $s['easypost_api_key'] . ':' ),
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [ 'rate' => [ 'id' => $rate_id ] ] ),
		] );
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( [ 'message' => $resp->get_error_message() ], 502 );
		}
		$code    = wp_remote_retrieve_response_code( $resp );
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || empty( $payload['tracking_code'] ) ) {
			wp_send_json_error( [ 'message' => $payload['error']['message'] ?? 'Label purchase failed.' ], 502 );
		}

		$carrier    = (string) ( $payload['selected_rate']['carrier'] ?? '' );
		$tracking   = (string) $payload['tracking_code'];
		$label_url  = (string) ( $payload['postage_label']['label_url'] ?? '' );

		$wpdb->update( DB::table( 'transfers' ), [ // phpcs:ignore WordPress.DB
			'shipment_carrier'   => $carrier,
			'shipment_tracking'  => $tracking,
			'shipping_label_url' => $label_url,
			'shipped_date'       => current_time( 'Y-m-d' ),
			'status'             => 'shipped_to_dealer',
			'updated_at'         => current_time( 'mysql' ),
		], [ 'id' => $id ] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $id,
			'event_type'  => 'label_purchased',
			'old_status'  => $t->status,
			'new_status'  => 'shipped_to_dealer',
			'notes'       => sprintf( 'Label purchased via EasyPost — %s, tracking %s.', $carrier, $tracking ),
			'actor'       => wp_get_current_user()->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		do_action( 'wpistic_ffl_transfer_status_changed', $id, $t->status, 'shipped_to_dealer' );

		wp_send_json_success( [ 'carrier' => $carrier, 'tracking' => $tracking, 'label_url' => $label_url ] );
	}

	// ── Push path: webhook receiver ─────────────────────────────

	public function register_routes(): void {
		register_rest_route( WPISTIC_FFL_REST_NS, '/carrier/webhook', [
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

		// Provider-agnostic HMAC: SHA-256 over the raw body using the shared
		// secret. Providers that send a different header format can be
		// adapted via the wpistic_ffl_carrier_webhook_verify filter.
		$raw_body  = $req->get_body();
		$signature = $req->get_header( 'X-Wpistic-Ffl-Signature' )
			?: $req->get_header( 'X-Easypost-Hmac-Signature' )
			?: $req->get_header( 'X-Shippo-Signature' )
			?: $req->get_header( 'X-Aftership-Hmac-Sha256' )
			?: '';
		$expected  = hash_hmac( 'sha256', $raw_body, $secret );

		$verified = apply_filters( 'wpistic_ffl_carrier_webhook_verify', null, $signature, $raw_body, $secret );
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

		$normalized = self::normalize_webhook_payload( $payload );
		if ( empty( $normalized['tracking_code'] ) || empty( $normalized['status'] ) ) {
			return new \WP_Error( 'incomplete_payload', 'Payload missing tracking_code or status.', [ 'status' => 400 ] );
		}

		// Match by tracking number — the most reliable identifier across providers.
		global $wpdb;
		$tid = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . DB::table( 'transfers' ) . ' WHERE shipment_tracking = %s LIMIT 1',
			$normalized['tracking_code']
		) );
		if ( ! $tid ) {
			// Acknowledge gracefully — provider should not retry for unknown shipments.
			return rest_ensure_response( [ 'ok' => true, 'matched' => false ] );
		}

		$advanced = self::ingest_status( $tid, $normalized['status'], $normalized );

		return rest_ensure_response( [ 'ok' => true, 'matched' => true, 'transfer_id' => $tid, 'advanced' => $advanced ] );
	}

	/**
	 * Flatten provider-specific JSON into { tracking_code, status, est_delivery_date }.
	 * Recognizes EasyPost, Shippo, AfterShip, ShipStation event shapes.
	 */
	private static function normalize_webhook_payload( array $p ): array {
		// EasyPost: { event: "tracker.updated", result: { tracking_code, status, ... } }
		if ( isset( $p['result']['tracking_code'], $p['result']['status'] ) ) {
			return [
				'tracking_code'     => (string) $p['result']['tracking_code'],
				'status'            => (string) $p['result']['status'],
				'est_delivery_date' => $p['result']['est_delivery_date'] ?? null,
				'source'            => 'easypost',
			];
		}
		// Shippo: { data: { tracking_number, tracking_status: { status } } }
		if ( isset( $p['data']['tracking_number'], $p['data']['tracking_status']['status'] ) ) {
			$shippo = strtolower( (string) $p['data']['tracking_status']['status'] );
			$map    = [
				'pre_transit' => 'pre_transit',
				'transit'     => 'in_transit',
				'delivered'   => 'delivered',
				'returned'    => 'failure',
				'failure'     => 'failure',
				'unknown'     => 'unknown',
			];
			return [
				'tracking_code'     => (string) $p['data']['tracking_number'],
				'status'            => $map[ $shippo ] ?? 'unknown',
				'est_delivery_date' => $p['data']['eta'] ?? null,
				'source'            => 'shippo',
			];
		}
		// AfterShip: { msg: { tracking_number, tag } }   tag = Delivered | InTransit | Exception | ...
		if ( isset( $p['msg']['tracking_number'], $p['msg']['tag'] ) ) {
			$tag = strtolower( (string) $p['msg']['tag'] );
			$map = [
				'delivered'      => 'delivered',
				'intransit'      => 'in_transit',
				'in_transit'     => 'in_transit',
				'outfordelivery' => 'out_for_delivery',
				'exception'      => 'failure',
				'pending'        => 'pre_transit',
				'expired'        => 'failure',
			];
			return [
				'tracking_code'     => (string) $p['msg']['tracking_number'],
				'status'            => $map[ $tag ] ?? 'unknown',
				'est_delivery_date' => $p['msg']['expected_delivery'] ?? null,
				'source'            => 'aftership',
			];
		}
		// ShipStation: { resource_type: "SHIP_NOTIFY", resource_url: …, tracking_number, status }
		// (Tracked via the user's own integration — they post a flattened body.)
		if ( isset( $p['tracking_number'], $p['status'] ) ) {
			return [
				'tracking_code' => (string) $p['tracking_number'],
				'status'        => (string) $p['status'],
				'source'        => 'shipstation',
			];
		}
		// Unrecognized — let it through if it already has the canonical keys.
		return [
			'tracking_code' => isset( $p['tracking_code'] ) ? (string) $p['tracking_code'] : '',
			'status'        => isset( $p['status'] ) ? (string) $p['status'] : '',
			'source'        => 'unknown',
		];
	}

	// ── Canonical ingestion ──────────────────────────────────────

	/**
	 * Apply a carrier status to a transfer. Records the audit event and, if
	 * the status indicates delivery, auto-advances the transfer to
	 * received_by_dealer (gated on the auto_advance_on_delivered setting).
	 *
	 * @return bool true if status was advanced.
	 */
	public static function ingest_status( int $transfer_id, string $carrier_status, array $detail = [] ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, status FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$transfer_id
		) );
		if ( ! $row ) {
			return false;
		}

		// Log the carrier event regardless of whether we advance status.
		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'carrier_status',
			'old_status'  => $row->status,
			'new_status'  => null,
			'notes'       => sprintf( 'Carrier reported "%s" via %s', $carrier_status, $detail['source'] ?? 'pull' ),
			'actor'       => 'carrier-sync',
			'actor_ip'    => '',
		] );

		Analytics::log( 'carrier_status', [
			'transfer_id' => $transfer_id,
			'metadata'    => [ 'carrier_status' => $carrier_status, 'detail' => $detail ],
		] );

		// Auto-advance on delivered.
		$settings = self::settings();
		$canon    = self::map_to_ffl_status( $carrier_status );
		if ( 'received_by_dealer' === $canon
			&& ! empty( $settings['auto_advance_on_delivered'] )
			&& in_array( $row->status, [ 'shipped_to_dealer', 'shipped' ], true )
		) {
			// The WHERE clause's status check (not just the earlier PHP-side
			// read) is what makes this atomic: a carrier-webhook retry racing
			// this same poll/webhook can only advance the row once — whichever
			// call's UPDATE actually matches a still-"shipped" row wins, and
			// $wpdb->update()'s return value tells us which one that was, so
			// the event log + hook only fire for the call that truly advanced
			// the transfer instead of both.
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB
				DB::table( 'transfers' ),
				[
					'status'                => 'received_by_dealer',
					'dealer_received_date'  => current_time( 'Y-m-d' ),
					'updated_at'            => current_time( 'mysql' ),
				],
				[
					'id'     => $transfer_id,
					'status' => $row->status,
				]
			);
			if ( ! $updated ) {
				return false;
			}
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => $transfer_id,
				'event_type'  => 'status_change',
				'old_status'  => $row->status,
				'new_status'  => 'received_by_dealer',
				'notes'       => 'Auto-advanced from carrier delivery confirmation.',
				'actor'       => 'carrier-sync',
				'actor_ip'    => '',
			] );
			do_action( 'wpistic_ffl_transfer_status_changed', $transfer_id, $row->status, 'received_by_dealer' );
			return true;
		}

		return false;
	}

	/**
	 * Carrier-vocabulary → FFL-vocabulary. Anything that means "the parcel
	 * landed at the destination" maps to received_by_dealer.
	 */
	private static function map_to_ffl_status( string $carrier_status ): ?string {
		switch ( strtolower( trim( $carrier_status ) ) ) {
			case 'delivered':
			case 'available_for_pickup':
				return 'received_by_dealer';
			case 'failure':
			case 'returned':
				return 'issue_reported';
		}
		return null; // No transfer-level change.
	}
}
