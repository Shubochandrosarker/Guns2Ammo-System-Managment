<?php
/**
 * Primary navigation  matches design (Range / Training / Experience / Shop / Transfers / Membership / Learn / About)
 *
 * Editable later via WP menu locations + ACF for submenus.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$here = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?? '/', '/' );

// Live WooCommerce product-category counts (cached 1hr, busted on
// product edits) — replaces the old hard-coded "24/12/38/21" meta
// numbers that drifted out of sync with the catalog + homepage.
$g2a_shop_counts = function_exists( 'g2a_biz_category_counts' ) ? g2a_biz_category_counts() : array();
$g2a_shop_meta   = function ( $slug ) use ( $g2a_shop_counts ) {
	return ( isset( $g2a_shop_counts[ $slug ] ) && (int) $g2a_shop_counts[ $slug ] > 0 )
		? sprintf( '%02d', (int) $g2a_shop_counts[ $slug ] )
		: '';
};

$nav = [
	[ 'label' => 'Range',      'href' => home_url( '/book-a-lane/' ),  'sub' => [
		[ 'label' => 'Book A Lane',     'href' => home_url( '/book-a-lane/' ),         'meta' => '01' ],
		[ 'label' => 'Pricing & Fees',  'href' => home_url( '/book-a-lane/#pricing' ), 'meta' => '02' ],
		[ 'label' => 'Range Rules',     'href' => home_url( '/range-safety/' ),        'meta' => '03' ],
		[ 'label' => 'Ladies Tuesday',  'href' => home_url( '/ladies-tuesday/' ),      'meta' => '04' ],
	] ],
	[ 'label' => 'Training',   'href' => home_url( '/training/' ), 'sub' => [
		[ 'label' => 'Course Catalog',      'href' => home_url( '/training/' ),                   'meta' => 'ALL' ],
		[ 'label' => 'Basic Handgun',       'href' => home_url( '/training/basic-handgun/' ),     'meta' => '01' ],
		[ 'label' => 'Arizona CCW',         'href' => home_url( '/arizona-ccw-certification/' ),                        'meta' => '02' ],
		[ 'label' => 'California CCW',      'href' => home_url( '/training/california-ccw/' ),    'meta' => '03' ],
		[ 'label' => 'Defensive Pistol',    'href' => home_url( '/training/defensive-pistol/' ),  'meta' => '04' ],
		[ 'label' => 'Private Instruction', 'href' => home_url( '/private-instruction/' ),        'meta' => '05' ],
	] ],
	[ 'label' => 'Experience', 'href' => home_url( '/machine-gun/' ), 'sub' => [
		[ 'label' => 'Machine Gun Packages', 'href' => home_url( '/machine-gun/' ),        'meta' => 'ALL' ],
		[ 'label' => 'MP5 Submachine Gun',   'href' => home_url( '/machine-gun/mp5/' ),    'meta' => '01' ],
		[ 'label' => 'M16 Rifle',            'href' => home_url( '/machine-gun/m16/' ),    'meta' => '02' ],
		[ 'label' => 'AK-47 Rifle',          'href' => home_url( '/machine-gun/ak-47/' ),  'meta' => '03' ],
	] ],
	[ 'label' => 'Shop',       'href' => home_url( '/shop/' ), 'sub' => [
		[ 'label' => 'All Products', 'href' => home_url( '/shop/' ),                      'meta' => 'ALL' ],
		[ 'label' => 'Handguns',     'href' => home_url( '/collections/handguns/' ),   'meta' => $g2a_shop_meta( 'handguns' ) ],
		[ 'label' => 'Rifles',       'href' => home_url( '/collections/rifles/' ),     'meta' => $g2a_shop_meta( 'rifles' ) ],
		[ 'label' => 'Ammunition',   'href' => home_url( '/collections/ammunition/' ), 'meta' => $g2a_shop_meta( 'ammunition' ) ],
		[ 'label' => 'Magazines',    'href' => home_url( '/collections/magazines/' ),  'meta' => $g2a_shop_meta( 'magazines' ) ],
	] ],
	[ 'label' => 'Transfers',  'href' => home_url( '/transfers/' ), 'sub' => [
		[ 'label' => 'FFL Transfer',      'href' => home_url( '/transfers/' ),               'meta' => '01' ],
		[ 'label' => 'NFA / Class III',   'href' => home_url( '/ffl-services/#nfa' ),        'meta' => '02' ],
		[ 'label' => 'Firearm Shipping',  'href' => home_url( '/ffl-services/#shipping' ),   'meta' => '03' ],
		[ 'label' => 'Consignment',       'href' => home_url( '/ffl-services/#consign' ),    'meta' => '04' ],
		[ 'label' => 'How It Works',      'href' => home_url( '/ffl-services/#how' ),        'meta' => '05' ],
	] ],
	[ 'label' => 'Membership', 'href' => home_url( '/memberships/' ), 'sub' => [
		[ 'label' => 'Plans & Pricing', 'href' => home_url( '/memberships/' ), 'meta' => '01' ],
		[ 'label' => 'Range Fees',      'href' => home_url( '/pricing/' ),     'meta' => '02' ],
		[ 'label' => 'Member Login',    'href' => home_url( '/login/' ),       'meta' => '03' ],
		[ 'label' => 'My Account',      'href' => home_url( '/account/' ),     'meta' => '04' ],
	] ],
	[ 'label' => 'Learn',      'href' => home_url( '/blog/' ), 'sub' => [
		[ 'label' => 'Knowledge Hub',      'href' => home_url( '/blog/' ),                          'meta' => 'ALL' ],
		[ 'label' => 'CCW & Carry Laws',   'href' => home_url( '/category/ccw/' ),                  'meta' => '04' ],
		[ 'label' => 'Range Safety',       'href' => home_url( '/category/safety/' ),               'meta' => '06' ],
		[ 'label' => 'Beginner Guides',    'href' => home_url( '/category/beginners/' ),            'meta' => '03' ],
		[ 'label' => 'Gear Reviews',       'href' => home_url( '/category/gear/' ),                 'meta' => '05' ],
	] ],
	[ 'label' => 'About',      'href' => home_url( '/about/' ), 'sub' => [
		[ 'label' => 'Our Range',     'href' => home_url( '/about/' ),         'meta' => '01' ],
		[ 'label' => 'Range Safety',  'href' => home_url( '/range-safety/' ),  'meta' => '02' ],
		[ 'label' => 'Contact',       'href' => home_url( '/contact/' ),       'meta' => '03' ],
	] ],
];

// Single source of truth for NAP — see inc/business-info.php.
$g2a_nav_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
$phone       = $g2a_nav_biz['phone'] ?? '(602) 715-2677';
$phone_tel   = $g2a_nav_biz['phone_tel'] ?? '+16027152677';
?>
<nav class="g2a-nav" id="g2a-nav">
	<a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="mark"></span>GUNS&nbsp;2&nbsp;AMMO</a>
	<ul class="links">
		<?php foreach ( $nav as $item ) :
			$is_current = ( trailingslashit( $here ) === trailingslashit( trim( parse_url( $item['href'], PHP_URL_PATH ) ?? '', '/' ) ) );
		?>
		<li>
			<a href="<?php echo esc_url( $item['href'] ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $item['label'] ); ?>
				<?php if ( ! empty( $item['sub'] ) ) : ?>
					<svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor" aria-hidden="true"><path d="M1 2 L4 6 L7 2 Z"/></svg>
				<?php endif; ?>
			</a>
			<?php if ( ! empty( $item['sub'] ) ) : ?>
			<ul class="submenu">
				<?php foreach ( $item['sub'] as $s ) : ?>
					<li><a href="<?php echo esc_url( $s['href'] ); ?>"><span><?php echo esc_html( $s['label'] ); ?></span><span class="meta"><?php echo esc_html( $s['meta'] ); ?></span></a></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</li>
		<?php endforeach; ?>
	</ul>
	<div class="right">
		<div class="live-status closed" id="g2a-live-status" title="Range hours">
			<span class="dot"></span>
			<span><span id="g2a-live-label">Closed</span> <span class="label" id="g2a-live-time"></span></span>
		</div>
		<a class="phone" href="tel:<?php echo esc_attr( $phone_tel ); ?>"><?php echo esc_html( $phone ); ?></a>
		<button class="g2a-mode" id="g2a-mode-toggle" type="button" aria-label="<?php esc_attr_e( 'Switch light / dark mode', 'guns2ammo' ); ?>" title="<?php esc_attr_e( 'Light / dark mode', 'guns2ammo' ); ?>">
			<svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
			<svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.4"/><path d="M12 2v2.2M12 19.8V22M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M2 12h2.2M19.8 12H22M4.9 19.1l1.6-1.6M17.5 6.5l1.6-1.6"/></svg>
		</button>
		<div class="g2a-profile" id="g2a-profile">
			<button class="profile-btn" id="g2a-profile-btn" aria-label="Account" aria-haspopup="true" aria-expanded="false">
				<span class="av" id="g2a-profile-av"><?php
				if ( is_user_logged_in() ) {
					$g2a_av_user_id = get_current_user_id();
					$g2a_av_name    = wp_get_current_user()->display_name;
					// Member profile image: the Memberistic account dashboard
					// upload (memberistic_profile_image_id) wins; otherwise a
					// membership plugin can supply one via the
					// g2a_profile_avatar_url filter, then gravatar, then the
					// 2-letter initials.
					$g2a_av_url = '';
					$g2a_img_id = (int) get_user_meta( $g2a_av_user_id, 'memberistic_profile_image_id', true );
					if ( $g2a_img_id ) {
						$g2a_av_url = (string) wp_get_attachment_image_url( $g2a_img_id, 'thumbnail' );
					}
					if ( ! $g2a_av_url ) {
						$g2a_av_url = get_avatar_url( $g2a_av_user_id, [ 'size' => 64 ] );
					}
					$g2a_av_url = apply_filters( 'g2a_profile_avatar_url', $g2a_av_url, $g2a_av_user_id );
					if ( $g2a_av_url ) {
						printf(
							'<img class="av-img" src="%s" alt="%s" width="64" height="64" loading="lazy" decoding="async" />',
							esc_url( $g2a_av_url ),
							esc_attr( $g2a_av_name )
						);
					} else {
						echo esc_html( strtoupper( substr( $g2a_av_name, 0, 2 ) ) );
					}
				} else {
					// Guest state: a real person icon, not an empty disc.
					?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20c1.2-3.6 4-5.4 7.5-5.4s6.3 1.8 7.5 5.4"/></svg><?php
				}
				?></span>
				<svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor" aria-hidden="true"><path d="M1 2 L4 6 L7 2 Z"/></svg>
			</button>
			<div class="profile-menu" id="g2a-profile-menu">
				<?php if ( is_user_logged_in() ) :
					$u = wp_get_current_user();
				?>
					<div class="pm-head">
						<div class="pm-name"><?php echo esc_html( $u->display_name ); ?></div>
						<div class="pm-meta"><span class="badge active no-dot" style="font-size:9px;padding:2px 6px;">MEMBER</span></div>
					</div>
					<a href="<?php echo esc_url( home_url( '/account/' ) ); ?>">Dashboard</a>
					<a href="<?php echo esc_url( home_url( '/account/#plan' ) ); ?>">My Membership</a>
					<a href="<?php echo esc_url( home_url( '/account/#billing' ) ); ?>">Billing &amp; Invoices</a>
					<a href="<?php echo esc_url( home_url( '/account/#card' ) ); ?>">Digital Card</a>
					<div class="pm-sep"></div>
					<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="pm-out">Sign Out</a>
				<?php else : ?>
					<div class="pm-head">
						<div class="pm-name">Welcome</div>
						<div class="pm-meta">Sign in or join as a member</div>
					</div>
					<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="pm-cta-primary">Sign In</a>
					<a href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>" class="pm-cta-secondary">Join Membership</a>
					<div class="pm-sep"></div>
					<a href="<?php echo esc_url( home_url( '/account/' ) ); ?>">Member Dashboard</a>
					<a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Contact Us</a>
				<?php endif; ?>
			</div>
		</div>
		<a class="btn btn-ember btn-sm" href="<?php echo esc_url( home_url( '/book-a-lane/' ) ); ?>">Book A Lane</a>
		<button class="hamburger" aria-label="Menu" id="g2a-burger">
			<svg width="20" height="14" viewBox="0 0 20 14" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><line x1="0" y1="1" x2="20" y2="1"/><line x1="0" y1="7" x2="20" y2="7"/><line x1="0" y1="13" x2="14" y2="13"/></svg>
		</button>
	</div>
</nav>
