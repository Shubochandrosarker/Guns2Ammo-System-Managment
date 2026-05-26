<?php
/**
 * Checkout form template.
 *
 * @package guns2ammo
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}
?>

<section class="g2a-wc-hero g2a-wc-hero--checkout">
	<div class="step-pip"><span class="dot cur"></span><span>Cart</span><span class="dot cur"></span><span>Details</span><span class="dot cur"></span><span>Payment</span><span class="dot"></span><span>Confirm</span></div>
	<span class="eyebrow">Secure Checkout</span>
	<h2>COMPLETE YOUR ORDER</h2>
	<p>Confirm billing details, pickup information, and payment. Orders are processed for local pickup or compliant transfer.</p>
</section>

<form name="checkout" method="post" class="checkout woocommerce-checkout g2a-checkout-form" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">
	<?php if ( $checkout->get_checkout_fields() ) : ?>
		<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

		<div class="col2-set g2a-checkout-details" id="customer_details">
			<div class="col-1 section-card">
				<h3><span class="n">1</span><?php esc_html_e( 'Billing details', 'woocommerce' ); ?></h3>
				<?php do_action( 'woocommerce_checkout_billing' ); ?>
			</div>

			<div class="col-2 section-card">
				<h3><span class="n">2</span><?php esc_html_e( 'Pickup / notes', 'guns2ammo' ); ?></h3>
				<?php do_action( 'woocommerce_checkout_shipping' ); ?>
			</div>
		</div>

		<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
	<?php endif; ?>

	<div class="g2a-order-review section-card">
		<h3 id="order_review_heading"><span class="n">3</span><?php esc_html_e( 'Your order', 'woocommerce' ); ?></h3>
		<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
		<div id="order_review" class="woocommerce-checkout-review-order">
			<?php do_action( 'woocommerce_checkout_order_review' ); ?>
		</div>
		<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
	</div>
</form>

<div class="trust-bottom">
	<span>SSL Protected</span>
	<span>Local Pickup Available</span>
	<span>FFL Compliant Transfer</span>
</div>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
