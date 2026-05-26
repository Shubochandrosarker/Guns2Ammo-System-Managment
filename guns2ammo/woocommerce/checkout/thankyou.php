<?php
/**
 * Order received template.
 *
 * @package guns2ammo
 */
defined( 'ABSPATH' ) || exit;
?>

<section class="g2a-thankyou-shell">
	<div class="checkdraw" aria-hidden="true">
		<svg viewBox="0 0 64 64" width="72" height="72"><path d="M18 33.5 27.5 43 47 22"/></svg>
	</div>

	<?php if ( $order ) : ?>
		<?php if ( $order->has_status( 'failed' ) ) : ?>
			<h2><?php esc_html_e( 'PAYMENT NEEDS ATTENTION', 'woocommerce' ); ?></h2>
			<p class="sub"><?php esc_html_e( 'Your payment was not completed. Try again or contact the range desk for help.', 'guns2ammo' ); ?></p>
			<div class="ty-actions">
				<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="btn btn-ember pay"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="btn btn-brass"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<h2><?php esc_html_e( 'ORDER RECEIVED', 'woocommerce' ); ?></h2>
			<p class="sub"><?php esc_html_e( 'Your order is confirmed. Watch your email for pickup, transfer, and range desk instructions.', 'guns2ammo' ); ?></p>

			<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details g2a-order-overview">
				<li class="woocommerce-order-overview__order order">
					<span><?php esc_html_e( 'Order number', 'woocommerce' ); ?></span>
					<strong><?php echo esc_html( $order->get_order_number() ); ?></strong>
				</li>
				<li class="woocommerce-order-overview__date date">
					<span><?php esc_html_e( 'Date', 'woocommerce' ); ?></span>
					<strong><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></strong>
				</li>
				<?php if ( is_user_logged_in() && $order->get_user_id() === get_current_user_id() && $order->get_billing_email() ) : ?>
					<li class="woocommerce-order-overview__email email">
						<span><?php esc_html_e( 'Email', 'woocommerce' ); ?></span>
						<strong><?php echo esc_html( $order->get_billing_email() ); ?></strong>
					</li>
				<?php endif; ?>
				<li class="woocommerce-order-overview__total total">
					<span><?php esc_html_e( 'Total', 'woocommerce' ); ?></span>
					<strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
				</li>
				<?php if ( $order->get_payment_method_title() ) : ?>
					<li class="woocommerce-order-overview__payment-method method">
						<span><?php esc_html_e( 'Payment method', 'woocommerce' ); ?></span>
						<strong><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></strong>
					</li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>

		<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		<?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>
	<?php else : ?>
		<h2><?php esc_html_e( 'ORDER RECEIVED', 'woocommerce' ); ?></h2>
		<p class="sub woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received"><?php echo esc_html( apply_filters( 'woocommerce_thankyou_order_received_text', __( 'Thank you. Your order has been received.', 'woocommerce' ), null ) ); ?></p>
	<?php endif; ?>

	<div class="next-grid">
		<a class="next-card" href="<?php echo esc_url( home_url( '/book-a-lane/' ) ); ?>">
			<div class="n">01</div><div class="t">Book A Lane</div><div class="d">Reserve your next range session.</div><div class="arrow">Reserve Now</div>
		</a>
		<a class="next-card" href="<?php echo esc_url( home_url( '/training/' ) ); ?>">
			<div class="n">02</div><div class="t">Browse Training</div><div class="d">CCW and skill-building courses.</div><div class="arrow">View Courses</div>
		</a>
		<a class="next-card" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
			<div class="n">03</div><div class="t">Keep Shopping</div><div class="d">Ammo, firearms, accessories.</div><div class="arrow">Visit Shop</div>
		</a>
	</div>
</section>
