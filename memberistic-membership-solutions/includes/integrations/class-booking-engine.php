<?php
/**
 * Booking Engine integration hooks.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Booking_Engine {
	public static function register() {
		add_filter( 'memberistic_can_book_as_member', array( self::class, 'can_book_as_member' ), 10, 3 );
		add_filter( 'memberistic_booking_discount', array( self::class, 'booking_discount' ), 10, 3 );
		add_action( 'memberistic_booking_recorded', array( self::class, 'record_booking_activity' ), 10, 3 );
		add_filter( 'g2ab_user_is_member', array( self::class, 'g2ab_user_is_member' ), 10, 4 );
		add_filter( 'g2ab_booking_pricing', array( self::class, 'g2ab_booking_pricing' ), 10, 5 );
		add_filter( 'g2ab_booking_display_pricing', array( self::class, 'g2ab_booking_pricing' ), 10, 4 );
		add_action( 'g2ab_booking_created', array( self::class, 'g2ab_booking_created' ), 10, 2 );
		add_action( 'g2ab_payment_succeeded', array( self::class, 'g2ab_payment_succeeded' ), 10, 3 );
	}

	public static function can_book_as_member( $can_book, $user_id = 0, $booking_type_id = 0 ) {
		$membership = self::get_active_membership_for_user( $user_id );
		return $membership ? true : (bool) $can_book;
	}

	public static function booking_discount( $discount, $user_id = 0, $booking_type_id = 0 ) {
		$membership = self::get_active_membership_for_user( $user_id );

		if ( ! $membership ) {
			return $discount;
		}

		$rules = self::plan_booking_rules( $membership );
		$key   = (string) absint( $booking_type_id );

		if ( isset( $rules[ $key ] ) ) {
			return max( 0, (float) $rules[ $key ] );
		}

		return isset( $rules['default'] ) ? max( 0, (float) $rules['default'] ) : $discount;
	}

	public static function g2ab_user_is_member( $allowed, $user_id, $booking_type, $customer_email = '' ) {
		if ( self::get_active_membership_for_user( $user_id ) ) {
			return true;
		}

		// Audit C27: the previous fallback granted member status
		// (and the member discount via g2ab_booking_pricing below)
		// whenever the SUPPLIED EMAIL matched an active membership,
		// even when the caller wasn't logged in as that member.
		// Anyone who knew a member's email could book at member
		// rates by typing it into the guest form. Now: require a
		// real authenticated user.
		return (bool) $allowed;
	}

	public static function g2ab_booking_pricing( $pricing, $booking_type, $party_size, $user_id, $customer_email = '' ) {
		// Audit C27: same fix as above — discount only applies to
		// the LOGGED-IN member, not anyone who types a member's
		// email into the guest form.
		$membership = self::get_active_membership_for_user( $user_id );

		if ( ! $membership ) {
			return $pricing;
		}

		$subtotal = isset( $pricing['subtotal'] ) ? (float) $pricing['subtotal'] : ( (float) $booking_type->base_price * max( 1, (int) $party_size ) );
		$percent  = self::discount_percent_for_booking_type( $membership, $booking_type );

		if ( $subtotal <= 0 || $percent <= 0 ) {
			$pricing['membership_source'] = 'memberistic';
			return $pricing;
		}

		$discount = round( $subtotal * min( 100, $percent ) / 100, 2 );

		$pricing['discount_amount']         = max( (float) ( $pricing['discount_amount'] ?? 0 ), $discount );
		$pricing['total']                   = max( 0, round( $subtotal - (float) $pricing['discount_amount'], 2 ) );
		$pricing['member_discount_percent'] = min( 100, $percent );
		// Carry the PLAN id (not the membership row id) — the booking engine looks
		// this up against the plans table for role/label assignment.
		$pricing['membership_level_id']     = (int) ( $membership['plan_id'] ?? 0 );
		$pricing['membership_source']       = 'memberistic';
		$pricing['discount_label']          = sprintf(
			/* translators: %s: discount percentage */
			__( 'Members Hub Discount Applied: %s%%', 'memberistic' ),
			rtrim( rtrim( number_format( $percent, 2 ), '0' ), '.' )
		);

		return $pricing;
	}

	public static function record_booking_activity( $membership_id, $person_id = 0, $booking_id = 0 ) {
		$membership_id = absint( $membership_id );

		if ( ! $membership_id ) {
			return;
		}

		Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'person_id'           => absint( $person_id ),
				'activity_type'       => 'booking_created',
				'title'               => __( 'Booking connected to membership', 'memberistic' ),
				'related_object_type' => 'booking',
				'related_object_id'   => sanitize_text_field( (string) $booking_id ),
			)
		);
	}

	public static function g2ab_booking_created( $booking_id, $context = array() ) {
		$booking = self::get_g2ab_booking( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$membership = self::get_active_membership_for_booking( $booking );

		if ( ! $membership ) {
			return;
		}

		$person = People_Repository::get_primary_by_membership( (int) $membership['id'] );
		self::record_booking_activity( (int) $membership['id'], $person ? (int) $person['id'] : 0, $booking_id );
		self::attach_memberistic_metadata_to_g2ab_booking( $booking, $membership, $person );
	}

	public static function g2ab_payment_succeeded( $booking_id, $gateway, $payload ) {
		$booking = self::get_g2ab_booking( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$membership = self::get_active_membership_for_booking( $booking );

		if ( ! $membership ) {
			return;
		}

		Activity_Repository::log(
			array(
				'membership_id'       => (int) $membership['id'],
				'activity_type'       => 'booking_completed',
				'title'               => __( 'Booking payment completed', 'memberistic' ),
				'related_object_type' => 'g2ab_booking',
				'related_object_id'   => (string) $booking_id,
			)
		);
	}

	public static function get_active_membership_for_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$membership = Memberships_Repository::get_by_user_id( $user_id );

		if ( self::membership_is_bookable( $membership ) ) {
			return $membership;
		}

		$user = get_user_by( 'id', $user_id );

		if ( $user && ! empty( $user->user_email ) ) {
			$person = People_Repository::get_by_email( $user->user_email );

			if ( $person ) {
				$membership = Memberships_Repository::get( (int) $person['membership_id'] );
				if ( self::membership_is_bookable( $membership ) ) {
					return $membership;
				}
			}
		}

		return null;
	}

	public static function get_active_membership_for_email( $email ) {
		$email = sanitize_email( (string) $email );

		if ( ! is_email( $email ) ) {
			return null;
		}

		$membership = Memberships_Repository::get_by_person_email( $email );
		return self::membership_is_bookable( $membership ) ? $membership : null;
	}

	public static function get_active_membership_for_booking( $booking ) {
		if ( ! empty( $booking->user_id ) ) {
			$membership = self::get_active_membership_for_user( (int) $booking->user_id );
			if ( $membership ) {
				return $membership;
			}
		}

		if ( ! empty( $booking->customer_email ) ) {
			$membership = Memberships_Repository::get_by_person_email( sanitize_email( $booking->customer_email ) );
			if ( self::membership_is_bookable( $membership ) ) {
				return $membership;
			}
		}

		return null;
	}

	public static function get_bookings_for_membership( $membership_id ) {
		global $wpdb;

		$person = People_Repository::get_primary_by_membership( absint( $membership_id ) );

		if ( ! $person || empty( $person['email'] ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'g2ab_bookings';

		if ( self::table_missing( $table ) ) {
			return array();
		}

		$resources = $wpdb->prefix . 'g2ab_resources';
		$types     = $wpdb->prefix . 'g2ab_booking_types';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, r.name AS resource_name, bt.name AS booking_type_name FROM {$table} b LEFT JOIN {$resources} r ON r.id = b.resource_id LEFT JOIN {$types} bt ON bt.id = b.booking_type_id WHERE b.customer_email = %s ORDER BY b.start_at DESC LIMIT 100",
				$person['email']
			),
			ARRAY_A
		) ?: array();
	}

	private static function discount_percent_for_booking_type( $membership, $booking_type ) {
		$booking_type_id = isset( $booking_type->id ) ? (int) $booking_type->id : 0;
		$rules           = self::plan_booking_rules( $membership );
		$key             = (string) $booking_type_id;

		if ( isset( $rules[ $key ] ) ) {
			return max( 0, (float) $rules[ $key ] );
		}

		if ( isset( $rules['default'] ) ) {
			return max( 0, (float) $rules['default'] );
		}

		return isset( $booking_type->member_discount ) ? max( 0, (float) $booking_type->member_discount ) : 0;
	}

	/**
	 * Booking discount rules for a membership, read from its PLAN's settings.
	 * The memberistic_memberships table has no `settings` column — only
	 * memberistic_plans does — so resolve the plan to read the rules. Without
	 * this, $rules was always empty and members never got their plan discount.
	 *
	 * @param array $membership Membership row (must include plan_id).
	 * @return array<string,mixed> Map of booking_type_id|'default' => percent.
	 */
	private static function plan_booking_rules( $membership ) {
		$plan_id = isset( $membership['plan_id'] ) ? (int) $membership['plan_id'] : 0;
		if ( ! $plan_id ) {
			return array();
		}
		$plan     = Plans_Repository::get( $plan_id );
		$settings = ( is_array( $plan ) && ! empty( $plan['settings'] ) ) ? json_decode( (string) $plan['settings'], true ) : array();
		if ( ! is_array( $settings ) || empty( $settings['booking_discounts'] ) || ! is_array( $settings['booking_discounts'] ) ) {
			return array();
		}
		return $settings['booking_discounts'];
	}

	private static function membership_is_bookable( $membership ) {
		if ( ! is_array( $membership ) || empty( $membership['status'] ) ) {
			return false;
		}

		if ( ! in_array( $membership['status'], array( 'active', 'comped', 'trial' ), true ) ) {
			return false;
		}

		// No / zero renewal date = non-expiring. Catch both the DATE ('0000-00-00')
		// and the DATETIME ('0000-00-00 00:00:00') zero values.
		$renewal_raw = (string) ( $membership['renewal_date'] ?? '' );
		if ( '' === $renewal_raw || 0 === strpos( $renewal_raw, '0000-00-00' ) ) {
			return true;
		}

		// renewal_date is a DATETIME ('Y-m-d H:i:s'), so take only the date part and
		// compare the END of that day in SITE time. Appending ' 23:59:59' to the full
		// value would be a "double time specification" and throw. Using wp_timezone()
		// avoids a server-UTC vs site-TZ mismatch that could expire a member early.
		$date_part = substr( $renewal_raw, 0, 10 );
		$renewal   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $date_part . ' 23:59:59', wp_timezone() );
		if ( ! $renewal ) {
			return true; // unparseable → be permissive rather than locking a member out
		}
		return $renewal->getTimestamp() >= time();
	}

	private static function get_g2ab_booking( $booking_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_bookings';

		if ( self::table_missing( $table ) ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $booking_id ) ) );
	}

	private static function attach_memberistic_metadata_to_g2ab_booking( $booking, $membership, $person ) {
		global $wpdb;

		$table = $wpdb->prefix . 'g2ab_bookings';
		$meta  = ! empty( $booking->metadata ) ? json_decode( (string) $booking->metadata, true ) : array();
		$meta  = is_array( $meta ) ? $meta : array();

		$meta['memberistic'] = array(
			'membership_id'    => (int) $membership['id'],
			'membership_uuid'  => (string) $membership['membership_uuid'],
			'person_id'        => $person ? (int) $person['id'] : 0,
			'membership_status'=> (string) $membership['status'],
		);

		$wpdb->update(
			$table,
			array( 'metadata' => wp_json_encode( $meta ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $booking->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function table_missing( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table;
	}
}
