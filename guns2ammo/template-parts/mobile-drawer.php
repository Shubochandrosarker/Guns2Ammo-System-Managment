<?php
/**
 * Mobile drawer  slides over from the right on <1100px viewports.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$items = [
	[ 'Range',      home_url( '/book-a-lane/' ) ],
	// Training tile points at the training index, not the CCW course.
	// CCW is a child of Training and reachable from there.
	[ 'Training',   home_url( '/training/' ) ],
	[ 'Experience', home_url( '/machine-gun/' ) ],
	[ 'Shop',       home_url( '/shop/' ) ],
	[ 'Transfers',  home_url( '/transfers/' ) ],
	[ 'Membership', home_url( '/memberships/' ) ],
	[ 'Learn',      home_url( '/blog/' ) ],
	[ 'About',      home_url( '/about/' ) ],
];
$phone = get_theme_mod( 'g2a_phone', '(602) 715-2677' );
?>
<div class="g2a-mobile" id="g2a-mobile"
	role="dialog"
	aria-modal="true"
	aria-labelledby="g2a-mobile-title"
	aria-hidden="true"
	tabindex="-1">
	<div class="hd">
		<a class="logo" id="g2a-mobile-title" href="<?php echo esc_url( home_url( '/' ) ); ?>" style="font-family: var(--font-display); font-size:22px; color: var(--color-white); letter-spacing:0.04em; text-decoration:none;">GUNS&nbsp;2&nbsp;AMMO</a>
		<button class="close" id="g2a-mclose" aria-label="<?php esc_attr_e( 'Close menu', 'guns2ammo' ); ?>"></button>
	</div>
	<nav class="g2a-mobile__links" aria-label="<?php esc_attr_e( 'Mobile navigation', 'guns2ammo' ); ?>">
		<?php foreach ( $items as $i => $it ) : ?>
			<a href="<?php echo esc_url( $it[1] ); ?>" style="--i:<?php echo (int) $i; ?>"><small><?php echo str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ); ?></small><?php echo esc_html( $it[0] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="g2a-mobile__foot">
		<a class="btn btn-ember" href="<?php echo esc_url( home_url( '/book-a-lane/' ) ); ?>"><?php esc_html_e( 'Book A Lane', 'guns2ammo' ); ?></a>
		<?php if ( is_user_logged_in() ) : ?>
			<a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/account/' ) ); ?>"><?php esc_html_e( 'My Account', 'guns2ammo' ); ?></a>
		<?php else : ?>
			<a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><?php esc_html_e( 'Sign In', 'guns2ammo' ); ?></a>
		<?php endif; ?>
		<a class="g2a-mobile__tel" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', '+1' . $phone ) ); ?>"><?php echo esc_html( str_replace( [ '(', ')', ' ', '-' ], [ '', '', '  ', '' ], $phone ) ); ?></a>
	</div>
</div>
