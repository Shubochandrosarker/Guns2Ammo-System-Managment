<?php
/**
 * G2A — GunBroker.com marketplace sync.
 *
 * Closes the clearest gap found in a competitor sweep against AmmoReady,
 * Orchid, and the direct WooCommerce rival "FFL Cockpit," all three of
 * which sync inventory/orders to GunBroker.com, the largest online firearms
 * marketplace. GunBroker publishes a real, documented REST API
 * (api.gunbroker.com) — confirmed against a real production PHP
 * integration found on GitHub (github.com/NArun412/coreware,
 * classes/class.gunbroker.php) and a second live PHP bridge
 * (github.com/jonfirearmland/gunbroker-bridge), not marketing copy —
 * same rigor bar this plugin already applies to every distributor client.
 *
 * CONFIRMED from that real, executed source:
 *   - Base URL: https://api.gunbroker.com/v1/  (production)
 *   - Auth: POST Users/AccessToken { username, password } -> { accessToken }.
 *     Every subsequent request sends two headers: X-DevKey (app-level,
 *     issued separately per environment) and X-AccessToken (this token).
 *     A 401 means the token expired; re-auth and retry.
 *   - Listings: POST Items (create), PUT Items/{itemID} (update),
 *     DELETE Items/{itemID} (end). Confirmed request fields: CategoryID,
 *     Condition, Title, Description, FixedPrice, Quantity, IsFFLRequired
 *     (the actual FFL-transfer flag), PaymentMethods, PictureURLs, SKU,
 *     GTIN, ShippingProfileID, PostalCode, WillShipInternational,
 *     ListingDuration. A create response's `links` array carries the new
 *     item ID in its first entry's `title` field; errors carry a
 *     `userMessage` string.
 *   - Categories: GET Categories?ShowOnlyFirstLevelSubCategories=true
 *     returns a real nested tree (categoryID/categoryName/subCategories).
 *   - Orders: GET OrdersSold (paginated list), GET Orders/{orderID}
 *     (single order detail) both confirmed live in the same reference code.
 *
 * NOT independently confirmed — treated the same honest way this plugin
 * already treats Davidson's (rejected) and the license server (real but
 * not reachable yet):
 *   - Sandbox base URL: support docs confirm a separate
 *     api.sandbox.gunbroker.com host and a separate sandbox DevKey exist,
 *     but the exact path suffix below is inferred by symmetry with
 *     production, not independently verified against a live sandbox call.
 *   - The exact JSON field names an order carries its buyer name/email and
 *     FFL license number under. normalize_order_row() below tries several
 *     plausible key spellings defensively rather than assuming one is
 *     correct — see that method's own comment.
 *   - Accepted enum values for Condition and ListingDuration. Both are
 *     filterable (wpistic_ffl_gunbroker_condition,
 *     wpistic_ffl_gunbroker_listing_duration) so a store can correct them
 *     once GunBroker's own validation response reveals the real accepted
 *     set, instead of this code silently guessing wrong forever.
 *   - Rate limits and DevKey approval/cost terms — no public number was
 *     found for either; GunBroker's own "request API access" process is
 *     the only way to get a definitive answer, same category as standing
 *     constraint items elsewhere in this plugin.
 *
 * Listing sync (push) is free to run any time, same as every distributor
 * catalog sync. Pulling GunBroker orders (this class's cron) never spends
 * money or places an order — a GunBroker sale is already paid for on
 * GunBroker's side; importing it here only creates the WooCommerce order +
 * FFL transfer records this plugin needs for compliance/A&D-ledger
 * tracking, reusing the exact same Checkout::create_transfer_on_payment()
 * pipeline a normal storefront sale uses (see import_order() below).
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Gunbroker {

	const OPTION_KEY              = 'wpistic_ffl_gunbroker_settings';
	const TOKEN_TRANSIENT_PREFIX  = 'wpistic_ffl_gunbroker_token_';
	const CONTEXT                 = 'gunbroker';
	const CRON_HOOK               = 'wpistic_ffl_gunbroker_check_orders';
	const PAGE_SLUG               = 'wpistic-ffl-gunbroker';

	const BASE_URL_PRODUCTION = 'https://api.gunbroker.com/v1/';
	const BASE_URL_SANDBOX    = 'https://api.sandbox.gunbroker.com/v1/';

	public function __construct() {
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_save_settings',      [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_test_connection',    [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_browse_categories', [ __CLASS__, 'ajax_browse_categories' ] );
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_sync_listing',      [ __CLASS__, 'ajax_sync_listing' ] );
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_end_listing',       [ __CLASS__, 'ajax_end_listing' ] );
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_sync_all_listings', [ __CLASS__, 'ajax_sync_all_listings' ] );
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_check_orders',      [ __CLASS__, 'ajax_check_orders' ] );
		add_action( 'wp_ajax_wpistic_ffl_gunbroker_assign_dealer',     [ __CLASS__, 'ajax_assign_dealer' ] );

		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 49 );

		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_product_fields' ] );
		add_action( 'woocommerce_process_product_meta',                 [ $this, 'save_product_fields' ] );

		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_check_orders' ] );
		if ( self::is_configured() ) {
			G2A_Scheduler::recurring( HOUR_IN_SECONDS, self::CRON_HOOK );
		}
	}

	// ── Settings ──────────────────────────────────────────────────────────

	/** @var array<string,mixed>|null Per-request memo -- a single token()/request() round trip calls settings() several times; each call would otherwise re-run get_option() + an AES-256-GCM decrypt per secret field for no reason. Reset whenever ajax_save_settings() writes new settings. */
	private static ?array $settings_cache = null;

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$settings = wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), [
			'environment' => 'sandbox',
			'devkey'      => '',
			'username'    => '',
			'password'    => '',
		] );
		if ( ! in_array( $settings['environment'], [ 'sandbox', 'production' ], true ) ) {
			$settings['environment'] = 'sandbox';
		}
		self::$settings_cache = G2A_Distributor_Support::decrypt_settings_secrets( $settings, [ 'devkey', 'password' ], self::CONTEXT, self::OPTION_KEY );
		return self::$settings_cache;
	}

	public static function is_configured(): bool {
		$s = self::settings();
		return '' !== $s['devkey'] && '' !== $s['username'] && '' !== $s['password'];
	}

	private static function base_url(): string {
		return 'production' === self::settings()['environment'] ? self::BASE_URL_PRODUCTION : self::BASE_URL_SANDBOX;
	}

	private static function token_transient_key(): string {
		return self::TOKEN_TRANSIENT_PREFIX . self::settings()['environment'];
	}

	// ── Auth + low-level request ──────────────────────────────────────────

	/** @return string|\WP_Error */
	private static function token() {
		$cached = get_transient( self::token_transient_key() );
		if ( $cached ) {
			return $cached;
		}

		if ( ! self::is_configured() ) {
			return new \WP_Error( 'gunbroker_not_configured', __( 'GunBroker API credentials are not configured.', 'advanced-ffl-checkout' ) );
		}
		$s = self::settings();

		$resp = wp_remote_post( self::base_url() . 'Users/AccessToken', [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-DevKey'     => $s['devkey'],
			],
			'body'    => wp_json_encode( [ 'username' => $s['username'], 'password' => $s['password'] ] ),
		] );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'gunbroker_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach GunBroker: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}

		$code         = wp_remote_retrieve_response_code( $resp );
		$payload      = json_decode( wp_remote_retrieve_body( $resp ), true );
		$access_token = is_array( $payload ) ? (string) ( $payload['accessToken'] ?? '' ) : '';
		if ( $code < 200 || $code >= 300 || '' === $access_token ) {
			return new \WP_Error( 'gunbroker_auth_failed', is_array( $payload ) && ! empty( $payload['userMessage'] )
				? (string) $payload['userMessage']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'GunBroker login failed (HTTP %d).', 'advanced-ffl-checkout' ), $code
				) );
		}

		$ttl = (int) apply_filters( 'wpistic_ffl_gunbroker_token_ttl', 45 * MINUTE_IN_SECONDS );
		set_transient( self::token_transient_key(), $access_token, $ttl );
		return $access_token;
	}

	/**
	 * @return array|\WP_Error Decoded JSON body, or WP_Error.
	 */
	private static function request( string $method, string $path, ?array $body = null, array $query = [] ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$s = self::settings();

		$url = self::base_url() . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = [
			'timeout' => 25,
			'method'  => $method,
			'headers' => [
				'Content-Type'  => 'application/json',
				'X-DevKey'      => $s['devkey'],
				'X-AccessToken' => $token, // Confirmed real header name -- see class docblock.
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'gunbroker_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach GunBroker: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}

		$code     = wp_remote_retrieve_response_code( $resp );
		$raw_body = wp_remote_retrieve_body( $resp );
		$payload  = '' !== $raw_body ? json_decode( $raw_body, true ) : [];

		if ( 401 === $code ) {
			// Token may have expired server-side before our cached TTL — clear and let the next call re-auth.
			delete_transient( self::token_transient_key() );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'gunbroker_api_error', is_array( $payload ) && ! empty( $payload['userMessage'] )
				? (string) $payload['userMessage']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'GunBroker API returned an unexpected response (HTTP %d).', 'advanced-ffl-checkout' ), $code
				) );
		}
		return is_array( $payload ) ? $payload : [];
	}

	// ── Categories (reference only — no full taxonomy was independently confirmed) ──

	/** @return array|\WP_Error */
	public static function get_categories() {
		return self::request( 'GET', 'Categories', null, [ 'ShowOnlyFirstLevelSubCategories' => 'true' ] );
	}

	// ── Listing sync (push WC product -> GunBroker) ───────────────────────

	/**
	 * Pure mapping from a plain product-data array to GunBroker's confirmed
	 * `POST/PUT Items` request shape — kept free of any \WC_Product so it's
	 * directly unit-testable, same pattern as the distributor clients'
	 * normalize_catalog_row()-style pure helpers.
	 *
	 * @param array{title:string,description:string,price:float,quantity:int,sku:string,upc?:string,is_ffl_required:bool,image_urls:string[]} $p
	 */
	public static function build_listing_payload( array $p, string $category_id ): array {
		$payload = [
			'CategoryID'            => (int) $category_id,
			// "1" is Factory New on most GunBroker-alike condition enumerations
			// -- never independently confirmed against GunBroker's own list,
			// hence filterable rather than a silent hardcoded guess.
			'Condition'             => (int) apply_filters( 'wpistic_ffl_gunbroker_condition', 1, $p ),
			'Title'                 => substr( (string) $p['title'], 0, 80 ),
			'Description'           => (string) $p['description'],
			'FixedPrice'            => (float) $p['price'],
			'Quantity'              => max( 0, (int) $p['quantity'] ),
			'IsFFLRequired'         => (bool) $p['is_ffl_required'],
			'SKU'                   => (string) $p['sku'],
			'PictureURLs'           => array_values( (array) $p['image_urls'] ),
			'WillShipInternational' => false,
			'ListingDuration'       => (int) apply_filters( 'wpistic_ffl_gunbroker_listing_duration', 7 ),
			'PaymentMethods'        => (array) apply_filters( 'wpistic_ffl_gunbroker_payment_methods', [
				'VisaMastercard' => true,
				'Check'          => true,
				'MoneyOrder'     => true,
			] ),
		];

		if ( '' !== (string) ( $p['upc'] ?? '' ) ) {
			$payload['GTIN'] = (string) $p['upc'];
		}
		$postal_code = (string) apply_filters( 'wpistic_ffl_gunbroker_postal_code', '' );
		if ( '' !== $postal_code ) {
			$payload['PostalCode'] = $postal_code;
		}
		$shipping_profile_id = (string) apply_filters( 'wpistic_ffl_gunbroker_shipping_profile_id', '' );
		if ( '' !== $shipping_profile_id ) {
			$payload['ShippingProfileID'] = $shipping_profile_id;
		}

		/**
		 * Filter the full listing payload before it's sent to GunBroker --
		 * the escape hatch for any field this class got wrong once a store
		 * actually sees GunBroker's own validation response.
		 */
		return (array) apply_filters( 'wpistic_ffl_gunbroker_listing_payload', $payload, $p );
	}

	/** Extracts a plain data array from a real \WC_Product for build_listing_payload(). */
	public static function product_to_array( \WC_Product $product ): array {
		$image_ids  = array_filter( array_merge( [ $product->get_image_id() ], $product->get_gallery_image_ids() ) );
		$image_urls = array_values( array_filter( array_map( function ( $id ) {
			return wp_get_attachment_image_url( $id, 'full' );
		}, $image_ids ) ) );

		return [
			'title'           => $product->get_name(),
			'description'     => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'price'           => (float) $product->get_price(),
			'quantity'        => max( 0, (int) $product->get_stock_quantity() ),
			'sku'             => (string) $product->get_sku(),
			'upc'             => (string) apply_filters( 'wpistic_ffl_gunbroker_product_upc', '', $product ),
			'is_ffl_required' => 'yes' === get_post_meta( $product->get_id(), '_wpistic_ffl_required', true ),
			'image_urls'      => $image_urls,
		];
	}

	/** @return array|\WP_Error */
	public static function sync_listing( int $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'gunbroker_no_product', __( 'Product not found.', 'advanced-ffl-checkout' ) );
		}

		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'gunbroker_listings' ) . ' WHERE product_id = %d', $product_id
		) );

		// The product-edit-screen field is the source of truth -- an admin
		// who corrects a wrong category there and clicks sync again must see
		// the fix go out, not have the previously-stored value win forever.
		$category_id = (string) get_post_meta( $product_id, '_wpistic_ffl_gunbroker_category_id', true );
		if ( '' === $category_id && $existing ) {
			$category_id = (string) $existing->category_id;
		}
		if ( '' === $category_id ) {
			return new \WP_Error( 'gunbroker_no_category', __( 'Set a GunBroker Category ID on this product first.', 'advanced-ffl-checkout' ) );
		}

		$payload  = self::build_listing_payload( self::product_to_array( $product ), $category_id );
		$has_item = $existing && '' !== (string) $existing->gunbroker_item_id;
		$result   = $has_item
			? self::request( 'PUT', 'Items/' . rawurlencode( $existing->gunbroker_item_id ), $payload )
			: self::request( 'POST', 'Items', $payload );

		if ( is_wp_error( $result ) ) {
			self::upsert_listing_row( $existing, [
				'product_id'  => $product_id,
				'category_id' => $category_id,
				'status'      => 'error',
				'last_error'  => $result->get_error_message(),
				'last_synced' => current_time( 'mysql' ),
			] );
			return $result;
		}

		// Confirmed (see class docblock): a create response's `links` array
		// carries the new item ID in its first entry's `title`.
		$item_id = $has_item ? (string) $existing->gunbroker_item_id : (string) ( $result['links'][0]['title'] ?? $result['itemID'] ?? $result['ItemID'] ?? '' );

		self::upsert_listing_row( $existing, [
			'product_id'        => $product_id,
			'gunbroker_item_id' => $item_id,
			'category_id'       => $category_id,
			'status'            => 'active',
			'last_error'        => null,
			'last_synced'       => current_time( 'mysql' ),
		] );

		return [ 'item_id' => $item_id ];
	}

	/** Shared insert-or-update for a gunbroker_listings row -- mirrors the plain $wpdb->insert()/update() pattern end_listing() and assign_dealer() already use, instead of hand-written INSERT ... ON DUPLICATE KEY SQL. */
	private static function upsert_listing_row( ?object $existing, array $data ): void {
		global $wpdb;
		if ( $existing ) {
			$wpdb->update( DB::table( 'gunbroker_listings' ), $data, [ 'product_id' => $data['product_id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( DB::table( 'gunbroker_listings' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** @return true|\WP_Error */
	public static function end_listing( int $product_id ) {
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'gunbroker_listings' ) . ' WHERE product_id = %d', $product_id
		) );
		if ( ! $existing || '' === (string) $existing->gunbroker_item_id ) {
			return new \WP_Error( 'gunbroker_no_listing', __( 'This product has no active GunBroker listing.', 'advanced-ffl-checkout' ) );
		}

		$result = self::request( 'DELETE', 'Items/' . rawurlencode( $existing->gunbroker_item_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$wpdb->update( DB::table( 'gunbroker_listings' ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			[ 'status' => 'ended', 'last_synced' => current_time( 'mysql' ) ],
			[ 'product_id' => $product_id ]
		);
		return true;
	}

	/** Syncs every product flagged "List on GunBroker." Capped so a huge catalog can't run indefinitely on one click. */
	public static function sync_all_listings(): array {
		$limit = (int) apply_filters( 'wpistic_ffl_gunbroker_bulk_sync_limit', 200 );
		$ids   = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_key'       => '_wpistic_ffl_gunbroker_list', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
		] );

		$synced = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			is_wp_error( self::sync_listing( (int) $id ) ) ? ++$failed : ++$synced;
		}
		return [ 'total' => count( $ids ), 'synced' => $synced, 'failed' => $failed ];
	}

	// ── Order pull (GunBroker -> WC order -> existing transfer pipeline) ──

	/**
	 * Defensive field extraction — the exact JSON key names an order's
	 * buyer/FFL data comes back under were never independently confirmed
	 * (see class docblock), so this tries every plausible spelling in
	 * priority order rather than assuming one is correct. Pure function,
	 * directly unit-testable against a hand-built fixture array.
	 *
	 * @return array{order_id:string,ffl_license_number:string,buyer_name:string,buyer_email:string,items:array<int,array{item_id:string,quantity:int,price:float}>}
	 */
	public static function normalize_order_row( array $raw ): array {
		$order_id    = (string) ( $raw['orderID'] ?? $raw['OrderID'] ?? $raw['orderId'] ?? '' );
		$ffl         = (string) ( $raw['fflNumber'] ?? $raw['FFLNumber'] ?? $raw['fflLicense'] ?? $raw['ffl']['licenseNumber'] ?? '' );
		$buyer_name  = (string) ( $raw['buyerName'] ?? $raw['BuyerName'] ?? $raw['billTo']['name'] ?? '' );
		$buyer_email = (string) ( $raw['buyerEmail'] ?? $raw['BuyerEmail'] ?? '' );

		$raw_items = $raw['items'] ?? $raw['Items'] ?? $raw['orderItems'] ?? $raw['OrderItems'] ?? [];
		$items     = [];
		foreach ( (array) $raw_items as $ri ) {
			$item_id = (string) ( $ri['itemID'] ?? $ri['ItemID'] ?? '' );
			if ( '' === $item_id ) {
				continue;
			}
			$items[] = [
				'item_id'  => $item_id,
				'quantity' => max( 1, (int) ( $ri['quantity'] ?? $ri['Quantity'] ?? 1 ) ),
				'price'    => (float) ( $ri['price'] ?? $ri['Price'] ?? $ri['salePrice'] ?? 0 ),
			];
		}

		return [
			'order_id'           => $order_id,
			'ffl_license_number' => self::normalize_license( $ffl ),
			'buyer_name'         => $buyer_name,
			'buyer_email'        => $buyer_email,
			'items'              => $items,
		];
	}

	/** Strips everything but letters/digits and upper-cases -- for matching against this plugin's own dealers.license_number regardless of dash placement. */
	public static function normalize_license( string $license ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $license ) ?? '' );
	}

	/**
	 * Matches a normalized license against `dealers.license_number` (the
	 * same table the monthly ATF sync populates) by stripping hyphens on
	 * both sides -- honest because it matches the exact hyphenated format
	 * `Sync` itself writes (see class-wpistic-ffl-sync.php), not a guessed
	 * format. Requires is_active = 1, same as Checkout's own dealer-meta
	 * validation (persist_dealer_meta_on_order()) -- a match against a
	 * delisted FFL must never silently create a transfer.
	 */
	public static function find_dealer_by_license( string $normalized_license ): ?int {
		if ( '' === $normalized_license ) {
			return null;
		}
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id FROM ' . DB::table( 'dealers' ) . ' WHERE UPPER(REPLACE(license_number, \'-\', \'\')) = %s AND is_active = 1 LIMIT 1',
			$normalized_license
		) );
		return $id ? (int) $id : null;
	}

	/** Confirms a dealer ID exists and is active before it's ever trusted to receive a transfer -- same rule find_dealer_by_license() enforces for the automatic match path. */
	private static function dealer_is_active( int $dealer_id ): bool {
		if ( ! $dealer_id ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT 1 FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d AND is_active = 1 LIMIT 1',
			$dealer_id
		) );
	}

	private static function product_id_for_gunbroker_item( string $item_id ): ?int {
		if ( '' === $item_id ) {
			return null;
		}
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT product_id FROM ' . DB::table( 'gunbroker_listings' ) . ' WHERE gunbroker_item_id = %s LIMIT 1',
			$item_id
		) );
		return $id ? (int) $id : null;
	}

	/**
	 * Pulls recent GunBroker sales and imports every one this shop hasn't
	 * already seen (deduped on gunbroker_order_id). Pagination parameter
	 * names below (pageSize/pageIndex) are not independently confirmed --
	 * filterable so a store can correct them once a live account is
	 * connected, same honesty pattern as the payload filters above.
	 *
	 * @return array{imported:int,needs_dealer:int,skipped:int}|\WP_Error
	 */
	public static function pull_orders() {
		$query   = (array) apply_filters( 'wpistic_ffl_gunbroker_orders_query', [ 'pageSize' => 50, 'pageIndex' => 1 ] );
		$payload = self::request( 'GET', 'OrdersSold', null, $query );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$raw_orders = $payload['results'] ?? $payload['Results'] ?? $payload['orders'] ?? $payload;
		if ( ! is_array( $raw_orders ) || ! isset( $raw_orders[0] ) ) {
			$raw_orders = [];
		}

		global $wpdb;
		$imported     = 0;
		$needs_dealer = 0;
		$skipped      = 0;

		foreach ( $raw_orders as $raw ) {
			$order_data = self::normalize_order_row( $raw );
			if ( '' === $order_data['order_id'] ) {
				++$skipped;
				continue;
			}

			// Atomic claim: the UNIQUE KEY on gunbroker_order_id (not a PHP
			// check-then-act SELECT) is what actually stops two overlapping
			// sweeps -- the hourly cron and a staff member's manual "Check
			// for New Orders" click -- from both importing the same
			// GunBroker sale as two separate WC orders/transfers. A losing
			// INSERT IGNORE here means another call already owns this
			// order_id; skip it entirely rather than falling through.
			$claimed = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'INSERT IGNORE INTO ' . DB::table( 'gunbroker_orders' ) . ' ( gunbroker_order_id, status, raw_json ) VALUES ( %s, %s, %s )',
				$order_data['order_id'], 'claimed', wp_json_encode( $raw )
			) );
			if ( ! $claimed ) {
				continue; // Already tracked from a prior or concurrent sweep -- never re-import.
			}

			$status = self::import_order( $order_data, $raw );
			if ( 'imported' === $status ) {
				++$imported;
			} elseif ( 'needs_dealer' === $status ) {
				++$needs_dealer;
			} else {
				++$skipped;
			}
		}

		update_option( 'wpistic_ffl_gunbroker_last_check', current_time( 'mysql' ), false );
		return [ 'imported' => $imported, 'needs_dealer' => $needs_dealer, 'skipped' => $skipped ];
	}

	/** Finalizes the row pull_orders() already claimed via INSERT IGNORE -- always an UPDATE, never a second INSERT, since the claim row already exists. */
	private static function finish_claimed_order( string $gunbroker_order_id, array $data ): void {
		global $wpdb;
		$wpdb->update( DB::table( 'gunbroker_orders' ), $data, [ 'gunbroker_order_id' => $gunbroker_order_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function import_order( array $order_data, array $raw ): string {
		$line_items = [];
		$unmapped   = 0;
		foreach ( $order_data['items'] as $line ) {
			$product_id = self::product_id_for_gunbroker_item( $line['item_id'] );
			if ( ! $product_id ) {
				++$unmapped; // No known mapping to a real WC product -- never fabricate a line item.
				continue;
			}
			$line_items[] = $line + [ 'product_id' => $product_id ];
		}

		if ( ! $line_items ) {
			self::finish_claimed_order( $order_data['order_id'], [
				'ffl_license_number' => $order_data['ffl_license_number'],
				'status'             => 'error',
			] );
			return 'error';
		}

		$dealer_id = '' !== $order_data['ffl_license_number'] ? self::find_dealer_by_license( $order_data['ffl_license_number'] ) : null;

		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			self::finish_claimed_order( $order_data['order_id'], [
				'ffl_license_number' => $order_data['ffl_license_number'],
				'dealer_id'          => $dealer_id,
				'status'             => 'error',
			] );
			return 'error';
		}

		foreach ( $line_items as $li ) {
			$product = wc_get_product( $li['product_id'] );
			if ( ! $product ) {
				continue;
			}
			// Bill what the buyer actually paid on GunBroker, not this
			// store's current catalog price -- the two can differ (a sale
			// ended, a price change) between the listing sync and the sale.
			$line_total = $li['price'] * $li['quantity'];
			$order->add_product( $product, $li['quantity'], [ 'subtotal' => $line_total, 'total' => $line_total ] );
		}
		if ( '' !== $order_data['buyer_email'] ) {
			$order->set_billing_email( $order_data['buyer_email'] );
		}
		if ( '' !== $order_data['buyer_name'] ) {
			$parts = explode( ' ', $order_data['buyer_name'], 2 );
			$order->set_billing_first_name( $parts[0] );
			$order->set_billing_last_name( $parts[1] ?? '' );
		}
		$order->update_meta_data( '_wpistic_ffl_gunbroker_order_id', $order_data['order_id'] );
		$order->set_created_via( 'gunbroker' );
		$order->calculate_totals();

		if ( $unmapped > 0 ) {
			// A silent partial import (order marked fully "imported" while
			// quietly missing a paid-for line) would understate both the WC
			// order total and the FFL transfer/A&D-ledger count -- surface
			// it on the order itself so staff reviewing it see the gap.
			$order->add_order_note( sprintf(
				/* translators: %d: number of unmapped GunBroker line items */
				__( '%d line item(s) on this GunBroker sale had no matching product listing and were left off this order -- check the GunBroker admin page\'s raw order data.', 'advanced-ffl-checkout' ),
				$unmapped
			) );
		}

		$status = 'needs_dealer';
		if ( $dealer_id ) {
			$order->update_meta_data( Checkout::META_DEALER_ID, $dealer_id );
			$status = 'imported';
		}
		$order->save();

		if ( $dealer_id ) {
			// Fires Checkout::create_transfer_on_payment() via the exact same
			// hook a real storefront sale uses. GunBroker sales are already
			// paid for on GunBroker's side -- this marks a fact, it never
			// collects a payment through this site's own gateway.
			$order->update_status( 'processing', __( 'Imported from a GunBroker sale.', 'advanced-ffl-checkout' ) );
		} else {
			$order->update_status( 'on-hold', __( 'Imported from GunBroker -- no FFL on file matched a known dealer. Assign one from the GunBroker admin page to create the transfer.', 'advanced-ffl-checkout' ) );
		}

		self::finish_claimed_order( $order_data['order_id'], [
			'wc_order_id'        => $order->get_id(),
			'ffl_license_number' => $order_data['ffl_license_number'],
			'dealer_id'          => $dealer_id,
			'status'             => $status,
		] );

		return $status;
	}

	public static function cron_check_orders(): void {
		$result = self::pull_orders();
		if ( is_wp_error( $result ) ) {
			error_log( '[Advanced FFL Checkout] GunBroker order check failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/** Manual staff resolution for a 'needs_dealer' row -- the only path since no FFL/dealer match was found automatically. */
	public static function assign_dealer( int $gunbroker_row_id, int $dealer_id ) {
		if ( ! self::dealer_is_active( $dealer_id ) ) {
			return new \WP_Error( 'gunbroker_dealer_inactive', __( 'That dealer ID does not exist or is not active.', 'advanced-ffl-checkout' ) );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'gunbroker_orders' ) . ' WHERE id = %d', $gunbroker_row_id
		) );
		if ( ! $row || ! $row->wc_order_id ) {
			return new \WP_Error( 'gunbroker_no_order', __( 'No WooCommerce order to assign a dealer to.', 'advanced-ffl-checkout' ) );
		}
		$order = wc_get_order( (int) $row->wc_order_id );
		if ( ! $order ) {
			return new \WP_Error( 'gunbroker_order_missing', __( 'The WooCommerce order for this GunBroker sale no longer exists.', 'advanced-ffl-checkout' ) );
		}

		$order->update_meta_data( Checkout::META_DEALER_ID, $dealer_id );
		$order->save();
		$order->update_status( 'processing', __( 'Dealer manually assigned from the GunBroker admin page.', 'advanced-ffl-checkout' ) );

		$wpdb->update( DB::table( 'gunbroker_orders' ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			[ 'dealer_id' => $dealer_id, 'status' => 'imported' ],
			[ 'id' => $gunbroker_row_id ]
		);

		// Audit trail: who assigned this dealer, from where -- Checkout's own
		// transfer-creation event (actor 'system') covers the transfer
		// existing at all, but not this specific staff decision. The
		// transfer(s) this status transition just created are readable back
		// off the order meta Checkout itself writes synchronously.
		$actor    = function_exists( 'wp_get_current_user' ) ? ( wp_get_current_user()->user_login ?: 'staff' ) : 'staff';
		$actor_ip = class_exists( __NAMESPACE__ . '\\Token' ) ? Token::client_ip() : '';
		foreach ( $order->get_meta( Checkout::META_TRANSFER_ID, false ) as $meta ) {
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'transfer_id' => (int) $meta->value,
				'event_type'  => 'gunbroker_dealer_assigned',
				'notes'       => sprintf( 'Dealer #%d manually assigned to GunBroker order %s.', $dealer_id, $row->gunbroker_order_id ),
				'actor'       => $actor,
				'actor_ip'    => $actor_ip,
			] );
		}

		return true;
	}

	// ── Product edit screen fields ────────────────────────────────────────

	public function add_product_fields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( [
			'id'          => '_wpistic_ffl_gunbroker_list',
			'label'       => __( 'List on GunBroker', 'advanced-ffl-checkout' ),
			'description' => __( 'Include this product the next time GunBroker listings are synced.', 'advanced-ffl-checkout' ),
		] );
		woocommerce_wp_text_input( [
			'id'          => '_wpistic_ffl_gunbroker_category_id',
			'label'       => __( 'GunBroker Category ID', 'advanced-ffl-checkout' ),
			'description' => __( 'Use "Browse Categories" on the GunBroker admin page to find the right numeric ID — required before this product can sync.', 'advanced-ffl-checkout' ),
			'type'        => 'number',
		] );
		echo '</div>';
	}

	public function save_product_fields( int $post_id ): void {
		update_post_meta( $post_id, '_wpistic_ffl_gunbroker_list', isset( $_POST['_wpistic_ffl_gunbroker_list'] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce handles the product-save nonce.
		$category_id = sanitize_text_field( wp_unslash( $_POST['_wpistic_ffl_gunbroker_category_id'] ?? '' ) );
		// phpcs:enable
		update_post_meta( $post_id, '_wpistic_ffl_gunbroker_category_id', preg_replace( '/[^0-9]/', '', $category_id ) ?? '' );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────

	public static function ajax_save_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$environment = 'production' === sanitize_key( wp_unslash( $_POST['environment'] ?? '' ) ) ? 'production' : 'sandbox';
		$devkey      = sanitize_text_field( wp_unslash( $_POST['devkey'] ?? '' ) );
		$username    = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
		$password    = (string) ( $_POST['password'] ?? '' );
		// phpcs:enable

		$current = self::settings();
		// Blank devkey/password fields mean "keep the existing one" -- same UX convention as the distributor clients.
		if ( '' === $devkey ) {
			$devkey = $current['devkey'];
		}
		if ( '' === $password ) {
			$password = $current['password'];
		}

		update_option( self::OPTION_KEY, [
			'environment' => $environment,
			'devkey'      => G2A_Distributor_Support::encrypt_secret( $devkey, self::CONTEXT ),
			'username'    => $username,
			'password'    => G2A_Distributor_Support::encrypt_secret( $password, self::CONTEXT ),
		] );
		self::$settings_cache = null;
		delete_transient( self::TOKEN_TRANSIENT_PREFIX . 'sandbox' );
		delete_transient( self::TOKEN_TRANSIENT_PREFIX . 'production' );
		wp_send_json_success();
	}

	public static function ajax_test_connection(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		delete_transient( self::token_transient_key() );
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( [ 'message' => $token->get_error_message() ], 400 );
		}
		wp_send_json_success();
	}

	public static function ajax_browse_categories(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$result = self::get_categories();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_sync_listing(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$product_id = (int) ( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$result     = self::sync_listing( $product_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_end_listing(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$product_id = (int) ( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$result     = self::end_listing( $product_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success();
	}

	public static function ajax_sync_all_listings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		wp_send_json_success( self::sync_all_listings() );
	}

	public static function ajax_check_orders(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$result = self::pull_orders();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_assign_dealer(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$row_id      = (int) ( $_POST['row_id'] ?? 0 );
		$dealer_find = sanitize_text_field( wp_unslash( $_POST['dealer'] ?? '' ) );
		// phpcs:enable

		$dealer_id = ctype_digit( $dealer_find ) ? (int) $dealer_find : self::find_dealer_by_license( self::normalize_license( $dealer_find ) );
		if ( ! $row_id || ! $dealer_id ) {
			wp_send_json_error( [ 'message' => __( 'Enter a valid dealer ID or license number.', 'advanced-ffl-checkout' ) ], 400 );
		}

		$result = self::assign_dealer( $row_id, (int) $dealer_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success();
	}

	// ── Admin page ─────────────────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'GunBroker', 'advanced-ffl-checkout' ),
			__( '🎯 GunBroker', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$nonce      = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$settings   = self::settings();
		$last_check = get_option( 'wpistic_ffl_gunbroker_last_check', '' );

		$listings = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT l.*, p.post_title FROM ' . DB::table( 'gunbroker_listings' ) . ' l
			 LEFT JOIN ' . $wpdb->posts . " p ON p.ID = l.product_id
			 ORDER BY l.last_synced DESC LIMIT 50"
		);
		$orders = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'gunbroker_orders' ) . ' ORDER BY imported_at DESC LIMIT 50'
		);
		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🎯</span>
				<?php esc_html_e( 'GunBroker Marketplace Sync', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:800px;">
				<?php esc_html_e( 'Real client against GunBroker\'s documented REST API (api.gunbroker.com) — requires a DevKey and a GunBroker account with API access approved. Listing sync is free to run any time. Pulling orders never spends money or places a real order — a GunBroker sale is already paid for on GunBroker\'s side; this only imports it as a WooCommerce order so the existing FFL transfer/compliance pipeline picks it up automatically.', 'advanced-ffl-checkout' ); ?>
			</p>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚙️ <?php esc_html_e( 'Credentials', 'advanced-ffl-checkout' ); ?></h2>
				<form id="wpistic-ffl-gunbroker-settings-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Environment', 'advanced-ffl-checkout' ); ?></label>
						<select name="environment" style="width:140px;">
							<option value="sandbox" <?php selected( $settings['environment'], 'sandbox' ); ?>><?php esc_html_e( 'Sandbox', 'advanced-ffl-checkout' ); ?></option>
							<option value="production" <?php selected( $settings['environment'], 'production' ); ?>><?php esc_html_e( 'Production', 'advanced-ffl-checkout' ); ?></option>
						</select>
					</p>
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'DevKey', 'advanced-ffl-checkout' ); ?></label>
						<input type="password" name="devkey" placeholder="<?php echo $settings['devkey'] ? esc_attr__( '•••••••• (unchanged)', 'advanced-ffl-checkout' ) : ''; ?>" style="width:220px;">
					</p>
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Username', 'advanced-ffl-checkout' ); ?></label>
						<input type="text" name="username" value="<?php echo esc_attr( $settings['username'] ); ?>" style="width:180px;">
					</p>
					<p style="margin:0;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Password', 'advanced-ffl-checkout' ); ?></label>
						<input type="password" name="password" placeholder="<?php echo $settings['password'] ? esc_attr__( '•••••••• (unchanged)', 'advanced-ffl-checkout' ) : ''; ?>" style="width:180px;">
					</p>
					<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></p>
					<p style="margin:0;"><button type="button" class="button" id="wpistic-ffl-gunbroker-test"><?php esc_html_e( 'Test Connection', 'advanced-ffl-checkout' ); ?></button></p>
					<p style="margin:0;"><button type="button" class="button" id="wpistic-ffl-gunbroker-categories"><?php esc_html_e( 'Browse Categories', 'advanced-ffl-checkout' ); ?></button></p>
					<span id="wpistic-ffl-gunbroker-settings-msg" style="font-weight:600;"></span>
				</form>
				<pre id="wpistic-ffl-gunbroker-categories-out" style="display:none;max-height:260px;overflow:auto;background:#f6f7f7;padding:10px;margin-top:12px;font-size:12px;"></pre>
				<p class="description" style="margin-top:10px;">
					<?php esc_html_e( 'Sandbox and production each need their own separate DevKey from GunBroker — switching the environment above does not carry a DevKey over from the other one. Rate limits and DevKey approval terms are not published by GunBroker; contact their API team if a request is rejected unexpectedly.', 'advanced-ffl-checkout' ); ?>
				</p>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📋 <?php esc_html_e( 'Product Listings', 'advanced-ffl-checkout' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Check "List on GunBroker" and set a Category ID on a product\'s edit screen, then sync it here (or sync everything flagged at once).', 'advanced-ffl-checkout' ); ?></p>
				<p><button type="button" class="button button-primary" id="wpistic-ffl-gunbroker-sync-all"><?php esc_html_e( 'Sync All Flagged Products', 'advanced-ffl-checkout' ); ?></button> <span id="wpistic-ffl-gunbroker-sync-all-msg" style="font-weight:600;"></span></p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Product', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'GunBroker Item ID', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Category', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Last Synced', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $listings as $l ) : ?>
						<tr>
							<td><?php echo esc_html( $l->post_title ?: ( '#' . (int) $l->product_id ) ); ?></td>
							<td><code><?php echo esc_html( $l->gunbroker_item_id ?: '—' ); ?></code></td>
							<td><?php echo esc_html( $l->category_id ); ?></td>
							<td>
								<span style="color:<?php echo 'active' === $l->status ? '#16A34A' : ( 'error' === $l->status ? '#DC2626' : '#666' ); ?>;font-weight:700;"><?php echo esc_html( ucfirst( $l->status ) ); ?></span>
								<?php if ( $l->last_error ) : ?><br><small style="color:#DC2626;"><?php echo esc_html( $l->last_error ); ?></small><?php endif; ?>
							</td>
							<td><?php echo esc_html( $l->last_synced ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $listings ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No products flagged for GunBroker yet.', 'advanced-ffl-checkout' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🧾 <?php esc_html_e( 'Orders', 'advanced-ffl-checkout' ); ?></h2>
				<p>
					<?php esc_html_e( 'Last checked:', 'advanced-ffl-checkout' ); ?> <?php echo esc_html( $last_check ?: __( 'Never', 'advanced-ffl-checkout' ) ); ?>
					&nbsp;·&nbsp;
					<button type="button" class="button" id="wpistic-ffl-gunbroker-check-orders"><?php esc_html_e( 'Check for New Orders', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-gunbroker-orders-msg" style="font-weight:600;"></span>
				</p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'GunBroker Order', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'WC Order', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'FFL on File', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Imported', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Action', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $orders as $o ) : ?>
						<tr>
							<td><code><?php echo esc_html( $o->gunbroker_order_id ); ?></code></td>
							<td><?php echo $o->wc_order_id ? '#' . (int) $o->wc_order_id : '—'; ?></td>
							<td><?php echo esc_html( $o->ffl_license_number ?: '—' ); ?></td>
							<td><span style="color:<?php echo 'imported' === $o->status ? '#16A34A' : ( 'error' === $o->status ? '#DC2626' : '#D97706' ); ?>;font-weight:700;"><?php echo esc_html( str_replace( '_', ' ', ucfirst( $o->status ) ) ); ?></span></td>
							<td><?php echo esc_html( $o->imported_at ); ?></td>
							<td>
								<?php if ( 'needs_dealer' === $o->status ) : ?>
									<input type="text" class="wpistic-ffl-gunbroker-dealer-input" placeholder="<?php esc_attr_e( 'Dealer ID or license #', 'advanced-ffl-checkout' ); ?>" style="width:150px;">
									<button type="button" class="button wpistic-ffl-gunbroker-assign-dealer" data-row="<?php echo (int) $o->id; ?>"><?php esc_html_e( 'Assign', 'advanced-ffl-checkout' ); ?></button>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $orders ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No GunBroker orders imported yet.', 'advanced-ffl-checkout' ); ?></td></tr>
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

			document.getElementById('wpistic-ffl-gunbroker-settings-form').addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('wpistic-ffl-gunbroker-settings-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_gunbroker_save_settings');
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Saved' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-gunbroker-test').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-gunbroker-settings-msg');
				msg.textContent = 'Testing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_gunbroker_test_connection');
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Connected' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-gunbroker-categories').addEventListener('click', function(){
				var out = document.getElementById('wpistic-ffl-gunbroker-categories-out');
				out.style.display = 'block'; out.textContent = 'Loading…';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_gunbroker_browse_categories');
				postJson(fd).then(function(j){
					out.textContent = j.success ? JSON.stringify(j.data, null, 2) : ('✗ ' + ((j.data && j.data.message) || 'Failed'));
				});
			});

			document.getElementById('wpistic-ffl-gunbroker-sync-all').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-gunbroker-sync-all-msg');
				msg.textContent = 'Syncing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_gunbroker_sync_all_listings');
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			document.getElementById('wpistic-ffl-gunbroker-check-orders').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-gunbroker-orders-msg');
				msg.textContent = 'Checking…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_gunbroker_check_orders');
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			document.querySelectorAll('.wpistic-ffl-gunbroker-assign-dealer').forEach(function(btn){
				btn.addEventListener('click', function(){
					var input = btn.previousElementSibling;
					if (!input.value) { return; }
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_gunbroker_assign_dealer');
					fd.append('row_id', btn.dataset.row);
					fd.append('dealer', input.value);
					postJson(fd).then(function(j){
						if (j.success) { location.reload(); }
						else { window.alert((j.data && j.data.message) || 'Failed'); }
					});
				});
			});
		})();
		</script>
		<?php
	}
}
