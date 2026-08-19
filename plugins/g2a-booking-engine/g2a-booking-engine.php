<?php
/**
 * Plugin Name:       G2A Booking Engine
 * Plugin URI:        https://wordpressistic.com/g2a-booking-engine
 * Description:       Custom booking engine for Guns 2 Ammo - shooting range lanes, firearms classes, and membership-based booking with real-time availability, online payments, pay-in-store support, and built-in Migration Tool (Amelia/Bookly/BookingPress/CSV).
 * Version:           1.12.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Wordpressistic
 * Author URI:        https://wordpressistic.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       g2a-booking
 * Domain Path:       /languages
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 *
 * G2AB_VERSION       — Plugin version. Bump on every release.
 * G2AB_DB_VERSION    — Schema version. Bump only when DB tables change.
 * G2AB_FILE          — Absolute path to this bootstrap file.
 * G2AB_PATH          — Absolute path to plugin directory (with trailing slash).
 * G2AB_URL           — URL to plugin directory (with trailing slash).
 * G2AB_BASENAME      — Plugin basename (folder/file.php) for hooks.
 * G2AB_TEXT_DOMAIN   — Text domain for i18n.
 * G2AB_REST_NAMESPACE — REST API namespace.
 */
define( 'G2AB_VERSION', '1.12.1' );
define( 'G2AB_DB_VERSION', '1.6.0' );
define( 'G2AB_FILE', __FILE__ );
define( 'G2AB_PATH', plugin_dir_path( __FILE__ ) );
define( 'G2AB_URL', plugin_dir_url( __FILE__ ) );
define( 'G2AB_BASENAME', plugin_basename( __FILE__ ) );
define( 'G2AB_TEXT_DOMAIN', 'g2a-booking' );
define( 'G2AB_REST_NAMESPACE', 'g2a-booking/v1' );

/**
 * Minimum environment check.
 *
 * Stops the plugin from loading on incompatible servers and shows an admin
 * notice instead of fatally erroring. PHP 8.0 + WordPress 6.2 are hard requirements.
 */
function g2ab_check_environment() {
	$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
	$wp_ok  = version_compare( get_bloginfo( 'version' ), '6.2', '>=' );

	if ( $php_ok && $wp_ok ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () use ( $php_ok, $wp_ok ) {
			$messages = array();
			if ( ! $php_ok ) {
				$messages[] = sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					esc_html__( 'G2A Booking Engine requires PHP %1$s or higher. Your server is running PHP %2$s.', 'g2a-booking' ),
					'8.0',
					esc_html( PHP_VERSION )
				);
			}
			if ( ! $wp_ok ) {
				$messages[] = sprintf(
					/* translators: 1: required WP version, 2: current WP version */
					esc_html__( 'G2A Booking Engine requires WordPress %1$s or higher. You are running WordPress %2$s.', 'g2a-booking' ),
					'6.2',
					esc_html( get_bloginfo( 'version' ) )
				);
			}
			printf(
				'<div class="notice notice-error"><p><strong>G2A Booking Engine</strong></p><p>%s</p></div>',
				implode( '</p><p>', $messages ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above.
			);
		}
	);

	return false;
}

if ( ! g2ab_check_environment() ) {
	return;
}

/**
 * Lightweight, prefix-based autoloader.
 *
 * Maps class names with the G2AB_ prefix to files inside /includes/.
 * Convention: class G2AB_Some_Thing → includes/class-some-thing.php
 *             class G2AB_REST_Bookings_Controller → includes/rest/class-bookings-controller.php
 *
 * This keeps the plugin Composer-free for now while staying organized.
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'G2AB_' ) ) {
			return;
		}

		// Strip prefix, lowercase, replace underscores with hyphens.
		$relative = strtolower( substr( $class_name, 5 ) );
		$relative = str_replace( '_', '-', $relative );

		// Subfolder mapping for layered classes.
		$subfolder_map = array(
			'rest-'         => 'rest/',
			'admin-'        => 'admin/',
			'frontend-'     => 'frontend/',
			'gateway-'      => 'payments/',
			'service-'      => 'services/',
			'integration-'  => 'integrations/',
		);

		// Class names that don't follow the `<Layer>_<Name>` pattern but still
		// belong in a known folder. Lookup happens after the prefix-strip but
		// before the candidate search.
		$exact_subfolder_map = array(
			'booking-expiry-cron' => 'cron/',
			'booking-activity'    => 'services/',
			'booking-statuses'    => 'services/',
			'booking-transitions' => 'services/',
			'checkout-policy'     => 'services/',
			'checkin-service'     => 'services/',
			'frontdesk-view'      => 'services/',
			'email-actions'       => 'services/',
			'range-guest-service' => 'services/',
		);

		$subfolder = '';
		foreach ( $subfolder_map as $needle => $folder ) {
			if ( 0 === strpos( $relative, $needle ) ) {
				$subfolder = $folder;
				$relative  = substr( $relative, strlen( $needle ) );
				break;
			}
		}

		if ( '' === $subfolder && isset( $exact_subfolder_map[ $relative ] ) ) {
			$subfolder = $exact_subfolder_map[ $relative ];
		}

		$file_root = G2AB_PATH . 'includes/' . $subfolder;
		$candidates = array(
			$file_root . 'class-' . $relative . '.php',
			$file_root . 'core/class-' . $relative . '.php',
			$file_root . 'helpers/class-' . $relative . '.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				require_once $candidate;
				return;
			}
		}
	}
);

/**
 * Procedural helpers (loaded eagerly).
 */
if ( file_exists( G2AB_PATH . 'includes/helpers/functions.php' ) ) {
	require_once G2AB_PATH . 'includes/helpers/functions.php';
}
if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( G2AB_PATH . 'includes/cli/class-stripe-recovery-command.php' ) ) {
	require_once G2AB_PATH . 'includes/cli/class-stripe-recovery-command.php';
	G2AB_CLI_Stripe_Recovery_Command::register();
}
if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( G2AB_PATH . 'includes/cli/class-event-capacity-command.php' ) ) {
	require_once G2AB_PATH . 'includes/cli/class-event-capacity-command.php';
	G2AB_CLI_Event_Capacity_Command::register();
}

/**
 * Activation / deactivation / uninstall hooks.
 *
 * Uninstall is handled by uninstall.php (only loaded by core when the user
 * deletes the plugin), so it is not registered here.
 */
register_activation_hook( __FILE__, array( 'G2AB_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'G2AB_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * Priority 5 on plugins_loaded so integrations (Memberistic, WooCommerce) that hook
 * later still get our actions/filters available when they load.
 */
add_action(
	'plugins_loaded',
	static function () {
		G2AB_Plugin::instance();
	},
	5
);

/**
 * Admin health-check / deploy-verification endpoint.
 *
 * Hit `https://your-site.com/?g2ab_version_check=1` while logged in as a user
 * with `manage_options` to see:
 *   - Which version of the plugin is currently EXECUTING (vs. what's on disk).
 *   - Whether the new file actually loaded after an update.
 *
 * Outputs JSON, never reveals secrets. Locked behind capability so deployment
 * details (versions, OPcache state) aren't leaked to the public internet.
 * Disable entirely by defining `G2AB_DISABLE_VERSION_CHECK` in wp-config.php.
 *
 * @since 1.2.4 Endpoint now requires manage_options; previously public.
 */
add_action( 'init', static function () {
	if ( empty( $_GET['g2ab_version_check'] ) ) return;
	if ( defined( 'G2AB_DISABLE_VERSION_CHECK' ) && G2AB_DISABLE_VERSION_CHECK ) return;
	if ( ! current_user_can( 'manage_options' ) ) {
		status_header( 403 );
		wp_send_json( array( 'error' => 'forbidden' ), 403 );
	}

	// Detect whether the FILE on disk matches the version EXECUTING.
	$file_version = '';
	$file_path    = G2AB_PATH . 'g2a-booking-engine.php';
	if ( file_exists( $file_path ) ) {
		$head = (string) file_get_contents( $file_path, false, null, 0, 2048 );
		if ( preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)\s*$/m', $head, $m ) ) {
			$file_version = trim( $m[1] );
		}
	}

	$frontend_php = G2AB_PATH . 'includes/class-frontend.php';
	$has_new_ui = false;
	if ( file_exists( $frontend_php ) ) {
		$content = (string) file_get_contents( $frontend_php );
		// Strings that exist ONLY in v1.2.x. Their presence proves the new file
		// is on disk; their absence proves the old file is still deployed.
		$has_new_ui = ( false !== strpos( $content, 'Calendly-style' ) )
			&& ( false !== strpos( $content, 'g2ab-shell' ) )
			&& ( false === strpos( $content, 'Mission Confirmed' ) );
	}

	$opcache_status = 'unknown';
	if ( function_exists( 'opcache_get_status' ) ) {
		$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $status ) ) {
			$opcache_status = ! empty( $status['opcache_enabled'] ) ? 'enabled' : 'disabled';
		}
	}

	wp_send_json( array(
		'executing_version' => G2AB_VERSION,
		'file_version'      => $file_version,
		'has_new_ui_code'   => $has_new_ui,
		'versions_match'    => ( G2AB_VERSION === $file_version ),
		'opcache'           => $opcache_status,
		'verdict'           => self_g2ab_diagnose_verdict( G2AB_VERSION, $file_version, $has_new_ui ),
		'docs'              => 'If versions_match is false or has_new_ui_code is false while file_version is 1.2.0+: PHP OPcache is serving stale bytecode. Restart PHP-FPM or have your host flush OPcache.',
	) );
} );

/**
 * Build a one-line diagnostic verdict for the version-check endpoint.
 */
function self_g2ab_diagnose_verdict( $exec, $file, $has_new_ui ) {
	if ( $exec === $file && $has_new_ui ) {
		return 'OK — new code is loaded and executing.';
	}
	if ( $exec !== $file ) {
		return 'STALE BYTECODE — file on disk is ' . $file . ' but PHP is executing ' . $exec . '. Flush OPcache.';
	}
	if ( ! $has_new_ui ) {
		return 'OLD FILES — new zip never replaced the plugin folder. Delete the plugin folder via FTP and re-upload.';
	}
	return 'unknown';
}
