<?php

namespace G2A\POS\Range;

use G2A\POS\Database\RangeWaiverRepository;

/**
 * Imports OtterWaiver CSV exports into the g2a_range_waivers table.
 *
 * Columns expected (case-insensitive, exact OtterWaiver layout — 51 columns):
 *   Adult First Name, Adult Last Name, Adult Birthday, Age, Email,
 *   Email Opt-In Status, Phone, Phone Opt-In Status, Country, City, State,
 *   Street, Postal Code, Participant Type, Date, Title, Flag, Unique ID,
 *   Source, Check In 1..10, Signed On, Document URL, Certificate URL,
 *   Emergency Contact Name, Emergency Contact Phone, Minor Name, Minor Age,
 *   Question 1..8, Answer 1..8, Second Signer Name, Second Signer Email,
 *   Countersign Status
 *
 * Dedupes on (source, unique_ref); falls back to (email, waiver_date) when
 * Unique ID is missing.
 */
final class OtterWaiverCsvImporter {

	public function import( string $csvPath, array $options = array() ): array {
		if ( ! is_readable( $csvPath ) ) {
			return array(
				'ok'    => false,
				'error' => 'csv_not_readable',
			);
		}

		$fh = fopen( $csvPath, 'r' );
		if ( ! $fh ) {
			return array(
				'ok'    => false,
				'error' => 'fopen_failed',
			);
		}

		$header = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! $header ) {
			fclose( $fh );
			return array(
				'ok'    => false,
				'error' => 'empty_csv',
			);
		}
		$idx = array();
		foreach ( $header as $i => $col ) {
			$idx[ strtolower( trim( (string) $col ) ) ] = $i;
		}

		$repo  = new RangeWaiverRepository();
		$stats = array(
			'rows_total'   => 0,
			'rows_created' => 0,
			'rows_updated' => 0,
			'rows_skipped' => 0,
			'rows_failed'  => 0,
		);

		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
			++$stats['rows_total'];
			try {
				$mapped = self::mapRow( $row, $idx );
				if ( ! $mapped ) {
					++$stats['rows_skipped'];
					continue;
				}
				$res = $repo->upsertFromExternal( $mapped );
				if ( $res['action'] === 'created' ) {
					++$stats['rows_created'];
				} elseif ( $res['action'] === 'updated' ) {
					++$stats['rows_updated'];
				} else {
					++$stats['rows_skipped'];
				}
			} catch ( \Throwable $e ) {
				++$stats['rows_failed'];
				if ( ! isset( $stats['errors'] ) ) {
					$stats['errors'] = array();
				}
				if ( count( $stats['errors'] ) < 20 ) {
					$stats['errors'][] = $e->getMessage();
				}
			}
		}
		fclose( $fh );

		return array(
			'ok'    => true,
			'stats' => $stats,
		);
	}

	public static function mapRow( array $row, array $idx ): ?array {
		$val   = static function ( string $col ) use ( $row, $idx ): ?string {
			$key = strtolower( $col );
			if ( ! isset( $idx[ $key ] ) ) {
				return null;
			}
			$i = $idx[ $key ];
			$v = $row[ $i ] ?? '';
			$v = trim( (string) $v );
			if ( $v === '' || $v === '""""' || $v === '""' ) {
				return null;
			}
			return $v;
		};
		$optin = static fn( ?string $v ) => $v !== null && strtolower( $v ) === 'yes';

		$first = $val( 'Adult First Name' );
		$last  = $val( 'Adult Last Name' );
		if ( ! $first && ! $last ) {
			return null;
		}

		$checkIns = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$c = $val( "Check In $i" );
			if ( $c ) {
				$checkIns[] = self::normalizeDatetime( $c );
			}
		}

		$questions = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$q = $val( "Question $i" );
			$a = $val( "Answer $i" );
			if ( $q || $a ) {
				$questions[] = array(
					'question' => $q ?? '',
					'answer'   => $a ?? '',
				);
			}
		}

		return array(
			'source'                  => 'otterwaiver',
			'unique_ref'              => $val( 'Unique ID' ),
			'first_name'              => $first ?? '',
			'last_name'               => $last ?? '',
			'dob'                     => self::normalizeDate( $val( 'Adult Birthday' ) ),
			'age'                     => $val( 'Age' ) ? (int) $val( 'Age' ) : null,
			'email'                   => $val( 'Email' ),
			'email_optin'             => $optin( $val( 'Email Opt-In Status' ) ),
			'phone'                   => $val( 'Phone' ),
			'phone_optin'             => $optin( $val( 'Phone Opt-In Status' ) ),
			'country'                 => $val( 'Country' ),
			'city'                    => $val( 'City' ),
			'state'                   => $val( 'State' ),
			'street'                  => $val( 'Street' ),
			'postal_code'             => $val( 'Postal Code' ),
			'participant_type'        => $val( 'Participant Type' ) ?? 'Adult',
			'waiver_date'             => self::normalizeDatetime( $val( 'Date' ) ),
			'title'                   => $val( 'Title' ),
			'flag'                    => $val( 'Flag' ),
			'signed_on'               => self::normalizeDatetime( $val( 'Signed On' ) ),
			'document_url'            => $val( 'Document URL' ),
			'certificate_url'         => $val( 'Certificate URL' ),
			'emergency_contact_name'  => $val( 'Emergency Contact Name' ),
			'emergency_contact_phone' => $val( 'Emergency Contact Phone' ),
			'minor_name'              => $val( 'Minor Name' ),
			'minor_age'               => $val( 'Minor Age' ) ? (int) $val( 'Minor Age' ) : null,
			'questions'               => $questions ?: null,
			'check_ins'               => $checkIns ?: null,
			'check_in_count'          => count( $checkIns ),
			'second_signer_name'      => $val( 'Second Signer Name' ),
			'second_signer_email'     => $val( 'Second Signer Email' ),
			'countersign_status'      => $val( 'Countersign Status' ),
		);
	}

	private static function normalizeDate( ?string $raw ): ?string {
		if ( ! $raw ) {
			return null;
		}
		// OtterWaiver uses MM/DD/YYYY.
		$ts = strtotime( $raw );
		return $ts ? date( 'Y-m-d', $ts ) : null;
	}

	private static function normalizeDatetime( ?string $raw ): ?string {
		if ( ! $raw ) {
			return null;
		}
		// ISO-8601 (with Z) from OtterWaiver — convert to MySQL DATETIME (UTC).
		$ts = strtotime( $raw );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
