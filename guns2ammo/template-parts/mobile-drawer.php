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
	<?php foreach ( $items as $i => $it ) : ?>
		<a href="<?php echo esc_url( $it[1] ); ?>"><small><?php echo str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ); ?></small><?php echo esc_html( $it[0] ); ?></a>
	<?php endforeach; ?>
	<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', '+1' . $phone ) ); ?>" style="font-family: var(--font-mono); font-size:14px; color: var(--color-brass-bright); border:none; padding-top:24px; letter-spacing:0.2em;"><?php echo esc_html( str_replace( [ '(', ')', ' ', '-' ], [ '', '', '  ', '' ], $phone ) ); ?></a>
</div>
