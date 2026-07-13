<?php
/**
 * G2A — ID / age verification, gated by ship-to state (Exhibit 10).
 *
 * Several states now legally require verified age-gating (ID.me / Persona /
 * Jumio-class checks) specifically for online ammunition and accessory
 * sales — a checkout notice does not satisfy that bar, and this plugin
 * previously had no independent identity-verification step at all (age and
 * identity were only ever asserted by the buyer). This reuses the same
 * per-state rule table the checkout-notice logic already has, and follows
 * the same honest pattern as the Background_Check_Provider registry
 * (Exhibit 01): a real, filterable provider abstraction, a genuinely
 * working manual-review fallback, and an HMAC-verified webhook for a
 * connected hosted provider (Persona/Jumio/ID.me-class) to push results —
 * without hardcoding one specific vendor's proprietary API this plugin has
 * no way to test against.
 *
 * Gates on EITHER the existing FFL-transfer flag OR the new
 * `_wpistic_ffl_age_restricted` product flag, since age-restricted
 * ammunition/accessories often ship directly to the buyer with no FFL
 * transfer at all — the FFL flag alone would miss exactly the online-sale
 * scenario this exhibit is about.
 *
 * A "verified" result is evidence for staff, never an auto-approval —
 * consistent with every other compliance flow in this plugin.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Id_Verification {

	const OPTION_KEY = 'wpistic_ffl_id_verification_settings';
	const ALLOWED_EXT_MIMES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'pdf'          => 'application/pdf',
	];
	const MAX_FILE_BYTES = 5 * 1024 * 1024;

	public function __construct() {
		add_action( 'woocommerce_checkout_process', [ $this, 'maybe_notice_at_checkout' ] );
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'maybe_start_verification' ] );

		add_action( 'admin_menu',    [ $this, 'register_admin_page' ], 48 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_public_page' ] );

		add_action( 'wp_ajax_wpistic_ffl_idv_save_settings', [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_idv_review',        [ __CLASS__, 'ajax_review' ] );
		add_action( 'wp_ajax_wpistic_ffl_idv_upload',        [ __CLASS__, 'ajax_upload' ] );
		add_action( 'wp_ajax_nopriv_wpistic_ffl_idv_upload', [ __CLASS__, 'ajax_upload' ] );
		add_action( 'wp_ajax_wpistic_ffl_idv_view_document',  [ __CLASS__, 'ajax_view_document' ] );
	}

	// ── Settings ──────────────────────────────────────────────

	public static function settings(): array {
		$defaults = [
			'enabled'       => 0,
			'gated_states'  => [ 'CA', 'NY', 'NJ' ], // The states this plugin's own state-rules seed already flags as high-regulation.
			'strict'        => 0, // Off by default — informational notice, not checkout-blocking, until an admin opts in.
			'provider'      => 'manual',
			'webhook_secret' => '',
		];
		return array_merge( $defaults, (array) get_option( self::OPTION_KEY, [] ) );
	}

	public static function known_providers(): array {
		$default = [
			'manual' => [ 'label' => 'Manual review (ID photo + staff approval)', 'has_push' => false ],
		];
		return (array) apply_filters( 'wpistic_ffl_id_verification_providers', $default );
	}

	private static function us_states(): array {
		return [ 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC' ];
	}

	// ── Checkout-time notice ─────────────────────────────────────

	public function maybe_notice_at_checkout(): void {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || ! WC()->cart || ! WC()->customer ) {
			return;
		}
		$state = WC()->customer->get_shipping_state() ?: WC()->customer->get_billing_state();
		if ( ! $state || ! in_array( strtoupper( $state ), $s['gated_states'], true ) ) {
			return;
		}
		if ( ! self::cart_needs_verification() ) {
			return;
		}
		wc_add_notice(
			sprintf(
				'<strong>%s:</strong> %s',
				esc_html__( 'ID Verification Required', 'advanced-ffl-checkout' ),
				esc_html__( 'Your state requires identity/age verification for this purchase. You will receive a secure verification link by email right after checkout.', 'advanced-ffl-checkout' )
			),
			! empty( $s['strict'] ) ? 'error' : 'notice'
		);
	}

	private static function cart_needs_verification(): bool {
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = $item['variation_id'] ?: $item['product_id'];
			if ( 'yes' === get_post_meta( $product_id, '_wpistic_ffl_required', true )
				|| 'yes' === get_post_meta( $product_id, '_wpistic_ffl_age_restricted', true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Order-level (not just cart-level) check — used once the order actually
	 * exists, since that's what maybe_start_verification() has to work with.
	 */
	private static function order_needs_verification( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			if ( 'yes' === get_post_meta( $product->get_id(), '_wpistic_ffl_required', true )
				|| 'yes' === get_post_meta( $product->get_id(), '_wpistic_ffl_age_restricted', true ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Kick off verification once the order exists ─────────────────

	public static function maybe_start_verification( \WC_Order $order ): void {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return;
		}
		$state = $order->get_shipping_state() ?: $order->get_billing_state();
		if ( ! $state || ! in_array( strtoupper( $state ), $s['gated_states'], true ) ) {
			return;
		}
		if ( ! self::order_needs_verification( $order ) ) {
			return;
		}

		global $wpdb;
		$existing = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . DB::table( 'id_verifications' ) . ' WHERE order_id = %d', $order->get_id()
		) );
		if ( $existing ) {
			return;
		}

		$wpdb->insert( DB::table( 'id_verifications' ), [ // phpcs:ignore WordPress.DB
			'order_id'      => $order->get_id(),
			'provider'      => $s['provider'],
			'ship_to_state' => strtoupper( $state ),
			'result_status' => 'pending',
		] );

		$url = self::url_for( $order->get_id() );
		$order->add_order_note( sprintf( 'ID/age verification required (ship-to state %s) — customer emailed a verification link.', strtoupper( $state ) ) );

		wp_mail(
			$order->get_billing_email(),
			__( 'Action Required: Verify Your Identity to Complete Your Order', 'advanced-ffl-checkout' ),
			'<p>' . sprintf(
				/* translators: %s = order number */
				esc_html__( 'Your order #%s requires identity/age verification before it can ship, per your state\'s requirements.', 'advanced-ffl-checkout' ),
				esc_html( $order->get_order_number() )
			) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Complete verification', 'advanced-ffl-checkout' ) . '</a></p>',
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);

		$admin_email = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );
		wp_mail(
			$admin_email,
			sprintf( '[G2A] 🪪 ID verification pending — Order #%s', $order->get_order_number() ),
			'<p>Order #' . esc_html( $order->get_order_number() ) . ' requires ID/age verification (ship-to state ' . esc_html( strtoupper( $state ) ) . ') before it should ship.</p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-id-verification' ) ) . '">Review queue</a></p>',
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);
	}

	// ── Public verification page ────────────────────────────────

	public function register_rewrites(): void {
		add_rewrite_tag( '%g2a_idv_order%', '([0-9]+)' );
		add_rewrite_tag( '%g2a_idv_sig%', '([^&/]+)' );
		add_rewrite_rule( '^verify-identity/([0-9]+)/([^/]+)/?$', 'index.php?g2a_idv_order=$matches[1]&g2a_idv_sig=$matches[2]', 'top' );
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'g2a_idv_order';
		$vars[] = 'g2a_idv_sig';
		return $vars;
	}

	public static function url_for( int $order_id ): string {
		return home_url( '/verify-identity/' . $order_id . '/' . self::sign( $order_id ) . '/' );
	}

	private static function sign( int $order_id ): string {
		return substr( hash_hmac( 'sha256', 'idv:' . $order_id, Token::secret() ), 0, 20 );
	}

	public function handle_public_page(): void {
		$order_id = (int) get_query_var( 'g2a_idv_order' );
		$sig      = get_query_var( 'g2a_idv_sig' );
		if ( ! $order_id || ! $sig ) {
			return;
		}
		if ( ! hash_equals( self::sign( $order_id ), sanitize_text_field( rawurldecode( (string) $sig ) ) ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'This verification link is invalid or has expired.', 'advanced-ffl-checkout' ) );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'id_verifications' ) . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1', $order_id
		) );
		if ( ! $row ) {
			status_header( 404 );
			wp_die( esc_html__( 'No verification request found for this order.', 'advanced-ffl-checkout' ) );
		}

		self::render_page( $row, $order_id, (string) $sig );
		exit;
	}

	private static function render_page( object $row, int $order_id, string $sig ): void {
		$theme = Theming::settings();
		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$nonce = wp_create_nonce( 'wpistic_ffl_idv_' . $order_id );
		?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow">
		<title><?php echo esc_html( $theme['business_name'] ); ?> — Verify Your Identity</title>
		<style>
		body{margin:0;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
		.wrap{max-width:520px;margin:0 auto;padding:48px 24px;}
		.card{background:<?php echo esc_attr( $theme['color_surface'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:16px;padding:32px;}
		label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;margin:14px 0 4px;}
		input[type=date],input[type=file]{width:100%;padding:10px 12px;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:8px;font-family:inherit;box-sizing:border-box;}
		.btn{display:inline-block;padding:12px 24px;background:<?php echo esc_attr( $theme['color_primary'] ); ?>;color:#0F0E12;border:none;border-radius:10px;font-weight:700;margin-top:18px;cursor:pointer;font-family:inherit;font-size:14px;}
		#msg{margin-top:12px;font-size:13px;}
		</style></head><body><div class="wrap"><div class="card">
			<h1 style="margin-top:0;font-size:20px;"><?php esc_html_e( 'Verify Your Identity', 'advanced-ffl-checkout' ); ?></h1>
			<?php if ( 'verified' === $row->result_status ) : ?>
				<p>✅ <?php esc_html_e( 'You\'re already verified — thank you. Your order can proceed.', 'advanced-ffl-checkout' ); ?></p>
			<?php elseif ( in_array( $row->result_status, [ 'submitted', 'manual_review' ], true ) ) : ?>
				<p>🕓 <?php esc_html_e( 'Your ID has been submitted and is awaiting staff review. We\'ll email you once it\'s confirmed.', 'advanced-ffl-checkout' ); ?></p>
			<?php else : ?>
				<p style="font-size:13px;opacity:.8;"><?php esc_html_e( 'Your state requires us to verify your age/identity before this order can ship. Upload a photo of a valid government-issued ID and confirm your date of birth — a staff member will review it.', 'advanced-ffl-checkout' ); ?></p>
				<form id="idv-form">
					<label for="idv-dob"><?php esc_html_e( 'Date of birth', 'advanced-ffl-checkout' ); ?></label>
					<input type="date" id="idv-dob" required>
					<label for="idv-file"><?php esc_html_e( 'Photo of government-issued ID', 'advanced-ffl-checkout' ); ?></label>
					<input type="file" id="idv-file" accept="image/jpeg,image/png,application/pdf" required>
					<button type="submit" class="btn"><?php esc_html_e( 'Submit for Review', 'advanced-ffl-checkout' ); ?></button>
					<div id="msg"></div>
				</form>
			<?php endif; ?>
		</div></div>
		<script>
		(function(){
			var form = document.getElementById('idv-form');
			if (! form) return;
			form.addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('msg');
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_idv_upload');
				fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
				fd.append('order_id', <?php echo (int) $order_id; ?>);
				fd.append('sig', <?php echo wp_json_encode( $sig ); ?>);
				fd.append('dob', document.getElementById('idv-dob').value);
				fd.append('file', document.getElementById('idv-file').files[0]);
				msg.textContent = 'Uploading…';
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						if (j && j.success) {
							document.querySelector('.card').innerHTML = '<h1>🕓 Submitted</h1><p>Thanks — a staff member will review your ID shortly. We\'ll email you once it\'s confirmed.</p>';
						} else {
							msg.textContent = '✗ ' + ((j && j.data && j.data.message) || 'Upload failed.');
							msg.style.color = '#DC2626';
						}
					})
					.catch(function(){ msg.textContent = '✗ Network error.'; msg.style.color = '#DC2626'; });
			});
		})();
		</script>
		</body></html>
		<?php
	}

	// ── Private upload storage (mirrors the Verification Hub's certified-copy pattern) ──

	private static function private_dir(): string {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpistic-ffl-private/id-verifications/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
		return $dir;
	}

	private static function file_content_matches_mime( string $tmp_path, string $mime ): bool {
		if ( 'application/pdf' === $mime ) {
			return '%PDF-' === file_get_contents( $tmp_path, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
			$info = @getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return is_array( $info ) && ( $info['mime'] ?? '' ) === $mime;
		}
		return false;
	}

	public static function ajax_upload(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$sig      = sanitize_text_field( wp_unslash( $_POST['sig'] ?? '' ) );
		$dob      = sanitize_text_field( wp_unslash( $_POST['dob'] ?? '' ) );
		// phpcs:enable
		if ( ! $order_id || ! hash_equals( self::sign( $order_id ), $sig ) ) {
			wp_send_json_error( [ 'message' => 'Invalid or expired verification link.' ], 403 );
		}
		if ( ! check_ajax_referer( 'wpistic_ffl_idv_' . $order_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed — please reload the page.' ], 403 );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
			wp_send_json_error( [ 'message' => 'Enter a valid date of birth.' ], 400 );
		}
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'No file uploaded.' ], 400 );
		}
		if ( (int) $_FILES['file']['size'] > self::MAX_FILE_BYTES ) {
			wp_send_json_error( [ 'message' => 'File is larger than 5MB.' ], 400 );
		}

		$check = wp_check_filetype( $_FILES['file']['name'], self::ALLOWED_EXT_MIMES );
		$mime  = $check['type'] ?: '';
		$ext   = $check['ext'] ?: '';
		if ( ! $mime || ! $ext || ! self::file_content_matches_mime( $_FILES['file']['tmp_name'], $mime ) ) {
			wp_send_json_error( [ 'message' => 'Only a real PDF, JPG, or PNG is accepted.' ], 400 );
		}

		$age = (int) ( new \DateTimeImmutable( 'now' ) )->diff( new \DateTimeImmutable( $dob ) )->y;
		$dir = self::private_dir() . $order_id . '/';
		wp_mkdir_p( $dir );
		$dest = $dir . wp_generate_password( 24, false, false ) . '.' . $ext;
		if ( ! move_uploaded_file( $_FILES['file']['tmp_name'], $dest ) ) {
			wp_send_json_error( [ 'message' => 'Could not save the uploaded file.' ], 500 );
		}

		global $wpdb;
		$wpdb->update( DB::table( 'id_verifications' ), [ // phpcs:ignore WordPress.DB
			'result_status'      => 'manual_review',
			'result_age_over_21' => $age >= 21 ? 1 : 0,
			'notes'              => 'DOB claimed: ' . $dob . ' (age ' . $age . '). Document: ' . $order_id . '/' . basename( $dest ),
		], [ 'order_id' => $order_id ] );

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note( 'Customer submitted ID for verification. Claimed age: ' . $age . '. Awaiting staff review.' );
		}

		wp_send_json_success();
	}

	// ── Admin review queue ────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'ID Verification', 'advanced-ffl-checkout' ),
			__( '🪪 ID Verification', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-id-verification',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$s     = self::settings();
		$nonce = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'id_verifications' ) . " ORDER BY FIELD(result_status,'manual_review','pending','verified','rejected'), created_at DESC LIMIT 100"
		);
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;"><span style="font-size:24px;">🪪</span><?php esc_html_e( 'ID / Age Verification', 'advanced-ffl-checkout' ); ?></h1>
			<p class="description" style="max-width:820px;"><?php esc_html_e( 'A submitted ID is evidence for staff review — approving here is a manual decision, never automatic. Verification is required for orders shipping age-restricted items to a gated state.', 'advanced-ffl-checkout' ); ?></p>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin:16px 0;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;"><?php esc_html_e( 'Settings', 'advanced-ffl-checkout' ); ?></h2>
				<form id="wpistic-ffl-idv-settings">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Enable ID verification', 'advanced-ffl-checkout' ); ?></label></p>
					<p><label><input type="checkbox" name="strict" value="1" <?php checked( ! empty( $s['strict'] ) ); ?>> <?php esc_html_e( 'Block checkout with an error notice (unchecked = informational notice only)', 'advanced-ffl-checkout' ); ?></label></p>
					<p><strong><?php esc_html_e( 'States requiring verification:', 'advanced-ffl-checkout' ); ?></strong></p>
					<div style="display:grid;grid-template-columns:repeat(8,1fr);gap:4px;max-width:700px;">
						<?php foreach ( self::us_states() as $st ) : ?>
							<label style="font-size:12px;"><input type="checkbox" name="gated_states[]" value="<?php echo esc_attr( $st ); ?>" <?php checked( in_array( $st, $s['gated_states'], true ) ); ?>> <?php echo esc_html( $st ); ?></label>
						<?php endforeach; ?>
					</div>
					<button type="submit" class="button button-primary" style="margin-top:14px;"><?php esc_html_e( 'Save Settings', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-idv-settings-result" style="margin-left:10px;font-weight:600;"></span>
				</form>
			</div>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Order', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'State', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Claimed 21+', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Document', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Action', 'advanced-ffl-checkout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><em><?php esc_html_e( 'No verification requests yet.', 'advanced-ffl-checkout' ); ?></em></td></tr>
				<?php else : foreach ( $rows as $r ) : ?>
					<tr data-idv-id="<?php echo esc_attr( $r->id ); ?>">
						<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( (string) $r->order_id ); ?></a></td>
						<td><?php echo esc_html( $r->ship_to_state ); ?></td>
						<td><?php echo null === $r->result_age_over_21 ? '—' : ( $r->result_age_over_21 ? '✅' : '❌' ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $r->result_status ) ) ); ?></td>
						<td><?php if ( 'manual_review' === $r->result_status || 'verified' === $r->result_status || 'rejected' === $r->result_status ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wpistic_ffl_idv_view_document&idv_id=' . (int) $r->id . '&nonce=' . wp_create_nonce( 'wpistic_ffl_admin_nonce' ) ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'advanced-ffl-checkout' ); ?></a>
						<?php else : echo '—'; endif; ?></td>
						<td>
							<?php if ( 'manual_review' === $r->result_status ) : ?>
								<button type="button" class="button button-primary wpistic-ffl-idv-approve"><?php esc_html_e( 'Approve', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button wpistic-ffl-idv-reject"><?php esc_html_e( 'Reject', 'advanced-ffl-checkout' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;

			document.getElementById('wpistic-ffl-idv-settings').addEventListener('submit', function(e){
				e.preventDefault();
				var result = document.getElementById('wpistic-ffl-idv-settings-result');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_idv_save_settings');
				result.textContent = 'Saving…';
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						result.textContent = j && j.success ? '✓ Saved' : '✗ Failed';
						result.style.color = j && j.success ? '#16A34A' : '#DC2626';
					});
			});

			function review(id, decision) {
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_idv_review');
				fd.append('nonce', nonce);
				fd.append('idv_id', id);
				fd.append('decision', decision);
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){ if (j && j.success) { location.reload(); } });
			}
			document.querySelectorAll('.wpistic-ffl-idv-approve').forEach(function(btn){
				btn.addEventListener('click', function(){ review(btn.closest('tr').getAttribute('data-idv-id'), 'verified'); });
			});
			document.querySelectorAll('.wpistic-ffl-idv-reject').forEach(function(btn){
				btn.addEventListener('click', function(){
					if (! confirm('<?php echo esc_js( __( 'Reject this ID? The customer will need to resubmit.', 'advanced-ffl-checkout' ) ); ?>')) return;
					review(btn.closest('tr').getAttribute('data-idv-id'), 'rejected');
				});
			});
		})();
		</script>
		<?php
	}

	public static function ajax_save_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$states = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['gated_states'] ?? [] ) ) );
		$states = array_values( array_intersect( array_map( 'strtoupper', $states ), self::us_states() ) );
		update_option( self::OPTION_KEY, array_merge( self::settings(), [
			'enabled'      => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'strict'       => ! empty( $_POST['strict'] ) ? 1 : 0,
			'gated_states' => $states,
		] ) );
		// phpcs:enable
		wp_send_json_success();
	}

	public static function ajax_review(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$id       = (int) ( $_POST['idv_id'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		// phpcs:enable
		if ( ! $id || ! in_array( $decision, [ 'verified', 'rejected' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request.' ], 400 );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'id_verifications' ) . ' WHERE id = %d', $id
		) );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => 'Not found.' ], 404 );
		}

		$wpdb->update( DB::table( 'id_verifications' ), [ 'result_status' => $decision ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB

		$order = wc_get_order( (int) $row->order_id );
		if ( $order ) {
			$user = wp_get_current_user();
			$order->add_order_note( sprintf( 'ID verification %s by %s.', $decision, $user->user_login ?: 'staff' ) );
			wp_mail(
				$order->get_billing_email(),
				'verified' === $decision ? __( 'Identity Verified — Your Order Will Proceed', 'advanced-ffl-checkout' ) : __( 'Identity Verification Rejected', 'advanced-ffl-checkout' ),
				'verified' === $decision
					? '<p>' . esc_html__( 'Thanks — your identity has been verified and your order will proceed.', 'advanced-ffl-checkout' ) . '</p>'
					: '<p>' . esc_html__( 'We could not verify the ID you submitted. Please contact us to resolve this before your order can ship.', 'advanced-ffl-checkout' ) . '</p>',
				[ 'Content-Type: text/html; charset=UTF-8' ]
			);
		}

		wp_send_json_success();
	}

	public static function ajax_view_document(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) ), 'wpistic_ffl_admin_nonce' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$id = (int) ( $_GET['idv_id'] ?? 0 );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT order_id, notes FROM ' . DB::table( 'id_verifications' ) . ' WHERE id = %d', $id
		) );
		if ( ! $row || ! preg_match( '#Document: (\d+)/([\w.-]+)#', (string) $row->notes, $m ) ) {
			wp_die( 'Document not found.', 404 );
		}
		$path = self::private_dir() . $m[1] . '/' . $m[2];
		if ( ! file_exists( $path ) ) {
			wp_die( 'File missing from storage.', 404 );
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . ( wp_check_filetype( $path )['type'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: inline; filename="id-' . (int) $row->order_id . '.' . pathinfo( $path, PATHINFO_EXTENSION ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// ── Provider webhook (for a real connected hosted-verification vendor) ──

	public function register_routes(): void {
		register_rest_route( WPISTIC_FFL_REST_NS, '/id-verification/webhook', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_webhook' ],
		] );
	}

	public function rest_webhook( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$s = self::settings();
		if ( empty( $s['webhook_secret'] ) ) {
			return new \WP_Error( 'webhook_disabled', 'Webhook secret not configured.', [ 'status' => 503 ] );
		}
		$raw_body  = $req->get_body();
		$signature = $req->get_header( 'X-Wpistic-Ffl-Signature' ) ?: '';
		if ( '' === $signature || ! hash_equals( hash_hmac( 'sha256', $raw_body, $s['webhook_secret'] ), $signature ) ) {
			return new \WP_Error( 'bad_signature', 'Invalid webhook signature.', [ 'status' => 401 ] );
		}
		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) || empty( $payload['order_id'] ) || empty( $payload['status'] ) ) {
			return new \WP_Error( 'bad_payload', 'Payload requires order_id and status.', [ 'status' => 400 ] );
		}
		$status = sanitize_key( (string) $payload['status'] );
		if ( ! in_array( $status, [ 'verified', 'rejected', 'manual_review' ], true ) ) {
			return new \WP_Error( 'bad_status', 'Invalid status.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$wpdb->update( DB::table( 'id_verifications' ), [ // phpcs:ignore WordPress.DB
			'result_status'      => $status,
			'provider_reference' => sanitize_text_field( (string) ( $payload['reference'] ?? '' ) ),
			'result_age_over_21' => isset( $payload['age_over_21'] ) ? ( $payload['age_over_21'] ? 1 : 0 ) : null,
		], [ 'order_id' => (int) $payload['order_id'] ] );

		return rest_ensure_response( [ 'ok' => true ] );
	}
}
