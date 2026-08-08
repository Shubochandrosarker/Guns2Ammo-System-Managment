<?php
/**
 * Plugin Name:       G2A Business API
 * Plugin URI:        https://wordpressistic.com/g2a-business-api
 * Description:       REST API that serves the Guns2Ammo Business Control Center WebApp at app.guns2ammo.com. Aggregates analytics from WooCommerce, G2A Booking Engine, Memberistic, Waiver Manager, and search/analytics providers behind a single, permission-checked namespace.
 * Version:           0.4.3
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Wordpressistic
 * Author URI:        https://wordpressistic.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       g2a-business-api
 *
 * @package G2ABA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'G2ABA_VERSION', '0.4.2' );
define( 'G2ABA_FILE', __FILE__ );
define( 'G2ABA_PATH', plugin_dir_path( __FILE__ ) );
define( 'G2ABA_URL', plugin_dir_url( __FILE__ ) );
define( 'G2ABA_BASENAME', plugin_basename( __FILE__ ) );
define( 'G2ABA_REST_NAMESPACE', 'g2a/v1' );

require_once G2ABA_PATH . 'includes/class-autoloader.php';
\WordPressistic\G2ABA\Autoloader::register();

register_activation_hook(
	G2ABA_FILE,
	static function () {
		\WordPressistic\G2ABA\Activator::activate();
	}
);

register_deactivation_hook(
	G2ABA_FILE,
	static function () {
		\WordPressistic\G2ABA\Deactivator::deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		( new \WordPressistic\G2ABA\Plugin() )->run();
	}
);
