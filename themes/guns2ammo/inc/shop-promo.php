<?php
/**
 * Shop promotional banner + live offer countdown.
 *
 * A campaign panel that sits at the top of the shop and product-category
 * archives: offer headline, supporting copy, a call to action, the perk row,
 * and a real-time animated countdown to the offer's end.
 *
 * Everything is editable in Customizer → "Guns 2 Ammo — Shop Promo Banner",
 * so running a new campaign never needs a code change. The banner is off
 * until an end date is set and the enable box is ticked.
 *
 * The countdown deadline is ABSOLUTE — it is resolved to a UNIX timestamp in
 * the site's own timezone and printed into the markup, so a page served from
 * a full-page cache still counts down to the right moment instead of
 * restarting from whenever the HTML was generated.
 *
 * Presentation: assets/css/shop-promo.css. Ticking: assets/js/shop-promo.js.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==================================================================
 * SETTINGS
 * ================================================================== */

/**
 * Default value for every promo setting.
 *
 * Kept in one array so the Customizer registration, the renderer and the
 * documentation can never disagree about what a field is called or what it
 * falls back to.
 *
 * @return array<string,string>
 */
function g2a_promo_defaults() {
	return array(
		'g2a_promo_enabled'        => '1',
		'g2a_promo_eyebrow'        => __( 'Exclusive Membership Offer', 'guns2ammo' ),
		'g2a_promo_lead'           => __( 'Get a Special', 'guns2ammo' ),
		'g2a_promo_highlight'      => __( '30% Off', 'guns2ammo' ),
		'g2a_promo_tail'           => __( 'on the Annual Membership Plan', 'guns2ammo' ),
		'g2a_promo_subline'        => __( 'Save more. Shoot more. Member perks all year.', 'guns2ammo' ),
		'g2a_promo_cta_label'      => __( 'Join Now', 'guns2ammo' ),
		'g2a_promo_cta_url'        => '/memberships/',
		'g2a_promo_ends'           => '',
		'g2a_promo_countdown_label'=> __( 'Offer ends in', 'guns2ammo' ),
		'g2a_promo_hide_expired'   => '1',
		'g2a_promo_image'          => '',
		'g2a_promo_card_name'      => __( 'Member', 'guns2ammo' ),
		'g2a_promo_card_note'      => __( 'Exclusive access. Premium perks.', 'guns2ammo' ),
		'g2a_promo_seal_top'       => __( 'Member Exclusive', 'guns2ammo' ),
		'g2a_promo_seal_bottom'    => __( 'Best Value', 'guns2ammo' ),
		'g2a_promo_perk_1'         => __( 'Exclusive Discounts', 'guns2ammo' ),
		'g2a_promo_perk_2'         => __( 'Member-Only Promotions', 'guns2ammo' ),
		'g2a_promo_perk_3'         => __( 'Early Access to New Gear', 'guns2ammo' ),
		'g2a_promo_perk_4'         => __( 'VIP Support All Year', 'guns2ammo' ),
	);
}

/**
 * Read one promo setting, falling back to its default.
 *
 * @param string $key Setting key.
 * @return string
 */
function g2a_promo_get( $key ) {
	$defaults = g2a_promo_defaults();
	$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	return (string) get_theme_mod( $key, $default );
}

add_action( 'customize_register', function ( $wp_customize ) {
	$wp_customize->add_section( 'g2a_shop_promo', array(
		'title'       => __( 'Guns 2 Ammo — Shop Promo Banner', 'guns2ammo' ),
		'priority'    => 31,
		'description' => __( 'Campaign banner shown at the top of the shop and product category pages. Set an end date to run the live countdown.', 'guns2ammo' ),
	) );

	$defaults = g2a_promo_defaults();

	$controls = array(
		'g2a_promo_enabled'         => array( 'label' => __( 'Show the banner', 'guns2ammo' ), 'type' => 'checkbox' ),
		'g2a_promo_eyebrow'         => array( 'label' => __( 'Eyebrow', 'guns2ammo' ) ),
		'g2a_promo_lead'            => array( 'label' => __( 'Headline — lead in', 'guns2ammo' ) ),
		'g2a_promo_highlight'       => array( 'label' => __( 'Headline — the big highlighted line', 'guns2ammo' ) ),
		'g2a_promo_tail'            => array( 'label' => __( 'Headline — closing line', 'guns2ammo' ) ),
		'g2a_promo_subline'         => array( 'label' => __( 'Supporting line', 'guns2ammo' ) ),
		'g2a_promo_cta_label'       => array( 'label' => __( 'Button label', 'guns2ammo' ) ),
		'g2a_promo_cta_url'         => array( 'label' => __( 'Button link', 'guns2ammo' ), 'type' => 'url' ),
		'g2a_promo_ends'            => array( 'label' => __( 'Offer ends (site time)', 'guns2ammo' ), 'type' => 'datetime' ),
		'g2a_promo_countdown_label' => array( 'label' => __( 'Countdown label', 'guns2ammo' ) ),
		'g2a_promo_hide_expired'    => array( 'label' => __( 'Hide the whole banner once the offer ends', 'guns2ammo' ), 'type' => 'checkbox' ),
		'g2a_promo_image'           => array( 'label' => __( 'Artwork (optional — replaces the drawn membership card)', 'guns2ammo' ), 'type' => 'image' ),
		'g2a_promo_card_name'       => array( 'label' => __( 'Card — tier name', 'guns2ammo' ) ),
		'g2a_promo_card_note'       => array( 'label' => __( 'Card — small print', 'guns2ammo' ) ),
		'g2a_promo_seal_top'        => array( 'label' => __( 'Seal — upper text', 'guns2ammo' ) ),
		'g2a_promo_seal_bottom'     => array( 'label' => __( 'Seal — lower text', 'guns2ammo' ) ),
		'g2a_promo_perk_1'          => array( 'label' => __( 'Perk 1', 'guns2ammo' ) ),
		'g2a_promo_perk_2'          => array( 'label' => __( 'Perk 2', 'guns2ammo' ) ),
		'g2a_promo_perk_3'          => array( 'label' => __( 'Perk 3', 'guns2ammo' ) ),
		'g2a_promo_perk_4'          => array( 'label' => __( 'Perk 4', 'guns2ammo' ) ),
	);

	foreach ( $controls as $id => $control ) {
		$type = isset( $control['type'] ) ? $control['type'] : 'text';

		switch ( $type ) {
			case 'checkbox':
				$sanitize = static function ( $value ) {
					return $value ? '1' : '';
				};
				break;
			case 'url':
			case 'image':
				$sanitize = 'esc_url_raw';
				break;
			default:
				$sanitize = 'sanitize_text_field';
		}

		$wp_customize->add_setting( $id, array(
			'default'           => $defaults[ $id ],
			'sanitize_callback' => $sanitize,
			'transport'         => 'refresh',
		) );

		if ( 'image' === $type ) {
			$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $id, array(
				'label'   => $control['label'],
				'section' => 'g2a_shop_promo',
			) ) );
			continue;
		}

		if ( 'datetime' === $type && class_exists( 'WP_Customize_Date_Time_Control' ) ) {
			$wp_customize->add_control( new WP_Customize_Date_Time_Control( $wp_customize, $id, array(
				'label'       => $control['label'],
				'section'     => 'g2a_shop_promo',
				'include_time'=> true,
				'description' => __( 'Leave empty to run the banner with no countdown.', 'guns2ammo' ),
			) ) );
			continue;
		}

		$wp_customize->add_control( $id, array(
			'label'       => $control['label'],
			'section'     => 'g2a_shop_promo',
			'type'        => 'datetime' === $type ? 'text' : $type,
			'description' => 'datetime' === $type ? __( 'Format: YYYY-MM-DD HH:MM. Leave empty for no countdown.', 'guns2ammo' ) : '',
		) );
	}
} );

/* ==================================================================
 * DEADLINE
 * ================================================================== */

/**
 * The offer deadline as a UNIX timestamp, or 0 when no valid future or past
 * date is configured.
 *
 * The stored value is wall-clock time in the site's timezone (that is what
 * the Customizer date control hands back), so it is parsed against
 * wp_timezone() rather than the server's PHP default — otherwise a store in
 * Arizona counts down to a UTC deadline and the offer looks like it ends
 * seven hours early.
 *
 * @return int
 */
function g2a_promo_deadline() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

	$raw = trim( g2a_promo_get( 'g2a_promo_ends' ) );
	if ( '' === $raw ) {
		$cached = 0;
		return $cached;
	}

	try {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date     = new DateTimeImmutable( $raw, $timezone );
		$cached   = $date->getTimestamp();
	} catch ( Exception $e ) {
		$cached = 0;
	}

	return $cached;
}

/**
 * Whether the promo banner should render on the current request.
 *
 * @return bool
 */
function g2a_promo_is_active() {
	if ( '' === g2a_promo_get( 'g2a_promo_enabled' ) ) {
		return false;
	}

	$deadline = g2a_promo_deadline();
	$expired  = $deadline > 0 && $deadline <= time();

	if ( $expired && '' !== g2a_promo_get( 'g2a_promo_hide_expired' ) ) {
		return false;
	}

	// Nothing to show if the campaign has no headline at all.
	$has_copy = '' !== trim( g2a_promo_get( 'g2a_promo_highlight' ) . g2a_promo_get( 'g2a_promo_lead' ) . g2a_promo_get( 'g2a_promo_tail' ) );

	return (bool) apply_filters( 'g2a_promo_is_active', $has_copy );
}

/**
 * Whether the current request is a shop screen the banner belongs on.
 *
 * @return bool
 */
function g2a_promo_is_shop_screen() {
	if ( ! function_exists( 'is_shop' ) ) {
		return false;
	}
	// First page only — the banner is a landing statement, not something to
	// repeat on page 7 of the catalogue.
	$screen = ( is_shop() || is_product_taxonomy() ) && ! is_paged();
	return (bool) apply_filters( 'g2a_promo_show_on_screen', $screen );
}

/* ==================================================================
 * RENDER
 * ================================================================== */

/* Priority 25 puts the banner after WooCommerce's breadcrumb (20) and before
 * the archive template's own hero — a breadcrumb tucked underneath a full
 * campaign panel reads as an orphan. */
add_action( 'woocommerce_before_main_content', 'g2a_promo_render', 25 );

/**
 * Print the promo banner.
 *
 * @return void
 */
function g2a_promo_render() {
	if ( ! g2a_promo_is_shop_screen() || ! g2a_promo_is_active() ) {
		return;
	}

	$deadline = g2a_promo_deadline();
	$live     = $deadline > time();
	$cta_url  = trim( g2a_promo_get( 'g2a_promo_cta_url' ) );
	$cta_url  = $cta_url ? $cta_url : '/memberships/';
	$cta_url  = preg_match( '#^https?://#i', $cta_url ) ? $cta_url : home_url( '/' . ltrim( $cta_url, '/' ) );
	$artwork  = trim( g2a_promo_get( 'g2a_promo_image' ) );

	$perks = array(
		array( 'tag', g2a_promo_get( 'g2a_promo_perk_1' ) ),
		array( 'calendar', g2a_promo_get( 'g2a_promo_perk_2' ) ),
		array( 'shield', g2a_promo_get( 'g2a_promo_perk_3' ) ),
		array( 'star', g2a_promo_get( 'g2a_promo_perk_4' ) ),
	);
	?>
	<section class="g2a-promo<?php echo $live ? ' has-countdown' : ''; ?>" aria-label="<?php echo esc_attr( g2a_promo_get( 'g2a_promo_eyebrow' ) ); ?>">
		<div class="g2a-promo__inner">

			<div class="g2a-promo__copy">
				<?php if ( '' !== trim( g2a_promo_get( 'g2a_promo_eyebrow' ) ) ) : ?>
					<p class="g2a-promo__eyebrow">
						<?php echo g2a_sp_icon( 'target' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
						<span><?php echo esc_html( g2a_promo_get( 'g2a_promo_eyebrow' ) ); ?></span>
						<i class="g2a-promo__rule" aria-hidden="true"></i>
					</p>
				<?php endif; ?>

				<h2 class="g2a-promo__title">
					<?php if ( '' !== trim( g2a_promo_get( 'g2a_promo_lead' ) ) ) : ?>
						<span class="g2a-promo__lead"><?php echo esc_html( g2a_promo_get( 'g2a_promo_lead' ) ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== trim( g2a_promo_get( 'g2a_promo_highlight' ) ) ) : ?>
						<span class="g2a-promo__highlight"><?php echo esc_html( g2a_promo_get( 'g2a_promo_highlight' ) ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== trim( g2a_promo_get( 'g2a_promo_tail' ) ) ) : ?>
						<span class="g2a-promo__tail"><?php echo esc_html( g2a_promo_get( 'g2a_promo_tail' ) ); ?></span>
					<?php endif; ?>
				</h2>

				<?php if ( '' !== trim( g2a_promo_get( 'g2a_promo_subline' ) ) ) : ?>
					<p class="g2a-promo__sub"><?php echo esc_html( g2a_promo_get( 'g2a_promo_subline' ) ); ?></p>
				<?php endif; ?>

				<?php g2a_promo_render_countdown( $deadline ); ?>

				<a class="g2a-promo__cta" href="<?php echo esc_url( $cta_url ); ?>">
					<?php echo g2a_sp_icon( 'double-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
					<span><?php echo esc_html( g2a_promo_get( 'g2a_promo_cta_label' ) ); ?></span>
				</a>
			</div>

			<div class="g2a-promo__art">
				<?php if ( $artwork ) : ?>
					<img class="g2a-promo__artwork" src="<?php echo esc_url( $artwork ); ?>" alt="" loading="lazy" decoding="async" />
				<?php else : ?>
					<div class="g2a-promo__card" aria-hidden="true">
						<div class="g2a-promo__card-head">
							<span class="g2a-promo__card-glyph">G</span>
							<span class="g2a-promo__card-names">
								<span class="g2a-promo__card-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
								<span class="g2a-promo__card-tier"><?php echo esc_html( g2a_promo_get( 'g2a_promo_card_name' ) ); ?></span>
							</span>
						</div>
						<div class="g2a-promo__card-note"><?php echo esc_html( g2a_promo_get( 'g2a_promo_card_note' ) ); ?></div>
					</div>
				<?php endif; ?>

				<?php if ( '' !== trim( g2a_promo_get( 'g2a_promo_seal_top' ) ) ) : ?>
					<div class="g2a-promo__seal" aria-hidden="true">
						<span class="g2a-promo__seal-top"><?php echo esc_html( g2a_promo_get( 'g2a_promo_seal_top' ) ); ?></span>
						<span class="g2a-promo__seal-star">&#9733;</span>
						<span class="g2a-promo__seal-bottom"><?php echo esc_html( g2a_promo_get( 'g2a_promo_seal_bottom' ) ); ?></span>
					</div>
				<?php endif; ?>
			</div>

			<ul class="g2a-promo__perks">
				<?php foreach ( $perks as $perk ) : ?>
					<?php if ( '' === trim( $perk[1] ) ) { continue; } ?>
					<li class="g2a-promo__perk">
						<?php echo g2a_sp_icon( $perk[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
						<span><?php echo esc_html( $perk[1] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

		</div>
	</section>
	<?php
}

/**
 * Print the animated countdown.
 *
 * Server-rendered with the correct remaining time so the first paint is
 * already right — the script then takes over and animates each digit change.
 * With JavaScript unavailable it degrades to a static "time remaining"
 * readout rather than a row of zeroes.
 *
 * @param int $deadline UNIX timestamp the offer ends at.
 * @return void
 */
function g2a_promo_render_countdown( $deadline ) {
	if ( $deadline <= time() ) {
		return;
	}

	$remaining = $deadline - time();
	$units     = array(
		'days'    => array( (int) floor( $remaining / DAY_IN_SECONDS ), _n_noop( 'Day', 'Days', 'guns2ammo' ) ),
		'hours'   => array( (int) floor( ( $remaining % DAY_IN_SECONDS ) / HOUR_IN_SECONDS ), _n_noop( 'Hour', 'Hours', 'guns2ammo' ) ),
		'minutes' => array( (int) floor( ( $remaining % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS ), _n_noop( 'Min', 'Mins', 'guns2ammo' ) ),
		'seconds' => array( (int) ( $remaining % MINUTE_IN_SECONDS ), _n_noop( 'Sec', 'Secs', 'guns2ammo' ) ),
	);
	?>
	<div class="g2a-promo__countdown g2a-count" data-g2a-countdown data-ends="<?php echo esc_attr( (string) $deadline ); ?>">
		<?php if ( '' !== trim( g2a_promo_get( 'g2a_promo_countdown_label' ) ) ) : ?>
			<span class="g2a-count__label"><?php echo esc_html( g2a_promo_get( 'g2a_promo_countdown_label' ) ); ?></span>
		<?php endif; ?>

		<div class="g2a-count__units" role="timer" aria-live="off">
			<?php foreach ( $units as $unit => $data ) : ?>
				<?php
				$value   = max( 0, $data[0] );
				$digits  = str_split( str_pad( (string) $value, 2, '0', STR_PAD_LEFT ) );
				?>
				<div class="g2a-count__unit">
					<span class="g2a-count__value" data-unit="<?php echo esc_attr( $unit ); ?>">
						<?php foreach ( $digits as $digit ) : ?>
							<span class="g2a-count__digit">
								<span class="g2a-count__cur"><?php echo esc_html( $digit ); ?></span>
								<span class="g2a-count__next" aria-hidden="true"><?php echo esc_html( $digit ); ?></span>
							</span>
						<?php endforeach; ?>
					</span>
					<span class="g2a-count__unit-label"><?php echo esc_html( translate_nooped_plural( $data[1], $value, 'guns2ammo' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<span class="g2a-count__ended"><?php esc_html_e( 'This offer has ended.', 'guns2ammo' ); ?></span>
	</div>
	<?php
}

/* ==================================================================
 * LEGACY SALE STRIP
 * inc/woocommerce.php renders its own "Special Offer" strip at the top
 * of the shop, driven by whichever product sale ends soonest. Two
 * stacked countdown banners is one too many — the campaign banner wins
 * while it is live.
 * ================================================================== */

add_action( 'wp', function () {
	if ( ! g2a_promo_is_shop_screen() || ! g2a_promo_is_active() ) {
		return;
	}
	if ( ! apply_filters( 'g2a_promo_replaces_sale_strip', true ) ) {
		return;
	}
	remove_action( 'woocommerce_before_shop_loop', 'g2a_render_shop_offer_banner', 5 );
}, 20 );
