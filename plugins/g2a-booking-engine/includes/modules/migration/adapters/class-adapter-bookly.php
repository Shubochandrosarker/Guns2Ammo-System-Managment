<?php
/**
 * Bookly → G2AB Migration Adapter.
 *
 * Reads from `wp_bookly_*` tables (Bookly free + Pro).
 * Tested against Bookly 22.x schema.
 *
 * @package G2AB\Modules\Migration\Adapters
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Migration_Adapter_Bookly extends G2AB_Migration_Adapter_Base {

	public function id(): string    { return 'bookly'; }
	public function label(): string { return 'Bookly'; }
	public function icon(): string  { return 'B'; }
	public function color(): string { return '#FF6B35'; }

	public function description(): string {
		return __( 'Imports services, staff, customers, and bookings from Bookly (free or Pro).', 'g2a-booking' );
	}

	public function is_available(): bool {
		return $this->table_exists( 'bookly_services' ) && $this->table_exists( 'bookly_appointments' );
	}

	public function get_record_counts(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$svc       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookly_services" );
		$staff     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookly_staff" );
		$bookings  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookly_customer_appointments" );
		$payments  = $this->table_exists( 'bookly_payments' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookly_payments" ) : 0;
		$customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookly_customers" );

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
		$rows = $wpdb->get_results(
			"SELECT id, full_name, email, phone, info, visibility
			 FROM {$p}bookly_staff ORDER BY id ASC",
			ARRAY_A
		);
		if ( ! $rows ) return;
		foreach ( $rows as $r ) {
			$name = $r['full_name'] ?: ( $r['email'] ?: ( 'Staff #' . $r['id'] ) );
			yield array(
				'external_id' => (int) $r['id'],
				'name'        => $name,
				'slug'        => sanitize_title( $name . '-' . $r['id'] ),
				'type'        => 'staff',
				'capacity'    => 1,
				'description' => $r['info'] ?: null,
				'is_active'   => ( $r['visibility'] ?? 'public' ) === 'public',
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
			"SELECT id, title, info, color, visibility, duration, padding_left, padding_right, capacity_min, capacity_max
			 FROM {$p}bookly_services ORDER BY id ASC",
			ARRAY_A
		);
		if ( ! $rows ) return;

		// Pricing lives in bookly_staff_services, take the average/min for the type.
		foreach ( $rows as $r ) {
			$base_price = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(price) FROM {$p}bookly_staff_services WHERE service_id = %d", $r['id']
			) );
			$duration_min = max( 15, (int) ( ( (int) $r['duration'] ) / 60 ) );

			yield array(
				'external_id'    => (int) $r['id'],
				'name'           => $r['title'] ?: ( 'Service #' . $r['id'] ),
				'slug'           => sanitize_title( ( $r['title'] ?: 'service' ) . '-' . $r['id'] ),
				'category'       => 'lane',
				'duration_min'   => $duration_min,
				'buffer_before'  => max( 0, (int) ( ( (int) ( $r['padding_left']  ?? 0 ) ) / 60 ) ),
				'buffer_after'   => max( 0, (int) ( ( (int) ( $r['padding_right'] ?? 0 ) ) / 60 ) ),
				'base_price'     => round( $base_price, 2 ),
				'deposit_amount' => 0.0, // Bookly deposits are per-staff-service, skip for v1.
				'description'    => $r['info'] ?: null,
				'is_active'      => ( $r['visibility'] ?? 'public' ) === 'public',
				'metadata'       => array(
					'source_color'        => $r['color'] ?? null,
					'source_min_capacity' => (int) ( $r['capacity_min'] ?? 1 ),
					'source_max_capacity' => (int) ( $r['capacity_max'] ?? 1 ),
				),
			);
		}
	}

	public function get_bookings( int $offset, int $limit ): iterable {
		global $wpdb;
		$p = $wpdb->prefix;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				ca.id              AS booking_id,
				ca.appointment_id  AS appointment_id,
				ca.customer_id     AS customer_id,
				ca.status          AS booking_status,
				ca.number_of_persons AS persons,
				ca.notes           AS notes,
				ca.custom_fields   AS custom_fields,
				ca.created_at      AS booking_created,
				a.start_date       AS start_at,
				a.end_date         AS end_at,
				a.service_id       AS service_id,
				a.staff_id         AS staff_id,
				a.internal_note    AS internal_notes,
				c.full_name        AS customer_name,
				c.email            AS customer_email,
				c.phone            AS customer_phone
			FROM {$p}bookly_customer_appointments ca
			INNER JOIN {$p}bookly_appointments a ON a.id = ca.appointment_id
			LEFT JOIN  {$p}bookly_customers c    ON c.id = ca.customer_id
			ORDER BY ca.id ASC
			LIMIT %d OFFSET %d",
			$limit, $offset
		), ARRAY_A );

		if ( ! $rows ) return;

		foreach ( $rows as $r ) {
			$start = $this->normalize_datetime( $r['start_at'] );
			$end   = $this->normalize_datetime( $r['end_at'] );
			if ( ! $start || ! $end ) continue;

			$duration_min = max( 15, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 ) );
			$name = $r['customer_name'] ?: ( $r['customer_email'] ?: __( 'Anonymous', 'g2a-booking' ) );

			$form_data = array();
			if ( ! empty( $r['custom_fields'] ) ) {
				$decoded = json_decode( $r['custom_fields'], true );
				if ( is_array( $decoded ) ) $form_data = $decoded;
			}

			$payments = $this->fetch_payments_for_booking( (int) $r['booking_id'] );
			$total = 0.0;
			$paid  = 0.0;
			foreach ( $payments as $pmt ) {
				$total = max( $total, (float) $pmt['amount'] );
				if ( $pmt['status'] === 'completed' ) $paid += (float) $pmt['amount'];
			}

			yield array(
				'external_id'              => (int) $r['booking_id'],
				'external_resource_id'     => $r['staff_id']   ? (int) $r['staff_id']   : null,
				'external_booking_type_id' => $r['service_id'] ? (int) $r['service_id'] : null,
				'customer_name'            => $name,
				'customer_email'           => sanitize_email( $r['customer_email'] ?? '' ),
				'customer_phone'           => $r['customer_phone'] ?? null,
				'start_at'                 => $start,
				'end_at'                   => $end,
				'duration_min'             => $duration_min,
				'party_size'               => max( 1, (int) ( $r['persons'] ?? 1 ) ),
				'status'                   => (string) ( $r['booking_status'] ?? 'pending' ),
				'total_amount'             => round( $total, 2 ),
				'paid_amount'              => round( $paid, 2 ),
				'currency'                 => $this->detect_currency(),
				'form_data'                => $form_data,
				'notes'                    => $r['notes'] ?: ( $r['internal_notes'] ?: null ),
				'created_at'               => $this->normalize_datetime( $r['booking_created'] ),
				'payments'                 => $payments,
			);
		}
	}

	private function fetch_payments_for_booking( int $booking_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $this->table_exists( 'bookly_payments' ) ) return array();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pp.id, pp.total, pp.status, pp.type AS gateway, pp.token AS transaction_id, pp.created_at, pp.gateway_ref_id
			 FROM {$p}bookly_payments pp
			 INNER JOIN {$p}bookly_orders o ON o.payment_id = pp.id
			 INNER JOIN {$p}bookly_customer_appointments ca ON ca.order_id = o.id
			 WHERE ca.id = %d",
			$booking_id
		), ARRAY_A );

		if ( ! $rows ) return array();

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'external_id'    => (int) $r['id'],
				'gateway'        => (string) ( $r['gateway'] ?? '' ),
				'transaction_id' => $r['transaction_id'] ?: ( $r['gateway_ref_id'] ?: null ),
				'amount'         => round( (float) $r['total'], 2 ),
				'currency'       => $this->detect_currency(),
				'status'         => (string) ( $r['status'] ?? 'pending' ),
				'processed_at'   => $this->normalize_datetime( $r['created_at'] ),
			);
		}
		return $out;
	}

	public function map_status( string $source_status ): string {
		switch ( $source_status ) {
			case 'approved': return 'confirmed';
			case 'done':     return 'completed';
			case 'pending':  return 'pending';
			case 'cancelled':
			case 'rejected': return 'cancelled';
			case 'mounted':  return 'pending';
			default:         return 'pending';
		}
	}

	public function map_gateway( string $source_gateway ): string {
		switch ( strtolower( $source_gateway ) ) {
			case 'stripe':         return 'stripe';
			case 'paypal':
			case 'paypal_express': return 'paypal';
			case 'authorize_net':
			case 'authorizenet':   return 'authnet';
			case 'local':
			case 'free':           return 'pay_in_store';
			default:               return 'pay_in_store';
		}
	}

	private function normalize_datetime( $raw ) {
		if ( ! $raw ) return null;
		$ts = strtotime( $raw );
		if ( ! $ts ) return null;
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private function detect_currency(): string {
		$cur = get_option( 'bookly_pmt_currency', 'USD' );
		return strtoupper( substr( (string) $cur, 0, 3 ) );
	}
}
