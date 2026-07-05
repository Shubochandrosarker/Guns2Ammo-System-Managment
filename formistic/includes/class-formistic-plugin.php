<?php
/**
 * Plugin orchestrator — wires the capture, shortcode, admin and AJAX layers.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton bootstrap for Formistic.
 */
final class Wpistic_Formistic_Plugin {

	/** @var Wpistic_Formistic_Plugin|null */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Wpistic_Formistic_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all component hooks.
	 */
	public function boot() {
		load_plugin_textdomain( 'formistic', false, dirname( WPISTIC_FORMISTIC_BASENAME ) . '/languages' );

		// Ensure the schema is current on every entry point (admin, front end,
		// AJAX, admin-post). This is a single option read unless the stored DB
		// version is stale — which also covers sites updating from an older
		// release whose tables used a different name prefix.
		Wpistic_Formistic_Database::maybe_upgrade();

		// Always-on core: inbox capture pipeline, built-in forms, attachments, GDPR.
		( new Wpistic_Formistic_Capture() )->register();
		( new Wpistic_Formistic_Shortcode() )->register();
		( new Wpistic_Formistic_Forms() )->register();
		( new Wpistic_Formistic_Attachments() )->register();
		( new Wpistic_Formistic_Gdpr() )->register();
		( new Wpistic_Formistic_API() )->register();

		// Optional feature modules — gated by the Addons screen.
		if ( Wpistic_Formistic_Addons::is_active( 'autoresponder' ) ) {
			( new Wpistic_Formistic_Autoresponder() )->register();
		}
		if ( Wpistic_Formistic_Addons::is_active( 'webhook' ) ) {
			( new Wpistic_Formistic_Webhooks() )->register();
		}
		if ( Wpistic_Formistic_Addons::is_active( 'ai' ) ) {
			( new Wpistic_Formistic_AI() )->register();
		}
		if ( Wpistic_Formistic_Addons::is_active( 'newsletter' ) ) {
			( new Wpistic_Formistic_Newsletter() )->register();
		}

		// Defensive re-schedule in case the cron event vanished mid-life.
		Wpistic_Formistic_Gdpr::maybe_schedule();

		if ( is_admin() ) {
			( new Wpistic_Formistic_Admin() )->register();
			( new Wpistic_Formistic_Addons() )->register();
			( new Wpistic_Formistic_Ajax() )->register();
			( new Wpistic_Formistic_Settings() )->register();
			( new Wpistic_Formistic_Export() )->register();
			( new Wpistic_Formistic_Bulk() )->register();

			if ( Wpistic_Formistic_Addons::is_active( 'templates' ) ) {
				( new Wpistic_Formistic_Templates() )->register();
			}
		}
	}
}
