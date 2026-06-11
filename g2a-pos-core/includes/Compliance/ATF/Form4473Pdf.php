<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Database\Form4473Repository;
use setasign\Fpdi\Fpdi;

/**
 * Form 4473 PDF renderer.
 *
 * Two modes, selected automatically:
 *
 * 1. OVERLAY (when assets/templates/4473.pdf exists)
 *    Imports each page of the official ATF 5300.9 template via FPDI and stamps
 *    field values at the coordinates defined in assets/templates/4473-fields.json.
 *    This is the legally-correct printable form.
 *
 * 2. FALLBACK (when no template is installed)
 *    Generates a clean, multi-page record using FPDF. Not the official form,
 *    but a faithful transcript suitable for the customer-copy receipt and for
 *    internal records until the official template is dropped in.
 *
 * The official 5300.9 PDF must be supplied by the licensee — it is not bundled
 * because ATF revises it (last revision Aug 2023) and we do not want a stale
 * form going into production. Drop atf.gov's 4473-part-1-firearms-transaction-record-over-the-counter-atf-form-53009.pdf
 * at assets/templates/4473.pdf to enable overlay mode.
 */
final class Form4473Pdf {

	public static function render( int $form_4473_id ): string {
		$repo = new Form4473Repository();
		$form = $repo->find( $form_4473_id );
		if ( ! $form ) {
			throw new \RuntimeException( '4473 not found.' );
		}

		$template = self::template_path();
		$map      = self::field_map();

		if ( $template !== null && $map !== null ) {
			if ( ! class_exists( Fpdi::class ) ) {
				throw new \RuntimeException( 'PDF engine missing: run `composer install` in the g2a-pos-core plugin directory (setasign/fpdi is required for overlay rendering).' );
			}
			return self::render_overlay( $form, $template, $map );
		}
		if ( ! class_exists( \FPDF::class ) ) {
			// Don't fatal the whole request when the composer vendors were
			// never installed — surface an actionable error instead.
			throw new \RuntimeException( 'PDF engine missing: run `composer install` in the g2a-pos-core plugin directory (setasign/fpdf is required for 4473 rendering).' );
		}
		return self::render_fallback( $form );
	}

	public static function template_available(): bool {
		return self::template_path() !== null && self::field_map() !== null;
	}

	private static function template_path(): ?string {
		$candidates = array();
		if ( defined( 'G2A_POS_CORE_PATH' ) ) {
			$candidates[] = G2A_POS_CORE_PATH . 'assets/templates/4473.pdf';
		}
		$override = function_exists( 'get_option' ) ? (string) get_option( 'g2a_pos_4473_template_path', '' ) : '';
		if ( $override !== '' ) {
			$candidates[] = $override;
		}
		foreach ( $candidates as $path ) {
			if ( is_string( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		return null;
	}

	private static function field_map(): ?array {
		if ( ! defined( 'G2A_POS_CORE_PATH' ) ) {
			return null;
		}
		$path = G2A_POS_CORE_PATH . 'assets/templates/4473-fields.json';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function render_overlay( array $form, string $template, array $map ): string {
		$pdf = new Fpdi();
		$pdf->SetCreator( 'G2A POS' );
		$pdf->SetAuthor( 'G2A POS' );
		$pdf->SetTitle( 'ATF Form 4473 — ' . ( $form['form_serial'] ?? '' ) );
		$page_count = $pdf->setSourceFile( $template );
		$values     = self::resolve_values( $form );

		for ( $page = 1; $page <= $page_count; $page++ ) {
			$tpl_id = $pdf->importPage( $page );
			$size   = $pdf->getTemplateSize( $tpl_id );
			$pdf->AddPage( ( $size['width'] > $size['height'] ) ? 'L' : 'P', array( $size['width'], $size['height'] ) );
			$pdf->useTemplate( $tpl_id );

			foreach ( $map as $field_key => $coord ) {
				if ( (int) ( $coord['page'] ?? 1 ) !== $page ) {
					continue;
				}
				if ( ! isset( $values[ $field_key ] ) ) {
					continue;
				}
				$val = (string) $values[ $field_key ];
				if ( $val === '' ) {
					continue;
				}
				$pdf->SetFont( $coord['font'] ?? 'Helvetica', $coord['style'] ?? '', (float) ( $coord['size'] ?? 10 ) );
				$pdf->SetTextColor( 0, 0, 0 );
				$pdf->SetXY( (float) $coord['x'], (float) $coord['y'] );
				if ( ! empty( $coord['width'] ) ) {
					$pdf->Cell( (float) $coord['width'], (float) ( $coord['height'] ?? 5 ), self::enc( $val ), 0, 0, $coord['align'] ?? 'L' );
				} else {
					$pdf->Write( 5, self::enc( $val ) );
				}
			}
		}

		return (string) $pdf->Output( 'S' );
	}

	private static function render_fallback( array $form ): string {
		$pdf = new \FPDF( 'P', 'mm', 'Letter' );
		$pdf->SetCreator( 'G2A POS' );
		$pdf->SetAuthor( 'G2A POS' );
		$pdf->SetTitle( 'ATF Form 4473 transcript — ' . ( $form['form_serial'] ?? '' ) );
		$pdf->AddPage();

		$pdf->SetFont( 'Helvetica', 'B', 14 );
		$pdf->Cell( 0, 8, self::enc( 'ATF Form 4473 — Firearms Transaction Record (transcript)' ), 0, 1 );
		$pdf->SetFont( 'Helvetica', '', 9 );
		$pdf->Cell( 0, 5, self::enc( 'Form serial: ' . ( $form['form_serial'] ?? '' ) ), 0, 1 );
		$pdf->Cell( 0, 5, self::enc( 'Status: ' . ( $form['transaction_status'] ?? '' ) ), 0, 1 );
		$pdf->Ln( 3 );

		self::section( $pdf, 'Section A — Transferee' );
		$name = trim( ( $form['transferee_last'] ?? '' ) . ', ' . ( $form['transferee_first'] ?? '' ) . ' ' . ( $form['transferee_middle'] ?? '' ) );
		self::kv( $pdf, 'Name', $name );
		self::kv( $pdf, 'Date of birth', (string) ( $form['transferee_dob'] ?? '' ) );
		self::kv( $pdf, 'Address', trim( ( $form['transferee_address'] ?? '' ) . ', ' . ( $form['transferee_city'] ?? '' ) . ', ' . ( $form['transferee_state'] ?? '' ) . ' ' . ( $form['transferee_zip'] ?? '' ) ) );
		self::kv( $pdf, 'County', (string) ( $form['transferee_county'] ?? '' ) );
		self::kv( $pdf, 'Phone', (string) ( $form['transferee_phone'] ?? '' ) );
		self::kv( $pdf, 'Email', (string) ( $form['transferee_email'] ?? '' ) );
		self::kv( $pdf, 'Place of birth', (string) ( $form['transferee_place_of_birth'] ?? '' ) );
		self::kv( $pdf, 'Sex / Ht / Wt', sprintf( '%s / %s / %s', $form['transferee_sex'] ?? '', $form['transferee_height_in'] ?? '', $form['transferee_weight_lb'] ?? '' ) );
		self::kv( $pdf, 'Ethnicity / Race', sprintf( '%s / %s', $form['transferee_ethnicity'] ?? '', $form['transferee_race'] ?? '' ) );
		self::kv( $pdf, 'US citizen', ! empty( $form['us_citizen'] ) ? 'Yes' : 'No' );
		if ( empty( $form['us_citizen'] ) ) {
			self::kv( $pdf, 'Country of citizenship', (string) ( $form['country_of_citizenship'] ?? '' ) );
			self::kv( $pdf, 'USCIS / I-94', sprintf( '%s / %s', $form['uscis_number'] ?? '', $form['i94_number'] ?? '' ) );
		}
		self::kv( $pdf, 'ID type', (string) ( $form['id_type'] ?? '' ) );
		self::kv( $pdf, 'ID number', (string) ( $form['id_number'] ?? '' ) );
		self::kv( $pdf, 'ID issuer', (string) ( $form['id_issuer'] ?? '' ) );
		self::kv( $pdf, 'ID expires', (string) ( $form['id_expiration'] ?? '' ) );
		if ( ! empty( $form['supplemental_id_type'] ) ) {
			self::kv( $pdf, 'Supplemental ID', sprintf( '%s %s', $form['supplemental_id_type'], $form['supplemental_id_number'] ?? '' ) );
		}
		$pdf->Ln( 2 );

		self::section( $pdf, 'Section B — Transferee certification answers' );
		$answers = (array) ( $form['section_b_answers'] ?? array() );
		if ( empty( $answers ) ) {
			$pdf->SetFont( 'Helvetica', 'I', 9 );
			$pdf->Cell( 0, 5, self::enc( 'No section-B answers recorded.' ), 0, 1 );
		} else {
			$pdf->SetFont( 'Helvetica', '', 9 );
			foreach ( $answers as $q => $a ) {
				self::kv( $pdf, (string) $q, is_bool( $a ) ? ( $a ? 'Yes' : 'No' ) : (string) $a );
			}
		}
		$pdf->Ln( 2 );

		self::section( $pdf, 'Section D — Firearms' );
		$firearms = (array) ( $form['firearms'] ?? array() );
		if ( ! $firearms ) {
			$pdf->SetFont( 'Helvetica', 'I', 9 );
			$pdf->Cell( 0, 5, self::enc( 'No firearms recorded.' ), 0, 1 );
		} else {
			$pdf->SetFont( 'Helvetica', 'B', 9 );
			$pdf->Cell( 8, 6, '#', 1, 0, 'C' );
			$pdf->Cell( 40, 6, self::enc( 'Manufacturer' ), 1, 0 );
			$pdf->Cell( 40, 6, self::enc( 'Model' ), 1, 0 );
			$pdf->Cell( 40, 6, self::enc( 'Serial number' ), 1, 0 );
			$pdf->Cell( 25, 6, self::enc( 'Type' ), 1, 0 );
			$pdf->Cell( 30, 6, self::enc( 'Caliber' ), 1, 1 );
			$pdf->SetFont( 'Helvetica', '', 9 );
			foreach ( $firearms as $i => $f ) {
				$pdf->Cell( 8, 6, (string) ( $i + 1 ), 1, 0, 'C' );
				$pdf->Cell( 40, 6, self::enc( (string) ( $f['manufacturer'] ?? '' ) ), 1, 0 );
				$pdf->Cell( 40, 6, self::enc( (string) ( $f['model'] ?? '' ) ), 1, 0 );
				$pdf->Cell( 40, 6, self::enc( (string) ( $f['serial_number'] ?? '' ) ), 1, 0 );
				$pdf->Cell( 25, 6, self::enc( (string) ( $f['firearm_type'] ?? '' ) ), 1, 0 );
				$pdf->Cell( 30, 6, self::enc( (string) ( $f['caliber'] ?? '' ) ), 1, 1 );
			}
		}
		$pdf->Ln( 2 );

		self::section( $pdf, 'NICS' );
		self::kv( $pdf, 'NTN', (string) ( $form['nics_ntn'] ?? '—' ) );
		self::kv( $pdf, 'Response', (string) ( $form['nics_response'] ?? 'pending' ) );
		self::kv( $pdf, 'Response date', (string) ( $form['nics_response_date'] ?? '—' ) );
		if ( ! empty( $form['default_proceed_eligible_at'] ) ) {
			self::kv( $pdf, 'Default proceed eligible at', (string) $form['default_proceed_eligible_at'] );
		}
		$pdf->Ln( 2 );

		self::section( $pdf, 'Signatures & disposition' );
		self::kv( $pdf, 'Transferee signed', (string) ( $form['transferee_signature_at'] ?? '—' ) );
		self::kv( $pdf, 'Transferor signed', (string) ( $form['transferor_signature_at'] ?? '—' ) );
		self::kv( $pdf, 'Transferred at', (string) ( $form['transferred_at'] ?? '—' ) );
		self::kv( $pdf, 'Transferor user', (string) ( $form['transferor_user_id'] ?? '—' ) );
		self::kv( $pdf, 'Retention until', (string) ( $form['retention_until'] ?? '—' ) );

		$pdf->SetY( -15 );
		$pdf->SetFont( 'Helvetica', 'I', 7 );
		$pdf->Cell( 0, 5, self::enc( 'Generated by G2A POS Core ' . ( defined( 'G2A_POS_CORE_VERSION' ) ? G2A_POS_CORE_VERSION : '' ) . ' at ' . current_time( 'mysql' ) . ' — transcript only; not a substitute for ATF Form 5300.9' ), 0, 0, 'C' );

		return (string) $pdf->Output( 'S' );
	}

	private static function resolve_values( array $form ): array {
		$firearms = (array) ( $form['firearms'] ?? array() );
		$values   = array(
			'transferee.last'           => $form['transferee_last'] ?? '',
			'transferee.first'          => $form['transferee_first'] ?? '',
			'transferee.middle'         => $form['transferee_middle'] ?? '',
			'transferee.suffix'         => $form['transferee_suffix'] ?? '',
			'transferee.dob'            => $form['transferee_dob'] ?? '',
			'transferee.place_of_birth' => $form['transferee_place_of_birth'] ?? '',
			'transferee.address'        => $form['transferee_address'] ?? '',
			'transferee.city'           => $form['transferee_city'] ?? '',
			'transferee.state'          => $form['transferee_state'] ?? '',
			'transferee.zip'            => $form['transferee_zip'] ?? '',
			'transferee.county'         => $form['transferee_county'] ?? '',
			'transferee.phone'          => $form['transferee_phone'] ?? '',
			'transferee.height_in'      => $form['transferee_height_in'] ?? '',
			'transferee.weight_lb'      => $form['transferee_weight_lb'] ?? '',
			'transferee.sex'            => $form['transferee_sex'] ?? '',
			'transferee.ethnicity'      => $form['transferee_ethnicity'] ?? '',
			'transferee.race'           => $form['transferee_race'] ?? '',
			'us_citizen'                => ! empty( $form['us_citizen'] ) ? 'X' : '',
			'non_citizen'               => empty( $form['us_citizen'] ) ? 'X' : '',
			'country_of_citizenship'    => $form['country_of_citizenship'] ?? '',
			'uscis'                     => $form['uscis_number'] ?? '',
			'i94'                       => $form['i94_number'] ?? '',
			'id.type'                   => $form['id_type'] ?? '',
			'id.number'                 => $form['id_number'] ?? '',
			'id.issuer'                 => $form['id_issuer'] ?? '',
			'id.expires'                => $form['id_expiration'] ?? '',
			'nics.ntn'                  => $form['nics_ntn'] ?? '',
			'nics.response'             => $form['nics_response'] ?? '',
			'nics.response_date'        => $form['nics_response_date'] ?? '',
			'transferee.signed_at'      => $form['transferee_signature_at'] ?? '',
			'transferor.signed_at'      => $form['transferor_signature_at'] ?? '',
			'transferred_at'            => $form['transferred_at'] ?? '',
			'form_serial'               => $form['form_serial'] ?? '',
		);
		foreach ( $firearms as $i => $f ) {
			$values[ "firearm.{$i}.manufacturer" ]  = $f['manufacturer'] ?? '';
			$values[ "firearm.{$i}.importer" ]      = $f['importer'] ?? '';
			$values[ "firearm.{$i}.model" ]         = $f['model'] ?? '';
			$values[ "firearm.{$i}.serial_number" ] = $f['serial_number'] ?? '';
			$values[ "firearm.{$i}.firearm_type" ]  = $f['firearm_type'] ?? '';
			$values[ "firearm.{$i}.caliber" ]       = $f['caliber'] ?? '';
		}
		return $values;
	}

	private static function section( \FPDF $pdf, string $title ): void {
		$pdf->SetFont( 'Helvetica', 'B', 11 );
		$pdf->SetFillColor( 240, 240, 240 );
		$pdf->Cell( 0, 7, self::enc( $title ), 0, 1, 'L', true );
		$pdf->SetFont( 'Helvetica', '', 9 );
	}

	private static function kv( \FPDF $pdf, string $key, string $value ): void {
		$pdf->SetFont( 'Helvetica', 'B', 9 );
		$pdf->Cell( 45, 5, self::enc( $key . ':' ), 0, 0 );
		$pdf->SetFont( 'Helvetica', '', 9 );
		$pdf->Cell( 0, 5, self::enc( $value ), 0, 1 );
	}

	/**
	 * FPDF uses CP1252-ish "Windows-1252". Convert UTF-8 input.
	 */
	private static function enc( string $s ): string {
		$out = @iconv( 'UTF-8', 'CP1252//TRANSLIT', $s );
		return $out !== false ? $out : $s;
	}
}
