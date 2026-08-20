<?php
/**
 * Plugin loader.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Admin\Admin_Menu;
use WordPressistic\G2AReferrals\Database\Installer;
use WordPressistic\G2AReferrals\Emails\Notifications;
use WordPressistic\G2AReferrals\Frontend\Account_Tab;
use WordPressistic\G2AReferrals\Frontend\Redemption_UI;
use WordPressistic\G2AReferrals\Rest\Context_Controller;
use WordPressistic\G2AReferrals\Rest\Rewards_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Attribution::register();
		Qualification::register();
		Redemption::register();
		Reversal::register();
		Stacking::register();
		Signup_Capture::register();
		Notifications::register();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'g2ar_daily_maintenance', array( $this, 'run_maintenance' ) );

		Account_Tab::register();
		Redemption_UI::register();

		if ( is_admin() ) {
			Admin_Menu::register();
		}
	}

	/**
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'g2a-referrals', false, dirname( G2AR_BASENAME ) . '/languages' );
	}

	/**
	 * @return void
	 */
	public function register_rest_routes() {
		( new Context_Controller() )->register_routes();
		( new Rewards_Controller() )->register_routes();
	}

	/**
	 * Nightly housekeeping.
	 *
	 * Batched and bounded: this host 502s under sustained load and the
	 * Bridgistic relay caps at 30s, so a sweep does a fixed slice and leaves
	 * the rest for tomorrow rather than trying to finish in one pass.
	 *
	 * @return void
	 */
	public function run_maintenance() {
		Rewards_Service::run_expiry( 500 );

		// Keep seeding codes for members who joined before this plugin, a
		// batch a night, until there are none left.
		Installer::backfill_codes( 500 );
	}
}
