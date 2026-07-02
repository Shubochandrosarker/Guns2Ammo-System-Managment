<?php
/**
 * G2AB_Events — data access + helpers for the Events system.
 *
 * An "event" is a scheduled, seat-limited activity (Ladies Night, a CCW
 * class, a competition). Each event owns one or more *occurrences* — a
 * specific date/time with its own seat count and (optionally) its own price.
 * Booking an occurrence creates a normal row in `g2ab_bookings` that carries
 * `event_id` + `event_occurrence_id` so the rest of the engine (front desk,
 * calendar, payments, email) treats it like any other booking.
 *
 * This mirrors the model the user asked for (Amelia-style events): pick an
 * event, pick a date/time, reserve a seat — free or paid, single price.
 *
 * @package G2AB
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Events {

	/** Booking statuses that still occupy a seat. */
	const ACTIVE_STATUSES = array( 'pending', 'reserved', 'confirmed', 'paid', 'completed' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'g2ab_events';
	}

	public static function occ_table() {
		global $wpdb;
		return $wpdb->prefix . 'g2ab_event_occurrences';
	}

	/* ────────────────────────────────────────────────────────────────
	 * Reads
	 * ──────────────────────────────────────────────────────────────── */

	/**
	 * List events.
	 *
	 * @param array $args status, category, limit, include_drafts, search.
	 * @return array of event rows (objects).
	 */
	public static function get_events( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args( $args, array(
			'status'   => 'publish',
			'category' => '',
			'search'   => '',
			'limit'    => 100,
			'orderby'  => 'sort_order',
		) );

		$where = array( '1=1' );
		$prep  = array();

		if ( $args['status'] && 'any' !== $args['status'] ) {
			$where[] = 'status = %s';
			$prep[]  = sanitize_key( $args['status'] );
		}
		if ( $args['category'] ) {
			$where[] = 'category = %s';
			$prep[]  = sanitize_key( $args['category'] );
		}
		if ( $args['search'] ) {
			$where[] = '(title LIKE %s OR summary LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$prep[]  = $like;
			$prep[]  = $like;
		}

		$order = 'sort_order' === $args['orderby'] ? 'sort_order ASC, title ASC' : 'created_at DESC';
		$table = self::table();
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order} LIMIT %d";
		$prep[] = max( 1, (int) $args['limit'] );

		return $wpdb->get_results( $wpdb->prepare( $sql, $prep ) ); // phpcs:ignore
	}

	/**
	 * Fetch a single event by numeric id or slug.
	 */
	public static function get_event( $id_or_slug ) {
		global $wpdb;
		$table = self::table();
		if ( is_numeric( $id_or_slug ) ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id_or_slug ) );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $id_or_slug ) ) );
	}

	/**
	 * Occurrences for an event.
	 *
	 * @param int   $event_id
	 * @param array $args upcoming_only, include_cancelled, limit, with_seats.
	 * @return array of occurrence rows, each augmented with ->seats_total,
	 *               ->seats_booked, ->seats_left, ->price_effective when
	 *               with_seats is true (default).
	 */
	public static function get_occurrences( $event_id, $args = array() ) {
		global $wpdb;
		$args = wp_parse_args( $args, array(
			'upcoming_only'      => true,
			'include_cancelled'  => false,
			'limit'              => 50,
			'with_seats'         => true,
		) );

		$occ   = self::occ_table();
		$where = array( 'event_id = %d' );
		$prep  = array( (int) $event_id );

		if ( ! $args['include_cancelled'] ) {
			$where[] = "status <> 'cancelled'";
		}
		if ( $args['upcoming_only'] ) {
			$where[] = 'end_at >= %s';
			$prep[]  = current_time( 'mysql' );
		}

		$sql    = "SELECT * FROM {$occ} WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_at ASC LIMIT %d';
		$prep[] = max( 1, (int) $args['limit'] );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $prep ) ); // phpcs:ignore

		if ( ! $rows ) {
			return array();
		}

		$event = $args['with_seats'] ? self::get_event( $event_id ) : null;
		foreach ( $rows as $row ) {
			if ( $args['with_seats'] ) {
				self::decorate_occurrence( $row, $event );
			}
		}
		return $rows;
	}

	/**
	 * Single occurrence (decorated with seat counts) by id.
	 */
	public static function get_occurrence( $occurrence_id, $decorate = true ) {
		global $wpdb;
		$occ = self::occ_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$occ} WHERE id = %d", (int) $occurrence_id ) );
		if ( $row && $decorate ) {
			self::decorate_occurrence( $row, self::get_event( $row->event_id ) );
		}
		return $row;
	}

	/**
	 * Next upcoming, bookable occurrence for an event (or null).
	 */
	public static function next_occurrence( $event_id ) {
		$list = self::get_occurrences( $event_id, array( 'upcoming_only' => true, 'limit' => 1 ) );
		return $list ? $list[0] : null;
	}

	/**
	 * Attach seat math + effective price to an occurrence row in place.
	 */
	public static function decorate_occurrence( $occurrence, $event = null ) {
		if ( ! $event ) {
			$event = self::get_event( $occurrence->event_id );
		}
		$total = (int) $occurrence->capacity;
		if ( $total <= 0 && $event ) {
			$total = (int) $event->capacity;
		}
		$booked = self::booked_seats( (int) $occurrence->id );

		$occurrence->seats_total  = $total;
		$occurrence->seats_booked = $booked;
		$occurrence->seats_left   = max( 0, $total - $booked );

		$price = ( null !== $occurrence->price ) ? (float) $occurrence->price : ( $event ? (float) $event->price : 0.0 );
		if ( $event && (int) $event->is_free === 1 ) {
			$price = 0.0;
		}
		$occurrence->price_effective = $price;
		return $occurrence;
	}

	/**
	 * Seats already reserved for an occurrence (sum of party sizes on
	 * non-cancelled bookings).
	 */
	public static function booked_seats( $occurrence_id ) {
		global $wpdb;
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$in       = "'" . implode( "','", array_map( 'esc_sql', self::ACTIVE_STATUSES ) ) . "'";
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(party_size),0) FROM {$bookings}
			  WHERE event_occurrence_id = %d AND status IN ({$in})",
			(int) $occurrence_id
		) );
	}

	/**
	 * Effective price for an occurrence for a given viewer.
	 *
	 * Member pricing precedence (when the viewer is an active member):
	 *   1. a fixed member_price set on the event (incl. 0 = free for members)
	 *   2. otherwise a member_discount percentage applied to the base price
	 *   3. otherwise the base price
	 */
	public static function price_for( $event, $occurrence, $is_member = false ) {
		if ( (int) $event->is_free === 1 ) {
			return 0.0;
		}
		$base = ( $occurrence && null !== $occurrence->price ) ? (float) $occurrence->price : (float) $event->price;
		$base = max( 0.0, $base );

		if ( $is_member ) {
			if ( property_exists( $event, 'member_price' ) && null !== $event->member_price ) {
				return max( 0.0, (float) $event->member_price );
			}
			$discount = property_exists( $event, 'member_discount' ) ? (float) $event->member_discount : 0.0;
			if ( $discount > 0 ) {
				$discount = min( 100, $discount );
				return round( $base * ( 1 - $discount / 100 ), 2 );
			}
		}
		return $base;
	}

	/**
	 * Format a naive, site-local datetime string for display WITHOUT shifting
	 * timezones. Occurrence/booking datetimes are stored as site-local strings
	 * (the same convention the booking engine uses), so feeding them through
	 * strtotime()+wp_date() would wrongly treat them as UTC and shift them.
	 */
	public static function format_local( $sql, $format = 'D, M j · g:i A' ) {
		$sql = (string) $sql;
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		$dt  = $tz
			? date_create_immutable_from_format( 'Y-m-d H:i:s', $sql, $tz )
			: date_create_immutable( $sql );
		if ( ! $dt ) {
			// Tolerate a missing-seconds value.
			$dt = $tz ? date_create_immutable_from_format( 'Y-m-d H:i', substr( $sql, 0, 16 ), $tz ) : false;
		}
		if ( ! $dt ) {
			return $sql;
		}
		return function_exists( 'wp_date' ) ? wp_date( $format, $dt->getTimestamp(), $tz ) : $dt->format( $format );
	}

	/**
	 * True Unix timestamp for a naive site-local datetime string. Use this for
	 * countdowns / comparisons so the epoch reflects the site timezone rather
	 * than being mis-read as UTC.
	 */
	public static function timestamp( $sql ) {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		$dt = $tz
			? date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $sql, $tz )
			: date_create_immutable( (string) $sql );
		return $dt ? $dt->getTimestamp() : (int) strtotime( (string) $sql );
	}

	/* ────────────────────────────────────────────────────────────────
	 * Writes
	 * ──────────────────────────────────────────────────────────────── */

	public static function create_event( $data ) {
		global $wpdb;
		$now   = current_time( 'mysql' );
		$clean = self::sanitize_event( $data );
		$clean['slug']       = self::unique_slug( $clean['slug'] ?: $clean['title'] );
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;

		$ok = $wpdb->insert( self::table(), $clean );
		return $ok ? (int) $wpdb->insert_id : new WP_Error( 'g2ab_event_insert_failed', __( 'Could not create event.', 'g2a-booking' ) );
	}

	public static function update_event( $id, $data ) {
		global $wpdb;
		$id    = (int) $id;
		$clean = self::sanitize_event( $data );
		// Keep an existing slug stable unless a new non-empty one is supplied.
		if ( empty( $data['slug'] ) ) {
			unset( $clean['slug'] );
		} else {
			$clean['slug'] = self::unique_slug( $clean['slug'], $id );
		}
		$clean['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( self::table(), $clean, array( 'id' => $id ) );
	}

	public static function delete_event( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::occ_table(), array( 'event_id' => $id ) );
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	public static function add_occurrence( $event_id, $data ) {
		global $wpdb;
		$event_id = (int) $event_id;
		$start    = self::normalize_datetime( $data['start_at'] ?? '' );
		if ( ! $start ) {
			return new WP_Error( 'g2ab_occ_bad_start', __( 'Invalid occurrence start time.', 'g2a-booking' ) );
		}
		$event    = self::get_event( $event_id );
		$duration = $event ? (int) $event->duration_min : 120;
		$end      = self::normalize_datetime( $data['end_at'] ?? '' );
		if ( ! $end ) {
			$end = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +' . max( 15, $duration ) . ' minutes' ) );
		}
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( self::occ_table(), array(
			'event_id'   => $event_id,
			'start_at'   => $start,
			'end_at'     => $end,
			'capacity'   => max( 0, (int) ( $data['capacity'] ?? 0 ) ),
			'price'      => ( isset( $data['price'] ) && '' !== $data['price'] && null !== $data['price'] ) ? round( (float) $data['price'], 2 ) : null,
			'status'     => sanitize_key( $data['status'] ?? 'scheduled' ) ?: 'scheduled',
			'note'       => isset( $data['note'] ) ? sanitize_text_field( $data['note'] ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : new WP_Error( 'g2ab_occ_insert_failed', __( 'Could not add occurrence.', 'g2a-booking' ) );
	}

	public static function delete_occurrence( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( self::occ_table(), array( 'id' => (int) $id ) );
	}

	/* ────────────────────────────────────────────────────────────────
	 * Internal booking type — gives event bookings a valid (NOT NULL)
	 * booking_type_id while keeping the real linkage on event_occurrence_id.
	 * ──────────────────────────────────────────────────────────────── */

	public static function get_or_create_event_booking_type() {
		global $wpdb;
		$bt   = $wpdb->prefix . 'g2ab_booking_types';
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE slug = %s", 'event-seat' ) );
		if ( $row ) {
			return $row;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( $bt, array(
			'name'            => 'Event Seat',
			'slug'            => 'event-seat',
			'category'        => 'event',
			'duration_min'    => 120,
			'base_price'      => 0.00,
			'payment_modes'   => 'full,free,in_store',
			'requires_waiver' => 0,
			'members_only'    => 0,
			'capacity_mode'   => 'party_size',
			'is_active'       => 1,
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE id = %d", (int) $wpdb->insert_id ) );
	}

	/* ────────────────────────────────────────────────────────────────
	 * Helpers
	 * ──────────────────────────────────────────────────────────────── */

	public static function categories() {
		return apply_filters( 'g2ab_event_categories', array(
			'event'       => __( 'General Event', 'g2a-booking' ),
			'ladies'      => __( 'Ladies Night', 'g2a-booking' ),
			'ccw-class'   => __( 'CCW / Class', 'g2a-booking' ),
			'competition' => __( 'Competition', 'g2a-booking' ),
			'private'     => __( 'Private Event', 'g2a-booking' ),
		) );
	}

	public static function category_label( $key ) {
		$cats = self::categories();
		return $cats[ $key ] ?? ucwords( str_replace( '-', ' ', (string) $key ) );
	}

	private static function sanitize_event( $data ) {
		$out = array();
		if ( isset( $data['title'] ) )         $out['title']           = sanitize_text_field( $data['title'] );
		if ( isset( $data['slug'] ) )          $out['slug']            = sanitize_title( $data['slug'] );
		if ( isset( $data['category'] ) )      $out['category']        = sanitize_key( $data['category'] ) ?: 'event';
		if ( isset( $data['summary'] ) )       $out['summary']         = sanitize_text_field( $data['summary'] );
		if ( isset( $data['description'] ) )   $out['description']      = wp_kses_post( $data['description'] );
		if ( isset( $data['price'] ) )         $out['price']           = round( (float) $data['price'], 2 );
		if ( array_key_exists( 'member_price', $data ) ) {
			$out['member_price'] = ( '' === $data['member_price'] || null === $data['member_price'] ) ? null : round( (float) $data['member_price'], 2 );
		}
		if ( isset( $data['member_discount'] ) ) {
			$out['member_discount'] = max( 0, min( 100, round( (float) $data['member_discount'], 2 ) ) );
		}
		if ( isset( $data['is_free'] ) )       $out['is_free']         = (int) ! empty( $data['is_free'] );
		if ( isset( $data['capacity'] ) )      $out['capacity']        = max( 0, (int) $data['capacity'] );
		if ( isset( $data['duration_min'] ) )  $out['duration_min']    = max( 15, (int) $data['duration_min'] );
		if ( isset( $data['requires_waiver'] ) ) $out['requires_waiver'] = (int) ! empty( $data['requires_waiver'] );
		if ( isset( $data['members_only'] ) )  $out['members_only']    = (int) ! empty( $data['members_only'] );
		if ( array_key_exists( 'form_id', $data ) ) $out['form_id']    = $data['form_id'] ? (int) $data['form_id'] : null;
		if ( isset( $data['image_url'] ) )     $out['image_url']       = esc_url_raw( $data['image_url'] );
		if ( isset( $data['location'] ) )      $out['location']        = sanitize_text_field( $data['location'] );
		if ( isset( $data['color'] ) )         $out['color']           = self::sanitize_hex( $data['color'] );
		if ( isset( $data['status'] ) )        $out['status']          = sanitize_key( $data['status'] ) ?: 'publish';
		if ( isset( $data['sort_order'] ) )    $out['sort_order']      = (int) $data['sort_order'];
		return $out;
	}

	private static function sanitize_hex( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : '';
	}

	private static function normalize_datetime( $str ) {
		$str = trim( (string) $str );
		if ( '' === $str ) {
			return '';
		}
		$str = str_replace( 'T', ' ', $str );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $str ) ) {
			$str .= ':00';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str ) ) {
			return '';
		}
		return $str;
	}

	private static function unique_slug( $base, $ignore_id = 0 ) {
		global $wpdb;
		$base  = sanitize_title( $base );
		if ( '' === $base ) {
			$base = 'event';
		}
		$table = self::table();
		$slug  = $base;
		$i     = 2;
		while ( true ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1",
				$slug, (int) $ignore_id
			) );
			if ( ! $exists ) {
				return $slug;
			}
			$slug = $base . '-' . $i;
			$i++;
		}
	}
}
