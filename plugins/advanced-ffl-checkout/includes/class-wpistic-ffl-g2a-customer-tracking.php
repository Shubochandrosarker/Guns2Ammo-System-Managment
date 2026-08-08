<?php
/**
 * G2A — Public per-transfer status page for customers.
 *
 * A customer who isn't logged in (e.g. clicked a status update email) lands
 * on /track-transfer/{ref}/{sig}/ where {sig} is a short HMAC over the
 * transfer ref. We never expose internal transfer IDs in URLs.
 *
 * The page renders only sanitized, customer-safe fields. PII like the FBI
 * NICS Transaction Number, dealer last-4 license digits, and full audit log
 * are deliberately omitted from the public view.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Customer_Tracking {

	const SLUG = 'track-transfer';

	public function __construct() {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle_request' ] );

		// "Request a different dealer" form on the tracking page.
		add_action( 'admin_post_nopriv_wpistic_ffl_track_request_dealer', [ __CLASS__, 'handle_request_new_dealer' ] );
		add_action( 'admin_post_wpistic_ffl_track_request_dealer',        [ __CLASS__, 'handle_request_new_dealer' ] );
	}

	public function register_rewrites(): void {
		add_rewrite_tag( '%g2a_track_ref%', '([^&/]+)' );
		add_rewrite_tag( '%g2a_track_sig%', '([^&/]+)' );
		add_rewrite_rule(
			'^' . self::SLUG . '/([^/]+)/([^/]+)/?$',
			'index.php?g2a_track_ref=$matches[1]&g2a_track_sig=$matches[2]',
			'top'
		);
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'g2a_track_ref';
		$vars[] = 'g2a_track_sig';
		return $vars;
	}

	public function handle_request(): void {
		$ref = get_query_var( 'g2a_track_ref' );
		$sig = get_query_var( 'g2a_track_sig' );
		if ( empty( $ref ) || empty( $sig ) ) {
			return;
		}

		$ref      = sanitize_text_field( rawurldecode( $ref ) );
		$sig      = sanitize_text_field( rawurldecode( $sig ) );
		$expected = self::sign( $ref );
		if ( ! hash_equals( $expected, $sig ) ) {
			status_header( 403 );
			self::render_error( __( 'This tracking link is invalid or has expired. Please use the latest update email or contact us for help.', 'advanced-ffl-checkout' ) );
			exit;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.transfer_ref, t.status, t.item_description, t.item_make, t.item_model, t.item_caliber,
			        t.shipment_carrier, t.shipment_tracking, t.shipped_date, t.dealer_received_date,
			        t.nics_delay_expires, t.customer_name, t.created_at, t.updated_at,
			        d.business_name AS dealer_name, d.premise_city AS dealer_city,
			        d.premise_state AS dealer_state, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.transfer_ref = %s LIMIT 1',
			$ref
		) );

		if ( ! $row ) {
			status_header( 404 );
			self::render_error( __( 'We can\'t find that transfer reference. It may have been removed or never existed.', 'advanced-ffl-checkout' ) );
			exit;
		}

		// Audit — the customer viewed their public tracking page.
		Analytics::log( 'public_track_viewed', [
			'metadata' => [ 'ref' => $ref ],
		] );

		self::render_page( $row );
		exit;
	}

	/**
	 * Build the URL we put inside customer-facing emails / SMS.
	 */
	public static function url_for( string $ref ): string {
		return home_url( '/' . self::SLUG . '/' . rawurlencode( $ref ) . '/' . rawurlencode( self::sign( $ref ) ) . '/' );
	}

	/**
	 * Lightweight per-string translation for the tracking page. Triggered by
	 * appending ?lang=es to the tracking URL — keeps things simple without
	 * full locale-switching across the whole site for one customer.
	 */
	public static function t( string $en ): string {
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		if ( 'es' !== $lang ) {
			return $en;
		}
		$es = [
			'Secure FFL Transfer Tracking' => 'Seguimiento Seguro de Transferencia FFL',
			'Your transfer status' => 'Estado de su transferencia',
			'Updated %s' => 'Actualizado %s',
			'Item' => 'Artículo',
			'Dealer' => 'Distribuidor',
			'Tracking' => 'Seguimiento',
			'Shipped' => 'Enviado',
			'Arrived' => 'Llegó',
			'3-Day Rule' => 'Regla de 3 días',
			'Window expires %s' => 'La ventana vence %s',
			'Questions about your transfer? Email %s' => '¿Preguntas sobre su transferencia? Envíe correo a %s',
			'Need to switch to a different FFL dealer?' => '¿Necesita cambiar a otro distribuidor FFL?',
			'Submit dealer-change request' => 'Enviar solicitud de cambio',
		];
		return $es[ $en ] ?? $en;
	}

	/**
	 * HMAC-SHA256 truncated to 16 hex chars. The ref already provides ~48 bits
	 * of randomness via uniqid; the sig adds tamper-resistance and binds the
	 * link to the site's token secret so deleting a transfer invalidates URLs.
	 */
	private static function sign( string $ref ): string {
		return substr( hash_hmac( 'sha256', 'track:' . $ref, Token::secret() ), 0, 16 );
	}

	private static function render_page( object $row ): void {
		$theme = Theming::settings();
		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$status_labels = G2A_Activity::status_labels();
		$status_label  = $status_labels[ $row->status ] ?? ucwords( str_replace( '_', ' ', $row->status ) );

		$timeline = [
			'payment_confirmed'  => __( 'Order Placed', 'advanced-ffl-checkout' ),
			'shipped_to_dealer'  => __( 'Shipped to Dealer', 'advanced-ffl-checkout' ),
			'received_by_dealer' => __( 'Arrived at Dealer', 'advanced-ffl-checkout' ),
			'background_check'   => __( 'Background Check', 'advanced-ffl-checkout' ),
			'transferred'        => __( 'Transfer Complete', 'advanced-ffl-checkout' ),
		];
		$current_step = self::current_timeline_step( $row->status );
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $theme['business_name'] ); ?> — <?php esc_html_e( 'Track Your FFL Transfer', 'advanced-ffl-checkout' ); ?></title>
<style>
body{margin:0;padding:0;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.5;}
.wrap{max-width:680px;margin:0 auto;padding:48px 24px;}
.card{background:<?php echo esc_attr( $theme['color_surface'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:16px;padding:32px;margin-bottom:20px;box-shadow:0 12px 48px -12px rgba(0,0,0,.45);}
.brand{text-align:center;margin-bottom:32px;}
.brand a{color:<?php echo esc_attr( $theme['color_primary'] ); ?>;text-decoration:none;font-weight:800;font-size:24px;letter-spacing:-.02em;}
h1{margin:0 0 8px;font-size:22px;}
.ref{display:inline-block;padding:6px 14px;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:999px;color:<?php echo esc_attr( $theme['color_primary'] ); ?>;font-family:ui-monospace,SFMono-Regular,monospace;font-weight:700;font-size:14px;}
.status{display:inline-block;padding:8px 14px;border-radius:999px;background:<?php echo esc_attr( $theme['color_primary'] ); ?>;color:#0F0E12;font-weight:800;font-size:13px;text-transform:uppercase;letter-spacing:.06em;margin-left:8px;}
.meta{color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;font-size:13px;margin-top:6px;}
.timeline{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin:24px 0;}
.t-step{padding:14px 8px;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:10px;text-align:center;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;}
.t-step.done{background:<?php echo esc_attr( $theme['color_primary'] ); ?>;color:#0F0E12;border-color:<?php echo esc_attr( $theme['color_primary'] ); ?>;}
.t-step.current{background:transparent;border-color:<?php echo esc_attr( $theme['color_primary'] ); ?>;color:<?php echo esc_attr( $theme['color_primary'] ); ?>;}
.kv{display:grid;grid-template-columns:120px 1fr;gap:8px 16px;margin:16px 0 0;font-size:14px;}
.kv dt{color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;font-size:11px;text-transform:uppercase;letter-spacing:.08em;}
.kv dd{margin:0;font-weight:600;}
.btn{display:inline-block;padding:12px 24px;background:<?php echo esc_attr( $theme['color_primary'] ); ?>;color:#0F0E12;text-decoration:none;border-radius:10px;font-weight:700;margin-top:16px;}
@media (max-width:540px){.timeline{grid-template-columns:repeat(2,1fr);}.kv{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="wrap">

	<div class="brand">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $theme['business_name'] ); ?></a>
		<div class="meta"><?php echo esc_html( self::t( 'Secure FFL Transfer Tracking' ) ); ?>
			<?php $lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; ?>
			· <a href="<?php echo esc_url( add_query_arg( 'lang', $lang === 'es' ? 'en' : 'es' ) ); ?>" style="color:<?php echo esc_attr( $theme['color_primary'] ); ?>;text-decoration:none;font-size:12px;"><?php echo $lang === 'es' ? 'English' : 'Español'; ?></a>
		</div>
	</div>

	<div class="card">
		<div>
			<span class="ref">#<?php echo esc_html( $row->transfer_ref ); ?></span>
			<span class="status"><?php echo esc_html( $status_label ); ?></span>
		</div>
		<h1 style="margin-top:16px;"><?php esc_html_e( 'Your transfer status', 'advanced-ffl-checkout' ); ?></h1>
		<p class="meta">
			<?php
			printf(
				/* translators: %s = updated-at date */
				esc_html__( 'Updated %s', 'advanced-ffl-checkout' ),
				esc_html( date_i18n( 'F j, Y g:i a', strtotime( $row->updated_at ) ) )
			);
			?>
		</p>

		<div class="timeline">
		<?php $i = 0; foreach ( $timeline as $key => $label ) :
			$class = 't-step';
			if ( $i < $current_step ) { $class .= ' done'; }
			elseif ( $i === $current_step ) { $class .= ' current'; }
			?>
			<div class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></div>
		<?php $i++; endforeach; ?>
		</div>

		<dl class="kv">
			<dt><?php esc_html_e( 'Item', 'advanced-ffl-checkout' ); ?></dt>
			<dd>
				<?php
				$item = trim( ( $row->item_make ?: '' ) . ' ' . ( $row->item_model ?: '' ) ) ?: ( $row->item_description ?: '—' );
				echo esc_html( $item . ( $row->item_caliber ? ' · ' . $row->item_caliber : '' ) );
				?>
			</dd>

			<?php if ( $row->dealer_name ) : ?>
				<dt><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></dt>
				<dd>
					<?php echo esc_html( $row->dealer_name ); ?><br>
					<span class="meta"><?php echo esc_html( trim( ( $row->dealer_city ?? '' ) . ', ' . ( $row->dealer_state ?? '' ), ', ' ) ); ?></span>
					<?php if ( $row->dealer_phone ) : ?>
						<br><a style="color:<?php echo esc_attr( $theme['color_primary'] ); ?>;text-decoration:none;" href="tel:<?php echo esc_attr( $row->dealer_phone ); ?>">📞 <?php echo esc_html( $row->dealer_phone ); ?></a>
					<?php endif; ?>
				</dd>
			<?php endif; ?>

			<?php $tracking = trim( strtoupper( (string) $row->shipment_carrier ) . ' ' . (string) $row->shipment_tracking );
			if ( $tracking ) : ?>
				<dt><?php esc_html_e( 'Tracking', 'advanced-ffl-checkout' ); ?></dt>
				<dd><?php echo esc_html( $tracking ); ?></dd>
			<?php endif; ?>

			<?php if ( $row->shipped_date ) : ?>
				<dt><?php esc_html_e( 'Shipped', 'advanced-ffl-checkout' ); ?></dt>
				<dd><?php echo esc_html( date_i18n( 'M j, Y', strtotime( (string) $row->shipped_date ) ) ); ?></dd>
			<?php endif; ?>

			<?php if ( $row->dealer_received_date ) : ?>
				<dt><?php esc_html_e( 'Arrived', 'advanced-ffl-checkout' ); ?></dt>
				<dd><?php echo esc_html( date_i18n( 'M j, Y', strtotime( (string) $row->dealer_received_date ) ) ); ?></dd>
			<?php endif; ?>

			<?php if ( in_array( $row->status, [ 'delayed', 'nics_delayed' ], true ) && $row->nics_delay_expires ) : ?>
				<dt><?php esc_html_e( '3-Day Rule', 'advanced-ffl-checkout' ); ?></dt>
				<dd>
					<?php
					printf(
						esc_html__( 'Window expires %s', 'advanced-ffl-checkout' ),
						esc_html( $row->nics_delay_expires )
					);
					?>
				</dd>
			<?php endif; ?>
		</dl>
	</div>

	<?php // Show the "request a different dealer" form only when the transfer is
	// still pre-arrival — once the gun is at the dealer, redirection is moot.
	if ( in_array( $row->status, [ 'payment_confirmed', 'dealer_selected', 'shipped_to_dealer', 'shipped' ], true ) ) :
		$req_done = isset( $_GET['req_done'] );
		?>
		<div class="card" style="text-align:left;">
			<h2 style="margin:0 0 8px;font-size:16px;">
				<?php esc_html_e( 'Need to switch to a different FFL dealer?', 'advanced-ffl-checkout' ); ?>
			</h2>
			<p class="meta" style="margin-top:0;">
				<?php esc_html_e( 'If the dealer above can\'t or won\'t accept this shipment, request a change here. Our team will contact you to confirm a new dealer before redirecting the parcel.', 'advanced-ffl-checkout' ); ?>
			</p>
			<?php if ( $req_done ) : ?>
				<p style="margin:12px 0 0;padding:12px 14px;background:rgba(74,222,128,0.12);border:1px solid <?php echo esc_attr( $theme['color_success'] ); ?>;border-radius:8px;color:<?php echo esc_attr( $theme['color_success'] ); ?>;">
					<?php esc_html_e( '✓ Request received — we\'ll be in touch shortly.', 'advanced-ffl-checkout' ); ?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;display:grid;gap:10px;">
					<?php wp_nonce_field( 'wpistic_ffl_track_request_dealer', 'wpistic_ffl_track_nonce' ); ?>
					<input type="hidden" name="action" value="wpistic_ffl_track_request_dealer">
					<input type="hidden" name="ref" value="<?php echo esc_attr( $row->transfer_ref ); ?>">
					<input type="hidden" name="sig" value="<?php echo esc_attr( $_GET['sig'] ?? '' ); ?>">
					<input type="text" name="contact_email" placeholder="<?php esc_attr_e( 'Your email (so we can reach you)', 'advanced-ffl-checkout' ); ?>" required style="padding:10px 12px;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:8px;font-family:inherit;">
					<textarea name="reason" rows="3" placeholder="<?php esc_attr_e( 'Tell us briefly why (the dealer refused, moved, closed, etc.)', 'advanced-ffl-checkout' ); ?>" style="padding:10px 12px;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:8px;font-family:inherit;resize:vertical;"></textarea>
					<input type="text" name="preferred_zip" placeholder="<?php esc_attr_e( 'New ZIP code (optional)', 'advanced-ffl-checkout' ); ?>" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" style="padding:10px 12px;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:8px;font-family:inherit;">
					<label style="position:absolute;left:-99999px;" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></label>
					<button type="submit" class="btn" style="border:none;cursor:pointer;font-family:inherit;"><?php esc_html_e( 'Submit dealer-change request', 'advanced-ffl-checkout' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="card" style="text-align:center;">
		<p class="meta" style="margin-top:0;">
			<?php
			printf(
				/* translators: %s = support email */
				esc_html__( 'Questions about your transfer? Email %s', 'advanced-ffl-checkout' ),
				'<a style="color:' . esc_attr( $theme['color_primary'] ) . ';text-decoration:none;" href="mailto:' . esc_attr( $theme['support_email'] ) . '">' . esc_html( $theme['support_email'] ) . '</a>'
			);
			?>
		</p>
	</div>

</div>
</body>
</html>
		<?php
	}

	/**
	 * Handle a "request a different dealer" form submission. Verifies the
	 * tracking signature (so only the email recipient can submit), records
	 * an events-table row, emails the admin, and redirects with ?req_done=1.
	 */
	public static function handle_request_new_dealer(): void {
		if ( ! isset( $_POST['wpistic_ffl_track_nonce'] )
			|| ! wp_verify_nonce( (string) wp_unslash( $_POST['wpistic_ffl_track_nonce'] ), 'wpistic_ffl_track_request_dealer' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'advanced-ffl-checkout' ), 403 );
		}
		if ( ! empty( $_POST['website'] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$ref = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
		$sig = sanitize_text_field( wp_unslash( $_POST['sig'] ?? '' ) );
		if ( '' === $ref || ! hash_equals( self::sign( $ref ), $sig ) ) {
			wp_die( esc_html__( 'This link is invalid or has expired.', 'advanced-ffl-checkout' ), 403 );
		}

		$email  = sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$zip    = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['preferred_zip'] ?? '' ) );

		global $wpdb;
		$transfer_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . DB::table( 'transfers' ) . ' WHERE transfer_ref = %s LIMIT 1',
			$ref
		) );
		if ( $transfer_id ) {
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => $transfer_id,
				'event_type'  => 'dealer_change_requested',
				'notes'       => sprintf( "Customer requested a different dealer.\nContact: %s\nNew ZIP: %s\nReason: %s", $email, $zip ?: '—', $reason ),
				'actor'       => 'customer-tracking',
				'actor_ip'    => Token::client_ip(),
			] );
			Analytics::log( 'dealer_change_requested', [
				'transfer_id' => $transfer_id,
				'metadata'    => [ 'contact_email' => $email, 'preferred_zip' => $zip ],
			] );

			$admin_email = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );
			wp_mail(
				$admin_email,
				sprintf( '[G2A] Dealer change requested — Transfer #%s', $ref ),
				'<p>The customer for transfer <strong>#' . esc_html( $ref ) . '</strong> has requested a different dealer.</p>'
				. '<p><strong>Contact:</strong> ' . esc_html( $email ) . '<br>'
				. '<strong>Preferred ZIP:</strong> ' . esc_html( $zip ?: '—' ) . '</p>'
				. '<p><strong>Reason:</strong><br>' . nl2br( esc_html( $reason ) ) . '</p>'
				. '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . $transfer_id ) ) . '">Open transfer</a></p>',
				[ 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $email ]
			);
		}

		wp_safe_redirect( add_query_arg( 'req_done', '1', self::url_for( $ref ) ) );
		exit;
	}

	private static function render_error( string $message ): void {
		$theme = Theming::settings();
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $theme['business_name'] ); ?> — <?php esc_html_e( 'Transfer Not Found', 'advanced-ffl-checkout' ); ?></title>
<style>body{margin:0;background:<?php echo esc_attr( $theme['color_bg'] ); ?>;color:<?php echo esc_attr( $theme['color_text'] ); ?>;font-family:system-ui,sans-serif;}.wrap{max-width:540px;margin:80px auto;padding:32px;background:<?php echo esc_attr( $theme['color_surface'] ); ?>;border:1px solid <?php echo esc_attr( $theme['color_border'] ); ?>;border-radius:12px;text-align:center;}</style>
</head>
<body><div class="wrap"><h1><?php echo esc_html( $theme['business_name'] ); ?></h1><p><?php echo esc_html( $message ); ?></p></div></body></html>
		<?php
	}

	/**
	 * Map an internal status to a timeline step index.
	 * Out-of-band statuses (cancelled / denied) collapse to step 0 so the
	 * timeline visually pauses — the status badge already tells the story.
	 */
	private static function current_timeline_step( string $status ): int {
		$map = [
			'payment_confirmed'  => 0,
			'dealer_selected'    => 0,
			'shipped_to_dealer'  => 1,
			'shipped'            => 1,
			'received_by_dealer' => 2,
			'dealer_received'    => 2,
			'background_check'   => 3,
			'nics_pending'       => 3,
			'delayed'            => 3,
			'nics_delayed'       => 3,
			'approved'           => 3,
			'transferred'        => 4,
		];
		return $map[ $status ] ?? 0;
	}
}
