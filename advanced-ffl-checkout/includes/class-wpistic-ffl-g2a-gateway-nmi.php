<?php
/**
 * G2A — NMI (Network Merchants Inc) payment gateway adapter (Exhibit 07).
 *
 * Stripe and PayPal routinely terminate firearms-merchant accounts — this
 * plugin previously had zero built-in guidance for a firearms-tolerant
 * processor beyond an inert `wpistic_ffl_filter_gateways` passthrough
 * filter. Most real firearms-friendly high-risk processors (PaymentCloud,
 * Durango Merchant Services, Easy Pay Direct, Signature Payments) are
 * white-labeled on top of NMI's gateway, so a first-party NMI adapter has
 * the broadest real-world applicability of any single processor to target.
 *
 * PCI scope: card data is tokenized CLIENT-SIDE via NMI's Collect.js —
 * the raw PAN/CVV never touch this site's server, keeping the merchant in
 * PCI SAQ A-EP scope (not the much heavier SAQ D). The server only ever
 * sees the resulting single-use payment token.
 *
 * Because different NMI-white-label resellers host their own branded copy
 * of Collect.js and their own API endpoint, both are admin-configurable —
 * this adapter targets NMI's documented Direct Post / Collect.js contract
 * generically, not one specific reseller's exact hostname.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Gateway_Nmi extends \WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'wpistic_ffl_nmi';
		$this->method_title       = __( 'NMI (Firearms-Friendly)', 'advanced-ffl-checkout' );
		$this->method_description = __( 'Card processing via NMI (Network Merchants Inc) — the gateway most real firearms-tolerant high-risk processors (PaymentCloud, Durango Merchant Services, Easy Pay Direct) are white-labeled on top of. Card data is tokenized client-side via Collect.js; this site never sees or stores a raw card number.', 'advanced-ffl-checkout' );
		$this->has_fields         = true;
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled      = $this->get_option( 'enabled' );
		$this->testmode     = 'yes' === $this->get_option( 'testmode' );
		$this->security_key = $this->get_option( 'security_key' );
		$this->tokenization_key = $this->get_option( 'tokenization_key' );
		$this->collectjs_url    = $this->get_option( 'collectjs_url' ) ?: 'https://secure.networkmerchants.com/token/Collect.js';
		$this->api_url           = $this->get_option( 'api_url' ) ?: 'https://secure.networkmerchants.com/api/transact.php';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_collectjs' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'advanced-ffl-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable NMI card payments', 'advanced-ffl-checkout' ),
				'default' => 'no',
			],
			'title' => [
				'title'       => __( 'Title', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'advanced-ffl-checkout' ),
				'default'     => __( 'Credit / Debit Card', 'advanced-ffl-checkout' ),
			],
			'description' => [
				'title'   => __( 'Description', 'advanced-ffl-checkout' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with your credit or debit card.', 'advanced-ffl-checkout' ),
			],
			'testmode' => [
				'title'   => __( 'Test mode', 'advanced-ffl-checkout' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable test mode (use your processor\'s sandbox security key)', 'advanced-ffl-checkout' ),
				'default' => 'yes',
			],
			'security_key' => [
				'title'       => __( 'Security Key', 'advanced-ffl-checkout' ),
				'type'        => 'password',
				'description' => __( 'Your NMI (or reseller) gateway Security Key — used server-side to run the transaction. Never sent to the browser.', 'advanced-ffl-checkout' ),
			],
			'tokenization_key' => [
				'title'       => __( 'Collect.js Tokenization Key', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => __( 'Your processor\'s PUBLIC Collect.js tokenization key — this one is safe to expose client-side, it can only create tokens, not run transactions.', 'advanced-ffl-checkout' ),
			],
			'collectjs_url' => [
				'title'       => __( 'Collect.js URL', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => __( 'Most NMI resellers host a branded copy at their own domain — check your processor\'s integration docs. Defaults to NMI\'s own.', 'advanced-ffl-checkout' ),
				'placeholder' => 'https://secure.networkmerchants.com/token/Collect.js',
			],
			'api_url' => [
				'title'       => __( 'Transaction API URL', 'advanced-ffl-checkout' ),
				'type'        => 'text',
				'description' => __( 'The Direct Post transact.php endpoint for your processor. Defaults to NMI\'s own.', 'advanced-ffl-checkout' ),
				'placeholder' => 'https://secure.networkmerchants.com/api/transact.php',
			],
		];
	}

	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		return ! empty( $this->security_key ) && ! empty( $this->tokenization_key );
	}

	public function maybe_enqueue_collectjs(): void {
		if ( ! is_checkout() || ! $this->is_available() ) {
			return;
		}
		wp_enqueue_script( 'wpistic-ffl-nmi-collectjs', $this->collectjs_url, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_add_inline_script( 'wpistic-ffl-nmi-collectjs', $this->collectjs_inline_config(), 'after' );
	}

	private function collectjs_inline_config(): string {
		$key = esc_js( $this->tokenization_key );
		// Field selectors match the payment_fields() markup below. Collect.js
		// intercepts the WC checkout form's submit, tokenizes in an iframe
		// (card data never enters this page's DOM/JS context at all), and
		// only then lets the real submit proceed with the token attached.
		return <<<JS
document.addEventListener('DOMContentLoaded', function () {
	if (typeof CollectJS === 'undefined') { return; }
	CollectJS.configure({
		paymentSelector: '#place_order',
		variant: 'inline',
		styleSniffer: true,
		tokenizationKey: '{$key}',
		fields: {
			ccnumber: { selector: '#wpistic-ffl-nmi-ccnumber', placeholder: 'Card Number' },
			ccexp:    { selector: '#wpistic-ffl-nmi-ccexp',    placeholder: 'MM / YY' },
			cvv:      { selector: '#wpistic-ffl-nmi-cvv',      placeholder: 'CVV' }
		},
		callback: function (response) {
			var input = document.getElementById('wpistic_ffl_nmi_payment_token');
			if (input) { input.value = response.token; }
			var form = document.querySelector('form.checkout, form#order_review');
			if (form) { form.submit(); }
		}
	});
});
JS;
	}

	public function payment_fields(): void {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		if ( $this->testmode ) {
			echo '<p style="color:#B45309;font-weight:600;">' . esc_html__( 'TEST MODE — no real charge will be made.', 'advanced-ffl-checkout' ) . '</p>';
		}
		?>
		<div id="wpistic-ffl-nmi-fields" style="display:grid;gap:8px;max-width:360px;">
			<div id="wpistic-ffl-nmi-ccnumber" style="height:40px;border:1px solid #ccd0d4;border-radius:4px;padding:4px 8px;"></div>
			<div style="display:flex;gap:8px;">
				<div id="wpistic-ffl-nmi-ccexp" style="flex:1;height:40px;border:1px solid #ccd0d4;border-radius:4px;padding:4px 8px;"></div>
				<div id="wpistic-ffl-nmi-cvv" style="flex:1;height:40px;border:1px solid #ccd0d4;border-radius:4px;padding:4px 8px;"></div>
			</div>
			<input type="hidden" id="wpistic_ffl_nmi_payment_token" name="wpistic_ffl_nmi_payment_token" value="">
		</div>
		<?php
	}

	/**
	 * The checkout form's own submit is intercepted by Collect.js (see the
	 * callback above) — by the time this runs server-side, a payment token
	 * should already be attached. If it isn't, the customer's card fields
	 * never tokenized (JS error, ad-blocker, etc.) and we fail closed
	 * rather than attempting a chargeless "success."
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'advanced-ffl-checkout' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$token = isset( $_POST['wpistic_ffl_nmi_payment_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wpistic_ffl_nmi_payment_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $token ) {
			wc_add_notice( __( 'Your card could not be verified. Please re-enter your card details and try again.', 'advanced-ffl-checkout' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$resp = wp_remote_post( $this->api_url, [
			'timeout' => 30,
			'body'    => [
				'security_key'   => $this->security_key,
				'type'           => 'sale',
				'payment_token'  => $token,
				'amount'         => number_format( (float) $order->get_total(), 2, '.', '' ),
				'orderid'        => (string) $order->get_order_number(),
				'orderdescription' => sprintf( 'Order #%s', $order->get_order_number() ),
				'email'          => $order->get_billing_email(),
				'firstname'      => $order->get_billing_first_name(),
				'lastname'       => $order->get_billing_last_name(),
				'address1'       => $order->get_billing_address_1(),
				'city'           => $order->get_billing_city(),
				'state'          => $order->get_billing_state(),
				'zip'            => $order->get_billing_postcode(),
				'country'        => $order->get_billing_country(),
			],
		] );

		if ( is_wp_error( $resp ) ) {
			wc_add_notice( __( 'Payment processor is currently unreachable. Please try again shortly.', 'advanced-ffl-checkout' ), 'error' );
			$this->log( 'Network error contacting NMI: ' . $resp->get_error_message() );
			return [ 'result' => 'failure' ];
		}

		// transact.php responds as a URL-encoded query string, not JSON —
		// this is the actual, unchanged shape of NMI's Direct Post API.
		parse_str( wp_remote_retrieve_body( $resp ), $result );

		$response_code = (string) ( $result['response'] ?? '' );
		if ( '1' !== $response_code ) {
			$reason = (string) ( $result['responsetext'] ?? 'Card declined.' );
			wc_add_notice( sprintf( __( 'Payment failed: %s', 'advanced-ffl-checkout' ), esc_html( $reason ) ), 'error' );
			$order->add_order_note( sprintf( 'NMI decline: %s (code %s)', $reason, $result['response_code'] ?? '?' ) );
			return [ 'result' => 'failure' ];
		}

		$transaction_id = (string) ( $result['transactionid'] ?? '' );
		$order->payment_complete( $transaction_id );
		$order->add_order_note( sprintf( 'NMI payment approved. Transaction ID: %s', $transaction_id ) );
		$order->update_meta_data( '_wpistic_ffl_nmi_transaction_id', $transaction_id );
		$order->save();

		WC()->cart->empty_cart();

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	/**
	 * NMI refund transaction type. WooCommerce calls this from the admin
	 * order screen's "Refund" panel.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'invalid_order', __( 'Order not found.', 'advanced-ffl-checkout' ) );
		}
		$transaction_id = $order->get_meta( '_wpistic_ffl_nmi_transaction_id' );
		if ( ! $transaction_id ) {
			return new \WP_Error( 'no_transaction', __( 'No NMI transaction ID on this order to refund.', 'advanced-ffl-checkout' ) );
		}

		$resp = wp_remote_post( $this->api_url, [
			'timeout' => 30,
			'body'    => array_filter( [
				'security_key'  => $this->security_key,
				'type'          => 'refund',
				'transactionid' => $transaction_id,
				'amount'        => $amount ? number_format( (float) $amount, 2, '.', '' ) : null,
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'nmi_unreachable', $resp->get_error_message() );
		}
		parse_str( wp_remote_retrieve_body( $resp ), $result );
		if ( '1' !== (string) ( $result['response'] ?? '' ) ) {
			return new \WP_Error( 'refund_failed', (string) ( $result['responsetext'] ?? 'Refund failed.' ) );
		}
		$order->add_order_note( sprintf( 'NMI refund processed: %s (ref transaction %s)', wc_price( $amount ), $transaction_id ) );
		return true;
	}

	private function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, [ 'source' => 'wpistic-ffl-nmi' ] );
		}
	}
}
