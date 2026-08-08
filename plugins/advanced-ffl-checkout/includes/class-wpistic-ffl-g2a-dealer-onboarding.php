<?php
/**
 * G2A — Public dealer onboarding form.
 *
 * A self-serve form FFLs can fill in to request being added to the
 * "Recommended" list, set their own portal email, and propose a custom
 * transfer fee. Submissions land in the events table for admin review
 * (no automatic activation — every request goes through staff approval).
 *
 * Rendered via shortcode [g2a_ffl_dealer_onboard] so it can be dropped on
 * any page. The form is rate-limited per-IP and honeypot-protected.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Dealer_Onboarding {

	const SHORTCODE = 'g2a_ffl_dealer_onboard';

	public function __construct() {
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
		add_action( 'admin_post_nopriv_wpistic_ffl_dealer_onboard', [ __CLASS__, 'handle_submission' ] );
		add_action( 'admin_post_wpistic_ffl_dealer_onboard',        [ __CLASS__, 'handle_submission' ] );
	}

	public function render_shortcode( $atts = [] ): string {
		$theme  = Theming::settings();
		$accent = $theme['color_primary'];
		$bg     = $theme['color_bg'];
		$text   = $theme['color_text'];
		$border = $theme['color_border'];

		ob_start();
		$done = isset( $_GET['onboard_done'] );
		?>
		<div class="g2a-ffl-onboard" style="max-width:640px;margin:24px auto;padding:24px;background:<?php echo esc_attr( $theme['color_surface'] ); ?>;color:<?php echo esc_attr( $text ); ?>;border:1px solid <?php echo esc_attr( $border ); ?>;border-radius:12px;font-family:system-ui,sans-serif;">
			<h2 style="margin:0 0 8px;color:<?php echo esc_attr( $accent ); ?>;font-size:22px;">🏪 <?php esc_html_e( 'Become a Featured FFL Dealer', 'advanced-ffl-checkout' ); ?></h2>
			<p style="margin:0 0 18px;color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;font-size:14px;">
				<?php esc_html_e( 'Submit your FFL details and we\'ll review your shop for inclusion in our recommended-dealer list. Active FFLs get top placement at checkout, an inline portal-confirmation flow, and a configurable transfer fee.', 'advanced-ffl-checkout' ); ?>
			</p>

			<?php if ( $done ) : ?>
				<div style="padding:14px 16px;background:rgba(74,222,128,0.12);border:1px solid <?php echo esc_attr( $theme['color_success'] ); ?>;border-radius:8px;color:<?php echo esc_attr( $theme['color_success'] ); ?>;">
					✓ <?php esc_html_e( 'Thanks — we received your submission. Our team will review it within 1–2 business days and email you the next steps.', 'advanced-ffl-checkout' ); ?>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid;gap:10px;">
					<?php wp_nonce_field( 'wpistic_ffl_dealer_onboard', 'wpistic_ffl_onboard_nonce' ); ?>
					<input type="hidden" name="action" value="wpistic_ffl_dealer_onboard">
					<label style="position:absolute;left:-99999px;" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></label>

					<?php
					$fields = [
						'business_name'  => __( 'Business / FFL name', 'advanced-ffl-checkout' ),
						'license_number' => __( 'FFL license number (3-2-XXX-XX-XX-XXXXX)', 'advanced-ffl-checkout' ),
						'contact_name'   => __( 'Your name', 'advanced-ffl-checkout' ),
						'contact_email'  => __( 'Best email for transfer notifications', 'advanced-ffl-checkout' ),
						'phone'          => __( 'Phone', 'advanced-ffl-checkout' ),
						'premise_zip'    => __( 'Premise ZIP code', 'advanced-ffl-checkout' ),
						'transfer_fee'   => __( 'Suggested transfer fee (USD)', 'advanced-ffl-checkout' ),
					];
					foreach ( $fields as $name => $label ) :
						$is_email = 'contact_email' === $name;
						$is_num   = in_array( $name, [ 'transfer_fee', 'premise_zip' ], true );
						$type = $is_email ? 'email' : ( $is_num ? ( 'transfer_fee' === $name ? 'number' : 'text' ) : 'text' );
						$extra = '';
						if ( 'premise_zip' === $name ) $extra = 'inputmode="numeric" maxlength="5" pattern="[0-9]{5}"';
						if ( 'transfer_fee' === $name ) $extra = 'step="0.01" min="0"';
						?>
						<label style="display:block;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;"><?php echo esc_html( $label ); ?></label>
						<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" required <?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput ?> style="padding:10px 12px;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $text ); ?>;border:1px solid <?php echo esc_attr( $border ); ?>;border-radius:8px;font-family:inherit;">
					<?php endforeach; ?>

					<label style="display:block;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:<?php echo esc_attr( $theme['color_text_muted'] ); ?>;"><?php esc_html_e( 'Anything else we should know?', 'advanced-ffl-checkout' ); ?></label>
					<textarea name="notes" rows="3" style="padding:10px 12px;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $text ); ?>;border:1px solid <?php echo esc_attr( $border ); ?>;border-radius:8px;font-family:inherit;resize:vertical;"></textarea>

					<button type="submit" style="margin-top:8px;padding:12px 24px;background:<?php echo esc_attr( $accent ); ?>;color:#0F0E12;font-weight:800;border:none;border-radius:8px;cursor:pointer;font-family:inherit;font-size:14px;letter-spacing:.02em;">
						<?php esc_html_e( 'Submit application', 'advanced-ffl-checkout' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function handle_submission(): void {
		if ( ! isset( $_POST['wpistic_ffl_onboard_nonce'] )
			|| ! wp_verify_nonce( (string) wp_unslash( $_POST['wpistic_ffl_onboard_nonce'] ), 'wpistic_ffl_dealer_onboard' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'advanced-ffl-checkout' ), 403 );
		}
		if ( ! empty( $_POST['website'] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		// Per-IP rate limit (3/hour) using a transient.
		$ip  = Token::client_ip();
		$key = 'wpistic_ffl_onboard_rl_' . md5( $ip );
		if ( (int) get_transient( $key ) >= 3 ) {
			wp_die( esc_html__( 'Too many submissions from this IP — try again in an hour.', 'advanced-ffl-checkout' ), 429 );
		}
		set_transient( $key, ( (int) get_transient( $key ) ) + 1, HOUR_IN_SECONDS );

		// Collect + sanitize.
		$f = [
			'business_name'  => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
			'license_number' => sanitize_text_field( wp_unslash( $_POST['license_number'] ?? '' ) ),
			'contact_name'   => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
			'contact_email'  => sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ),
			'phone'          => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'premise_zip'    => preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['premise_zip'] ?? '' ) ),
			'transfer_fee'   => (float) ( $_POST['transfer_fee'] ?? 0 ),
			'notes'          => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
		];
		if ( ! $f['business_name'] || ! $f['contact_email'] ) {
			wp_die( esc_html__( 'Missing required fields.', 'advanced-ffl-checkout' ), 400 );
		}

		global $wpdb;
		$body = sprintf(
			"Dealer onboarding submission:\nBusiness: %s\nFFL: %s\nContact: %s <%s>\nPhone: %s\nPremise ZIP: %s\nSuggested fee: \$%s\n\nNotes:\n%s",
			$f['business_name'], $f['license_number'], $f['contact_name'], $f['contact_email'], $f['phone'], $f['premise_zip'], number_format( $f['transfer_fee'], 2 ), $f['notes']
		);

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'dealer_onboarding',
			'notes'       => $body,
			'actor'       => 'public-form',
			'actor_ip'    => $ip,
		] );

		$admin = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );
		wp_mail(
			$admin,
			sprintf( '[G2A] New FFL onboarding request: %s', $f['business_name'] ),
			nl2br( esc_html( $body ) ),
			[ 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $f['contact_email'] ]
		);

		wp_safe_redirect( add_query_arg( 'onboard_done', '1', wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}
}
