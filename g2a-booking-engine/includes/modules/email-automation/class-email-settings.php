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
			.g2ab-em__row input[type=text],.g2ab-em__row textarea{width:100%;}
			.g2ab-em__row textarea{min-height:280px;font-family:Menlo,monospace;font-size:13px;}
			.g2ab-em__tags{background:#F4F5F7;padding:12px;border-left:3px solid #E8802F;font-size:12px;margin-bottom:18px;}
			.g2ab-em__tags code{display:inline-block;margin:2px 4px 2px 0;background:#1A191E;color:#fff;padding:2px 6px;border-radius:2px;}
			.g2ab-em__brand-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px;}
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
					<input type="text" name="from_name" value="<?php echo esc_attr( get_option( G2AB_Email_Engine::OPTION_FROM_NAME, get_option( 'g2ab_business_name' ) ) ); ?>" />
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
					<code>{resource_name}</code> <code>{start_at}</code> <code>{end_at}</code> <code>{duration}</code>
					<code>{party_size}</code> <code>{amount}</code> <code>{currency}</code> <code>{invoice_url}</code>
					<code>{pay_url}</code> <code>{cancel_url}</code> <code>{business_name}</code> <code>{business_phone}</code>
					<code>{business_address}</code> <code>{brand_color}</code> <code>{brand_logo_url}</code> <code>{site_url}</code>
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

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'g2ab_send_test_email', '_g2ab_nonce' ); ?>
					<input type="hidden" name="action" value="g2ab_send_test_email" />
					<input type="hidden" name="event" value="<?php echo esc_attr( $active ); ?>" />
					<label>Send test to:</label>
					<input type="email" name="test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" style="width:300px;" />
					<button class="button">Send Test</button>
				</form>
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
			wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'email_automation', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
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

	public function send_test() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_send_test_email', '_g2ab_nonce' );

		$event = sanitize_key( $_POST['event'] ?? 'booking_created' );
		$to    = sanitize_email( $_POST['test_to'] ?? '' );
		if ( ! $to ) wp_die( 'Invalid email.' );

		// Build a fake booking object for preview.
		$fake = (object) array(
			'id'           => 9999,
			'uuid'         => 'TEST-' . strtoupper( wp_generate_password( 8, false ) ),
			'resource_name' => 'Lane 03 (Tactical)',
			'start_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day 18:00' ) ),
			'end_at'       => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day 19:00' ) ),
			'duration_min' => 60,
			'party_size'   => 2,
			'amount'       => 89.00,
			'fields'       => json_encode( array(
				'name'  => 'Test Customer',
				'email' => $to,
				'phone' => '(555) 123-4567',
			) ),
		);

		$engine = new G2AB_Email_Engine();
		$tpl = $engine->get_template( $event );
		if ( $tpl ) {
			$tags = $engine->build_tags( $fake );
			$subj = '[TEST] ' . $engine->merge( $tpl['subject'], $tags );
			$body = $engine->merge( $tpl['body_html'], $tags );
			$engine->send_custom( $to, $subj, $body );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'email_automation', 'event' => $event, 'test_sent' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
