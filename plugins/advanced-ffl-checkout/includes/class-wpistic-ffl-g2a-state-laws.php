<?php
/**
 * G2A — State law engine extension to all 50 states + DC.
 *
 * Top-up routine that runs on every plugin load: ensures the state_rules
 * table has a baseline entry for every US state/territory we ship to. The
 * original Compliance class seeds 12; this fills the rest with neutral
 * notices ("standard federal rules apply") so the checkout's state-notice
 * widget never shows an empty result for a non-seeded state.
 *
 * Existing rows are never overwritten — admins / future updates that hand-
 * tune a specific state stay intact.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_State_Laws {

	const OPTION_SEEDED = 'wpistic_ffl_state_laws_50_seeded';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_seed' ], 30 );
	}

	public static function maybe_seed(): void {
		if ( (int) get_option( self::OPTION_SEEDED, 0 ) >= 2 ) {
			return; // versioned — bump when seed changes
		}
		global $wpdb;
		$table = DB::table( 'state_rules' );

		// Pull which states already have at least one rule.
		$existing = (array) $wpdb->get_col( "SELECT DISTINCT state_code FROM {$table}" );
		$existing = array_flip( array_map( 'strval', $existing ) );

		// v1.10.0 — filterable, same as the primary seed in DB::seed_state_rules(),
		// so a maintained legal-data feed can extend/override the top-up rows too.
		$baseline = (array) apply_filters( 'wpistic_ffl_state_rules_seed_topup', self::baseline_rules() );
		$version  = (string) get_option( 'wpistic_ffl_state_rules_version', '1.0' );

		foreach ( $baseline as $state => $rules ) {
			if ( isset( $existing[ $state ] ) ) {
				continue; // hand-tuned state — don't touch
			}
			foreach ( $rules as $rule ) {
				$wpdb->insert( $table, [ // phpcs:ignore WordPress.DB
					'state_code'   => $state,
					'rule_type'    => $rule[0],
					'description'  => $rule[1],
					'item_types'   => $rule[2] ?? 'handgun,rifle,shotgun',
					'is_active'    => 1,
					'source'       => 'builtin',
					'rule_version' => $version,
				] );
			}
		}

		update_option( self::OPTION_SEEDED, 2 );
	}

	/**
	 * Baseline rule entries by state. Where federal law is the only constraint
	 * we add a single "federal_only" entry so the front-end can show
	 * "standard federal background check required" rather than nothing.
	 *
	 * NOT legal advice — this is a checkout-notice helper. Admins are
	 * expected to tune per-state language to their own counsel.
	 */
	private static function baseline_rules(): array {
		$federal = [ [
			'federal_only',
			'Standard federal background check (NICS) required. No additional state-level permit, waiting period, or roster known.',
			'handgun,rifle,shotgun',
		] ];

		return [
			'AL' => $federal,
			'AK' => $federal,
			'AR' => $federal,
			// AZ — shall-issue, constitutional carry
			'AZ' => [ [ 'state_summary', 'Arizona: Constitutional carry state. NICS background check at the FFL is required; no separate state permit or waiting period.', 'handgun,rifle,shotgun' ] ],
			// CA already seeded (3 rules)
			// CO already seeded
			'CT' => [ [ 'eligibility_certificate', 'Connecticut: Eligibility Certificate, permit to carry pistol, or long-gun eligibility cert required. Additional state background check applies.', 'handgun,rifle,shotgun' ] ],
			'DE' => $federal,
			// DC — handgun roster + permit
			'DC' => [ [ 'permit_required', 'District of Columbia: Pistol registration required. Handgun roster restrictions apply.', 'handgun' ] ],
			'FL' => [ [ 'waiting_period_long_guns', 'Florida: 3-day waiting period for handgun purchases (CCW holders may be exempt).', 'handgun' ] ],
			'GA' => $federal,
			// HI already seeded
			'ID' => $federal,
			// IL already seeded (FOID)
			'IN' => $federal,
			'IA' => $federal,
			'KS' => $federal,
			'KY' => $federal,
			'LA' => $federal,
			'ME' => $federal,
			// MD already seeded
			// MA already seeded
			'MI' => [ [ 'permit_to_purchase', 'Michigan: Pistol purchase permit OR CPL required for handguns.', 'handgun' ] ],
			'MN' => [ [ 'permit_to_purchase', 'Minnesota: Permit to purchase OR permit to carry required for handguns + assault weapons. 7-day waiting period if no permit.', 'handgun' ] ],
			'MS' => $federal,
			'MO' => $federal,
			'MT' => $federal,
			'NE' => [ [ 'permit_to_purchase', 'Nebraska: Handgun purchase permit OR CCW required.', 'handgun' ] ],
			'NV' => $federal,
			'NH' => $federal,
			// NJ, NY already seeded
			'NM' => $federal,
			'NC' => [ [ 'permit_to_purchase', 'North Carolina: Pistol purchase permit OR concealed-carry permit required for handguns.', 'handgun' ] ],
			'ND' => $federal,
			'OH' => $federal,
			'OK' => $federal,
			'OR' => [ [ 'permit_to_purchase', 'Oregon: Measure 114 requires a permit-to-purchase + safety course. Standard NICS also applies.', 'handgun,rifle,shotgun' ] ],
			'PA' => [ [ 'state_check', 'Pennsylvania: State Police Instant Check System (PICS) supplements NICS for handguns.', 'handgun' ] ],
			'RI' => [ [ 'waiting_period', 'Rhode Island: 7-day waiting period for all firearms. Blue Card / safety course required for handguns.', 'handgun,rifle,shotgun' ] ],
			'SC' => $federal,
			'SD' => $federal,
			'TN' => $federal,
			'TX' => $federal,
			'UT' => $federal,
			'VT' => $federal,
			'VA' => [ [ 'limit_pistol_purchases', 'Virginia: Standard NICS. Verify any current state-level handgun-per-month limit before purchase.', 'handgun' ] ],
			// WA already seeded
			'WV' => $federal,
			'WI' => [ [ 'waiting_period', 'Wisconsin: 48-hour waiting period for handgun purchases.', 'handgun' ] ],
			'WY' => $federal,
		];
	}
}
