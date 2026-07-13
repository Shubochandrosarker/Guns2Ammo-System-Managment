<?php
/**
 * G2A — Lipsey's distributor drop-ship integration (gap #11).
 *
 * Targets Lipsey's real, documented dealer API (https://api.lipseys.com) —
 * the same one many other firearms e-commerce integrations use (there's a
 * public reference client at github.com/Lipseys/LipseysApiIntegrationPhp).
 * Requires an approved Lipsey's dealer account to authenticate; this
 * plugin has no such account to test against in-session, so — same
 * honesty note as the NMI gateway (v1.11.0) — this client targets the
 * real, stable contract but has never been run against a live account.
 * Verify Login/CatalogFeed/DropShipFirearm against a real sandbox before
 * trusting it with a real order.
 *
 * Confirmed (not guessed) from the public reference client:
 *   - Base URL: https://api.lipseys.com/api/
 *   - POST integration/authentication/login  { Email, Password } -> { token, ... }
 *   - Subsequent requests send the token in a custom "Token" header
 *     (NOT "Authorization: Bearer") — e.g. "Token: {token}".
 *   - GET  integration/items/CatalogFeed            -> full catalog
 *   - POST integration/order/DropShipFirearm        -> firearm drop-ship order
 *       { Ffl, Name, PoNumber, Phone, DelayShipping, DisableEmail,
 *         Items: [ { ItemNo, Quantity, Note } ] }
 *
 * Order submission is ALWAYS an explicit admin click from the Distributor
 * admin page — never automatic on any transfer event. Same "real money
 * only spent on an explicit click" rule as EasyPost label purchase
 * (v1.11.0): rate-shopping/catalog sync is free to run automatically,
 * placing a wholesale purchase order never is.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Lipseys {

	const OPTION_KEY       = 'wpistic_ffl_lipseys_settings';
	const TOKEN_TRANSIENT  = 'wpistic_ffl_lipseys_token';
	const API_BASE         = 'https://api.lipseys.com/api/';
	const LOGIN_PATH       = 'integration/authentication/login';
	const CATALOG_PATH     = 'integration/items/CatalogFeed';
	const DROPSHIP_FIREARM_PATH = 'integration/order/DropShipFirearm';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 49 );
		add_action( 'wp_ajax_wpistic_ffl_lipseys_save_settings',    [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_lipseys_test_connection',  [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_wpistic_ffl_lipseys_sync_catalog',     [ __CLASS__, 'ajax_sync_catalog' ] );
		add_action( 'wp_ajax_wpistic_ffl_lipseys_submit_order',     [ __CLASS__, 'ajax_submit_order' ] );
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public static function settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), [
			'email'    => '',
			'password' => '',
		] );
	}

	public static function is_configured(): bool {
		$s = self::settings();
		return '' !== $s['email'] && '' !== $s['password'];
	}

	// ── Auth + low-level request ──────────────────────────────────────────

	/** @return string|\WP_Error */
	private static function token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$settings = self::settings();
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'lipseys_not_configured', __( 'Lipsey\'s API credentials are not configured.', 'advanced-ffl-checkout' ) );
		}

		$resp = wp_remote_post( self::API_BASE . self::LOGIN_PATH, [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'Email' => $settings['email'], 'Password' => $settings['password'] ] ),
		] );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'lipseys_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach Lipsey\'s API: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}
		$code    = wp_remote_retrieve_response_code( $resp );
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || empty( $payload['token'] ) ) {
			return new \WP_Error( 'lipseys_auth_failed', is_array( $payload ) && ! empty( $payload['message'] )
				? (string) $payload['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Lipsey\'s login failed (HTTP %d).', 'advanced-ffl-checkout' ), $code
				) );
		}

		$ttl = (int) apply_filters( 'wpistic_ffl_lipseys_token_ttl', 45 * MINUTE_IN_SECONDS );
		set_transient( self::TOKEN_TRANSIENT, $payload['token'], $ttl );
		return $payload['token'];
	}

	/**
	 * @return array|\WP_Error Decoded JSON body, or WP_Error.
	 */
	private static function request( string $method, string $path, array $body = [] ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = [
			'timeout' => 25,
			'method'  => $method,
			'headers' => [
				'Content-Type' => 'application/json',
				'Token'        => $token, // Confirmed real header name -- see class docblock.
			],
		];
		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'lipseys_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach Lipsey\'s API: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}
		$code    = wp_remote_retrieve_response_code( $resp );
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 401 === $code ) {
			// Token may have expired server-side before our cached TTL — clear and let the next call re-auth.
			delete_transient( self::TOKEN_TRANSIENT );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'lipseys_api_error', is_array( $payload ) && ! empty( $payload['message'] )
				? (string) $payload['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Lipsey\'s API returned an unexpected response (HTTP %d).', 'advanced-ffl-checkout' ), $code
				) );
		}
		return is_array( $payload ) ? $payload : [];
	}

	// ── Catalog sync ──────────────────────────────────────────────────────

	/**
	 * Pulls the full catalog feed and upserts it into distributor_products.
	 * Capped so one sync can't run indefinitely on a huge feed — logs how
	 * many rows were skipped rather than silently truncating.
	 */
	public static function sync_catalog(): array|\WP_Error {
		$payload = self::request( 'GET', self::CATALOG_PATH );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$items = is_array( $payload ) && isset( $payload[0] ) ? $payload : ( $payload['items'] ?? $payload['Items'] ?? [] );
		if ( ! is_array( $items ) ) {
			return new \WP_Error( 'lipseys_bad_catalog', __( 'Unexpected catalog response shape from Lipsey\'s.', 'advanced-ffl-checkout' ) );
		}

		$limit    = (int) apply_filters( 'wpistic_ffl_lipseys_catalog_sync_limit', 5000 );
		$total    = count( $items );
		$imported = 0;

		global $wpdb;
		$table = DB::table( 'distributor_products' );
		foreach ( array_slice( $items, 0, $limit ) as $item ) {
			$item_no = (string) ( $item['itemNo'] ?? $item['ItemNo'] ?? '' );
			if ( '' === $item_no ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"INSERT INTO {$table}
				 ( distributor, item_no, description, manufacturer, model, upc, price, quantity, is_firearm, last_synced )
				 VALUES ( 'lipseys', %s, %s, %s, %s, %s, %f, %d, %d, %s )
				 ON DUPLICATE KEY UPDATE
				   description = VALUES(description), manufacturer = VALUES(manufacturer),
				   model = VALUES(model), upc = VALUES(upc), price = VALUES(price),
				   quantity = VALUES(quantity), is_firearm = VALUES(is_firearm), last_synced = VALUES(last_synced)",
				$item_no,
				(string) ( $item['description'] ?? $item['Description'] ?? '' ),
				(string) ( $item['manufacturer'] ?? $item['Manufacturer'] ?? '' ),
				(string) ( $item['model'] ?? $item['Model'] ?? '' ),
				(string) ( $item['upc'] ?? $item['Upc'] ?? '' ),
				(float) ( $item['price'] ?? $item['Price'] ?? 0 ),
				(int) ( $item['quantity'] ?? $item['Quantity'] ?? 0 ),
				! empty( $item['itemType'] ) && false !== stripos( (string) $item['itemType'], 'firearm' ) ? 1 : 0,
				current_time( 'mysql' )
			) );
			++$imported;
		}

		update_option( 'wpistic_ffl_lipseys_last_sync', current_time( 'mysql' ), false );

		return [
			'total'    => $total,
			'imported' => $imported,
			'skipped'  => max( 0, $total - $imported ),
		];
	}

	// ── Order submission (explicit admin action only) ───────────────────

	/**
	 * @return array|\WP_Error
	 */
	public static function submit_dropship_firearm_order( int $transfer_id, string $item_no, int $quantity, string $note = '' ) {
		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT t.*, d.license_number, d.business_name, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d', $transfer_id
		) );
		if ( ! $t ) {
			return new \WP_Error( 'lipseys_no_transfer', __( 'Transfer not found.', 'advanced-ffl-checkout' ) );
		}
		if ( ! $t->license_number ) {
			return new \WP_Error( 'lipseys_no_dealer_ffl', __( 'This transfer has no dealer FFL on file to ship to.', 'advanced-ffl-checkout' ) );
		}

		$body = [
			'Ffl'           => $t->license_number,
			'Name'          => $t->business_name,
			'PoNumber'      => $t->transfer_ref,
			'Phone'         => preg_replace( '/[^0-9]/', '', (string) $t->dealer_phone ) ?: '0000000000',
			'DelayShipping' => false,
			'DisableEmail'  => false,
			'Items'         => [ [ 'ItemNo' => $item_no, 'Quantity' => $quantity, 'Note' => $note ] ],
		];

		$result = self::request( 'POST', self::DROPSHIP_FIREARM_PATH, $body );

		$status  = is_wp_error( $result ) ? 'failed' : 'submitted';
		$ref     = ! is_wp_error( $result ) ? (string) ( $result['orderNumber'] ?? $result['OrderNumber'] ?? $result['referenceNumber'] ?? '' ) : '';
		$response_json = wp_json_encode( is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result );

		$wpdb->insert( DB::table( 'distributor_orders' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'distributor'           => 'lipseys',
			'transfer_id'           => $transfer_id,
			'po_number'             => $t->transfer_ref,
			'item_no'               => $item_no,
			'quantity'              => $quantity,
			'ship_to_ffl'           => $t->license_number,
			'status'                => $status,
			'distributor_order_ref' => $ref,
			'response_json'         => $response_json,
			'submitted_by'          => get_current_user_id() ?: null,
		] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => $transfer_id,
			'event_type'  => 'distributor_order_' . $status,
			'notes'       => sprintf(
				'Lipsey\'s drop-ship order (item %s x%d) %s.%s',
				$item_no, $quantity, $status,
				$ref ? ' Distributor ref: ' . $ref : ''
			),
			'actor'       => wp_get_current_user()->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		return is_wp_error( $result ) ? $result : [ 'status' => $status, 'ref' => $ref ];
	}

	// ── AJAX ──────────────────────────────────────────────────────────────

	public static function ajax_save_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$password = (string) ( $_POST['password'] ?? '' );
		// phpcs:enable

		$current = self::settings();
		// Blank password field on the form means "keep the existing one" —
		// same UX convention as most WordPress API-key settings screens.
		if ( '' === $password ) {
			$password = $current['password'];
		}

		update_option( self::OPTION_KEY, [ 'email' => $email, 'password' => $password ] );
		delete_transient( self::TOKEN_TRANSIENT );
		wp_send_json_success();
	}

	public static function ajax_test_connection(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		delete_transient( self::TOKEN_TRANSIENT );
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( [ 'message' => $token->get_error_message() ], 400 );
		}
		wp_send_json_success();
	}

	public static function ajax_sync_catalog(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$result = self::sync_catalog();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_submit_order(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$transfer_id = (int) ( $_POST['transfer_id'] ?? 0 );
		$item_no     = sanitize_text_field( wp_unslash( $_POST['item_no'] ?? '' ) );
		$quantity    = max( 1, (int) ( $_POST['quantity'] ?? 1 ) );
		$note        = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		// phpcs:enable

		if ( ! $transfer_id || ! $item_no ) {
			wp_send_json_error( [ 'message' => 'Transfer and item number are required.' ], 400 );
		}

		$result = self::submit_dropship_firearm_order( $transfer_id, $item_no, $quantity, $note );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	// ── Admin page ────────────────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Distributor (Lipsey\'s)', 'advanced-ffl-checkout' ),
			__( '📦 Distributor', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-lipseys',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$nonce    = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$settings = self::settings();
		$last_sync = get_option( 'wpistic_ffl_lipseys_last_sync', '' );

		$catalog = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'distributor_products' ) . " WHERE distributor = 'lipseys' ORDER BY last_synced DESC LIMIT 50"
		);
		$orders = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT o.*, t.transfer_ref FROM ' . DB::table( 'distributor_orders' ) . ' o
			 LEFT JOIN ' . DB::table( 'transfers' ) . " t ON t.id = o.transfer_id
			 WHERE o.distributor = 'lipseys' ORDER BY o.created_at DESC LIMIT 50"
		);
		$open_transfers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, transfer_ref, item_sku, item_description FROM ' . DB::table( 'transfers' ) . "
			 WHERE status NOT IN ('transferred','cancelled') ORDER BY created_at DESC LIMIT 100"
		);
		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">📦</span>
				<?php esc_html_e( 'Distributor Drop-Ship (Lipsey\'s)', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Real client against Lipsey\'s dealer API (api.lipseys.com) — requires an approved Lipsey\'s dealer account. Catalog sync is free to run any time; submitting a drop-ship order is always an explicit click here and is never triggered automatically by any transfer event.', 'advanced-ffl-checkout' ); ?>
			</p>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚙️ <?php esc_html_e( 'Credentials', 'advanced-ffl-checkout' ); ?></h2>
				<form id="wpistic-ffl-lipseys-settings-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Dealer Email', 'advanced-ffl-checkout' ); ?></label>
						<input type="email" name="email" value="<?php echo esc_attr( $settings['email'] ); ?>" style="width:240px;">
					</p>
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Password', 'advanced-ffl-checkout' ); ?></label>
						<input type="password" name="password" placeholder="<?php echo $settings['password'] ? esc_attr__( '•••••••• (unchanged)', 'advanced-ffl-checkout' ) : ''; ?>" style="width:200px;">
					</p>
					<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></p>
					<p style="margin:0;"><button type="button" class="button" id="wpistic-ffl-lipseys-test"><?php esc_html_e( 'Test Connection', 'advanced-ffl-checkout' ); ?></button></p>
					<span id="wpistic-ffl-lipseys-settings-msg" style="font-weight:600;"></span>
				</form>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📋 <?php esc_html_e( 'Catalog', 'advanced-ffl-checkout' ); ?></h2>
				<p>
					<?php esc_html_e( 'Last synced:', 'advanced-ffl-checkout' ); ?> <?php echo esc_html( $last_sync ?: __( 'Never', 'advanced-ffl-checkout' ) ); ?>
					&nbsp;·&nbsp;
					<button type="button" class="button" id="wpistic-ffl-lipseys-sync"><?php esc_html_e( 'Sync Catalog', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-lipseys-sync-msg" style="font-weight:600;"></span>
				</p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Item #', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Description', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Mfr', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Price', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $catalog as $c ) : ?>
						<tr>
							<td><code><?php echo esc_html( $c->item_no ); ?></code></td>
							<td><?php echo esc_html( $c->description ); ?></td>
							<td><?php echo esc_html( $c->manufacturer ); ?></td>
							<td>$<?php echo esc_html( number_format( (float) $c->price, 2 ) ); ?></td>
							<td><?php echo esc_html( (string) $c->quantity ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $catalog ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No catalog data yet — click "Sync Catalog" above.', 'advanced-ffl-checkout' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🚚 <?php esc_html_e( 'Submit Drop-Ship Order', 'advanced-ffl-checkout' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Ships to the transfer\'s own dealer FFL on file. This places a real wholesale order with Lipsey\'s — double check the item number and quantity first.', 'advanced-ffl-checkout' ); ?></p>
				<form id="wpistic-ffl-lipseys-order-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></label>
						<select name="transfer_id" style="width:260px;">
							<option value=""><?php esc_html_e( '— Select —', 'advanced-ffl-checkout' ); ?></option>
							<?php foreach ( $open_transfers as $t ) : ?>
								<option value="<?php echo (int) $t->id; ?>" data-sku="<?php echo esc_attr( $t->item_sku ); ?>"><?php echo esc_html( $t->transfer_ref . ' — ' . $t->item_description ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Lipsey\'s Item #', 'advanced-ffl-checkout' ); ?></label>
						<input type="text" name="item_no" style="width:140px;">
					</p>
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></label>
						<input type="number" name="quantity" value="1" min="1" style="width:70px;">
					</p>
					<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Order', 'advanced-ffl-checkout' ); ?></button></p>
					<span id="wpistic-ffl-lipseys-order-msg" style="font-weight:600;"></span>
				</form>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🧾 <?php esc_html_e( 'Order History', 'advanced-ffl-checkout' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Item', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Ship-to FFL', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Distributor Ref', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'When', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $orders as $o ) : ?>
						<tr>
							<td><?php echo esc_html( $o->transfer_ref ?: '#' . (int) $o->transfer_id ); ?></td>
							<td><code><?php echo esc_html( $o->item_no ); ?></code></td>
							<td><?php echo esc_html( (string) $o->quantity ); ?></td>
							<td><?php echo esc_html( $o->ship_to_ffl ); ?></td>
							<td><span style="color:<?php echo 'submitted' === $o->status ? '#16A34A' : '#DC2626'; ?>;font-weight:700;"><?php echo esc_html( ucfirst( $o->status ) ); ?></span></td>
							<td><?php echo esc_html( $o->distributor_order_ref ?: '—' ); ?></td>
							<td><?php echo esc_html( $o->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $orders ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No orders submitted yet.', 'advanced-ffl-checkout' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			function postJson(body) {
				body.append('nonce', nonce);
				return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function(r){ return r.json(); });
			}

			document.getElementById('wpistic-ffl-lipseys-settings-form').addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('wpistic-ffl-lipseys-settings-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_lipseys_save_settings');
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Saved' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-lipseys-test').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-lipseys-settings-msg');
				msg.textContent = 'Testing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_lipseys_test_connection');
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Connected' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-lipseys-sync').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-lipseys-sync-msg');
				msg.textContent = 'Syncing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_lipseys_sync_catalog');
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			var transferSelect = document.querySelector('#wpistic-ffl-lipseys-order-form select[name="transfer_id"]');
			var itemInput = document.querySelector('#wpistic-ffl-lipseys-order-form input[name="item_no"]');
			transferSelect.addEventListener('change', function(){
				var opt = transferSelect.options[transferSelect.selectedIndex];
				if (opt && opt.dataset.sku && !itemInput.value) { itemInput.value = opt.dataset.sku; }
			});

			document.getElementById('wpistic-ffl-lipseys-order-form').addEventListener('submit', function(e){
				e.preventDefault();
				if (!window.confirm('Submit this as a real drop-ship order to Lipsey\'s?')) { return; }
				var msg = document.getElementById('wpistic-ffl-lipseys-order-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_lipseys_submit_order');
				msg.textContent = 'Submitting…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});
		})();
		</script>
		<?php
	}
}
