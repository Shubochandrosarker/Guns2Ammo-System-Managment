<?php
/**
 * Owner-facing WP-admin settings screen.
 *
 * Lets an owner store the credentials the plugin needs without touching
 * WP-CLI:
 *
 *   - Google service-account JSON (shared by GSC + GA4)
 *   - GSC site URL
 *   - GA4 property id
 *   - Anthropic API key
 *
 * Only redacted status ("configured" / "not set") is ever rendered — the
 * plaintext values live in Secrets and never come back out. The page also
 * offers a "Regenerate insights now" button so an owner doesn't have to
 * wait for the hourly cron after a fresh install.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Admin;

use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\Integrations\Google_Service_Account;
use WordPressistic\G2ABA\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {
	private const NONCE_ACTION = 'g2aba_settings_save';
	private const NONCE_NAME   = '_g2aba_nonce';
	private const PAGE_SLUG    = 'g2a-business-api';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_g2aba_settings_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'G2A Business API', 'g2a-business-api' ),
			__( 'G2A Business API', 'g2a-business-api' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'g2a-business-api' ) );
		}

		$saved_flash = isset( $_GET['saved'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['saved'] ) ) : '';
		$error_flash = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';

		$google_ok    = ( new Google_Service_Account() )->is_configured();
		$gsc_site     = (string) get_option( 'g2aba_gsc_site_url', '' );
		$ga4_property = (string) get_option( 'g2aba_ga4_property_id', '' );
		$anthropic_ok = Secrets::has( 'model:anthropic-primary' );

		$insights_last_run   = (string) get_option( 'g2aba_insights_last_run', '' );
		$insights_last_error = (string) get_option( 'g2aba_insights_last_error', '' );
		$gsc_last_error      = (string) get_option( 'g2aba_gsc_last_error', '' );
		$ga4_last_error      = (string) get_option( 'g2aba_ga4_last_error', '' );

		$form_url = esc_url( admin_url( 'admin-post.php' ) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'G2A Business API', 'g2a-business-api' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Store the credentials the plugin uses to talk to Google Search Console, Google Analytics 4, and Anthropic. Only "configured" state is ever displayed — plaintext values are stored encrypted at rest.', 'g2a-business-api' ); ?>
			</p>

			<?php if ( '1' === $saved_flash ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'g2a-business-api' ); ?></p></div>
			<?php elseif ( '' !== $error_flash ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error_flash ); ?></p></div>
			<?php endif; ?>

			<form action="<?php echo $form_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>" method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="g2aba_settings_save" />

				<h2><?php esc_html_e( 'Google (Search Console + Analytics 4)', 'g2a-business-api' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Service-account JSON', 'g2a-business-api' ); ?></th>
						<td>
							<textarea name="google_service_account_json" rows="4" cols="60" class="large-text code" placeholder="Paste the service-account JSON key. Leave blank to keep the current value."></textarea>
							<p class="description">
								<?php
								echo $google_ok
									? '<span style="color:#2f855a;">' . esc_html__( '✔ Service-account credential is stored.', 'g2a-business-api' ) . '</span>'
									: '<span style="color:#b32d2e;">' . esc_html__( '✖ Not configured.', 'g2a-business-api' ) . '</span>';
								?>
								<label style="display:inline-block; margin-left: 16px;">
									<input type="checkbox" name="clear_google" value="1" />
									<?php esc_html_e( 'Clear stored credential', 'g2a-business-api' ); ?>
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="g2aba_gsc_site_url"><?php esc_html_e( 'GSC site URL', 'g2a-business-api' ); ?></label></th>
						<td>
							<input id="g2aba_gsc_site_url" name="gsc_site_url" type="text" class="regular-text" value="<?php echo esc_attr( $gsc_site ); ?>" placeholder="sc-domain:guns2ammo.com" />
							<p class="description">
								<?php esc_html_e( 'Use the exact identifier from your GSC property (either "sc-domain:example.com" or "https://example.com/").', 'g2a-business-api' ); ?>
								<?php if ( '' !== $gsc_last_error ) : ?>
									<br /><span style="color:#b32d2e;"><?php echo esc_html( sprintf( 'Last GSC error: %s', $gsc_last_error ) ); ?></span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="g2aba_ga4_property_id"><?php esc_html_e( 'GA4 property id', 'g2a-business-api' ); ?></label></th>
						<td>
							<input id="g2aba_ga4_property_id" name="ga4_property_id" type="text" class="regular-text" value="<?php echo esc_attr( $ga4_property ); ?>" placeholder="412896xxxx" />
							<p class="description">
								<?php esc_html_e( 'Numeric GA4 property id (Admin → Property Settings). The service-account email must be added as a viewer.', 'g2a-business-api' ); ?>
								<?php if ( '' !== $ga4_last_error ) : ?>
									<br /><span style="color:#b32d2e;"><?php echo esc_html( sprintf( 'Last GA4 error: %s', $ga4_last_error ) ); ?></span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Anthropic (insight generator)', 'g2a-business-api' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'API key', 'g2a-business-api' ); ?></th>
						<td>
							<input name="anthropic_api_key" type="password" class="regular-text" placeholder="<?php echo $anthropic_ok ? 'sk-ant-****' : 'sk-ant-...'; ?>" autocomplete="off" />
							<p class="description">
								<?php
								echo $anthropic_ok
									? '<span style="color:#2f855a;">' . esc_html__( '✔ Anthropic key is stored.', 'g2a-business-api' ) . '</span>'
									: '<span style="color:#b32d2e;">' . esc_html__( '✖ Not configured.', 'g2a-business-api' ) . '</span>';
								?>
								<label style="display:inline-block; margin-left: 16px;">
									<input type="checkbox" name="clear_anthropic" value="1" />
									<?php esc_html_e( 'Clear stored key', 'g2a-business-api' ); ?>
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Insight cron', 'g2a-business-api' ); ?></th>
						<td>
							<p>
								<?php
								echo '' === $insights_last_run
									? esc_html__( 'No insight cache generated yet.', 'g2a-business-api' )
									: esc_html( sprintf( 'Last successful run: %s (UTC)', $insights_last_run ) );
								?>
								<?php if ( '' !== $insights_last_error ) : ?>
									<br /><span style="color:#b32d2e;"><?php echo esc_html( sprintf( 'Last error: %s', $insights_last_error ) ); ?></span>
								<?php endif; ?>
							</p>
							<p>
								<label>
									<input type="checkbox" name="regenerate_now" value="1" />
									<?php esc_html_e( 'Regenerate insights on save', 'g2a-business-api' ); ?>
								</label>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'g2a-business-api' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$errors = array();

		// GSC site URL.
		$gsc_site = isset( $_POST['gsc_site_url'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['gsc_site_url'] ) ) : '';
		update_option( 'g2aba_gsc_site_url', $gsc_site, false );

		// GA4 property id.
		$ga4 = isset( $_POST['ga4_property_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ga4_property_id'] ) ) : '';
		// Strip anything that isn't a digit — GA4 property ids are numeric.
		$ga4 = preg_replace( '/[^0-9]/', '', $ga4 );
		update_option( 'g2aba_ga4_property_id', (string) $ga4, false );

		// Google service-account credential (JSON textarea) + optional clear.
		if ( ! empty( $_POST['clear_google'] ) ) {
			Secrets::forget( Google_Service_Account::SECRET_ID );
			Secrets::forget( Google_Service_Account::LEGACY_GSC_SECRET );
		} elseif ( ! empty( $_POST['google_service_account_json'] ) ) {
			$json    = wp_unslash( (string) $_POST['google_service_account_json'] );
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) && ! empty( $decoded['client_email'] ) && ! empty( $decoded['private_key'] ) ) {
				Secrets::put( Google_Service_Account::SECRET_ID, $json );
			} else {
				$errors[] = __( 'Google service-account JSON was invalid — must include client_email and private_key.', 'g2a-business-api' );
			}
		}

		// Anthropic key + optional clear.
		if ( ! empty( $_POST['clear_anthropic'] ) ) {
			Secrets::forget( 'model:anthropic-primary' );
		} elseif ( ! empty( $_POST['anthropic_api_key'] ) ) {
			$key = trim( wp_unslash( (string) $_POST['anthropic_api_key'] ) );
			if ( '' !== $key ) {
				Secrets::put( 'model:anthropic-primary', $key );
			}
		}

		// Optional immediate regeneration — schedule for 5s from now so the
		// admin request itself finishes fast.
		if ( ! empty( $_POST['regenerate_now'] ) ) {
			wp_schedule_single_event( time() + 5, 'g2aba_generate_insights' );
		}

		// Make sure the current admin still holds the dashboard cap. Rewriting
		// on save is cheap and self-heals if the capability was ever revoked.
		Capabilities::grant_defaults();

		$redirect = add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'saved' => empty( $errors ) ? '1' : '0',
				'error' => empty( $errors ) ? false : implode( ' ', $errors ),
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
