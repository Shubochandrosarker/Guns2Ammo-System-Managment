<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Gunbroker;
use WpisticFFL\Tests\Unit\Support\FakeWpdb;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-db.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-gunbroker.php';
require_once __DIR__ . '/Support/FakeWpdb.php';

/**
 * GunBroker has no confirmed public taxonomy or order-field spec (see
 * G2A_Gunbroker's own docblock) -- these tests cover the pure logic that
 * doesn't depend on that: listing-payload construction from a plain
 * product-data array, defensive order-row field extraction, license
 * normalization, and the dealer-lookup query shape, without a live account.
 */
final class GunbrokerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_build_listing_payload_maps_the_confirmed_fields(): void {
		$product = [
			'title'           => 'Test Rifle',
			'description'     => 'A fine rifle',
			'price'           => 899.00,
			'quantity'        => 3,
			'sku'             => 'TR-100',
			'upc'             => '012345678905',
			'is_ffl_required' => true,
			'image_urls'      => [ 'https://example.com/rifle.jpg' ],
		];

		$payload = G2A_Gunbroker::build_listing_payload( $product, '2614' );

		$this->assertSame( 2614, $payload['CategoryID'] );
		$this->assertSame( 'Test Rifle', $payload['Title'] );
		$this->assertSame( 899.00, $payload['FixedPrice'] );
		$this->assertSame( 3, $payload['Quantity'] );
		$this->assertTrue( $payload['IsFFLRequired'] );
		$this->assertSame( 'TR-100', $payload['SKU'] );
		$this->assertSame( '012345678905', $payload['GTIN'] );
		$this->assertSame( [ 'https://example.com/rifle.jpg' ], $payload['PictureURLs'] );
		$this->assertFalse( $payload['WillShipInternational'] );
	}

	public function test_build_listing_payload_omits_gtin_when_no_upc(): void {
		$product = [
			'title' => 'No UPC Item', 'description' => '', 'price' => 10.0, 'quantity' => 1,
			'sku' => 'X', 'is_ffl_required' => false, 'image_urls' => [],
		];

		$payload = G2A_Gunbroker::build_listing_payload( $product, '100' );

		$this->assertArrayNotHasKey( 'GTIN', $payload );
	}

	public function test_build_listing_payload_truncates_an_overlong_title(): void {
		$product = [
			'title' => str_repeat( 'A', 200 ), 'description' => '', 'price' => 1.0, 'quantity' => 1,
			'sku' => '', 'is_ffl_required' => false, 'image_urls' => [],
		];

		$payload = G2A_Gunbroker::build_listing_payload( $product, '1' );

		$this->assertSame( 80, strlen( $payload['Title'] ) );
	}

	public function test_normalize_order_row_extracts_confirmed_and_defensive_fields(): void {
		$raw = [
			'orderID'    => 'GB-9988',
			'fflNumber'  => '1-23-456-78-9A-01234',
			'buyerName'  => 'Jane Doe',
			'buyerEmail' => 'jane@example.com',
			'items'      => [
				[ 'itemID' => '555', 'quantity' => 2, 'price' => 499.99 ],
			],
		];

		$normalized = G2A_Gunbroker::normalize_order_row( $raw );

		$this->assertSame( 'GB-9988', $normalized['order_id'] );
		$this->assertSame( '123456789A01234', $normalized['ffl_license_number'] );
		$this->assertSame( 'Jane Doe', $normalized['buyer_name'] );
		$this->assertSame( 'jane@example.com', $normalized['buyer_email'] );
		$this->assertCount( 1, $normalized['items'] );
		$this->assertSame( '555', $normalized['items'][0]['item_id'] );
		$this->assertSame( 2, $normalized['items'][0]['quantity'] );
		$this->assertSame( 499.99, $normalized['items'][0]['price'] );
	}

	public function test_normalize_order_row_tries_alternate_key_spellings(): void {
		// Real field names were never independently confirmed -- this
		// exercises the PascalCase fallback path a differently-cased
		// response would hit.
		$raw = [
			'OrderID'    => 'GB-1',
			'FFLNumber'  => 'AB-12-345',
			'BuyerName'  => 'John Smith',
			'OrderItems' => [ [ 'ItemID' => '7', 'Quantity' => 1 ] ],
		];

		$normalized = G2A_Gunbroker::normalize_order_row( $raw );

		$this->assertSame( 'GB-1', $normalized['order_id'] );
		$this->assertSame( 'AB12345', $normalized['ffl_license_number'] );
		$this->assertSame( 'John Smith', $normalized['buyer_name'] );
		$this->assertSame( '7', $normalized['items'][0]['item_id'] );
	}

	public function test_normalize_order_row_skips_items_with_no_item_id(): void {
		$raw = [ 'orderID' => 'GB-2', 'items' => [ [ 'quantity' => 1 ], [ 'itemID' => '9', 'quantity' => 1 ] ] ];

		$normalized = G2A_Gunbroker::normalize_order_row( $raw );

		$this->assertCount( 1, $normalized['items'] );
		$this->assertSame( '9', $normalized['items'][0]['item_id'] );
	}

	public function test_normalize_license_strips_punctuation_and_upcases(): void {
		$this->assertSame( '123456789A01234', G2A_Gunbroker::normalize_license( '1-23-456-78-9a-01234' ) );
		$this->assertSame( '', G2A_Gunbroker::normalize_license( '' ) );
	}

	public function test_find_dealer_by_license_returns_null_for_blank_input(): void {
		$this->assertNull( G2A_Gunbroker::find_dealer_by_license( '' ) );
	}

	public function test_find_dealer_by_license_queries_the_dealers_table(): void {
		$fake = new FakeWpdb();
		$fake->when( 'UPPER(REPLACE(license_number', '7' );
		$GLOBALS['wpdb'] = $fake;

		$id = G2A_Gunbroker::find_dealer_by_license( '123456789A01234' );

		$this->assertSame( 7, $id );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_find_dealer_by_license_returns_null_when_no_match(): void {
		$fake = new FakeWpdb();
		$fake->when( 'UPPER(REPLACE(license_number', null );
		$GLOBALS['wpdb'] = $fake;

		$this->assertNull( G2A_Gunbroker::find_dealer_by_license( 'NOMATCH' ) );
		unset( $GLOBALS['wpdb'] );
	}
}
