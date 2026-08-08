<?php
/**
 * G2A — Pittman-Robertson federal excise tax line item.
 *
 * 26 U.S.C. § 4181 imposes a federal excise tax on firearms and ammunition
 * — 10% on pistols/revolvers, 11% on other firearms and ammunition — owed
 * by the MANUFACTURER OR IMPORTER of record, not by a downstream reseller.
 * Most stores running this plugin are resellers and owe nothing here; this
 * hook only fires for products an admin has explicitly flagged as their own
 * manufacture/import via the product-level checkbox added in v1.10.0
 * (Checkout::add_ffl_product_field()) — there is no store-wide toggle,
 * because whether this applies is a per-product/per-merchant fact, not a
 * site-wide setting.
 *
 * This is a checkout convenience, NOT tax or legal advice — the rate,
 * whether it applies to a given sale, and how it's remitted to the IRS are
 * the merchant's responsibility to confirm with their own counsel/CPA.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Excise_Tax {

	public function __construct() {
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'maybe_add_fee' ] );
	}

	public function maybe_add_fee(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}

		$total = 0.0;
		$count = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = $item['variation_id'] ?: $item['product_id'];
			if ( 'yes' !== get_post_meta( $product_id, '_wpistic_ffl_excise_taxable', true ) ) {
				continue;
			}
			$rate = (float) ( get_post_meta( $product_id, '_wpistic_ffl_excise_rate', true ) ?: 11 ) / 100;
			$line_subtotal = (float) ( $item['line_subtotal'] ?? 0 );
			$total += $line_subtotal * $rate;
			$count++;
		}

		if ( $total <= 0 ) {
			return;
		}

		/**
		 * Filter the computed excise-tax amount before it's added as a cart fee.
		 * Return 0 (or negative-cancel with a matching fee) to override entirely.
		 */
		$total = (float) apply_filters( 'wpistic_ffl_excise_tax_amount', $total, $count );
		if ( $total <= 0 ) {
			return;
		}

		WC()->cart->add_fee(
			__( 'Federal Excise Tax (Pittman-Robertson)', 'advanced-ffl-checkout' ),
			round( $total, 2 ),
			true // taxable — matches how the rest of the order is taxed.
		);
	}
}
