<?php
/**
 * Admin: Range Status console page.
 *
 * Live lane map, KPI cards, walk-in check-in, reservations and a payment
 * feed for the desk. Shares its renderer with the [g2ab_range_console]
 * shortcode so a desk laptop on the public site looks identical.
 *
 * @package G2AB
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Range_Status {

	private static $instance = null;
	const SLUG = 'g2ab-range-status';
	const CAP  = 'manage_g2ab_bookings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Range Status', 'g2a-booking' ),
			__( 'Range Status', 'g2a-booking' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		if ( ! class_exists( 'G2AB_Range_Console_View' ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Range Status', 'g2a-booking' ) . '</h1></div>';
			return;
		}
		G2AB_Range_Console_View::enqueue();
		echo '<div class="wrap g2ab-admin" style="max-width:none;">';
		G2AB_Range_Console_View::markup( 'admin' );
		echo '</div>';
	}
}
