<?php
/**
 * Stripe Checkout service.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Emails\Email_Service;
use function WordPressistic\Memberistic\memberistic_admin_url;
use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_get_page_url;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stripe_Service {
	const API_BASE    = 'https://api.stripe.com/v1';
	const API_VERSION = '2024-04-10';

	/**
	 * True while an inbound Stripe webhook event is being processed.
	 *
	 * Inbound events (e.g. customer.subscription.deleted) sync Stripe's state
	 * into the local DB via change_status(), which fires
	 * memberistic_membership_status_changed — the same hook the outbound
	 * cancel listener uses. This flag stops the sync from calling Stripe back
	 * to cancel a subscription Stripe just told us it cancelled.
	 *
	 * @var bool
	 */
	private static $processing_inbound_event = false;

	/**
	 * Cron hook for retrying failed remote cancellations.
	 */
	const CANCEL_RETRY_HOOK = 'memberistic_stripe_cancel_retry';

	/**
	 * Option holding memberships whose Stripe cancel failed and is being
	 * retried — keeps the failure visible in wp-admin until resolved.
	 */
	const CANCEL_FAILURES_OPTION = 'memberistic_stripe_cancel_failures';

	/**
	 * Retry delays (seconds) per attempt. After the last one is exhausted the
	 * failure stays on the admin notice until staff resolve it in Stripe.
	 *
	 * @var int[]
	 */
	const CANCEL_RETRY_DELAYS = array( 300, 1800, 7200, 21600, 86400, 172800 );

	/**
	 * Membership ids whose remote cancel was already attempted by
	 * cancel_remote_first() during this request, so the status-change hook
	 * listener doesn't call Stripe a second time for the same cancel.
	 *
	 * @var array<int,bool>
	 */
	private static $cancel_preflight_done = array();

	public static function is_enabled() {
		return 'yes' === memberistic_get_setting( 'stripe_enabled', 'no' ) && '' !== self::get_secret_key();
	}

	public static function get_secret_key() {
		$mode = memberistic_get_setting( 'stripe_mode', 'test' );
		$key  = 'live' === $mode ? memberistic_get_setting( 'stripe_live_secret_key', '' ) : memberistic_get_setting( 'stripe_test_secret_key', '' );
		return trim( (string) $key );
	}

	public static function maybe_handle_public_checkout_request() {
		if ( empty( $_GET['memberistic_checkout_handler'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( empty( $_POST['memberistic_action'] ) || 'start_checkout' !== sanitize_key( wp_unslash( $_POST['memberistic_action'] ) ) ) {
			return;
		}

		self::handle_checkout_request();
	}

	public static function checkout_action_url() {
		return add_query_arg( 'memberistic_checkout_handler', '1', home_url( '/' ) );
	}

	/**
	 * Nonced URL that opens the logged-in member's Stripe billing portal.
	 *
	 * Used by the account dashboard "Update Payment Method" / "Update Payment"
	 * buttons. Hitting it routes through maybe_handle_billing_portal_request(),
	 * which exchanges the member's stored stripe_customer_id for a one-time
	 * Stripe-hosted portal session (update card, view invoices, cancel) and
	 * redirects there. Members with no Stripe customer (legacy / cash / POS)
	 * are sent to the plans page to start a recurring subscription instead.
	 */
	public static function billing_portal_action_url() {
		return wp_nonce_url(
			add_query_arg( 'memberistic_billing_portal', '1', home_url( '/' ) ),
			'memberistic_billing_portal',
			'memberistic_bp_nonce'
		);
	}

	/**
	 * Front-end handler: open the Stripe customer billing portal for the
	 * current member. Registered on `init` alongside the checkout handler.
	 */
	public static function maybe_handle_billing_portal_request() {
		if ( empty( $_GET['memberistic_billing_portal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$account_url = memberistic_get_page_url( 'account_page_id', 'account', home_url( '/account/' ) );
		$plans_url   = memberistic_get_page_url( 'plans_page_id', 'memberships', '' );
		if ( ! $plans_url ) {
			$plans_url = memberistic_get_page_url( 'plans_page_id', 'memberistic-memberships', home_url( '/memberships/' ) );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( empty( $_GET['memberistic_bp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['memberistic_bp_nonce'] ) ), 'memberistic_billing_portal' ) ) {
			wp_safe_redirect( add_query_arg( 'memberistic_billing', 'invalid', $account_url ) );
			exit;
		}

		$membership  = Memberships_Repository::get_by_user_id( get_current_user_id() );
		$customer_id = $membership && ! empty( $membership['stripe_customer_id'] ) ? (string) $membership['stripe_customer_id'] : '';

		// Legacy / non-Stripe members have no Stripe customer to manage. Send
		// them to the plans page so they can start a real recurring
		// subscription — this is the self-serve fix for imported members who
		// were never set up to auto-charge.
		if ( ! self::is_enabled() || '' === $customer_id ) {
			wp_safe_redirect( $plans_url ?: $account_url );
			exit;
		}

		$session = self::create_billing_portal_session( $customer_id, $account_url );

		if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
			// Portal not configured in the Stripe dashboard yet, or a transient
			// Stripe error. Fail safe back to the account page with a notice
			// rather than a white wp_die() screen.
			wp_safe_redirect( add_query_arg( 'memberistic_billing', 'unavailable', $account_url ) );
			exit;
		}

		$url  = esc_url_raw( $session['url'] );
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'billing.stripe.com' !== $host ) {
			wp_safe_redirect( $account_url );
			exit;
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Create a Stripe customer billing-portal session.
	 *
	 * @param string $customer_id Stripe customer id (cus_...).
	 * @param string $return_url  Where Stripe returns the member after they finish.
	 * @return array<string,mixed>|\WP_Error
	 */
	/**
	 * Retrieve a Stripe subscription object by id (read-only).
	 *
	 * @param string $subscription_id Stripe subscription id (sub_…).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function get_subscription( $subscription_id ) {
		$subscription_id = trim( (string) $subscription_id );
		if ( '' === $subscription_id ) {
			return new \WP_Error( 'memberistic_missing_subscription', __( 'No subscription id provided.', 'memberistic' ) );
		}
		return self::request( 'GET', '/subscriptions/' . rawurlencode( $subscription_id ) );
	}

	public static function create_billing_portal_session( $customer_id, $return_url ) {
		return self::request(
			'POST',
			'/billing_portal/sessions',
			array(
				'customer'   => $customer_id,
				'return_url' => $return_url,
			)
		);
	}

	/**
	 * Cancel a Stripe subscription.
	 *
	 * @param string $subscription_id Stripe subscription id (sub_…).
	 * @param bool   $at_period_end   True to stop billing at the end of the
	 *                                current period instead of immediately.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function cancel_subscription( $subscription_id, $at_period_end = false ) {
		$subscription_id = trim( (string) $subscription_id );
		if ( '' === $subscription_id ) {
			return new \WP_Error( 'memberistic_missing_subscription', __( 'No subscription id provided.', 'memberistic' ) );
		}
		if ( $at_period_end ) {
			return self::request( 'POST', '/subscriptions/' . rawurlencode( $subscription_id ), array( 'cancel_at_period_end' => 'true' ) );
		}
		return self::request( 'DELETE', '/subscriptions/' . rawurlencode( $subscription_id ) );
	}

	/**
	 * Cancel the Stripe subscription behind a membership when its status is
	 * changed to "cancelled" on the WordPress side.
	 *
	 * Hooked on memberistic_membership_status_changed, so every cancel that
	 * goes through Memberships_Repository::change_status() — the admin REST
	 * cancel action, the admin edit-screen status change, and the legacy
	 * wp-admin members page — propagates to Stripe. Before this listener the
	 * status flip was DB-only and Stripe kept billing the member.
	 *
	 * @param int    $membership_id Membership id.
	 * @param string $status        New status.
	 */
	public static function maybe_cancel_remote_subscription( $membership_id, $status ) {
		$membership_id = (int) $membership_id;

		if ( 'cancelled' !== $status || self::$processing_inbound_event || ! self::is_enabled() ) {
			return;
		}

		// cancel_remote_first() already attempted (and logged) this cancel
		// during the current request — success or failure, don't do it twice.
		if ( isset( self::$cancel_preflight_done[ $membership_id ] ) ) {
			return;
		}

		$membership = Memberships_Repository::get( $membership_id );
		if ( ! is_array( $membership ) || empty( $membership['stripe_subscription_id'] ) ) {
			return;
		}

		$result = self::attempt_remote_cancel( $membership_id, $membership );

		if ( is_wp_error( $result ) ) {
			self::record_cancel_failure( $membership_id, (string) $membership['stripe_subscription_id'], $result, 0 );
		}
	}

	/**
	 * Stop remote billing for a membership BEFORE the local record is
	 * cancelled ("Stripe first, local status second").
	 *
	 * Returns true when it is safe to flip the local status: the Stripe
	 * subscription was cancelled now, was already gone, the membership has
	 * no subscription, or Stripe is disabled. Returns WP_Error when Stripe
	 * failed — a retry is already queued, and the caller should NOT mark
	 * the membership cancelled unless the operator explicitly forces a
	 * local-only cancel.
	 *
	 * @param int $membership_id Membership id.
	 * @return true|\WP_Error
	 */
	public static function cancel_remote_first( $membership_id ) {
		$membership_id = (int) $membership_id;

		self::$cancel_preflight_done[ $membership_id ] = true;

		if ( ! self::is_enabled() ) {
			return true;
		}

		$membership = Memberships_Repository::get( $membership_id );
		if ( ! is_array( $membership ) || empty( $membership['stripe_subscription_id'] ) ) {
			return true;
		}

		$result = self::attempt_remote_cancel( $membership_id, $membership );

		if ( is_wp_error( $result ) ) {
			self::record_cancel_failure( $membership_id, (string) $membership['stripe_subscription_id'], $result, 0 );
			return $result;
		}

		return true;
	}

	/**
	 * Perform one idempotent remote cancel attempt and log the outcome.
	 *
	 * "Already cancelled / no such subscription" answers from Stripe count
	 * as success — remote billing is confirmed stopped either way.
	 *
	 * @param int                 $membership_id Membership id.
	 * @param array<string,mixed> $membership    Membership row.
	 * @return true|\WP_Error
	 */
	private static function attempt_remote_cancel( $membership_id, array $membership ) {
		$subscription_id = (string) $membership['stripe_subscription_id'];

		/**
		 * Filter whether the Stripe subscription should stop billing at the
		 * end of the paid period (true) or immediately (false, default —
		 * matches the local status flipping to cancelled right away).
		 *
		 * @param bool                $at_period_end Default false.
		 * @param array<string,mixed> $membership    Membership row.
		 */
		$at_period_end = (bool) apply_filters( 'memberistic_stripe_cancel_at_period_end', false, $membership );

		$result = self::cancel_subscription( $subscription_id, $at_period_end );

		if ( is_wp_error( $result ) ) {
			$data        = $result->get_error_data();
			$http_status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			$stripe_code = is_array( $data ) && isset( $data['response']['error']['code'] ) ? (string) $data['response']['error']['code'] : '';

			// Idempotent terminus: the subscription is already gone or
			// already cancelled at Stripe — nothing left to stop. Stripe
			// answers "No such subscription" (resource_missing, 404) or
			// "…has been canceled…" for those cases.
			if ( 404 !== $http_status && 'resource_missing' !== $stripe_code && false === stripos( $result->get_error_message(), 'has been canceled' ) ) {
				return $result;
			}
		}

		self::clear_cancel_failure( $membership_id );

		Activity_Repository::log(
			array(
				'membership_id' => (int) $membership_id,
				'activity_type' => 'membership_cancelled',
				'title'         => $at_period_end
					? __( 'Stripe subscription set to cancel at period end', 'memberistic' )
					: __( 'Stripe subscription cancelled — billing stopped', 'memberistic' ),
			)
		);

		return true;
	}

	/**
	 * Record a failed remote cancel: loud activity entry, persistent admin
	 * notice, and a scheduled retry with backoff.
	 *
	 * @param int       $membership_id   Membership id.
	 * @param string    $subscription_id Stripe subscription id.
	 * @param \WP_Error $error           Failure.
	 * @param int       $attempt         Attempt that just failed (0 = first try).
	 */
	private static function record_cancel_failure( $membership_id, $subscription_id, $error, $attempt ) {
		$membership_id = (int) $membership_id;
		$next_attempt  = (int) $attempt + 1;
		$exhausted     = $next_attempt > count( self::CANCEL_RETRY_DELAYS );

		error_log( sprintf( 'Memberistic: failed to cancel Stripe subscription %s for membership #%d (attempt %d): %s', $subscription_id, $membership_id, $attempt + 1, $error->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'activity_type' => 'membership_cancelled',
				'title'         => $exhausted
					? sprintf(
						/* translators: %s = Stripe error message */
						__( 'Stripe cancellation FAILED and retries are exhausted — cancel the subscription manually in the Stripe Dashboard: %s', 'memberistic' ),
						$error->get_error_message()
					)
					: sprintf(
						/* translators: %s = Stripe error message */
						__( 'Stripe cancellation FAILED — subscription may still be billing. A retry is queued: %s', 'memberistic' ),
						$error->get_error_message()
					),
			)
		);

		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		$failures = is_array( $failures ) ? $failures : array();

		$failures[ $membership_id ] = array(
			'subscription_id' => $subscription_id,
			'message'         => $error->get_error_message(),
			'attempts'        => $attempt + 1,
			'exhausted'       => $exhausted,
			'last_failed_at'  => time(),
		);
		update_option( self::CANCEL_FAILURES_OPTION, $failures, false );

		if ( ! $exhausted ) {
			$delay = self::CANCEL_RETRY_DELAYS[ $next_attempt - 1 ];
			if ( ! wp_next_scheduled( self::CANCEL_RETRY_HOOK, array( $membership_id, $next_attempt ) ) ) {
				wp_schedule_single_event( time() + $delay, self::CANCEL_RETRY_HOOK, array( $membership_id, $next_attempt ) );
			}
		}
	}

	/**
	 * Cron: retry a failed remote cancellation.
	 *
	 * On success the local membership is cancelled too if the operator's
	 * original cancel was blocked by the failure (Stripe-first ordering).
	 *
	 * @param int $membership_id Membership id.
	 * @param int $attempt       Attempt number (1-based).
	 */
	public static function run_cancel_retry( $membership_id, $attempt = 1 ) {
		$membership_id = (int) $membership_id;

		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		if ( ! is_array( $failures ) || ! isset( $failures[ $membership_id ] ) ) {
			return; // Resolved elsewhere (webhook, staff, later success).
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		$membership = Memberships_Repository::get( $membership_id );
		if ( ! is_array( $membership ) || empty( $membership['stripe_subscription_id'] ) ) {
			self::clear_cancel_failure( $membership_id );
			return;
		}

		$result = self::attempt_remote_cancel( $membership_id, $membership );

		if ( is_wp_error( $result ) ) {
			self::record_cancel_failure( $membership_id, (string) $membership['stripe_subscription_id'], $result, (int) $attempt );
			return;
		}

		// Remote billing is now confirmed stopped. If the membership is
		// still not cancelled locally (the original cancel was blocked by
		// the Stripe failure), finish the job.
		if ( 'cancelled' !== (string) $membership['status'] ) {
			self::$cancel_preflight_done[ $membership_id ] = true;
			Memberships_Repository::update( $membership_id, array( 'cancelled_at' => current_time( 'mysql' ) ) );
			Memberships_Repository::change_status( $membership_id, 'cancelled' );
			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => 'membership_cancelled',
					'title'         => __( 'Membership cancelled after Stripe retry succeeded', 'memberistic' ),
				)
			);
		}
	}

	/**
	 * Remove a membership from the failed-cancel notice.
	 *
	 * @param int $membership_id Membership id.
	 */
	private static function clear_cancel_failure( $membership_id ) {
		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		if ( is_array( $failures ) && isset( $failures[ (int) $membership_id ] ) ) {
			unset( $failures[ (int) $membership_id ] );
			if ( empty( $failures ) ) {
				delete_option( self::CANCEL_FAILURES_OPTION );
			} else {
				update_option( self::CANCEL_FAILURES_OPTION, $failures, false );
			}
		}
	}

	/**
	 * Persistent wp-admin notice while any Stripe cancellation is failed or
	 * retrying, so a still-billing subscription can never go unnoticed.
	 */
	public static function render_cancel_failure_notice() {
		if ( ! memberistic_current_user_can( 'edit_memberistic_members' ) ) {
			return;
		}

		$failures = get_option( self::CANCEL_FAILURES_OPTION, array() );
		if ( ! is_array( $failures ) || empty( $failures ) ) {
			return;
		}

		$lines = array();
		foreach ( $failures as $membership_id => $failure ) {
			$lines[] = sprintf(
				/* translators: 1: membership id, 2: attempts, 3: error message */
				esc_html__( 'Membership #%1$d (attempt %2$d): %3$s', 'memberistic' ),
				(int) $membership_id,
				isset( $failure['attempts'] ) ? (int) $failure['attempts'] : 1,
				esc_html( isset( $failure['message'] ) ? (string) $failure['message'] : '' )
			);
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s</p></div>',
			esc_html__( 'Memberistic: Stripe subscription cancellation failed — these members may still be billed.', 'memberistic' ),
			implode( '<br>', $lines ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- lines escaped above.
			esc_html__( 'Automatic retries are running. If this persists, cancel the subscription manually in the Stripe Dashboard; the notice clears when Stripe confirms.', 'memberistic' )
		);
	}

	public static function handle_checkout_request() {
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'memberistic_checkout' ) ) {
			wp_die( esc_html__( 'Checkout request could not be verified.', 'memberistic' ) );
		}

		if ( ! self::is_enabled() ) {
			wp_die( esc_html__( 'Stripe checkout is not enabled yet.', 'memberistic' ) );
		}

		// Throttle the public checkout endpoint per IP. The signup nonce is
		// rendered on a public page (identical for all logged-out visitors),
		// so without this a script could seed pending memberships and fire
		// 'membership_created' mail to arbitrary addresses. 8 attempts / 10 min.
		// Checked and charged as one atomic step (MySQL advisory lock) so a
		// scripted concurrent burst can't have two requests both read the
		// same stale count and both slip through — the lost-update race
		// that plain get_transient()-then-set_transient() is exposed to.
		$mem_ip  = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && filter_var( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ), FILTER_VALIDATE_IP )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )
			: ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0' );
		$mem_rl_key = 'memberistic_checkout_rl_' . md5( $mem_ip );
		if ( ! self::atomic_check_and_increment( $mem_rl_key, (int) apply_filters( 'memberistic_checkout_rate_limit', 8 ), 10 * MINUTE_IN_SECONDS ) ) {
			wp_die(
				esc_html__( 'Too many checkout attempts. Please wait a few minutes and try again.', 'memberistic' ),
				esc_html__( 'Slow down', 'memberistic' ),
				array( 'response' => 429 )
			);
		}

		$plan_id       = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
		$billing_cycle = isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : 'monthly';
		$full_name     = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone         = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$terms         = isset( $_POST['terms_acceptance'] ) ? sanitize_key( wp_unslash( $_POST['terms_acceptance'] ) ) : '';
		$plan          = $plan_id ? Plans_Repository::get( $plan_id ) : null;

		if ( ! $plan ) {
			wp_die( esc_html__( 'The selected membership plan was not found or is no longer available.', 'memberistic' ) );
		}

		if ( '' === $full_name ) {
			wp_die( esc_html__( 'Please enter your full name.', 'memberistic' ) );
		}

		if ( ! is_email( $email ) ) {
			wp_die( esc_html__( 'Please enter a valid email address.', 'memberistic' ) );
		}

		if ( 'yes' !== $terms ) {
			wp_die( esc_html__( 'You must accept the terms to continue.', 'memberistic' ) );
		}

		// Age-verification gate (Verifyistic integration). When "require
		// verification before signup" is on and the visitor hasn't verified,
		// stop here before any membership / Stripe session is created.
		if ( class_exists( '\\WordPressistic\\Memberistic\\Integrations\\Verifyistic_Bridge' )
			&& \WordPressistic\Memberistic\Integrations\Verifyistic_Bridge::signup_blocked() ) {
			$back = wp_get_referer();
			wp_die(
				esc_html__( 'Please complete the age verification on the website before signing up for a membership.', 'memberistic' ),
				esc_html__( 'Age verification required', 'memberistic' ),
				array(
					'response'  => 403,
					'back_link' => true,
					'link_url'  => $back ?: home_url( '/' ),
					'link_text' => __( 'Go back and verify', 'memberistic' ),
				)
			);
		}

		if ( ! in_array( $billing_cycle, array( 'monthly', 'annual' ), true ) ) {
			$billing_cycle = 'monthly';
		}

		$user_id = self::resolve_checkout_user( $email, $full_name );

		// Re-entrance guard: a refresh, back-button, or double-click on the
		// checkout form used to create a SECOND pending membership for the
		// same email. If a pending row already exists for this email, reuse
		// it (and its existing Stripe checkout URL) instead of inserting a
		// duplicate.
		$pending_existing = Memberships_Repository::get_pending_by_person_email( $email );
		if ( $pending_existing && ! empty( $pending_existing['id'] ) ) {
			$existing_session = self::create_checkout_session( (int) $pending_existing['id'], $plan, $billing_cycle, $email );
			if ( ! is_wp_error( $existing_session ) && ! empty( $existing_session['url'] ) ) {
				$redirect_url  = esc_url_raw( $existing_session['url'] );
				$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );
				if ( in_array( $redirect_host, array( 'checkout.stripe.com', 'billing.stripe.com' ), true ) ) {
					wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
					exit;
				}
			}
		}

		$membership_id = Memberships_Repository::create(
			array(
				'plan_id'        => $plan_id,
				'primary_user_id'=> $user_id,
				'billing_cycle'  => $billing_cycle,
				'status'         => 'pending',
				'payment_source' => 'stripe',
				'created_by'     => get_current_user_id(),
			)
		);

		if ( ! $membership_id ) {
			wp_die( esc_html__( 'Membership could not be created.', 'memberistic' ) );
		}

		$person_id = People_Repository::create(
			array(
				'membership_id' => $membership_id,
				'role'          => 'primary',
				'full_name'     => $full_name,
				'email'         => $email,
				'phone'         => $phone,
				'wp_user_id'    => $user_id,
				'waiver_status' => 'missing',
				'status'        => 'active',
			)
		);

		Email_Service::send_membership_email( $membership_id, 'membership_created' );

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'person_id'     => $person_id,
				'activity_type' => 'membership_created',
				'title'         => __( 'Online checkout started', 'memberistic' ),
			)
		);

		$session = self::create_checkout_session( $membership_id, $plan, $billing_cycle, $email );

		if ( is_wp_error( $session ) ) {
			wp_die( esc_html( $session->get_error_message() ) );
		}

		if ( empty( $session['url'] ) ) {
			wp_die( esc_html__( 'Stripe did not return a checkout URL.', 'memberistic' ) );
		}

		$redirect_url  = esc_url_raw( $session['url'] );
		$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );

		if ( ! in_array( $redirect_host, array( 'checkout.stripe.com', 'billing.stripe.com' ), true ) ) {
			wp_die( esc_html__( 'Stripe returned an invalid checkout URL.', 'memberistic' ) );
		}

		wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Pre-payment lookup ONLY. Returns the current user id, or a matching
	 * existing user, or 0. Does NOT create a new WP user — that would
	 * fire wp_new_user_notification() (and consume the email_exists()
	 * uniqueness slot) for any visitor who clicks Checkout, including
	 * spray attackers who supply someone else's email. User creation is
	 * deferred to ensure_user_for_completed_checkout(), invoked from
	 * handle_checkout_completed() after Stripe confirms payment.
	 */
	private static function resolve_checkout_user( $email, $full_name ) {
		$current_user_id = get_current_user_id();

		if ( $current_user_id ) {
			return $current_user_id;
		}

		$existing_user_id = email_exists( $email );

		if ( $existing_user_id ) {
			return (int) $existing_user_id;
		}

		return 0;
	}

	/**
	 * Post-payment user provisioning. Called from handle_checkout_completed
	 * once Stripe confirms a successful checkout. Creates the WP user if
	 * one didn't exist at checkout time and links it back to the
	 * membership + person rows.
	 */
	private static function ensure_user_for_completed_checkout( $membership_id ) {
		$membership = Memberships_Repository::get( $membership_id );
		if ( ! $membership ) {
			return 0;
		}

		if ( ! empty( $membership['primary_user_id'] ) ) {
			return (int) $membership['primary_user_id'];
		}

		$person = People_Repository::get_primary_by_membership( (int) $membership_id );
		if ( empty( $person['email'] ) || ! is_email( $person['email'] ) ) {
			return 0;
		}

		$email     = (string) $person['email'];
		$full_name = isset( $person['full_name'] ) ? (string) $person['full_name'] : '';

		$existing = email_exists( $email );
		if ( $existing ) {
			$user_id = (int) $existing;
		} else {
			$email_parts   = explode( '@', $email );
			$username_base = sanitize_user( $email_parts[0], true );
			$username      = $username_base ?: 'memberistic-member';
			$suffix        = 1;
			while ( username_exists( $username ) ) {
				$username = $username_base . '-' . $suffix;
				$suffix++;
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_email'   => $email,
					'display_name' => $full_name,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return 0;
			}
			if ( function_exists( 'wp_new_user_notification' ) ) {
				wp_new_user_notification( (int) $user_id, null, 'user' );
			}
		}

		Memberships_Repository::update( (int) $membership_id, array( 'primary_user_id' => (int) $user_id ) );
		if ( ! empty( $person['id'] ) ) {
			People_Repository::update( (int) $person['id'], array( 'wp_user_id' => (int) $user_id ) );
		}

		return (int) $user_id;
	}

	public static function create_checkout_session( $membership_id, $plan, $billing_cycle, $email ) {
		$amount   = 'annual' === $billing_cycle ? (float) $plan['annual_price'] : (float) $plan['monthly_price'];
		$interval = 'annual' === $billing_cycle ? 'year' : 'month';

		if ( $amount <= 0 ) {
			return new \WP_Error( 'memberistic_invalid_amount', __( 'The selected plan does not have a valid price.', 'memberistic' ) );
		}

		$success_url = memberistic_get_page_url( 'thank_you_page_id', 'memberistic-thank-you', home_url( '/' ) );
		$cancel_url  = memberistic_get_page_url( 'failed_payment_page_id', 'memberistic-payment-failed', home_url( '/' ) );

		// Guard against a corrupted/unsupported admin currency setting that would
		// make Stripe reject the whole checkout session.
		$currency = strtolower( (string) memberistic_get_setting( 'currency', 'USD' ) );
		if ( ! in_array( $currency, array( 'usd', 'eur', 'gbp', 'cad', 'aud' ), true ) ) {
			$currency = 'usd';
		}

		$payload = array(
			'mode'                                      => 'subscription',
			'customer_email'                            => $email,
			'success_url'                               => add_query_arg( array( 'memberistic_checkout' => 'success', 'membership_id' => $membership_id ), $success_url ),
			'cancel_url'                                => add_query_arg( array( 'memberistic_checkout' => 'cancelled', 'membership_id' => $membership_id ), $cancel_url ),
			'line_items[0][quantity]'                   => 1,
			'line_items[0][price_data][currency]'       => $currency,
			'line_items[0][price_data][unit_amount]'    => (int) round( $amount * 100 ),
			'line_items[0][price_data][recurring][interval]' => $interval,
			'line_items[0][price_data][product_data][name]'  => $plan['name'] . ' Membership',
			'metadata[membership_id]'                   => $membership_id,
			'metadata[plan_id]'                         => $plan['id'],
			'metadata[billing_cycle]'                   => $billing_cycle,
			'subscription_data[metadata][membership_id]'=> $membership_id,
			'subscription_data[metadata][plan_id]'      => $plan['id'],
			'subscription_data[metadata][billing_cycle]'=> $billing_cycle,
		);

		return self::request( 'POST', '/checkout/sessions', $payload );
	}

	public static function request( $method, $endpoint, $payload = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . self::get_secret_key(),
				'Stripe-Version' => self::API_VERSION,
			),
		);

		if ( ! empty( $payload ) ) {
			$args['body'] = $payload;
		}

		$response = wp_remote_request( self::API_BASE . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Stripe request failed.', 'memberistic' );
			return new \WP_Error( 'memberistic_stripe_error', $message, array( 'status' => $code, 'response' => $body ) );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Whether the Stripe webhook secret is configured.
	 *
	 * Used to short-circuit incoming webhook requests before any payload parsing.
	 */
	public static function webhook_is_configured() {
		return '' !== trim( (string) memberistic_get_setting( 'stripe_webhook_secret', '' ) );
	}

	public static function verify_webhook_signature( $payload, $header ) {
		$secret = trim( (string) memberistic_get_setting( 'stripe_webhook_secret', '' ) );

		if ( '' === $secret || '' === $header ) {
			return false;
		}

		$timestamp = '';
		$signature = '';
		$parts     = explode( ',', $header );

		foreach ( $parts as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			}
			if ( 'v1' === $pair[0] ) {
				$signature = $pair[1];
			}
		}

		if ( '' === $timestamp || '' === $signature || abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Stripe webhook dedup: persistent processed-event-id store.
	 *
	 * Backed by an option (FIFO-capped at 500 ids) so a redeploy / object-
	 * cache flush doesn't reset dedup and cause a duplicate Payments row +
	 * duplicate receipt email on Stripe retries.
	 */
	private static function processed_events_option_key() {
		return 'memberistic_stripe_processed_events';
	}

	public static function is_event_processed( $event_id ) {
		$event_id = (string) $event_id;
		if ( '' === $event_id ) {
			return false;
		}
		$list = get_option( self::processed_events_option_key(), array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		return in_array( $event_id, $list, true );
	}

	public static function mark_event_processed( $event_id ) {
		$event_id = (string) $event_id;
		if ( '' === $event_id ) {
			return;
		}
		$list = get_option( self::processed_events_option_key(), array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		if ( in_array( $event_id, $list, true ) ) {
			return;
		}
		$list[] = $event_id;
		// FIFO cap — keep the most recent 500 ids.
		if ( count( $list ) > 500 ) {
			$list = array_slice( $list, -500 );
		}
		update_option( self::processed_events_option_key(), $list, false );
	}

	public static function process_webhook_event( $event ) {
		$type     = isset( $event['type'] ) ? $event['type'] : '';
		$event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		$obj      = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

		// Idempotency: Stripe retries on 5xx (and occasionally double-sends
		// on success). Without dedup, every retry creates a duplicate
		// Payments row, a duplicate Activity row, and a duplicate
		// membership_activated / payment_received email. We persist the
		// processed event ids in a capped option list so dedup survives an
		// object-cache flush (transients used to fail open after a redeploy).
		//
		// The check-then-mark is wrapped in the same MySQL advisory lock used
		// elsewhere in this class (atomic_check_and_increment) — two near-
		// simultaneous deliveries of the same event id (a documented Stripe
		// behavior) would otherwise both read is_event_processed() as false
		// before either finished mark_event_processed(), letting both through.
		if ( '' !== $event_id ) {
			$lock_key = 'stripe_evt_' . $event_id;
			if ( ! self::acquire_lock( $lock_key ) ) {
				// Fail closed: refuse rather than risk double-processing.
				return new WP_Error( 'memberistic_stripe_webhook_locked', __( 'Could not acquire the idempotency lock; try again.', 'memberistic' ) );
			}
			try {
				if ( self::is_event_processed( $event_id ) ) {
					do_action( 'memberistic_stripe_webhook_event_duplicate', $event_id, $type );
					return true;
				}
				self::mark_event_processed( $event_id );
			} finally {
				self::release_lock( $lock_key );
			}
		}

		do_action( 'memberistic_stripe_webhook_event', $type, $obj, $event );

		// Inbound events reflect state Stripe already holds; flag the window
		// so the outbound-cancel listener on the status-changed hook doesn't
		// call Stripe back about a cancellation Stripe just reported.
		self::$processing_inbound_event = true;

		try {
			switch ( $type ) {
				case 'checkout.session.completed':
					return self::handle_checkout_completed( $obj );
				case 'customer.subscription.deleted':
					return self::handle_subscription_deleted( $obj );
				case 'invoice.payment_failed':
					return self::handle_invoice_failed( $obj );
				case 'invoice.payment_succeeded':
					return self::handle_invoice_succeeded( $obj );
				case 'payment_intent.succeeded':
				case 'payment_intent.payment_failed':
					// Payment-intent events are handled by the invoice events for
					// subscriptions; one-off PaymentIntents are not currently used
					// but the hook above lets integrations extend.
					return true;
			}

			return true;
		} finally {
			self::$processing_inbound_event = false;
		}
	}

	/**
	 * Recurring renewal succeeded — bump renewal_date forward and log a payment.
	 *
	 * @param array<string, mixed> $invoice Invoice object payload.
	 */
	private static function handle_invoice_succeeded( $invoice ) {
		$subscription_id = ! empty( $invoice['subscription'] ) ? sanitize_text_field( (string) $invoice['subscription'] ) : '';

		if ( '' === $subscription_id ) {
			return false;
		}

		$membership = Memberships_Repository::get_by_stripe_subscription_id( $subscription_id );

		if ( ! $membership ) {
			return false;
		}

		$membership_id = (int) $membership['id'];
		$billing_cycle = $membership['billing_cycle'];
		// Anchor the new renewal off the EXISTING renewal_date if the
		// member is being renewed before their current term ends — so
		// they keep the paid time. Falls back to "now" for first-time
		// invoices or expired memberships. Site-local time throughout.
		$now_local     = current_time( 'mysql' );
		$current_rd    = ! empty( $membership['renewal_date'] ) ? $membership['renewal_date'] : '';
		$anchor        = ( $current_rd && $current_rd > $now_local ) ? $current_rd : $now_local;
		$renewal_date  = \WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::compute_next_renewal( $billing_cycle, $anchor );

		// Skip the very first invoice that fires on checkout completion — that path is
		// already handled by checkout.session.completed and creates the start_date row.
		$is_first_invoice = ! empty( $invoice['billing_reason'] ) && 'subscription_create' === $invoice['billing_reason'];

		Memberships_Repository::update(
			$membership_id,
			array(
				'status'       => 'active',
				'renewal_date' => $renewal_date,
			)
		);

		// The very first invoice fires alongside checkout.session.completed,
		// which already writes the Payments row + sends the receipt. Skip
		// the create() here to avoid a duplicate Payments row + duplicate
		// receipt for the same initial charge. A secondary safety guards
		// against any other path that might double-write the same txn id.
		if ( ! $is_first_invoice ) {
			$txn_id = isset( $invoice['payment_intent'] )
				? sanitize_text_field( (string) $invoice['payment_intent'] )
				: sanitize_text_field( (string) ( $invoice['id'] ?? '' ) );
			$already = '' !== $txn_id ? Payments_Repository::get_by_gateway_transaction_id( $txn_id ) : null;
			if ( ! $already ) {
				Payments_Repository::create(
					array(
						'membership_id'          => $membership_id,
						'amount'                 => ! empty( $invoice['amount_paid'] ) ? ( (float) $invoice['amount_paid'] / 100 ) : 0,
						'currency'               => ! empty( $invoice['currency'] ) ? strtoupper( $invoice['currency'] ) : 'USD',
						'payment_method'         => 'stripe_subscription',
						'payment_gateway'        => 'stripe',
						'gateway_transaction_id' => $txn_id,
						'status'                 => 'completed',
						'paid_at'                => current_time( 'mysql' ),
						'raw_response'           => $invoice,
					)
				);
			}
		}

		// On renewals (every invoice after the first) send two distinct
		// messages: a transactional charge receipt, then a renewal
		// confirmation. The very first invoice fires on subscription create
		// alongside checkout.session.completed, which already sends the
		// initial receipt + activation email — so we skip it here to avoid a
		// duplicate receipt for the same charge.
		if ( ! $is_first_invoice ) {
			$amount_paid = ! empty( $invoice['amount_paid'] ) ? ( (float) $invoice['amount_paid'] / 100 ) : 0;
			$currency    = ! empty( $invoice['currency'] ) ? strtoupper( $invoice['currency'] ) : 'USD';
			$txn         = isset( $invoice['payment_intent'] ) ? (string) $invoice['payment_intent'] : (string) ( $invoice['id'] ?? '' );

			Email_Service::send_membership_email(
				$membership_id,
				'payment_receipt',
				array(
					'amount'         => \WordPressistic\Memberistic\memberistic_format_price( $amount_paid, $currency ),
					'transaction_id' => $txn,
					'payment_date'   => date_i18n( get_option( 'date_format' ) ),
					'payment_method' => __( 'Card on file (Stripe)', 'memberistic' ),
				)
			);

			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => 'membership_renewed',
					'title'         => __( 'Membership renewed via Stripe', 'memberistic' ),
				)
			);

			// Renewal confirmation — a second, distinct message from the receipt.
			Email_Service::send_membership_email( $membership_id, 'membership_renewed' );
		}

		do_action( 'memberistic_membership_activated', $membership_id );

		return true;
	}

	private static function handle_checkout_completed( $session ) {
		$metadata      = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$membership_id = ! empty( $metadata['membership_id'] ) ? absint( $metadata['membership_id'] ) : 0;

		if ( ! $membership_id ) {
			return false;
		}

		$membership = Memberships_Repository::get( $membership_id );

		if ( ! $membership ) {
			return false;
		}

		// User creation is deferred from checkout-start to here so an
		// unauthenticated visitor can't spray wp_new_user_notification
		// at arbitrary emails by hitting the checkout endpoint.
		self::ensure_user_for_completed_checkout( $membership_id );

		$billing_cycle = $membership['billing_cycle'];
		$start_date    = current_time( 'mysql' );
		// Site-local renewal compute (was UTC-via-gmdate which mis-drifted
		// on non-UTC sites like Mesa AZ).
		$renewal_date  = \WordPressistic\Memberistic\Integrations\WooCommerce_Bridge::compute_next_renewal( $billing_cycle, $start_date );

		Memberships_Repository::update(
			$membership_id,
			array(
				'status'                 => 'active',
				'start_date'             => $start_date,
				'renewal_date'           => $renewal_date,
				'stripe_customer_id'      => isset( $session['customer'] ) ? sanitize_text_field( (string) $session['customer'] ) : '',
				'stripe_subscription_id'  => isset( $session['subscription'] ) ? sanitize_text_field( (string) $session['subscription'] ) : '',
			)
		);

		$checkout_txn_id = isset( $session['payment_intent'] )
			? sanitize_text_field( (string) $session['payment_intent'] )
			: sanitize_text_field( (string) ( isset( $session['id'] ) ? $session['id'] : '' ) );
		$existing_payment = '' !== $checkout_txn_id ? Payments_Repository::get_by_gateway_transaction_id( $checkout_txn_id ) : null;
		if ( ! $existing_payment ) {
			Payments_Repository::create(
				array(
					'membership_id'          => $membership_id,
					'amount'                 => ! empty( $session['amount_total'] ) ? ( (float) $session['amount_total'] / 100 ) : 0,
					'currency'               => ! empty( $session['currency'] ) ? strtoupper( $session['currency'] ) : 'USD',
					'payment_method'         => 'stripe_checkout',
					'payment_gateway'        => 'stripe',
					'gateway_transaction_id' => $checkout_txn_id,
					'status'                 => 'completed',
					'paid_at'                => current_time( 'mysql' ),
					'raw_response'           => $session,
				)
			);
		}

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'activity_type' => 'membership_activated',
				'title'         => __( 'Membership activated by Stripe checkout', 'memberistic' ),
			)
		);

		Email_Service::send_membership_email( $membership_id, 'membership_activated' );

		// Receipt for the initial charge (amount / reference) alongside the
		// welcome/activation email.
		$amount_total = ! empty( $session['amount_total'] ) ? ( (float) $session['amount_total'] / 100 ) : 0;
		if ( $amount_total > 0 ) {
			$currency = ! empty( $session['currency'] ) ? strtoupper( $session['currency'] ) : 'USD';
			$txn      = isset( $session['payment_intent'] ) ? (string) $session['payment_intent'] : (string) ( $session['id'] ?? '' );
			Email_Service::send_membership_email(
				$membership_id,
				'payment_receipt',
				array(
					'amount'         => \WordPressistic\Memberistic\memberistic_format_price( $amount_total, $currency ),
					'transaction_id' => $txn,
					'payment_date'   => date_i18n( get_option( 'date_format' ) ),
					'payment_method' => __( 'Card on file (Stripe)', 'memberistic' ),
				)
			);
		}

		do_action( 'memberistic_membership_activated', $membership_id );

		return true;
	}

	private static function handle_subscription_deleted( $subscription ) {
		// Trust the subscription_id over metadata: metadata can be stale (the
		// subscription may have been edited in the Stripe dashboard, or the
		// metadata.membership_id may point at a different row after a
		// re-subscribe), but stripe_subscription_id is authoritative on our
		// side. Fall back to metadata only when no row maps to the sub id.
		$membership_id = 0;
		if ( ! empty( $subscription['id'] ) ) {
			$existing = Memberships_Repository::get_by_stripe_subscription_id( sanitize_text_field( (string) $subscription['id'] ) );
			if ( $existing ) {
				$membership_id = (int) $existing['id'];
			}
		}

		if ( ! $membership_id && ! empty( $subscription['metadata']['membership_id'] ) ) {
			$membership_id = absint( $subscription['metadata']['membership_id'] );
		}

		if ( ! $membership_id ) {
			return false;
		}

		Memberships_Repository::change_status( $membership_id, 'cancelled' );
		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'activity_type' => 'membership_cancelled',
				'title'         => __( 'Stripe subscription cancelled', 'memberistic' ),
			)
		);

		Email_Service::send_membership_email( $membership_id, 'membership_cancelled' );

		return true;
	}

	private static function handle_invoice_failed( $invoice ) {
		$subscription_id = ! empty( $invoice['subscription'] ) ? sanitize_text_field( (string) $invoice['subscription'] ) : '';

		if ( '' === $subscription_id ) {
			return false;
		}

		$membership = Memberships_Repository::get_by_stripe_subscription_id( $subscription_id );

		if ( ! $membership ) {
			return false;
		}

		Memberships_Repository::change_status( (int) $membership['id'], 'past_due' );
		Activity_Repository::log(
			array(
				'membership_id' => (int) $membership['id'],
				'activity_type' => 'payment_failed',
				'title'         => __( 'Stripe invoice payment failed', 'memberistic' ),
			)
		);

		Email_Service::send_membership_email( (int) $membership['id'], 'payment_failed' );

		return true;
	}

	/**
	 * Atomic "still under cap? then charge one" for a transient-backed
	 * counter — the check and the increment happen as a single step (via a
	 * MySQL advisory lock) so a scripted concurrent burst can't have two
	 * requests both read the same stale count and both slip through.
	 *
	 * @return bool True when the counter was under `$max` and got charged.
	 */
	private static function atomic_check_and_increment( $key, $max, $ttl ) {
		if ( ! self::acquire_lock( $key ) ) {
			// Fail closed: refuse rather than risk an unguarded read-modify-write.
			return false;
		}
		try {
			$count = (int) get_transient( $key );
			if ( $count >= $max ) {
				return false;
			}
			set_transient( $key, $count + 1, $ttl );
			return true;
		} finally {
			self::release_lock( $key );
		}
	}

	/**
	 * MySQL advisory lock. Duck-types `$wpdb` (rather than requiring the
	 * real `wpdb` class) so it silently no-ops if a request context somehow
	 * lacks it; every real WordPress request has `$wpdb`.
	 */
	private static function acquire_lock( $key, $timeout = 3 ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return true;
		}
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'memberistic_rl_lock_' . $key, max( 0, $timeout ) ) );
	}

	private static function release_lock( $key ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'memberistic_rl_lock_' . $key ) );
	}
}
