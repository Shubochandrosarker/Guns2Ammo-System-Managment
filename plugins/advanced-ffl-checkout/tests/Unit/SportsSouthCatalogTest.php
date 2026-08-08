<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Sports_South;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-sports-south.php';

/**
 * Sports South's DailyItemUpdate returns the standard ADO.NET DataSet XML
 * shape (<NewDataSet><Table>field...</Table></NewDataSet>) -- see
 * G2A_Sports_South's own docblock for the honesty note on why that shape,
 * not a live account, is what's being parsed against. These tests build a
 * <Table> row fixture directly (SimpleXMLElement, no network) and verify
 * the field mapping confirmed against two independent open-source clients.
 */
final class SportsSouthCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	private function row( string $xml ): \SimpleXMLElement {
		$doc = simplexml_load_string( '<NewDataSet><Table>' . $xml . '</Table></NewDataSet>' );
		return $doc->Table;
	}

	public function test_map_catalog_row_extracts_confirmed_fields(): void {
		$row = $this->row(
			'<ITEMNO>SS-1</ITEMNO><IDESC>Test Pistol</IDESC><SCNAM1>Acme</SCNAM1>' .
			'<IMODEL>Model 9</IMODEL><IUPC>012345678905</IUPC><CPRC>399.00</CPRC>' .
			'<PRC1>499.00</PRC1><QTYOH>7</QTYOH><ITYPE>H</ITYPE>'
		);

		$item = G2A_Sports_South::map_catalog_row( $row );

		$this->assertSame( 'SS-1', $item['item_no'] );
		$this->assertSame( 'Test Pistol', $item['description'] );
		$this->assertSame( 'Acme', $item['manufacturer'] );
		$this->assertSame( 'Model 9', $item['model'] );
		$this->assertSame( '012345678905', $item['upc'] );
		$this->assertSame( 399.00, $item['price'], 'Dealer cost (CPRC) wins over retail (PRC1) when both are present.' );
		$this->assertSame( 7, $item['quantity'] );
		$this->assertTrue( $item['is_firearm'], 'ITYPE "H" (handgun) is in the firearm-type set.' );
	}

	public function test_map_catalog_row_falls_back_to_retail_price_and_manufacturer_id(): void {
		$row = $this->row( '<ITEMNO>SS-2</ITEMNO><IMFGNO>MFG7</IMFGNO><PRC1>19.99</PRC1><ITYPE>A</ITYPE>' );

		$item = G2A_Sports_South::map_catalog_row( $row );

		$this->assertSame( 'MFG7', $item['manufacturer'] );
		$this->assertSame( 19.99, $item['price'] );
		$this->assertFalse( $item['is_firearm'], 'ITYPE "A" (accessory) is not in the firearm-type set.' );
	}

	public function test_a_store_supplied_filter_can_override_the_firearm_classification(): void {
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return 'wpistic_ffl_sports_south_is_firearm' === $tag ? true : $value;
		} );
		$row = $this->row( '<ITEMNO>SS-3</ITEMNO><ITYPE>A</ITYPE>' );

		$item = G2A_Sports_South::map_catalog_row( $row );

		$this->assertTrue( $item['is_firearm'] );
	}
}
