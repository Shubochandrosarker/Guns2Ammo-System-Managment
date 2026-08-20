<?php
/**
 * GET /wp-json/g2ar/v1/context
 *
 * AirLift page cache is active on this site, so member-variant markup can
 * never sit in cached HTML — the first member to load a product page would
 * otherwise serve their referral code to every anonymous visitor after them.
 * Both banners render as an empty reserved placeholder and this single
 * request fills them in. One round trip, no layout shift, correct for every
 * visitor.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Rest;

use WordPressistic\G2AReferrals\Codes;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Context_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			G2AR_REST_NAMESPACE,
			'/context',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_context' ),
					// Public: an anonymous visitor must get the non-member
					// variant, not a 401. Nothing sensitive is returned for a
					// logged-out caller.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Per-visitor banner context.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_context( $request ) {
		unset( $request );

		$user_id   = get_current_user_id();
		$is_member = $user_id > 0 && function_exists( 'g2ar_user_is_member' ) && g2ar_user_is_member( $user_id );

		$payload = array(
			'is_member'     => (bool) $is_member,
			'referral_code' => '',
			'share_url'     => '',
			'banner_variant' => $is_member ? 'member' : 'guest',
			'guest_passes'  => 0,
			'copy'          => $this->copy( $is_member ),
		);

		if ( $is_member ) {
			$referrer = Referrers_Repository::ensure_for_user( $user_id );

			if ( $referrer ) {
				$payload['referral_code'] = (string) $referrer['code'];
				$payload['share_url']     = Codes::share_url( (string) $referrer['code'] );
			}

			$payload['guest_passes'] = (float) Rewards_Repository::balance( $user_id, Rewards_Repository::TYPE_GUEST_PASS );
		}

		if ( ! Settings::is_on( 'banner_enabled' ) ) {
			$payload['banner_variant'] = 'none';
		}

		$response = rest_ensure_response( $payload );

		// Never let a CDN or the page cache hold a per-visitor answer.
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Vary', 'Cookie' );

		return $response;
	}

	/**
	 * Customer-facing copy for the active variant.
	 *
	 * All of it is editable in Referrals → Settings — nothing about the
	 * offer is baked into markup.
	 *
	 * @param bool $is_member Whether the caller is a member.
	 * @return array
	 */
	private function copy( $is_member ) {
		if ( $is_member ) {
			return array(
				'lead' => (string) Settings::get( 'banner_member_lead' ),
				'body' => (string) Settings::get( 'banner_member_body' ),
				'cta'  => (string) Settings::get( 'banner_member_cta' ),
				'href' => esc_url_raw( $this->account_url() ),
			);
		}

		// The 10% first-order claim only runs once someone has confirmed the
		// offer is live and redeemable. Advertising a discount the checkout
		// cannot honour is worse than not advertising one — and the stacking
		// rule needs a real offer to enforce against.
		$offer_live = Settings::is_on( 'first_order_offer_enabled' );

		$body = $offer_live
			? sprintf(
				(string) Settings::get( 'banner_guest_body_offer' ),
				(int) Settings::get_int( 'first_order_offer_percent' )
			)
			: (string) Settings::get( 'banner_guest_body_plain' );

		return array(
			'lead' => (string) Settings::get( 'banner_guest_lead' ),
			'body' => $body,
			'cta'  => (string) Settings::get( 'banner_guest_cta' ),
			'href' => esc_url_raw( home_url( '/membership/' ) ),
		);
	}

	/**
	 * Where "Get my link" points.
	 *
	 * @return string
	 */
	private function account_url() {
		$page_id = 0;

		if ( function_exists( 'memberistic_get_setting' ) ) {
			$page_id = absint( memberistic_get_setting( 'account_page_id', 0 ) );
		}

		$url = $page_id ? get_permalink( $page_id ) : home_url( '/my-account/' );

		return add_query_arg( 'tab', 'rewards', $url ) . '#rewards';
	}
}
