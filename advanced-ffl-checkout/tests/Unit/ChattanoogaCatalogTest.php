<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Chattanooga;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-chattanooga.php';

/**
 * Chattanooga's product-feed is a signed CSV download whose header row was
 * confirmed against a real 81,195-row CSSI export found in a real reference
 * repo (see G2A_Chattanooga's own docblock): SKU, Item Name, Quantity In
 * Stock, Price, UPC, Web Item Name, Web Item Description, Drop Ship Flag,
 * Drop Ship Price, Category, Ship Weight, Image Location, Manufacturer,
 * Manufacturer Item Number, Length, Width, Height, MAP, MSRP, Available
 * Drop Ship Delivery Options, Allocated Item?. There is no confirmed
 * FFL-required column anywhere in that real sample -- unlike Bill Hicks or
 * GunBroker, is_firearm has no vendor signal to even guess from, so it
 * defaults to false and must come from a store-supplied filter. These
 * tests exercise parse_csv() and map_catalog_row() directly against
 * hand-built CSV fixtures, no network.
 */
final class ChattanoogaCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_parse_csv_splits_header_and_rows_into_associative_arrays(): void {
		$csv = "SKU,Item Name,Manufacturer\nCT-1,Test Rifle,Acme\nCT-2,Test Pistol,Acme";

		$rows = G2A_Chattanooga::parse_csv( $csv );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'CT-1', $rows[0]['SKU'] );
		$this->assertSame( 'Test Rifle', $rows[0]['Item Name'] );
		$this->assertSame( 'CT-2', $rows[1]['SKU'] );
	}

	public function test_parse_csv_skips_a_short_malformed_row_instead_of_misaligning_columns(): void {
		$csv = "SKU,Item Name,Manufacturer\nCT-1,Test Rifle,Acme\nCT-2,Missing Field";

		$rows = G2A_Chattanooga::parse_csv( $csv );

		$this->assertCount( 1, $rows, 'The malformed second data row must be skipped, not silently mis-mapped.' );
		$this->assertSame( 'CT-1', $rows[0]['SKU'] );
	}

	public function test_parse_csv_returns_empty_for_a_header_only_or_empty_body(): void {
		$this->assertSame( [], G2A_Chattanooga::parse_csv( "SKU,Item Name\n" ) );
		$this->assertSame( [], G2A_Chattanooga::parse_csv( '' ) );
	}

	/**
	 * Regression test: the earlier line-presplit-then-str_getcsv approach
	 * tore a quoted, embedded-newline field into two lines, silently
	 * dropping the row as "malformed" -- a real, legal CSV shape (RFC 4180)
	 * a long product description could easily contain.
	 */
	public function test_parse_csv_handles_a_quoted_field_with_an_embedded_newline(): void {
		$csv = "SKU,Item Name,Manufacturer\nCT-1,\"Multi\nline desc\",Acme\nCT-2,Simple desc,Acme";

		$rows = G2A_Chattanooga::parse_csv( $csv );

		$this->assertCount( 2, $rows, 'The embedded-newline row must survive, not be torn in two and dropped.' );
		$this->assertSame( "Multi\nline desc", $rows[0]['Item Name'] );
		$this->assertSame( 'CT-2', $rows[1]['SKU'] );
	}

	public function test_parse_csv_stops_once_the_limit_is_reached(): void {
		$csv = "SKU,Item Name\nCT-1,A\nCT-2,B\nCT-3,C";

		$rows = G2A_Chattanooga::parse_csv( $csv, 2 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'CT-1', $rows[0]['SKU'] );
		$this->assertSame( 'CT-2', $rows[1]['SKU'] );
	}

	public function test_map_catalog_row_extracts_the_confirmed_real_header_fields(): void {
		$row = [
			'SKU'                      => 'CT-100',
			'Item Name'                => 'Test Rifle',
			'Manufacturer'             => 'Acme',
			'Manufacturer Item Number' => 'Model 9',
			'UPC'                      => '012345678905',
			'Price'                    => '$1,250.00',
			'Quantity In Stock'        => '7',
		];

		$item = G2A_Chattanooga::map_catalog_row( $row );

		$this->assertSame( 'CT-100', $item['item_no'] );
		$this->assertSame( 'Test Rifle', $item['description'] );
		$this->assertSame( 'Acme', $item['manufacturer'] );
		$this->assertSame( 'Model 9', $item['model'] );
		$this->assertSame( '012345678905', $item['upc'] );
		$this->assertSame( 1250.00, $item['price'], 'A currency symbol and thousands separator must not truncate the value.' );
		$this->assertSame( 7, $item['quantity'] );
		$this->assertFalse( $item['is_firearm'], 'No vendor-supplied FFL flag exists in the real feed -- must default to false, never guessed.' );
	}

	public function test_map_catalog_row_matches_headers_case_insensitively(): void {
		$row = [ 'sku' => 'CT-200', 'manufacturer' => 'Acme', 'drop ship price' => '19.99' ];

		$item = G2A_Chattanooga::map_catalog_row( $row );

		$this->assertSame( 'CT-200', $item['item_no'] );
		$this->assertSame( 'Acme', $item['manufacturer'] );
		$this->assertSame( 19.99, $item['price'], 'Falls back to Drop Ship Price when Price is absent.' );
	}

	public function test_a_store_supplied_filter_can_classify_a_firearm(): void {
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return 'wpistic_ffl_chattanooga_is_firearm' === $tag ? true : $value;
		} );
		$row = [ 'SKU' => 'CT-300', 'Category' => 'Handguns' ];

		$item = G2A_Chattanooga::map_catalog_row( $row );

		$this->assertTrue( $item['is_firearm'] );
	}
}
