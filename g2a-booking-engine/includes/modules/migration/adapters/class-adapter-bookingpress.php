<?php
/**
 * BookingPress → G2AB Migration Adapter.
 *
 * Reads from `wp_bookingpress_*` tables.
 * Tested against BookingPress 1.x schema.
 *
 * @package G2AB\Modules\Migration\Adapters
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Migration_Adapter_BookingPress extends G2AB_Migration_Adapter_Base {

	public function id(): string    { return 'bookingpress'; }
	public function label(): string { return 'BookingPress'; }
	public function icon(): string  { return 'P'; }
	public function color(): string { return '#0073AA'; }

	public function description(): string {
		return __( 'Imports services, staff, customers, and appointments from BookingPress.', 'g2a-booking' );
	}

	public function is_available(): bool {
		return $this->table_exists( 'bookingpress_services' ) && $this->table_exists( 'bookingpress_appointment_bookings' );
	}

	public function get_record_counts(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$svc       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookingpress_services" );
		$staff     = $this->table_exists( 'bookingpress_staff_members' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookingpress_staff_members" ) : 0;
		$bookings  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookingpress_appointment_bookings" );
		$payments  = $this->table_exists( 'bookingpress_payment_logs' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookingpress_payment_logs" ) : 0;
		$customers = $this->table_exists( 'bookingpress_customers' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookingpress_customers" ) : 0;

		return array(
			'resources'     => $staff,
			'booking_types' => $svc,
			'bookings'      => $bookings,
			'payments'      => $payments,
			'customers'     => $customers,
		);
	}

	public function get_resources(): iterable {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $this->table_exists( 'bookingpress_staff_members' ) ) return;

		$rows = $wpdb->get_results(
			"SELECT bookingpress_staffmember_id AS id, bookingpress_staffmember_name AS name,
				bookingpress_staffmember_email AS email, bookingpress_staffmember_phone AS phone,
				bookingpress_staffmember_status AS status
			 FROM {$p}bookingpress_staff_members ORDER BY bookingpress_staffmember_id ASC",
			ARRAY_A
		);
		if ( ! $rows ) return;

		foreach ( $rows as $r ) {
			yield array(
				'external_id' => (int) $r['id'],
				'name'        => $r['name'] ?: ( $r['email'] ?: ( 'Staff #' . $r['id'] ) ),
				'slug'        => sanitize_title( ( $r['name'] ?: 'staff' ) . '-' . $r['id'] ),
				'type'        => 'staff',
				'capacity'    => 1,
				'description' => null,
				'is_active'   => ( $r['status'] ?? 'enabled' ) === 'enabled',
				'metadata'    => array(
					'source_email' => $r['email'] ?? null,
					'source_phone' => $r['phone'] ?? null,
				),
			);
		}
	}

	public function get_booking_types(): iterable {
		global $wpdb;
		$p = $wpdb->prefix;

		$rows = $wpdb->get_results(
			"SELECT bookingpress_service_id AS id, bookingpress_service_name AS name,
				bookingpress_service_description AS description, bookingpress_service_price AS price,
				bookingpress_service_duration_val AS duration_val, bookingpress_service_duration_unit AS duration_unit,
				bookingpress_service_status AS status
			 FROM {$p}bookingpress_services ORDER BY bookingpress_service_id ASC",
			ARRAY_A
		);
		if ( ! $rows ) return;

		foreach ( $rows as $r ) {
			$duration_min = (int) $r['duration_val'];
			if ( ( $r['duration_unit'] ?? 'min' ) === 'hour' ) $duration_min *= 60;
			$duration_min = max( 15, $duration_min );

			yield array(
				'external_id'    => (int) $r['id'],
				'name'           => $r['name'] ?: ( 'Service #' . $r['id'] ),
				'slug'           => sanitize_title( ( $r['name'] ?: 'service' ) . '-' . $r['id'] ),
				'category'       => 'lane',
				'duration_min'   => $duration_min,
				'buffer_before'  => 0,
				'buffer_after'   => 0,
				'base_price'     => round( (float) $r['price'], 2 ),
				'deposit_amount' => 0.0,
				'description'    => $r['description'] ?: null,
				'is_active'      => ( $r['status'] ?? 'enabled' ) === 'enabled',
				'metadata'       => array(),
			);
		}
	}

	public function get_bookings( int $offset, int $limit ): iterable {
		global $wpdb;
		$p = $wpdb->prefix;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				ab.bookingpress_appointment_book_id   AS booking_id,
				ab.bookingpress_service_id            AS service_id,
				ab.bookingpress_staffmember_id        AS staff_id,
				ab.bookingpress_customer_name         AS customer_name,
				ab.bookingpress_customer_email        AS customer_email,
				ab.bookingpress_customer_phone        AS customer_phone,
				ab.bookingpress_appointment_date      AS appt_date,
				ab.bookingpress_appointment_time      AS appt_time,
				ab.bookingpress_appointment_end_time  AS appt_end_time,
				ab.bookingpress_appointment_status    AS booking_status,
				ab.bookingpress_payment_method        AS pay_method,
				ab.bookingpress_payment_status        AS pay_status,
				ab.bookingpress_total_payable_amount  AS total_amount,
				ab.bookingpress_total_paid_amount     AS paid_amount,
				ab.bookingpress_appointment_note      AS notes,
				ab.bookingpress_appointment_booking_form_data AS form_data,
				ab.bookingpress_appointment_booking_date AS booking_created,
				ab.bookingpress_total_member          AS persons
			 FROM {$p}bookingpress_appointment_bookings ab
			 ORDER BY ab.bookingpress_appointment_book_id ASC
			 LIMIT %d OFFSET %d",
			$limit, $offset
		), ARRAY_A );

		if ( ! $rows ) return;

		foreach ( $rows as $r ) {
			$start = $this->compose_datetime( $r['appt_date'], $r['appt_time'] );
			$end   = $this->compose_datetime( $r['appt_date'], $r['appt_end_time'] ?: $r['appt_time'] );
			if ( ! $start ) continue;

			if ( ! $end || strtotime( $end ) <= strtotime( $start ) ) {
				$end = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + 3600 );
			}

			$duration_min = max( 15, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 ) );

			$form_data = array();
			if ( ! empty( $r['form_data'] ) ) {
				$decoded = json_decode( $r['form_data'], true );
				if ( is_array( $decoded ) ) $form_data = $decoded;
			}

			$payments = array();
			if ( ! empty( $r['pay_method'] ) || ! empty( $r['paid_amount'] ) ) {
				$payments[] = array(
					'external_id'    => (int) $r['booking_id'], // BP doesn't expose payment IDs cleanly per booking
					'gateway'        => (string) ( $r['pay_method'] ?? '' ),
					'transaction_id' => null,
					'amount'         => round( (float) ( $r['paid_amount'] ?? 0 ), 2 ),
					'currency'       => $this->detect_currency(),
					'status'         => (string) ( $r['pay_status'] ?? 'pending' ),
					'processed_at'   => $this->normalize_datetime( $r['booking_created'] ),
				);
			}

			yield array(
				'external_id'              => (int) $r['booking_id'],
				'external_resource_id'     => $r['staff_id']   ? (int) $r['staff_id']   : null,
				'external_booking_type_id' => $r['service_id'] ? (int) $r['service_id'] : null,
				'customer_name'            => $r['customer_name'] ?: ( $r['customer_email'] ?: __( 'Anonymous', 'g2a-booking' ) ),
				'customer_email'           => sanitize_email( $r['customer_email'] ?? '' ),
				'customer_phone'           => $r['customer_phone'] ?? null,
				'start_at'                 => $start,
				'end_at'                   => $end,
				'duration_min'             => $duration_min,
				'party_size'               => max( 1, (int) ( $r['persons'] ?? 1 ) ),
				'status'                   => (string) ( $r['booking_status'] ?? 'pending' ),
				'total_amount'             => round( (float) ( $r['total_amount'] ?? 0 ), 2 ),
				'paid_amount'              => round( (float) ( $r['paid_amount']  ?? 0 ), 2 ),
				'currency'                 => $this->detect_currency(),
				'form_data'                => $form_data,
				'notes'                    => $r['notes'] ?: null,
				'created_at'               => $this->normalize_datetime( $r['booking_created'] ),
				'payments'                 => $payments,
			);
		}
	}

	public function map_status( string $source_status ): string {
		switch ( strtolower( $source_status ) ) {
			case 'approved': return 'confirmed';
			case 'pending':  return 'pending';
			case 'canceled':
			case 'cancelled':
			case 'rejected': return 'cancelled';
			case 'completed':return 'completed';
			default:         return 'pending';
		}
	}

	public function map_gateway( string $source_gateway ): string {
		switch ( strtolower( $source_gateway ) ) {
			case 'stripe':       return 'stripe';
			case 'paypal':       return 'paypal';
			case 'authorizenet':
			case 'authorize.net':
			case 'authnet':      return 'authnet';
			case 'direct':
			case 'pay_locally':
			case 'onsite':       return 'pay_in_store';
			default:             return 'pay_in_store';
		}
	}

	private function compose_datetime( $date, $time ) {
		if ( ! $date ) return null;
		$ts = strtotime( trim( $date . ' ' . ( $time ?: '00:00:00' ) ) );
		if ( ! $ts ) return null;
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private function normalize_datetime( $raw ) {
		if ( ! $raw ) return null;
		$ts = strtotime( $raw );
		if ( ! $ts ) return null;
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private function detect_currency(): string {
		$cur = get_option( 'bookingpress_default_currency', 'USD' );
		return strtoupper( substr( (string) $cur, 0, 3 ) );
	}
}
