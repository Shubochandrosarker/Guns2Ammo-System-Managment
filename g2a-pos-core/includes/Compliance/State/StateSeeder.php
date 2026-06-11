<?php

namespace G2A\POS\Compliance\State;

final class StateSeeder {

	public static function seed_if_empty(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'g2a_state_compliance_rules';
		$count = (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}
		self::seed();
	}

	public static function seed(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'g2a_state_compliance_rules';
		$now   = current_time( 'mysql' );
		$today = current_time( 'Y-m-d' );

		foreach ( self::rules() as $row ) {
			$row['effective_at'] = $today;
			$row['updated_at']   = $now;
			if ( isset( $row['rules_payload'] ) && is_array( $row['rules_payload'] ) ) {
				$row['rules_payload'] = wp_json_encode( $row['rules_payload'] );
			}
			$wpdb->replace( $table, $row );
		}
	}

	private static function defaults( string $code, string $name ): array {
		return array(
			'state_code'                           => $code,
			'state_name'                           => $name,
			'handgun_min_age'                      => 21,
			'long_gun_min_age'                     => 18,
			'ammo_handgun_min_age'                 => 21,
			'ammo_long_gun_min_age'                => 18,
			'waiting_period_hours_handgun'         => 0,
			'waiting_period_hours_long_gun'        => 0,
			'requires_permit_to_purchase_handgun'  => 0,
			'requires_permit_to_purchase_long_gun' => 0,
			'permit_label'                         => null,
			'requires_foid'                        => 0,
			'requires_dros'                        => 0,
			'requires_state_nics_check'            => 0,
			'state_nics_label'                     => null,
			'requires_safety_certificate'          => 0,
			'safety_certificate_label'             => null,
			'roster_required'                      => 0,
			'one_handgun_per_30_days'              => 0,
			'assault_weapon_restricted'            => 0,
			'magazine_capacity_limit'              => null,
			'requires_long_gun_multi_sale_report'  => 0,
			'ffl_to_ffl_required_for_nonresident'  => 1,
			'rules_payload'                        => array(
				'source'              => 'seed',
				'verify_with_counsel' => true,
			),
		);
	}

	private static function rules(): array {
		$states = array(
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
		);

		$rows = array();
		foreach ( $states as $code => $name ) {
			$rows[ $code ] = self::defaults( $code, $name );
		}

		// ATF Form 3310.12 multi-rifle border states
		foreach ( array( 'AZ', 'CA', 'NM', 'TX' ) as $code ) {
			$rows[ $code ]['requires_long_gun_multi_sale_report'] = 1;
		}

		// California
		$rows['CA'] = array_merge(
			$rows['CA'],
			array(
				'waiting_period_hours_handgun'  => 240,
				'waiting_period_hours_long_gun' => 240,
				'requires_dros'                 => 1,
				'requires_state_nics_check'     => 1,
				'state_nics_label'              => 'CA DROS / DOJ',
				'requires_safety_certificate'   => 1,
				'safety_certificate_label'      => 'CA Firearm Safety Certificate (FSC)',
				'roster_required'               => 1,
				'one_handgun_per_30_days'       => 1,
				'assault_weapon_restricted'     => 1,
				'magazine_capacity_limit'       => 10,
				'long_gun_min_age'              => 21,
				'ammo_long_gun_min_age'         => 21,
				'rules_payload'                 => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
					'notes'               => 'CA: 10-day waiting, DROS, FSC, roster, 1-handgun/30-days, AWB.',
				),
			)
		);

		// Colorado
		$rows['CO'] = array_merge(
			$rows['CO'],
			array(
				'waiting_period_hours_handgun'  => 72,
				'waiting_period_hours_long_gun' => 72,
				'long_gun_min_age'              => 21,
				'magazine_capacity_limit'       => 15,
				'rules_payload'                 => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
					'notes'               => 'CO: 3-day waiting (2023), long-gun 21.',
				),
			)
		);

		// Connecticut
		$rows['CT'] = array_merge(
			$rows['CT'],
			array(
				'requires_permit_to_purchase_handgun' => 1,
				'permit_label'                        => 'CT Permit to Purchase / Eligibility Certificate',
				'requires_state_nics_check'           => 1,
				'state_nics_label'                    => 'CT DESPP',
				'assault_weapon_restricted'           => 1,
				'magazine_capacity_limit'             => 10,
				'long_gun_min_age'                    => 21,
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Delaware
		$rows['DE'] = array_merge(
			$rows['DE'],
			array(
				'waiting_period_hours_handgun'  => 72,
				'waiting_period_hours_long_gun' => 72,
				'long_gun_min_age'              => 21,
				'magazine_capacity_limit'       => 17,
				'assault_weapon_restricted'     => 1,
				'rules_payload'                 => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// DC
		$rows['DC'] = array_merge(
			$rows['DC'],
			array(
				'requires_permit_to_purchase_handgun' => 1,
				'permit_label'                        => 'DC Firearms Registration',
				'magazine_capacity_limit'             => 10,
				'assault_weapon_restricted'           => 1,
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Florida
		$rows['FL'] = array_merge(
			$rows['FL'],
			array(
				'waiting_period_hours_handgun'  => 72,
				'waiting_period_hours_long_gun' => 72,
				'long_gun_min_age'              => 21,
				'rules_payload'                 => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Hawaii
		$rows['HI'] = array_merge(
			$rows['HI'],
			array(
				'requires_permit_to_purchase_handgun'  => 1,
				'requires_permit_to_purchase_long_gun' => 1,
				'permit_label'                         => 'HI Permit to Acquire',
				'waiting_period_hours_handgun'         => 336,
				'magazine_capacity_limit'              => 10,
				'assault_weapon_restricted'            => 1,
				'rules_payload'                        => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
					'notes'               => 'HI: permit to acquire, 14-day permit issuance period.',
				),
			)
		);

		// Illinois - FOID
		$rows['IL'] = array_merge(
			$rows['IL'],
			array(
				'requires_foid'                        => 1,
				'requires_permit_to_purchase_handgun'  => 1,
				'requires_permit_to_purchase_long_gun' => 1,
				'permit_label'                         => 'IL FOID Card',
				'waiting_period_hours_handgun'         => 72,
				'waiting_period_hours_long_gun'        => 72,
				'long_gun_min_age'                     => 21,
				'assault_weapon_restricted'            => 1,
				'magazine_capacity_limit'              => 10,
				'rules_payload'                        => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Iowa
		$rows['IA'] = array_merge(
			$rows['IA'],
			array(
				'requires_permit_to_purchase_handgun' => 1,
				'permit_label'                        => 'IA Permit to Acquire (or CCW)',
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Maryland
		$rows['MD'] = array_merge(
			$rows['MD'],
			array(
				'requires_permit_to_purchase_handgun' => 1,
				'permit_label'                        => 'MD Handgun Qualification License (HQL)',
				'waiting_period_hours_handgun'        => 168,
				'long_gun_min_age'                    => 21,
				'roster_required'                     => 1,
				'assault_weapon_restricted'           => 1,
				'magazine_capacity_limit'             => 10,
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Massachusetts
		$rows['MA'] = array_merge(
			$rows['MA'],
			array(
				'requires_permit_to_purchase_handgun'  => 1,
				'requires_permit_to_purchase_long_gun' => 1,
				'permit_label'                         => 'MA LTC / FID Card',
				'roster_required'                      => 1,
				'assault_weapon_restricted'            => 1,
				'magazine_capacity_limit'              => 10,
				'rules_payload'                        => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Michigan - pistol purchase permit
		$rows['MI'] = array_merge(
			$rows['MI'],
			array(
				'requires_permit_to_purchase_handgun' => 1,
				'permit_label'                        => 'MI License to Purchase (or CPL)',
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
					'notes'               => 'MI: pistol purchase permit + pistol sales record (RI-060).',
				),
			)
		);

		// Minnesota
		$rows['MN'] = array_merge(
			$rows['MN'],
			array(
				'requires_permit_to_purchase_handgun'  => 1,
				'requires_permit_to_purchase_long_gun' => 1,
				'permit_label'                         => 'MN Permit to Purchase (handgun/assault rifle)',
				'waiting_period_hours_handgun'         => 168,
				'rules_payload'                        => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Nebraska
		$rows['NE'] = array_merge(
			$rows['NE'],
			array(
				'requires_permit_to_purchase_handgun' => 1,
				'permit_label'                        => 'NE Handgun Purchase Certificate',
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// New Jersey
		$rows['NJ'] = array_merge(
			$rows['NJ'],
			array(
				'requires_permit_to_purchase_handgun'  => 1,
				'requires_permit_to_purchase_long_gun' => 1,
				'permit_label'                         => 'NJ FID Card / Permit to Purchase Handgun',
				'waiting_period_hours_handgun'         => 168,
				'one_handgun_per_30_days'              => 1,
				'assault_weapon_restricted'            => 1,
				'magazine_capacity_limit'              => 10,
				'rules_payload'                        => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// New York
		$rows['NY'] = array_merge(
			$rows['NY'],
			array(
				'requires_permit_to_purchase_handgun' => 1,
				'permit_label'                        => 'NY Pistol Permit',
				'requires_state_nics_check'           => 1,
				'state_nics_label'                    => 'NY NICS (NICS-IS)',
				'long_gun_min_age'                    => 21,
				'assault_weapon_restricted'           => 1,
				'magazine_capacity_limit'             => 10,
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
					'notes'               => 'NY: state-conducted NICS via NICS-IS; semiautomatic rifle license (CCIA) for 18-20 prohibited.',
				),
			)
		);

		// North Carolina
		$rows['NC'] = array_merge(
			$rows['NC'],
			array(
				'requires_permit_to_purchase_handgun' => 0,
				'rules_payload'                       => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
					'notes'               => 'NC: pistol purchase permit repealed 2023; CCH still recognized.',
				),
			)
		);

		// Oregon
		$rows['OR'] = array_merge(
			$rows['OR'],
			array(
				'requires_state_nics_check' => 1,
				'state_nics_label'          => 'OSP Firearms Instant Check',
				'rules_payload'             => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Pennsylvania
		$rows['PA'] = array_merge(
			$rows['PA'],
			array(
				'requires_state_nics_check' => 1,
				'state_nics_label'          => 'PA PICS',
				'rules_payload'             => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Rhode Island
		$rows['RI'] = array_merge(
			$rows['RI'],
			array(
				'waiting_period_hours_handgun'  => 168,
				'waiting_period_hours_long_gun' => 168,
				'long_gun_min_age'              => 21,
				'magazine_capacity_limit'       => 10,
				'rules_payload'                 => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Tennessee
		$rows['TN'] = array_merge(
			$rows['TN'],
			array(
				'requires_state_nics_check' => 1,
				'state_nics_label'          => 'TN TICS',
				'rules_payload'             => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Utah
		$rows['UT'] = array_merge(
			$rows['UT'],
			array(
				'requires_state_nics_check' => 1,
				'state_nics_label'          => 'BCI Utah',
				'rules_payload'             => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Vermont
		$rows['VT'] = array_merge(
			$rows['VT'],
			array(
				'waiting_period_hours_handgun' => 72,
				'long_gun_min_age'             => 21,
				'magazine_capacity_limit'      => 10,
				'rules_payload'                => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Virginia
		$rows['VA'] = array_merge(
			$rows['VA'],
			array(
				'requires_state_nics_check' => 1,
				'state_nics_label'          => 'VSP State Police',
				'rules_payload'             => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// Washington
		$rows['WA'] = array_merge(
			$rows['WA'],
			array(
				'waiting_period_hours_handgun'  => 240,
				'waiting_period_hours_long_gun' => 240,
				'long_gun_min_age'              => 21,
				'requires_safety_certificate'   => 1,
				'safety_certificate_label'      => 'WA Firearm Safety Training (semiautomatic rifle)',
				'magazine_capacity_limit'       => 10,
				'assault_weapon_restricted'     => 1,
				'rules_payload'                 => array(
					'source'              => 'seed',
					'verify_with_counsel' => true,
				),
			)
		);

		// ---- NICS-bypass via qualifying state permit (18 USC 922(t)(3)) ----
		// Source: ATF "Permanent Brady Permit Chart". The list moves over time
		// — verify with current ATF guidance before relying in production.
		$bypass = array(
			'AK' => array(
				'label'         => 'AK Concealed Handgun Permit',
				'max_age_years' => 5,
			),
			'AZ' => array(
				'label'         => 'AZ Concealed Weapons Permit (CCW)',
				'max_age_years' => 5,
			),
			'AR' => array(
				'label'         => 'AR Concealed Handgun License',
				'max_age_years' => 5,
			),
			'GA' => array(
				'label'         => 'GA Weapons Carry License',
				'max_age_years' => 5,
			),
			'HI' => array(
				'label'         => 'HI License to Carry',
				'max_age_years' => 5,
			),
			'ID' => array(
				'label'         => 'ID Enhanced Concealed Carry License',
				'max_age_years' => 5,
			),
			'IA' => array(
				'label'         => 'IA Permit to Carry Weapons',
				'max_age_years' => 5,
			),
			'KS' => array(
				'label'         => 'KS Concealed Handgun License',
				'max_age_years' => 4,
			),
			'KY' => array(
				'label'         => 'KY Concealed Deadly Weapons License',
				'max_age_years' => 5,
			),
			'LA' => array(
				'label'         => 'LA Concealed Handgun Permit',
				'max_age_years' => 5,
			),
			'MI' => array(
				'label'         => 'MI Concealed Pistol License',
				'max_age_years' => 5,
			),
			'MS' => array(
				'label'         => 'MS Permit to Carry',
				'max_age_years' => 5,
			),
			'MT' => array(
				'label'         => 'MT Concealed Weapons Permit',
				'max_age_years' => 5,
			),
			'NE' => array(
				'label'         => 'NE Concealed Handgun Permit',
				'max_age_years' => 5,
			),
			'NV' => array(
				'label'         => 'NV Concealed Firearm Permit (post-7/2011)',
				'max_age_years' => 5,
			),
			'NC' => array(
				'label'         => 'NC Concealed Handgun Permit',
				'max_age_years' => 5,
				'handgun_only'  => 1,
			),
			'ND' => array(
				'label'         => 'ND Concealed Weapons License (Class 1)',
				'max_age_years' => 5,
			),
			'OH' => array(
				'label'         => 'OH Concealed Handgun License',
				'max_age_years' => 5,
			),
			'SC' => array(
				'label'         => 'SC Concealable Weapons Permit',
				'max_age_years' => 5,
			),
			'SD' => array(
				'label'         => 'SD Enhanced / Gold Concealed Pistol Permit',
				'max_age_years' => 5,
			),
			'TN' => array(
				'label'         => 'TN Enhanced Handgun Carry Permit',
				'max_age_years' => 5,
			),
			'TX' => array(
				'label'         => 'TX License to Carry (LTC)',
				'max_age_years' => 5,
			),
			'UT' => array(
				'label'         => 'UT Concealed Firearm Permit',
				'max_age_years' => 5,
			),
			'VA' => array(
				'label'         => 'VA Concealed Handgun Permit',
				'max_age_years' => 5,
			),
			'WV' => array(
				'label'         => 'WV Concealed Pistol License',
				'max_age_years' => 5,
			),
			'WY' => array(
				'label'         => 'WY Concealed Firearm Permit',
				'max_age_years' => 5,
			),
		);
		foreach ( $bypass as $code => $spec ) {
			if ( ! isset( $rows[ $code ] ) ) {
				continue;
			}
			$rows[ $code ] = array_merge(
				$rows[ $code ],
				array(
					'ccw_bypasses_nics'       => 1,
					'ccw_max_age_years'       => (int) $spec['max_age_years'],
					'ccw_permit_label'        => (string) $spec['label'],
					'ccw_qualifying_citation' => '18 USC 922(t)(3); ATF Permanent Brady Permit Chart',
					'ccw_handgun_only'        => isset( $spec['handgun_only'] ) ? 1 : 0,
				)
			);
		}

		return array_values( $rows );
	}
}
