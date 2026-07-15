<?php
/**
 * Plugin Name:       Messageistic
 * Plugin URI:        https://www.wordpressistic.com
 * Description:       Premium provider-independent SMS and customer communication engine. Supports self-hosted SMS via a local Android gateway app or Jasmin, plus OtterText, Twilio, and Testing providers through a pluggable adapter system.
 * Version:           0.8.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            WordPressistic
 * Author URI:        https://www.wordpressistic.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       messageistic
 * Domain Path:       /languages
 *
 * @package Messageistic
 */

defined( 'ABSPATH' ) || exit;

define( 'MESSAGEISTIC_VERSION', '0.8.0' );
define( 'MESSAGEISTIC_FILE', __FILE__ );
define( 'MESSAGEISTIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'MESSAGEISTIC_URL', plugin_dir_url( __FILE__ ) );
define( 'MESSAGEISTIC_BASENAME', plugin_basename( __FILE__ ) );
define( 'MESSAGEISTIC_DB_VERSION', '1.3.0' );

require_once MESSAGEISTIC_DIR . 'includes/Core/Autoloader.php';
\Messageistic\Core\Autoloader::register();

register_activation_hook( __FILE__, [ \Messageistic\Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Messageistic\Core\Deactivator::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
    \Messageistic\Core\Plugin::instance()->boot();
} );
