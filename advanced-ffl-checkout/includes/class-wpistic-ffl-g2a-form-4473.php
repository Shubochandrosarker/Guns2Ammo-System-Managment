<?php
/**
 * G2A — ATF Form 4473 pre-fill draft generator.
 *
 * Produces a non-official PDF DRAFT that auto-fills Section A (transferee
 * info, item description, transferor info) from the FFL transfer record so
 * the dealer's staff can use it as a template to speed up paperwork at
 * pickup time. Stamped "DRAFT — NOT FOR ATF SUBMISSION" on every page so
 * nobody mistakes it for a real 4473.
 *
 * Implementation note: we do NOT distribute or replicate the actual ATF
 * Form 4473. This generator outputs our own labelled-field worksheet that
 * mirrors the same field labels — staff transcribe values to the real
 * official form. Legally safe + operationally useful.
 *
 * Output is on-the-fly HTML routed to the browser as text/html with a
 * print stylesheet, so no server-side PDF lib is required. Dealers print
 * with browser File → Print → Save as PDF.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Form_4473 {

	const QUERY_VAR = 'g2a_4473_draft';

	public function __construct() {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_var' ] );
		add_action( 'template_redirect',  [ $this, 'maybe_render' ] );
	}

	public function register_rewrites(): void {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([0-9]+)' );
		add_rewrite_rule( '^ffl-4473-draft/([0-9]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public function add_query_var( array $v ): array {
		$v[] = self::QUERY_VAR;
		return $v;
	}

	public function maybe_render(): void {
		$id = (int) get_query_var( self::QUERY_VAR );
		if ( ! $id ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_safe_redirect( wp_login_url( home_url() ) );
			exit;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.*, d.business_name AS dealer_name, d.license_number AS dealer_license,
			        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
			        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d', $id
		) );
		if ( ! $row ) {
			status_header( 404 );
			wp_die( 'Transfer not found.' );
		}
		self::render( $row );
		exit;
	}

	private static function render( object $t ): void {
		$theme = Theming::settings();
		$store = $theme['business_name'] ?: get_bloginfo( 'name' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>4473 Draft — <?php echo esc_html( $t->transfer_ref ); ?></title>
<style>
@page { size: Letter; margin: 0.6in; }
body { font-family: "Helvetica Neue", Arial, sans-serif; color: #111; font-size: 11pt; line-height: 1.4; margin: 24px; }
.draft-banner { background: #DCB45F; color: #0F0E12; padding: 8px 14px; margin: 0 0 18px; border-radius: 6px; text-align:center; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
.draft-banner small { display:block;font-weight:400;text-transform:none;letter-spacing:0;margin-top:2px; }
h1 { font-size: 18pt; margin: 0 0 4px; }
.eyebrow { font-size: 9pt; color: #666; text-transform: uppercase; letter-spacing: .1em; margin: 0 0 18px; }
fieldset { border: 1.5px solid #1A191E; padding: 14px 16px; margin: 0 0 14px; border-radius: 6px; page-break-inside: avoid; }
legend { padding: 0 8px; font-weight: 700; font-size: 10pt; text-transform: uppercase; letter-spacing: .06em; color: #1A191E; }
table.kv { width: 100%; border-collapse: collapse; }
table.kv td { padding: 5px 8px; border-bottom: 1px dotted #ccc; vertical-align: top; font-size: 10.5pt; }
table.kv td:first-child { width: 38%; color: #444; font-weight: 600; font-size: 9.5pt; text-transform: uppercase; letter-spacing: .04em; }
.sig { margin-top: 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.sig div { border-top: 1px solid #1A191E; padding-top: 4px; font-size: 9pt; color: #666; text-transform: uppercase; letter-spacing: .06em; }
.footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #ccc; font-size: 9pt; color: #666; line-height: 1.6; }
.btn-print { background: #DCB45F; color: #0F0E12; padding: 8px 16px; border: none; border-radius: 6px; font-weight: 800; cursor: pointer; }
@media print { .no-print { display: none; } body { margin: 0; } }
</style>
</head>
<body>

<div class="no-print" style="margin-bottom:14px;display:flex;gap:10px;align-items:center;justify-content:space-between;">
	<strong>4473 worksheet for transfer #<?php echo esc_html( $t->transfer_ref ); ?></strong>
	<button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<div class="draft-banner">
	⚠ DRAFT — NOT FOR ATF SUBMISSION
	<small>This worksheet pre-fills the same field labels as ATF Form 4473 to speed up the dealer's paperwork. Transcribe values onto the official ATF form.</small>
</div>

<h1><?php echo esc_html( $store ); ?> — FFL Transfer Worksheet</h1>
<p class="eyebrow">Transfer Reference: <?php echo esc_html( $t->transfer_ref ); ?> · Generated <?php echo esc_html( date_i18n( 'F j, Y g:i a' ) ); ?></p>

<fieldset>
	<legend>Section A · Firearm Transaction Information</legend>
	<table class="kv">
		<tr><td>Manufacturer / Importer</td><td><?php echo esc_html( $t->item_make ?: '—' ); ?></td></tr>
		<tr><td>Model</td><td><?php echo esc_html( $t->item_model ?: '—' ); ?></td></tr>
		<tr><td>Serial number</td><td><?php echo esc_html( $t->item_serial ?: '__________________' ); ?></td></tr>
		<tr><td>Type</td><td><?php echo esc_html( ucfirst( $t->item_type ?: 'handgun' ) ); ?></td></tr>
		<tr><td>Caliber / gauge</td><td><?php echo esc_html( $t->item_caliber ?: '__________' ); ?></td></tr>
		<tr><td>Item description (SKU)</td><td><?php echo esc_html( $t->item_description . ( $t->item_sku ? ' — SKU ' . $t->item_sku : '' ) ); ?></td></tr>
	</table>
</fieldset>

<fieldset>
	<legend>Section B · Transferee (Buyer)</legend>
	<table class="kv">
		<tr><td>Name</td><td><?php echo esc_html( $t->customer_name ); ?></td></tr>
		<tr><td>Phone</td><td><?php echo esc_html( $t->customer_phone ?: '—' ); ?></td></tr>
		<tr><td>Email</td><td><?php echo esc_html( $t->customer_email ); ?></td></tr>
		<tr><td>Date of birth</td><td>__ / __ / ____</td></tr>
		<tr><td>State of residence</td><td>__</td></tr>
		<tr><td>ID type + number</td><td>____________________________</td></tr>
		<tr><td>State permit (if any)</td><td>____________________________</td></tr>
	</table>
</fieldset>

<fieldset>
	<legend>Section C · NICS</legend>
	<table class="kv">
		<tr><td>NICS check date</td><td><?php echo esc_html( $t->nics_check_date ?: '____ / ____ / ____' ); ?></td></tr>
		<tr><td>NICS Transaction Number (NTN)</td><td><?php echo esc_html( $t->nics_transaction_number ?: '____________________' ); ?></td></tr>
		<tr><td>Response</td><td>☐ Proceed ☐ Delayed ☐ Denied ☐ Cancelled</td></tr>
		<tr><td>3-day window expires (if delayed)</td><td><?php echo esc_html( $t->nics_delay_expires ?: '____ / ____ / ____' ); ?></td></tr>
	</table>
</fieldset>

<fieldset>
	<legend>Section D · Transferor (Receiving FFL)</legend>
	<table class="kv">
		<tr><td>Business name</td><td><?php echo esc_html( $t->dealer_name ?? '—' ); ?></td></tr>
		<tr><td>FFL number</td><td><?php echo esc_html( $t->dealer_license ?? '—' ); ?></td></tr>
		<tr><td>Address</td><td><?php echo esc_html( trim( ( $t->dealer_street ?? '' ) . ', ' . ( $t->dealer_city ?? '' ) . ', ' . ( $t->dealer_state ?? '' ) . ' ' . ( $t->dealer_zip ?? '' ), ', ' ) ); ?></td></tr>
		<tr><td>Phone</td><td><?php echo esc_html( $t->dealer_phone ?? '—' ); ?></td></tr>
		<tr><td>Transfer date</td><td><?php echo esc_html( $t->transfer_date ?: '____ / ____ / ____' ); ?></td></tr>
		<tr><td>Form 4473 reference</td><td><?php echo esc_html( $t->form_4473_ref ?: '__________________' ); ?></td></tr>
	</table>
</fieldset>

<div class="sig">
	<div>Buyer signature · date</div>
	<div>Dealer signature · date</div>
</div>

<div class="footer">
	This worksheet is a <strong>draft only</strong> generated from the e-commerce transfer record. It is not the official ATF Form 4473 and may not be submitted to ATF in place of the official form. All federal certification questions (Section B 11.a–11.l, etc.) must be answered by the transferee on the official Form 4473 in the presence of the dealer.
</div>

</body>
</html>
		<?php
	}
}
