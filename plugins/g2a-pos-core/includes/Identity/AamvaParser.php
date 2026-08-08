<?php

namespace G2A\POS\Identity;

/**
 * Parses the AAMVA DL/ID PDF417 barcode payload (versions 1-10).
 * Input is the raw text decoded from the barcode scanner.
 */
final class AamvaParser {

	private const FIELDS = array(
		'DCS' => 'family_name',
		'DAC' => 'given_name',
		'DCT' => 'given_name',
		'DAD' => 'middle_name',
		'DBB' => 'dob',
		'DBA' => 'expires',
		'DBD' => 'issued',
		'DAQ' => 'license_number',
		'DAG' => 'address_street',
		'DAI' => 'address_city',
		'DAJ' => 'address_state',
		'DAK' => 'address_zip',
		'DBC' => 'sex',
		'DAU' => 'height',
		'DAW' => 'weight_lb',
		'DAY' => 'eye_color',
		'DAZ' => 'hair_color',
		'DDE' => 'name_truncation_family',
		'DDF' => 'name_truncation_given',
		'DDG' => 'name_truncation_middle',
		'DCG' => 'country',
		'DCA' => 'vehicle_class',
		'DCB' => 'restrictions',
		'DCD' => 'endorsements',
	);

	public static function parse( string $payload ): ?array {
		if ( ! str_contains( $payload, 'ANSI ' ) ) {
			return null;
		}

		$fields = array();
		$lines  = preg_split( '/[\r\n]+/', $payload );
		foreach ( $lines as $line ) {
			if ( strlen( $line ) < 4 ) {
				continue;
			}
			$code  = substr( $line, 0, 3 );
			$value = trim( substr( $line, 3 ) );
			if ( ! isset( self::FIELDS[ $code ] ) || $value === '' ) {
				continue;
			}
			$key = self::FIELDS[ $code ];
			if ( ! isset( $fields[ $key ] ) ) {
				$fields[ $key ] = self::normalize( $key, $value );
			}
		}

		if ( ! isset( $fields['family_name'] ) || ! isset( $fields['given_name'] ) ) {
			return null;
		}

		return array(
			'family_name'    => $fields['family_name'] ?? null,
			'given_name'     => $fields['given_name'] ?? null,
			'middle_name'    => $fields['middle_name'] ?? null,
			'dob'            => $fields['dob'] ?? null,
			'license_number' => $fields['license_number'] ?? null,
			'expires'        => $fields['expires'] ?? null,
			'issued'         => $fields['issued'] ?? null,
			'address'        => array(
				'street' => $fields['address_street'] ?? null,
				'city'   => $fields['address_city'] ?? null,
				'state'  => $fields['address_state'] ?? null,
				'zip'    => substr( (string) ( $fields['address_zip'] ?? '' ), 0, 5 ) ?: null,
			),
			'sex'            => $fields['sex'] ?? null,
			'height'         => $fields['height'] ?? null,
			'weight_lb'      => $fields['weight_lb'] ?? null,
			'country'        => $fields['country'] ?? null,
		);
	}

	private static function normalize( string $key, string $value ): mixed {
		return match ( $key ) {
			'dob', 'expires', 'issued' => self::date( $value ),
			'address_state' => strtoupper( substr( $value, 0, 2 ) ),
			'sex' => self::sex( $value ),
			'weight_lb' => is_numeric( $value ) ? (int) $value : null,
			default => $value,
		};
	}

	private static function date( string $raw ): ?string {
		$raw = preg_replace( '/[^0-9]/', '', $raw );
		if ( strlen( $raw ) === 8 ) {
			// MMDDYYYY (US standard since AAMVA 2005)
			return sprintf( '%s-%s-%s', substr( $raw, 4, 4 ), substr( $raw, 0, 2 ), substr( $raw, 2, 2 ) );
		}
		return null;
	}

	private static function sex( string $raw ): ?string {
		$raw = trim( $raw );
		return match ( $raw ) {
			'1', 'M' => 'M',
			'2', 'F' => 'F',
			'9', 'X' => 'X',
			default => null,
		};
	}
}
