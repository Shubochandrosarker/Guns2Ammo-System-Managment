<?php
/**
 * Dealer confirmation portal — public landing page template.
 *
 * Variables in scope (provided by Portal::handle_request → render_page):
 *   $view = [
 *     'token_raw'   => string,
 *     'error'       => array|null,
 *     'already'     => array|null,   // info about prior consumption
 *     'transfer'    => object|null,
 *     'dealer'      => array|null,
 *     'action_done' => string|null,  // ?ok=mark_received|report_issue|not_my_shipment
 *     'two_factor'  => string,
 *     'theme'       => array,
 *     'slug'        => string,
 *     'token'       => object|null,  // dealer_tokens row when verified
 *   ]
 *
 * @package WpisticFFL
 */

defined( 'ABSPATH' ) || exit;

$theme       = $view['theme'];
$transfer    = $view['transfer'];
$dealer      = $view['dealer'];
$error       = $view['error'];
$action_done = $view['action_done'];
$two_factor  = $view['two_factor'];
$inline_css  = \WpisticFFL\Theming::render_inline_css();
$plugin_css  = WPISTIC_FFL_URL . 'assets/css/portal.css?ver=' . WPISTIC_FFL_VERSION;
$plugin_js   = WPISTIC_FFL_URL . 'assets/js/portal.js?ver=' . WPISTIC_FFL_VERSION;
$store_name  = $theme['business_name'];
$logo_url    = $theme['logo_url'];
$portal_url  = home_url( '/' . $view['slug'] . '/' . rawurlencode( $view['token_raw'] ) . '/' );

// Form submission target (always admin-post.php to keep it simple + nonce-safe)
$is_preview  = ! empty( $view['is_preview'] );
$form_action = $is_preview ? '#preview-disabled' : admin_url( 'admin-post.php' );

// Detect a flash error from a redirect (?error=)
$flash_error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
$flash_messages = [
	'two_factor_failed' => 'email_otp' === ( $view['two_factor'] ?? '' )
		? __( 'Verification code did not match, expired, or was used too many times. Click "Resend" below for a fresh code.', 'advanced-ffl-checkout' )
		: __( 'License verification failed. Please double-check the last 4 characters of your FFL license number.', 'advanced-ffl-checkout' ),
];
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $store_name ); ?> — <?php esc_html_e( 'FFL Transfer Confirmation', 'advanced-ffl-checkout' ); ?></title>
	<?php if ( ! empty( $theme['favicon_url'] ) ) : ?>
		<link rel="icon" href="<?php echo esc_url( $theme['favicon_url'] ); ?>">
	<?php endif; ?>
	<link rel="stylesheet" href="<?php echo esc_url( $plugin_css ); ?>">
	<style id="wpistic-ffl-portal-vars"><?php echo $inline_css; // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body class="wpistic-ffl-portal-body">

<?php do_action( 'wpistic_ffl_portal_before' ); ?>

<main class="wpistic-ffl-portal" role="main">

	<header class="wpistic-ffl-portal__header">
		<?php if ( $logo_url ) : ?>
			<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" class="wpistic-ffl-portal__logo">
		<?php else : ?>
			<h2 class="wpistic-ffl-portal__brandname"><?php echo esc_html( $store_name ); ?></h2>
		<?php endif; ?>
		<p class="wpistic-ffl-portal__tagline"><?php esc_html_e( 'Secure FFL Transfer Portal', 'advanced-ffl-checkout' ); ?></p>
	</header>

	<section class="wpistic-ffl-portal__card">

		<?php
		// ── ERROR STATE ──────────────────────────────────────────────────────
		if ( $error && empty( $view['already'] ) ) :
			?>
			<div class="wpistic-ffl-portal__icon wpistic-ffl-portal__icon--error">
				<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
			</div>
			<h1 class="wpistic-ffl-portal__heading"><?php esc_html_e( 'Confirmation link issue', 'advanced-ffl-checkout' ); ?></h1>
			<p class="wpistic-ffl-portal__lede"><?php echo esc_html( $error['message'] ); ?></p>
			<p class="wpistic-ffl-portal__lede wpistic-ffl-portal__muted">
				<?php
				printf(
					/* translators: %s = support email */
					esc_html__( 'If you believe this is in error, please contact %s.', 'advanced-ffl-checkout' ),
					'<a href="mailto:' . esc_attr( $theme['support_email'] ) . '">' . esc_html( $theme['support_email'] ) . '</a>'
				);
				?>
			</p>

		<?php
		// ── ALREADY-USED REPLAY VIEW ──────────────────────────────────────────
		elseif ( $view['already'] ) :
			?>
			<div class="wpistic-ffl-portal__icon wpistic-ffl-portal__icon--success">
				<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
			</div>
			<h1 class="wpistic-ffl-portal__heading"><?php esc_html_e( 'Already confirmed', 'advanced-ffl-checkout' ); ?></h1>
			<p class="wpistic-ffl-portal__lede">
				<?php
				printf(
					/* translators: 1: action name, 2: date */
					esc_html__( 'This transfer was marked as %1$s on %2$s. No further action is required.', 'advanced-ffl-checkout' ),
					'<strong>' . esc_html( str_replace( '_', ' ', (string) ( $view['already']['used_action'] ?? 'received' ) ) ) . '</strong>',
					'<strong>' . esc_html( get_date_from_gmt( (string) ( $view['already']['used_at'] ?? '' ), 'F j, Y \a\t g:i A' ) ) . '</strong>'
				);
				?>
			</p>
			<?php if ( $transfer ) : ?>
				<?php self_render_transfer_summary( $transfer, $dealer ); ?>
			<?php endif; ?>

		<?php
		// ── SUCCESS STATE (just submitted) ────────────────────────────────────
		elseif ( $action_done ) :
			$success_messages = [
				'mark_received'   => __( 'Thank you. The receiving FFL has confirmed this transfer. Customer will be notified automatically.', 'advanced-ffl-checkout' ),
				'report_issue'    => __( 'We have logged your issue report. Our team will reach out shortly to resolve it.', 'advanced-ffl-checkout' ),
				'not_my_shipment' => __( 'Thank you. We have flagged this shipment for review.', 'advanced-ffl-checkout' ),
			];
			?>
			<div class="wpistic-ffl-portal__icon wpistic-ffl-portal__icon--success">
				<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
			</div>
			<h1 class="wpistic-ffl-portal__heading"><?php esc_html_e( 'Confirmation received', 'advanced-ffl-checkout' ); ?></h1>
			<p class="wpistic-ffl-portal__lede"><?php echo esc_html( $success_messages[ $action_done ] ?? $success_messages['mark_received'] ); ?></p>
			<?php if ( $transfer ) self_render_transfer_summary( $transfer, $dealer ); ?>
			<p class="wpistic-ffl-portal__muted wpistic-ffl-portal__center">
				<?php esc_html_e( 'You may close this page.', 'advanced-ffl-checkout' ); ?>
			</p>

		<?php
		// ── ACTION FORM (token valid, awaiting dealer action) ─────────────────
		elseif ( $transfer && $dealer ) :
			?>
			<h1 class="wpistic-ffl-portal__heading">
				<?php echo esc_html( \WpisticFFL\Theming::replace_tags( $theme['welcome_heading'], [] ) ); ?>
			</h1>
			<p class="wpistic-ffl-portal__lede">
				<?php echo esc_html( \WpisticFFL\Theming::replace_tags( $theme['welcome_subtext'], [] ) ); ?>
			</p>

			<?php if ( $flash_error && isset( $flash_messages[ $flash_error ] ) ) : ?>
				<div class="wpistic-ffl-portal__alert wpistic-ffl-portal__alert--error" role="alert">
					<?php echo esc_html( $flash_messages[ $flash_error ] ); ?>
				</div>
			<?php endif; ?>

			<?php self_render_transfer_summary( $transfer, $dealer ); ?>

			<form method="post" action="<?php echo esc_url( $form_action ); ?>" class="wpistic-ffl-portal__form" id="wpistic-ffl-portal-form" autocomplete="off" <?php if ( $is_preview ) echo 'onsubmit="alert(\'Preview mode — form submission disabled.\'); return false;"'; ?>>
				<?php wp_nonce_field( 'wpistic_ffl_portal_action', 'wpistic_ffl_portal_nonce' ); ?>
				<input type="hidden" name="action" value="wpistic_ffl_portal_submit">
				<input type="hidden" name="token" value="<?php echo esc_attr( $view['token_raw'] ); ?>">
				<input type="hidden" name="portal_action" id="wpistic-ffl-portal-action" value="">

				<?php /* Honeypot — real dealers leave this blank; bots fill it in. */ ?>
				<div class="wpistic-ffl-portal__honeypot" aria-hidden="true">
					<label>Website (leave blank)<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
				</div>

				<?php if ( 'last4_ffl' === $two_factor ) : ?>
					<div class="wpistic-ffl-portal__field">
						<label for="wpistic-ffl-last4" class="wpistic-ffl-portal__label">
							<?php esc_html_e( 'Verify your identity — last 4 of your FFL license number', 'advanced-ffl-checkout' ); ?>
							<span class="wpistic-ffl-portal__required">*</span>
						</label>
						<input type="text" id="wpistic-ffl-last4" name="last4" maxlength="4" required pattern="[0-9A-Za-z]{4}" inputmode="numeric" autocomplete="off" class="wpistic-ffl-portal__input wpistic-ffl-portal__input--otp" placeholder="••••">
						<p class="wpistic-ffl-portal__hint"><?php esc_html_e( 'Enter the last 4 characters of your FFL license number for security.', 'advanced-ffl-checkout' ); ?></p>
					</div>
				<?php elseif ( 'email_otp' === $two_factor ) : ?>
					<div class="wpistic-ffl-portal__field">
						<label for="wpistic-ffl-otp" class="wpistic-ffl-portal__label">
							<?php esc_html_e( 'Verify your identity — 6-digit code from email', 'advanced-ffl-checkout' ); ?>
							<span class="wpistic-ffl-portal__required">*</span>
						</label>
						<input type="text" id="wpistic-ffl-otp" name="otp_code" maxlength="6" required pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="wpistic-ffl-portal__input wpistic-ffl-portal__input--otp" placeholder="••••••" style="letter-spacing:.4em;text-align:center;">
						<p class="wpistic-ffl-portal__hint">
							<?php if ( ! empty( $view['otp_sent_to'] ) ) : ?>
								<?php
								printf(
									/* translators: %s = masked email */
									esc_html__( 'We sent a 6-digit code to %s. It expires in 10 minutes.', 'advanced-ffl-checkout' ),
									'<strong>' . esc_html( $view['otp_sent_to'] ) . '</strong>'
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Enter the 6-digit verification code from the email we just sent. It expires in 10 minutes.', 'advanced-ffl-checkout' ); ?>
							<?php endif; ?>
							<br>
							<a href="<?php echo esc_url( add_query_arg( 'resend_otp', '1', $portal_url ) ); ?>" style="color:var(--ffl-primary,#DCB45F);text-decoration:underline;font-size:12px;">
								<?php esc_html_e( "Didn't get the code? Resend", 'advanced-ffl-checkout' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>

				<div class="wpistic-ffl-portal__field">
					<label for="wpistic-ffl-notes" class="wpistic-ffl-portal__label">
						<?php esc_html_e( 'Notes (optional)', 'advanced-ffl-checkout' ); ?>
					</label>
					<textarea id="wpistic-ffl-notes" name="notes" rows="3" class="wpistic-ffl-portal__textarea" placeholder="<?php esc_attr_e( 'Any condition notes, packaging issues, or comments.', 'advanced-ffl-checkout' ); ?>"></textarea>
				</div>

				<div class="wpistic-ffl-portal__actions">
					<button type="submit" class="wpistic-ffl-portal__btn wpistic-ffl-portal__btn--primary" data-action="mark_received">
						<?php echo esc_html( $theme['btn_received_label'] ); ?>
					</button>
					<button type="submit" class="wpistic-ffl-portal__btn wpistic-ffl-portal__btn--warning" data-action="report_issue">
						<?php echo esc_html( $theme['btn_issue_label'] ); ?>
					</button>
					<button type="submit" class="wpistic-ffl-portal__btn wpistic-ffl-portal__btn--ghost" data-action="not_my_shipment">
						<?php echo esc_html( $theme['btn_not_mine_label'] ); ?>
					</button>
				</div>

				<p class="wpistic-ffl-portal__compliance">
					<?php esc_html_e( 'By confirming, you acknowledge receipt of this firearm shipment as the federally licensed receiving party. Your IP address and timestamp are logged for ATF compliance.', 'advanced-ffl-checkout' ); ?>
				</p>
			</form>
		<?php endif; ?>

	</section>

	<footer class="wpistic-ffl-portal__footer">
		<?php
		$footer_text = \WpisticFFL\Theming::replace_tags( (string) $theme['footer_text'], [] );
		if ( $footer_text ) :
		?>
			<p><?php echo esc_html( $footer_text ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $theme['privacy_url'] ) || ! empty( $theme['terms_url'] ) ) : ?>
			<p class="wpistic-ffl-portal__legal">
				<?php if ( ! empty( $theme['privacy_url'] ) ) : ?>
					<a href="<?php echo esc_url( $theme['privacy_url'] ); ?>"><?php esc_html_e( 'Privacy', 'advanced-ffl-checkout' ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $theme['privacy_url'] ) && ! empty( $theme['terms_url'] ) ) echo ' · '; ?>
				<?php if ( ! empty( $theme['terms_url'] ) ) : ?>
					<a href="<?php echo esc_url( $theme['terms_url'] ); ?>"><?php esc_html_e( 'Terms', 'advanced-ffl-checkout' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( \WpisticFFL\License::show_branding() ) : ?>
			<p class="wpistic-ffl-portal__powered">
				<?php
				printf(
					/* translators: %s: link to wordpressistic.com */
					esc_html__( 'Powered by %s', 'advanced-ffl-checkout' ),
					'<a href="https://wordpressistic.com" rel="noopener" target="_blank">Wordpressistic</a>'
				);
				?>
			</p>
		<?php endif; ?>
	</footer>

</main>

<script src="<?php echo esc_url( $plugin_js ); ?>" defer></script>
</body>
</html>
<?php

/**
 * Inline helper — render the transfer summary card.
 * Defined here (not in a class) so the template stays portable to theme overrides later.
 */
function self_render_transfer_summary( object $transfer, array $dealer ): void {
	$customer_masked = \WpisticFFL\Theming::mask_name( (string) $transfer->customer_name );
	$item            = trim( implode( ' ', array_filter( [ $transfer->item_make, $transfer->item_model ] ) ) ) ?: $transfer->item_description;
	?>
	<div class="wpistic-ffl-portal__summary">
		<div class="wpistic-ffl-portal__summary-row">
			<span class="wpistic-ffl-portal__summary-key"><?php esc_html_e( 'Transfer Reference', 'advanced-ffl-checkout' ); ?></span>
			<span class="wpistic-ffl-portal__summary-val"><?php echo esc_html( $transfer->transfer_ref ); ?></span>
		</div>
		<div class="wpistic-ffl-portal__summary-row">
			<span class="wpistic-ffl-portal__summary-key"><?php esc_html_e( 'Receiving Dealer', 'advanced-ffl-checkout' ); ?></span>
			<span class="wpistic-ffl-portal__summary-val">
				<?php echo esc_html( $dealer['name'] ); ?>
				<small class="wpistic-ffl-portal__muted-inline">
					· FFL #<?php echo esc_html( $dealer['license_number'] ); ?>
				</small>
			</span>
		</div>
		<div class="wpistic-ffl-portal__summary-row">
			<span class="wpistic-ffl-portal__summary-key"><?php esc_html_e( 'Item', 'advanced-ffl-checkout' ); ?></span>
			<span class="wpistic-ffl-portal__summary-val">
				<?php echo esc_html( $item ); ?>
				<?php if ( ! empty( $transfer->item_caliber ) ) : ?>
					<small class="wpistic-ffl-portal__muted-inline">· <?php echo esc_html( $transfer->item_caliber ); ?></small>
				<?php endif; ?>
			</span>
		</div>
		<div class="wpistic-ffl-portal__summary-row">
			<span class="wpistic-ffl-portal__summary-key"><?php esc_html_e( 'Customer', 'advanced-ffl-checkout' ); ?></span>
			<span class="wpistic-ffl-portal__summary-val"><?php echo esc_html( $customer_masked ); ?></span>
		</div>
		<?php if ( $transfer->shipment_carrier || $transfer->shipment_tracking ) : ?>
			<div class="wpistic-ffl-portal__summary-row">
				<span class="wpistic-ffl-portal__summary-key"><?php esc_html_e( 'Shipment', 'advanced-ffl-checkout' ); ?></span>
				<span class="wpistic-ffl-portal__summary-val">
					<?php echo esc_html( strtoupper( (string) $transfer->shipment_carrier ) ); ?>
					<?php if ( $transfer->shipment_tracking ) : ?>
						<small class="wpistic-ffl-portal__muted-inline">· <?php echo esc_html( $transfer->shipment_tracking ); ?></small>
					<?php endif; ?>
				</span>
			</div>
		<?php endif; ?>
		<?php if ( $transfer->shipped_date ) : ?>
			<div class="wpistic-ffl-portal__summary-row">
				<span class="wpistic-ffl-portal__summary-key"><?php esc_html_e( 'Shipped', 'advanced-ffl-checkout' ); ?></span>
				<span class="wpistic-ffl-portal__summary-val"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $transfer->shipped_date ) ) ); ?></span>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
