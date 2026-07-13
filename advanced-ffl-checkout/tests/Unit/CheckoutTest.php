<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\Checkout;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-checkout.php';

final class CheckoutTest extends TestCase {

	public function test_product_requires_ffl_true_when_meta_is_yes(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'yes' );
		$this->assertTrue( Checkout::product_requires_ffl( 123 ) );
	}

	public function test_product_requires_ffl_false_when_meta_is_missing(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( Checkout::product_requires_ffl( 123 ) );
	}

	/**
	 * v1.12.0 regression coverage: a cart with one FFL line item (qty 2) and
	 * one non-FFL line item must yield exactly one get_ffl_items() entry per
	 * physical FFL unit, and must skip the non-FFL line entirely.
	 */
	public function test_get_ffl_items_expands_quantity_and_skips_non_ffl_lines(): void {
		Functions\when( 'get_post_meta' )->alias( function ( $product_id, $key ) {
			if ( '_wpistic_ffl_required' === $key ) {
				return 10 === $product_id ? 'yes' : 'no';
			}
			return '';
		} );

		$order            = new \WC_Order();
		$ffl_product      = new \WC_Product( 10, 'Glock 19', 'GLOCK19' );
		$non_ffl_product  = new \WC_Product( 20, 'Ammo Box', 'AMMO' );

		$order->set_items( [
			new \WC_Order_Item_Product( $ffl_product, 2 ),
			new \WC_Order_Item_Product( $non_ffl_product, 5 ),
		] );

		$units = Checkout::get_ffl_items( $order );

		$this->assertCount( 2, $units, 'One entry per physical unit on the FFL line; non-FFL line contributes zero.' );
		foreach ( $units as $unit ) {
			$this->assertSame( 10, $unit['product']->get_id() );
		}
	}

	public function test_get_ffl_items_returns_empty_when_cart_has_no_ffl_products(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'no' );

		$order = new \WC_Order();
		$order->set_items( [ new \WC_Order_Item_Product( new \WC_Product( 20 ), 3 ) ] );

		$this->assertSame( [], Checkout::get_ffl_items( $order ) );
	}
}
