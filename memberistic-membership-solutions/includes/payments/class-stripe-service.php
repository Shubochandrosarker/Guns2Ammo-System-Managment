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
use function WordPressistic\Memberistic\memberistic_get_page_url;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stripe_Service {
	const API_BASE    = 'https://api.stripe.com/v1';
	const API_VERSION = '2024-04-10';

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

	public static function handle_checkout_request() {
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'memberistic_checkout' ) ) {
			wp_die( esc_html__( 'Checkout request could not be verified.', 'memberistic' ) );
		}

		if ( ! self::is_enabled() ) {
			wp_die( esc_html__( 'Stripe checkout is not enabled yet.', 'memberistic' ) );
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

		if ( ! in_array( $billing_cycle, array( 'monthly', 'annual' ), true ) ) {
			$billing_cycle = 'monthly';
		}

		$user_id = self::resolve_checkout_user( $email, $full_name );

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

		$payload = array(
			'mode'                                      => 'subscription',
			'customer_email'                            => $email,
			'success_url'                               => add_query_arg( array( 'memberistic_checkout' => 'success', 'membership_id' => $membership_id ), $success_url ),
			'cancel_url'                                => add_query_arg( array( 'memberistic_checkout' => 'cancelled', 'membership_id' => $membership_id ), $cancel_url ),
			'line_items[0][quantity]'                   => 1,
			'line_items[0][price_data][currency]'       => strtolower( memberistic_get_setting( 'currency', 'USD' ) ),
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

	public static function process_webhook_event( $event ) {
		$type     = isset( $event['type'] ) ? $event['type'] : '';
		$event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		$obj      = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

		// Idempotency: Stripe retries on 5xx (and occasionally double-sends
		// on success). Without dedup, every retry creates a duplicate
		// Payments row, a duplicate Activity row, and a duplicate
		// membership_activated / payment_received email. Track event_id
		// in a transient (object-cache fronted in production); skip
		// processing on a repeat.
		if ( '' !== $event_id ) {
			$dedup_key = 'memberistic_stripe_evt_' . md5( $event_id );
			if ( false !== get_transient( $dedup_key ) ) {
				do_action( 'memberistic_stripe_webhook_event_duplicate', $event_id, $type );
				return true;
			}
			set_transient( $dedup_key, time(), 7 * DAY_IN_SECONDS );
		}

		do_action( 'memberistic_stripe_webhook_event', $type, $obj, $event );

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

		Payments_Repository::create(
			array(
				'membership_id'          => $membership_id,
				'amount'                 => ! empty( $invoice['amount_paid'] ) ? ( (float) $invoice['amount_paid'] / 100 ) : 0,
				'currency'               => ! empty( $invoice['currency'] ) ? strtoupper( $invoice['currency'] ) : 'USD',
				'payment_method'         => 'stripe_subscription',
				'payment_gateway'        => 'stripe',
				'gateway_transaction_id' => isset( $invoice['payment_intent'] ) ? sanitize_text_field( (string) $invoice['payment_intent'] ) : sanitize_text_field( (string) ( $invoice['id'] ?? '' ) ),
				'status'                 => 'completed',
				'paid_at'                => current_time( 'mysql' ),
				'raw_response'           => $invoice,
			)
		);

		if ( ! $is_first_invoice ) {
			Activity_Repository::log(
				array(
					'membership_id' => $membership_id,
					'activity_type' => 'membership_renewed',
					'title'         => __( 'Membership renewed via Stripe', 'memberistic' ),
				)
			);

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

		Payments_Repository::create(
			array(
				'membership_id'          => $membership_id,
				'amount'                 => ! empty( $session['amount_total'] ) ? ( (float) $session['amount_total'] / 100 ) : 0,
				'currency'               => ! empty( $session['currency'] ) ? strtoupper( $session['currency'] ) : 'USD',
				'payment_method'         => 'stripe_checkout',
				'payment_gateway'        => 'stripe',
				'gateway_transaction_id' => isset( $session['payment_intent'] ) ? sanitize_text_field( (string) $session['payment_intent'] ) : sanitize_text_field( (string) ( isset( $session['id'] ) ? $session['id'] : '' ) ),
				'status'                 => 'completed',
				'paid_at'                => current_time( 'mysql' ),
				'raw_response'           => $session,
			)
		);

		Activity_Repository::log(
			array(
				'membership_id' => $membership_id,
				'activity_type' => 'membership_activated',
				'title'         => __( 'Membership activated by Stripe checkout', 'memberistic' ),
			)
		);

		Email_Service::send_membership_email( $membership_id, 'membership_activated' );
		do_action( 'memberistic_membership_activated', $membership_id );

		return true;
	}

	private static function handle_subscription_deleted( $subscription ) {
		$membership_id = ! empty( $subscription['metadata']['membership_id'] ) ? absint( $subscription['metadata']['membership_id'] ) : 0;

		// Fallback: subscription cancelled events sometimes don't carry our metadata
		// if the subscription was created outside our flow. Match by sub-id instead.
		if ( ! $membership_id && ! empty( $subscription['id'] ) ) {
			$existing = Memberships_Repository::get_by_stripe_subscription_id( sanitize_text_field( (string) $subscription['id'] ) );
			if ( $existing ) {
				$membership_id = (int) $existing['id'];
			}
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
}
