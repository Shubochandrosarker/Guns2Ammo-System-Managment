<?php
/**
 * Admin: Front Desk page.
 *
 * Today's roster + search + per-booking quick actions (check-in, verify
 * waiver, collect payment, mark no-show, add note, print receipt).
 *
 * Talks to G2AB_REST_Frontdesk_Controller. Shares a renderer with the
 * `[g2ab_frontdesk]` shortcode so a staff terminal page on the public
 * site looks and behaves identically.
 *
 * @package G2AB
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Frontdesk {

	const SLUG = 'g2ab-frontdesk';
	const CAP  = 'manage_g2ab_bookings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Front Desk', 'g2a-booking' ),
			__( 'Front Desk', 'g2a-booking' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		G2AB_Frontdesk_View::enqueue();
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		echo '<div class="wrap g2ab-wrap g2ab-admin">';
		echo '<h1>' . esc_html__( 'Front Desk', 'g2a-booking' ) . '</h1>';
		echo '<p>' . esc_html__( 'Today\'s bookings, customer search, and one-tap actions for the desk.', 'g2a-booking' ) . '</p>';
		G2AB_Frontdesk_View::markup( 'admin' );
		echo '</div>';
	}
}
