<?php
/**
 * Plugin Name: G2A POS Core
 * Description: Production FFL POS — compliance (4473/NICS/Bound Book/NFA/ACE audit), 4 wholesalers, CRM, gunsmithing, layaway/SO/consignment/trade-ins, classes, range ops, loyalty + gift cards, POs with three-way match, cycle counts, multi-location transfers, shipping (UPS/FedEx 21+), messaging, KPI dashboard, hardware kit, compliance calendar, FFL routing — and an on-prem AI agent with RAG brain, tool calls, and tamper-evident audit.
 * Version: 3.3.5
 * Author: Wordpressistic
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: g2a-pos-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'g2a_pos_core_admin_notice' ) ) {
	function g2a_pos_core_admin_notice( string $message ): void {
		add_action(
			'admin_notices',
			static function () use ( $message ): void {
				echo '<div class="notice notice-error"><p><strong>G2A POS Core:</strong> ' . esc_html( $message ) . '</p></div>';
			}
		);
	}
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	g2a_pos_core_admin_notice( 'Requires PHP 8.1 or higher. Current PHP: ' . PHP_VERSION );
	return;
}

global $wp_version;
if ( ! isset( $wp_version ) || version_compare( (string) $wp_version, '6.5', '<' ) ) {
	g2a_pos_core_admin_notice( 'Requires WordPress 6.5 or higher.' );
	return;
}

define( 'G2A_POS_CORE_VERSION', '3.3.5' );
define( 'G2A_POS_CORE_FILE', __FILE__ );
define( 'G2A_POS_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'G2A_POS_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once G2A_POS_CORE_PATH . 'includes/Core/Autoloader.php';

G2A\POS\Core\Autoloader::register();

if ( is_readable( G2A_POS_CORE_PATH . 'vendor/autoload.php' ) ) {
	// Belt and braces: a vendor/ whose composer autoloader references
	// packages that are not on disk (exactly what shipped between the
	// dev-deps removal and 3.1.5 — autoload_static.php still listed
	// mockery's files-autoload entries) turns require into an instant
	// fatal on EVERY request: the whole site goes down the moment the
	// plugin is active. Catch it, warn loudly, and run degraded (PDF
	// generation reports the missing engine on use) instead of dying.
	try {
		require_once G2A_POS_CORE_PATH . 'vendor/autoload.php';
	} catch ( \Throwable $vendor_error ) {
		g2a_pos_core_admin_notice(
			'Bundled libraries failed to load (' . $vendor_error->getMessage() . '). '
			. 'PDF generation is disabled until a clean build is installed — re-run `composer install --no-dev` and re-zip.'
		);
	}
}

register_activation_hook(
	__FILE__,
	static function (): void {
		try {
			G2A\POS\Core\Plugin::activate();
		} catch ( \Throwable $e ) {
			G2A\POS\Support\Logger::exception( 'Activation failure', $e );
			wp_die( 'G2A POS Core activation failed: ' . esc_html( $e->getMessage() ) );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		try {
			G2A\POS\Core\Plugin::deactivate();
		} catch ( \Throwable $e ) {
			G2A\POS\Support\Logger::exception( 'Deactivation failure', $e );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		try {
			G2A\POS\Core\Plugin::instance()->boot();
		} catch ( \Throwable $e ) {
			G2A\POS\Support\Logger::exception( 'Boot failure', $e );
			g2a_pos_core_admin_notice( 'Boot failed: ' . $e->getMessage() );
		}
	}
);
