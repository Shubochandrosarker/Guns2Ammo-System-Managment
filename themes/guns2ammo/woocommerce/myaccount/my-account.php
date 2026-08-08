<?php
/**
 * My account dashboard wrapper.
 *
 * @package guns2ammo
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_navigation' );
?>

<section class="g2a-account-shell">
	<div class="ac-h">
		<h2><?php esc_html_e( 'MY ACCOUNT', 'woocommerce' ); ?></h2>
		<span class="badge active"><?php esc_html_e( 'Range Customer', 'guns2ammo' ); ?></span>
	</div>

	<div class="dash woocommerce-MyAccount-wrap">
		<aside class="dash-side woocommerce-MyAccount-navigation">
			<div class="me">
				<div class="av b"><?php echo esc_html( strtoupper( substr( wp_get_current_user()->display_name ?: wp_get_current_user()->user_login, 0, 2 ) ) ); ?></div>
				<div>
					<div class="who"><?php echo esc_html( wp_get_current_user()->display_name ?: wp_get_current_user()->user_login ); ?></div>
					<div class="since"><?php esc_html_e( 'Customer Account', 'guns2ammo' ); ?></div>
				</div>
			</div>
			<?php wc_get_template( 'myaccount/navigation.php' ); ?>
		</aside>

		<main class="woocommerce-MyAccount-content panel">
			<?php
			do_action( 'woocommerce_account_content' );
			?>
		</main>
	</div>
</section>

<?php do_action( 'woocommerce_after_account_navigation' ); ?>
