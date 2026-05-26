<?php
/**
 * Email Automation — module bootstrap.
 *
 * Wires the engine, cron, and settings classes. Listens to booking
 * lifecycle hooks and dispatches templated emails.
 *
 * @package G2AB\Modules\EmailAutomation
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_Email_Automation {

	private static $instance = null;
	private $engine = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$this->engine = new G2AB_Email_Engine();

		// Booking lifecycle hooks (fired from class-bookings-controller.php).
		add_action( 'g2ab_booking_created',   array( $this, 'on_created' ),   10, 2 );
		add_action( 'g2ab_booking_confirmed', array( $this, 'on_confirmed' ), 10, 2 );
		add_action( 'g2ab_booking_paid',      array( $this, 'on_paid' ),      10, 2 );
		add_action( 'g2ab_booking_cancelled', array( $this, 'on_cancelled' ), 10, 2 );
		add_action( 'g2ab_booking_no_show',   array( $this, 'on_no_show' ),   10, 2 );
		add_action( 'g2ab_booking_completed', array( $this, 'on_completed' ), 10, 2 );

		// Cron registration.
		new G2AB_Email_Cron( $this->engine );

		// Admin settings tab.
		if ( is_admin() ) new G2AB_Email_Settings();
	}

	public function engine() { return $this->engine; }

	public function on_created( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_created' ) ) return;
		$this->engine->send_event( 'booking_created', $booking, $context );
	}

	public function on_confirmed( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_confirmed' ) ) return;
		$this->engine->send_event( 'booking_confirmed', $booking, $context );
	}

	public function on_paid( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_paid' ) ) return;
		$this->engine->send_event( 'booking_paid', $booking, $context );
	}

	public function on_cancelled( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_cancelled' ) ) return;
		$this->engine->send_event( 'booking_cancelled', $booking, $context );
	}

	public function on_no_show( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_no_show' ) ) return;
		$this->engine->send_event( 'booking_no_show', $booking, $context );
	}

	public function on_completed( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_completed' ) ) return;
		$this->engine->send_event( 'booking_completed', $booking, $context );
	}

	private function is_event_enabled( $event ) {
		$opts = get_option( 'g2ab_email_events', array() );
		// Default-on for all critical events; default-off for noisy ones.
		$default_on = array( 'booking_created', 'booking_confirmed', 'booking_paid', 'booking_cancelled' );
		if ( ! isset( $opts[ $event ] ) ) return in_array( $event, $default_on, true );
		return ! empty( $opts[ $event ] );
	}
}
