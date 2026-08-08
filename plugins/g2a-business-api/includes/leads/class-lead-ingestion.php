<?php
/**
 * Native WP `do_action()` listeners that turn Formistic submissions,
 * booking-engine bookings, and Memberistic membership signups into
 * categorized `g2aba_leads` rows.
 *
 * Formistic, G2A Booking Engine, and Memberistic are separate plugins on the
 * same PHP process — this class only ever *listens*; it never modifies
 * those plugins. Every handler:
 *  - guards on the source table existing (mirrors the `SHOW TABLES LIKE`
 *    idiom in Providers\Email_Overview_Provider) so an absent/not-yet-
 *    installed sibling plugin is a silent no-op, not a fatal.
 *  - wraps its body in try/catch(Throwable) so a leads-ingestion bug can
 *    never break the originating plugin's request (a Formistic capture, a
 *    booking, or a membership activation must always succeed regardless of
 *    what happens here). Failures are logged via error_log() only.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lead_Ingestion {
	private const EXCERPT_LENGTH = 300;

	public static function register(): void {
		add_action( 'wpistic_formistic_submission_captured', array( __CLASS__, 'on_formistic_submission_captured' ), 10, 3 );

		add_action( 'g2ab_booking_created', array( __CLASS__, 'on_booking_created' ), 10, 2 );
		add_action( 'g2ab_booking_status_changed', array( __CLASS__, 'on_booking_status_changed' ), 10, 3 );
		add_action( 'g2ab_booking_cancelled', array( __CLASS__, 'on_booking_cancelled' ), 10, 2 );
		add_action( 'g2ab_booking_no_show', array( __CLASS__, 'on_booking_no_show' ), 10, 1 );

		add_action( 'memberistic_membership_created', array( __CLASS__, 'on_membership_created' ), 10, 1 );
		add_action( 'memberistic_membership_activated', array( __CLASS__, 'on_membership_activated' ), 10, 1 );
	}

	/**
	 * @param int                  $id        Formistic submission id.
	 * @param string               $form_name Form name.
	 * @param array<string, mixed> $fields    Label => value map.
	 */
	public static function on_formistic_submission_captured( int $id, string $form_name, array $fields ): void {
		try {
			$contact = self::extract_formistic_contact( $fields );
			$category = Lead_Categorizer::from_formistic( $form_name, $fields );

			( new Leads_Repository() )->upsert_by_source(
				'formistic',
				(string) $id,
				array(
					'category'      => $category,
					'status'        => 'new',
					'contact_name'  => $contact['name'],
					'contact_email' => $contact['email'],
					'contact_phone' => $contact['phone'],
					'subject'       => $contact['subject'] !== '' ? $contact['subject'] : $form_name,
					'excerpt'       => self::truncate( $contact['message'], self::EXCERPT_LENGTH ),
					'meta'          => array(
						'form_name' => $form_name,
						'fields'    => $fields,
					),
				)
			);
		} catch ( \Throwable $e ) {
			error_log( '[G2ABA] Leads: failed to ingest Formistic submission #' . $id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * @param int                  $booking_id Booking id.
	 * @param array<string, mixed> $context    May include uuid, resource_id,
	 *                                          start_at, source — call-site
	 *                                          dependent, do not assume all
	 *                                          keys present.
	 */
	public static function on_booking_created( int $booking_id, array $context = array() ): void {
		try {
			global $wpdb;
			$bookings_table = $wpdb->prefix . 'g2ab_bookings';
			$types_table    = $wpdb->prefix . 'g2ab_booking_types';

			if ( ! self::table_exists( $bookings_table ) || ! self::table_exists( $types_table ) ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$booking = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE id = %d", $booking_id ),
				ARRAY_A
			);
			if ( ! is_array( $booking ) ) {
				return;
			}

			$type_category = '';
			$type_name     = '';
			if ( ! empty( $booking['booking_type_id'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$type = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT category, name FROM {$types_table} WHERE id = %d",
						(int) $booking['booking_type_id']
					),
					ARRAY_A
				);
				if ( is_array( $type ) ) {
					$type_category = (string) ( $type['category'] ?? '' );
					$type_name     = (string) ( $type['name'] ?? '' );
				}
			}

			$category = Lead_Categorizer::from_booking_type( $type_category, $type_name );

			( new Leads_Repository() )->upsert_by_source(
				'booking_engine',
				(string) $booking_id,
				array(
					'category'      => $category,
					'status'        => 'new',
					'contact_name'  => $booking['customer_name'] ?? null,
					'contact_email' => $booking['customer_email'] ?? null,
					'contact_phone' => $booking['customer_phone'] ?? null,
					'subject'       => '' !== $type_name ? $type_name : 'Booking #' . $booking_id,
					'meta'          => array(
						'booking_id'      => $booking_id,
						'booking_type_id' => $booking['booking_type_id'] ?? null,
						'status'          => $booking['status'] ?? null,
						'start_at'        => $booking['start_at'] ?? ( $context['start_at'] ?? null ),
						'context'         => $context,
					),
				)
			);
		} catch ( \Throwable $e ) {
			error_log( '[G2ABA] Leads: failed to ingest booking #' . $booking_id . ': ' . $e->getMessage() );
		}
	}

	public static function on_booking_status_changed( int $booking_id, string $new_status, string $old_status = '' ): void {
		try {
			$repository = new Leads_Repository();
			$lead_id    = $repository->find_id_by_source( 'booking_engine', (string) $booking_id );
			if ( null === $lead_id ) {
				// Only ever updates an already-ingested lead — never creates one.
				return;
			}

			$mapped = self::map_booking_status( $new_status );
			if ( null !== $mapped ) {
				$repository->update_status( $lead_id, $mapped );
			}
		} catch ( \Throwable $e ) {
			error_log( '[G2ABA] Leads: failed to update lead status for booking #' . $booking_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * `g2ab_booking_cancelled` is fired with an inconsistent first argument
	 * across the booking engine's own call sites — sometimes the booking id
	 * (int), sometimes the full booking row (object), depending on call
	 * site. Accept both rather than assume.
	 *
	 * @param int|object $booking_or_id
	 */
	public static function on_booking_cancelled( $booking_or_id, array $context = array() ): void {
		try {
			$booking_id = self::extract_booking_id( $booking_or_id );
			if ( null === $booking_id ) {
				return;
			}
			$repository = new Leads_Repository();
			$lead_id    = $repository->find_id_by_source( 'booking_engine', (string) $booking_id );
			if ( null === $lead_id ) {
				return;
			}
			$repository->update_status( $lead_id, 'lost' );
		} catch ( \Throwable $e ) {
			error_log( '[G2ABA] Leads: failed to mark cancelled booking lost: ' . $e->getMessage() );
		}
	}

	/**
	 * @param int|object $booking_or_id
	 */
	public static function on_booking_no_show( $booking_or_id ): void {
		try {
			$booking_id = self::extract_booking_id( $booking_or_id );
			if ( null === $booking_id ) {
				return;
			}
			$repository = new Leads_Repository();
			$lead_id    = $repository->find_id_by_source( 'booking_engine', (string) $booking_id );
			if ( null === $lead_id ) {
				return;
			}
			$repository->update_status( $lead_id, 'lost' );
		} catch ( \Throwable $e ) {
			error_log( '[G2ABA] Leads: failed to mark no-show booking lost: ' . $e->getMessage() );
		}
	}

	public static function on_membership_created( int $membership_id ): void {
		self::ingest_membership( $membership_id );
	}

	public static function on_membership_activated( int $membership_id ): void {
		self::ingest_membership( $membership_id );
	}

	private static function ingest_membership( int $membership_id ): void {
		try {
			global $wpdb;
			$memberships_table = $wpdb->prefix . 'memberistic_memberships';
			$plans_table       = $wpdb->prefix . 'memberistic_plans';
			$people_table      = $wpdb->prefix . 'memberistic_people';

			if ( ! self::table_exists( $memberships_table ) ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$membership = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$memberships_table} WHERE id = %d", $membership_id ),
				ARRAY_A
			);
			if ( ! is_array( $membership ) ) {
				return;
			}

			$plan_name = '';
			if ( ! empty( $membership['plan_id'] ) && self::table_exists( $plans_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$plan_name = (string) $wpdb->get_var(
					$wpdb->prepare( "SELECT name FROM {$plans_table} WHERE id = %d", (int) $membership['plan_id'] )
				);
			}

			$contact_name  = null;
			$contact_email = null;
			$contact_phone = null;
			if ( self::table_exists( $people_table ) ) {
				// Prefer the "primary" person on the membership; fall back to
				// whichever person row was created first.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$person = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT full_name, email, phone FROM {$people_table}
						 WHERE membership_id = %d
						 ORDER BY (role = 'primary') DESC, id ASC
						 LIMIT 1",
						$membership_id
					),
					ARRAY_A
				);
				if ( is_array( $person ) ) {
					$contact_name  = $person['full_name'] ?? null;
					$contact_email = $person['email'] ?? null;
					$contact_phone = $person['phone'] ?? null;
				}
			}

			( new Leads_Repository() )->upsert_by_source(
				'memberistic',
				(string) $membership_id,
				array(
					'category'      => Lead_Categorizer::from_membership(),
					'status'        => 'new',
					'contact_name'  => $contact_name,
					'contact_email' => $contact_email,
					'contact_phone' => $contact_phone,
					'subject'       => '' !== $plan_name ? $plan_name . ' membership' : 'New membership',
					'meta'          => array(
						'membership_id'  => $membership_id,
						'plan_id'        => $membership['plan_id'] ?? null,
						'plan_name'      => $plan_name,
						'billing_amount' => $membership['billing_amount'] ?? null,
						'status'         => $membership['status'] ?? null,
					),
				)
			);
		} catch ( \Throwable $e ) {
			error_log( '[G2ABA] Leads: failed to ingest membership #' . $membership_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Map a booking-engine status onto a lead status. Returns null when the
	 * booking status has no bearing on lead status (leave the lead as-is).
	 */
	private static function map_booking_status( string $status ): ?string {
		switch ( $status ) {
			case 'confirmed':
			case 'paid':
			case 'completed':
				return 'in_progress';
			case 'cancelled':
			case 'no_show':
			case 'expired':
				return 'lost';
			default:
				return null;
		}
	}

	/**
	 * @param int|object $booking_or_id
	 */
	private static function extract_booking_id( $booking_or_id ): ?int {
		if ( is_object( $booking_or_id ) ) {
			return isset( $booking_or_id->id ) ? (int) $booking_or_id->id : null;
		}
		$id = (int) $booking_or_id;
		return $id > 0 ? $id : null;
	}

	/**
	 * Case-insensitive extraction of name/email/phone/subject/message from a
	 * Formistic label => value field map. Formistic humanizes field keys
	 * into labels (e.g. "Your Name", "Email Address"), so we match on
	 * substring containment rather than exact keys.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{name: ?string, email: ?string, phone: ?string, subject: string, message: string}
	 */
	private static function extract_formistic_contact( array $fields ): array {
		$name    = null;
		$email   = null;
		$phone   = null;
		$subject = '';
		$message = '';

		foreach ( $fields as $label => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$l = strtolower( (string) $label );

			if ( null === $email && ( false !== strpos( $l, 'email' ) || is_email( $value ) ) ) {
				$email = $value;
				continue;
			}
			if ( null === $name && false !== strpos( $l, 'name' ) ) {
				$name = $value;
				continue;
			}
			if ( null === $phone && ( false !== strpos( $l, 'phone' ) || false !== strpos( $l, 'mobile' ) ) ) {
				$phone = $value;
				continue;
			}
			if ( '' === $subject && false !== strpos( $l, 'subject' ) ) {
				$subject = $value;
				continue;
			}
			if ( '' === $message && ( false !== strpos( $l, 'message' ) || false !== strpos( $l, 'note' ) || false !== strpos( $l, 'help' ) ) ) {
				$message = $value;
			}
		}

		return array(
			'name'    => $name,
			'email'   => $email,
			'phone'   => $phone,
			'subject' => $subject,
			'message' => $message,
		);
	}

	private static function truncate( string $text, int $length ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) > $length ) {
			$text = rtrim( substr( $text, 0, $length ) ) . '…';
		}
		return $text;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
