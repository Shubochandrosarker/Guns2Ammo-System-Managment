<?php

namespace G2A\POS\Pricing;

use G2A\POS\Database\MapRuleRepository;

/**
 * Wires MAP rules into WooCommerce price display.
 *
 *  - If a product has a MAP rule and the active sale price is BELOW MAP,
 *    the public price is hidden behind a click-to-reveal / add-to-cart prompt.
 *  - Cart, checkout, REST, and admin contexts are intentionally untouched —
 *    the actual selling price is unchanged; this is a *display* control only,
 *    which is what manufacturers' MAP policies require.
 *  - All hits are recorded so the merchant can prove compliance.
 */
final class MapPricingService {

	private const META_FLAG = '_g2a_pos_below_map';

	public static function boot(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( 'woocommerce_get_price_html', array( self::class, 'filter_price_html' ), 50, 2 );
		add_action( 'woocommerce_single_product_summary', array( self::class, 'maybe_render_reveal_widget' ), 11 );
	}

	public static function evaluate( int $productId, float $activePrice ): array {
		return self::evaluateRule( self::ruleFor( $productId ), $activePrice );
	}

	/**
	 * Pure decision function — given a rule row (or null) and the active sale
	 * price, return whether the public price needs suppressing. Extracted from
	 * evaluate() so the contract can be unit-tested without touching the DB.
	 */
	public static function evaluateRule( ?array $rule, float $activePrice ): array {
		if ( ! $rule ) {
			return array( 'has_rule' => false );
		}
		$map = (float) ( $rule['map_price'] ?? 0 );
		if ( $map <= 0 ) {
			return array( 'has_rule' => false );
		}
		return array(
			'has_rule'       => true,
			'map_price'      => $map,
			'active_price'   => $activePrice,
			'below_map'      => $activePrice > 0 && $activePrice < $map,
			'display_mode'   => (string) ( $rule['display_mode'] ?? 'click_to_reveal' ),
			'override_label' => $rule['override_label'] ?? null,
			'source'         => (string) ( $rule['source'] ?? 'manual' ),
			'rule_id'        => (int) ( $rule['id'] ?? 0 ),
		);
	}

	public static function filter_price_html( string $html, $product ): string {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $html;
		}
		// Allow override for admin/cart/checkout contexts.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return $html;
		}

		$price = (float) $product->get_price();
		$eval  = self::evaluate( (int) $product->get_id(), $price );
		if ( ! $eval['has_rule'] || ! $eval['below_map'] ) {
			return $html;
		}

		// Log the violation suppression (sampled by transient to avoid floods).
		self::sampledLog( $product, $eval );

		$label = $eval['override_label'] ?: __( 'See price in cart', 'g2a-pos-core' );
		if ( $eval['display_mode'] === 'show_map' ) {
			return sprintf(
				'<span class="g2a-map-price">%s <small>(MAP)</small></span><span class="g2a-map-note">%s</span>',
				wc_price( $eval['map_price'] ),
				esc_html__( 'Your price is lower — see cart.', 'g2a-pos-core' )
			);
		}

		return sprintf(
			'<span class="g2a-map-hidden" data-map-product="%d">%s</span>',
			(int) $product->get_id(),
			esc_html( $label )
		);
	}

	public static function maybe_render_reveal_widget(): void {
		global $product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$eval = self::evaluate( (int) $product->get_id(), (float) $product->get_price() );
		if ( ! $eval['has_rule'] || ! $eval['below_map'] ) {
			return;
		}
		if ( $eval['display_mode'] === 'show_map' ) {
			return;
		}
		printf(
			'<p class="g2a-map-notice">%s</p>',
			esc_html__(
				'Manufacturer pricing rules prevent us from showing the discounted price publicly. Add to cart to see your final price.',
				'g2a-pos-core'
			)
		);
	}

	private static function ruleFor( int $productId ): ?array {
		static $cache = array();
		if ( array_key_exists( $productId, $cache ) ) {
			return $cache[ $productId ];
		}
		$repo = new MapRuleRepository();
		$sku  = null;
		$upc  = null;
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $productId );
			if ( $product ) {
				$sku = (string) $product->get_sku();
				$upc = (string) $product->get_meta( '_upc' );
				if ( $upc === '' ) {
					// `_global_unique_id` (GTIN/UPC) is a WC-internal meta key
					// since WooCommerce 9.1 — reading it via the generic
					// get_meta() triggers is_internal_meta_key's doing-it-wrong
					// notice, which rendered next to every storefront price.
					// Use the dedicated getter; fall back to raw post meta on
					// older WooCommerce where neither exists.
					if ( method_exists( $product, 'get_global_unique_id' ) ) {
						$upc = (string) $product->get_global_unique_id();
					} else {
						$upc = (string) get_post_meta( $productId, '_global_unique_id', true );
					}
				}
			}
		}
		$rule                = $repo->findForProduct( $productId, $sku ?: null, $upc ?: null );
		$cache[ $productId ] = $rule;
		return $rule;
	}

	private static function sampledLog( $product, array $evaluation ): void {
		$key = 'g2a_map_log_' . $product->get_id();
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
		$repo = new MapRuleRepository();
		$repo->logViolation(
			array(
				'wc_product_id'   => (int) $product->get_id(),
				'sku'             => (string) $product->get_sku(),
				'channel'         => 'storefront',
				'map_price'       => (float) $evaluation['map_price'],
				'attempted_price' => (float) $evaluation['active_price'],
				'action_taken'    => 'price_hidden',
				'context'         => array( 'display_mode' => $evaluation['display_mode'] ),
			)
		);
	}
}
