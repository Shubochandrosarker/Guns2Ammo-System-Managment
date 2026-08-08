<?php
/**
 * My account navigation.
 *
 * @package guns2ammo
 */
defined( 'ABSPATH' ) || exit;

$items = wc_get_account_menu_items();
?>

<nav class="woocommerce-MyAccount-navigation-nav" aria-label="<?php esc_attr_e( 'Account pages', 'woocommerce' ); ?>">
	<?php foreach ( $items as $endpoint => $label ) : ?>
		<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>" class="<?php echo wc_get_account_menu_item_classes( $endpoint ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
	<?php endforeach; ?>
</nav>
