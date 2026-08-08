<?php
/**
 * G2A — Distributor registry.
 *
 * Mirrors the pattern already established by G2A_Carrier_Providers and the
 * background-check provider registry: a filterable metadata list plus a
 * fully-implemented default set, rather than a formal interface every
 * distributor class must implement. Distributor wire protocols vary too
 * much (REST/JSON for Lipsey's and Chattanooga, SOAP-ish ASMX form-POST
 * for Sports South, FTP/FTPS file exchange for RSR and Bill Hicks & Co.)
 * for one shared method signature to mean the same thing across all of
 * them -- each class exposes its own sync_catalog()/submit-order method,
 * and this registry only holds the metadata the admin page needs to
 * render tabs and route AJAX actions to the right class.
 *
 * As of v1.21.0, each distributor is also independently toggleable (see
 * G2A_Addons_Admin, "🧩 Add-ons") -- a store that only uses one or two of
 * these turns the rest off, so the main bootstrap never instantiates
 * their class (no hooks/AJAX actions registered) and their tab
 * disappears from the shared "📦 Distributors" page. Enabled-by-default
 * for every known slug so an already-running site upgrading to this
 * version sees no functional change until it explicitly disables one --
 * disabling never deletes that distributor's settings/catalog/order
 * history, only stops it from loading.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Distributor_Registry {

	const ADDON_OPTION_KEY = 'wpistic_ffl_distributor_addons';

	/**
	 * [ slug => [ label, class, has_catalog_sync, has_order_submission, protocol ] ].
	 * Additional distributors can register via the
	 * wpistic_ffl_distributor_providers filter.
	 */
	public static function known_distributors(): array {
		$default = [
			'lipseys' => [
				'label'                 => "Lipsey's",
				'class'                 => G2A_Lipseys::class,
				'has_catalog_sync'      => true,
				'has_order_submission'  => true,
				'protocol'              => 'REST (JSON)',
			],
			'sports_south' => [
				'label'                 => 'Sports South',
				'class'                 => G2A_Sports_South::class,
				'has_catalog_sync'      => true,
				'has_order_submission'  => true,
				'protocol'              => 'SOAP-style ASMX (form POST)',
			],
			'rsr' => [
				'label'                 => 'RSR Group',
				'class'                 => G2A_Rsr::class,
				'has_catalog_sync'      => true,
				'has_order_submission'  => true,
				'protocol'              => 'FTPS file exchange',
				// The only distributor that self-schedules a recurring
				// wp-cron event -- disabling it must clear this too, or the
				// event stays scheduled forever with nothing hooked to it
				// (see set_enabled()).
				'cron_hook'             => G2A_Rsr::CRON_HOOK,
			],
			'bill_hicks' => [
				'label'                 => 'Bill Hicks & Co.',
				'class'                 => G2A_Bill_Hicks::class,
				'has_catalog_sync'      => true,
				'has_order_submission'  => true,
				'protocol'              => 'FTP file exchange',
			],
			'chattanooga' => [
				'label'                 => 'Chattanooga Shooting Supplies',
				'class'                 => G2A_Chattanooga::class,
				'has_catalog_sync'      => true,
				'has_order_submission'  => true,
				'protocol'              => 'REST (JSON)',
			],
		];

		return (array) apply_filters( 'wpistic_ffl_distributor_providers', $default );
	}

	public static function get( string $slug ): ?array {
		$all = self::known_distributors();
		return $all[ $slug ] ?? null;
	}

	/** Enabled-by-default (see class docblock) -- absence from the stored settings means "on," not "off." */
	public static function is_enabled( string $slug ): bool {
		$settings = (array) get_option( self::ADDON_OPTION_KEY, [] );
		return ! isset( $settings[ $slug ] ) || (bool) $settings[ $slug ];
	}

	public static function set_enabled( string $slug, bool $enabled ): void {
		$settings          = (array) get_option( self::ADDON_OPTION_KEY, [] );
		$settings[ $slug ] = $enabled;
		update_option( self::ADDON_OPTION_KEY, $settings );

		// Disabling stops the class from ever being instantiated again, so
		// any hook it self-registered from its own constructor (a cron
		// event, most notably -- see RSR's 'cron_hook' metadata above)
		// would otherwise stay scheduled forever with nothing left hooked
		// to it. Cleared from both engines, same as the plugin's own
		// deactivation hook already does for every other cron this plugin
		// schedules.
		if ( ! $enabled ) {
			$cron_hook = self::get( $slug )['cron_hook'] ?? '';
			if ( '' !== $cron_hook ) {
				wp_clear_scheduled_hook( $cron_hook );
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					\as_unschedule_all_actions( $cron_hook, [], 'wpistic-ffl' );
				}
			}
		}
	}

	/** The subset of known_distributors() currently enabled -- what the main bootstrap loads and the "📦 Distributors" tabbed page shows. */
	public static function enabled_distributors(): array {
		return array_filter(
			self::known_distributors(),
			fn( string $slug ) => self::is_enabled( $slug ),
			ARRAY_FILTER_USE_KEY
		);
	}
}
