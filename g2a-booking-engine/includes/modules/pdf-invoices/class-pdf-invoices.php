<?php
/**
 * PDF Invoices — module bootstrap.
 *
 * - Hooks into g2ab_booking_paid to attach invoice PDF to confirmation email
 * - Registers viewer route + admin settings
 *
 * @package G2AB\Modules\PDFInvoices
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_PDF_Invoices {

	private static $instance = null;
	private $engine = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$this->engine = new G2AB_Invoice_Engine();
		new G2AB_Invoice_Renderer( $this->engine );

		if ( is_admin() ) new G2AB_Invoice_Settings();

		// Generate the invoice as soon as a booking is paid — independent of
		// whether the paid email is enabled. Gateways fire `g2ab_payment_succeeded`
		// (Stripe, PayPal, Fortis, Authnet) and the Woo bridge fires
		// `g2ab_booking_paid`, so we listen to both.
		add_action( 'g2ab_booking_paid',      array( $this, 'on_booking_paid' ), 10, 2 );
		add_action( 'g2ab_payment_succeeded', array( $this, 'on_payment_succeeded' ), 10, 3 );

		// Attach the (already generated) invoice to the payment-received email.
		add_filter( 'g2ab_email_attachments', array( $this, 'attach_to_paid_email' ), 10, 4 );
	}

	/**
	 * `g2ab_booking_paid` listener — accepts either a booking row/object or a
	 * booking id depending on who fired it.
	 *
	 * @param mixed $booking_or_id Booking row, object, or numeric id.
	 * @param array $context       Optional context.
	 */
	public function on_booking_paid( $booking_or_id, $context = array() ) {
		$booking = $this->resolve_booking( $booking_or_id );
		if ( $booking ) {
			$this->safe_generate( $booking );
		}
	}

	/**
	 * `g2ab_payment_succeeded` listener — gateway hooks pass the booking id.
	 */
	public function on_payment_succeeded( $booking_id, $gateway = '', $payload = null ) {
		$booking = $this->resolve_booking( $booking_id );
		if ( $booking ) {
			$this->safe_generate( $booking );
		}
	}

	private function resolve_booking( $booking_or_id ) {
		if ( is_object( $booking_or_id ) || ( is_array( $booking_or_id ) && ! empty( $booking_or_id['id'] ) ) ) {
			return $booking_or_id;
		}
		$id = absint( $booking_or_id );
		if ( ! $id ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1",
			$id
		) );
	}

	private function safe_generate( $booking ) {
		try {
			$this->engine->generate( $booking );
		} catch ( \Throwable $e ) {
			error_log( '[G2AB] Invoice generation failed: ' . $e->getMessage() );
		}
	}

	public function engine() { return $this->engine; }

	public function attach_to_paid_email( $attachments, $event, $booking, $context ) {
		if ( 'booking_paid' !== $event ) return $attachments;
		try {
			$result = $this->engine->generate( $booking );
			if ( ! empty( $result['path'] ) && file_exists( $result['path'] ) ) {
				$attachments[] = $result['path'];
			}
		} catch ( \Throwable $e ) {
			error_log( '[G2AB] Invoice attachment failed: ' . $e->getMessage() );
		}
		return $attachments;
	}
}
