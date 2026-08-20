<?php
/**
 * The Referrals admin menu.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Admin;

use WordPressistic\G2AReferrals\Codes;
use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Events_Repository;
use WordPressistic\G2AReferrals\Database\Installer;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Database\Visits_Repository;
use WordPressistic\G2AReferrals\Rewards_Service;
use WordPressistic\G2AReferrals\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Menu {

	public const SLUG = 'g2ar-referrals';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_g2ar_save_settings', array( self::class, 'handle_save_settings' ) );
		add_action( 'admin_post_g2ar_manual_grant', array( self::class, 'handle_manual_grant' ) );
		add_action( 'admin_post_g2ar_referrer_status', array( self::class, 'handle_referrer_status' ) );
		add_action( 'admin_post_g2ar_conversion_action', array( self::class, 'handle_conversion_action' ) );
	}

	/**
	 * Capability required for the front-desk screens.
	 *
	 * @return string
	 */
	private static function staff_cap() {
		return Installer::STAFF_CAP;
	}

	/**
	 * Build the menu.
	 *
	 * @return void
	 */
	public static function menu() {
		$staff = self::staff_cap();

		add_menu_page(
			__( 'Referrals', 'g2a-referrals' ),
			__( 'Referrals', 'g2a-referrals' ),
			$staff,
			self::SLUG,
			array( self::class, 'render_overview' ),
			'dashicons-share',
			57
		);

		$pages = array(
			'overview'    => array( __( 'Overview', 'g2a-referrals' ), 'render_overview', $staff ),
			'referrers'   => array( __( 'Referrers', 'g2a-referrals' ), 'render_referrers', $staff ),
			'conversions' => array( __( 'Conversions', 'g2a-referrals' ), 'render_conversions', $staff ),
			'rewards'     => array( __( 'Rewards', 'g2a-referrals' ), 'render_rewards', $staff ),
			'front-desk'  => array( __( 'Front Desk', 'g2a-referrals' ), 'render_front_desk', $staff ),
			'audit'       => array( __( 'Audit Log', 'g2a-referrals' ), 'render_audit', 'manage_options' ),
			'settings'    => array( __( 'Settings', 'g2a-referrals' ), 'render_settings', 'manage_options' ),
		);

		foreach ( $pages as $slug => $page ) {
			list( $label, $callback, $cap ) = $page;

			add_submenu_page(
				self::SLUG,
				$label,
				$label,
				$cap,
				'overview' === $slug ? self::SLUG : self::SLUG . '-' . $slug,
				array( self::class, $callback )
			);
		}
	}

	/**
	 * Guard every screen.
	 *
	 * @param string $cap Required capability.
	 * @return void
	 */
	private static function guard( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'g2a-referrals' ), 403 );
		}
	}

	/**
	 * Overview: the money view.
	 *
	 * @return void
	 */
	public static function render_overview() {
		self::guard( self::staff_cap() );

		$counts = Conversions_Repository::counts_by_status();

		$passes_out = Rewards_Repository::outstanding_total( Rewards_Repository::TYPE_GUEST_PASS );
		$months_out = Rewards_Repository::outstanding_total( Rewards_Repository::TYPE_MEMBERSHIP_DAYS );

		$pass_value  = Settings::get_float( 'guest_pass_value' );
		$month_value = Settings::get_float( 'membership_month_value' );

		// Outstanding liability in dollars: what the business would owe if
		// every unspent reward were redeemed tomorrow.
		$liability = ( $passes_out * $pass_value ) + ( $months_out * $month_value );

		$total_conversions = array_sum( $counts );
		$clicks            = self::total_clicks();
		$conversion_rate   = $clicks > 0 ? ( $total_conversions / $clicks ) * 100 : 0;

		$granted   = self::ledger_total( Rewards_Repository::TYPE_GUEST_PASS, 'grant' );
		$redeemed  = self::ledger_total( Rewards_Repository::TYPE_GUEST_PASS, 'redeem' );
		$redemption_rate = $granted > 0 ? ( $redeemed / $granted ) * 100 : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Referrals', 'g2a-referrals' ); ?></h1>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;">
				<?php
				self::stat_card(
					__( 'Outstanding reward liability', 'g2a-referrals' ),
					self::money( $liability ),
					sprintf(
						/* translators: 1: unredeemed Guest Passes, 2: unredeemed free months. */
						__( '%1$s Guest Passes + %2$s free months unredeemed', 'g2a-referrals' ),
						number_format_i18n( $passes_out ),
						number_format_i18n( $months_out )
					)
				);
				self::stat_card( __( 'Referrals this month', 'g2a-referrals' ), (string) self::conversions_this_month(), '' );
				self::stat_card( __( 'Conversion rate', 'g2a-referrals' ), number_format_i18n( $conversion_rate, 1 ) . '%', __( 'Conversions per link click', 'g2a-referrals' ) );
				self::stat_card( __( 'Guest Pass redemption rate', 'g2a-referrals' ), number_format_i18n( $redemption_rate, 1 ) . '%', __( 'Passes spent of passes granted', 'g2a-referrals' ) );
				?>
			</div>

			<h2><?php esc_html_e( 'Conversions by status', 'g2a-referrals' ); ?></h2>
			<table class="widefat striped" style="max-width:520px;">
				<tbody>
					<?php foreach ( $counts as $status => $count ) : ?>
						<tr>
							<td><?php echo esc_html( ucfirst( $status ) ); ?></td>
							<td><?php echo esc_html( (string) $count ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Top referrers', 'g2a-referrals' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Member', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Code', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Referred', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Rewarded', 'g2a-referrals' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Referrers_Repository::search( array( 'limit' => 10 ) ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['display_name'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) $row['code'] ); ?></code></td>
							<td><?php echo esc_html( (string) (int) $row['total_referred'] ); ?></td>
							<td><?php echo esc_html( (string) (int) $row['total_rewarded'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Referrers list with suspend and manual grant.
	 *
	 * @return void
	 */
	public static function render_referrers() {
		self::guard( self::staff_cap() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$rows   = Referrers_Repository::search( array( 'search' => $search ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Referrers', 'g2a-referrals' ); ?></h1>

			<form method="get" style="margin:16px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-referrers' ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, email or code', 'g2a-referrals' ); ?>">
				<?php submit_button( __( 'Search', 'g2a-referrals' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Member', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Code', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Status', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Guest Passes', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'g2a-referrals' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['display_name'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) $row['code'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['status'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( Rewards_Repository::balance( (int) $row['user_id'], Rewards_Repository::TYPE_GUEST_PASS ) ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'g2ar_referrer_status' ); ?>
									<input type="hidden" name="action" value="g2ar_referrer_status">
									<input type="hidden" name="referrer_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
									<input type="hidden" name="status" value="<?php echo esc_attr( 'active' === $row['status'] ? 'suspended' : 'active' ); ?>">
									<button class="button button-small">
										<?php echo 'active' === $row['status'] ? esc_html__( 'Suspend', 'g2a-referrals' ) : esc_html__( 'Reinstate', 'g2a-referrals' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Manual grant', 'g2a-referrals' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A reason is required. Every adjustment is written to the append-only ledger and the audit chain under your name.', 'g2a-referrals' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'g2ar_manual_grant' ); ?>
				<input type="hidden" name="action" value="g2ar_manual_grant">
				<table class="form-table">
					<tr>
						<th><label for="g2ar-grant-user"><?php esc_html_e( 'Member', 'g2a-referrals' ); ?></label></th>
						<td><input id="g2ar-grant-user" type="text" name="user" class="regular-text" placeholder="<?php esc_attr_e( 'User ID, email or G2A-XXXXXX', 'g2a-referrals' ); ?>" required></td>
					</tr>
					<tr>
						<th><label for="g2ar-grant-type"><?php esc_html_e( 'Reward', 'g2a-referrals' ); ?></label></th>
						<td>
							<select id="g2ar-grant-type" name="reward_type">
								<option value="guest_pass"><?php esc_html_e( 'Guest Pass', 'g2a-referrals' ); ?></option>
								<option value="membership_days"><?php esc_html_e( 'Free month', 'g2a-referrals' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="g2ar-grant-amount"><?php esc_html_e( 'Amount', 'g2a-referrals' ); ?></label></th>
						<td><input id="g2ar-grant-amount" type="number" name="amount" value="1" min="1" step="1" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="g2ar-grant-reason"><?php esc_html_e( 'Reason', 'g2a-referrals' ); ?></label></th>
						<td><input id="g2ar-grant-reason" type="text" name="reason" class="regular-text" required></td>
					</tr>
				</table>
				<?php submit_button( __( 'Grant reward', 'g2a-referrals' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Conversions ledger with approve / reject / reverse.
	 *
	 * @return void
	 */
	public static function render_conversions() {
		self::guard( self::staff_cap() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$rows   = Conversions_Repository::search( array( 'status' => $status ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Conversions', 'g2a-referrals' ); ?></h1>

			<ul class="subsubsub">
				<?php foreach ( array_merge( array( '' ), Conversions_Repository::STATUSES ) as $option ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG . '-conversions', 'status' => $option ), admin_url( 'admin.php' ) ) ); ?>"
							<?php echo $option === $status ? ' class="current"' : ''; ?>>
							<?php echo esc_html( '' === $option ? __( 'All', 'g2a-referrals' ) : ucfirst( $option ) ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Friend', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Plan', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Paid', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Status', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'g2a-referrals' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $rows as $row ) :
						$referrer = Referrers_Repository::get( (int) $row['referrer_id'] );
						?>
						<tr>
							<td><?php echo esc_html( (string) $row['id'] ); ?></td>
							<td><code><?php echo esc_html( (string) ( $referrer['code'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( self::user_label( (int) $row['friend_user_id'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['plan_id'] ); ?></td>
							<td><?php echo esc_html( self::money( (float) $row['amount_paid'] ) ); ?></td>
							<td>
								<?php echo esc_html( (string) $row['status'] ); ?>
								<?php if ( ! empty( $row['reject_reason'] ) ) : ?>
									<br><small><?php echo esc_html( (string) $row['reject_reason'] ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'g2ar_conversion_action' ); ?>
									<input type="hidden" name="action" value="g2ar_conversion_action">
									<input type="hidden" name="conversion_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
									<input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason', 'g2a-referrals' ); ?>" size="18">
									<button class="button button-small" name="do" value="reject"><?php esc_html_e( 'Reject', 'g2a-referrals' ); ?></button>
									<button class="button button-small" name="do" value="reverse"><?php esc_html_e( 'Reverse', 'g2a-referrals' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Outstanding balances and the expiry forecast.
	 *
	 * @return void
	 */
	public static function render_rewards() {
		self::guard( self::staff_cap() );

		$passes = Rewards_Repository::outstanding_total( Rewards_Repository::TYPE_GUEST_PASS );
		$months = Rewards_Repository::outstanding_total( Rewards_Repository::TYPE_MEMBERSHIP_DAYS );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rewards', 'g2a-referrals' ); ?></h1>

			<table class="widefat striped" style="max-width:640px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reward', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Outstanding', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Unit value', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Liability', 'g2a-referrals' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Guest Passes', 'g2a-referrals' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $passes ) ); ?></td>
						<td><?php echo esc_html( self::money( Settings::get_float( 'guest_pass_value' ) ) ); ?></td>
						<td><?php echo esc_html( self::money( $passes * Settings::get_float( 'guest_pass_value' ) ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Free months', 'g2a-referrals' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $months ) ); ?></td>
						<td><?php echo esc_html( self::money( Settings::get_float( 'membership_month_value' ) ) ); ?></td>
						<td><?php echo esc_html( self::money( $months * Settings::get_float( 'membership_month_value' ) ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Expiry forecast', 'g2a-referrals' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Guest Passes reaching their expiry date in each window. Gross of anything redeemed before then, so treat it as an upper bound on what falls off the books.', 'g2a-referrals' ); ?>
			</p>
			<table class="widefat striped" style="max-width:420px;">
				<tbody>
					<?php foreach ( array( 7, 30, 90 ) as $days ) : ?>
						<tr>
							<td>
								<?php
								printf(
									/* translators: %d: number of days. */
									esc_html__( 'Next %d days', 'g2a-referrals' ),
									(int) $days
								);
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( Rewards_Repository::expiring_within( Rewards_Repository::TYPE_GUEST_PASS, $days ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Front desk: look up a code, see the member, act in person.
	 *
	 * A range runs on counter conversations — someone walks in holding a
	 * printed card and staff need the answer in one screen.
	 *
	 * @return void
	 */
	public static function render_front_desk() {
		self::guard( self::staff_cap() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		$query    = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$referrer = '' !== $query ? Referrers_Repository::get_by_code( $query ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Front Desk', 'g2a-referrals' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-front-desk' ); ?>">
				<input type="search" name="code" value="<?php echo esc_attr( $query ); ?>" class="regular-text"
					placeholder="<?php esc_attr_e( 'G2A-XXXXXX', 'g2a-referrals' ); ?>" autofocus>
				<?php submit_button( __( 'Look up', 'g2a-referrals' ), 'primary', '', false ); ?>
			</form>

			<?php if ( '' !== $query && ! $referrer ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'No member holds that code.', 'g2a-referrals' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $referrer ) : ?>
				<h2><?php echo esc_html( self::user_label( (int) $referrer['user_id'] ) ); ?></h2>
				<table class="widefat striped" style="max-width:560px;">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Code', 'g2a-referrals' ); ?></th>
							<td><code><?php echo esc_html( (string) $referrer['code'] ); ?></code></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'g2a-referrals' ); ?></th>
							<td><?php echo esc_html( (string) $referrer['status'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Guest Passes available', 'g2a-referrals' ); ?></th>
							<td><strong><?php echo esc_html( number_format_i18n( Rewards_Repository::balance( (int) $referrer['user_id'], Rewards_Repository::TYPE_GUEST_PASS ) ) ); ?></strong></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Next expiry', 'g2a-referrals' ); ?></th>
							<td><?php echo esc_html( (string) ( Rewards_Repository::next_expiry( (int) $referrer['user_id'], Rewards_Repository::TYPE_GUEST_PASS ) ?: '—' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Friends referred', 'g2a-referrals' ); ?></th>
							<td><?php echo esc_html( (string) (int) $referrer['total_referred'] ); ?></td>
						</tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Recent activity', 'g2a-referrals' ); ?></h3>
				<table class="widefat striped" style="max-width:720px;">
					<tbody>
						<?php foreach ( Rewards_Repository::history( (int) $referrer['user_id'], 10 ) as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
								<td><?php echo esc_html( (string) $row['direction'] ); ?></td>
								<td><?php echo esc_html( (string) $row['reward_type'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $row['amount'], 2 ) ); ?></td>
								<td><?php echo esc_html( (string) $row['note'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Audit log with chain verification.
	 *
	 * @return void
	 */
	public static function render_audit() {
		self::guard( 'manage_options' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$type   = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : '';
		$rows   = Events_Repository::search( array( 'event_type' => $type, 'limit' => 200 ) );
		$verify = Events_Repository::verify_chain( 5000 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Audit Log', 'g2a-referrals' ); ?></h1>

			<div class="notice <?php echo $verify['ok'] ? 'notice-success' : 'notice-error'; ?>">
				<p>
					<?php
					if ( $verify['ok'] ) {
						printf(
							/* translators: %d: number of rows checked. */
							esc_html__( 'Hash chain intact across %d rows.', 'g2a-referrals' ),
							(int) $verify['rows_checked']
						);
					} else {
						printf(
							/* translators: %s: comma-separated row ids. */
							esc_html__( 'Hash chain broken at rows: %s', 'g2a-referrals' ),
							esc_html( implode( ', ', $verify['broken'] ) )
						);
					}
					?>
				</p>
			</div>

			<form method="get" style="margin:16px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-audit' ); ?>">
				<input type="text" name="event_type" value="<?php echo esc_attr( $type ); ?>" placeholder="<?php esc_attr_e( 'Event type', 'g2a-referrals' ); ?>">
				<?php submit_button( __( 'Filter', 'g2a-referrals' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Event', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Object', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'g2a-referrals' ); ?></th>
						<th><?php esc_html_e( 'Payload', 'g2a-referrals' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><?php echo esc_html( (string) $row['event_type'] ); ?></td>
							<td><?php echo esc_html( trim( (string) $row['object_type'] . ' #' . (string) $row['object_id'] ) ); ?></td>
							<td><?php echo esc_html( self::user_label( (int) $row['actor_id'] ) ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( (string) $row['payload_json'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Settings: every value and every piece of customer-facing copy.
	 *
	 * @return void
	 */
	public static function render_settings() {
		self::guard( 'manage_options' );

		$settings = Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Referral Settings', 'g2a-referrals' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'g2ar_save_settings' ); ?>
				<input type="hidden" name="action" value="g2ar_save_settings">

				<h2><?php esc_html_e( 'Reward values', 'g2a-referrals' ); ?></h2>
				<table class="form-table">
					<?php
					self::number_field( 'friend_reward_periods', __( "Friend's free billing periods", 'g2a-referrals' ), $settings, __( 'Added to their first membership term.', 'g2a-referrals' ) );
					self::number_field( 'referrer_reward_amount', __( 'Guest Passes per referral', 'g2a-referrals' ), $settings, '' );
					self::number_field( 'guest_pass_expiry_days', __( 'Guest Pass expiry (days)', 'g2a-referrals' ), $settings, __( '0 = never expires. 90 days caps the liability sitting on the books and gives the reward urgency.', 'g2a-referrals' ) );
					self::number_field( 'referral_cap_per_month', __( 'Referral cap per member per month', 'g2a-referrals' ), $settings, __( '0 = no cap. Bounds lane capacity as well as abuse — every pass is a free hour someone has to staff.', 'g2a-referrals' ) );
					self::number_field( 'hold_window_days', __( 'Reversal hold window (days)', 'g2a-referrals' ), $settings, __( 'A refund or cancellation inside this window reverses both rewards.', 'g2a-referrals' ) );
					self::number_field( 'cookie_days', __( 'Attribution cookie (days)', 'g2a-referrals' ), $settings, '' );
					?>
				</table>

				<h2><?php esc_html_e( 'Liability accounting', 'g2a-referrals' ); ?></h2>
				<table class="form-table">
					<?php
					self::number_field( 'guest_pass_value', __( 'Guest Pass value ($)', 'g2a-referrals' ), $settings, __( 'Used only for the outstanding-liability figure.', 'g2a-referrals' ), '0.01' );
					self::number_field( 'membership_month_value', __( 'Free month value ($)', 'g2a-referrals' ), $settings, '', '0.01' );
					self::number_field( 'membership_price_floor', __( 'Membership price floor ($)', 'g2a-referrals' ), $settings, __( 'No offer may take a membership below this.', 'g2a-referrals' ), '0.01' );
					?>
				</table>

				<h2><?php esc_html_e( 'Fraud thresholds', 'g2a-referrals' ); ?></h2>
				<table class="form-table">
					<?php
					self::number_field( 'max_conversions_per_hash', __( 'Conversions per device before review', 'g2a-referrals' ), $settings, '' );
					self::number_field( 'max_conversions_per_day', __( 'Conversions per referrer per day before review', 'g2a-referrals' ), $settings, '' );
					self::number_field( 'rate_limit_attempts', __( 'Code lookups per window', 'g2a-referrals' ), $settings, '' );
					self::number_field( 'rate_limit_window_minutes', __( 'Rate-limit window (minutes)', 'g2a-referrals' ), $settings, '' );
					?>
				</table>

				<h2><?php esc_html_e( 'Product page', 'g2a-referrals' ); ?></h2>
				<table class="form-table">
					<?php
					self::toggle_field( 'try_at_range_enabled', __( 'Show "Try At Range" on FFL products', 'g2a-referrals' ), $settings, '' );
					self::toggle_field(
						'try_at_range_fee_credit',
						__( 'Offer lane fee toward the purchase', 'g2a-referrals' ),
						$settings,
						__( 'COMMERCIAL PROMISE — leave off until the owner has confirmed it. Off runs "See how it shoots before you commit." instead.', 'g2a-referrals' )
					);
					self::text_field( 'try_at_range_heading', __( 'Heading', 'g2a-referrals' ), $settings );
					self::text_field( 'try_at_range_body_safe', __( 'Body (no fee credit)', 'g2a-referrals' ), $settings );
					self::text_field( 'try_at_range_body_credit', __( 'Body (with fee credit)', 'g2a-referrals' ), $settings );
					self::text_field( 'try_at_range_cta', __( 'Button label', 'g2a-referrals' ), $settings );
					self::text_field( 'try_at_range_meta', __( 'Meta line', 'g2a-referrals' ), $settings );
					?>
				</table>

				<h2><?php esc_html_e( 'Promotional banner', 'g2a-referrals' ); ?></h2>
				<table class="form-table">
					<?php
					self::toggle_field( 'banner_enabled', __( 'Show the banner', 'g2a-referrals' ), $settings, '' );
					self::toggle_field(
						'first_order_offer_enabled',
						__( 'Advertise the first-order discount', 'g2a-referrals' ),
						$settings,
						__( 'Leave off until the offer is confirmed live and redeemable — the stacking rule needs a real offer to enforce against, and advertising a discount checkout cannot honour is worse than not advertising one.', 'g2a-referrals' )
					);
					self::number_field( 'first_order_offer_percent', __( 'First-order discount (%)', 'g2a-referrals' ), $settings, '' );
					self::number_field( 'banner_dismiss_days', __( 'Dismissal lasts (days)', 'g2a-referrals' ), $settings, '' );
					self::text_field( 'banner_guest_lead', __( 'Non-member lead', 'g2a-referrals' ), $settings );
					self::text_field( 'banner_guest_body_offer', __( 'Non-member body (offer live)', 'g2a-referrals' ), $settings, __( 'Use %1$s%% for the discount percentage.', 'g2a-referrals' ) );
					self::text_field( 'banner_guest_body_plain', __( 'Non-member body (no offer)', 'g2a-referrals' ), $settings );
					self::text_field( 'banner_guest_cta', __( 'Non-member button', 'g2a-referrals' ), $settings );
					self::text_field( 'banner_member_lead', __( 'Member lead', 'g2a-referrals' ), $settings );
					self::text_field( 'banner_member_body', __( 'Member body', 'g2a-referrals' ), $settings );
					self::text_field( 'banner_member_cta', __( 'Member button', 'g2a-referrals' ), $settings );
					self::text_field( 'terms_text', __( 'Rewards terms', 'g2a-referrals' ), $settings );
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ── Form helpers ──────────────────────────────────────────────── */

	/**
	 * @param string $key      Setting key.
	 * @param string $label    Field label.
	 * @param array  $settings All settings.
	 * @param string $help     Description.
	 * @param string $step     Input step.
	 * @return void
	 */
	private static function number_field( $key, $label, array $settings, $help = '', $step = '1' ) {
		?>
		<tr>
			<th><label for="g2ar-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input id="g2ar-<?php echo esc_attr( $key ); ?>" type="number" step="<?php echo esc_attr( $step ); ?>" min="0"
					name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) ( $settings[ $key ] ?? '' ) ); ?>" class="regular-text">
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param string $key      Setting key.
	 * @param string $label    Field label.
	 * @param array  $settings All settings.
	 * @param string $help     Description.
	 * @return void
	 */
	private static function text_field( $key, $label, array $settings, $help = '' ) {
		?>
		<tr>
			<th><label for="g2ar-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input id="g2ar-<?php echo esc_attr( $key ); ?>" type="text" name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( (string) ( $settings[ $key ] ?? '' ) ); ?>" class="large-text">
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param string $key      Setting key.
	 * @param string $label    Field label.
	 * @param array  $settings All settings.
	 * @param string $help     Description.
	 * @return void
	 */
	private static function toggle_field( $key, $label, array $settings, $help = '' ) {
		?>
		<tr>
			<th><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="yes" <?php checked( 'yes', $settings[ $key ] ?? 'no' ); ?>>
					<?php esc_html_e( 'Enabled', 'g2a-referrals' ); ?>
				</label>
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param string $label Card label.
	 * @param string $value Big number.
	 * @param string $note  Small print.
	 * @return void
	 */
	private static function stat_card( $label, $value, $note ) {
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;">
			<div style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#646970;"><?php echo esc_html( $label ); ?></div>
			<div style="font-size:28px;font-weight:600;margin-top:6px;"><?php echo esc_html( $value ); ?></div>
			<?php if ( $note ) : ?>
				<div style="font-size:12px;color:#646970;margin-top:4px;"><?php echo esc_html( $note ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ── Handlers ──────────────────────────────────────────────────── */

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		self::guard( 'manage_options' );
		check_admin_referer( 'g2ar_save_settings' );

		$values = array();

		foreach ( array_keys( Settings::defaults() ) as $key ) {
			$default = Settings::defaults()[ $key ];

			if ( in_array( $default, array( 'yes', 'no' ), true ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
				$values[ $key ] = isset( $_POST[ $key ] ) ? 'yes' : 'no';
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::update() sanitises by declared type.
			$values[ $key ] = wp_unslash( $_POST[ $key ] );
		}

		Settings::update( $values );

		Events_Repository::log( 'settings_saved', array( 'payload' => array( 'keys' => array_keys( $values ) ) ) );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) );
		exit;
	}

	/**
	 * Manual reward grant.
	 *
	 * @return void
	 */
	public static function handle_manual_grant() {
		self::guard( self::staff_cap() );
		check_admin_referer( 'g2ar_manual_grant' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$who    = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$type   = isset( $_POST['reward_type'] ) ? sanitize_key( wp_unslash( $_POST['reward_type'] ) ) : '';
		$amount = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$user_id = self::resolve_user( $who );

		if ( $user_id > 0 && $amount > 0 && '' !== $reason ) {
			Rewards_Service::manual_grant( $user_id, $type, $amount, $reason );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-referrers' ) );
		exit;
	}

	/**
	 * Suspend or reinstate a referrer.
	 *
	 * @return void
	 */
	public static function handle_referrer_status() {
		self::guard( self::staff_cap() );
		check_admin_referer( 'g2ar_referrer_status' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$id     = isset( $_POST['referrer_id'] ) ? absint( wp_unslash( $_POST['referrer_id'] ) ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $id > 0 ) {
			Referrers_Repository::set_status( $id, $status );
			Events_Repository::log(
				'referrer_status_changed',
				array(
					'referrer_id' => $id,
					'object_type' => 'referrer',
					'object_id'   => $id,
					'payload'     => array( 'status' => $status ),
				)
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-referrers' ) );
		exit;
	}

	/**
	 * Reject or reverse a conversion.
	 *
	 * @return void
	 */
	public static function handle_conversion_action() {
		self::guard( self::staff_cap() );
		check_admin_referer( 'g2ar_conversion_action' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$id     = isset( $_POST['conversion_id'] ) ? absint( wp_unslash( $_POST['conversion_id'] ) ) : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $id > 0 ) {
			if ( 'reverse' === $do ) {
				Rewards_Service::reverse_conversion( $id, $reason ?: __( 'Reversed by staff', 'g2a-referrals' ) );
			} elseif ( 'reject' === $do ) {
				Conversions_Repository::set_status( $id, 'rejected', array( 'reject_reason' => $reason ?: 'manual' ) );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-conversions' ) );
		exit;
	}

	/* ── Small helpers ─────────────────────────────────────────────── */

	/**
	 * Resolve a user id, email or referral code to a WP user id.
	 *
	 * @param string $who Raw input.
	 * @return int
	 */
	private static function resolve_user( $who ) {
		$who = trim( (string) $who );

		if ( '' === $who ) {
			return 0;
		}

		if ( ctype_digit( $who ) ) {
			return (int) $who;
		}

		if ( is_email( $who ) ) {
			$user = get_user_by( 'email', $who );

			return $user ? (int) $user->ID : 0;
		}

		if ( Codes::is_valid_format( $who ) ) {
			$referrer = Referrers_Repository::get_by_code( $who );

			return $referrer ? (int) $referrer['user_id'] : 0;
		}

		return 0;
	}

	/**
	 * A user's display name for admin tables.
	 *
	 * @param int $user_id WP user id.
	 * @return string
	 */
	private static function user_label( $user_id ) {
		if ( $user_id <= 0 ) {
			return __( 'System', 'g2a-referrals' );
		}

		$user = get_userdata( (int) $user_id );

		return $user ? (string) $user->display_name : '#' . (int) $user_id;
	}

	/**
	 * Format a dollar amount.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		return '$' . number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * Total link clicks across all referrers.
	 *
	 * @return int
	 */
	private static function total_clicks() {
		global $wpdb;

		$table = Visits_Repository::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Conversions created in the current UTC month.
	 *
	 * @return int
	 */
	private static function conversions_this_month() {
		global $wpdb;

		$table = Conversions_Repository::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE DATE_FORMAT( created_at, '%%Y-%%m' ) = %s",
				gmdate( 'Y-m' )
			)
		);
	}

	/**
	 * Absolute total of one ledger direction for a reward type.
	 *
	 * @param string $type      Reward type.
	 * @param string $direction Ledger direction.
	 * @return float
	 */
	private static function ledger_total( $type, $direction ) {
		global $wpdb;

		$table = Rewards_Repository::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( ABS( amount ) ), 0 ) FROM {$table} WHERE reward_type = %s AND direction = %s",
				(string) $type,
				(string) $direction
			)
		);

		return round( (float) $sum, 2 );
	}
}
