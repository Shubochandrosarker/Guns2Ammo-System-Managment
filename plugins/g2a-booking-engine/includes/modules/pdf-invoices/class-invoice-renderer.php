<?php
/**
 * Invoice front-end viewer + download routes.
 *
 * Adds two query handlers:
 *   ?g2ab_invoice={uuid}&t={signed_token}       → render invoice (HTML or PDF)
 *   ?g2ab_invoice_pdf={uuid}&t={signed_token}   → force-download PDF (or fall back to print)
 *
 * Access control (in order):
 *   1. Valid signed token (HMAC, default 30-day expiry) — what we email customers.
 *   2. Logged-in user whose email or user_id matches the booking owner.
 *   3. Logged-in staff with `manage_g2ab_bookings` cap.
 *   4. Filterable via `g2ab_invoice_can_view` (defaults to FALSE — fail-closed).
 *
 * @package G2AB\Modules\PDFInvoices
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Invoice_Renderer {

	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
	}

	public function maybe_serve() {
		$uuid_view = isset( $_GET['g2ab_invoice'] ) ? sanitize_text_field( wp_unslash( $_GET['g2ab_invoice'] ) ) : '';
		$uuid_pdf  = isset( $_GET['g2ab_invoice_pdf'] ) ? sanitize_text_field( wp_unslash( $_GET['g2ab_invoice_pdf'] ) ) : '';

		if ( ! $uuid_view && ! $uuid_pdf ) return;
		$uuid = $uuid_view ?: $uuid_pdf;

		// Strip filename prefix if user pasted full filename.
		$uuid = preg_replace( '/\.(pdf|html)$/i', '', $uuid );
		$uuid = preg_replace( '/^INV-/i', '', $uuid );

		$booking = $this->fetch_booking( $uuid );
		if ( ! $booking ) {
			status_header( 404 );
			wp_die( 'Invoice not found.', 'Not Found', array( 'response' => 404 ) );
		}

		if ( ! $this->user_can_view( $booking ) ) {
			status_header( 403 );
			wp_die( 'You do not have permission to view this invoice. Please use the link from your confirmation email.', 'Forbidden', array( 'response' => 403 ) );
		}

		if ( $uuid_pdf ) {
			$result = $this->engine->generate( $booking );
			if ( 'pdf' === $result['type'] && file_exists( $result['path'] ) ) {
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: attachment; filename="' . basename( $result['path'] ) . '"' );
				header( 'Content-Length: ' . filesize( $result['path'] ) );
				header( 'X-Robots-Tag: noindex, nofollow' );
				readfile( $result['path'] );
				exit;
			}
			// Fall through to HTML render with print-on-load.
		}

		// Render inline HTML.
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $this->engine->render_html( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $uuid_pdf ) {
			echo '<script>window.addEventListener("load", function(){ setTimeout(function(){ window.print(); }, 400); });</script>';
		} else {
			$token = function_exists( 'g2ab_invoice_sign_token' ) ? g2ab_invoice_sign_token( $booking->uuid ) : '';
			$pdf_url = esc_url( add_query_arg( array(
				'g2ab_invoice_pdf' => $booking->uuid,
				't'                => $token,
			), home_url( '/' ) ) );
			echo '<a href="' . $pdf_url . '" style="position:fixed;top:20px;right:20px;background:#E8802F;color:#fff;padding:12px 24px;text-decoration:none;font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-family:Arial,sans-serif;font-size:12px;box-shadow:0 4px 12px rgba(0,0,0,.2);" onclick="event.preventDefault();window.print();">Download / Print PDF</a>';
		}
		exit;
	}

	/**
	 * Access check. Fail-closed — anyone without a credential gets 403.
	 */
	private function user_can_view( $booking ) {
		// 1. Signed token from URL (this is what the confirmation email contains).
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		if ( $token && function_exists( 'g2ab_invoice_verify_token' ) && g2ab_invoice_verify_token( $booking->uuid, $token ) ) {
			return apply_filters( 'g2ab_invoice_can_view', true, $booking, 'token' );
		}

		// 2/3. Logged-in user — owner or staff.
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$is_owner = (
				( (int) $user->ID === (int) $booking->user_id )
				|| ( strtolower( $user->user_email ) === strtolower( (string) $booking->customer_email ) )
			);
			if ( $is_owner || current_user_can( 'manage_g2ab_bookings' ) ) {
				return apply_filters( 'g2ab_invoice_can_view', true, $booking, $is_owner ? 'owner' : 'staff' );
			}
		}

		// 4. Fail-closed.
		return (bool) apply_filters( 'g2ab_invoice_can_view', false, $booking, '' );
	}

	private function fetch_booking( $uuid ) {
		global $wpdb;
		$bk = $wpdb->prefix . 'g2ab_bookings';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bk} WHERE uuid = %s LIMIT 1", $uuid ) ); // phpcs:ignore
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bk} WHERE uuid LIKE %s LIMIT 1", $wpdb->esc_like( $uuid ) . '%' ) ); // phpcs:ignore
		}
		if ( ! $row ) return null;

		if ( ! empty( $row->resource_id ) ) {
			$rt = $wpdb->prefix . 'g2ab_resources';
			$row->resource_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$rt} WHERE id = %d", (int) $row->resource_id ) ); // phpcs:ignore
		}
		return $row;
	}
}
