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

		// One-time seed: ensure the template store carries enabled defaults
		// for the critical lifecycle emails (created / confirmed / paid /
		// cancelled) so confirmations send out of the box on a fresh
		// install or upgrade. Idempotent — guarded by an option flag, and
		// never overwrites templates an admin has already saved.
		G2AB_Email_Engine::maybe_seed_default_templates();
		add_action( 'g2ab_activated', array( 'G2AB_Email_Engine', 'maybe_seed_default_templates' ) );

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
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_created', $booking, $context );
	}

	public function on_confirmed( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_confirmed' ) ) return;
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_confirmed', $booking, $context );
	}

	public function on_paid( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_paid' ) ) return;
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_paid', $booking, $context );
	}

	public function on_cancelled( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_cancelled' ) ) return;
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_cancelled', $booking, $context );
	}

	public function on_no_show( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_no_show' ) ) return;
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_no_show', $booking, $context );
	}

	public function on_completed( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_completed' ) ) return;
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_completed', $booking, $context );
	}

	/**
	 * Normalize the booking arg into a row object.
	 *
	 * Lifecycle hooks like `g2ab_booking_paid` are fired by multiple call sites
	 * — some pass the full booking row, others pass just the booking id. Without
	 * this normalization the merge-tag build collapses an int to `[0 => 123]`
	 * and every customer-facing tag renders empty.
	 *
	 * Mirrors the implementation in G2AB_Module_PDF_Invoices::resolve_booking().
	 *
	 * @param mixed $booking_or_id Object, array, or numeric id.
	 * @return object|null Booking row, or null if not resolvable.
	 */
	private function resolve_booking( $booking_or_id ) {
		// Accept any object as-is. Requiring a non-empty ->uuid here meant a
		// lifecycle hook fired with a uuid-less booking object fell through to
		// absint( $object ) === 0 and returned null — silently dropping the
		// email (and emitting an "Object could not be converted to int" warning
		// on PHP 8). The PDF invoice module's resolver accepts any object too.
		if ( is_object( $booking_or_id ) ) {
			return $booking_or_id;
		}
		if ( is_array( $booking_or_id ) && ! empty( $booking_or_id['id'] ) ) {
			return (object) $booking_or_id;
		}
		$id = absint( $booking_or_id );
		if ( ! $id ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1",
			$id
		) );
		return $row ?: null;
	}

	private function is_event_enabled( $event ) {
		$opts = get_option( 'g2ab_email_events', array() );
		// Default-on for all critical events; default-off for noisy ones.
		$default_on = array( 'booking_created', 'booking_confirmed', 'booking_paid', 'booking_cancelled' );
		if ( ! isset( $opts[ $event ] ) ) return in_array( $event, $default_on, true );
		return ! empty( $opts[ $event ] );
	}
}
