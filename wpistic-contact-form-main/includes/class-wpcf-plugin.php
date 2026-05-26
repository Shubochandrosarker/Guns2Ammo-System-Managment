<?php
/**
 * Plugin orchestrator — wires the capture, shortcode, admin and AJAX layers.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton bootstrap for WPistic Contact Form.
 */
final class WPISTIC_CF_Plugin {

	/** @var WPISTIC_CF_Plugin|null */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return WPISTIC_CF_Plugin
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
		load_plugin_textdomain( 'wpistic-contact-form', false, dirname( WPISTIC_CF_BASENAME ) . '/languages' );

		( new WPISTIC_CF_Capture() )->register();
		( new WPISTIC_CF_Shortcode() )->register();
		( new WPISTIC_CF_Forms() )->register();
		( new WPISTIC_CF_Autoresponder() )->register();
		( new WPISTIC_CF_Attachments() )->register();
		( new WPISTIC_CF_Webhooks() )->register();
		( new WPISTIC_CF_Gdpr() )->register();
		( new WPistic_CF_AI() )->register();

		// Defensive re-schedule in case the cron event vanished mid-life.
		WPISTIC_CF_Gdpr::maybe_schedule();

		if ( is_admin() ) {
			( new WPISTIC_CF_Admin() )->register();
			( new WPISTIC_CF_Ajax() )->register();
			( new WPISTIC_CF_Settings() )->register();
			( new WPISTIC_CF_Templates() )->register();
			( new WPISTIC_CF_Export() )->register();
			( new WPISTIC_CF_Bulk() )->register();
		}
	}
}
