<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Database\BoundBookRepository;

/**
 * Exports the A&D bound book to CSV per ATF 27 CFR 478.125(e) columns.
 * Output stays plain-text for ATF compatibility and demand-letter response.
 */
final class BoundBookExporter {

	public const COLUMNS = array(
		'entry_number',
		'acquisition_date',
		'manufacturer',
		'importer',
		'model',
		'serial_number',
		'caliber',
		'firearm_type',
		'acquired_from_name',
		'acquired_from_address',
		'acquired_from_ffl',
		'disposition_date',
		'disposed_to_name',
		'disposed_to_address',
		'disposed_to_ffl_or_4473',
	);

	public static function to_csv( array $args = array() ): string {
		$repo = new BoundBookRepository();
		$rows = $repo->paginate(
			array_merge(
				array(
					'per_page' => 200000,
					'page'     => 1,
				),
				$args
			)
		);

		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, self::COLUMNS );

		foreach ( $rows as $row ) {
			fputcsv(
				$fh,
				array_map(
					array( self::class, 'csv_cell' ),
					array(
						(string) ( $row['entry_number'] ?? '' ),
						(string) ( $row['acq_date'] ?? '' ),
						(string) ( $row['manufacturer'] ?? '' ),
						(string) ( $row['importer'] ?? '' ),
						(string) ( $row['model'] ?? '' ),
						(string) ( $row['serial_number'] ?? '' ),
						(string) ( $row['caliber'] ?? '' ),
						(string) ( $row['firearm_type'] ?? '' ),
						(string) ( $row['acq_source_name'] ?? '' ),
						(string) ( $row['acq_source_address'] ?? '' ),
						(string) ( $row['acq_source_ffl'] ?? '' ),
						(string) ( $row['disp_date'] ?? '' ),
						(string) ( $row['disp_buyer_name'] ?? '' ),
						(string) ( $row['disp_buyer_address'] ?? '' ),
						( $row['disp_buyer_ffl'] ?? '' ) ?: ( isset( $row['disp_form4473_id'] ) ? '4473#' . $row['disp_form4473_id'] : '' ),
					)
				)
			);
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $csv;
	}

	/**
	 * ATF eForms-compatible CSV. Matches the column order accepted by the
	 * ATF eForms 7CR bound-book upload tool. Date columns are normalized to
	 * MM/DD/YYYY and FFL numbers are stripped of dashes per the eForms spec.
	 */
	public static function to_eforms_csv( array $args = array() ): string {
		$repo = new BoundBookRepository();
		$rows = $repo->paginate(
			array_merge(
				array(
					'per_page' => 200000,
					'page'     => 1,
				),
				$args
			)
		);

		$columns = array(
			'Entry Number',
			'Manufacturer',
			'Importer',
			'Country of Manufacture',
			'Model',
			'Serial Number',
			'Type',
			'Caliber/Gauge',
			'Date Acquired',
			'Acquired From Name',
			'Acquired From Address',
			'Acquired From FFL',
			'Date Disposed',
			'Disposed To Name',
			'Disposed To Address',
			'Disposed To FFL',
			'4473 Serial',
			'Notes',
		);

		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, $columns );

		foreach ( $rows as $row ) {
			fputcsv(
				$fh,
				array_map(
					array( self::class, 'csv_cell' ),
					array(
						(string) ( $row['entry_number'] ?? '' ),
						(string) ( $row['manufacturer'] ?? '' ),
						(string) ( $row['importer'] ?? '' ),
						(string) ( $row['country_of_manufacture'] ?? '' ),
						(string) ( $row['model'] ?? '' ),
						(string) ( $row['serial_number'] ?? '' ),
						(string) ( $row['firearm_type'] ?? '' ),
						(string) ( $row['caliber'] ?? '' ),
						self::format_date( $row['acq_date'] ?? null ),
						(string) ( $row['acq_source_name'] ?? '' ),
						(string) ( $row['acq_source_address'] ?? '' ),
						self::strip_ffl( $row['acq_source_ffl'] ?? '' ),
						self::format_date( $row['disp_date'] ?? null ),
						(string) ( $row['disp_buyer_name'] ?? '' ),
						(string) ( $row['disp_buyer_address'] ?? '' ),
						self::strip_ffl( $row['disp_buyer_ffl'] ?? '' ),
						(string) ( $row['disp_form4473_id'] ?? '' ),
						(string) ( $row['notes'] ?? '' ),
					)
				)
			);
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $csv;
	}

	/**
	 * XML envelope matching the ATF eForms eBound Book exchange schema.
	 * The schema accepts a flat <FirearmRecord> repeated per row.
	 */
	public static function to_eforms_xml( array $args = array() ): string {
		$repo = new BoundBookRepository();
		$rows = $repo->paginate(
			array_merge(
				array(
					'per_page' => 200000,
					'page'     => 1,
				),
				$args
			)
		);

		$xml = new \SimpleXMLElement( '<?xml version="1.0" encoding="UTF-8"?><EBoundBookExport/>' );
		$xml->addAttribute( 'GeneratedAt', gmdate( 'c' ) );
		$xml->addAttribute( 'Format', 'ATF-eForms-7CR-v1' );

		foreach ( $rows as $row ) {
			$rec = $xml->addChild( 'FirearmRecord' );
			$rec->addChild( 'EntryNumber', htmlspecialchars( (string) ( $row['entry_number'] ?? '' ) ) );
			$rec->addChild( 'Manufacturer', htmlspecialchars( (string) ( $row['manufacturer'] ?? '' ) ) );
			$rec->addChild( 'Importer', htmlspecialchars( (string) ( $row['importer'] ?? '' ) ) );
			$rec->addChild( 'Model', htmlspecialchars( (string) ( $row['model'] ?? '' ) ) );
			$rec->addChild( 'SerialNumber', htmlspecialchars( (string) ( $row['serial_number'] ?? '' ) ) );
			$rec->addChild( 'Type', htmlspecialchars( (string) ( $row['firearm_type'] ?? '' ) ) );
			$rec->addChild( 'Caliber', htmlspecialchars( (string) ( $row['caliber'] ?? '' ) ) );

			$acq = $rec->addChild( 'Acquisition' );
			$acq->addChild( 'Date', self::format_date( $row['acq_date'] ?? null ) );
			$acq->addChild( 'Name', htmlspecialchars( (string) ( $row['acq_source_name'] ?? '' ) ) );
			$acq->addChild( 'Address', htmlspecialchars( (string) ( $row['acq_source_address'] ?? '' ) ) );
			$acq->addChild( 'FFL', self::strip_ffl( $row['acq_source_ffl'] ?? '' ) );

			if ( ! empty( $row['disp_date'] ) ) {
				$disp = $rec->addChild( 'Disposition' );
				$disp->addChild( 'Date', self::format_date( $row['disp_date'] ) );
				$disp->addChild( 'Name', htmlspecialchars( (string) ( $row['disp_buyer_name'] ?? '' ) ) );
				$disp->addChild( 'Address', htmlspecialchars( (string) ( $row['disp_buyer_address'] ?? '' ) ) );
				$disp->addChild( 'FFL', self::strip_ffl( $row['disp_buyer_ffl'] ?? '' ) );
				if ( ! empty( $row['disp_form4473_id'] ) ) {
					$disp->addChild( 'Form4473Ref', (string) $row['disp_form4473_id'] );
				}
			}
		}

		$dom                     = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = true;
		$dom->loadXML( $xml->asXML() );
		return (string) $dom->saveXML();
	}

	/** Neutralise spreadsheet formula injection (same approach as CrmController::csv_cell). */
	private static function csv_cell( $value ): string {
		$value = (string) $value;
		if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private static function format_date( $value ): string {
		if ( ! $value ) {
			return '';
		}
		$ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		return $ts ? date( 'm/d/Y', $ts ) : '';
	}

	private static function strip_ffl( $ffl ): string {
		return preg_replace( '/[^A-Za-z0-9]/', '', (string) $ffl ) ?? '';
	}
}
