<?php
/**
 * Frontend shortcode: [g2ab_frontdesk]
 *
 * Renders the same Front Desk terminal that lives in wp-admin, so a staff
 * laptop pointed at a public page can use it without ever entering the
 * dashboard. Capability-gated — non-staff visitors get nothing.
 *
 * @package G2AB
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Frontdesk {

	private static $instance = null;
	private $printed = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2ab_frontdesk', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="g2ab-fd__gate">' . esc_html__( 'Please sign in as staff to use the front desk terminal.', 'g2a-booking' ) . '</div>';
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return '<div class="g2ab-fd__gate">' . esc_html__( 'You do not have permission to use the front desk terminal.', 'g2a-booking' ) . '</div>';
		}
		if ( ! class_exists( 'G2AB_Frontdesk_View' ) ) {
			return '';
		}
		if ( ! $this->printed ) {
			G2AB_Frontdesk_View::enqueue();
			$this->printed = true;
		}
		ob_start();
		G2AB_Frontdesk_View::markup( 'frontend' );
		return ob_get_clean();
	}
}
