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
 * with browser File → Print → Save as PDF — the v1.8.0 signature capture
 * below draws directly onto that same printable page, so a captured
 * signature is already part of the PDF the "Print / Save as PDF" button
 * produces; no PDF library was introduced for this.
 *
 * v1.8.0 — on-screen signature capture (buyer + dealer) so the in-counter
 * worksheet carries a real captured signature instead of a blank line staff
 * fill in by hand later. Captures are append-only in the `signatures` table
 * (same audit philosophy as the `events` log) — re-signing adds a new row,
 * it never overwrites the prior capture.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Form_4473 {

	const QUERY_VAR = 'g2a_4473_draft';

	/** Generous ceiling for a signature-pad PNG at typical stroke complexity. */
	const MAX_SIGNATURE_BYTES = 300000;

	const ROLES = [ 'buyer', 'dealer' ];

	public function __construct() {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_var' ] );
		add_action( 'template_redirect',  [ $this, 'maybe_render' ] );
		add_action( 'wp_ajax_wpistic_ffl_4473_save_signature', [ __CLASS__, 'ajax_save_signature' ] );
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

	/**
	 * Most recent signature row per role for this transfer, or null if that
	 * role hasn't signed yet. Append-only table — "most recent" is the
	 * currently-effective signature.
	 *
	 * @return array<string,?object> [ 'buyer' => object|null, 'dealer' => object|null ]
	 */
	private static function latest_signatures( int $transfer_id ): array {
		global $wpdb;
		$out = [ 'buyer' => null, 'dealer' => null ];
		foreach ( self::ROLES as $role ) {
			$out[ $role ] = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT * FROM ' . DB::table( 'signatures' ) . '
				 WHERE transfer_id = %d AND role = %s
				 ORDER BY created_at DESC, id DESC LIMIT 1',
				$transfer_id, $role
			) );
		}
		return $out;
	}

	/**
	 * AJAX: persist a captured signature (buyer or dealer) for a transfer.
	 * Admin-only (manage_woocommerce), nonce-protected, structurally
	 * validates the payload is really a PNG before it ever reaches the DB.
	 */
	public static function ajax_save_signature(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$transfer_id = (int) ( $_POST['transfer_id'] ?? 0 );
		$role        = sanitize_key( $_POST['role'] ?? '' );
		$signer_name = sanitize_text_field( wp_unslash( $_POST['signer_name'] ?? '' ) );
		$data_url    = (string) ( $_POST['signature_data'] ?? '' );
		// phpcs:enable

		if ( ! $transfer_id || ! in_array( $role, self::ROLES, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid transfer or role.' ], 400 );
		}

		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT COUNT(*) FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d', $transfer_id
		) );
		if ( ! $exists ) {
			wp_send_json_error( [ 'message' => 'Transfer not found.' ], 404 );
		}

		if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$#', $data_url, $m ) ) {
			wp_send_json_error( [ 'message' => 'Signature must be a PNG data URL.' ], 400 );
		}
		if ( strlen( $m[1] ) > self::MAX_SIGNATURE_BYTES ) {
			wp_send_json_error( [ 'message' => 'Signature image is too large.' ], 400 );
		}
		$binary = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $binary || ! function_exists( 'getimagesizefromstring' ) || false === @getimagesizefromstring( $binary ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_send_json_error( [ 'message' => 'Signature data is not a valid image.' ], 400 );
		}
		// Reject a blank/near-blank canvas — the pointer-events JS already
		// guards Save, but a direct API caller shouldn't be able to skip it.
		if ( strlen( $binary ) < 200 ) {
			wp_send_json_error( [ 'message' => 'Signature appears empty.' ], 400 );
		}

		$user = wp_get_current_user();
		$wpdb->insert( DB::table( 'signatures' ), [ // phpcs:ignore WordPress.DB
			'transfer_id'    => $transfer_id,
			'role'           => $role,
			'signer_name'    => $signer_name ?: ( 'buyer' === $role ? '' : $user->display_name ),
			'signature_data' => $data_url,
			'signed_by'      => $user->ID ?: null,
			'signed_ip'      => self::ip_to_binary( Token::client_ip() ),
		] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'signature_captured',
			'notes'       => ucfirst( $role ) . ' signature captured for the 4473 worksheet.',
			'actor'       => $user->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success( [
			'role'        => $role,
			'signer_name' => $signer_name,
			'signed_at'   => date_i18n( 'F j, Y g:i a' ),
			'data_url'    => $data_url,
		] );
	}

	private static function ip_to_binary( string $ip ): ?string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $packed ? null : $packed;
	}

	private static function render( object $t ): void {
		$theme = Theming::settings();
		$store = $theme['business_name'] ?: get_bloginfo( 'name' );
		$sigs  = self::latest_signatures( (int) $t->id );
		$nonce = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
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
.sig-block { margin-top: 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; page-break-inside: avoid; }
.sig-pad-wrap { border: 1.5px solid #1A191E; border-radius: 6px; padding: 10px; }
.sig-pad-wrap.is-signed { border-style: solid; }
.sig-label { font-size: 9pt; color: #666; text-transform: uppercase; letter-spacing: .06em; margin: 0 0 6px; display:flex; justify-content:space-between; align-items:center; }
.sig-canvas { width: 100%; height: 120px; touch-action: none; background: #fafafa; border: 1px dashed #ccc; border-radius: 4px; display:block; cursor: crosshair; }
.sig-image { width: 100%; height: 120px; object-fit: contain; background: #fff; border: 1px solid #eee; border-radius: 4px; }
.sig-meta { font-size: 8.5pt; color: #888; margin-top: 6px; }
.sig-name-input { width:100%; margin-top:8px; padding:5px 7px; font-size:9.5pt; border:1px solid #ccc; border-radius:4px; }
.sig-actions { display:flex; gap:8px; margin-top:8px; }
.sig-actions button { font-size: 9pt; padding: 5px 11px; border-radius: 4px; border: 1px solid #1A191E; background: #fff; cursor: pointer; }
.sig-actions button.primary { background: #DCB45F; color: #0F0E12; border-color: #DCB45F; font-weight: 700; }
.sig-actions button:disabled { opacity:.45; cursor:not-allowed; }
.footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #ccc; font-size: 9pt; color: #666; line-height: 1.6; }
.btn-print { background: #DCB45F; color: #0F0E12; padding: 8px 16px; border: none; border-radius: 6px; font-weight: 800; cursor: pointer; }
@media print {
	.no-print { display: none; }
	body { margin: 0; }
	.sig-pad-wrap { border-style: solid !important; }
}
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

<div class="sig-block">
<?php foreach ( self::ROLES as $role ) :
	$sig      = $sigs[ $role ];
	$label    = 'buyer' === $role ? 'Buyer signature · date' : 'Dealer signature · date';
	$defaultName = 'buyer' === $role ? $t->customer_name : ( $t->dealer_name ?? '' );
	?>
	<div class="sig-pad-wrap<?php echo $sig ? ' is-signed' : ''; ?>" data-role="<?php echo esc_attr( $role ); ?>">
		<p class="sig-label">
			<span><?php echo esc_html( $label ); ?></span>
			<span class="no-print"><button type="button" class="sig-resign" style="display:<?php echo $sig ? 'inline' : 'none'; ?>;font-size:8pt;background:none;border:none;text-decoration:underline;cursor:pointer;color:#666;">re-sign</button></span>
		</p>

		<img class="sig-image" style="display:<?php echo $sig ? 'block' : 'none'; ?>;" src="<?php echo $sig ? esc_attr( $sig->signature_data ) : ''; ?>" alt="<?php echo esc_attr( ucfirst( $role ) ); ?> signature">
		<canvas class="sig-canvas" style="display:<?php echo $sig ? 'none' : 'block'; ?>;" width="500" height="120"></canvas>

		<p class="sig-meta"><?php echo $sig ? esc_html( ( $sig->signer_name ?: ucfirst( $role ) ) . ' · signed ' . date_i18n( 'F j, Y g:i a', strtotime( $sig->created_at ) ) ) : ''; ?></p>

		<div class="no-print sig-pad-controls" style="display:<?php echo $sig ? 'none' : 'block'; ?>;">
			<input type="text" class="sig-name-input" placeholder="Full name" value="<?php echo esc_attr( $defaultName ); ?>">
			<div class="sig-actions">
				<button type="button" class="sig-clear">Clear</button>
				<button type="button" class="sig-save primary" disabled>Save Signature</button>
			</div>
		</div>
	</div>
<?php endforeach; ?>
</div>

<div class="footer">
	This worksheet is a <strong>draft only</strong> generated from the e-commerce transfer record. It is not the official ATF Form 4473 and may not be submitted to ATF in place of the official form. All federal certification questions (Section B 11.a–11.l, etc.) must be answered by the transferee on the official Form 4473 in the presence of the dealer.
</div>

<script>
(function() {
	var ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var nonce       = <?php echo wp_json_encode( $nonce ); ?>;
	var transferId  = <?php echo (int) $t->id; ?>;

	document.querySelectorAll( '.sig-pad-wrap' ).forEach( function( wrap ) {
		var role       = wrap.dataset.role;
		var canvas     = wrap.querySelector( '.sig-canvas' );
		var img        = wrap.querySelector( '.sig-image' );
		var meta       = wrap.querySelector( '.sig-meta' );
		var controls   = wrap.querySelector( '.sig-pad-controls' );
		var nameInput  = wrap.querySelector( '.sig-name-input' );
		var clearBtn   = wrap.querySelector( '.sig-clear' );
		var saveBtn    = wrap.querySelector( '.sig-save' );
		var resignBtn  = wrap.querySelector( '.sig-resign' );
		if ( ! canvas ) { return; }

		var ctx        = canvas.getContext( '2d' );
		var drawing    = false;
		var hasStrokes = false;
		ctx.lineWidth   = 2;
		ctx.lineCap     = 'round';
		ctx.strokeStyle = '#111';

		function pos( e ) {
			var rect = canvas.getBoundingClientRect();
			var scaleX = canvas.width / rect.width;
			var scaleY = canvas.height / rect.height;
			var point = e.touches ? e.touches[0] : e;
			return { x: ( point.clientX - rect.left ) * scaleX, y: ( point.clientY - rect.top ) * scaleY };
		}
		function start( e ) {
			e.preventDefault();
			drawing = true;
			var p = pos( e );
			ctx.beginPath();
			ctx.moveTo( p.x, p.y );
		}
		function move( e ) {
			if ( ! drawing ) { return; }
			e.preventDefault();
			var p = pos( e );
			ctx.lineTo( p.x, p.y );
			ctx.stroke();
			if ( ! hasStrokes ) {
				hasStrokes = true;
				saveBtn.disabled = false;
			}
		}
		function stop() { drawing = false; }

		canvas.addEventListener( 'pointerdown', start );
		canvas.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', stop );

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function() {
				ctx.clearRect( 0, 0, canvas.width, canvas.height );
				hasStrokes = false;
				saveBtn.disabled = true;
			} );
		}

		if ( resignBtn ) {
			resignBtn.addEventListener( 'click', function() {
				img.style.display = 'none';
				canvas.style.display = 'block';
				controls.style.display = 'block';
				resignBtn.style.display = 'none';
				meta.textContent = '';
				ctx.clearRect( 0, 0, canvas.width, canvas.height );
				hasStrokes = false;
				saveBtn.disabled = true;
			} );
		}

		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function() {
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				var body = new URLSearchParams();
				body.set( 'action', 'wpistic_ffl_4473_save_signature' );
				body.set( 'nonce', nonce );
				body.set( 'transfer_id', transferId );
				body.set( 'role', role );
				body.set( 'signer_name', nameInput.value );
				body.set( 'signature_data', canvas.toDataURL( 'image/png' ) );

				fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function( r ) { return r.json(); } )
					.then( function( json ) {
						if ( ! json.success ) {
							alert( json.data && json.data.message ? json.data.message : 'Could not save signature.' );
							saveBtn.disabled = false;
							saveBtn.textContent = 'Save Signature';
							return;
						}
						img.src = json.data.data_url;
						img.style.display = 'block';
						canvas.style.display = 'none';
						controls.style.display = 'none';
						if ( resignBtn ) { resignBtn.style.display = 'inline'; }
						meta.textContent = ( json.data.signer_name || role ) + ' · signed ' + json.data.signed_at;
						wrap.classList.add( 'is-signed' );
						saveBtn.textContent = 'Save Signature';
					} )
					.catch( function() {
						alert( 'Network error saving signature.' );
						saveBtn.disabled = false;
						saveBtn.textContent = 'Save Signature';
					} );
			} );
		}
	} );
})();
</script>

</body>
</html>
		<?php
	}
}
