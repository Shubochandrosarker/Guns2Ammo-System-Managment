<?php
/**
 * Member-facing rewards API for the account dashboard.
 *
 * Cookie + nonce authentication only.
 *
 * The `Bearer` scheme is unusable on this site: the JWT Authentication
 * plugin intercepts it globally at rest_pre_dispatch and 403s the entire
 * request before it reaches any handler. The edge also strips custom X-*
 * headers, so a bespoke auth header is not an option either.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Rest;

use WordPressistic\G2AReferrals\Codes;
use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Database\Visits_Repository;
use WordPressistic\G2AReferrals\QR;
use WordPressistic\G2AReferrals\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rewards_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			G2AR_REST_NAMESPACE,
			'/me',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_me' ),
					'permission_callback' => array( $this, 'require_member' ),
				),
			)
		);

		register_rest_route(
			G2AR_REST_NAMESPACE,
			'/me/qr',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_qr' ),
					'permission_callback' => array( $this, 'require_member' ),
				),
			)
		);

		register_rest_route(
			G2AR_REST_NAMESPACE,
			'/validate',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'validate_code' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'code' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Only a signed-in user may read their own rewards.
	 *
	 * @return bool|\WP_Error
	 */
	public function require_member() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'g2ar_not_logged_in', __( 'You must be signed in.', 'g2a-referrals' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * The signed-in member's full rewards picture.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_me( $request ) {
		unset( $request );

		$user_id  = get_current_user_id();
		$referrer = Referrers_Repository::ensure_for_user( $user_id );

		if ( ! $referrer ) {
			return rest_ensure_response(
				array(
					'has_code' => false,
					'code'     => '',
				)
			);
		}

		$code        = (string) $referrer['code'];
		$conversions = Conversions_Repository::for_referrer( (int) $referrer['id'], 50 );

		$payload = array(
			'has_code'    => true,
			'code'        => $code,
			'share_url'   => Codes::share_url( $code ),
			'status'      => (string) $referrer['status'],
			'balances'    => array(
				'guest_passes'    => (float) Rewards_Repository::balance( $user_id, Rewards_Repository::TYPE_GUEST_PASS ),
				'free_months'     => (float) Rewards_Repository::balance( $user_id, Rewards_Repository::TYPE_MEMBERSHIP_DAYS ),
				'next_expiry'     => Rewards_Repository::next_expiry( $user_id, Rewards_Repository::TYPE_GUEST_PASS ),
			),
			'funnel'      => array(
				'clicks'  => Visits_Repository::count_for_referrer( (int) $referrer['id'] ),
				'joined'  => (int) $referrer['total_referred'],
				'rewards' => (int) $referrer['total_rewarded'],
			),
			'cap'         => array(
				'per_month' => Settings::get_int( 'referral_cap_per_month' ),
				'used'      => Conversions_Repository::count_rewarded_in_month( (int) $referrer['id'] ),
			),
			'friends'     => array_map( array( $this, 'present_friend' ), $conversions ),
			'history'     => array_map( array( $this, 'present_ledger' ), Rewards_Repository::history( $user_id, 50 ) ),
			'terms'       => (string) Settings::get( 'terms_text' ),
			'values'      => array(
				'guest_pass_expiry_days' => Settings::get_int( 'guest_pass_expiry_days' ),
				'friend_reward_periods'  => Settings::get_int( 'friend_reward_periods' ),
				'referrer_reward_amount' => Settings::get_int( 'referrer_reward_amount' ),
			),
		);

		$response = rest_ensure_response( $payload );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );

		return $response;
	}

	/**
	 * A downloadable QR PNG of the member's share link.
	 *
	 * The front desk prints these — a range is a face-to-face business and a
	 * card on the counter converts better than a link nobody remembers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_qr( $request ) {
		unset( $request );

		$referrer = Referrers_Repository::ensure_for_user( get_current_user_id() );

		if ( ! $referrer ) {
			return new \WP_Error( 'g2ar_no_code', __( 'No referral code yet.', 'g2a-referrals' ), array( 'status' => 404 ) );
		}

		$png = QR::png_data_uri( Codes::share_url( (string) $referrer['code'] ) );

		if ( '' === $png ) {
			return new \WP_Error( 'g2ar_qr_failed', __( 'Could not build a QR code.', 'g2a-referrals' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'code'     => (string) $referrer['code'],
				'data_uri' => $png,
				'filename' => 'guns2ammo-referral-' . strtolower( str_replace( 'G2A-', '', (string) $referrer['code'] ) ) . '.png',
			)
		);
	}

	/**
	 * Is this code real? Used by the checkout field and the front desk.
	 *
	 * Deliberately returns nothing but a boolean and the owner's first name:
	 * an open validation endpoint must not become a way to enumerate members.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate_code( $request ) {
		if ( ! \WordPressistic\G2AReferrals\Fraud::allow_code_lookup( 'rest_validate' ) ) {
			return new \WP_Error( 'g2ar_rate_limited', __( 'Too many attempts. Try again shortly.', 'g2a-referrals' ), array( 'status' => 429 ) );
		}

		$code = Codes::normalize( (string) $request->get_param( 'code' ) );

		if ( '' === $code ) {
			return rest_ensure_response(
				array(
					'valid' => false,
					'owner' => '',
				)
			);
		}

		$referrer = Referrers_Repository::get_by_code( $code );
		$valid    = $referrer && 'active' === (string) $referrer['status'];

		return rest_ensure_response(
			array(
				'valid' => (bool) $valid,
				'code'  => $valid ? $code : '',
				'owner' => $valid ? $this->short_name( (int) $referrer['user_id'] ) : '',
			)
		);
	}

	/**
	 * A referred friend as the REFERRER is allowed to see them.
	 *
	 * First name and last initial, join date, status, reward. Never the
	 * friend's email, phone, plan price or purchases. This is a firearms
	 * retailer — customer privacy is not negotiable, and "who bought what"
	 * is exactly the thing that must not leak between customers.
	 *
	 * @param array $conversion Conversion row.
	 * @return array
	 */
	private function present_friend( array $conversion ) {
		return array(
			'name'      => $this->short_name( (int) $conversion['friend_user_id'] ),
			'joined_at' => (string) $conversion['created_at'],
			'status'    => (string) $conversion['status'],
			'label'     => $this->status_label( (string) $conversion['status'] ),
			'reward'    => 'rewarded' === (string) $conversion['status']
				? $this->reward_label()
				: '',
		);
	}

	/**
	 * One ledger row, formatted for the member's history table.
	 *
	 * @param array $row Ledger row.
	 * @return array
	 */
	private function present_ledger( array $row ) {
		return array(
			'date'      => (string) $row['created_at'],
			'type'      => (string) $row['reward_type'],
			'direction' => (string) $row['direction'],
			'amount'    => (float) $row['amount'],
			'note'      => (string) $row['note'],
			'expires_at' => $row['expires_at'] ? (string) $row['expires_at'] : null,
		);
	}

	/**
	 * "Sarah M." — first name plus last initial, never more.
	 *
	 * @param int $user_id WP user id.
	 * @return string
	 */
	private function short_name( $user_id ) {
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
	 * Human label for a conversion status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( $status ) {
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
	 * What the referrer earned, in their own words.
	 *
	 * @return string
	 */
	private function reward_label() {
		$amount = max( 1, Settings::get_int( 'referrer_reward_amount' ) );

		return sprintf(
			/* translators: %d: number of Guest Passes. */
			_n( '%d Guest Pass', '%d Guest Passes', $amount, 'g2a-referrals' ),
			$amount
		);
	}
}
