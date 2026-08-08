<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Export\Export_Renderer;

class ExportRendererTest extends TestCase {
	public function test_to_csv_emits_header_and_row_with_crlf() {
		$out = Export_Renderer::to_csv(
			array( 'id', 'title' ),
			array( array( 'id' => 't1', 'title' => 'Hello' ) )
		);
		$this->assertSame( "\"id\",\"title\"\r\n\"t1\",\"Hello\"\r\n", $out );
	}

	public function test_to_csv_quotes_embedded_quotes() {
		$out = Export_Renderer::to_csv(
			array( 'title' ),
			array( array( 'title' => 'She said "hi"' ) )
		);
		$this->assertStringContainsString( '"She said ""hi"""', $out );
	}

	public function test_to_csv_survives_embedded_commas_and_newlines() {
		$out = Export_Renderer::to_csv(
			array( 'body' ),
			array( array( 'body' => "line1, line2\nline3" ) )
		);
		// A cell containing commas and newlines must remain within its quoted string.
		$this->assertStringContainsString( "\"line1, line2\nline3\"", $out );
	}

	public function test_to_csv_maps_labels_when_provided() {
		$out = Export_Renderer::to_csv(
			array( 'ts' ),
			array( array( 'ts' => '2026-07-03T09:00:00Z' ) ),
			array( 'ts' => 'Timestamp' )
		);
		$this->assertStringStartsWith( "\"Timestamp\"", $out );
	}

	public function test_to_csv_writes_empty_string_for_missing_keys() {
		$out = Export_Renderer::to_csv(
			array( 'a', 'b' ),
			array( array( 'a' => 'X' ) )
		);
		$this->assertStringContainsString( "\"X\",\"\"", $out );
	}

	public function test_stringify_converts_scalars() {
		$this->assertSame( '',        Export_Renderer::stringify( null ) );
		$this->assertSame( 'true',    Export_Renderer::stringify( true ) );
		$this->assertSame( 'false',   Export_Renderer::stringify( false ) );
		$this->assertSame( '42',      Export_Renderer::stringify( 42 ) );
		$this->assertSame( '3.14',    Export_Renderer::stringify( 3.14 ) );
		$this->assertSame( 'hello',   Export_Renderer::stringify( 'hello' ) );
	}

	public function test_stringify_json_encodes_arrays_and_objects() {
		$this->assertSame( '{"a":1}', Export_Renderer::stringify( array( 'a' => 1 ) ) );
	}

	public function test_to_report_text_includes_metadata_block() {
		$out = Export_Renderer::to_report_text( array(
			'id'          => 'weekly-business',
			'label'       => 'Weekly business report',
			'generatedAt' => '2026-07-03T07:00:00Z',
			'range'       => array( 'from' => '2026-06-27', 'to' => '2026-07-03' ),
			'body'        => "Revenue: $48,124\nBookings: 486",
		) );
		$this->assertStringStartsWith( '# Weekly business report', $out );
		$this->assertStringContainsString( 'Generated: 2026-07-03T07:00:00Z', $out );
		$this->assertStringContainsString( 'Range: 2026-06-27 → 2026-07-03', $out );
		$this->assertStringContainsString( 'Revenue: $48,124', $out );
	}
}
