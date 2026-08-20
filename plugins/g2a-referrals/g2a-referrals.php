<?php
/**
 * Plugin Name:       G2A Referrals
 * Plugin URI:        https://wordpressistic.com/g2a-referrals
 * Description:       Membership referral rewards for Guns 2 Ammo. A referred friend who buys a membership gets a free month; the referrer earns a Guest Pass. Append-only reward ledger, Guest Pass redemption on lane bookings, member dashboard and front-desk admin.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Wordpressistic
 * Author URI:        https://wordpressistic.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       g2a-referrals
 * Domain Path:       /languages
 *
 * @package G2AR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 *
 * G2AR_VERSION        — Plugin version. Bump on every release.
 * G2AR_DB_VERSION     — Schema version. Bump only when DB tables change.
 * G2AR_FILE           — Absolute path to this bootstrap file.
 * G2AR_PATH           — Absolute path to plugin directory (with trailing slash).
 * G2AR_URL            — URL to plugin directory (with trailing slash).
 * G2AR_BASENAME       — Plugin basename (folder/file.php) for hooks.
 * G2AR_TEXT_DOMAIN    — Text domain for i18n.
 * G2AR_REST_NAMESPACE — REST API namespace.
 */
define( 'G2AR_VERSION', '1.0.0' );
define( 'G2AR_DB_VERSION', '1.0.0' );
define( 'G2AR_FILE', __FILE__ );
define( 'G2AR_PATH', plugin_dir_path( __FILE__ ) );
define( 'G2AR_URL', plugin_dir_url( __FILE__ ) );
define( 'G2AR_BASENAME', plugin_basename( __FILE__ ) );
define( 'G2AR_TEXT_DOMAIN', 'g2a-referrals' );
define( 'G2AR_REST_NAMESPACE', 'g2ar/v1' );

/**
 * Minimum environment check.
 *
 * Stops the plugin loading on incompatible servers and shows an admin notice
 * instead of fatally erroring. PHP 8.0 + WordPress 6.2 are hard requirements,
 * matching the rest of the Guns 2 Ammo stack.
 *
 * @return bool
 */
function g2ar_check_environment() {
	$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
	$wp_ok  = version_compare( get_bloginfo( 'version' ), '6.2', '>=' );

	if ( $php_ok && $wp_ok ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () use ( $php_ok, $wp_ok ) {
			$needs = array();
			if ( ! $php_ok ) {
				$needs[] = 'PHP 8.0+';
			}
			if ( ! $wp_ok ) {
				$needs[] = 'WordPress 6.2+';
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of requirements. */
						__( 'G2A Referrals is inactive. It requires %s.', 'g2a-referrals' ),
						implode( ', ', $needs )
					)
				)
			);
		}
	);

	return false;
}

/**
 * Autoloader for the plugin's namespaced classes.
 *
 * Maps WordPressistic\G2AReferrals\Sub\Name to
 * includes/sub/class-name.php, matching the file naming used across the
 * Guns 2 Ammo plugins.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function g2ar_autoload( $class_name ) {
	$prefix = 'WordPressistic\\G2AReferrals\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$parts    = explode( '\\', $relative );
	$class    = array_pop( $parts );
	$dir      = '';

	foreach ( $parts as $part ) {
		$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
	}

	$file = G2AR_PATH . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'g2ar_autoload' );

require_once G2AR_PATH . 'includes/functions.php';

register_activation_hook( __FILE__, array( '\WordPressistic\G2AReferrals\Database\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WordPressistic\G2AReferrals\Database\Installer', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded, so Memberistic and the
 * booking engine have already declared their hooks and helpers.
 *
 * @return void
 */
function g2ar_boot() {
	if ( ! g2ar_check_environment() ) {
		return;
	}

	\WordPressistic\G2AReferrals\Plugin::instance()->register();
}
add_action( 'plugins_loaded', 'g2ar_boot', 20 );
