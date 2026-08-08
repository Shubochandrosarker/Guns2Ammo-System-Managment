<?php
/**
 * [wpistic_contact_form] shortcode — a branded, ready-to-use contact
 * form whose submissions land in the Formistic dashboard.
 *
 * Supports an optional file-upload field, reCAPTCHA v3 and Cloudflare
 * Turnstile when configured under Settings → Spam.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the contact-form shortcode and its submit handler.
 */
class Wpistic_Formistic_Shortcode {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_shortcode( 'wpistic_contact_form', [ $this, 'render' ] );
		add_action( 'admin_post_wpistic_formistic_submit', [ $this, 'handle_submit' ] );
		add_action( 'admin_post_nopriv_wpistic_formistic_submit', [ $this, 'handle_submit' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
	}

	/**
	 * Register (lightweight) frontend styles.
	 */
	public function assets() {
		wp_register_style( 'wpistic-formistic-form', WPISTIC_FORMISTIC_URL . 'assets/form.css', [], WPISTIC_FORMISTIC_VERSION );
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
				'title'     => __( 'Send Us a Message', 'formistic' ),
				'form_name' => __( 'Contact Form', 'formistic' ),
				'button'    => __( 'Send Message', 'formistic' ),
				'upload'    => '0',
			],
			$atts,
			'wpistic_contact_form'
		);

		$show_upload = ( '1' === (string) $atts['upload'] ) && class_exists( 'Wpistic_Formistic_Attachments' ) && Wpistic_Formistic_Attachments::enabled();
		Wpistic_Formistic_Database::log_impression( (string) $atts['form_name'] );

		wp_enqueue_style( 'wpistic-formistic-form' );

		$sent       = isset( $_GET['wpistic_formistic_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['wpistic_formistic_sent'] ) ) : '';
		$enctype    = $show_upload ? ' enctype="multipart/form-data"' : '';

		ob_start();
		?>
		<div class="wpistic-formistic-form-wrap" id="Wpistic_Formistic">
			<?php if ( '1' === $sent ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--ok">
					<?php esc_html_e( 'Thank you — your message has been sent. We will get back to you shortly.', 'formistic' ); ?>
				</div>
			<?php elseif ( 'error' === $sent ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--err">
					<?php esc_html_e( 'Sorry, something went wrong. Please try again.', 'formistic' ); ?>
				</div>
			<?php elseif ( 'spam' === $sent ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--err">
					<?php esc_html_e( 'Your submission was blocked by our spam filter. If you believe this is a mistake, please try again or contact us another way.', 'formistic' ); ?>
				</div>
			<?php elseif ( 'rate' === $sent ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--err">
					<?php esc_html_e( 'Too many submissions from your network. Please wait a while and try again.', 'formistic' ); ?>
				</div>
			<?php elseif ( 'upload' === $sent ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--err">
					<?php esc_html_e( 'There was a problem with one of your file uploads. Please check the file type and size and try again.', 'formistic' ); ?>
				</div>
			<?php elseif ( 'consent' === $sent ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--err">
					<?php esc_html_e( 'Please tick the consent box to continue.', 'formistic' ); ?>
				</div>
			<?php endif; ?>

			<form class="wpistic-formistic-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $enctype; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<input type="hidden" name="action" value="wpistic_formistic_submit">
				<input type="hidden" name="wpistic_formistic_form_name" value="<?php echo esc_attr( $atts['form_name'] ); ?>">
				<?php wp_nonce_field( 'wpistic_formistic_submit', 'wpistic_formistic_nonce' ); ?>
				<p class="wpistic-formistic-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'formistic' ); ?>
						<input type="text" name="wpistic_formistic_hp" tabindex="-1" autocomplete="off">
					</label>
				</p>

				<?php if ( $atts['title'] ) : ?>
					<h3 class="wpistic-formistic-form-title"><?php echo esc_html( $atts['title'] ); ?></h3>
				<?php endif; ?>

				<div class="wpistic-formistic-form-row">
					<label class="wpistic-formistic-field">
						<span><?php esc_html_e( 'Your Name', 'formistic' ); ?> *</span>
						<input type="text" name="wpistic_formistic_name" required>
					</label>
					<label class="wpistic-formistic-field">
						<span><?php esc_html_e( 'Email Address', 'formistic' ); ?> *</span>
						<input type="email" name="wpistic_formistic_email" required>
					</label>
				</div>
				<div class="wpistic-formistic-form-row">
					<label class="wpistic-formistic-field">
						<span><?php esc_html_e( 'Phone', 'formistic' ); ?></span>
						<input type="text" name="wpistic_formistic_phone">
					</label>
					<label class="wpistic-formistic-field">
						<span><?php esc_html_e( 'Subject', 'formistic' ); ?></span>
						<input type="text" name="wpistic_formistic_subject">
					</label>
				</div>
				<label class="wpistic-formistic-field">
					<span><?php esc_html_e( 'Message', 'formistic' ); ?> *</span>
					<textarea name="wpistic_formistic_message" rows="6" required></textarea>
				</label>

				<?php if ( $show_upload ) :
					$exts = Wpistic_Formistic_Attachments::allowed_extensions();
					$max  = (int) get_option( 'wpistic_formistic_att_max_size_mb', 5 );
					$accept = $exts ? implode( ',', array_map( function ( $e ) { return '.' . $e; }, $exts ) ) : '';
					?>
					<label class="wpistic-formistic-field wpistic-formistic-field--file">
						<span><?php esc_html_e( 'Attachments', 'formistic' ); ?></span>
						<input type="file" name="wpistic_formistic_files[]" multiple<?php if ( $accept ) echo ' accept="' . esc_attr( $accept ) . '"'; ?>>
						<small class="wpistic-formistic-field__help">
							<?php
							/* translators: 1: comma list of file extensions, 2: max size in MB */
							printf( esc_html__( 'Allowed: %1$s · Max %2$d MB per file', 'formistic' ), esc_html( implode( ', ', $exts ) ?: __( 'any', 'formistic' ) ), (int) $max );
							?>
						</small>
					</label>
				<?php endif; ?>

				<?php if ( class_exists( 'Wpistic_Formistic_Gdpr' ) && Wpistic_Formistic_Gdpr::consent_enabled() ) : ?>
					<label class="wpistic-formistic-consent">
						<input type="checkbox" name="wpistic_formistic_consent" value="1"<?php echo Wpistic_Formistic_Gdpr::consent_required() ? ' required' : ''; ?>>
						<span><?php echo esc_html( Wpistic_Formistic_Gdpr::consent_text() ); ?><?php echo Wpistic_Formistic_Gdpr::consent_required() ? ' *' : ''; ?></span>
					</label>
				<?php endif; ?>

				<?php if ( class_exists( 'Wpistic_Formistic_Spam' ) ) {
					Wpistic_Formistic_Spam::print_turnstile_field();
					Wpistic_Formistic_Spam::print_recaptcha_field();
				} ?>

				<button type="submit" class="wpistic-formistic-form-submit"><?php echo esc_html( $atts['button'] ); ?></button>
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
		if ( ! empty( $_POST['wpistic_formistic_hp'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$nonce = isset( $_POST['wpistic_formistic_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpistic_formistic_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpistic_formistic_submit' ) ) {
			$this->redirect_back( $back, 'error' );
		}

		// CAPTCHA pre-checks (only if configured & enabled).
		if ( class_exists( 'Wpistic_Formistic_Spam' ) ) {
			$r1 = Wpistic_Formistic_Spam::verify_recaptcha();
			if ( is_wp_error( $r1 ) ) {
				$this->redirect_back( $back, 'spam' );
			}
			$r2 = Wpistic_Formistic_Spam::verify_turnstile();
			if ( is_wp_error( $r2 ) ) {
				$this->redirect_back( $back, 'spam' );
			}
		}

		$form_name = isset( $_POST['wpistic_formistic_form_name'] )
			? sanitize_text_field( wp_unslash( $_POST['wpistic_formistic_form_name'] ) )
			: __( 'Contact Form', 'formistic' );

		$fields = [];
		$map    = [
			'wpistic_formistic_name'    => __( 'Name', 'formistic' ),
			'wpistic_formistic_email'   => __( 'Email', 'formistic' ),
			'wpistic_formistic_phone'   => __( 'Phone', 'formistic' ),
			'wpistic_formistic_subject' => __( 'Subject', 'formistic' ),
			'wpistic_formistic_message' => __( 'Message', 'formistic' ),
		];
		foreach ( $map as $key => $label ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$value = ( 'wpistic_formistic_message' === $key )
				? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) )
				: sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			if ( '' !== trim( $value ) ) {
				$fields[ $label ] = $value;
			}
		}

		// Server-side validation — required fields + valid email.
		$email_label   = __( 'Email', 'formistic' );
		$name_label    = __( 'Name', 'formistic' );
		$message_label = __( 'Message', 'formistic' );
		$email_value   = $fields[ $email_label ] ?? '';

		if (
			empty( $fields[ $name_label ] ) ||
			empty( $fields[ $message_label ] ) ||
			! is_email( $email_value )
		) {
			$this->redirect_back( $back, 'error' );
		}

		// GDPR consent — if enabled & required, the box must be ticked.
		if ( class_exists( 'Wpistic_Formistic_Gdpr' ) && Wpistic_Formistic_Gdpr::consent_enabled() ) {
			$ticked = ! empty( $_POST['wpistic_formistic_consent'] );
			if ( Wpistic_Formistic_Gdpr::consent_required() && ! $ticked ) {
				$this->redirect_back( $back, 'consent' );
			}
			$fields[ __( 'Consent', 'formistic' ) ] = $ticked
				? Wpistic_Formistic_Gdpr::consent_record_value()
				: __( 'No (optional, declined)', 'formistic' );
		}

		$capture = new Wpistic_Formistic_Capture();
		$id      = $capture->store( $form_name, $fields );

		if ( ! $id ) {
			// Blocked by spam stack (blocklist / rate limit / Akismet).
			$this->redirect_back( $back, 'spam' );
		}

		// Handle uploaded files (after the submission row exists).
		if ( class_exists( 'Wpistic_Formistic_Attachments' ) && Wpistic_Formistic_Attachments::enabled() && ! empty( $_FILES['wpistic_formistic_files'] ) ) {
			$result = Wpistic_Formistic_Attachments::ingest_post_files( 'wpistic_formistic_files', $id );
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
		$url = add_query_arg( 'wpistic_formistic_sent', $status, remove_query_arg( 'wpistic_formistic_sent', $back ) ) . '#Wpistic_Formistic';
		wp_safe_redirect( $url );
		exit;
	}
}
