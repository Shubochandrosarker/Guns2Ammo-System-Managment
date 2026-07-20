<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Bill_Hicks;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-bill-hicks.php';

/**
 * Bill Hicks & Co. has no REST/SOAP API -- catalog rows are parsed by CSV
 * header name (since the exact column order was never independently
 * confirmed, unlike RSR's positional layout -- see G2A_Bill_Hicks's own
 * docblock), and order files are pure tab-delimited string construction.
 * These tests cover both without a live FTP account.
 */
final class BillHicksCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_normalize_catalog_row_maps_confirmed_csv_columns(): void {
		$row = [
			'universal_product_code' => '012345678905',
			'product_name'           => 'Test Rifle',
			'product_price'          => '899.00',
			'marp'                   => '849.00',
			'category_description'   => 'Rifle',
			'product' => 'BHC-ITEM-1',
		];

		$item = G2A_Bill_Hicks::normalize_catalog_row( $row, [ 'BHC-ITEM-1' => 6 ] );

		$this->assertNotNull( $item );
		$this->assertSame( 'BHC-ITEM-1', $item['item_no'] );
		$this->assertSame( 'Test Rifle', $item['description'] );
		$this->assertSame( 'Rifle', $item['manufacturer'] );
		$this->assertSame( '012345678905', $item['upc'] );
		$this->assertSame( 849.00, $item['price'], 'MAP price (marp) wins over list price when both are present.' );
		$this->assertSame( 6, $item['quantity'], 'Quantity comes from the separate inventory file, keyed by item.' );
		$this->assertTrue( $item['is_firearm'] );
	}

	public function test_normalize_catalog_row_falls_back_to_product_price_without_marp(): void {
		$row = [ 'product' => 'X1', 'product_name' => 'Ammo Box', 'product_price' => '29.99', 'category_description' => 'Ammunition' ];

		$item = G2A_Bill_Hicks::normalize_catalog_row( $row );

		$this->assertSame( 29.99, $item['price'] );
		$this->assertFalse( $item['is_firearm'] );
	}

	/**
	 * A real header-mapped CSV row always has the marp column present,
	 * just blank for items with no MAP price -- a bare `??` treats '' as
	 * "present" and would silently price the item at $0.00 instead of
	 * falling back to product_price.
	 */
	public function test_normalize_catalog_row_treats_a_blank_marp_column_as_absent(): void {
		$row = [ 'product' => 'X2', 'product_name' => 'Test Item', 'product_price' => '19.99', 'marp' => '' ];

		$item = G2A_Bill_Hicks::normalize_catalog_row( $row );

		$this->assertSame( 19.99, $item['price'], 'A blank marp column must fall back to product_price, not zero the item out.' );
	}

	public function test_normalize_catalog_row_falls_back_to_upc_keyed_quantity(): void {
		// No 'product' column on the catalog row (per this class's own
		// documented uncertainty about the real field layout) -- item_no
		// falls back to UPC, and the quantity lookup must also try UPC
		// rather than only ever trying the (possibly different) inventory
		// file's own 'product' key.
		$row = [ 'universal_product_code' => '000111222333', 'product_name' => 'Test Item', 'product_price' => '10.00' ];

		$item = G2A_Bill_Hicks::normalize_catalog_row( $row, [ '000111222333' => 3 ] );

		$this->assertSame( 3, $item['quantity'] );
	}

	public function test_normalize_catalog_row_rejects_a_row_with_no_item_identifier(): void {
		$this->assertNull( G2A_Bill_Hicks::normalize_catalog_row( [ 'product_name' => 'Nameless' ] ) );
	}

	public function test_build_order_file_produces_the_confirmed_hl_ll_format(): void {
		$t = (object) [
			'transfer_ref'   => 'G2A-XYZ98765',
			'business_name'  => 'Test Gun Shop',
			'premise_street' => '123 Main St',
			'premise_city'   => 'Springfield',
			'premise_state'  => 'IL',
			'premise_zip'    => '62704',
			'license_number' => '1-23-456-78-9A-01234',
		];

		$built = G2A_Bill_Hicks::build_order_file( $t, 'BHC-ITEM-1', 'Test Rifle', 1, 849.0, '5001', '5002' );

		$this->assertSame( 'G2A-XYZ98765-1-order.txt', $built['filename'] );

		$lines = explode( "\r\n", rtrim( $built['contents'], "\r\n" ) );
		$this->assertCount( 2, $lines );
		$this->assertSame(
			"HL\t5001\t5002\tTest Gun Shop\t123 Main St\tSpringfield\tIL\t62704\tG2A-XYZ98765-1\tGround\t\t1-23-456-78-9A-01234",
			$lines[0]
		);
		$this->assertSame( "LL\tBHC-ITEM-1\tTest Rifle\t1\t849.00", $lines[1] );
	}

	/**
	 * Regression test: without an attempt discriminator, resubmitting an
	 * order for the same transfer would silently overwrite the prior
	 * upload at the same remote filename before Bill Hicks ever picks it
	 * up.
	 */
	public function test_build_order_file_gives_each_attempt_a_distinct_filename(): void {
		$t = (object) [
			'transfer_ref' => 'G2A-DUPE', 'business_name' => 'Shop', 'premise_street' => '', 'premise_city' => '',
			'premise_state' => '', 'premise_zip' => '', 'license_number' => '',
		];

		$first  = G2A_Bill_Hicks::build_order_file( $t, 'X', 'Item', 1, 0.0, '5001', '', 1 );
		$second = G2A_Bill_Hicks::build_order_file( $t, 'X', 'Item', 1, 0.0, '5001', '', 2 );

		$this->assertNotSame( $first['filename'], $second['filename'] );
	}

	public function test_build_order_file_uses_customer_number_when_no_dropship_number_set(): void {
		$t = (object) [
			'transfer_ref' => 'G2A-NODS', 'business_name' => 'Shop', 'premise_street' => '', 'premise_city' => '',
			'premise_state' => '', 'premise_zip' => '', 'license_number' => '',
		];

		$built = G2A_Bill_Hicks::build_order_file( $t, 'X', 'Item', 1, 0.0, '5001', '' );

		$this->assertStringContainsString( "HL\t5001\t5001\t", $built['contents'] );
	}
}
