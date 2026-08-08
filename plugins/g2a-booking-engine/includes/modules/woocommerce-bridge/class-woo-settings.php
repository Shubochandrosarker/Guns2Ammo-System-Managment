<?php
/**
 * WooCommerce Bridge — admin settings tab.
 *
 * @package G2AB\Modules\WooBridge
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Woo_Settings {

	public function __construct() {
		add_filter( 'g2ab_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'g2ab_settings_render_woocommerce', array( $this, 'render' ) );
		add_action( 'admin_post_g2ab_save_woo', array( $this, 'save' ) );
		add_action( 'admin_post_g2ab_create_woo_product', array( $this, 'create_product' ) );
	}

	public function register_tab( $tabs ) {
		$tabs['woocommerce'] = 'WooCommerce';
		return $tabs;
	}

	public function render() {
		$wc_active = class_exists( 'WooCommerce' );
		$product_id = (int) get_option( G2AB_Gateway_Woocommerce::OPT_PRODUCT_ID, 0 );
		$product = $product_id && $wc_active ? wc_get_product( $product_id ) : null;

		$msg = '';
		if ( ! empty( $_GET['saved'] ) ) $msg = '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
		if ( ! empty( $_GET['product_created'] ) ) $msg = '<div class="notice notice-success is-dismissible"><p>Booking placeholder product created.</p></div>';
		?>
		<style>
			.g2ab-wc__panel{background:#fff;border:1px solid #d0d4d9;padding:24px;margin-bottom:24px;}
			.g2ab-wc__row{margin-bottom:18px;max-width:760px;}
			.g2ab-wc__row label{display:block;font-weight:600;margin-bottom:6px;}
			.g2ab-wc__pill{display:inline-block;padding:4px 10px;border-radius:2px;color:#fff;font-size:11px;letter-spacing:.06em;font-weight:700;}
			.g2ab-wc__list{margin:0;padding:0;list-style:none;}
			.g2ab-wc__list li{padding:8px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;}
		</style>
		<?php echo $msg; // phpcs:ignore ?>

		<div class="g2ab-wc__panel">
			<h2 style="margin-top:0;">WooCommerce Status
				<?php if ( $wc_active ) : ?>
					<span class="g2ab-wc__pill" style="background:#4CAF50;">CONNECTED</span>
				<?php else : ?>
					<span class="g2ab-wc__pill" style="background:#C62828;">NOT INSTALLED</span>
				<?php endif; ?>
			</h2>

			<?php if ( ! $wc_active ) : ?>
				<p>WooCommerce is not active. Install and activate WooCommerce, then return to this tab.</p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>">Install WooCommerce</a>
				<?php return; ?>
			<?php endif; ?>

			<p>Bookings can route through WooCommerce checkout. Customer pays with any active WC gateway (Stripe, PayPal, Square, Authorize.net, manual, etc.). When the WC order is paid, the booking is auto-confirmed.</p>

			<h3 style="margin-top:24px;">Active WC Payment Gateways</h3>
			<ul class="g2ab-wc__list">
				<?php foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gw ) : ?>
					<li>
						<span><strong><?php echo esc_html( $gw->get_method_title() ); ?></strong> — <?php echo esc_html( $gw->id ); ?></span>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $gw->id ) ); ?>">Configure</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<p style="margin-top:14px;font-size:13px;color:#666;">All of the above will appear at the customer's checkout when they pay for a booking.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'g2ab_save_woo', '_g2ab_nonce' ); ?>
			<input type="hidden" name="action" value="g2ab_save_woo" />

			<div class="g2ab-wc__panel">
				<h2 style="margin-top:0;">Placeholder Booking Product</h2>
				<p>WooCommerce orders need a product. We use a single hidden virtual product to represent any booking — the line item is renamed to match the booking details on each checkout.</p>

				<?php if ( $product ) : ?>
					<div class="g2ab-wc__row">
						<p>
							<strong>Linked product:</strong> <?php echo esc_html( $product->get_name() ); ?>
							(ID #<?php echo (int) $product_id; ?>) —
							<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>">Edit</a>
						</p>
					</div>
					<div class="g2ab-wc__row">
						<label>Change Product ID</label>
						<input type="number" name="product_id" value="<?php echo (int) $product_id; ?>" />
					</div>
				<?php else : ?>
					<p>No placeholder product yet.</p>
				<?php endif; ?>
			</div>

			<button class="button button-primary">Save Settings</button>
		</form>

		<?php if ( ! $product ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px;">
				<?php wp_nonce_field( 'g2ab_create_woo_product', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_create_woo_product" />
				<button class="button">Auto-create Placeholder Product</button>
			</form>
		<?php endif; ?>

		<div class="g2ab-wc__panel" style="background:#FFF8F0;border-color:#E8802F;">
			<h3 style="margin-top:0;">How it works</h3>
			<ol style="line-height:1.8;">
				<li>Customer fills booking form → clicks Reserve.</li>
				<li>Plugin creates a WC order (status: <code>pending</code>) with the booking details as the line item.</li>
				<li>Customer is redirected to <code>/checkout/order-pay/{order_id}</code> — WC's "pay for order" page.</li>
				<li>Customer picks any active WC gateway and pays.</li>
				<li>WC fires <code>woocommerce_order_status_processing</code> → bridge marks booking as <strong>paid</strong> + fires <code>g2ab_booking_paid</code>.</li>
				<li>Email Automation module sends payment receipt with PDF invoice attached.</li>
			</ol>
		</div>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_woo', '_g2ab_nonce' );

		$pid = absint( $_POST['product_id'] ?? 0 );
		update_option( G2AB_Gateway_Woocommerce::OPT_PRODUCT_ID, $pid );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'woocommerce', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function create_product() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_create_woo_product', '_g2ab_nonce' );
		if ( ! class_exists( 'WC_Product_Simple' ) ) wp_die( 'WooCommerce not active.' );

		$product = new WC_Product_Simple();
		$product->set_name( 'Range Booking' );
		$product->set_status( 'private' ); // hidden from catalog
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_price( 0 );
		$product->set_regular_price( 0 );
		$product->set_sold_individually( false );
		$product->set_description( 'Auto-generated placeholder for G2A booking checkout. Do not delete.' );
		$pid = $product->save();

		update_option( G2AB_Gateway_Woocommerce::OPT_PRODUCT_ID, $pid );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'woocommerce', 'product_created' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
