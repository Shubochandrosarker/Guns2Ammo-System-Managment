<?php
/**
 * Waivers archive — stored, searchable history of every signed waiver
 * (imported from Ottertext/OtterWaiver and going forward), independent of
 * membership. Powers "does this person have a waiver on file?" lookups by
 * email or name at booking / check-in time.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waivers_Archive {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'memberistic_waivers_archive';
	}

	/**
	 * Stable de-dupe key for a person (so repeat signings collapse to one
	 * "current" waiver). Email wins; falls back to name+dob.
	 */
	public static function dedupe_key( $email, $first, $last, $dob ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email ) {
			return 'e:' . $email;
		}
		return 'n:' . strtolower( trim( $first . '|' . $last . '|' . $dob ) );
	}

	/**
	 * Insert a waiver row. Caller passes already-sanitized values.
	 *
	 * @return int Inserted id (0 on failure).
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$row = wp_parse_args( $data, array(
			'first_name'       => '',
			'last_name'        => '',
			'email'            => '',
			'phone'            => '',
			'dob'              => null,
			'signed_at'        => null,
			'source'           => '',
			'participant_type' => '',
			'external_url'     => '',
			'attachment_id'    => null,
			'local_path'       => '',
			'minor_name'       => '',
			'minor_age'        => '',
			'emergency_name'   => '',
			'emergency_phone'  => '',
			'matched_user_id'  => null,
			'is_current'       => 1,
			'dedupe_key'       => '',
			'raw_json'         => '',
			'import_batch'     => '',
			'created_at'       => current_time( 'mysql' ),
		) );
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Mark all rows sharing a de-dupe key as not-current except $keep_id, so
	 * each person resolves to exactly one current waiver (the latest signed).
	 */
	public static function set_current( $dedupe_key, $keep_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 0 WHERE dedupe_key = %s AND id <> %d", $dedupe_key, (int) $keep_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 1 WHERE id = %d", (int) $keep_id ) );
		// phpcs:enable
	}

	/**
	 * The current, NOT-EXPIRED waiver row for a person, looked up by email
	 * first, then by last name + DOB, then by full name. Returns null when
	 * nothing valid is on file.
	 *
	 * This archive table has no explicit expires_at column (it's populated
	 * largely from historical Ottertext/OtterWaiver imports), so "current"
	 * previously meant only "the newest of possibly several re-signs" with
	 * no age limit at all — a waiver signed years ago would satisfy this
	 * lookup forever. We now apply the same validity window Memberistic uses
	 * for member waivers elsewhere (Waiver_Service::validity_days(), default
	 * 365 days) against signed_at, so a stale signature no longer silently
	 * satisfies the booking/check-in gate. A row with no signed_at at all is
	 * treated as unverifiable-age and excluded rather than trusted forever.
	 *
	 * @param string $email
	 * @param string $name  "First Last" (optional).
	 * @param string $dob   Y-m-d (optional, sharpens the name match).
	 * @return array|null
	 */
	public static function find_on_file( $email, $name = '', $dob = '' ) {
		global $wpdb;
		$table  = self::table();
		$email  = strtolower( trim( (string) $email ) );
		$cutoff = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( Waiver_Service::validity_days() * DAY_IN_SECONDS ) );

		if ( '' !== $email ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s AND is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s ORDER BY signed_at DESC LIMIT 1", $email, $cutoff ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}

		$name = trim( (string) $name );
		if ( '' !== $name ) {
			$parts = preg_split( '/\s+/', $name );
			$last  = array_pop( $parts );
			$first = implode( ' ', $parts );
			$dob   = trim( (string) $dob );

			if ( '' !== $dob ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE last_name = %s AND dob = %s AND is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s ORDER BY signed_at DESC LIMIT 1", $last, $dob, $cutoff ), ARRAY_A );
				if ( $row ) {
					return $row;
				}
			}
			if ( '' !== $first ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE last_name = %s AND first_name = %s AND is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s ORDER BY signed_at DESC LIMIT 1", $last, $first, $cutoff ), ARRAY_A );
				if ( $row ) {
					return $row;
				}
			}
		}
		return null;
	}

	/** Convenience boolean. Only true when a non-expired waiver is on file. */
	public static function has_on_file( $email, $name = '', $dob = '' ) {
		return null !== self::find_on_file( $email, $name, $dob );
	}

	/**
	 * The public/staff URL to view a waiver PDF for an archive row. Prefers the
	 * locally-mirrored attachment; falls back to the external Ottertext URL.
	 */
	public static function pdf_url( array $row ) {
		if ( ! empty( $row['attachment_id'] ) ) {
			$url = wp_get_attachment_url( (int) $row['attachment_id'] );
			if ( $url ) {
				return $url;
			}
		}
		return ! empty( $row['external_url'] ) ? (string) $row['external_url'] : '';
	}

	/** Aggregate stats for the admin screen. */
	public static function stats() {
		global $wpdb;
		$table = self::table();
		return array(
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore
			'current'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_current = 1" ), // phpcs:ignore
			'with_pdf'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE attachment_id IS NOT NULL" ), // phpcs:ignore
			'matched'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE matched_user_id IS NOT NULL" ), // phpcs:ignore
		);
	}
}
