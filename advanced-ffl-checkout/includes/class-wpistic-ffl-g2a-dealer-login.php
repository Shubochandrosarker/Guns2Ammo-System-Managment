<?php
/**
 * G2A — Persistent dealer portal login + self-service (gap #12).
 *
 * The existing `Portal` class is a single-use magic-link flow: every
 * shipment notification issues a fresh signed token, and once it's
 * consumed the dealer needs a new one. That flow is NOT being replaced —
 * it stays the default and the only option for dealers who were never
 * invited here. This adds an alternative for dealers who want to just log
 * in whenever, using a real WordPress user account (role `ffl_dealer`)
 * linked to their `dealers` row via `dealers.wp_user_id`.
 *
 * An `ffl_dealer` account has no wp-admin access at all (redirected to the
 * front-end portal page instead) and can only ever see/act on transfers
 * where `dealer_id` matches their own linked dealer row — enforced on
 * every AJAX call, not just in the UI.
 *
 * Self-service actions (mark received / report issue / not my shipment)
 * reuse Portal::update_transfer_status()/notify_admin() so a logged-in
 * confirmation has exactly the same downstream effects (status change,
 * events row, wpistic_ffl_transfer_status_changed action -> mailer/NICS/
 * ad-ledger/etc.) as a magic-link confirmation. It's logged under distinct
 * `*_selfservice` analytics event types so it doesn't get folded into the
 * magic-link "confirmation rate" funnel metric, which is denominated on
 * tokens issued -- a self-service login never issues one.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Dealer_Login {

	const ROLE        = 'ffl_dealer';
	const SHORTCODE    = 'ffl_dealer_portal';
	const PAGE_OPTION = 'wpistic_ffl_dealer_portal_page_id';

	public function __construct() {
		add_action( 'init', [ __CLASS__, 'maybe_register_role' ] );
		add_shortcode( self::SHORTCODE, [ __CLASS__, 'render_shortcode' ] );

		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 47 );
		add_action( 'wp_ajax_wpistic_ffl_dealer_login_invite',       [ __CLASS__, 'ajax_invite' ] );
		add_action( 'wp_ajax_wpistic_ffl_dealer_login_revoke',       [ __CLASS__, 'ajax_revoke' ] );
		add_action( 'wp_ajax_wpistic_ffl_dealer_login_create_page',  [ __CLASS__, 'ajax_create_page' ] );

		// Logged-in dealer actions -- no _nopriv variant, a dealer must be authenticated.
		add_action( 'wp_ajax_wpistic_ffl_dealer_portal_action',       [ __CLASS__, 'ajax_dealer_action' ] );
		add_action( 'wp_ajax_wpistic_ffl_dealer_portal_save_contact', [ __CLASS__, 'ajax_save_contact' ] );

		add_action( 'admin_init', [ __CLASS__, 'block_wp_admin_for_dealers' ] );
		add_filter( 'login_redirect', [ __CLASS__, 'redirect_dealers_after_login' ], 10, 3 );
	}

	// ── Role ────────────────────────────────────────────────────────────────

	/**
	 * Runs on every `init` (cheap, idempotent) rather than only on
	 * activation, since a plugin ZIP upgrade doesn't re-run
	 * register_activation_hook() -- same reasoning as
	 * wpistic_ffl_ensure_default_options() in the main bootstrap file.
	 */
	public static function maybe_register_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'FFL Dealer (Portal)', 'advanced-ffl-checkout' ), [ 'read' => true ] );
		}
	}

	/** No wp-admin surface for a dealer-only account -- send them to their portal page instead. */
	public static function block_wp_admin_for_dealers(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( $user && $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( self::portal_page_url() );
			exit;
		}
	}

	/** @param \WP_User|\WP_Error $user */
	public static function redirect_dealers_after_login( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( $user instanceof \WP_User && in_array( self::ROLE, (array) $user->roles, true ) ) {
			return self::portal_page_url();
		}
		return $redirect_to;
	}

	private static function portal_page_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION );
		if ( $page_id && get_post( $page_id ) ) {
			return get_permalink( $page_id );
		}
		return home_url( '/' );
	}

	private static function dealer_for_user( int $user_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE wp_user_id = %d', $user_id
		) );
	}

	// ── Front-end shortcode ───────────────────────────────────────────────

	public static function render_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return self::render_login_form();
		}

		$user = wp_get_current_user();
		if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return '<p>' . esc_html__( 'This account is not linked to an FFL dealer portal.', 'advanced-ffl-checkout' )
				. ' <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'advanced-ffl-checkout' ) . '</a></p>';
		}

		$dealer = self::dealer_for_user( $user->ID );
		if ( ! $dealer ) {
			return '<p>' . esc_html__( 'No dealer record is currently linked to this account. Contact the store to reconnect your portal access.', 'advanced-ffl-checkout' )
				. ' <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'advanced-ffl-checkout' ) . '</a></p>';
		}

		return self::render_dashboard( $dealer );
	}

	private static function render_login_form(): string {
		ob_start();
		?>
		<div class="wpistic-ffl-dealer-login" style="max-width:380px;">
			<h3><?php esc_html_e( 'Dealer Portal Login', 'advanced-ffl-checkout' ); ?></h3>
			<?php
			wp_login_form( [
				'redirect'       => isset( $_SERVER['REQUEST_URI'] ) ? home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : home_url( '/' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'label_username' => __( 'Email or Username', 'advanced-ffl-checkout' ),
			] );
			?>
			<p><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot your password?', 'advanced-ffl-checkout' ); ?></a></p>
			<p class="description"><?php esc_html_e( "Don't have a login yet? Ask the store to invite you from their Dealer Logins page, or use the confirmation link from your shipment email.", 'advanced-ffl-checkout' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_dashboard( object $dealer ): string {
		global $wpdb;
		$transfers = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM " . DB::table( 'transfers' ) . "
			 WHERE dealer_id = %d AND status NOT IN ('transferred','cancelled','nics_denied')
			 ORDER BY created_at DESC LIMIT 100",
			(int) $dealer->id
		) );

		$nonce = wp_create_nonce( 'wpistic_ffl_dealer_portal' );

		ob_start();
		?>
		<div class="wpistic-ffl-dealer-portal">
			<h2><?php echo esc_html( $dealer->business_name ); ?></h2>
			<p class="description"><?php echo esc_html( 'FFL ' . $dealer->license_number ); ?></p>

			<h3><?php esc_html_e( 'Contact Info', 'advanced-ffl-checkout' ); ?></h3>
			<form id="wpistic-ffl-dp-contact-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;max-width:520px;">
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Phone', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="phone" value="<?php echo esc_attr( $dealer->phone ); ?>">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Email', 'advanced-ffl-checkout' ); ?></label>
					<input type="email" name="email" value="<?php echo esc_attr( $dealer->email ); ?>">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-dp-contact-msg" style="font-weight:600;"></span>
			</form>

			<h3 style="margin-top:24px;"><?php esc_html_e( 'Your Active Transfers', 'advanced-ffl-checkout' ); ?></h3>
			<?php if ( empty( $transfers ) ) : ?>
				<p><?php esc_html_e( 'Nothing outstanding right now.', 'advanced-ffl-checkout' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Ref', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Item', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $transfers as $t ) : ?>
						<tr data-transfer="<?php echo (int) $t->id; ?>">
							<td><?php echo esc_html( $t->transfer_ref ); ?></td>
							<td><?php echo esc_html( $t->customer_name ); ?></td>
							<td><?php echo esc_html( $t->item_description ); ?></td>
							<td class="wpistic-ffl-dp-status"><?php echo esc_html( ucwords( str_replace( '_', ' ', $t->status ) ) ); ?></td>
							<td>
								<button type="button" class="button button-small wpistic-ffl-dp-act" data-action="mark_received"><?php esc_html_e( 'Mark Received', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button button-small wpistic-ffl-dp-act" data-action="report_issue"><?php esc_html_e( 'Report Issue', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button button-small wpistic-ffl-dp-act" data-action="not_my_shipment"><?php esc_html_e( 'Not Mine', 'advanced-ffl-checkout' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top:20px;"><a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'advanced-ffl-checkout' ); ?></a></p>
		</div>
		<script>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			function postJson(body) {
				body.append('nonce', nonce);
				return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function(r){ return r.json(); });
			}

			var contactForm = document.getElementById('wpistic-ffl-dp-contact-form');
			if (contactForm) {
				contactForm.addEventListener('submit', function(e){
					e.preventDefault();
					var msg = document.getElementById('wpistic-ffl-dp-contact-msg');
					var fd  = new FormData(contactForm);
					fd.append('action', 'wpistic_ffl_dealer_portal_save_contact');
					msg.textContent = 'Saving…'; msg.style.color = '#666';
					postJson(fd).then(function(j){
						msg.style.color = j.success ? '#16A34A' : '#DC2626';
						msg.textContent = j.success ? '✓ Saved' : '✗ ' + ((j.data && j.data.message) || 'Failed');
					});
				});
			}

			document.querySelectorAll('.wpistic-ffl-dp-act').forEach(function(btn){
				btn.addEventListener('click', function(){
					var row = btn.closest('tr');
					var transferId = row.dataset.transfer;
					var notes = '';
					if ('report_issue' === btn.dataset.action || 'not_my_shipment' === btn.dataset.action) {
						notes = window.prompt('Notes for this report (optional):', '') || '';
					}
					btn.disabled = true;
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_dealer_portal_action');
					fd.append('transfer_id', transferId);
					fd.append('portal_action', btn.dataset.action);
					fd.append('notes', notes);
					postJson(fd).then(function(j){
						if (j.success) {
							row.querySelector('.wpistic-ffl-dp-status').textContent = 'Updated';
							row.style.opacity = '0.5';
							row.querySelectorAll('button').forEach(function(b){ b.disabled = true; });
						} else {
							btn.disabled = false;
							alert((j.data && j.data.message) || 'Failed');
						}
					});
				});
			});
		})();
		</script>
		<?php
		return (string) ob_get_clean();
	}

	// ── Logged-in dealer AJAX actions ────────────────────────────────────

	public static function ajax_dealer_action(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
		}
		check_ajax_referer( 'wpistic_ffl_dealer_portal', 'nonce' );

		$user = wp_get_current_user();
		if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden.' ], 403 );
		}
		$dealer = self::dealer_for_user( $user->ID );
		if ( ! $dealer ) {
			wp_send_json_error( [ 'message' => 'No dealer linked to this account.' ], 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$transfer_id = (int) ( $_POST['transfer_id'] ?? 0 );
		$action      = sanitize_key( wp_unslash( $_POST['portal_action'] ?? '' ) );
		$notes       = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		// phpcs:enable

		$valid_actions = [ 'mark_received', 'report_issue', 'not_my_shipment' ];
		if ( ! in_array( $action, $valid_actions, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid action.' ], 400 );
		}

		global $wpdb;
		// Ownership check -- a dealer may only act on their OWN transfers,
		// enforced here regardless of what the UI shows.
		$owned = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d AND dealer_id = %d',
			$transfer_id, (int) $dealer->id
		) );
		if ( ! $owned ) {
			wp_send_json_error( [ 'message' => 'Transfer not found for this dealer.' ], 404 );
		}

		$new_status = match ( $action ) {
			'mark_received'                     => 'received_by_dealer',
			'report_issue', 'not_my_shipment'   => 'issue_reported',
			default                              => 'received_by_dealer',
		};

		Portal::update_transfer_status( $transfer_id, $new_status, $action, $notes );

		// Distinct event-type suffix -- see class docblock: this must not be
		// folded into the magic-link "confirmation rate" funnel metric.
		Analytics::log( ( 'mark_received' === $action ? 'confirmed' : $action ) . '_selfservice', [
			'transfer_id' => $transfer_id,
			'dealer_id'   => (int) $dealer->id,
			'metadata'    => [ 'notes' => $notes, 'via' => 'dealer_login', 'dealer_user' => $user->user_login ],
		] );

		$email_settings = get_option( 'wpistic_ffl_email_settings', [] );
		if ( 'mark_received' === $action && ! empty( $email_settings['admin_notify_on_confirm'] ) ) {
			Portal::notify_admin( $transfer_id, 'confirmed', $notes );
		}
		if ( in_array( $action, [ 'report_issue', 'not_my_shipment' ], true ) && ! empty( $email_settings['admin_notify_on_issue'] ) ) {
			Portal::notify_admin( $transfer_id, $action, $notes );
		}

		wp_send_json_success();
	}

	public static function ajax_save_contact(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Not logged in.' ], 401 );
		}
		check_ajax_referer( 'wpistic_ffl_dealer_portal', 'nonce' );

		$user = wp_get_current_user();
		if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden.' ], 403 );
		}
		$dealer = self::dealer_for_user( $user->ID );
		if ( ! $dealer ) {
			wp_send_json_error( [ 'message' => 'No dealer linked to this account.' ], 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		// phpcs:enable
		if ( $email && ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => 'Invalid email address.' ], 400 );
		}

		global $wpdb;
		$wpdb->update( DB::table( 'dealers' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'phone'      => $phone,
			'email'      => $email,
			'updated_at' => current_time( 'mysql' ),
		], [ 'id' => (int) $dealer->id ] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => 0,
			'event_type'  => 'dealer_self_service_contact_updated',
			'notes'       => 'Dealer #' . (int) $dealer->id . ' updated their own contact info via the self-service portal.',
			'actor'       => 'dealer-portal:' . $user->user_login,
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success();
	}

	// ── Admin: invite / revoke ────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Dealer Logins', 'advanced-ffl-checkout' ),
			__( '🔑 Dealer Logins', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-dealer-logins',
			[ $this, 'render_admin_page' ]
		);
	}

	public static function ajax_invite(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$dealer_id = (int) ( $_POST['dealer_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $dealer_id
		) );
		if ( ! $dealer ) {
			wp_send_json_error( [ 'message' => 'Dealer not found.' ], 404 );
		}
		if ( ! $dealer->email || ! is_email( $dealer->email ) ) {
			wp_send_json_error( [ 'message' => 'This dealer has no valid email on file -- add one before inviting.' ], 400 );
		}

		$user = ( $dealer->wp_user_id ) ? get_userdata( (int) $dealer->wp_user_id ) : false;
		if ( ! $user ) {
			$user = get_user_by( 'email', $dealer->email );
		}
		if ( ! $user ) {
			$user_id = wp_insert_user( [
				'user_login'   => sanitize_user( 'ffl-dealer-' . $dealer_id, true ),
				'user_email'   => $dealer->email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $dealer->business_name,
				'role'         => self::ROLE,
			] );
			if ( is_wp_error( $user_id ) ) {
				wp_send_json_error( [ 'message' => $user_id->get_error_message() ], 500 );
			}
			$user = get_userdata( $user_id );
		} elseif ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->add_role( self::ROLE );
		}

		$wpdb->update( DB::table( 'dealers' ), [ 'wp_user_id' => $user->ID ], [ 'id' => $dealer_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			wp_send_json_error( [ 'message' => $key->get_error_message() ], 500 );
		}
		$reset_url = add_query_arg( [
			'action' => 'rp',
			'key'    => $key,
			'login'  => rawurlencode( $user->user_login ),
		], wp_login_url() );

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] Set up your dealer portal login', $site_name );
		$body      = '<p>' . sprintf(
				/* translators: %s: site/store name */
				esc_html__( '%s has invited you to a self-service dealer portal where you can view and confirm your FFL transfers any time, without waiting for a new confirmation email link.', 'advanced-ffl-checkout' ),
				esc_html( $site_name )
			) . '</p>'
			. '<p><a href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Set your password and log in', 'advanced-ffl-checkout' ) . '</a></p>'
			. '<p>' . esc_html__( 'This link expires in 24 hours, the same as any WordPress password-reset link.', 'advanced-ffl-checkout' ) . '</p>';

		wp_mail( $dealer->email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => 0,
			'event_type'  => 'dealer_login_invited',
			'notes'       => 'Self-service portal login invite sent to dealer #' . $dealer_id . ' (' . $dealer->email . ').',
			'actor'       => wp_get_current_user()->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success( [ 'user_login' => $user->user_login ] );
	}

	public static function ajax_revoke(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$dealer_id = (int) ( $_POST['dealer_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $dealer_id
		) );
		if ( ! $dealer ) {
			wp_send_json_error( [ 'message' => 'Dealer not found.' ], 404 );
		}

		if ( $dealer->wp_user_id ) {
			$user = get_userdata( (int) $dealer->wp_user_id );
			if ( $user ) {
				$user->remove_role( self::ROLE );
			}
		}
		$wpdb->update( DB::table( 'dealers' ), [ 'wp_user_id' => null ], [ 'id' => $dealer_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => 0,
			'event_type'  => 'dealer_login_revoked',
			'notes'       => 'Self-service portal login access revoked for dealer #' . $dealer_id . '.',
			'actor'       => wp_get_current_user()->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success();
	}

	public static function ajax_create_page(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$existing_id = (int) get_option( self::PAGE_OPTION );
		if ( $existing_id && get_post( $existing_id ) ) {
			wp_send_json_success( [ 'page_id' => $existing_id, 'url' => get_permalink( $existing_id ) ] );
		}

		$page_id = wp_insert_post( [
			'post_title'   => __( 'Dealer Portal', 'advanced-ffl-checkout' ),
			'post_content' => '[' . self::SHORTCODE . ']',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		], true );
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			wp_send_json_error( [ 'message' => is_wp_error( $page_id ) ? $page_id->get_error_message() : 'Could not create page.' ], 500 );
		}
		update_option( self::PAGE_OPTION, $page_id );
		wp_send_json_success( [ 'page_id' => $page_id, 'url' => get_permalink( $page_id ) ] );
	}

	public function render_admin_page(): void {
		global $wpdb;
		$nonce  = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per_page = 20;

		$where  = 'WHERE 1=1';
		$params = [];
		if ( $search ) {
			$where   .= ' AND ( business_name LIKE %s OR license_number LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$sql_base = 'FROM ' . DB::table( 'dealers' ) . ' ' . $where;
		$total    = (int) $wpdb->get_var( $params ? $wpdb->prepare( 'SELECT COUNT(*) ' . $sql_base, $params ) : 'SELECT COUNT(*) ' . $sql_base ); // phpcs:ignore WordPress.DB
		$offset   = ( $paged - 1 ) * $per_page;
		$dealers  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * ' . $sql_base . ' ORDER BY business_name ASC LIMIT %d OFFSET %d',
			array_merge( $params, [ $per_page, $offset ] )
		) );

		$portal_page_id = (int) get_option( self::PAGE_OPTION );
		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🔑</span>
				<?php esc_html_e( 'Dealer Logins', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Optional persistent login for FFL dealers, as an alternative to the single-use magic-link confirmation email. A dealer with a login can sign in any time to see and act on their own transfers -- the magic-link flow keeps working exactly as before either way.', 'advanced-ffl-checkout' ); ?>
			</p>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
				<?php if ( $portal_page_id && get_post( $portal_page_id ) ) : ?>
					<p>✓ <?php esc_html_e( 'Dealer portal page:', 'advanced-ffl-checkout' ); ?>
						<a href="<?php echo esc_url( get_permalink( $portal_page_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $portal_page_id ) ); ?></a>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'No dealer portal page yet -- create one to enable logins (adds the [ffl_dealer_portal] shortcode to a new published page).', 'advanced-ffl-checkout' ); ?></p>
					<button type="button" class="button button-primary" id="wpistic-ffl-dl-create-page"><?php esc_html_e( 'Create Dealer Portal Page', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-dl-create-page-msg" style="font-weight:600;"></span>
				<?php endif; ?>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<form method="get" style="margin-bottom:12px;">
					<input type="hidden" name="page" value="wpistic-ffl-dealer-logins">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search business name or license #', 'advanced-ffl-checkout' ); ?>" style="width:280px;">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'advanced-ffl-checkout' ); ?></button>
				</form>

				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Email', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Login Status', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $dealers as $d ) :
						$linked_user = $d->wp_user_id ? get_userdata( (int) $d->wp_user_id ) : false;
						?>
						<tr>
							<td><strong><?php echo esc_html( $d->business_name ); ?></strong><br><code><?php echo esc_html( $d->license_number ); ?></code></td>
							<td><?php echo esc_html( $d->email ?: '—' ); ?></td>
							<td>
								<?php if ( $linked_user ) : ?>
									<span style="color:#16A34A;font-weight:600;">✓ <?php echo esc_html( $linked_user->user_login ); ?></span>
								<?php else : ?>
									<span style="color:#9ca3af;"><?php esc_html_e( 'Not linked', 'advanced-ffl-checkout' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button button-small wpistic-ffl-dl-invite" data-dealer="<?php echo (int) $d->id; ?>"><?php echo $linked_user ? esc_html__( 'Resend Invite', 'advanced-ffl-checkout' ) : esc_html__( 'Invite', 'advanced-ffl-checkout' ); ?></button>
								<?php if ( $linked_user ) : ?>
									<button type="button" class="button button-small wpistic-ffl-dl-revoke" data-dealer="<?php echo (int) $d->id; ?>"><?php esc_html_e( 'Revoke', 'advanced-ffl-checkout' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $dealers ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No dealers found.', 'advanced-ffl-checkout' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php
				$total_pages = (int) ceil( $total / $per_page );
				if ( $total_pages > 1 ) :
					?>
					<div style="margin-top:14px;">
						<?php
						echo wp_kses_post( paginate_links( [
							'base'     => add_query_arg( 'paged', '%#%' ),
							'format'   => '',
							'current'  => $paged,
							'total'    => $total_pages,
							'add_args' => array_filter( [ 's' => $search ] ),
						] ) );
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function(){
			var pageNonce = <?php echo wp_json_encode( $nonce ); ?>;
			function postJson(body) {
				if (!body.has('nonce')) { body.append('nonce', pageNonce); }
				return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function(r){ return r.json(); });
			}

			document.querySelectorAll('.wpistic-ffl-dl-invite').forEach(function(btn){
				btn.addEventListener('click', function(){
					btn.disabled = true;
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_dealer_login_invite');
					fd.append('dealer_id', btn.dataset.dealer);
					postJson(fd).then(function(j){
						btn.disabled = false;
						if (j.success) { location.reload(); }
						else { alert((j.data && j.data.message) || 'Failed'); }
					});
				});
			});
			document.querySelectorAll('.wpistic-ffl-dl-revoke').forEach(function(btn){
				btn.addEventListener('click', function(){
					if (!confirm('Revoke this dealer\'s portal login access?')) { return; }
					btn.disabled = true;
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_dealer_login_revoke');
					fd.append('dealer_id', btn.dataset.dealer);
					postJson(fd).then(function(j){
						btn.disabled = false;
						if (j.success) { location.reload(); }
						else { alert((j.data && j.data.message) || 'Failed'); }
					});
				});
			});
			var createBtn = document.getElementById('wpistic-ffl-dl-create-page');
			if (createBtn) {
				createBtn.addEventListener('click', function(){
					createBtn.disabled = true;
					var msg = document.getElementById('wpistic-ffl-dl-create-page-msg');
					msg.textContent = 'Creating…'; msg.style.color = '#666';
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_dealer_login_create_page');
					postJson(fd).then(function(j){
						if (j.success) { location.reload(); }
						else { createBtn.disabled = false; msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
					});
				});
			}
		})();
		</script>
		<?php
	}
}
