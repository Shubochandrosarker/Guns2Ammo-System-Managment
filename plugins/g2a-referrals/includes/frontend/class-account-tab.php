<?php
/**
 * The "Rewards" tab on the member account dashboard.
 *
 * Contributed to Memberistic's [memberistic_account] through the
 * memberistic_account_tabs / memberistic_account_panels filters rather than
 * by forking templates/account.php — a fork would silently lose every
 * future Memberistic release.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Frontend;

use WordPressistic\G2AReferrals\Codes;
use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Database\Visits_Repository;
use WordPressistic\G2AReferrals\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Account_Tab {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'memberistic_account_tabs', array( self::class, 'add_tab' ), 10, 2 );
		add_filter( 'memberistic_account_panels', array( self::class, 'add_panel' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Should this user see the tab at all?
	 *
	 * @param int $user_id WP user id.
	 * @return bool
	 */
	private static function visible( $user_id ) {
		if ( $user_id <= 0 || ! function_exists( 'memberistic_get_membership_status' ) ) {
			return false;
		}

		$status = memberistic_get_membership_status( $user_id );

		// Requires an actual membership, not merely a WordPress account:
		// rendering the tab calls ensure_for_user(), which mints a referral
		// code, and someone with a bare account is not in the programme.
		//
		// has_membership rather than is_live, because Guest Pass plan
		// holders can still refer — they earn a free month instead of a
		// pass, since they have no membership to bring a guest onto.
		return is_array( $status ) && ! empty( $status['has_membership'] );
	}

	/**
	 * Add the nav item.
	 *
	 * @param array $tabs    Existing tabs.
	 * @param int   $user_id Current user id.
	 * @return array
	 */
	public static function add_tab( $tabs, $user_id = 0 ) {
		if ( ! self::visible( (int) $user_id ) ) {
			return $tabs;
		}

		$tabs[] = array(
			'slug'  => 'rewards',
			'label' => __( 'Rewards', 'g2a-referrals' ),
			'icon'  => '★',
		);

		return $tabs;
	}

	/**
	 * Add the panel renderer.
	 *
	 * @param array $panels  Existing panels.
	 * @param int   $user_id Current user id.
	 * @return array
	 */
	public static function add_panel( $panels, $user_id = 0 ) {
		if ( ! self::visible( (int) $user_id ) ) {
			return $panels;
		}

		$panels['rewards'] = array( self::class, 'render' );

		return $panels;
	}

	/**
	 * Load the tab's styles and script on the account page only.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! is_singular() || ! is_user_logged_in() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'memberistic_account' ) ) {
			return;
		}

		wp_enqueue_style( 'g2ar-rewards', G2AR_URL . 'assets/css/rewards.css', array(), G2AR_VERSION );
		wp_enqueue_script( 'g2ar-rewards', G2AR_URL . 'assets/js/rewards.js', array(), G2AR_VERSION, true );

		wp_localize_script(
			'g2ar-rewards',
			'g2arRewards',
			array(
				'root'  => esc_url_raw( rest_url( G2AR_REST_NAMESPACE . '/' ) ),
				// Cookie + nonce. Never a Bearer token: the JWT
				// Authentication plugin intercepts that scheme globally at
				// rest_pre_dispatch and 403s the whole request.
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'copied' => __( 'Copied', 'g2a-referrals' ),
					'copy'   => __( 'Copy', 'g2a-referrals' ),
					'failed' => __( 'Could not build a QR code.', 'g2a-referrals' ),
				),
			)
		);
	}

	/**
	 * Render the panel. Server-rendered first so the tab is complete and
	 * readable with JavaScript off; the script only adds copy-to-clipboard,
	 * the QR download and the live refresh.
	 *
	 * @param int $user_id Current user id.
	 * @return void
	 */
	public static function render( $user_id = 0 ) {
		$user_id  = $user_id ?: get_current_user_id();
		$referrer = Referrers_Repository::ensure_for_user( (int) $user_id );

		if ( ! $referrer ) {
			echo '<div class="g2ar-rw"><p>' . esc_html__( 'Your referral link will appear here once your membership is active.', 'g2a-referrals' ) . '</p></div>';

			return;
		}

		$code      = (string) $referrer['code'];
		$share_url = Codes::share_url( $code );
		$passes    = Rewards_Repository::balance( (int) $user_id, Rewards_Repository::TYPE_GUEST_PASS );
		$months    = Rewards_Repository::balance( (int) $user_id, Rewards_Repository::TYPE_MEMBERSHIP_DAYS );
		$expiry    = Rewards_Repository::next_expiry( (int) $user_id, Rewards_Repository::TYPE_GUEST_PASS );
		$clicks    = Visits_Repository::count_for_referrer( (int) $referrer['id'] );
		$friends   = Conversions_Repository::for_referrer( (int) $referrer['id'], 25 );
		$history   = Rewards_Repository::history( (int) $user_id, 25 );
		$cap       = Settings::get_int( 'referral_cap_per_month' );
		$used      = Conversions_Repository::count_rewarded_in_month( (int) $referrer['id'] );

		$share_targets = self::share_targets( $share_url );
		?>
		<div class="g2ar-rw" data-g2ar-rewards>

			<div class="g2ar-rw__hero">
				<p class="g2ar-rw__eyebrow"><?php esc_html_e( 'Your referral code', 'g2a-referrals' ); ?></p>
				<div class="g2ar-rw__coderow">
					<span class="g2ar-rw__code" data-g2ar-code><?php echo esc_html( $code ); ?></span>
					<button type="button" class="g2ar-rw__copy" data-g2ar-copy="<?php echo esc_attr( $share_url ); ?>">
						<?php esc_html_e( 'Copy', 'g2a-referrals' ); ?>
					</button>
				</div>
				<p class="g2ar-rw__link"><?php echo esc_html( $share_url ); ?></p>

				<div class="g2ar-rw__share">
					<?php foreach ( $share_targets as $target ) : ?>
						<a class="g2ar-rw__sharebtn" href="<?php echo esc_url( $target['href'] ); ?>"<?php echo $target['blank'] ? ' target="_blank" rel="noopener"' : ''; ?>>
							<?php echo esc_html( $target['label'] ); ?>
						</a>
					<?php endforeach; ?>
					<button type="button" class="g2ar-rw__sharebtn" data-g2ar-qr>
						<?php esc_html_e( 'Download QR', 'g2a-referrals' ); ?>
					</button>
				</div>
				<div class="g2ar-rw__qr" data-g2ar-qr-target hidden></div>
			</div>

			<div class="g2ar-rw__tiles">
				<div class="g2ar-rw__tile">
					<span class="g2ar-rw__num"><?php echo esc_html( self::number( $passes ) ); ?></span>
					<span class="g2ar-rw__label"><?php esc_html_e( 'Guest Passes available', 'g2a-referrals' ); ?></span>
				</div>
				<div class="g2ar-rw__tile">
					<span class="g2ar-rw__num"><?php echo esc_html( self::number( $months ) ); ?></span>
					<span class="g2ar-rw__label"><?php esc_html_e( 'Free months earned', 'g2a-referrals' ); ?></span>
				</div>
				<div class="g2ar-rw__tile">
					<span class="g2ar-rw__num"><?php echo esc_html( $expiry ? self::date( $expiry ) : '—' ); ?></span>
					<span class="g2ar-rw__label"><?php esc_html_e( 'Next expiry', 'g2a-referrals' ); ?></span>
				</div>
			</div>

			<div class="g2ar-rw__funnel">
				<div class="g2ar-rw__step">
					<span class="g2ar-rw__num"><?php echo esc_html( (string) $clicks ); ?></span>
					<span class="g2ar-rw__label"><?php esc_html_e( 'Link clicks', 'g2a-referrals' ); ?></span>
				</div>
				<div class="g2ar-rw__step">
					<span class="g2ar-rw__num"><?php echo esc_html( (string) (int) $referrer['total_referred'] ); ?></span>
					<span class="g2ar-rw__label"><?php esc_html_e( 'Friends joined', 'g2a-referrals' ); ?></span>
				</div>
				<div class="g2ar-rw__step">
					<span class="g2ar-rw__num"><?php echo esc_html( (string) (int) $referrer['total_rewarded'] ); ?></span>
					<span class="g2ar-rw__label"><?php esc_html_e( 'Rewards earned', 'g2a-referrals' ); ?></span>
				</div>
			</div>

			<?php if ( $cap > 0 ) : ?>
				<p class="g2ar-rw__cap">
					<?php
					printf(
						/* translators: 1: rewards used this month, 2: monthly cap. */
						esc_html__( '%1$d of %2$d referral rewards used this month.', 'g2a-referrals' ),
						(int) $used,
						(int) $cap
					);
					?>
				</p>
			<?php endif; ?>

			<h3 class="g2ar-rw__h"><?php esc_html_e( 'Friends you referred', 'g2a-referrals' ); ?></h3>
			<?php if ( ! $friends ) : ?>
				<p class="g2ar-rw__empty"><?php esc_html_e( 'No referrals yet. Share your link and your first free month is on us.', 'g2a-referrals' ); ?></p>
			<?php else : ?>
				<ul class="g2ar-rw__list">
					<?php foreach ( $friends as $friend ) : ?>
						<li class="g2ar-rw__row">
							<span class="g2ar-rw__who"><?php echo esc_html( self::short_name( (int) $friend['friend_user_id'] ) ); ?></span>
							<span class="g2ar-rw__when"><?php echo esc_html( self::date( (string) $friend['created_at'] ) ); ?></span>
							<?php
							// Colour alone never carries meaning — every badge
							// pairs its colour with a text label.
							?>
							<span class="g2ar-rw__badge is-<?php echo esc_attr( (string) $friend['status'] ); ?>">
								<?php echo esc_html( self::status_label( (string) $friend['status'] ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h3 class="g2ar-rw__h"><?php esc_html_e( 'Reward history', 'g2a-referrals' ); ?></h3>
			<?php if ( ! $history ) : ?>
				<p class="g2ar-rw__empty"><?php esc_html_e( 'Nothing yet.', 'g2a-referrals' ); ?></p>
			<?php else : ?>
				<ul class="g2ar-rw__list">
					<?php foreach ( $history as $row ) : ?>
						<li class="g2ar-rw__row">
							<span class="g2ar-rw__who"><?php echo esc_html( self::direction_label( (string) $row['direction'], (string) $row['reward_type'] ) ); ?></span>
							<span class="g2ar-rw__when"><?php echo esc_html( self::date( (string) $row['created_at'] ) ); ?></span>
							<span class="g2ar-rw__note"><?php echo esc_html( (string) $row['note'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="g2ar-rw__terms"><?php echo esc_html( (string) Settings::get( 'terms_text' ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Share targets. SMS and WhatsApp first — the front desk hands the link
	 * over in person more often than anyone emails it.
	 *
	 * @param string $share_url Share link.
	 * @return array[]
	 */
	private static function share_targets( $share_url ) {
		$message = sprintf(
			/* translators: %s: referral share link. */
			__( 'Join Guns 2 Ammo and get a free month on your membership: %s', 'g2a-referrals' ),
			$share_url
		);

		return array(
			array(
				'label' => __( 'SMS', 'g2a-referrals' ),
				'href'  => 'sms:?&body=' . rawurlencode( $message ),
				'blank' => false,
			),
			array(
				'label' => __( 'WhatsApp', 'g2a-referrals' ),
				'href'  => 'https://wa.me/?text=' . rawurlencode( $message ),
				'blank' => true,
			),
			array(
				'label' => __( 'Messenger', 'g2a-referrals' ),
				'href'  => 'https://www.facebook.com/dialog/send?link=' . rawurlencode( $share_url ) . '&app_id=0&redirect_uri=' . rawurlencode( home_url( '/' ) ),
				'blank' => true,
			),
			array(
				'label' => __( 'Email', 'g2a-referrals' ),
				'href'  => 'mailto:?subject=' . rawurlencode( __( 'A free month at Guns 2 Ammo', 'g2a-referrals' ) ) . '&body=' . rawurlencode( $message ),
				'blank' => false,
			),
		);
	}

	/**
	 * "Sarah M." and nothing more.
	 *
	 * The referrer never sees a friend's email, phone, plan price or
	 * purchases. This is a firearms retailer: what a customer bought is not
	 * something another customer gets to learn from a rewards screen.
	 *
	 * @param int $user_id Friend's WP user id.
	 * @return string
	 */
	private static function short_name( $user_id ) {
		$user = $user_id ? get_userdata( (int) $user_id ) : null;

		if ( ! $user ) {
			return __( 'A friend', 'g2a-referrals' );
		}

		$first = trim( (string) $user->first_name );
		$last  = trim( (string) $user->last_name );

		if ( '' === $first ) {
			$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
			$first = (string) ( $parts[0] ?? '' );
			$last  = (string) ( $parts[1] ?? '' );
		}

		if ( '' === $first ) {
			return __( 'A friend', 'g2a-referrals' );
		}

		return $last ? $first . ' ' . strtoupper( substr( $last, 0, 1 ) ) . '.' : $first;
	}

	/**
	 * @param string $status Conversion status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'pending'   => __( 'Pending', 'g2a-referrals' ),
			'qualified' => __( 'Qualified', 'g2a-referrals' ),
			'rewarded'  => __( 'Rewarded', 'g2a-referrals' ),
			'rejected'  => __( 'Not eligible', 'g2a-referrals' ),
			'reversed'  => __( 'Reversed', 'g2a-referrals' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * @param string $direction Ledger direction.
	 * @param string $type      Reward type.
	 * @return string
	 */
	private static function direction_label( $direction, $type ) {
		$noun = Rewards_Repository::TYPE_GUEST_PASS === $type
			? __( 'Guest Pass', 'g2a-referrals' )
			: __( 'Free month', 'g2a-referrals' );

		switch ( $direction ) {
			case 'grant':
				/* translators: %s: reward name. */
				return sprintf( __( '%s earned', 'g2a-referrals' ), $noun );
			case 'redeem':
				/* translators: %s: reward name. */
				return sprintf( __( '%s used', 'g2a-referrals' ), $noun );
			case 'expire':
				/* translators: %s: reward name. */
				return sprintf( __( '%s expired', 'g2a-referrals' ), $noun );
			default:
				/* translators: %s: reward name. */
				return sprintf( __( '%s reversed', 'g2a-referrals' ), $noun );
		}
	}

	/**
	 * Format a balance without trailing decimal noise.
	 *
	 * @param float $value Balance.
	 * @return string
	 */
	private static function number( $value ) {
		$value = (float) $value;

		return ( abs( $value - round( $value ) ) < 0.005 )
			? (string) (int) round( $value )
			: number_format_i18n( $value, 2 );
	}

	/**
	 * Format a UTC datetime in the site's timezone and date format.
	 *
	 * @param string $mysql MySQL UTC datetime.
	 * @return string
	 */
	private static function date( $mysql ) {
		$timestamp = strtotime( $mysql . ' UTC' );

		return $timestamp ? wp_date( (string) get_option( 'date_format', 'j M Y' ), $timestamp ) : '';
	}
}
