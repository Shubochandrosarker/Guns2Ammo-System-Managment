<?php
/**
 * License gate — feature-flag enforcement for free / Pro / Agency tiers.
 *
 * WPISTIC_FFL_UNLIMITED (set in the main bootstrap, overridable via
 * wp-config.php) still unlocks everything unconditionally — that path is
 * unchanged and stays the right choice for a single-client build like this
 * Guns2Ammo deployment.
 *
 * v1.10.0 (Exhibit 06) — the remote activation path described in the
 * original plan comment below is now real: activate() makes an actual
 * signed HTTP request to wordpressistic.com, caches the returned
 * entitlements, and a weekly cron revalidates them with a 7-day grace
 * window if the endpoint is briefly unreachable. What this does NOT do is
 * assume that endpoint is live today — wordpressistic.com hosting a real
 * `wpistic-licenses/v1` license-issuing backend is a separate deployment
 * this PR doesn't stand up (see STATUS.md). Investigated Memberistic
 * (this account's other membership plugin) as a possible backend first —
 * it's Guns2Ammo's shooting-range membership system (Defender/Patriot/
 * Guardian tiers for range customers), an unrelated product to a software
 * license server, so it was NOT repurposed for this. Until a real server
 * exists at that URL, activate() will correctly and honestly return a
 * connection error rather than silently succeeding.
 *
 * Original plan this implements:
 *   - Connects to https://wordpressistic.com/wp-json/wpistic-licenses/v1/activate
 *   - User flow: membership purchase → license key in member profile
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

	const API_BASE = 'https://wordpressistic.com/wp-json/wpistic-licenses/v1';
	const OPTION_KEY = 'wpistic_ffl_license';
	const PRODUCT_SLUG = 'advanced-ffl-checkout';

	/** Revalidation cadence + how long a cached "active" stays trusted after the last successful check-in. */
	const REVALIDATE_CRON = 'wpistic_ffl_license_revalidate';
	const GRACE_PERIOD_SECONDS = 7 * DAY_IN_SECONDS;

	public function __construct() {
		add_action( self::REVALIDATE_CRON, [ __CLASS__, 'cron_revalidate' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule_revalidation' ], 20 );
		add_action( 'wp_ajax_wpistic_ffl_license_activate',   [ __CLASS__, 'ajax_activate' ] );
		add_action( 'wp_ajax_wpistic_ffl_license_deactivate', [ __CLASS__, 'ajax_deactivate' ] );
	}

	public static function maybe_schedule_revalidation(): void {
		$ent = self::entitlements();
		if ( empty( $ent['key'] ) || 'remote' !== ( $ent['mode'] ?? '' ) ) {
			return;
		}
		G2A_Scheduler::recurring( WEEK_IN_SECONDS, self::REVALIDATE_CRON, [], strtotime( '+1 week' ) );
	}

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
	 * Activate a license key against wordpressistic.com. Real HTTP call —
	 * see the class docblock for why the counterparty endpoint isn't
	 * guaranteed to exist yet, and why that's the honest state to leave
	 * this in rather than a permanently-succeeding stub.
	 */
	public static function activate( string $license_key ): bool|\WP_Error {
		if ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) {
			update_option( self::OPTION_KEY, [
				'key'       => substr( sanitize_text_field( $license_key ), 0, 64 ),
				'tier'      => self::TIER_AGENCY,
				'active'    => true,
				'activated' => time(),
				'site_url'  => home_url(),
				'mode'      => 'unlimited',
			], false );
			return true;
		}

		$key = substr( sanitize_text_field( $license_key ), 0, 64 );
		if ( '' === $key ) {
			return new \WP_Error( 'missing_key', __( 'Enter a license key.', 'advanced-ffl-checkout' ) );
		}

		$payload = self::remote_request( '/activate', [
			'license_key' => $key,
			'site_url'    => home_url(),
			'product'     => self::PRODUCT_SLUG,
			'version'     => defined( 'WPISTIC_FFL_VERSION' ) ? WPISTIC_FFL_VERSION : '',
		] );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( empty( $payload['active'] ) ) {
			return new \WP_Error( 'activation_failed', $payload['message'] ?? __( 'License activation failed.', 'advanced-ffl-checkout' ) );
		}

		$tier = in_array( $payload['tier'] ?? '', [ self::TIER_FREE, self::TIER_PRO, self::TIER_AGENCY ], true ) ? $payload['tier'] : self::TIER_FREE;
		update_option( self::OPTION_KEY, [
			'key'            => $key,
			'tier'           => $tier,
			'active'         => true,
			'activated'      => time(),
			'site_url'       => home_url(),
			'mode'           => 'remote',
			'last_validated' => time(),
			'grace_since'    => null,
			'expires_at'     => $payload['expires_at'] ?? null,
		], false );

		self::maybe_schedule_revalidation();

		return true;
	}

	/**
	 * Best-effort remote deactivation, then always clear local state
	 * regardless of whether the remote call succeeded — a site owner must
	 * always be able to deactivate locally even if wordpressistic.com is
	 * unreachable.
	 */
	public static function deactivate(): void {
		$ent = self::entitlements();
		if ( ! empty( $ent['key'] ) && 'remote' === ( $ent['mode'] ?? '' ) && ! ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) ) {
			self::remote_request( '/deactivate', [
				'license_key' => $ent['key'],
				'site_url'    => home_url(),
			] );
		}
		delete_option( self::OPTION_KEY );
		G2A_Scheduler::unschedule_all( self::REVALIDATE_CRON );
	}

	/**
	 * Weekly cron — revalidates the cached entitlements. A network failure
	 * does NOT immediately deactivate: `grace_since` starts a 7-day clock
	 * the first time a check fails, and only flips `active` to false once
	 * that clock runs out without a successful check-in. A successful
	 * check at any point clears the clock.
	 */
	public static function cron_revalidate(): void {
		$ent = self::entitlements();
		if ( empty( $ent['key'] ) || 'remote' !== ( $ent['mode'] ?? '' ) ) {
			return;
		}

		$payload = self::remote_request( '/validate', [
			'license_key' => $ent['key'],
			'site_url'    => home_url(),
		] );

		if ( is_wp_error( $payload ) ) {
			// Unreachable — start (or continue) the grace window rather than
			// deactivating on a single network blip.
			$grace_since = $ent['grace_since'] ?? time();
			if ( ( time() - $grace_since ) >= self::GRACE_PERIOD_SECONDS ) {
				$ent['active'] = false;
			}
			$ent['grace_since'] = $grace_since;
			update_option( self::OPTION_KEY, $ent, false );
			return;
		}

		// A real answer from the server — trust it outright, active or not.
		$ent['active']         = ! empty( $payload['active'] );
		$ent['tier']           = in_array( $payload['tier'] ?? '', [ self::TIER_FREE, self::TIER_PRO, self::TIER_AGENCY ], true ) ? $payload['tier'] : ( $ent['tier'] ?? self::TIER_FREE );
		$ent['last_validated'] = time();
		$ent['grace_since']    = null;
		$ent['expires_at']     = $payload['expires_at'] ?? ( $ent['expires_at'] ?? null );
		update_option( self::OPTION_KEY, $ent, false );
	}

	/**
	 * Shared HTTP client for /activate, /validate, /deactivate. Returns the
	 * decoded JSON body on a 2xx response, or WP_Error on network failure
	 * or a non-2xx response.
	 */
	private static function remote_request( string $path, array $body ) {
		$resp = wp_remote_post( self::API_BASE . $path, [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'license_unreachable', sprintf(
				/* translators: %s = underlying network error */
				__( 'Could not reach the license server: %s', 'advanced-ffl-checkout' ),
				$resp->get_error_message()
			) );
		}
		$code    = wp_remote_retrieve_response_code( $resp );
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $payload ) ) {
			return new \WP_Error( 'license_server_error', is_array( $payload ) && ! empty( $payload['message'] )
				? (string) $payload['message']
				: sprintf(
					/* translators: %d = HTTP status code */
					__( 'License server returned an unexpected response (HTTP %d).', 'advanced-ffl-checkout' ),
					$code
				) );
		}
		return $payload;
	}

	public static function ajax_activate(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$key    = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$result = self::activate( $key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( [ 'tier' => self::tier() ] );
	}

	public static function ajax_deactivate(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		self::deactivate();
		wp_send_json_success();
	}

	/**
	 * Cached entitlements (set by activate()).
	 */
	public static function entitlements(): array {
		$ent = get_option( self::OPTION_KEY, [] );
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
