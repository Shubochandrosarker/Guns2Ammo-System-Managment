<?php
/**
 * The "Use 1 Guest Pass" opt-in on the booking form.
 *
 * The booking engine has no field-injection hook of its own, so rather than
 * fork its shortcode this contributes a small script that adds the checkbox
 * to the summary card and attaches use_guest_pass to the outgoing booking
 * request. If the booking engine later grows a native field API, the server
 * side here does not change — only where the flag comes from.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Frontend;

use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Redemption_UI {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 25 );
	}

	/**
	 * Load on booking screens only, and only for a member who actually has
	 * a pass to spend.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! is_user_logged_in() || ! Settings::is_on( 'redemption_enabled' ) ) {
			return;
		}

		if ( ! self::is_booking_screen() ) {
			return;
		}

		$balance = Rewards_Repository::balance( get_current_user_id(), Rewards_Repository::TYPE_GUEST_PASS );

		if ( $balance < 1 ) {
			return;
		}

		wp_enqueue_script( 'g2ar-redeem', G2AR_URL . 'assets/js/redeem.js', array(), G2AR_VERSION, true );

		wp_localize_script(
			'g2ar-redeem',
			'g2arRedeem',
			array(
				'balance'          => (float) $balance,
				'bookingNamespace' => defined( 'G2AB_REST_NAMESPACE' ) ? G2AB_REST_NAMESPACE : 'g2a-booking/v1',
				'i18n'             => array(
					'label' => __( 'Use 1 Guest Pass (brings a friend free)', 'g2a-referrals' ),
					/* translators: %s: number of Guest Passes available. */
					'have'  => __( 'You have %s available.', 'g2a-referrals' ),
					'free'  => __( 'This booking is already free — your Guest Pass stays in your account.', 'g2a-referrals' ),
				),
			)
		);
	}

	/**
	 * Is this a page that renders the booking form?
	 *
	 * @return bool
	 */
	private static function is_booking_screen() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		if ( ! $post ) {
			return false;
		}

		foreach ( array( 'g2a_lane_booking', 'g2a_booking_form' ) as $shortcode ) {
			if ( has_shortcode( (string) $post->post_content, $shortcode ) ) {
				return true;
			}
		}

		return is_page_template( 'page-templates/template-book-a-lane.php' );
	}
}
