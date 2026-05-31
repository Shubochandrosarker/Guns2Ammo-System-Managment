<?php
/**
 * [g2ab_member_checkin] — public page members land on after scanning the
 * staff dashboard QR. Self-identifies (logged-in WP user, or
 * email + last-name fallback), then sits in a "waiting for staff
 * confirmation" state. The staff dashboard polls for pending requests
 * and pops a confirm modal.
 *
 * Security:
 *   - Station token is required and HMAC-validated on every submit.
 *   - Members CAN see only their own status on submit; the server never
 *     leaks waiver/PII to the URL itself.
 *   - One pending request per station at a time; resubmission replaces
 *     the previous one.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Member_Checkin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2ab_member_checkin', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		// The station token comes via ?s= on the URL the QR encodes.
		$station = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$valid_station = $station && G2AB_REST_Staff_Controller::verify_station_token( $station );

		wp_enqueue_style(
			'g2ab-member-checkin',
			G2AB_URL . 'assets/css/member-checkin.css',
			array(),
			G2AB_VERSION
		);
		wp_enqueue_script(
			'g2ab-member-checkin',
			G2AB_URL . 'assets/js/member-checkin.js',
			array(),
			G2AB_VERSION,
			true
		);
		wp_localize_script( 'g2ab-member-checkin', 'g2abCheckin', array(
			'root'    => esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'station' => $station,
			'valid'   => $valid_station,
			'user'    => is_user_logged_in() ? array(
				'id'    => get_current_user_id(),
				'email' => wp_get_current_user()->user_email,
				'name'  => wp_get_current_user()->display_name,
			) : null,
			'i18n'    => array(
				'waiting'    => __( 'Waiting for staff to confirm your check-in…', 'g2a-booking' ),
				'confirmed'  => __( 'Welcome! You\'re checked in.', 'g2a-booking' ),
				'declined'   => __( 'Please see the front desk.', 'g2a-booking' ),
				'badStation' => __( 'This QR code is no longer valid. Ask staff to refresh the screen.', 'g2a-booking' ),
				'sending'    => __( 'Sending to front desk…', 'g2a-booking' ),
			),
		) );

		ob_start();
		?>
		<div class="g2ab-mc" id="g2ab-mc">
			<div class="g2ab-mc__shell">
				<div class="g2ab-mc__brand">
					<div class="g2ab-mc__mark">◆</div>
					<div class="g2ab-mc__name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
				</div>

				<?php if ( ! $valid_station ) : ?>
					<div class="g2ab-mc__msg g2ab-mc__msg--err">
						<h2><?php esc_html_e( 'QR expired', 'g2a-booking' ); ?></h2>
						<p><?php esc_html_e( 'This check-in QR code has expired or is invalid. Ask staff to refresh the screen and scan again.', 'g2a-booking' ); ?></p>
					</div>
				<?php else : ?>
					<div class="g2ab-mc__step" data-step="form">
						<h2><?php esc_html_e( 'Check in', 'g2a-booking' ); ?></h2>
						<p class="g2ab-mc__lede"><?php esc_html_e( "We'll let the front desk know you're here.", 'g2a-booking' ); ?></p>
						<?php if ( is_user_logged_in() ) :
							$u = wp_get_current_user(); ?>
							<div class="g2ab-mc__who">
								<div class="g2ab-mc__who-l"><?php esc_html_e( 'Signed in as', 'g2a-booking' ); ?></div>
								<div class="g2ab-mc__who-v"><?php echo esc_html( $u->display_name ); ?></div>
								<div class="g2ab-mc__who-e"><?php echo esc_html( $u->user_email ); ?></div>
							</div>
							<button type="button" class="g2ab-mc__btn" id="g2ab-mc-go-loggedin"><?php esc_html_e( 'Notify front desk', 'g2a-booking' ); ?></button>
							<button type="button" class="g2ab-mc__btn g2ab-mc__btn--ghost" id="g2ab-mc-use-form"><?php esc_html_e( 'Not me — use a different email', 'g2a-booking' ); ?></button>
						<?php endif; ?>

						<form id="g2ab-mc-form" class="g2ab-mc__form" autocomplete="on"<?php echo is_user_logged_in() ? ' hidden' : ''; ?>>
							<label>
								<span><?php esc_html_e( 'Email', 'g2a-booking' ); ?></span>
								<input name="email" type="email" required autocomplete="email" inputmode="email">
							</label>
							<label>
								<span><?php esc_html_e( 'Last name', 'g2a-booking' ); ?></span>
								<input name="last_name" type="text" required autocomplete="family-name" maxlength="60">
							</label>
							<button type="submit" class="g2ab-mc__btn"><?php esc_html_e( 'Notify front desk', 'g2a-booking' ); ?></button>
						</form>
					</div>

					<div class="g2ab-mc__step" data-step="waiting" hidden>
						<div class="g2ab-mc__spinner" aria-hidden="true"></div>
						<h2><?php esc_html_e( 'Front desk has been notified', 'g2a-booking' ); ?></h2>
						<p class="g2ab-mc__lede" id="g2ab-mc-wait-msg"><?php esc_html_e( "We'll confirm in a moment. Please head to the counter.", 'g2a-booking' ); ?></p>
					</div>

					<div class="g2ab-mc__step" data-step="done" hidden>
						<div class="g2ab-mc__ok" aria-hidden="true">✓</div>
						<h2 id="g2ab-mc-done-title"><?php esc_html_e( "You're checked in", 'g2a-booking' ); ?></h2>
						<p class="g2ab-mc__lede" id="g2ab-mc-done-msg"></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
