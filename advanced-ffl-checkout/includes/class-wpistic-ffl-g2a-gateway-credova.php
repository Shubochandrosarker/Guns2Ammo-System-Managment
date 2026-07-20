<?php
/**
 * G2A — Credova firearms financing/leasing gateway.
 *
 * Closes the last of the three competitor-sweep gaps this session set out
 * to evaluate (GunBroker marketplace sync, Chattanooga distributor,
 * Credova/Sezzle financing) -- Sezzle was deliberately NOT built this
 * round due to contradictory evidence on whether Sezzle's own merchant
 * policy actually permits firearms accounts (its marketing guidelines list
 * firearms as a prohibited co-branding category, while several live
 * merchant pages show real firearms retailers using it) -- that needs
 * direct confirmation with Sezzle's approvals team before building
 * against it, same "don't guess" posture as every other integration here.
 * Credova has no such ambiguity: it exists specifically to finance
 * firearms purchases.
 *
 * CONFIRMED, pulled verbatim from Credova's own real, open-source Ruby
 * gem (github.com/ammoready/credova) -- not marketing copy:
 *   - Base URLs: `https://staging-lending-api.credova.com` (sandbox) /
 *     `https://lending-api.credova.com` (production), API version `v2`.
 *   - Auth: NOT a static API key header. `POST token` with
 *     `username=...&password=...` (form-urlencoded) returns a JWT; every
 *     subsequent request sends `Authorization: Bearer {jwt}`.
 *   - Endpoints (all under `/v2/`): `POST applications` (create),
 *     `GET applications/{id}/status` (status check),
 *     `POST applications/{id}/deliveryinformation`,
 *     `POST applications/{id}/uploadinvoice` (multipart).
 *   - Create-application request fields (camelCase on the wire --
 *     confirmed by the gem's own `deep_transform_keys(&:camelize)` step):
 *     `storeCode` (required), `firstName`/`lastName` (required),
 *     `mobilePhone` (required), `email` (required), `dateOfBirth`,
 *     `address`, `products`, `redirectUrl`, `referenceNumber`. There is
 *     NO `amount`/`cartTotal` field -- the financed amount is derived
 *     from `products`, which is why this class always sends real order
 *     line items rather than a bare total.
 *   - The callback/webhook URL is a real HTTP header on the create call,
 *     confirmed verbatim: `Callback-Url` (not a body field).
 *
 * NOT independently confirmed -- same honest treatment this plugin
 * already gives Sports South's XML envelope shape and GunBroker's order
 * field names. The gem itself never names these (checked directly, not
 * inferred):
 *   - What field the CREATE response carries the application ID / the
 *     URL to send the customer to under. extract_application_field()
 *     below tries several plausible spellings defensively rather than
 *     assuming one -- if NONE match, checkout fails closed with a clear
 *     error instead of redirecting to a guessed, possibly-wrong URL.
 *   - The application status enum (e.g. is an approved/signed
 *     application's status literally "SIGNED"? "APPROVED"? something
 *     else?) -- both the approved and declined status lists below are
 *     filterable so a store can correct them once a real account's
 *     response reveals the true values, instead of this code silently
 *     misclassifying a real approval as unresolved forever.
 *   - The incoming webhook's payload shape -- the gem is create-only, it
 *     never parses a callback. rest_webhook() below extracts defensively
 *     and, just as importantly, cross-checks the payload against an
 *     order this site actually created and is actually still waiting on
 *     (not a blind trust of whatever a request claims), so an
 *     unconfirmed/wrong field name fails closed rather than open.
 *   - The `products` field's real shape -- filterable
 *     (`wpistic_ffl_credova_products_payload`) so a store can correct it
 *     once Credova's own validation response reveals the true shape.
 *
 * Because the exact approval signal isn't nailed down, this gateway never
 * marks an order paid at checkout time (unlike NMI's synchronous
 * approve/decline) -- it creates a real financing application, sends the
 * customer to Credova's own hosted flow to complete it, and holds the
 * order until the webhook (or the "Check Credova financing status" order
 * action / its daily cron sweep) confirms a real outcome. An order stuck
 * in a genuinely unrecognized status is left visible for manual staff
 * review, never silently guessed one way or the other.
 *
 * Pre-existing plugin-wide limitation, not something new this gateway
 * introduces: the FFL dealer-selection widget, its required-field
 * validation, and Compliance's age/state gate are all wired to Classic
 * Checkout hooks only (woocommerce_after_checkout_billing_form/
 * woocommerce_checkout_process) -- there is no WooCommerce Blocks/Store
 * API integration anywhere in this plugin yet. A store running Blocks
 * checkout would have that exposure for every gateway, not specifically
 * this one; it isn't fixed here since it's out of this gateway's scope.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Gateway_Credova extends \WC_Payment_Gateway {

	const BASE_URL_SANDBOX    = 'https://staging-lending-api.credova.com/v2/';
	const BASE_URL_PRODUCTION = 'https://lending-api.credova.com/v2/';
	const TOKEN_TRANSIENT_PREFIX = 'wpistic_ffl_credova_jwt_';
	const META_APPLICATION_ID    = '_wpistic_ffl_credova_application_id';
	const ORDER_ACTION           = 'wpistic_ffl_credova_check_status';
	const CRON_HOOK              = 'wpistic_ffl_credova_check_status_sweep';

	public function __construct() {
		$this->id                 = 'wpistic_ffl_credova';
		$this->method_title       = __( 'Credova (Firearms Financing)', 'advanced-ffl-checkout' );
		$this->method_description = __( 'Real firearms lease-to-own/financing via Credova (lending-api.credova.com) -- confirmed against Credova\'s own open-source Ruby SDK, not marketing copy. Sends the customer to Credova\'s own hosted application flow; the order is held until Credova confirms an outcome (webhook or manual "Check Status" action), never marked paid at checkout time.', 'advanced-ffl-checkout' );
		$this->has_fields         = false; // Redirect-based -- no card/PII fields collected on this site.
		$this->supports           = [ 'products' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' ) ?: __( 'Financing (Credova)', 'advanced-ffl-checkout' );
		$this->description = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );
		$this->sandbox      = 'yes' === $this->get_option( 'sandbox' );
		$this->username     = $this->get_option( 'username' );
		$this->password     = $this->get_option( 'password' );
		$this->store_code   = $this->get_option( 'store_code' );
		$this->webhook_secret = $this->get_option( 'webhook_secret' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

		// Manual resiliency path for a stuck on-hold order (webhook never
		// arrived, or its payload didn't match rest_webhook()'s defensive
		// extraction) -- a real "Check Status" order action, not just the
		// docblock's word for it.
		add_filter( 'woocommerce_order_actions', [ $this, 'add_order_action' ] );
		add_action( 'woocommerce_order_action_' . self::ORDER_ACTION, [ $this, 'run_order_action' ] );
	}

	/** Only offered on an order actually paid via this gateway and still on-hold -- hidden otherwise, same as WooCommerce's own built-in capture/void actions. */
	public function add_order_action( array $actions ): array {
		global $theorder;
		if ( $theorder instanceof \WC_Order && $this->id === $theorder->get_payment_method() && $theorder->has_status( 'on-hold' ) ) {
			$actions[ self::ORDER_ACTION ] = __( 'Check Credova financing status', 'advanced-ffl-checkout' );
		}
		return $actions;
	}

	public function run_order_action( \WC_Order $order ): void {
		$application_id = (string) $order->get_meta( self::META_APPLICATION_ID );
		if ( '' === $application_id ) {
			$order->add_order_note( __( 'No Credova application ID on file for this order -- cannot check status.', 'advanced-ffl-checkout' ) );
			return;
		}
		$result = $this->check_application_status( $application_id );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf( 'Credova status check failed: %s', $result->get_error_message() ) );
		}
	}

	/**
	 * Daily automated sweep of the same "Check Status" action, for stores
	 * that never notice a stuck order needs the manual action above.
	 * Capped per run so a large backlog can't run indefinitely on one cron
	 * tick, same "cap it, log what's skipped next time" convention as
	 * every catalog sync in this plugin.
	 */
	public static function cron_check_pending(): void {
		$gateway = new self();
		if ( ! $gateway->is_available() ) {
			return;
		}
		$orders = wc_get_orders( [
			'payment_method' => $gateway->id,
			'status'         => 'on-hold',
			'limit'          => (int) apply_filters( 'wpistic_ffl_credova_status_sweep_limit', 50 ),
		] );
		foreach ( $orders as $order ) {
			$application_id = (string) $order->get_meta( self::META_APPLICATION_ID );
			if ( '' !== $application_id ) {
				$gateway->check_application_status( $application_id );
			}
		}
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'advanced-ffl-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Credova financing', 'advanced-ffl-checkout' ),
				'default' => 'no',
			],
			'title' => [
				'title'       => __( 'Title', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'advanced-ffl-checkout' ),
				'default'     => __( 'Financing (Credova)', 'advanced-ffl-checkout' ),
			],
			'description' => [
				'title'   => __( 'Description', 'advanced-ffl-checkout' ),
				'type'    => 'textarea',
				'default' => __( 'Finance your purchase through Credova. You\'ll complete a short application on Credova\'s site before your order is placed.', 'advanced-ffl-checkout' ),
			],
			'sandbox' => [
				'title'   => __( 'Sandbox mode', 'advanced-ffl-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use Credova\'s staging environment', 'advanced-ffl-checkout' ),
				'default' => 'yes',
			],
			'username' => [
				'title'       => __( 'Username', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => __( 'Issued by Credova during merchant onboarding (credova.com/retailer).', 'advanced-ffl-checkout' ),
			],
			'password' => [
				'title' => __( 'Password', 'advanced-ffl-checkout' ),
				'type'  => 'password',
			],
			'store_code' => [
				'title'       => __( 'Store Code', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => __( 'Your Credova-assigned store code -- required on every application (Credova\'s "storeCode" field).', 'advanced-ffl-checkout' ),
			],
			'webhook_secret' => [
				'title'       => __( 'Webhook Shared Secret', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'REQUIRED for financing to complete automatically. Credova\'s own real webhook authentication scheme was not confirmed in this session, so this plugin requires its own shared secret on the webhook endpoint (%s) instead of accepting unauthenticated requests -- if it\'s left blank, incoming webhooks are refused (HTTP 503) and orders must be resolved with the "Check Credova Status" order action instead. If Credova\'s dashboard lets you set a bearer token or query-string secret on outgoing webhooks, put the same value here.', 'advanced-ffl-checkout' ),
					esc_url( rest_url( WPISTIC_FFL_REST_NS . '/credova/webhook' ) )
				),
			],
		];
	}

	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		return '' !== $this->username && '' !== $this->password && '' !== $this->store_code;
	}

	public function payment_fields(): void {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		if ( $this->sandbox ) {
			echo '<p style="color:#B45309;font-weight:600;">' . esc_html__( 'SANDBOX MODE — no real financing application will be created.', 'advanced-ffl-checkout' ) . '</p>';
		}
	}

	// ── Auth + low-level request ──────────────────────────────────────────

	private function base_url(): string {
		return $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
	}

	private function token_transient_key(): string {
		return self::TOKEN_TRANSIENT_PREFIX . ( $this->sandbox ? 'sandbox' : 'production' );
	}

	/** @return string|\WP_Error */
	private function token() {
		$cached = get_transient( $this->token_transient_key() );
		if ( $cached ) {
			return $cached;
		}

		$resp = wp_remote_post( $this->base_url() . 'token', [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => [ 'username' => $this->username, 'password' => $this->password ],
		] );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'credova_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach Credova: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}

		$code    = wp_remote_retrieve_response_code( $resp );
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		// Unlike every other field this class reads from a Credova response,
		// 'jwt' IS independently confirmed -- the real gem's token exchange
		// literally calls `response.fetch(:jwt)` (see class docblock), so
		// this is a single hardcoded key on purpose, not an unhedged guess.
		$jwt = is_array( $payload ) ? (string) ( $payload['jwt'] ?? '' ) : '';
		if ( $code < 200 || $code >= 300 || '' === $jwt ) {
			return new \WP_Error( 'credova_auth_failed', sprintf(
				/* translators: %d: HTTP status code */
				__( 'Credova login failed (HTTP %d).', 'advanced-ffl-checkout' ), $code
			) );
		}

		// Credova's own JWT expiry wasn't confirmed -- a conservative,
		// filterable default that re-authenticates well before any
		// plausible real expiry, cheaper than eagerly re-authing every call.
		$ttl = (int) apply_filters( 'wpistic_ffl_credova_token_ttl', 15 * MINUTE_IN_SECONDS );
		set_transient( $this->token_transient_key(), $jwt, $ttl );
		return $jwt;
	}

	/** @return array|\WP_Error */
	private function request( string $method, string $path, ?array $body = null, array $extra_headers = [] ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = [
			'timeout' => 30,
			'method'  => $method,
			'headers' => array_merge( [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token, // Confirmed real header/scheme -- see class docblock.
			], $extra_headers ),
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( $this->base_url() . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'credova_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach Credova: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}

		$code = wp_remote_retrieve_response_code( $resp );
		if ( 401 === $code ) {
			delete_transient( $this->token_transient_key() );
		}
		$raw_body = wp_remote_retrieve_body( $resp );
		$payload  = '' !== $raw_body ? json_decode( $raw_body, true ) : [];
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'credova_api_error', sprintf(
				/* translators: %d: HTTP status code */
				__( 'Credova API returned an unexpected response (HTTP %d).', 'advanced-ffl-checkout' ), $code
			) );
		}
		return is_array( $payload ) ? $payload : [];
	}

	/** Tries several plausible key spellings in priority order -- the real key was never independently confirmed (see class docblock). */
	public static function extract_field( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				return (string) $payload[ $key ];
			}
		}
		return '';
	}

	/** Builds the confirmed create-application request body from a real WC order. Pure/no I/O -- directly unit-testable against a fixture order-data array. */
	public static function build_application_payload( array $order_data, string $store_code, string $redirect_url ): array {
		$products = [];
		foreach ( $order_data['items'] as $item ) {
			$products[] = [
				'name'     => (string) $item['name'],
				'quantity' => (int) $item['quantity'],
				'price'    => (float) $item['price'],
			];
		}

		$payload = [
			'storeCode'       => $store_code,
			'firstName'       => (string) $order_data['first_name'],
			'lastName'        => (string) $order_data['last_name'],
			'mobilePhone'     => preg_replace( '/[^0-9]/', '', (string) $order_data['phone'] ) ?: '',
			'email'           => (string) $order_data['email'],
			'referenceNumber' => (string) $order_data['order_number'],
			'redirectUrl'     => $redirect_url,
			'products'        => $products,
		];

		/** Filter the full create-application payload -- escape hatch for the unconfirmed `products` shape (see class docblock). */
		return (array) apply_filters( 'wpistic_ffl_credova_products_payload', $payload, $order_data );
	}

	// ── Checkout (create application, redirect, hold the order) ──────────

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'advanced-ffl-checkout' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$order_data = [
			'first_name'   => $order->get_billing_first_name(),
			'last_name'    => $order->get_billing_last_name(),
			'phone'        => $order->get_billing_phone(),
			'email'        => $order->get_billing_email(),
			'order_number' => $order->get_order_number(),
			'items'        => array_map( function ( $item ) use ( $order ) {
				return [
					'name'     => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'price'    => $order->get_item_total( $item, false, false ),
				];
			}, array_values( $order->get_items() ) ),
		];

		$payload = self::build_application_payload( $order_data, $this->store_code, $this->get_return_url( $order ) );
		$callback_url = rest_url( WPISTIC_FFL_REST_NS . '/credova/webhook' );

		$result = $this->request( 'POST', 'applications', $payload, [ 'Callback-Url' => $callback_url ] );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( sprintf( __( 'Credova financing is currently unavailable: %s', 'advanced-ffl-checkout' ), esc_html( $result->get_error_message() ) ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$application_url = self::extract_field( $result, [ 'redirectUrl', 'redirect_url', 'applicationUrl', 'application_url', 'url', 'checkoutUrl', 'checkout_url' ] );
		$application_id  = self::extract_field( $result, [ 'id', 'applicationId', 'application_id', 'publicId', 'public_id' ] );

		if ( '' === $application_url || '' === $application_id ) {
			// Never redirect the customer, or hold the order, without a real
			// application ID to resolve it against later -- an order with no
			// META_APPLICATION_ID can never be matched by
			// resolve_application_status()'s lookup and would sit on-hold
			// forever with no way to reconcile it. Fail closed on either
			// missing field, not just the URL.
			wc_add_notice( __( 'Credova did not return a financing application link. Please try again or choose another payment method.', 'advanced-ffl-checkout' ), 'error' );
			$this->log( 'Credova create-application response had no recognizable redirect-URL/application-id field: ' . wp_json_encode( $result ) );
			return [ 'result' => 'failure' ];
		}

		$order->update_meta_data( self::META_APPLICATION_ID, $application_id );
		$order->update_status( 'on-hold', __( 'Awaiting the customer\'s Credova financing application.', 'advanced-ffl-checkout' ) );
		$order->save();

		return [ 'result' => 'success', 'redirect' => $application_url ];
	}

	// ── Status resolution (webhook + manual fallback) ─────────────────────

	/** Store-supplied lists since the real status enum wasn't independently confirmed (see class docblock). Matched case-insensitively. */
	private static function approved_statuses(): array {
		return (array) apply_filters( 'wpistic_ffl_credova_approved_statuses', [ 'signed', 'approved', 'complete', 'completed', 'active' ] );
	}

	private static function declined_statuses(): array {
		return (array) apply_filters( 'wpistic_ffl_credova_declined_statuses', [ 'declined', 'denied', 'cancelled', 'canceled', 'expired', 'rejected' ] );
	}

	/**
	 * Claims exclusive rights to resolve one application_id, using the real
	 * UNIQUE index MySQL already puts on wp_options.option_name as the
	 * atomic gate -- not a PHP-side check-then-act read. Two concurrent
	 * resolutions for the same application_id (a webhook retry racing a
	 * manual "Check Status" click, or two rapid webhook deliveries) can
	 * both reach resolve_application_status(), but only one of them wins
	 * this INSERT IGNORE; the loser's caller must not mutate the order.
	 */
	private function claim_application_resolution( string $application_id ): bool {
		global $wpdb;
		$claimed = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			'wpistic_ffl_credova_resolved_' . $application_id,
			current_time( 'mysql' )
		) );
		return (bool) $claimed;
	}

	/**
	 * Applies a resolved status to the order that's actually still waiting
	 * on Credova for it -- never trusts a caller-supplied order ID/number
	 * alone; only orders using this gateway and still 'on-hold' are
	 * eligible, so a wrong/forged application reference can't touch an
	 * unrelated or already-resolved order. The atomic claim above is only
	 * taken right before an actual state-changing mutation, never before
	 * -- an unrecognized status makes no claim, so it's always safe to
	 * retry once a corrected status-list filter is in place.
	 */
	public function resolve_application_status( string $application_id, string $raw_status ): bool {
		if ( '' === $application_id || '' === $raw_status ) {
			return false;
		}
		$orders = wc_get_orders( [
			'payment_method' => $this->id,
			'status'         => 'on-hold',
			'limit'          => 1,
			'meta_key'       => self::META_APPLICATION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $application_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		$order = $orders[0] ?? null;
		if ( ! $order ) {
			return false;
		}

		$status = strtolower( trim( $raw_status ) );
		if ( in_array( $status, self::approved_statuses(), true ) ) {
			if ( ! $this->claim_application_resolution( $application_id ) ) {
				return false; // Already resolved by a concurrent call.
			}
			$order->add_order_note( sprintf( 'Credova application %s approved (status: %s).', $application_id, $raw_status ) );
			$order->payment_complete( $application_id );
			WC()->cart && WC()->cart->empty_cart();
			return true;
		}
		if ( in_array( $status, self::declined_statuses(), true ) ) {
			if ( ! $this->claim_application_resolution( $application_id ) ) {
				return false; // Already resolved by a concurrent call.
			}
			$order->update_status( 'cancelled', sprintf( __( 'Credova application %1$s was not approved (status: %2$s).', 'advanced-ffl-checkout' ), $application_id, $raw_status ) );
			return true;
		}

		// An unrecognized status is a real possibility given the enum was
		// never independently confirmed -- leave the order visibly on-hold
		// with a note rather than silently guessing which way to resolve it.
		$order->add_order_note( sprintf( 'Credova sent an unrecognized application status "%s" -- review manually (approved/declined synonym lists are filterable).', $raw_status ) );
		return false;
	}

	// ── Webhook ────────────────────────────────────────────────────────────

	/**
	 * Wired from a dedicated instance in the main bootstrap file's own
	 * `rest_api_init` hook, not from this class's constructor -- WooCommerce
	 * only instantiates its registered gateway classes when its own
	 * payment-gateways subsystem actually loads, which a request that hits
	 * nothing but this custom REST route can't be relied on to guarantee.
	 * Settings (webhook_secret, etc.) come from the same wp_options row
	 * either way, so a second, throwaway instance for routing purposes
	 * behaves identically to WC's own.
	 */
	public function register_routes(): void {
		register_rest_route( WPISTIC_FFL_REST_NS, '/credova/webhook', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_webhook' ],
		] );
	}

	/**
	 * The webhook secret is REQUIRED, not optional -- an unconfigured
	 * secret refuses the request outright (503), same posture
	 * G2A_Id_Verification::rest_webhook() already uses. This endpoint's
	 * `permission_callback` is `__return_true` (Credova's own real webhook
	 * auth scheme was never confirmed, so WordPress-level auth can't be
	 * required), which means the shared-secret check below is the ONLY
	 * gate standing between an anonymous POST and a real
	 * `$order->payment_complete()` call -- it must never be skippable.
	 */
	public function rest_webhook( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		if ( '' === $this->webhook_secret ) {
			return new \WP_Error( 'webhook_disabled', 'Credova webhook secret not configured.', [ 'status' => 503 ] );
		}

		$bearer      = '';
		$auth_header = $req->get_header( 'authorization' ) ?: '';
		if ( 0 === stripos( $auth_header, 'Bearer ' ) ) {
			$bearer = trim( substr( $auth_header, 7 ) );
		}
		$query_secret = (string) $req->get_param( 'secret' );
		if ( ! hash_equals( $this->webhook_secret, $bearer ) && ! hash_equals( $this->webhook_secret, $query_secret ) ) {
			return new \WP_Error( 'bad_auth', 'Invalid webhook credentials.', [ 'status' => 401 ] );
		}

		$payload = json_decode( $req->get_body(), true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'bad_payload', 'Expected a JSON body.', [ 'status' => 400 ] );
		}

		$application_id = self::extract_field( $payload, [ 'id', 'applicationId', 'application_id', 'publicId', 'public_id' ] );
		$status         = self::extract_field( $payload, [ 'status', 'applicationStatus', 'application_status' ] );

		if ( '' === $application_id || '' === $status ) {
			return new \WP_Error( 'bad_payload', 'Payload has no recognizable application id/status field.', [ 'status' => 400 ] );
		}

		$resolved = $this->resolve_application_status( $application_id, $status );
		return rest_ensure_response( [ 'ok' => true, 'resolved' => $resolved ] );
	}

	// ── Resiliency: poll the confirmed status-check endpoint ──────────────

	/**
	 * Reachable two ways -- the "Check Credova financing status" order
	 * action (constructor, above) for a staff member to trigger by hand,
	 * and the daily cron_check_pending() sweep for stores that never
	 * notice a stuck order needs it -- for stores whose webhook never
	 * arrives or whose payload shape doesn't match rest_webhook()'s
	 * defensive extraction. Polls the one confirmed status endpoint
	 * directly rather than waiting indefinitely on an unconfirmed webhook.
	 */
	public function check_application_status( string $application_id ) {
		$result = $this->request( 'GET', 'applications/' . rawurlencode( $application_id ) . '/status' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$status = self::extract_field( $result, [ 'status', 'applicationStatus', 'application_status' ] );
		if ( '' === $status ) {
			return new \WP_Error( 'credova_no_status', __( 'Credova\'s status response had no recognizable status field.', 'advanced-ffl-checkout' ) );
		}
		return [ 'status' => $status, 'resolved' => $this->resolve_application_status( $application_id, $status ) ];
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, [ 'source' => 'wpistic-ffl-credova' ] );
		}
	}
}
