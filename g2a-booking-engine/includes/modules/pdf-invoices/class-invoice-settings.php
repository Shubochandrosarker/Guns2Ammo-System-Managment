<?php
/**
 * PDF Invoice — admin settings tab.
 *
 * @package G2AB\Modules\PDFInvoices
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Invoice_Settings {

	public function __construct() {
		add_filter( 'g2ab_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'g2ab_settings_render_pdf_invoices', array( $this, 'render' ) );
		add_action( 'admin_post_g2ab_save_invoice', array( $this, 'save' ) );
	}

	public function register_tab( $tabs ) {
		$tabs['pdf_invoices'] = 'Invoices';
		return $tabs;
	}

	public function render() {
		$autoload = file_exists( G2AB_PATH . 'vendor/autoload.php' );
		if ( $autoload ) {
			require_once G2AB_PATH . 'vendor/autoload.php';
		}
		$has_dompdf = $autoload && class_exists( '\\Dompdf\\Dompdf' );
		$has_mpdf   = $autoload && class_exists( '\\Mpdf\\Mpdf' );
		$has_wkhtml = function_exists( 'shell_exec' ) && ! empty( trim( @shell_exec( 'which wkhtmltopdf 2>/dev/null' ) ) );
		$has_lite   = true;

		$pdf_status = $has_dompdf ? 'Dompdf' : ( $has_mpdf ? 'mPDF' : ( $has_wkhtml ? 'wkhtmltopdf' : ( $has_lite ? 'Built-in PDF Lite' : 'BROWSER PRINT (fallback)' ) ) );
		$pdf_color = ( $has_dompdf || $has_mpdf || $has_wkhtml || $has_lite ) ? '#4CAF50' : '#E8802F';

		$msg = '';
		if ( ! empty( $_GET['saved'] ) ) $msg = '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
		?>
		<style>
			.g2ab-inv__row{margin-bottom:18px;max-width:760px;}
			.g2ab-inv__row label{display:block;font-weight:600;margin-bottom:6px;}
			.g2ab-inv__row input[type=text],.g2ab-inv__row input[type=url],.g2ab-inv__row textarea,.g2ab-inv__row input[type=number]{width:100%;}
			.g2ab-inv__row textarea{min-height:100px;}
			.g2ab-inv__panel{background:#fff;border:1px solid #d0d4d9;padding:24px;margin-bottom:24px;}
			.g2ab-inv__grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
			.g2ab-inv__pill{display:inline-block;padding:4px 10px;border-radius:2px;color:#fff;font-size:11px;letter-spacing:.06em;font-weight:700;}
		</style>
		<?php echo $msg; // phpcs:ignore ?>

		<div class="g2ab-inv__panel">
			<h2 style="margin-top:0;">PDF Engine
				<span class="g2ab-inv__pill" style="background:<?php echo esc_attr( $pdf_color ); ?>;"><?php echo esc_html( $pdf_status ); ?></span>
			</h2>
			<?php if ( ! $has_dompdf && ! $has_mpdf && ! $has_wkhtml && ! $has_lite ) : ?>
				<p style="margin:0 0 12px;">No server-side PDF library detected. Invoices will render as printable HTML — customers can use browser "Save as PDF" (works on all modern browsers).</p>
				<details>
					<summary style="cursor:pointer;color:#0073aa;">How to enable true PDF (optional)</summary>
					<div style="margin-top:12px;background:#F4F5F7;padding:14px;font-size:13px;line-height:1.6;">
						<strong>Option A — Dompdf via Composer (recommended):</strong>
						<ol style="margin:6px 0 12px 20px;">
							<li>SSH into your server and <code>cd</code> to <code>wp-content/plugins/g2a-booking-engine/</code></li>
							<li>Run <code>composer require dompdf/dompdf</code></li>
							<li>Reload this page — engine will auto-detect.</li>
						</ol>
						<strong>Option B — wkhtmltopdf binary:</strong>
						<p style="margin:6px 0;">Install via <code>apt install wkhtmltopdf</code> (or ask Hostinger support to enable). Auto-detected on next request.</p>
					</div>
				</details>
			<?php else : ?>
				<p style="margin:0;">PDF generation is active. Dompdf or mPDF will be used when installed; otherwise the built-in lightweight invoice PDF engine runs automatically on shared hosting.</p>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'g2ab_save_invoice', '_g2ab_nonce' ); ?>
			<input type="hidden" name="action" value="g2ab_save_invoice" />

			<div class="g2ab-inv__panel">
				<h2 style="margin-top:0;">Branding</h2>
				<div class="g2ab-inv__row">
					<label>Logo URL (paste from Media Library)</label>
					<input type="url" name="logo_url" value="<?php echo esc_attr( get_option( G2AB_Invoice_Engine::OPT_LOGO, '' ) ); ?>" placeholder="https://yoursite.com/wp-content/uploads/logo.png" />
				</div>
				<div class="g2ab-inv__grid">
					<div class="g2ab-inv__row">
						<label>Header Color (hex)</label>
						<input type="text" name="brand_color" value="<?php echo esc_attr( get_option( G2AB_Invoice_Engine::OPT_COLOR, '#1A191E' ) ); ?>" />
					</div>
					<div class="g2ab-inv__row">
						<label>Accent Color (CTA + border)</label>
						<input type="text" name="accent_color" value="<?php echo esc_attr( get_option( G2AB_Invoice_Engine::OPT_ACCENT, '#E8802F' ) ); ?>" />
					</div>
				</div>
				<div class="g2ab-inv__row">
					<label>Business Block (multi-line — appears under "From")</label>
					<textarea name="business_block"><?php echo esc_textarea( get_option( G2AB_Invoice_Engine::OPT_BUSINESS_BLOCK, g2ab_business_name() . "\n" . g2ab_business_address() . "\n" . g2ab_business_phone() ) ); ?></textarea>
				</div>
				<div class="g2ab-inv__row">
					<label>Invoice Footer (HTML allowed — terms, refund policy, etc.)</label>
					<textarea name="footer"><?php echo esc_textarea( get_option( G2AB_Invoice_Engine::OPT_FOOTER, 'Thank you for your business.' ) ); ?></textarea>
				</div>
			</div>

			<div class="g2ab-inv__panel">
				<h2 style="margin-top:0;">Numbering & Tax</h2>
				<div class="g2ab-inv__grid">
					<div class="g2ab-inv__row">
						<label>Invoice Prefix</label>
						<input type="text" name="prefix" value="<?php echo esc_attr( get_option( G2AB_Invoice_Engine::OPT_PREFIX, 'INV' ) ); ?>" />
					</div>
				</div>
				<div class="g2ab-inv__grid">
					<div class="g2ab-inv__row">
						<label>Tax Label</label>
						<input type="text" name="tax_label" value="<?php echo esc_attr( get_option( G2AB_Invoice_Engine::OPT_TAX_LABEL, 'Sales Tax' ) ); ?>" />
					</div>
					<div class="g2ab-inv__row">
						<label>Tax Rate (%) — set 0 if tax-inclusive or no tax</label>
						<input type="number" step="0.01" name="tax_rate" value="<?php echo esc_attr( get_option( G2AB_Invoice_Engine::OPT_TAX_RATE, 0 ) ); ?>" />
					</div>
				</div>
			</div>

			<button class="button button-primary">Save Invoice Settings</button>
		</form>

		<div class="g2ab-inv__panel" style="background:#F4F5F7;">
			<h3 style="margin-top:0;">Preview</h3>
			<p>Generate a sample invoice to preview the design with current settings.</p>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'g2ab_invoice_preview', '1', admin_url( 'admin.php?page=g2ab-settings&tab=pdf_invoices' ) ) ); ?>" target="_blank">Open Sample Invoice</a>
		</div>

		<?php
		// Sample invoice preview handler (admin-only).
		if ( ! empty( $_GET['g2ab_invoice_preview'] ) && current_user_can( 'manage_options' ) ) {
			$engine = new G2AB_Invoice_Engine();
			$sample = (object) array(
				'uuid'         => 'SAMPLE-' . strtoupper( wp_generate_password( 8, false ) ),
				'resource_name' => 'Lane 03 (Tactical)',
				'start_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day 18:00' ) ),
				'duration_min' => 60,
				'party_size'   => 2,
				'amount'       => 89.00,
				'status'       => 'paid',
				'fields'       => json_encode( array( 'name' => 'Sample Customer', 'email' => 'sample@example.com' ) ),
			);
			echo '<div style="border:2px solid #E8802F;margin-top:20px;">' . $engine->render_html( $sample ) . '</div>'; // phpcs:ignore
		}
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_invoice', '_g2ab_nonce' );

		update_option( G2AB_Invoice_Engine::OPT_LOGO,            esc_url_raw( $_POST['logo_url'] ?? '' ) );
		update_option( G2AB_Invoice_Engine::OPT_COLOR,           sanitize_hex_color( $_POST['brand_color'] ?? '#1A191E' ) );
		update_option( G2AB_Invoice_Engine::OPT_ACCENT,          sanitize_hex_color( $_POST['accent_color'] ?? '#E8802F' ) );
		update_option( G2AB_Invoice_Engine::OPT_BUSINESS_BLOCK,  sanitize_textarea_field( $_POST['business_block'] ?? '' ) );
		update_option( G2AB_Invoice_Engine::OPT_FOOTER,          wp_kses_post( wp_unslash( $_POST['footer'] ?? '' ) ) );
		update_option( G2AB_Invoice_Engine::OPT_PREFIX,          sanitize_key( $_POST['prefix'] ?? 'INV' ) );
		update_option( G2AB_Invoice_Engine::OPT_TAX_LABEL,       sanitize_text_field( $_POST['tax_label'] ?? 'Sales Tax' ) );
		update_option( G2AB_Invoice_Engine::OPT_TAX_RATE,        (float) ( $_POST['tax_rate'] ?? 0 ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'pdf_invoices', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
