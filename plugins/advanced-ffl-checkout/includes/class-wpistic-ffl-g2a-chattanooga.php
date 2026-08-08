<?php
/**
 * G2A — Chattanooga Shooting Supplies (CSSI) distributor integration.
 *
 * A fifth distributor client, closing the next-clearest gap found in the
 * same competitor sweep that led to the GunBroker marketplace-sync work
 * (v1.18.0): Chattanooga shows up as an integrated distributor in
 * AmmoReady/Gearfire/FFL Cockpit's own lists, and — unlike Davidson's,
 * which this plugin deliberately did NOT build against for lack of a real
 * public contract — Chattanooga genuinely publishes one.
 *
 * CONFIRMED, cross-verified against four independent real production
 * codebases on GitHub (a commercial coreFORCE POS module, a real FFL-hub
 * PHP client, a Go client, and a JS sync script -- not marketing copy):
 *   - Base URL: https://api.chattanoogashooting.com/rest/v5/ (all four
 *     sources agree on this exact host + path prefix).
 *   - Auth: `Authorization: Basic {SID}:{md5(token)}` -- a literal string
 *     concatenation, NOT standard base64-encoded HTTP Basic auth. SID and
 *     Token are dealer-account credentials issued by Chattanooga.
 *   - Catalog: `GET items/product-feed` returns a signed CSV download URL
 *     (`{"product_feed":{"url": "..."}}`); `GET items` (paginated JSON,
 *     max 50/page) also exists as an alternative. This class uses the
 *     product-feed (one full-catalog file), matching this plugin's
 *     existing "one call/file returns everything, cap processing, log the
 *     rest as skipped" convention (same as Lipsey's/Sports South/RSR/Bill
 *     Hicks) rather than a paginated multi-thousand-request sync.
 *   - Order submission: `POST orders` with `purchase_order_number`,
 *     `drop_ship_flag`, `insurance_flag`, `customer`, `delivery_option`,
 *     `federal_firearms_license_number`, `ship_to_address {name, line_1,
 *     line_2, city, state_code, zip}`, `order_items: [{item_number,
 *     order_quantity}]` (all field NAMES confirmed from the same real
 *     `placeOrder` source -- but see below, the exact accepted VALUE shape
 *     for `customer`/`delivery_option` was not). Response carries
 *     `orders[0].order_number`.
 *   - FFL-on-file check: `GET federal-firearms-licenses/{fflNumber}`
 *     returns `on_file_flag` -- a real, confirmed precondition check this
 *     class uses to hard-block a doomed order submission before it's sent,
 *     the same way Lipsey's blocks on a missing dealer license number. A
 *     failure of the CHECK itself (network/API error on this one endpoint)
 *     does not block -- only a definitive "not on file" answer does; see
 *     submit_order()'s own comment.
 *   - Errors: JSON body, HTTP status codes; `error_code`/`message` fields.
 *   - Product-feed CSV header row, confirmed against a real 81,195-row CSSI
 *     export found in a real reference repo's own sample data: `SKU, Item
 *     Name, Quantity In Stock, Price, UPC, Web Item Name, Web Item
 *     Description, Drop Ship Flag, Drop Ship Price, Category, Ship Weight,
 *     Image Location, Manufacturer, Manufacturer Item Number, Length,
 *     Width, Height, MAP, MSRP, Available Drop Ship Delivery Options,
 *     Allocated Item?`. map_catalog_row() matches these case-insensitively
 *     (a real reference parser for this exact feed does the same).
 *
 * NOT independently confirmed -- same honest treatment this plugin already
 * gives Sports South's DataSet XML shape and GunBroker's order-field names:
 *   - There is NO firearm/FFL-required column anywhere in the real sample
 *     feed above -- unlike Bill Hicks/GunBroker, there's no vendor-supplied
 *     signal here to even guess from. is_firearm defaults to false and
 *     must be classified by a store-supplied filter (e.g. matching on the
 *     real `Category` column's text), same honest "no flag exists" posture
 *     this plugin already applies elsewhere rather than inventing one.
 *   - The exact accepted value shape for the `customer` and `delivery_option`
 *     order fields (string vs. object; which delivery-option strings are
 *     valid) -- both filterable so a store can correct them once a live
 *     account's validation response reveals the real accepted shape.
 *   - Rate limits, and whether API access requires anything beyond an
 *     approved Chattanooga dealer account.
 *   - No webhook mechanism was found anywhere in reference code -- treated
 *     as polling-only (this class's catalog sync is manual/on-demand, like
 *     every other distributor here; no automatic catalog cron exists).
 *
 * Catalog sync is free to run any time; submitting a wholesale drop-ship
 * order is always an explicit admin click, never triggered automatically
 * by any transfer event -- same rule as every other distributor client.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Chattanooga {

	const OPTION_KEY = 'wpistic_ffl_chattanooga_settings';
	const CONTEXT    = 'chattanooga';
	const API_BASE   = 'https://api.chattanoogashooting.com/rest/v5/';

	public function __construct() {
		add_action( 'wp_ajax_wpistic_ffl_chattanooga_save_settings',   [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_chattanooga_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_wpistic_ffl_chattanooga_sync_catalog',    [ __CLASS__, 'ajax_sync_catalog' ] );
		add_action( 'wp_ajax_wpistic_ffl_chattanooga_submit_order',    [ __CLASS__, 'ajax_submit_order' ] );
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public static function settings(): array {
		$settings = wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), [
			'sid'   => '',
			'token' => '',
		] );
		return G2A_Distributor_Support::decrypt_settings_secrets( $settings, [ 'token' ], self::CONTEXT, self::OPTION_KEY );
	}

	public static function is_configured(): bool {
		$s = self::settings();
		return '' !== $s['sid'] && '' !== $s['token'];
	}

	// ── Low-level request ────────────────────────────────────────────────

	/**
	 * @return array|\WP_Error Decoded JSON body, or WP_Error.
	 */
	private static function request( string $method, string $path, ?array $body = null, array $query = [] ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'chattanooga_not_configured', __( 'Chattanooga API credentials are not configured.', 'advanced-ffl-checkout' ) );
		}
		$s = self::settings();

		$url = self::API_BASE . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = [
			'timeout' => 30,
			'method'  => $method,
			'headers' => [
				'Content-Type'  => 'application/json',
				// Confirmed real header shape -- a literal "SID:md5(token)"
				// string, NOT standard base64-encoded HTTP Basic auth. See
				// class docblock.
				'Authorization' => 'Basic ' . $s['sid'] . ':' . md5( $s['token'] ),
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'chattanooga_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach Chattanooga: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}

		$code    = wp_remote_retrieve_response_code( $resp );
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'chattanooga_api_error', is_array( $payload ) && ! empty( $payload['message'] )
				? (string) $payload['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Chattanooga API returned an unexpected response (HTTP %d).', 'advanced-ffl-checkout' ), $code
				) );
		}
		return is_array( $payload ) ? $payload : [];
	}

	// ── Catalog sync ──────────────────────────────────────────────────────

	/**
	 * Pulls the full product-feed CSV (one file, same "whole catalog in one
	 * shot" shape as every other distributor client here) and upserts it
	 * into the shared distributor_products table.
	 *
	 * @return array{total:int,imported:int,skipped:int}|\WP_Error
	 */
	public static function sync_catalog() {
		$feed = self::request( 'GET', 'items/product-feed', null, (array) apply_filters( 'wpistic_ffl_chattanooga_product_feed_query', [] ) );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}

		$feed_url = (string) ( $feed['product_feed']['url'] ?? $feed['url'] ?? '' );
		if ( '' === $feed_url ) {
			return new \WP_Error( 'chattanooga_no_feed_url', __( 'Chattanooga did not return a product feed download URL.', 'advanced-ffl-checkout' ) );
		}

		// The feed URL is a separately-signed download link, not another
		// API call -- no Authorization header needed/sent here.
		$resp = wp_remote_get( $feed_url, [ 'timeout' => 60 ] );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'chattanooga_feed_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not download the Chattanooga product feed: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'chattanooga_feed_http_error', sprintf(
				/* translators: %d: HTTP status code */
				__( 'Chattanooga product feed download returned HTTP %d.', 'advanced-ffl-checkout' ), $code
			) );
		}

		$limit    = (int) apply_filters( 'wpistic_ffl_chattanooga_catalog_sync_limit', 5000 );
		$rows     = self::parse_csv( wp_remote_retrieve_body( $resp ), $limit );
		$total    = count( $rows );
		$imported = 0;

		foreach ( $rows as $row ) {
			$item = self::map_catalog_row( $row );
			if ( '' === $item['item_no'] ) {
				continue;
			}
			G2A_Distributor_Support::upsert_catalog_item( 'chattanooga', $item );
			++$imported;
		}

		update_option( 'wpistic_ffl_chattanooga_last_sync', current_time( 'mysql' ), false );

		return [ 'total' => $total, 'imported' => $imported, 'skipped' => max( 0, $total - $imported ) ];
	}

	/**
	 * Splits a raw CSV body (header row + data rows) into an array of
	 * associative rows keyed by its own header line. Reads through a real
	 * stream (php://temp, not a pre-split-by-newline string) so a legally
	 * quoted field containing an embedded newline parses correctly instead
	 * of being torn into two malformed rows -- the earlier line-presplit
	 * version silently dropped exactly that kind of row. Stops once $limit
	 * rows have been collected rather than parsing the whole feed and
	 * discarding the excess afterward. Pure/no network I/O -- directly
	 * unit-testable against a hand-built CSV fixture string.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function parse_csv( string $body, int $limit = PHP_INT_MAX ): array {
		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, $body );
		rewind( $stream );

		$header = fgetcsv( $stream, 0, ',', '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $stream );
			return [];
		}
		$header = array_map( 'trim', $header );

		$rows = [];
		while ( count( $rows ) < $limit && false !== ( $fields = fgetcsv( $stream, 0, ',', '"', '\\' ) ) ) {
			if ( count( $fields ) !== count( $header ) ) {
				continue; // Malformed/short row -- skip rather than mis-align columns.
			}
			$rows[] = array_combine( $header, $fields );
		}
		fclose( $stream );
		return $rows;
	}

	/** Strips anything but digits/decimal point/leading minus before casting, so a currency symbol or thousands separator doesn't truncate the value at the first non-numeric character (PHP's own (float)/(int) cast would otherwise silently read "$1,250.00" as 1.0). */
	private static function to_number( string $raw ): float {
		$clean = preg_replace( '/[^0-9.\-]/', '', $raw ) ?? '';
		return '' === $clean || '-' === $clean || '.' === $clean ? 0.0 : (float) $clean;
	}

	/**
	 * Normalizes one product-feed CSV row into the shared catalog-item
	 * shape. Header names are matched case-insensitively against the real,
	 * confirmed header row (see class docblock) -- a real reference parser
	 * for this exact feed does the same. There is no confirmed FFL-required
	 * column anywhere in the real sample feed; is_firearm defaults to false
	 * and must be classified via a store-supplied filter (e.g. matching on
	 * the real Category column), never guessed.
	 */
	public static function map_catalog_row( array $row ): array {
		$normalized = [];
		foreach ( $row as $key => $value ) {
			$normalized[ strtolower( trim( (string) $key ) ) ] = $value;
		}

		$item_no = trim( (string) ( $normalized['sku'] ?? $normalized['item_number'] ?? '' ) );

		return [
			'item_no'      => $item_no,
			'description'  => (string) ( $normalized['item name'] ?? $normalized['web item name'] ?? $normalized['web item description'] ?? '' ),
			'manufacturer' => (string) ( $normalized['manufacturer'] ?? '' ),
			'model'        => (string) ( $normalized['manufacturer item number'] ?? '' ),
			'upc'          => (string) ( $normalized['upc'] ?? '' ),
			'price'        => self::to_number( (string) ( $normalized['price'] ?? $normalized['drop ship price'] ?? 0 ) ),
			'quantity'     => (int) self::to_number( (string) ( $normalized['quantity in stock'] ?? 0 ) ),
			'is_firearm'   => (bool) apply_filters( 'wpistic_ffl_chattanooga_is_firearm', false, $row ),
		];
	}

	// ── FFL-on-file check (real, confirmed precondition) ──────────────────

	/**
	 * Chattanooga tracks whether a certified FFL copy is already on file
	 * for a given license number -- a real precondition for a drop-ship
	 * order to succeed. Checked (and hard-blocked on) before submit_order()
	 * sends a doomed order, the same way Lipsey's blocks on a missing
	 * dealer license number.
	 *
	 * @return bool|\WP_Error
	 */
	public static function ffl_on_file( string $ffl_number ) {
		$result = self::request( 'GET', 'federal-firearms-licenses/' . rawurlencode( $ffl_number ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (bool) ( $result['on_file_flag'] ?? $result['onFileFlag'] ?? false );
	}

	// ── Order submission (explicit admin action only) ───────────────────

	/** @return array|\WP_Error */
	public static function submit_order( int $transfer_id, string $item_no, int $quantity ) {
		$t = G2A_Distributor_Support::transfer_with_dealer( $transfer_id );
		if ( ! $t ) {
			return new \WP_Error( 'chattanooga_no_transfer', __( 'Transfer not found.', 'advanced-ffl-checkout' ) );
		}
		if ( ! $t->license_number ) {
			return new \WP_Error( 'chattanooga_no_dealer_ffl', __( 'This transfer has no dealer FFL on file to ship to.', 'advanced-ffl-checkout' ) );
		}

		// Only a DEFINITIVE "not on file" answer blocks. A failed *check*
		// (a network/API hiccup on this one endpoint while orders itself may
		// be healthy) must not stop an order Chattanooga's own orders
		// endpoint might still accept -- that endpoint is the real arbiter
		// either way, this is just an early, best-effort warning.
		$on_file = self::ffl_on_file( $t->license_number );
		if ( false === $on_file ) {
			G2A_Distributor_Support::record_distributor_order( 'chattanooga', [
				'transfer_id' => $transfer_id,
				'po_number'   => $t->transfer_ref,
				'item_no'     => $item_no,
				'quantity'    => $quantity,
				'ship_to_ffl' => $t->license_number,
				'status'      => 'blocked',
				'note'        => sprintf( 'Chattanooga order (item %s x%d) blocked -- FFL not on file with Chattanooga.', $item_no, $quantity ),
			] );
			return new \WP_Error( 'chattanooga_ffl_not_on_file', __( 'Chattanooga does not have a certified copy of this dealer\'s FFL on file yet -- the order would fail on their side. Get the FFL on file with Chattanooga directly before retrying.', 'advanced-ffl-checkout' ) );
		}

		$body = [
			'purchase_order_number'        => $t->transfer_ref,
			'drop_ship_flag'                => 1,
			'insurance_flag'                => (int) apply_filters( 'wpistic_ffl_chattanooga_insurance_flag', 0 ),
			'delivery_option'                => (string) apply_filters( 'wpistic_ffl_chattanooga_delivery_option', 'ground' ),
			'federal_firearms_license_number' => $t->license_number,
			'customer'                       => (string) apply_filters( 'wpistic_ffl_chattanooga_order_customer', $t->business_name ),
			'ship_to_address'                => [
				'name'       => $t->business_name,
				'line_1'     => (string) $t->premise_street,
				'line_2'     => '',
				'city'       => (string) $t->premise_city,
				'state_code' => (string) $t->premise_state,
				'zip'        => (string) $t->premise_zip,
			],
			'order_items' => [
				[ 'item_number' => $item_no, 'order_quantity' => $quantity ],
			],
		];

		$result = self::request( 'POST', 'orders', $body );

		$status = is_wp_error( $result ) ? 'failed' : 'submitted';
		$ref    = ! is_wp_error( $result ) ? (string) ( $result['orders'][0]['order_number'] ?? $result['order_number'] ?? '' ) : '';

		G2A_Distributor_Support::record_distributor_order( 'chattanooga', [
			'transfer_id'           => $transfer_id,
			'po_number'             => $t->transfer_ref,
			'item_no'               => $item_no,
			'quantity'              => $quantity,
			'ship_to_ffl'           => $t->license_number,
			'status'                => $status,
			'distributor_order_ref' => $ref,
			'response_json'         => wp_json_encode( is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result ),
			'note'                  => sprintf(
				'Chattanooga order (item %s x%d) %s.%s',
				$item_no, $quantity, $status,
				$ref ? ' Order #: ' . $ref : ''
			),
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
		$sid   = sanitize_text_field( wp_unslash( $_POST['sid'] ?? '' ) );
		// wp_unslash() matters here (unlike the analogous password/token
		// reads in the other distributor clients, which have the same latent
		// gap) -- WordPress's own addslashes()-on-all-of-$_POST would
		// otherwise bake spurious backslashes into a token containing a
		// quote/backslash character, permanently corrupting it once encrypted.
		$token = (string) wp_unslash( $_POST['token'] ?? '' );
		// phpcs:enable

		$current = self::settings();
		// Blank token field means "keep the existing one" -- same UX convention as every other distributor client.
		if ( '' === $token ) {
			$token = $current['token'];
		}

		update_option( self::OPTION_KEY, [ 'sid' => $sid, 'token' => G2A_Distributor_Support::encrypt_secret( $token, self::CONTEXT ) ] );
		wp_send_json_success();
	}

	public static function ajax_test_connection(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$result = self::request( 'GET', 'items', null, [ 'page' => 1, 'per_page' => 1 ] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
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
		// phpcs:enable

		if ( ! $transfer_id || ! $item_no ) {
			wp_send_json_error( [ 'message' => 'Transfer and item number are required.' ], 400 );
		}

		$result = self::submit_order( $transfer_id, $item_no, $quantity );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	// ── Admin panel (rendered inside the shared "📦 Distributors" page — see G2A_Distributors_Admin) ──

	public static function render_panel(): void {
		global $wpdb;
		$nonce     = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$settings  = self::settings();
		$last_sync = get_option( 'wpistic_ffl_chattanooga_last_sync', '' );

		$catalog = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'distributor_products' ) . " WHERE distributor = 'chattanooga' ORDER BY last_synced DESC LIMIT 50"
		);
		$orders = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT o.*, t.transfer_ref FROM ' . DB::table( 'distributor_orders' ) . ' o
			 LEFT JOIN ' . DB::table( 'transfers' ) . " t ON t.id = o.transfer_id
			 WHERE o.distributor = 'chattanooga' ORDER BY o.created_at DESC LIMIT 50"
		);
		$open_transfers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, transfer_ref, item_sku, item_description FROM ' . DB::table( 'transfers' ) . "
			 WHERE status NOT IN ('transferred','cancelled') ORDER BY created_at DESC LIMIT 100"
		);
		?>
		<p class="description" style="max-width:760px;">
			<?php esc_html_e( 'Real client against Chattanooga Shooting Supplies\' documented REST API (api.chattanoogashooting.com) — requires an approved Chattanooga dealer account (SID + Token). Catalog sync is free to run any time; submitting an order is always an explicit click here, and is blocked up front if Chattanooga doesn\'t have this dealer\'s FFL on file yet.', 'advanced-ffl-checkout' ); ?>
		</p>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚙️ <?php esc_html_e( 'Credentials', 'advanced-ffl-checkout' ); ?></h2>
			<form id="wpistic-ffl-chattanooga-settings-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'SID', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="sid" value="<?php echo esc_attr( $settings['sid'] ); ?>" style="width:180px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Token', 'advanced-ffl-checkout' ); ?></label>
					<input type="password" name="token" placeholder="<?php echo $settings['token'] ? esc_attr__( '•••••••• (unchanged)', 'advanced-ffl-checkout' ) : ''; ?>" style="width:200px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></p>
				<p style="margin:0;"><button type="button" class="button" id="wpistic-ffl-chattanooga-test"><?php esc_html_e( 'Test Connection', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-chattanooga-settings-msg" style="font-weight:600;"></span>
			</form>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📋 <?php esc_html_e( 'Catalog', 'advanced-ffl-checkout' ); ?></h2>
			<p>
				<?php esc_html_e( 'Last synced:', 'advanced-ffl-checkout' ); ?> <?php echo esc_html( $last_sync ?: __( 'Never', 'advanced-ffl-checkout' ) ); ?>
				&nbsp;·&nbsp;
				<button type="button" class="button" id="wpistic-ffl-chattanooga-sync"><?php esc_html_e( 'Sync Catalog', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-chattanooga-sync-msg" style="font-weight:600;"></span>
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
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🚚 <?php esc_html_e( 'Submit Order', 'advanced-ffl-checkout' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ships to the transfer\'s own dealer FFL on file. This places a real wholesale order with Chattanooga — double check the item number and quantity first.', 'advanced-ffl-checkout' ); ?></p>
			<form id="wpistic-ffl-chattanooga-order-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
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
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Chattanooga Item #', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="item_no" style="width:140px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></label>
					<input type="number" name="quantity" value="1" min="1" style="width:70px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Order', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-chattanooga-order-msg" style="font-weight:600;"></span>
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
					<th><?php esc_html_e( 'Order #', 'advanced-ffl-checkout' ); ?></th>
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

		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			function postJson(body) {
				body.append('nonce', nonce);
				return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function(r){ return r.json(); });
			}

			document.getElementById('wpistic-ffl-chattanooga-settings-form').addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('wpistic-ffl-chattanooga-settings-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_chattanooga_save_settings');
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Saved' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-chattanooga-test').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-chattanooga-settings-msg');
				msg.textContent = 'Testing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_chattanooga_test_connection');
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Connected' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-chattanooga-sync').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-chattanooga-sync-msg');
				msg.textContent = 'Syncing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_chattanooga_sync_catalog');
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			var transferSelect = document.querySelector('#wpistic-ffl-chattanooga-order-form select[name="transfer_id"]');
			var itemInput = document.querySelector('#wpistic-ffl-chattanooga-order-form input[name="item_no"]');
			transferSelect.addEventListener('change', function(){
				var opt = transferSelect.options[transferSelect.selectedIndex];
				if (opt && opt.dataset.sku && !itemInput.value) { itemInput.value = opt.dataset.sku; }
			});

			document.getElementById('wpistic-ffl-chattanooga-order-form').addEventListener('submit', function(e){
				e.preventDefault();
				if (!window.confirm('Submit this as a real order to Chattanooga?')) { return; }
				var msg = document.getElementById('wpistic-ffl-chattanooga-order-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_chattanooga_submit_order');
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
