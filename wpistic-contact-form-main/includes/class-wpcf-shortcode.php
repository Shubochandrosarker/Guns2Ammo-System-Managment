<?php
/**
 * [wpistic_contact_form] shortcode — a branded, ready-to-use contact
 * form whose submissions land in the WPistic Contact dashboard.
 *
 * Supports an optional file-upload field, reCAPTCHA v3 and Cloudflare
 * Turnstile when configured under Settings → Spam.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the contact-form shortcode and its submit handler.
 */
class WPISTIC_CF_Shortcode {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_shortcode( 'wpistic_contact_form', [ $this, 'render' ] );
		add_action( 'admin_post_WPISTIC_CF_submit', [ $this, 'handle_submit' ] );
		add_action( 'admin_post_nopriv_WPISTIC_CF_submit', [ $this, 'handle_submit' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
	}

	/**
	 * Register (lightweight) frontend styles.
	 */
	public function assets() {
		wp_register_style( 'WPISTIC_CF-form', WPISTIC_CF_URL . 'assets/form.css', [], WPISTIC_CF_VERSION );
	}

	/**
	 * Render the contact form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			[
				'title'     => __( 'Send Us a Message', 'wpistic-contact-form' ),
				'form_name' => __( 'Contact Form', 'wpistic-contact-form' ),
				'button'    => __( 'Send Message', 'wpistic-contact-form' ),
				'upload'    => '0',
			],
			$atts,
			'wpistic_contact_form'
		);

		$show_upload = ( '1' === (string) $atts['upload'] ) && class_exists( 'WPISTIC_CF_Attachments' ) && WPISTIC_CF_Attachments::enabled();
		WPISTIC_CF_Database::log_impression( (string) $atts['form_name'] );

		wp_enqueue_style( 'WPISTIC_CF-form' );

		$sent       = isset( $_GET['WPISTIC_CF_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['WPISTIC_CF_sent'] ) ) : '';
		$enctype    = $show_upload ? ' enctype="multipart/form-data"' : '';

		ob_start();
		?>
		<div class="WPISTIC_CF-form-wrap" id="WPISTIC_CF">
			<?php if ( '1' === $sent ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--ok">
					<?php esc_html_e( 'Thank you — your message has been sent. We will get back to you shortly.', 'wpistic-contact-form' ); ?>
				</div>
			<?php elseif ( 'error' === $sent ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--err">
					<?php esc_html_e( 'Sorry, something went wrong. Please try again.', 'wpistic-contact-form' ); ?>
				</div>
			<?php elseif ( 'spam' === $sent ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--err">
					<?php esc_html_e( 'Your submission was blocked by our spam filter. If you believe this is a mistake, please try again or contact us another way.', 'wpistic-contact-form' ); ?>
				</div>
			<?php elseif ( 'rate' === $sent ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--err">
					<?php esc_html_e( 'Too many submissions from your network. Please wait a while and try again.', 'wpistic-contact-form' ); ?>
				</div>
			<?php elseif ( 'upload' === $sent ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--err">
					<?php esc_html_e( 'There was a problem with one of your file uploads. Please check the file type and size and try again.', 'wpistic-contact-form' ); ?>
				</div>
			<?php elseif ( 'consent' === $sent ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--err">
					<?php esc_html_e( 'Please tick the consent box to continue.', 'wpistic-contact-form' ); ?>
				</div>
			<?php endif; ?>

			<form class="WPISTIC_CF-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $enctype; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<input type="hidden" name="action" value="WPISTIC_CF_submit">
				<input type="hidden" name="WPISTIC_CF_form_name" value="<?php echo esc_attr( $atts['form_name'] ); ?>">
				<?php wp_nonce_field( 'WPISTIC_CF_submit', 'WPISTIC_CF_nonce' ); ?>
				<p class="WPISTIC_CF-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'wpistic-contact-form' ); ?>
						<input type="text" name="WPISTIC_CF_hp" tabindex="-1" autocomplete="off">
					</label>
				</p>

				<?php if ( $atts['title'] ) : ?>
					<h3 class="WPISTIC_CF-form-title"><?php echo esc_html( $atts['title'] ); ?></h3>
				<?php endif; ?>

				<div class="WPISTIC_CF-form-row">
					<label class="WPISTIC_CF-field">
						<span><?php esc_html_e( 'Your Name', 'wpistic-contact-form' ); ?> *</span>
						<input type="text" name="WPISTIC_CF_name" required>
					</label>
					<label class="WPISTIC_CF-field">
						<span><?php esc_html_e( 'Email Address', 'wpistic-contact-form' ); ?> *</span>
						<input type="email" name="WPISTIC_CF_email" required>
					</label>
				</div>
				<div class="WPISTIC_CF-form-row">
					<label class="WPISTIC_CF-field">
						<span><?php esc_html_e( 'Phone', 'wpistic-contact-form' ); ?></span>
						<input type="text" name="WPISTIC_CF_phone">
					</label>
					<label class="WPISTIC_CF-field">
						<span><?php esc_html_e( 'Subject', 'wpistic-contact-form' ); ?></span>
						<input type="text" name="WPISTIC_CF_subject">
					</label>
				</div>
				<label class="WPISTIC_CF-field">
					<span><?php esc_html_e( 'Message', 'wpistic-contact-form' ); ?> *</span>
					<textarea name="WPISTIC_CF_message" rows="6" required></textarea>
				</label>

				<?php if ( $show_upload ) :
					$exts = WPISTIC_CF_Attachments::allowed_extensions();
					$max  = (int) get_option( 'WPISTIC_CF_att_max_size_mb', 5 );
					$accept = $exts ? implode( ',', array_map( function ( $e ) { return '.' . $e; }, $exts ) ) : '';
					?>
					<label class="WPISTIC_CF-field WPISTIC_CF-field--file">
						<span><?php esc_html_e( 'Attachments', 'wpistic-contact-form' ); ?></span>
						<input type="file" name="WPISTIC_CF_files[]" multiple<?php if ( $accept ) echo ' accept="' . esc_attr( $accept ) . '"'; ?>>
						<small class="WPISTIC_CF-field__help">
							<?php
							/* translators: 1: comma list of file extensions, 2: max size in MB */
							printf( esc_html__( 'Allowed: %1$s · Max %2$d MB per file', 'wpistic-contact-form' ), esc_html( implode( ', ', $exts ) ?: __( 'any', 'wpistic-contact-form' ) ), (int) $max );
							?>
						</small>
					</label>
				<?php endif; ?>

				<?php if ( class_exists( 'WPISTIC_CF_Gdpr' ) && WPISTIC_CF_Gdpr::consent_enabled() ) : ?>
					<label class="WPISTIC_CF-consent">
						<input type="checkbox" name="WPISTIC_CF_consent" value="1"<?php echo WPISTIC_CF_Gdpr::consent_required() ? ' required' : ''; ?>>
						<span><?php echo esc_html( WPISTIC_CF_Gdpr::consent_text() ); ?><?php echo WPISTIC_CF_Gdpr::consent_required() ? ' *' : ''; ?></span>
					</label>
				<?php endif; ?>

				<?php if ( class_exists( 'WPISTIC_CF_Spam' ) ) {
					WPISTIC_CF_Spam::print_turnstile_field();
					WPISTIC_CF_Spam::print_recaptcha_field();
				} ?>

				<button type="submit" class="WPISTIC_CF-form-submit"><?php echo esc_html( $atts['button'] ); ?></button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle the shortcode form submission.
	 */
	public function handle_submit() {
		$back = wp_get_referer() ?: home_url( '/' );

		// Honeypot — silently drop bots.
		if ( ! empty( $_POST['WPISTIC_CF_hp'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$nonce = isset( $_POST['WPISTIC_CF_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['WPISTIC_CF_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'WPISTIC_CF_submit' ) ) {
			$this->redirect_back( $back, 'error' );
		}

		// CAPTCHA pre-checks (only if configured & enabled).
		if ( class_exists( 'WPISTIC_CF_Spam' ) ) {
			$r1 = WPISTIC_CF_Spam::verify_recaptcha();
			if ( is_wp_error( $r1 ) ) {
				$this->redirect_back( $back, 'spam' );
			}
			$r2 = WPISTIC_CF_Spam::verify_turnstile();
			if ( is_wp_error( $r2 ) ) {
				$this->redirect_back( $back, 'spam' );
			}
		}

		$form_name = isset( $_POST['WPISTIC_CF_form_name'] )
			? sanitize_text_field( wp_unslash( $_POST['WPISTIC_CF_form_name'] ) )
			: __( 'Contact Form', 'wpistic-contact-form' );

		$fields = [];
		$map    = [
			'WPISTIC_CF_name'    => __( 'Name', 'wpistic-contact-form' ),
			'WPISTIC_CF_email'   => __( 'Email', 'wpistic-contact-form' ),
			'WPISTIC_CF_phone'   => __( 'Phone', 'wpistic-contact-form' ),
			'WPISTIC_CF_subject' => __( 'Subject', 'wpistic-contact-form' ),
			'WPISTIC_CF_message' => __( 'Message', 'wpistic-contact-form' ),
		];
		foreach ( $map as $key => $label ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$value = ( 'WPISTIC_CF_message' === $key )
				? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) )
				: sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			if ( '' !== trim( $value ) ) {
				$fields[ $label ] = $value;
			}
		}

		// Server-side validation — required fields + valid email.
		$email_label   = __( 'Email', 'wpistic-contact-form' );
		$name_label    = __( 'Name', 'wpistic-contact-form' );
		$message_label = __( 'Message', 'wpistic-contact-form' );
		$email_value   = $fields[ $email_label ] ?? '';

		if (
			empty( $fields[ $name_label ] ) ||
			empty( $fields[ $message_label ] ) ||
			! is_email( $email_value )
		) {
			$this->redirect_back( $back, 'error' );
		}

		// GDPR consent — if enabled & required, the box must be ticked.
		if ( class_exists( 'WPISTIC_CF_Gdpr' ) && WPISTIC_CF_Gdpr::consent_enabled() ) {
			$ticked = ! empty( $_POST['WPISTIC_CF_consent'] );
			if ( WPISTIC_CF_Gdpr::consent_required() && ! $ticked ) {
				$this->redirect_back( $back, 'consent' );
			}
			$fields[ __( 'Consent', 'wpistic-contact-form' ) ] = $ticked
				? WPISTIC_CF_Gdpr::consent_record_value()
				: __( 'No (optional, declined)', 'wpistic-contact-form' );
		}

		$capture = new WPISTIC_CF_Capture();
		$id      = $capture->store( $form_name, $fields );

		if ( ! $id ) {
			// Blocked by spam stack (blocklist / rate limit / Akismet).
			$this->redirect_back( $back, 'spam' );
		}

		// Handle uploaded files (after the submission row exists).
		if ( class_exists( 'WPISTIC_CF_Attachments' ) && WPISTIC_CF_Attachments::enabled() && ! empty( $_FILES['WPISTIC_CF_files'] ) ) {
			$result = WPISTIC_CF_Attachments::ingest_post_files( 'WPISTIC_CF_files', $id );
			if ( ! empty( $result['errors'] ) && empty( $result['stored'] ) ) {
				$this->redirect_back( $back, 'upload' );
			}
		}

		$this->redirect_back( $back, '1' );
	}

	/**
	 * Redirect to the form page with a status flag and exit.
	 *
	 * @param string $back   Origin URL.
	 * @param string $status One of: 1 | error | spam | rate | upload.
	 */
	protected function redirect_back( $back, $status ) {
		$url = add_query_arg( 'WPISTIC_CF_sent', $status, remove_query_arg( 'WPISTIC_CF_sent', $back ) ) . '#WPISTIC_CF';
		wp_safe_redirect( $url );
		exit;
	}
}
