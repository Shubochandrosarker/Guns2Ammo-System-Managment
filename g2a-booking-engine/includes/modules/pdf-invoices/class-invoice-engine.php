<?php
/**
 * G2AB Invoice Engine — generates HTML + PDF invoices.
 *
 * PDF strategy (in order of preference):
 *   1. Dompdf — if vendor/autoload.php exists with Dompdf installed
 *   2. mPDF   — if mPDF is autoloadable
 *   3. Built-in PDF Lite for hosts without Composer/binaries
 *   4. Fallback: serve the HTML invoice with a "Save as PDF" button
 *      (browser native print-to-PDF). Email attachments fall back to HTML.
 *
 * @package G2AB\Modules\PDFInvoices
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Invoice_Engine {

	const OPT_LOGO          = 'g2ab_invoice_logo_url';
	const OPT_COLOR         = 'g2ab_invoice_brand_color';
	const OPT_ACCENT        = 'g2ab_invoice_accent_color';
	const OPT_BUSINESS_BLOCK = 'g2ab_invoice_business_block';
	const OPT_FOOTER        = 'g2ab_invoice_footer';
	const OPT_PREFIX        = 'g2ab_invoice_prefix';
	const OPT_TAX_LABEL     = 'g2ab_invoice_tax_label';
	const OPT_TAX_RATE      = 'g2ab_invoice_tax_rate';

	/**
	 * Generate PDF (or HTML fallback) and return ['type' => 'pdf'|'html', 'path' => ..., 'url' => ...].
	 */
	public function generate( $booking ) {
		$html = $this->render_html( $booking );

		// Try PDF generation.
		$pdf_path = $this->try_pdf( $html, $booking );
		if ( $pdf_path ) {
			return array( 'type' => 'pdf', 'path' => $pdf_path, 'url' => $this->path_to_url( $pdf_path ) );
		}

		// Fallback: save HTML.
		$file = $this->invoice_filename( $booking, 'html' );
		$path = $this->upload_dir() . $file;
		file_put_contents( $path, $html );
		return array( 'type' => 'html', 'path' => $path, 'url' => $this->path_to_url( $path ) );
	}

	/**
	 * Try to render PDF using available libraries.
	 *
	 * @return string|false Path to PDF on success, false on failure.
	 */
	private function try_pdf( $html, $booking ) {
		$file = $this->invoice_filename( $booking, 'pdf' );
		$path = $this->upload_dir() . $file;

		// 1. Dompdf via vendor/autoload.php.
		$autoload = G2AB_PATH . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
			if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
				try {
					// isRemoteEnabled defaults to false to avoid SSRF via logo URL.
					// Sites that need a remote logo can flip the option in settings.
					$remote = 1 === (int) get_option( 'g2ab_invoice_allow_remote_assets', 0 );
					$dompdf = new \Dompdf\Dompdf( array( 'isRemoteEnabled' => $remote, 'isHtml5ParserEnabled' => true ) );
					$dompdf->loadHtml( $html, 'UTF-8' );
					$dompdf->setPaper( 'A4', 'portrait' );
					$dompdf->render();
					file_put_contents( $path, $dompdf->output() );
					return $path;
				} catch ( \Throwable $e ) {
					error_log( '[G2AB] Dompdf failed: ' . $e->getMessage() );
				}
			}
			if ( class_exists( '\\Mpdf\\Mpdf' ) ) {
				try {
					$mpdf = new \Mpdf\Mpdf();
					$mpdf->WriteHTML( $html );
					$mpdf->Output( $path, 'F' );
					return $path;
				} catch ( \Throwable $e ) {
					error_log( '[G2AB] mPDF failed: ' . $e->getMessage() );
				}
			}
		}

		// 2. wkhtmltopdf binary (some hosts have it).
		if ( function_exists( 'shell_exec' ) && trim( shell_exec( 'which wkhtmltopdf 2>/dev/null' ) ) ) {
			$tmp_html = $this->upload_dir() . $this->invoice_filename( $booking, 'tmp.html' );
			file_put_contents( $tmp_html, $html );
			$cmd = 'wkhtmltopdf --enable-local-file-access ' . escapeshellarg( $tmp_html ) . ' ' . escapeshellarg( $path );
			shell_exec( $cmd . ' 2>&1' );
			@unlink( $tmp_html );
			if ( file_exists( $path ) && filesize( $path ) > 1000 ) return $path;
		}

		if ( $this->generate_lite_pdf( $booking, $path ) ) {
			return $path;
		}

		return false;
	}

	private function generate_lite_pdf( $booking, $path ) {
		$booking = is_object( $booking ) ? (array) $booking : (array) $booking;
		$invoice_no = sanitize_key( get_option( self::OPT_PREFIX, 'INV' ) ) . '-' . strtoupper( substr( $booking['uuid'] ?? '', 0, 8 ) );
		$currency = get_option( 'g2ab_currency', 'USD' );
		$amount = number_format( (float) ( $booking['amount'] ?? ( $booking['paid_amount'] ?? ( $booking['total_amount'] ?? 0 ) ) ), 2 );
		$lines = array(
			'Guns 2 Ammo Booking Invoice',
			'Invoice: ' . $invoice_no,
			'Issued: ' . date_i18n( get_option( 'date_format' ) ),
			'',
			'Customer: ' . sanitize_text_field( $booking['customer_name'] ?? '' ),
			'Email: ' . sanitize_email( $booking['customer_email'] ?? '' ),
			'Phone: ' . sanitize_text_field( $booking['customer_phone'] ?? '' ),
			'',
			'Booking UUID: ' . sanitize_text_field( $booking['uuid'] ?? '' ),
			'Status: ' . sanitize_text_field( $booking['status'] ?? 'paid' ),
			'Amount Paid: ' . $currency . ' ' . $amount,
			'',
			wp_strip_all_tags( get_option( self::OPT_FOOTER, 'Thank you for your business.' ) ),
		);

		$content = "BT\n/F1 16 Tf\n50 790 Td\n";
		foreach ( $lines as $index => $line ) {
			if ( 0 === $index ) {
				$content .= '(' . $this->pdf_escape( $line ) . ") Tj\n/F1 11 Tf\n0 -28 Td\n";
				continue;
			}
			$content .= '(' . $this->pdf_escape( $line ) . ") Tj\n0 -18 Td\n";
		}
		$content .= "ET\n";

		$objects = array(
			"1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
			"2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
			"3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
			"4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
			"5 0 obj\n<< /Length " . strlen( $content ) . " >>\nstream\n" . $content . "endstream\nendobj\n",
		);

		$pdf = "%PDF-1.4\n";
		$offsets = array( 0 );
		foreach ( $objects as $object ) {
			$offsets[] = strlen( $pdf );
			$pdf .= $object;
		}

		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= count( $objects ); $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

		return false !== file_put_contents( $path, $pdf ) && file_exists( $path ) && filesize( $path ) > 500;
	}

	private function pdf_escape( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/[^\x20-\x7E]/', ' ', $text );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}

	private function upload_dir() {
		$up = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'g2ab-invoices/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Lock down — invoices contain customer data.
			file_put_contents( $dir . '.htaccess', "Options -Indexes\nDeny from all\n" );
			file_put_contents( $dir . 'index.html', '' );
		}
		return $dir;
	}

	private function path_to_url( $path ) {
		// Invoices are gated — return a public viewer URL with a signed token so
		// the link emailed to the customer works without requiring login.
		$uuid = basename( $path, '.' . pathinfo( $path, PATHINFO_EXTENSION ) );
		$args = array( 'g2ab_invoice' => $uuid );
		if ( function_exists( 'g2ab_invoice_sign_token' ) ) {
			$args['t'] = g2ab_invoice_sign_token( $uuid );
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	private function invoice_filename( $booking, $ext = 'pdf' ) {
		$id = is_object( $booking ) ? $booking->uuid : ( $booking['uuid'] ?? wp_generate_uuid4() );
		$prefix = sanitize_key( get_option( self::OPT_PREFIX, 'INV' ) );
		return $prefix . '-' . sanitize_file_name( $id ) . '.' . $ext;
	}

	/**
	 * Render the invoice HTML.
	 */
	public function render_html( $booking ) {
		$booking = is_object( $booking ) ? (array) $booking : (array) $booking;

		$brand   = get_option( self::OPT_COLOR, '#0F1115' );
		$accent  = get_option( self::OPT_ACCENT, '#D2691E' );
		$logo    = get_option( self::OPT_LOGO, '' );
		$biz     = get_option( self::OPT_BUSINESS_BLOCK, get_option( 'g2ab_business_name' ) . "\n" . get_option( 'g2ab_business_address' ) . "\n" . get_option( 'g2ab_business_phone' ) );
		$footer  = get_option( self::OPT_FOOTER, 'Thank you for your business.' );
		$tax_label = get_option( self::OPT_TAX_LABEL, 'Sales Tax' );
		$tax_rate = (float) get_option( self::OPT_TAX_RATE, 0 );

		$amount = (float) ( $booking['amount'] ?? ( $booking['paid_amount'] ?? ( $booking['total_amount'] ?? 0 ) ) );
		$tax_amount = round( $amount * ( $tax_rate / 100 ), 2 );
		$subtotal = $amount - $tax_amount;
		if ( $tax_rate <= 0 ) { $subtotal = $amount; $tax_amount = 0; }
		$total = $amount;
		$currency = get_option( 'g2ab_currency', 'USD' );

		$customer_name  = sanitize_text_field( $booking['customer_name'] ?? '' );
		$customer_email = sanitize_email( $booking['customer_email'] ?? '' );
		$customer_phone = sanitize_text_field( $booking['customer_phone'] ?? '' );
		if ( ! empty( $booking['fields'] ) ) {
			$f = is_string( $booking['fields'] ) ? json_decode( $booking['fields'], true ) : $booking['fields'];
			if ( is_array( $f ) ) {
				$customer_name = $customer_name ?: ( $f['customer_name'] ?? ( $f['name'] ?? trim( ( $f['first_name'] ?? '' ) . ' ' . ( $f['last_name'] ?? '' ) ) ) );
				$customer_email = $customer_email ?: ( $f['customer_email'] ?? ( $f['email'] ?? '' ) );
				$customer_phone = $customer_phone ?: ( $f['customer_phone'] ?? ( $f['phone'] ?? '' ) );
			}
		}
		if ( empty( $booking['fields'] ) && ! empty( $booking['form_data'] ) ) {
			$f = is_string( $booking['form_data'] ) ? json_decode( $booking['form_data'], true ) : $booking['form_data'];
			if ( is_array( $f ) ) {
				$customer_name = $customer_name ?: ( $f['customer_name'] ?? '' );
				$customer_email = $customer_email ?: ( $f['customer_email'] ?? '' );
				$customer_phone = $customer_phone ?: ( $f['customer_phone'] ?? '' );
			}
		}

		$invoice_no = sanitize_key( get_option( self::OPT_PREFIX, 'INV' ) ) . '-' . strtoupper( substr( $booking['uuid'] ?? '', 0, 8 ) );
		$issued = date_i18n( get_option( 'date_format' ) );
		$status = $booking['payment_status'] ?? ( $booking['status'] ?? 'paid' );
		$status_color = in_array( $status, array( 'paid', 'completed' ), true ) ? '#4CAF50' : '#F9A825';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?php echo esc_html( $invoice_no ); ?></title>
<style>
@page { margin: 24mm 18mm; }
* { box-sizing: border-box; }
body { font-family: 'Helvetica', Arial, sans-serif; color: #0F1115; margin: 0; padding: 0; font-size: 13px; line-height: 1.5; }
.inv { max-width: 760px; margin: 0 auto; padding: 0; }
.inv__head { background: <?php echo esc_attr( $brand ); ?>; color: #fff; padding: 28px 32px; border-bottom: 6px solid <?php echo esc_attr( $accent ); ?>; }
.inv__head-flex { display: table; width: 100%; }
.inv__head-l, .inv__head-r { display: table-cell; vertical-align: top; }
.inv__head-r { text-align: right; }
.inv__logo { max-height: 48px; }
.inv__brand-text { font-family: 'Inter', 'Segoe UI', 'Arial Black', sans-serif; font-size: 28px; letter-spacing: .04em; }
.inv__title { font-family: 'Inter', 'Segoe UI', sans-serif; font-size: 36px; letter-spacing: .08em; margin: 0; }
.inv__num { font-size: 12px; opacity: .85; letter-spacing: .06em; }
.inv__body { padding: 32px; }
.inv__row { display: table; width: 100%; margin-bottom: 28px; }
.inv__col { display: table-cell; vertical-align: top; width: 50%; padding-right: 16px; }
.inv__col h4 { font-size: 11px; letter-spacing: .08em; color: #8A95A5; text-transform: uppercase; margin: 0 0 6px; font-weight: 700; }
.inv__col p { margin: 0; white-space: pre-line; }
.inv__status { display: inline-block; background: <?php echo esc_attr( $status_color ); ?>; color: #fff; padding: 6px 14px; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; }
table.inv__items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
table.inv__items th { background: #F4F5F7; padding: 12px; text-align: left; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; border-bottom: 2px solid <?php echo esc_attr( $brand ); ?>; }
table.inv__items td { padding: 14px 12px; border-bottom: 1px solid #E2E5E9; }
table.inv__items td.right { text-align: right; }
.inv__totals { width: 280px; margin-left: auto; }
.inv__totals tr td { padding: 6px 0; }
.inv__totals tr td.right { text-align: right; }
.inv__totals tr.grand td { border-top: 2px solid <?php echo esc_attr( $brand ); ?>; font-size: 16px; font-weight: 700; padding-top: 12px; color: <?php echo esc_attr( $brand ); ?>; }
.inv__footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #E2E5E9; font-size: 11px; color: #666; text-align: center; }
.inv__pay-cta { background: <?php echo esc_attr( $accent ); ?>; color: #fff; padding: 14px 32px; text-decoration: none; display: inline-block; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; margin-top: 12px; }
</style>
</head>
<body>
<div class="inv">
	<div class="inv__head">
		<div class="inv__head-flex">
			<div class="inv__head-l">
				<?php if ( $logo ) : ?>
					<img src="<?php echo esc_url( $logo ); ?>" alt="" class="inv__logo" />
				<?php else : ?>
					<div class="inv__brand-text"><?php echo esc_html( get_option( 'g2ab_business_name', get_bloginfo( 'name' ) ) ); ?></div>
				<?php endif; ?>
			</div>
			<div class="inv__head-r">
				<h1 class="inv__title">INVOICE</h1>
				<div class="inv__num"><?php echo esc_html( $invoice_no ); ?></div>
				<div class="inv__num"><?php echo esc_html( $issued ); ?></div>
			</div>
		</div>
	</div>

	<div class="inv__body">
		<div class="inv__row">
			<div class="inv__col">
				<h4>From</h4>
				<p><?php echo nl2br( esc_html( $biz ) ); ?></p>
			</div>
			<div class="inv__col">
				<h4>Billed To</h4>
				<p><?php echo esc_html( $customer_name ?: 'Walk-in Customer' ); ?><br>
				<?php echo esc_html( $customer_email ); ?><br>
				<?php echo esc_html( $customer_phone ); ?></p>
				<p style="margin-top:14px;"><span class="inv__status"><?php echo esc_html( strtoupper( $status ) ); ?></span></p>
			</div>
		</div>

		<table class="inv__items">
			<thead>
				<tr>
					<th>Description</th>
					<th class="right" style="text-align:right;">Qty</th>
					<th class="right" style="text-align:right;">Rate</th>
					<th class="right" style="text-align:right;">Amount</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>
						<strong><?php echo esc_html( $booking['resource_name'] ?? 'Booking' ); ?></strong><br>
						<small style="color:#666;">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $booking['start_at'] ?? 'now' ) ) ); ?>
							&middot; <?php echo (int) ( $booking['duration_min'] ?? 60 ); ?> min
							&middot; <?php echo (int) ( $booking['party_size'] ?? 1 ); ?> shooter(s)
						</small>
					</td>
					<td class="right">1</td>
					<td class="right">$<?php echo esc_html( number_format( $subtotal, 2 ) ); ?></td>
					<td class="right">$<?php echo esc_html( number_format( $subtotal, 2 ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<table class="inv__totals">
			<tr><td>Subtotal</td><td class="right">$<?php echo esc_html( number_format( $subtotal, 2 ) ); ?></td></tr>
			<?php if ( $tax_amount > 0 ) : ?>
				<tr><td><?php echo esc_html( $tax_label ); ?> (<?php echo esc_html( $tax_rate ); ?>%)</td><td class="right">$<?php echo esc_html( number_format( $tax_amount, 2 ) ); ?></td></tr>
			<?php endif; ?>
			<tr class="grand"><td>TOTAL</td><td class="right">$<?php echo esc_html( number_format( $total, 2 ) ); ?> <?php echo esc_html( $currency ); ?></td></tr>
		</table>

		<?php if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) : ?>
			<p style="text-align:center;margin-top:32px;">
				<a href="<?php echo esc_url( add_query_arg( 'g2ab_pay', $booking['uuid'], home_url( '/' ) ) ); ?>" class="inv__pay-cta">Pay Now</a>
			</p>
		<?php endif; ?>

		<div class="inv__footer">
			<p><?php echo wp_kses_post( $footer ); ?></p>
			<p>Confirmation: <code><?php echo esc_html( $booking['uuid'] ?? '' ); ?></code></p>
		</div>
	</div>
</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
