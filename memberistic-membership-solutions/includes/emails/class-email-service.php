<?php
/**
 * Transactional email service.
 *
 * Lifecycle responsibility:
 *  - Render templates with the 20 documented merge tags.
 *  - Dispatch through wp_mail() with branded From headers.
 *  - Log every send (success or failure) to the Activity feed and the
 *    dedicated memberistic_email_logs table so the front desk can audit
 *    renewals, payment-failure follow-ups, and waiver reminders.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Emails;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Email_Logs_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use function WordPressistic\Memberistic\memberistic_get_brand_label;
use function WordPressistic\Memberistic\memberistic_get_page_url;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Service {
	/**
	 * Registered transactional templates exposed to the admin UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function templates() {
		return apply_filters(
			'memberistic_email_templates',
			array(
				array(
					'id'          => 'membership_created',
					'label'       => __( 'Membership Created', 'memberistic' ),
					'description' => __( 'Confirms a new membership was set up. Sent automatically on checkout; safe to resend.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_activated',
					'label'       => __( 'Membership Activated', 'memberistic' ),
					'description' => __( 'Welcomes a member when their membership becomes active.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_renewed',
					'label'       => __( 'Membership Renewed', 'memberistic' ),
					'description' => __( 'Receipt sent after a successful renewal payment or staff renewal action.', 'memberistic' ),
				),
				array(
					'id'          => 'renewal_reminder',
					'label'       => __( 'Renewal Reminder (Generic)', 'memberistic' ),
					'description' => __( 'Generic renewal reminder used when no specific window template is configured.', 'memberistic' ),
				),
				array(
					'id'          => 'expiring_30_days',
					'label'       => __( 'Expiring in 30 Days', 'memberistic' ),
					'description' => __( 'Heads-up notice 30 days before renewal_date.', 'memberistic' ),
				),
				array(
					'id'          => 'expiring_7_days',
					'label'       => __( 'Expiring in 7 Days', 'memberistic' ),
					'description' => __( 'Sent 7 days before renewal_date.', 'memberistic' ),
				),
				array(
					'id'          => 'expiring_tomorrow',
					'label'       => __( 'Expiring Tomorrow', 'memberistic' ),
					'description' => __( 'Last-day-of-membership reminder.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_expired',
					'label'       => __( 'Membership Expired', 'memberistic' ),
					'description' => __( 'Sent after the membership expires.', 'memberistic' ),
				),
				array(
					'id'          => 'payment_failed',
					'label'       => __( 'Payment Failed', 'memberistic' ),
					'description' => __( 'Notifies the member that the most recent charge failed.', 'memberistic' ),
				),
				array(
					'id'          => 'membership_cancelled',
					'label'       => __( 'Membership Cancelled', 'memberistic' ),
					'description' => __( 'Confirms cancellation and final billing window.', 'memberistic' ),
				),
				array(
					'id'          => 'linked_member_added',
					'label'       => __( 'Linked Member Added', 'memberistic' ),
					'description' => __( 'Confirms a new linked / family member was attached to the membership.', 'memberistic' ),
				),
				array(
					'id'          => 'waiver_missing',
					'label'       => __( 'Waiver Missing', 'memberistic' ),
					'description' => __( 'Reminds the member to complete an outstanding waiver before benefits unlock.', 'memberistic' ),
				),
				array(
					'id'          => 'staff_manual',
					'label'       => __( 'Staff Manual Message', 'memberistic' ),
					'description' => __( 'Generic staff-initiated message sent from the member profile.', 'memberistic' ),
				),
			)
		);
	}

	/**
	 * Whether a template id is supported.
	 *
	 * @param string $template Template id.
	 */
	public static function template_exists( $template ) {
		foreach ( self::templates() as $row ) {
			if ( $row['id'] === $template ) {
				return true;
			}
		}

		return false;
	}

	public static function send_membership_email( $membership_id, $template, $extra_context = array() ) {
		$membership = Memberships_Repository::get_with_summary( absint( $membership_id ) );

		if ( ! $membership ) {
			return false;
		}

		$person = People_Repository::get_primary_by_membership( (int) $membership['id'] );

		if ( empty( $person['email'] ) || ! is_email( $person['email'] ) ) {
			return false;
		}

		// Global kill-switch. Use the MEMBERISTIC_EMAIL_DISABLED constant in
		// wp-config.php (recommended for staging/dev) or the
		// memberistic_emails_disabled option for an admin-toggleable switch.
		if ( ( defined( 'MEMBERISTIC_EMAIL_DISABLED' ) && MEMBERISTIC_EMAIL_DISABLED )
			|| (bool) get_option( 'memberistic_emails_disabled', false ) ) {
			Email_Logs_Repository::log(
				array(
					'membership_id' => (int) $membership['id'],
					'person_id'     => (int) $person['id'],
					'template'      => $template,
					'recipient'     => $person['email'],
					'subject'       => '',
					'status'        => 'suppressed',
					'error_message' => __( 'Suppressed: email kill-switch is enabled.', 'memberistic' ),
				)
			);
			return false;
		}

		// Allow integrators to short-circuit individual templates per membership.
		$should_send = apply_filters( 'memberistic_should_send_email', true, $template, $membership, $person, $extra_context );
		if ( ! $should_send ) {
			return false;
		}

		$context = self::build_context( $membership, $person, $extra_context );
		$message = self::build_message( $template, $context );

		// Staging override: redirect all outbound to a single staff inbox.
		$recipient = $person['email'];
		$override  = defined( 'MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT' )
			? MEMBERISTIC_EMAIL_OVERRIDE_RECIPIENT
			: (string) get_option( 'memberistic_email_override_recipient', '' );
		if ( $override && is_email( $override ) ) {
			$recipient          = $override;
			$message['subject'] = '[REROUTED -> ' . $person['email'] . '] ' . $message['subject'];
		}

		$sent = wp_mail( $recipient, $message['subject'], $message['body'], self::headers() );

		Email_Logs_Repository::log(
			array(
				'membership_id' => (int) $membership['id'],
				'person_id'     => (int) $person['id'],
				'template'      => $template,
				'recipient'     => $recipient,
				'subject'       => $message['subject'],
				'status'        => $sent ? 'sent' : 'failed',
				'error_message' => $sent ? '' : __( 'wp_mail() returned false.', 'memberistic' ),
			)
		);

		if ( $sent ) {
			Activity_Repository::log(
				array(
					'membership_id'       => (int) $membership['id'],
					'person_id'           => (int) $person['id'],
					'activity_type'       => 'email_sent',
					'title'               => sprintf(
						/* translators: %s: email template key */
						__( 'Email sent: %s', 'memberistic' ),
						str_replace( '_', ' ', $template )
					),
					'related_object_type' => 'email',
					'related_object_id'   => $template,
				)
			);
		}

		return $sent;
	}

	/**
	 * Build the merge-tag context array for a membership.
	 *
	 * Exposes the 20 documented merge tags so template overrides via the
	 * `memberistic_email_template_body` / `memberistic_email_template_subject`
	 * filters can stay declarative.
	 */
	private static function build_context( $membership, $person, $extra_context = array() ) {
		$brand        = memberistic_get_brand_label();
		$account_url  = memberistic_get_page_url( 'account_page_id', 'memberistic-account', home_url( '/' ) );
		$renewal_url  = memberistic_get_page_url( 'renewal_page_id', 'memberistic-renewal', $account_url );
		$booking_url  = memberistic_get_page_url( 'plans_page_id', 'memberistic-memberships', home_url( '/' ) );
		$current_user = wp_get_current_user();
		$staff_name   = $current_user && $current_user->exists() ? $current_user->display_name : '';

		$context = array(
			'{member_name}'        => (string) ( $person['full_name'] ?? '' ),
			'{membership_id}'      => (string) ( $membership['membership_uuid'] ?? '' ),
			'{plan_name}'          => (string) ( $membership['plan_name'] ?? '' ),
			'{billing_cycle}'      => ucwords( str_replace( '_', ' ', (string) ( $membership['billing_cycle'] ?? '' ) ) ),
			'{renewal_date}'       => ! empty( $membership['renewal_date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $membership['renewal_date'] ) ) : __( 'Not set', 'memberistic' ),
			'{expiration_date}'    => ! empty( $membership['end_date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $membership['end_date'] ) ) : ( ! empty( $membership['renewal_date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $membership['renewal_date'] ) ) : __( 'Not set', 'memberistic' ) ),
			'{amount}'             => isset( $extra_context['amount'] ) ? (string) $extra_context['amount'] : '',
			'{payment_link}'       => $renewal_url,
			'{renewal_link}'       => $renewal_url,
			'{account_url}'        => $account_url,
			'{booking_url}'        => $booking_url,
			'{business_name}'      => (string) memberistic_get_setting( 'business_name', $brand ),
			'{business_phone}'     => (string) memberistic_get_setting( 'business_phone', '' ),
			'{business_address}'   => (string) memberistic_get_setting( 'business_address', '' ),
			'{site_url}'           => home_url( '/' ),
			'{linked_member_name}' => isset( $extra_context['linked_member_name'] ) ? (string) $extra_context['linked_member_name'] : '',
			'{waiver_status}'      => ucwords( str_replace( '_', ' ', (string) ( $person['waiver_status'] ?? 'missing' ) ) ),
			'{staff_name}'         => $staff_name,
			'{support_email}'      => (string) memberistic_get_setting( 'email_from_address', get_option( 'admin_email' ) ),
			'{logo_url}'           => (string) memberistic_get_setting( 'logo_url', '' ),
			'{brand_label}'        => $brand,
		);

		return apply_filters( 'memberistic_email_merge_tags', $context, $membership, $person, $extra_context );
	}

	private static function build_message( $template, $context ) {
		$defaults = self::default_template_strings();
		$subject  = $defaults[ $template ]['subject'] ?? sprintf( __( '%s membership update', 'memberistic' ), $context['{brand_label}'] );
		$body     = $defaults[ $template ]['body'] ?? '';

		if ( '' === $body ) {
			// Generic body falls back to a status summary.
			$body = __(
				"Hi {member_name},\n\nMembership: {membership_id}\nPlan: {plan_name}\nStatus: {plan_name}\nRenewal: {renewal_date}\n\nView your account here:\n{account_url}\n\n{brand_label}",
				'memberistic'
			);
		}

		$subject = apply_filters( 'memberistic_email_template_subject', $subject, $template, $context );
		$body    = apply_filters( 'memberistic_email_template_body', $body, $template, $context );

		return array(
			'subject' => strtr( $subject, $context ),
			'body'    => strtr( $body, $context ),
		);
	}

	private static function default_template_strings() {
		return array(
			'membership_created'   => array(
				'subject' => __( 'Your {brand_label} membership was started', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership ({membership_id}) was created. Once payment is confirmed, your membership becomes active automatically.\n\nView your account: {account_url}\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'membership_activated' => array(
				'subject' => __( 'Your {brand_label} membership is active', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nWelcome to {brand_label}! Your {plan_name} membership ({membership_id}) is now active.\nBilling cycle: {billing_cycle}\nNext renewal: {renewal_date}\n\nManage your membership: {account_url}\nBook a lane: {booking_url}\n\n{business_name}\n{business_phone}", 'memberistic' ),
			),
			'membership_renewed'   => array(
				'subject' => __( 'Your {brand_label} membership was renewed', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nThanks for renewing your {plan_name} membership ({membership_id}).\nNext renewal: {renewal_date}\n\n{brand_label}\n{account_url}", 'memberistic' ),
			),
			'renewal_reminder'     => array(
				'subject' => __( 'Your {brand_label} membership renewal is coming up', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership renews on {renewal_date}.\n\nManage or renew your membership: {renewal_link}\n\n{brand_label}", 'memberistic' ),
			),
			'expiring_30_days'     => array(
				'subject' => __( '30 days until your {brand_label} membership renews', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership ({membership_id}) renews on {renewal_date} — 30 days from now.\n\nIf you need to update payment details or change plans, you can do that here:\n{account_url}\n\n{brand_label}", 'memberistic' ),
			),
			'expiring_7_days'      => array(
				'subject' => __( '7 days until your {brand_label} membership renews', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nQuick reminder: your {plan_name} membership renews on {renewal_date}.\n\nManage your membership: {account_url}\nRenew now: {renewal_link}\n\n{brand_label}", 'memberistic' ),
			),
			'expiring_tomorrow'    => array(
				'subject' => __( 'Your {brand_label} membership renews tomorrow', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership renews tomorrow, {renewal_date}.\n\nRenew or update payment: {renewal_link}\n\n{brand_label}", 'memberistic' ),
			),
			'membership_expired'   => array(
				'subject' => __( 'Your {brand_label} membership has expired', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership ({membership_id}) has expired. To restore member benefits, renew here:\n{renewal_link}\n\n{brand_label}\n{business_phone}", 'memberistic' ),
			),
			'payment_failed'       => array(
				'subject' => __( 'Action needed for your {brand_label} membership', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nWe could not complete your latest payment for the {plan_name} membership ({membership_id}). Please update payment details to keep the membership active.\n\nUpdate payment: {payment_link}\n\n{brand_label}\n{support_email}", 'memberistic' ),
			),
			'membership_cancelled' => array(
				'subject' => __( 'Your {brand_label} membership was cancelled', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nYour {plan_name} membership ({membership_id}) has been cancelled. If this was unexpected, please reach out to staff.\n\n{business_phone}\n{brand_label}", 'memberistic' ),
			),
			'linked_member_added'  => array(
				'subject' => __( 'A linked member was added to your {brand_label} membership', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\n{linked_member_name} was added as a linked member on your {plan_name} membership ({membership_id}).\n\nView linked members: {account_url}\n\n{brand_label}", 'memberistic' ),
			),
			'waiver_missing'       => array(
				'subject' => __( 'Waiver needed for your {brand_label} membership', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\nA waiver is still needed before membership benefits can be fully used. Please complete the waiver process with staff at your next visit.\n\nMembership: {membership_id}\n{account_url}\n\n{brand_label}", 'memberistic' ),
			),
			'staff_manual'         => array(
				'subject' => __( 'A message from {brand_label}', 'memberistic' ),
				'body'    => __( "Hi {member_name},\n\n{staff_name} from {brand_label} wanted to reach out about your membership ({membership_id}).\n\n{business_phone}\n{brand_label}", 'memberistic' ),
			),
		);
	}

	private static function headers() {
		$from_name  = memberistic_get_setting( 'email_from_name', memberistic_get_brand_label() );
		$from_email = memberistic_get_setting( 'email_from_address', get_option( 'admin_email' ) );
		$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );

		if ( is_email( $from_email ) ) {
			$headers[] = 'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>';
		}

		return $headers;
	}
}
