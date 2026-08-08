<?php
/**
 * System-health checks. Each check reports {status, detail, lastCheckedAt}.
 *
 * These are cheap — every one runs on the same request as the dashboard's
 * health tab. Anything expensive belongs in a scheduled `g2aba_health_recheck`
 * cron writing to an option, not here.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health_Provider {
	public function checks(): array {
		$now = gmdate( 'c' );

		$active_plugins = (array) get_option( 'active_plugins', array() );

		$plugin_checks = array();
		foreach (
			array(
				'g2a-business-api'                                        => 'g2a-business-api',
				'g2a-booking-engine'                                      => 'g2a-booking-engine',
				'memberistic-membership-solutions'                        => 'memberistic-membership-solutions',
				'advanced-ffl-checkout'                                   => 'advanced-ffl-checkout',
				'messageistic'                                            => 'messageistic',
			) as $label => $slug
		) {
			$installed = $this->is_plugin_installed( $active_plugins, $slug );
			$plugin_checks[] = array(
				'id'            => 'plugin-' . $slug,
				'label'         => $label . ' plugin',
				'group'         => 'plugins',
				'status'        => $installed ? 'ok' : 'warn',
				'detail'        => $installed ? 'Active' : 'Not installed / not active',
				'lastCheckedAt' => $now,
			);
		}

		$checks = array_merge(
			$plugin_checks,
			array(
				array(
					'id'            => 'cron-wp-cron',
					'label'         => 'WordPress cron',
					'group'         => 'cron',
					'status'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'ok' : 'warn',
					'detail'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
						? 'Real system cron is expected to trigger wp-cron.php'
						: 'DISABLE_WP_CRON not set — a real cron is recommended for scheduled jobs.',
					'lastCheckedAt' => $now,
				),
				array(
					'id'            => 'apis-woocommerce',
					'label'         => 'WooCommerce REST',
					'group'         => 'apis',
					'status'        => function_exists( 'wc_get_orders' ) ? 'ok' : 'warn',
					'detail'        => function_exists( 'wc_get_orders' ) ? 'Available' : 'WooCommerce not loaded',
					'lastCheckedAt' => $now,
				),
				array(
					'id'            => 'security-auth-key',
					'label'         => 'Encryption key (AUTH_KEY)',
					'group'         => 'security',
					'status'        => defined( 'AUTH_KEY' ) && strlen( (string) AUTH_KEY ) >= 32 ? 'ok' : 'error',
					'detail'        => defined( 'AUTH_KEY' ) && strlen( (string) AUTH_KEY ) >= 32
						? 'AUTH_KEY is defined and long enough for AES-256 derivation.'
						: 'AUTH_KEY missing or too short — regenerate in wp-config.php.',
					'lastCheckedAt' => $now,
				),
				array(
					'id'            => 'security-application-passwords',
					'label'         => 'Application passwords enabled',
					'group'         => 'security',
					'status'        => function_exists( 'wp_authenticate_application_password' ) ? 'ok' : 'warn',
					'detail'        => function_exists( 'wp_authenticate_application_password' )
						? 'Enabled (WP 5.6+)'
						: 'Not available — upgrade WordPress.',
					'lastCheckedAt' => $now,
				),
			)
		);

		return $checks;
	}

	/**
	 * Boolean configured/active flags for every integration the dashboard
	 * renders. Lets the frontend show "Not configured — connect in Settings"
	 * instead of zeroed charts when a provider is simply unconfigured.
	 *
	 * @return array<string, bool>
	 */
	public function integrations(): array {
		global $wpdb;

		$models = get_option( 'g2aba_models', array() );

		return array(
			'ga4Configured'          => ( new \WordPressistic\G2ABA\Integrations\GA4_Client() )->is_configured(),
			'gscConfigured'          => ( new \WordPressistic\G2ABA\Integrations\GSC_Client() )->is_configured(),
			'gbpConfigured'          => ( new \WordPressistic\G2ABA\Integrations\GBP_Client() )->is_configured(),
			'wooActive'              => function_exists( 'wc_get_orders' ),
			'bookingEngineActive'    => class_exists( '\G2AB_Analytics' )
				|| $this->table_exists( $wpdb->prefix . 'g2ab_bookings' ),
			'memberisticActive'      => class_exists( '\WordPressistic\Memberistic\Database\Schema' )
				|| $this->table_exists( $wpdb->prefix . 'memberistic_memberships' ),
			'messageisticActive'     => class_exists( '\Messageistic\REST\Dashboard_API' ),
			'formisticActive'        => class_exists( 'Wpistic_Formistic_Database' )
				|| $this->table_exists( $wpdb->prefix . 'wpistic_formistic_submissions' ),
			'aiConnectionConfigured' => is_array( $models ) && count( $models ) > 0,
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function is_plugin_installed( array $active_plugins, string $slug ): bool {
		foreach ( $active_plugins as $active ) {
			if ( 0 === strpos( (string) $active, $slug . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
