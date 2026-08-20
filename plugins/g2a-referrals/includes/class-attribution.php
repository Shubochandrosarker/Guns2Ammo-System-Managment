<?php
/**
 * ?ref= handling, the attribution cookie, and visit recording.
 *
 * Runs only when a ?ref= is present or at checkout — never on every page
 * load. Unindexed postmeta scans have already timed this site out once, and
 * attribution has no business adding a query to a cached page view.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Database\Events_Repository;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Visits_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Attribution {

	public const COOKIE = 'g2ar_ref';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'maybe_capture' ), 5 );
	}

	/**
	 * Capture a ?ref= if one is present and valid.
	 *
	 * @return void
	 */
	public static function maybe_capture() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public inbound link, not a form submission.
		if ( empty( $_GET['ref'] ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public inbound link.
		$raw  = sanitize_text_field( wp_unslash( $_GET['ref'] ) );
		$code = Codes::normalize( $raw );

		if ( '' === $code ) {
			return;
		}

		if ( ! Fraud::allow_code_lookup() ) {
			return;
		}

		$referrer = Referrers_Repository::get_by_code( $code );

		if ( ! $referrer || 'active' !== (string) $referrer['status'] ) {
			return;
		}

		// A member clicking their own link is not a visit worth recording.
		if ( get_current_user_id() && (int) $referrer['user_id'] === get_current_user_id() ) {
			return;
		}

		$existing = self::current_code();

		// First-touch wins by default: an existing cookie is never silently
		// overwritten. Switching the model to last-touch is an admin setting,
		// not something a second link click decides.
		$overwrite = ( '' === $existing ) || ( 'last_touch' === Settings::get( 'attribution_model' ) );

		$visit_id = Visits_Repository::create(
			array(
				'referrer_id'  => (int) $referrer['id'],
				'visitor_hash' => Fingerprint::visitor_hash(),
				'landing_url'  => self::current_url(),
				'referrer_url' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'device'       => Fingerprint::device(),
			)
		);

		Events_Repository::log(
			'visit_recorded',
			array(
				'referrer_id' => (int) $referrer['id'],
				'object_type' => 'visit',
				'object_id'   => $visit_id,
				'actor_id'    => 0,
				'payload'     => array(
					'overwrote_cookie' => $overwrite && '' !== $existing,
					'device'           => Fingerprint::device(),
				),
			)
		);

		if ( $overwrite ) {
			self::set_cookie( $code, $visit_id );
		}
	}

	/**
	 * Write the attribution cookie.
	 *
	 * Not HttpOnly on purpose: the front-end banner reads it to decide which
	 * variant to show without a second round trip. It carries a referral code
	 * and a visit id, nothing personal.
	 *
	 * @param string $code     Canonical referral code.
	 * @param int    $visit_id Visit row id.
	 * @return void
	 */
	public static function set_cookie( $code, $visit_id = 0 ) {
		$days  = max( 1, Settings::get_int( 'cookie_days' ) );
		$value = wp_json_encode(
			array(
				'code'  => $code,
				'visit' => (int) $visit_id,
				'ts'    => time(),
			)
		);

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => time() + ( $days * DAY_IN_SECONDS ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * The attribution cookie's payload for this request.
	 *
	 * @return array{code:string,visit:int}
	 */
	public static function cookie_payload() {
		$empty = array(
			'code'  => '',
			'visit' => 0,
		);

		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return $empty;
		}

		$raw     = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return $empty;
		}

		$code = Codes::normalize( $decoded['code'] ?? '' );

		if ( '' === $code ) {
			return $empty;
		}

		return array(
			'code'  => $code,
			'visit' => (int) ( $decoded['visit'] ?? 0 ),
		);
	}

	/**
	 * The referral code currently attributed to this visitor.
	 *
	 * @return string
	 */
	public static function current_code() {
		$payload = self::cookie_payload();

		return $payload['code'];
	}

	/**
	 * Resolve which referrer should be credited for a signup.
	 *
	 * Order: a code typed at checkout beats the cookie, which beats a link
	 * stored against the logged-in user. An explicit code is the strongest
	 * signal we have — someone read it off a card at the counter.
	 *
	 * @param string $typed_code Code entered at checkout, if any.
	 * @return array{referrer:array|null,visit_id:int,source:string}
	 */
	public static function resolve( $typed_code = '' ) {
		$none = array(
			'referrer' => null,
			'visit_id' => 0,
			'source'   => '',
		);

		$typed = Codes::normalize( $typed_code );
		if ( '' !== $typed ) {
			$referrer = Referrers_Repository::get_by_code( $typed );
			if ( $referrer && 'active' === (string) $referrer['status'] ) {
				return array(
					'referrer' => $referrer,
					'visit_id' => 0,
					'source'   => 'typed',
				);
			}
		}

		$cookie = self::cookie_payload();
		if ( '' !== $cookie['code'] ) {
			$referrer = Referrers_Repository::get_by_code( $cookie['code'] );
			if ( $referrer && 'active' === (string) $referrer['status'] ) {
				return array(
					'referrer' => $referrer,
					'visit_id' => $cookie['visit'],
					'source'   => 'cookie',
				);
			}
		}

		// Last resort: a visit fingerprint recorded within the cookie window
		// but whose cookie the visitor cleared.
		$visit = Visits_Repository::latest_for_visitor( Fingerprint::visitor_hash() );
		if ( $visit ) {
			$referrer = Referrers_Repository::get( (int) $visit['referrer_id'] );
			if ( $referrer && 'active' === (string) $referrer['status'] ) {
				return array(
					'referrer' => $referrer,
					'visit_id' => (int) $visit['id'],
					'source'   => 'fingerprint',
				);
			}
		}

		return $none;
	}

	/**
	 * Current request URL, capped to the column width.
	 *
	 * @return string
	 */
	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return mb_substr( esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri ), 0, 255 );
	}
}
