<?php
/**
 * License gate — feature-flag stub for free / Pro / Agency tiers.
 *
 * Today: returns unlocked for everything when WPISTIC_FFL_UNLIMITED is true
 *        (set in main bootstrap, also overridable via wp-config.php).
 *
 * v1.2.0 plan:
 *   - Connects to https://wordpressistic.com/wp-json/wpistic-licenses/v1/activate
 *   - User flow: PMPro membership purchase → license key in member profile
 *   - Plugin stores license key + cached entitlements (7-day grace if API unreachable)
 *   - Weekly revalidation cron
 *
 * Usage in code:
 *   if ( License::can( 'pro_theming' ) ) { ... }
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class License {

	const TIER_FREE   = 'free';
	const TIER_PRO    = 'pro';
	const TIER_AGENCY = 'agency';

	/** Map of feature → minimum required tier. */
	private static array $features = [
		// Free
		'core_transfers'         => self::TIER_FREE,
		'dealer_database'        => self::TIER_FREE,
		'portal_basic'           => self::TIER_FREE,
		'preset_themes'          => self::TIER_FREE,

		// Pro
		'pro_theming'            => self::TIER_PRO,
		'custom_logo'            => self::TIER_PRO,
		'custom_css'             => self::TIER_PRO,
		'remove_branding'        => self::TIER_PRO,
		'all_2fa_methods'        => self::TIER_PRO,
		'custom_token_expiry'    => self::TIER_PRO,
		'auto_reminders'         => self::TIER_PRO,
		'webhooks'               => self::TIER_PRO,
		'csv_export'             => self::TIER_PRO,
		'extended_analytics'     => self::TIER_PRO,
		'elementor_widget'       => self::TIER_PRO,

		// Agency
		'sms_fallback'           => self::TIER_AGENCY,
		'email_parser'           => self::TIER_AGENCY,
		'multi_site'             => self::TIER_AGENCY,
		'priority_support'       => self::TIER_AGENCY,
	];

	/** Tier weights for comparison. */
	private static array $weights = [
		self::TIER_FREE   => 0,
		self::TIER_PRO    => 10,
		self::TIER_AGENCY => 20,
	];

	/**
	 * Is the plugin currently in an unlocked / activated state?
	 */
	public static function is_active(): bool {
		if ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) {
			return true;
		}
		$entitlements = self::entitlements();
		return ! empty( $entitlements['active'] );
	}

	/**
	 * Current tier: 'free' | 'pro' | 'agency'.
	 */
	public static function tier(): string {
		if ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) {
			return self::TIER_AGENCY;
		}
		$entitlements = self::entitlements();
		return $entitlements['tier'] ?? self::TIER_FREE;
	}

	/**
	 * Feature gate.
	 *
	 * @param string $feature  Feature key from the $features map.
	 */
	public static function can( string $feature ): bool {
		// Hard unlock for client / dev / agency builds
		if ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) {
			return true;
		}

		$required = self::$features[ $feature ] ?? self::TIER_PRO;
		$current  = self::tier();

		return ( self::$weights[ $current ] ?? 0 ) >= ( self::$weights[ $required ] ?? 999 );
	}

	/**
	 * Activate license against wordpressistic.com.
	 *
	 * STUB for v1.1.0 — always returns success when UNLIMITED constant is set.
	 * Real implementation lands in v1.2.0.
	 */
	public static function activate( string $license_key ): bool|\WP_Error {
		if ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) {
			update_option( 'wpistic_ffl_license', [
				'key'       => substr( sanitize_text_field( $license_key ), 0, 64 ),
				'tier'      => self::TIER_AGENCY,
				'active'    => true,
				'activated' => time(),
				'site_url'  => home_url(),
				'mode'      => 'unlimited',
			], false );
			return true;
		}

		// v1.2.0: POST to wordpressistic.com/wp-json/wpistic-licenses/v1/activate
		return new \WP_Error( 'not_implemented', __( 'Remote license activation will be available in v1.2.0.', 'advanced-ffl-checkout' ) );
	}

	public static function deactivate(): void {
		delete_option( 'wpistic_ffl_license' );
	}

	/**
	 * Cached entitlements (set by activate()).
	 */
	public static function entitlements(): array {
		$ent = get_option( 'wpistic_ffl_license', [] );
		return is_array( $ent ) ? $ent : [];
	}

	/**
	 * Should the "Powered by Wordpressistic" branding be shown?
	 * Pro+ tier removes branding when toggled off.
	 */
	public static function show_branding(): bool {
		if ( ! self::can( 'remove_branding' ) ) {
			return true;
		}
		$theme = get_option( 'wpistic_ffl_theme_settings', [] );
		// Defaults to showing branding even on Pro until admin opts out.
		return ! empty( $theme['show_branding'] );
	}
}
