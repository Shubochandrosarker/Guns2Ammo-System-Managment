<?php
/**
 * Admin menu.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_get_brand_label;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Menu {
	/**
	 * Register plugin admin menu.
	 */
	public static function register() {
		$label = apply_filters( 'memberistic_admin_menu_label', memberistic_get_brand_label() );

		add_menu_page(
			$label,
			$label,
			'view_memberistic_dashboard',
			'memberistic-dashboard',
			array( Dashboard_Page::class, 'render' ),
			'dashicons-groups',
			56
		);

		add_submenu_page( 'memberistic-dashboard', __( 'Dashboard', 'memberistic' ), __( 'Dashboard', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-dashboard', array( Dashboard_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Members', 'memberistic' ), __( 'Members', 'memberistic' ), 'view_memberistic_members', 'memberistic-members', array( Members_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Plans', 'memberistic' ), __( 'Plans', 'memberistic' ), 'manage_memberistic_plans', 'memberistic-plans', array( Plans_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Payments', 'memberistic' ), __( 'Payments', 'memberistic' ), 'manage_memberistic_payments', 'memberistic-payments', array( Payments_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Check-Ins', 'memberistic' ), __( 'Check-Ins', 'memberistic' ), 'memberistic_checkin_members', 'memberistic-checkins', array( Checkins_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Activity', 'memberistic' ), __( 'Activity', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-activity', array( Activity_Page::class, 'render' ) );

		add_submenu_page( 'memberistic-dashboard', __( 'Emails', 'memberistic' ), __( 'Emails', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-emails', array( self::class, 'render_emails' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Integrations', 'memberistic' ), __( 'Integrations', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-integrations', array( self::class, 'render_integrations' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Shortcodes', 'memberistic' ), __( 'Shortcodes', 'memberistic' ), 'view_memberistic_dashboard', 'memberistic-shortcodes', array( self::class, 'render_shortcodes' ) );

		add_submenu_page( 'memberistic-dashboard', __( 'Import', 'memberistic' ), __( 'Import', 'memberistic' ), 'manage_memberistic_settings', 'memberistic-import', array( Import_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Settings', 'memberistic' ), __( 'Settings', 'memberistic' ), 'manage_memberistic_settings', 'memberistic-settings', array( Settings_Page::class, 'render' ) );
		add_submenu_page( 'memberistic-dashboard', __( 'Tools', 'memberistic' ), __( 'Tools', 'memberistic' ), 'manage_memberistic_settings', 'memberistic-tools', array( self::class, 'render_tools' ) );
	}

	private static function guard_dashboard() {
		if ( ! memberistic_current_user_can( 'view_memberistic_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
	}

	public static function render_emails() {
		self::guard_dashboard();
		// Legacy server-side CSV export — still works for users who bookmark the URL.
		if ( isset( $_GET['memberistic_export'] ) && 'emails' === sanitize_key( wp_unslash( $_GET['memberistic_export'] ) ) && check_admin_referer( 'memberistic_export_emails' ) ) {
			self::download_email_csv( self::email_rows() );
		}
		?>
		<div class="wrap memberistic-wrap">
			<div id="memberistic-emails-app" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading email directory…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic email directory requires JavaScript.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_integrations() {
		self::guard_dashboard();
		$cards = array(
			array( 'name' => 'G2A Booking Engine', 'desc' => __( 'Member eligibility, free lane booking rules, booking activity, and front desk visibility.', 'memberistic' ), 'active' => class_exists( 'G2AB_Plugin' ), 'icon' => 'B', 'status' => class_exists( 'G2AB_Plugin' ) ? 'connected' : 'not_connected' ),
			array( 'name' => 'Stripe Checkout', 'desc' => __( 'Hosted membership subscription checkout and webhooks.', 'memberistic' ), 'active' => 'yes' === memberistic_get_setting( 'stripe_enabled', 'no' ), 'icon' => 'S', 'status' => 'yes' === memberistic_get_setting( 'stripe_enabled', 'no' ) ? 'connected' : 'not_connected' ),
			array( 'name' => 'WooCommerce', 'desc' => __( 'Completed-order sync for membership purchases.', 'memberistic' ), 'active' => class_exists( 'WooCommerce' ) && 'yes' === memberistic_get_setting( 'woocommerce_enabled', 'no' ), 'icon' => 'W', 'status' => class_exists( 'WooCommerce' ) && 'yes' === memberistic_get_setting( 'woocommerce_enabled', 'no' ) ? 'connected' : 'not_connected' ),
			array( 'name' => 'Email Automation', 'desc' => __( 'Lifecycle notifications for checkout, activation, failed payment, cancellation, renewals, and waivers.', 'memberistic' ), 'active' => true, 'icon' => 'M', 'status' => 'connected' ),
			array( 'name' => 'Klaviyo Sync', 'desc' => __( 'Export member segments, renewal windows, and failed payment audiences into marketing automation.', 'memberistic' ), 'active' => false, 'icon' => 'K', 'status' => 'coming_soon' ),
			array( 'name' => 'POS Bridge', 'desc' => __( 'Connect memberships with retail counter sales, barcode lookup, and staff checkout workflows.', 'memberistic' ), 'active' => false, 'icon' => 'P', 'status' => 'coming_soon' ),
			array( 'name' => 'Waiver Provider', 'desc' => __( 'Connect signed range waivers with each person record and staff check-in status.', 'memberistic' ), 'active' => false, 'icon' => 'W', 'status' => 'coming_soon' ),
			array( 'name' => 'SMS Reminders', 'desc' => __( 'Send renewal, failed payment, check-in, and booking reminders by text message.', 'memberistic' ), 'active' => false, 'icon' => 'T', 'status' => 'coming_soon' ),
		);
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Memberistic Integrations', 'memberistic' ); ?></h1>
			<div class="memberistic-addon-grid">
				<?php foreach ( $cards as $card ) : ?>
					<div class="memberistic-addon-card <?php echo $card['active'] ? 'is-active' : ''; ?> <?php echo 'coming_soon' === $card['status'] ? 'is-coming-soon' : ''; ?>">
						<span class="memberistic-addon-icon"><?php echo esc_html( $card['icon'] ); ?></span>
						<h2><?php echo esc_html( $card['name'] ); ?></h2>
						<p><?php echo esc_html( $card['desc'] ); ?></p>
						<strong><?php echo esc_html( 'coming_soon' === $card['status'] ? __( 'Coming Soon', 'memberistic' ) : ( $card['active'] ? __( 'Connected', 'memberistic' ) : __( 'Not Connected', 'memberistic' ) ) ); ?></strong>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public static function render_tools() {
		self::guard_dashboard();
		$checks = array(
			__( 'Database version', 'memberistic' ) => get_option( 'memberistic_db_version', 'n/a' ),
			__( 'Plugin version', 'memberistic' )  => MEMBERISTIC_VERSION,
			__( 'REST namespace', 'memberistic' )  => rest_url( 'memberistic/v1/' ),
			__( 'Stripe webhook', 'memberistic' )  => rest_url( 'memberistic/v1/webhooks/stripe' ),
		);
		?>
		<div class="wrap memberistic-wrap">
			<h1><?php esc_html_e( 'Memberistic Tools', 'memberistic' ); ?></h1>
			<div class="memberistic-two-column">
				<div class="memberistic-card">
					<h2><?php esc_html_e( 'System Health', 'memberistic' ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $checks as $label => $value ) : ?>
							<tr><th><?php echo esc_html( $label ); ?></th><td><code><?php echo esc_html( (string) $value ); ?></code></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="memberistic-card">
					<h2><?php esc_html_e( 'Quick Actions', 'memberistic' ); ?></h2>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-settings#memberistic-pages' ) ); ?>"><?php esc_html_e( 'Review Page Mapping', 'memberistic' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-emails' ) ); ?>"><?php esc_html_e( 'Open Email Directory', 'memberistic' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=memberistic-integrations' ) ); ?>"><?php esc_html_e( 'Check Integrations', 'memberistic' ); ?></a></p>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_shortcodes() {
		self::guard_dashboard();
		?>
		<div class="wrap memberistic-wrap"><h1><?php esc_html_e( 'Memberistic Shortcodes', 'memberistic' ); ?></h1><div class="memberistic-card"><ul class="memberistic-shortcode-list">
			<li><code>[memberistic_plans]</code></li><li><code>[memberistic_checkout]</code></li><li><code>[memberistic_account]</code></li><li><code>[memberistic_staff_dashboard]</code></li><li><code>[memberistic_login]</code></li><li><code>[memberistic_thank_you]</code></li><li><code>[memberistic_payment_failed]</code></li>
		</ul></div></div>
		<?php
	}

	private static function email_rows() {
		global $wpdb;
		$people      = $wpdb->prefix . 'memberistic_people';
		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$plans       = $wpdb->prefix . 'memberistic_plans';
		return $wpdb->get_results( "SELECT p.full_name, p.email, p.phone, p.role, p.waiver_status, p.waiver_signed_at, p.waiver_expires_at, p.created_at AS person_created_at, m.membership_uuid, m.status AS membership_status, m.billing_cycle, m.renewal_date, pl.name AS plan_name FROM {$people} p LEFT JOIN {$memberships} m ON m.id=p.membership_id LEFT JOIN {$plans} pl ON pl.id=m.plan_id WHERE p.email <> '' ORDER BY p.full_name ASC LIMIT 5000", ARRAY_A ) ?: array();
	}

	private static function download_email_csv( $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=memberistic-member-emails-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'Name',
				'Email',
				'Phone',
				'Role',
				'Membership ID',
				'Plan',
				'Membership Status',
				'Billing Cycle',
				'Renewal Date',
				'Waiver Status',
				'Waiver Signed At',
				'Waiver Expires At',
				'Created',
			)
		);
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['full_name'],
					$row['email'],
					$row['phone'],
					ucfirst( (string) ( $row['role'] ?? '' ) ),
					$row['membership_uuid'],
					$row['plan_name'],
					ucfirst( str_replace( '_', ' ', (string) $row['membership_status'] ) ),
					ucfirst( (string) ( $row['billing_cycle'] ?? '' ) ),
					$row['renewal_date'],
					ucfirst( str_replace( '_', ' ', (string) ( $row['waiver_status'] ?? '' ) ) ),
					$row['waiver_signed_at'],
					$row['waiver_expires_at'],
					$row['person_created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}
}
