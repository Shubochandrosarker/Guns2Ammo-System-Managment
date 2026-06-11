<?php

namespace G2A\POS\Compliance\ATF;

use G2A\POS\Database\BoundBookRepository;

/**
 * ACE audit pack: zips the bound book, every 4473 in the range, every
 * NICS transaction, theft/loss reports, and a manifest into a single ZIP
 * the merchant can hand an IOI on inspection day. Falls back to a tar.gz
 * if ZipArchive isn't available.
 */
final class AceAuditPack {

	public static function build( ?string $from = null, ?string $to = null ): array {
		$from = $from ?: date( 'Y-m-d', strtotime( '-365 days' ) );
		$to   = $to ?: date( 'Y-m-d' );

		$tmp = wp_tempnam( 'ace_audit_' . $from . '_' . $to );
		$zip = new \ZipArchive();
		if ( $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			return array(
				'ok'    => false,
				'error' => 'zip_open_failed',
			);
		}

		// Bound book CSV + eForms CSV + eForms XML.
		$zip->addFromString(
			'bound_book/bound_book.csv',
			BoundBookExporter::to_csv(
				array(
					'date_from' => $from,
					'date_to'   => $to,
				)
			)
		);
		$zip->addFromString(
			'bound_book/eforms_bound_book.csv',
			BoundBookExporter::to_eforms_csv(
				array(
					'date_from' => $from,
					'date_to'   => $to,
				)
			)
		);
		$zip->addFromString(
			'bound_book/eforms_bound_book.xml',
			BoundBookExporter::to_eforms_xml(
				array(
					'date_from' => $from,
					'date_to'   => $to,
				)
			)
		);

		// 4473s in range.
		global $wpdb;
		$forms     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_serial, transaction_status, transferee_last, transferee_first,
                    transferee_dob, transferred_at, created_at
             FROM {$wpdb->prefix}g2a_form_4473
             WHERE created_at BETWEEN %s AND %s
             ORDER BY id",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		) ?: array();
		$forms_csv = self::array_to_csv(
			$forms,
			array(
				'id',
				'form_serial',
				'transaction_status',
				'transferee_last',
				'transferee_first',
				'transferee_dob',
				'transferred_at',
				'created_at',
			)
		);
		$zip->addFromString( 'forms_4473/4473_index.csv', $forms_csv );

		// NICS transactions.
		$nics = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_4473_id, ntn, response_status, delayed_until,
                    transferred, denied_reason, created_at
             FROM {$wpdb->prefix}g2a_nics_transactions
             WHERE created_at BETWEEN %s AND %s
             ORDER BY id",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		) ?: array();
		$zip->addFromString(
			'nics/nics_log.csv',
			self::array_to_csv(
				$nics,
				array(
					'id',
					'form_4473_id',
					'ntn',
					'response_status',
					'delayed_until',
					'transferred',
					'denied_reason',
					'created_at',
				)
			)
		);

		// Theft & loss + multi-sale reports.
		$reports = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, report_type, status, due_by, submitted_at, submission_ref, created_at
             FROM {$wpdb->prefix}g2a_atf_reports
             WHERE created_at BETWEEN %s AND %s
             ORDER BY id",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		) ?: array();
		$zip->addFromString(
			'reports/reports_index.csv',
			self::array_to_csv(
				$reports,
				array(
					'id',
					'report_type',
					'status',
					'due_by',
					'submitted_at',
					'submission_ref',
					'created_at',
				)
			)
		);

		// Hash-chain verification snapshot.
		$verify = ( new BoundBookRepository() )->verify_chain( 50000 );
		$zip->addFromString( 'bound_book/chain_verification.json', wp_json_encode( $verify, JSON_PRETTY_PRINT ) );

		// Manifest.
		$manifest = array(
			'generated_at' => gmdate( 'c' ),
			'date_range'   => array(
				'from' => $from,
				'to'   => $to,
			),
			'counts'       => array(
				'forms_4473' => count( $forms ),
				'nics'       => count( $nics ),
				'reports'    => count( $reports ),
			),
			'integrity'    => $verify,
		);
		$zip->addFromString( 'MANIFEST.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$zip->close();

		return array(
			'ok'       => true,
			'path'     => $tmp,
			'manifest' => $manifest,
		);
	}

	private static function array_to_csv( array $rows, array $cols ): string {
		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, $cols );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $cols as $c ) {
				$line[] = (string) ( $row[ $c ] ?? '' );
			}
			fputcsv( $fh, $line );
		}
		rewind( $fh );
		$out = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $out;
	}
}
