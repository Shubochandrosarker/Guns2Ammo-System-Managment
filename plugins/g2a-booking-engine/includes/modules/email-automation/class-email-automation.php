<?php
/**
 * Email Automation — module bootstrap.
 *
 * Wires the engine, cron, and settings classes. Listens to booking
 * lifecycle hooks and dispatches templated emails.
 *
 * Lifecycle policy (see G2AB_Booking_Visibility):
 *  - Pending/unpaid checkout holds NEVER receive a confirmation email.
 *    They get the "payment required" email only once a usable checkout URL
 *    exists (g2ab_payment_checkout_ready).
 *  - Free ($0) bookings are created status 'confirmed' and receive the
 *    CONFIRMED email (on_created with status confirmed, or the
 *    g2ab_booking_status_changed hook — both paths are deduped by the
 *    engine's idempotency log).
 *  - The pay-in-store reservation email only goes to staff-created
 *    reserved/in_store rows — public 'web' rows never get it.
 *  - The paid email fires only from the verified booking_paid path.
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
		add_action( 'g2ab_payment_checkout_ready', array( $this, 'on_checkout_ready' ), 10, 2 );
		add_action( 'g2ab_booking_confirmed', array( $this, 'on_confirmed' ), 10, 2 );
		// Free/$0 public bookings fire g2ab_booking_status_changed('confirmed')
		// at creation without g2ab_booking_confirmed — listen to both. The
		// engine idempotency log dedupes when a caller fires both hooks.
		add_action( 'g2ab_booking_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
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
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;

		// Only operational bookings (confirmed $0 rows, verified-paid rows,
		// staff-created pay-at-store reservations) are emailed at creation.
		// Pending payable checkout holds wait for g2ab_payment_checkout_ready.
		if ( ! class_exists( 'G2AB_Booking_Visibility' ) || ! G2AB_Booking_Visibility::is_operational( $booking ) ) {
			return;
		}

		$status = sanitize_key( (string) ( $booking->status ?? '' ) );
		$mode   = sanitize_key( (string) ( $booking->payment_mode ?? '' ) );
		$source = sanitize_key( (string) ( $booking->source ?? '' ) );

		if ( 'reserved' === $status && 'in_store' === $mode ) {
			// Staff-created rows only — is_operational() already enforces the
			// staff source list, this re-check is belt-and-braces so a public
			// 'web' row can never receive the pay-at-store email.
			if ( ! in_array( $source, G2AB_Booking_Visibility::staff_sources(), true ) ) return;
			if ( ! $this->is_event_enabled( 'pay_in_store_reservation' ) ) return;
			$this->engine->send_event( 'pay_in_store_reservation', $booking, $context );
			return;
		}

		if ( 'confirmed' === $status ) {
			// Free/$0 bookings (and staff-created confirmed rows) get the
			// CONFIRMED email straight away — not a vague "received" note.
			// Deduped against the status-changed/confirmed hooks by the log.
			if ( ! $this->is_event_enabled( 'booking_confirmed' ) ) return;
			$this->engine->send_event( 'booking_confirmed', $booking, $context );
			return;
		}

		if ( ! $this->is_event_enabled( 'booking_created' ) ) return;
		$this->engine->send_event( 'booking_created', $booking, $context );
	}

	public function on_checkout_ready( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'payment_required' ) ) return;
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;

		// A usable live checkout URL is the whole point of this email.
		if ( empty( $context['pay_url'] ) ) return;

		// Never nag bookings that are already operational or terminal.
		$status = sanitize_key( (string) ( $booking->status ?? '' ) );
		if ( in_array( $status, array( 'paid', 'confirmed', 'completed', 'cancelled', 'refunded', 'partially_refunded', 'expired', 'no_show' ), true ) ) {
			return;
		}

		$this->engine->send_event( 'payment_required', $booking, $context );
	}

	public function on_confirmed( $booking, $context = array() ) {
		if ( ! $this->is_event_enabled( 'booking_confirmed' ) ) return;
		$booking = $this->resolve_booking( $booking );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_confirmed', $booking, $context );
	}

	/**
	 * g2ab_booking_status_changed listener — catches confirmations that fire
	 * without a dedicated g2ab_booking_confirmed action (free/$0 public
	 * bookings at creation, transitions via G2AB_Booking_Transitions).
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $new_status New status.
	 * @param string $old_status Previous status.
	 */
	public function on_status_changed( $booking_id, $new_status = '', $old_status = '' ) {
		if ( 'confirmed' !== sanitize_key( (string) $new_status ) ) return;
		if ( ! $this->is_event_enabled( 'booking_confirmed' ) ) return;
		$booking = $this->resolve_booking( $booking_id );
		if ( ! $booking ) return;
		$this->engine->send_event( 'booking_confirmed', $booking, array(
			'source_event' => 'g2ab_booking_status_changed',
		) );
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
	 * Lifecycle hooks like `g2ab_booking_created` are fired by multiple call
	 * sites — some pass the full booking row, others pass just the booking id.
	 * Without this normalization the merge-tag build collapses an int to
	 * `[0 => 123]` and every customer-facing tag renders empty, so the
	 * customer send is silently skipped by the recipient guard.
	 *
	 * Mirrors the implementation in G2AB_Module_PDF_Invoices::resolve_booking().
	 *
	 * @param mixed $booking_or_id Object, array, or numeric id.
	 * @return object|null Booking row, or null if not resolvable.
	 */
	private function resolve_booking( $booking_or_id ) {
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
		$default_on = array( 'booking_created', 'payment_required', 'pay_in_store_reservation', 'booking_confirmed', 'booking_paid', 'booking_cancelled' );
		if ( ! isset( $opts[ $event ] ) ) return in_array( $event, $default_on, true );
		return ! empty( $opts[ $event ] );
	}
}
