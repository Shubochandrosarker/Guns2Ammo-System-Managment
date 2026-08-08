<?php
/**
 * Email Automation — admin settings tab.
 *
 * Renders inside the existing g2ab-settings page via the
 * 'email_automation' tab key registered in class-settings-pro.php.
 *
 * @package G2AB\Modules\EmailAutomation
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Email_Settings {

	public function __construct() {
		add_filter( 'g2ab_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'g2ab_settings_render_email_automation', array( $this, 'render' ) );
		add_action( 'admin_post_g2ab_save_email_settings', array( $this, 'save' ) );
		add_action( 'admin_post_g2ab_send_test_email', array( $this, 'send_test' ) );
		add_action( 'admin_post_g2ab_preview_email', array( $this, 'preview' ) );
	}

	public function register_tab( $tabs ) {
		$tabs['email_automation'] = 'Email Templates';
		return $tabs;
	}

	public function render() {
		$engine = new G2AB_Email_Engine();
		$labels = $engine->event_labels();
		$saved  = get_option( G2AB_Email_Engine::OPTION_TEMPLATES, array() );
		$events_enabled = get_option( 'g2ab_email_events', array() );

		$active = isset( $_GET['event'] ) ? sanitize_key( $_GET['event'] ) : 'booking_created';
		if ( ! isset( $labels[ $active ] ) ) $active = 'booking_created';
		$tpl = $engine->get_template( $active );

		$msg = '';
		if ( ! empty( $_GET['saved'] ) ) $msg = '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
		if ( ! empty( $_GET['test_sent'] ) ) $msg = '<div class="notice notice-success is-dismissible"><p>Test email dispatched.</p></div>';
		if ( ! empty( $_GET['review_url_invalid'] ) ) $msg = '<div class="notice notice-warning is-dismissible"><p>The review URL was not saved — it must be a valid http(s) URL.</p></div>';

		$preview_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'g2ab_preview_email', 'event' => $active ), admin_url( 'admin-post.php' ) ),
			'g2ab_preview_email',
			'_g2ab_nonce'
		);
		?>
		<style>
			.g2ab-em__layout{display:grid;grid-template-columns:280px 1fr;gap:24px;}
			.g2ab-em__sidebar{background:#fff;border:1px solid #d0d4d9;padding:0;}
			.g2ab-em__event{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eee;text-decoration:none;color:#1A191E;font-size:13px;}
			.g2ab-em__event.is-active{background:#1A191E;color:#fff;border-left:4px solid #E8802F;}
			.g2ab-em__event:hover{background:#F4F5F7;}
			.g2ab-em__event.is-active:hover{background:#1A191E;}
			.g2ab-em__pill{font-size:10px;padding:2px 8px;border-radius:2px;background:#E8802F;color:#fff;}
			.g2ab-em__pill--off{background:#A7A6AE;}
			.g2ab-em__panel{background:#fff;border:1px solid #d0d4d9;padding:24px;}
			.g2ab-em__panel h2{margin-top:0;}
			.g2ab-em__row{margin-bottom:18px;}
			.g2ab-em__row label{display:block;font-weight:600;margin-bottom:6px;}
			.g2ab-em__row input[type=text],.g2ab-em__row input[type=url],.g2ab-em__row textarea{width:100%;}
			.g2ab-em__row textarea{min-height:280px;font-family:Menlo,monospace;font-size:13px;}
			.g2ab-em__tags{background:#F4F5F7;padding:12px;border-left:3px solid #E8802F;font-size:12px;margin-bottom:18px;}
			.g2ab-em__tags code{display:inline-block;margin:2px 4px 2px 0;background:#1A191E;color:#fff;padding:2px 6px;border-radius:2px;}
			.g2ab-em__brand-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px;}
			.g2ab-em__actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
		</style>

		<?php echo $msg; // phpcs:ignore ?>

		<h2 style="margin-top:24px;">Email Branding (applies to all templates)</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'g2ab_save_email_settings', '_g2ab_nonce' ); ?>
			<input type="hidden" name="action" value="g2ab_save_email_settings" />
			<input type="hidden" name="scope" value="brand" />
			<div class="g2ab-em__brand-row">
				<div class="g2ab-em__row">
					<label>From Name</label>
					<input type="text" name="from_name" value="<?php echo esc_attr( get_option( G2AB_Email_Engine::OPTION_FROM_NAME, g2ab_business_name() ) ); ?>" />
				</div>
				<div class="g2ab-em__row">
					<label>From Email</label>
					<input type="email" name="from_addr" value="<?php echo esc_attr( get_option( G2AB_Email_Engine::OPTION_FROM_ADDR, get_option( 'admin_email' ) ) ); ?>" />
				</div>
				<div class="g2ab-em__row">
					<label>Logo URL (paste media library URL)</label>
					<input type="url" name="logo_url" value="<?php echo esc_attr( get_option( G2AB_Email_Engine::OPTION_LOGO_URL, '' ) ); ?>" placeholder="https://yoursite.com/wp-content/uploads/logo.png" />
				</div>
				<div class="g2ab-em__row">
					<label>Brand Color (hex)</label>
					<input type="text" name="brand_color" value="<?php echo esc_attr( get_option( G2AB_Email_Engine::OPTION_BRAND_HEX, '#E8802F' ) ); ?>" />
				</div>
				<div class="g2ab-em__row">
					<label>Review URL (e.g. your Google review link)</label>
					<input type="url" name="review_url" value="<?php echo esc_attr( get_option( G2AB_Email_Engine::OPTION_REVIEW_URL, '' ) ); ?>" placeholder="https://search.google.com/local/writereview?placeid=YOUR_PLACE_ID" />
					<p class="description" style="margin:6px 0 0;">The &quot;Leave a review&quot; button in the post-visit email only renders when a valid http(s) URL is saved here.</p>
				</div>
			</div>
			<div class="g2ab-em__row">
				<label>Footer HTML (optional)</label>
				<textarea name="footer_html" style="min-height:80px;"><?php echo esc_textarea( get_option( G2AB_Email_Engine::OPTION_FOOTER, '' ) ); ?></textarea>
			</div>
			<button class="button button-primary">Save Branding</button>
		</form>

		<hr style="margin:32px 0;" />

		<h2>Email Templates</h2>

		<div class="g2ab-em__layout">
			<aside class="g2ab-em__sidebar">
				<?php foreach ( $labels as $key => $label ) :
					$tpl_data = $engine->get_template( $key );
					$enabled = ! empty( $tpl_data['enabled'] );
				?>
					<a class="g2ab-em__event <?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'email_automation', 'event' => $key ), admin_url( 'admin.php' ) ) ); ?>">
						<span><?php echo esc_html( $label ); ?></span>
						<span class="g2ab-em__pill <?php echo $enabled ? '' : 'g2ab-em__pill--off'; ?>"><?php echo $enabled ? 'ON' : 'OFF'; ?></span>
					</a>
				<?php endforeach; ?>
			</aside>

			<section class="g2ab-em__panel">
				<h2><?php echo esc_html( $labels[ $active ] ); ?></h2>

				<div class="g2ab-em__tags">
					<strong>Available merge tags:</strong>
					<code>{customer_name}</code> <code>{customer_email}</code> <code>{customer_phone}</code> <code>{uuid}</code>
					<code>{confirmation_number}</code> <code>{resource_name}</code> <code>{service_name}</code>
					<code>{start_at}</code> <code>{end_at}</code> <code>{duration}</code> <code>{party_size}</code>
					<code>{amount_formatted}</code> <code>{due_now_formatted}</code> <code>{balance_due_formatted}</code>
					<code>{paid_amount_formatted}</code> <code>{currency}</code>
					<code>{invoice_url}</code> <code>{pay_url}</code> <code>{complete_payment_url}</code>
					<code>{view_booking_url}</code> <code>{cancel_url}</code> <code>{reschedule_url}</code> <code>{review_url}</code>
					<code>{business_name}</code> <code>{business_phone}</code> <code>{business_address}</code>
					<code>{brand_color}</code> <code>{brand_logo_url}</code> <code>{site_url}</code>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'g2ab_save_email_settings', '_g2ab_nonce' ); ?>
					<input type="hidden" name="action" value="g2ab_save_email_settings" />
					<input type="hidden" name="scope" value="template" />
					<input type="hidden" name="event" value="<?php echo esc_attr( $active ); ?>" />

					<div class="g2ab-em__row">
						<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $tpl['enabled'] ) ); ?> /> Enable this email</label>
					</div>
					<div class="g2ab-em__row">
						<label>Send to:</label>
						<label style="font-weight:normal;display:inline-block;margin-right:16px;"><input type="checkbox" name="recipient_customer" value="1" <?php checked( ! empty( $tpl['recipient_customer'] ) ); ?> /> Customer</label>
						<label style="font-weight:normal;display:inline-block;"><input type="checkbox" name="recipient_admin" value="1" <?php checked( ! empty( $tpl['recipient_admin'] ) ); ?> /> Admin/Staff</label>
					</div>
					<div class="g2ab-em__row">
						<label>Subject</label>
						<input type="text" name="subject" value="<?php echo esc_attr( $tpl['subject'] ?? '' ); ?>" />
					</div>
					<div class="g2ab-em__row">
						<label>Body (HTML)</label>
						<textarea name="body_html"><?php echo esc_textarea( $tpl['body_html'] ?? '' ); ?></textarea>
					</div>
					<button class="button button-primary">Save Template</button>
				</form>

				<hr style="margin:24px 0;" />

				<div class="g2ab-em__actions">
					<a class="button" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener">Preview with sample data</a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex;gap:8px;align-items:center;">
						<?php wp_nonce_field( 'g2ab_send_test_email', '_g2ab_nonce' ); ?>
						<input type="hidden" name="action" value="g2ab_send_test_email" />
						<input type="hidden" name="event" value="<?php echo esc_attr( $active ); ?>" />
						<label style="margin:0;">Send test to:</label>
						<input type="email" name="test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" style="width:300px;" />
						<button class="button">Send Test</button>
					</form>
				</div>
			</section>
		</div>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_email_settings', '_g2ab_nonce' );

		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : '';

		if ( 'brand' === $scope ) {
			update_option( G2AB_Email_Engine::OPTION_FROM_NAME, sanitize_text_field( $_POST['from_name'] ?? '' ) );
			update_option( G2AB_Email_Engine::OPTION_FROM_ADDR, sanitize_email( $_POST['from_addr'] ?? '' ) );
			update_option( G2AB_Email_Engine::OPTION_LOGO_URL,  esc_url_raw( $_POST['logo_url'] ?? '' ) );
			update_option( G2AB_Email_Engine::OPTION_BRAND_HEX, sanitize_hex_color( $_POST['brand_color'] ?? '#E8802F' ) );
			update_option( G2AB_Email_Engine::OPTION_FOOTER,    wp_kses_post( $_POST['footer_html'] ?? '' ) );

			// Review URL: must be a valid, non-empty http(s) URL to be stored;
			// anything else clears the option (and the review CTA disappears).
			$review_raw = trim( (string) wp_unslash( $_POST['review_url'] ?? '' ) );
			$review_url = esc_url_raw( $review_raw );
			$args = array( 'page' => 'g2ab-settings', 'tab' => 'email_automation', 'saved' => 1 );
			if ( '' !== $review_url && preg_match( '#^https?://#i', $review_url ) ) {
				update_option( G2AB_Email_Engine::OPTION_REVIEW_URL, $review_url );
			} else {
				update_option( G2AB_Email_Engine::OPTION_REVIEW_URL, '' );
				if ( '' !== $review_raw ) {
					$args['review_url_invalid'] = 1;
				}
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'template' === $scope ) {
			$event = sanitize_key( $_POST['event'] ?? '' );
			if ( ! $event ) wp_die( 'Invalid event.' );
			$engine = new G2AB_Email_Engine();
			$engine->save_template( $event, array(
				'enabled'            => ! empty( $_POST['enabled'] ) ? 1 : 0,
				'recipient_customer' => ! empty( $_POST['recipient_customer'] ) ? 1 : 0,
				'recipient_admin'    => ! empty( $_POST['recipient_admin'] ) ? 1 : 0,
				'subject'            => sanitize_text_field( $_POST['subject'] ?? '' ),
				'body_html'          => wp_kses_post( wp_unslash( $_POST['body_html'] ?? '' ) ),
			) );
			wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'email_automation', 'event' => $event, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'email_automation' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Build the fully-fake sample booking + merge tags used by both the
	 * test send and the preview. The id is 0 and is_test is set so
	 * G2AB_Email_Engine::build_tags() NEVER queries or merges a real row —
	 * a test can never collide with (or leak) live customer data.
	 *
	 * @param G2AB_Email_Engine $engine Engine instance.
	 * @param string            $event  Template/event id.
	 * @param string            $to     Recipient (used as sample customer email).
	 * @return array Merge tags.
	 */
	private function build_sample_tags( $engine, $event, $to ) {
		$uuid = wp_generate_uuid4();
		$fake = array(
			'id'             => 0,
			'is_test'        => 1,
			'uuid'           => $uuid,
			'status'         => 'confirmed',
			'payment_mode'   => 'full',
			'source'         => 'web',
			'resource_name'  => 'Lane 03 (Tactical)',
			'type_name'      => 'Range Session',
			'start_at'       => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day 18:00' ) ),
			'end_at'         => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day 19:00' ) ),
			'duration_min'   => 60,
			'party_size'     => 2,
			'total_amount'   => 89.00,
			'paid_amount'    => 89.00,
			'currency'       => strtoupper( (string) get_option( 'g2ab_currency', 'USD' ) ),
			'customer_name'  => 'Test Customer',
			'customer_email' => $to,
			'customer_phone' => '(555) 123-4567',
		);

		$context = array( 'source_event' => 'test' );
		if ( in_array( $event, array( 'payment_required' ), true ) ) {
			// A sample "live" checkout URL so the pay CTA renders in
			// previews/tests. The action-page URL is safe: it validates the
			// token server-side and this UUID matches no real booking.
			$fake['paid_amount']            = 0.00;
			$fake['status']                 = 'pending';
			$context['pay_url']             = class_exists( 'G2AB_Email_Actions' ) ? G2AB_Email_Actions::url( $uuid, 'pay' ) : home_url( '/' );
			$context['checkout_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
			$context['due_now']             = 89.00;
		}
		if ( 'pay_in_store_reservation' === $event ) {
			$fake['paid_amount']  = 0.00;
			$fake['status']       = 'reserved';
			$fake['payment_mode'] = 'in_store';
			$fake['source']       = 'frontdesk';
		}

		return $engine->build_tags( $fake, $context );
	}

	public function send_test() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_send_test_email', '_g2ab_nonce' );

		$event = sanitize_key( $_POST['event'] ?? 'booking_created' );
		$to    = sanitize_email( $_POST['test_to'] ?? '' );
		if ( ! $to ) wp_die( 'Invalid email.' );

		$engine = new G2AB_Email_Engine();
		$tpl    = $engine->get_template( $event );
		if ( $tpl ) {
			$tags = $this->build_sample_tags( $engine, $event, $to );
			$subj = '[TEST] ' . $engine->merge( $tpl['subject'], $tags );
			$body = $engine->merge( $tpl['body_html'], $tags );
			$engine->send_custom( $to, $subj, $body );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'email_automation', 'event' => $event, 'test_sent' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the fully-branded template with sample data in the browser so
	 * staff can proof every template without dispatching a message.
	 */
	public function preview() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_preview_email', '_g2ab_nonce' );

		$event  = sanitize_key( $_GET['event'] ?? 'booking_created' );
		$engine = new G2AB_Email_Engine();
		$tpl    = $engine->get_template( $event );
		if ( ! $tpl ) wp_die( 'Unknown template.' );

		$tags = $this->build_sample_tags( $engine, $event, wp_get_current_user()->user_email );
		$subj = $engine->merge( $tpl['subject'], $tags );
		$body = $engine->merge( $tpl['body_html'], $tags );
		$html = $engine->wrap_html( $body, $subj );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — full email document built from escaped fragments by the engine.
		exit;
	}
}
