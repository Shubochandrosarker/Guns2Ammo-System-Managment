<?php
/**
 * Top-level plugin bootstrap. Wires the router into rest_api_init.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	public function run(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// CORS for the off-site dashboard (app.guns2ammo.com). Must be wired
		// on every request so REST preflights are answered.
		Cors::register();

		// Cron hooks must be registered on every request (not just admin), so
		// that WP-Cron can invoke them when a scheduled event fires.
		\WordPressistic\G2ABA\AI\Insight_Generator::register_cron();
		\WordPressistic\G2ABA\Agents\Agent_Runner::register();

		// Concrete automation handlers subscribe to their per-slug cron hooks.
		\WordPressistic\G2ABA\Automation\Handlers\Weekly_Report_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\Low_Stock_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\SEO_Drop_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\Membership_Renewal_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\Booking_Reminder_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\Waiver_Reminder_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\Abandoned_Inquiry_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\Ladies_Upsell_Handler::register();
		\WordPressistic\G2ABA\Automation\Handlers\Churn_Risk_Handler::register();

		// BridGistic executor subscribes to the approval hook.
		\WordPressistic\G2ABA\BridGistic\Executor::register();

		// Owner-facing settings + operations screens.
		if ( is_admin() ) {
			\WordPressistic\G2ABA\Admin\Settings_Page::register();
			\WordPressistic\G2ABA\Admin\Email_Drafts_Page::register();
			\WordPressistic\G2ABA\Admin\Cancellations_Page::register();
			\WordPressistic\G2ABA\Admin\Opt_Outs_Page::register();
		}
	}

	public function register_rest_routes(): void {
		( new Router() )->register();
	}
}
