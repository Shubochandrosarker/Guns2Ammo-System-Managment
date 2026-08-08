<?php
/**
 * Small utility that renders internal store shapes to CSV / text.
 *
 * Kept as a set of pure functions so unit tests can assert every escaping
 * subtlety (embedded commas, quotes, CRLF) without spinning up REST.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Export_Renderer {
	/**
	 * Render a header row + a list of associative rows as RFC 4180 CSV.
	 *
	 * - Every cell is stringified, quoted, and internal quotes are doubled.
	 * - Row terminator is CRLF (RFC 4180).
	 * - Header columns are the union of keys the caller passes explicitly.
	 *
	 * @param array<int, string>            $columns   Column keys, in output order.
	 * @param array<int, array<string,mixed>> $rows      Row objects. Missing keys → empty cell.
	 * @param array<string, string>|null    $labels    Optional map from key to header label. Defaults to key.
	 */
	public static function to_csv( array $columns, array $rows, ?array $labels = null ): string {
		$out    = array();
		$header = array();
		foreach ( $columns as $key ) {
			$header[] = self::escape_cell( $labels[ $key ] ?? $key );
		}
		$out[] = implode( ',', $header );

		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $columns as $key ) {
				$cells[] = self::escape_cell( self::stringify( $row[ $key ] ?? '' ) );
			}
			$out[] = implode( ',', $cells );
		}
		return implode( "\r\n", $out ) . "\r\n";
	}

	public static function escape_cell( $value ): string {
		$s = self::stringify( $value );
		return '"' . str_replace( '"', '""', $s ) . '"';
	}

	public static function stringify( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Compose the plain-text body for a report delivery. The body itself is
	 * pre-composed by Report_Generator; this fn just adds a header block so
	 * downloaded files stand on their own.
	 *
	 * @param array{id?:string, label?:string, generatedAt?:string, body?:string, range?:array} $report
	 */
	public static function to_report_text( array $report ): string {
		$lines = array(
			sprintf( '# %s', (string) ( $report['label'] ?? $report['id'] ?? 'Report' ) ),
			sprintf( 'Generated: %s', (string) ( $report['generatedAt'] ?? '' ) ),
		);
		if ( isset( $report['range']['from'], $report['range']['to'] ) ) {
			$lines[] = sprintf( 'Range: %s → %s', $report['range']['from'], $report['range']['to'] );
		}
		$lines[] = '';
		$lines[] = (string) ( $report['body'] ?? '' );
		return implode( "\n", $lines );
	}
}
