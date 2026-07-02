<?php
/**
 * Amelia Booking to G2AB migration adapter.
 *
 * Reads from wp_amelia_* tables without loading Amelia plugin code.
 *
 * @package G2AB\Modules\Migration\Adapters
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Migration_Adapter_Amelia extends G2AB_Migration_Adapter_Base {

	public function id(): string    { return 'amelia'; }
	public function label(): string { return 'Amelia Booking'; }
	public function icon(): string  { return 'A'; }
	public function color(): string { return '#1A82E2'; }

	public function description(): string {
		return __( 'Imports Amelia services, employees, customers, appointments, and payments with defensive schema checks.', 'g2a-booking' );
	}

	public function is_available(): bool {
		return $this->table_exists( 'amelia_services' ) && $this->table_exists( 'amelia_appointments' );
	}

	public function get_record_counts(): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$svc       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}amelia_services" );
		$emp       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}amelia_users WHERE type = 'provider'" );
		$bookings  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}amelia_customer_bookings" );
		$payments  = $this->table_exists( 'amelia_payments' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}amelia_payments" )
			: 0;
		$customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}amelia_users WHERE type = 'customer'" );

		return array(
			'resources'     => $emp,
			'booking_types' => $svc,
			'bookings'      => $bookings,
			'payments'      => $payments,
			'customers'     => $customers,
		);
	}

	public function preflight_warnings(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$warnings = array();

		$orphans = (int) $wpdb->get_var(
			"SELECT COUNT(cb.id) FROM {$p}amelia_customer_bookings cb
			 LEFT JOIN {$p}amelia_appointments a ON a.id = cb.appointmentId
			 WHERE a.id IS NULL"
		);
		if ( $orphans > 0 ) {
			$warnings[] = sprintf(
				_n( '%d orphaned booking found (no parent appointment) - it will be skipped.', '%d orphaned bookings found (no parent appointment) - they will be skipped.', $orphans, 'g2a-booking' ),
				$orphans
			);
		}

		$no_email = (int) $wpdb->get_var(
			"SELECT COUNT(cb.id) FROM {$p}amelia_customer_bookings cb
			 LEFT JOIN {$p}amelia_users u ON u.id = cb.customerId
			 WHERE u.email IS NULL OR u.email = ''"
		);
		if ( $no_email > 0 ) {
			$warnings[] = sprintf(
				_n( '%d booking has no customer email - it will import as anonymous.', '%d bookings have no customer email - they will import as anonymous.', $no_email, 'g2a-booking' ),
				$no_email
			);
		}

		return $warnings;
	}

	public function get_resources(): iterable {
		global $wpdb;
		$p = $wpdb->prefix;

		$rows = $wpdb->get_results(
			"SELECT id, firstName, lastName, email, phone, status, note
			 FROM {$p}amelia_users WHERE type = 'provider' ORDER BY id ASC",
			ARRAY_A
		);
		if ( ! $rows ) return;

		foreach ( $rows as $r ) {
			$name = trim( ( $r['firstName'] ?? '' ) . ' ' . ( $r['lastName'] ?? '' ) );
			if ( $name === '' ) $name = $r['email'] ?: ( 'Provider #' . $r['id'] );

			yield array(
				'external_id' => (int) $r['id'],
				'name'        => $name,
				'slug'        => sanitize_title( $name . '-' . $r['id'] ),
				'type'        => 'instructor',
				'capacity'    => 1,
				'description' => $r['note'] ?: null,
				'is_active'   => ( $r['status'] ?? 'visible' ) === 'visible',
				'metadata'    => array(
					'source_email'   => $r['email'] ?? null,
					'source_phone'   => $r['phone'] ?? null,
					'source_adapter' => 'amelia',
				),
			);
		}
	}

	public function get_booking_types(): iterable {
		global $wpdb;
		$p = $wpdb->prefix;

		$color           = $this->select_column( 'amelia_services', 'color', '', 'color', 'NULL' );
		$status          = $this->select_column( 'amelia_services', 'status', '', 'status', "'visible'" );
		$time_before     = $this->select_column( 'amelia_services', 'timeBefore', '', 'timeBefore', '0' );
		$time_after      = $this->select_column( 'amelia_services', 'timeAfter', '', 'timeAfter', '0' );
		$min_capacity    = $this->select_column( 'amelia_services', 'minCapacity', '', 'minCapacity', '1' );
		$max_capacity    = $this->select_column( 'amelia_services', 'maxCapacity', '', 'maxCapacity', '1' );
		$deposit_payment = $this->select_column( 'amelia_services', 'depositPayment', '', 'depositPayment', "'disabled'" );
		$deposit         = $this->select_column( 'amelia_services', 'deposit', '', 'deposit', '0' );

		$rows = $wpdb->get_results(
			"SELECT id, name, description, {$color}, {$status}, price, duration, {$time_before}, {$time_after}, {$min_capacity}, {$max_capacity}, {$deposit_payment}, {$deposit}
			 FROM {$p}amelia_services ORDER BY id ASC",
			ARRAY_A
		);
		if ( ! $rows ) return;

		foreach ( $rows as $r ) {
			$duration_min = max( 15, (int) ( ( (int) $r['duration'] ) / 60 ) );

			$deposit_amount = 0.0;
			if ( ! empty( $r['depositPayment'] ) && $r['depositPayment'] !== 'disabled' ) {
				$deposit_amount = (float) ( $r['deposit'] ?? 0 );
			}

			yield array(
				'external_id'    => (int) $r['id'],
				'name'           => $r['name'] ?: ( 'Service #' . $r['id'] ),
				'slug'           => sanitize_title( ( $r['name'] ?: 'service' ) . '-' . $r['id'] ),
				'category'       => 'class',
				'duration_min'   => $duration_min,
				'buffer_before'  => max( 0, (int) ( ( (int) ( $r['timeBefore'] ?? 0 ) ) / 60 ) ),
				'buffer_after'   => max( 0, (int) ( ( (int) ( $r['timeAfter'] ?? 0 ) ) / 60 ) ),
				'base_price'     => round( (float) $r['price'], 2 ),
				'deposit_amount' => round( $deposit_amount, 2 ),
				'description'    => $r['description'] ?: null,
				'is_active'      => ( $r['status'] ?? 'visible' ) === 'visible',
				'metadata'       => array(
					'source_color'        => $r['color'] ?? null,
					'source_min_capacity' => (int) ( $r['minCapacity'] ?? 1 ),
					'source_max_capacity' => (int) ( $r['maxCapacity'] ?? 1 ),
					'source_adapter'      => 'amelia',
					'resource_type'       => 'instructor',
				),
			);
		}
	}

	public function get_bookings( int $offset, int $limit ): iterable {
		global $wpdb;
		$p = $wpdb->prefix;

		$booking_tax     = $this->select_column( 'amelia_customer_bookings', 'tax', 'cb', 'booking_tax', '0' );
		$persons         = $this->select_column( 'amelia_customer_bookings', 'persons', 'cb', 'persons', '1' );
		$booking_info    = $this->column_exists( 'amelia_customer_bookings', 'info' )
			? 'cb.info AS booking_info'
			: $this->select_column( 'amelia_customer_bookings', 'customFields', 'cb', 'booking_info', 'NULL' );
		$booking_created = $this->select_column( 'amelia_customer_bookings', 'created', 'cb', 'booking_created', 'NULL' );
		$internal_notes  = $this->select_column( 'amelia_appointments', 'internalNotes', 'a', 'internal_notes', 'NULL' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				cb.id              AS booking_id,
				cb.appointmentId   AS appointment_id,
				cb.customerId      AS customer_id,
				cb.status          AS booking_status,
				cb.price           AS booking_price,
				{$booking_tax},
				{$persons},
				{$booking_info},
				{$booking_created},
				a.bookingStart     AS start_at,
				a.bookingEnd       AS end_at,
				a.serviceId        AS service_id,
				a.providerId       AS provider_id,
				a.status           AS appointment_status,
				{$internal_notes},
				u.firstName        AS customer_first,
				u.lastName         AS customer_last,
				u.email            AS customer_email,
				u.phone            AS customer_phone
			FROM {$p}amelia_customer_bookings cb
			INNER JOIN {$p}amelia_appointments a ON a.id = cb.appointmentId
			LEFT JOIN  {$p}amelia_users u        ON u.id = cb.customerId
			ORDER BY cb.id ASC
			LIMIT %d OFFSET %d",
			$limit, $offset
		), ARRAY_A );

		if ( ! $rows ) return;

		foreach ( $rows as $r ) {
			$name = trim( ( $r['customer_first'] ?? '' ) . ' ' . ( $r['customer_last'] ?? '' ) );
			if ( $name === '' ) $name = $r['customer_email'] ?: __( 'Anonymous', 'g2a-booking' );

			$start = $this->normalize_datetime( $r['start_at'] );
			$end   = $this->normalize_datetime( $r['end_at'] );
			if ( ! $start || ! $end ) {
				yield array(
					'external_id' => (int) $r['booking_id'],
					'start_at'    => null,
					'end_at'      => null,
				);
				continue;
			}

			$duration_min = max( 15, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 ) );
			$total        = round( (float) ( $r['booking_price'] ?? 0 ) + (float) ( $r['booking_tax'] ?? 0 ), 2 );
			$form_data    = $this->decode_booking_info( $r['booking_info'] ?? '' );
			$payments     = $this->fetch_payments_for_booking( (int) $r['booking_id'] );
			$paid_amount  = 0.0;

			foreach ( $payments as $pmt ) {
				if ( in_array( strtolower( (string) $pmt['status'] ), array( 'paid', 'partiallypaid', 'completed' ), true ) ) {
					$paid_amount += (float) $pmt['amount'];
				}
			}

			yield array(
				'external_id'              => (int) $r['booking_id'],
				'external_resource_id'     => $r['provider_id'] ? (int) $r['provider_id'] : null,
				'external_booking_type_id' => $r['service_id'] ? (int) $r['service_id'] : null,
				'customer_name'            => $name,
				'customer_email'           => sanitize_email( $r['customer_email'] ?? '' ),
				'customer_phone'           => $r['customer_phone'] ?? null,
				'start_at'                 => $start,
				'end_at'                   => $end,
				'duration_min'             => $duration_min,
				'party_size'               => max( 1, (int) ( $r['persons'] ?? 1 ) ),
				'status'                   => (string) ( $r['booking_status'] ?? 'pending' ),
				'total_amount'             => $total,
				'paid_amount'              => round( $paid_amount, 2 ),
				'currency'                 => $this->detect_currency(),
				'form_data'                => $form_data,
				'notes'                    => $r['internal_notes'] ?: null,
				'created_at'               => $this->normalize_datetime( $r['booking_created'] ),
				'payments'                 => $payments,
			);
		}
	}

	private function fetch_payments_for_booking( int $booking_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! $this->table_exists( 'amelia_payments' ) ) return array();
		if ( ! $this->column_exists( 'amelia_payments', 'customerBookingId' ) ) return array();

		$gateway_title = $this->select_column( 'amelia_payments', 'gatewayTitle', '', 'gatewayTitle', 'NULL' );
		$date_time     = $this->select_column( 'amelia_payments', 'dateTime', '', 'dateTime', 'NULL' );
		$transaction   = $this->select_column( 'amelia_payments', 'transactionId', '', 'transactionId', 'NULL' );
		$currency      = $this->select_column( 'amelia_payments', 'currency', '', 'currency', "'" . esc_sql( $this->detect_currency() ) . "'" );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, amount, status, gateway, {$gateway_title}, {$date_time}, {$transaction}, {$currency}
			 FROM {$p}amelia_payments WHERE customerBookingId = %d",
			$booking_id
		), ARRAY_A );

		if ( ! $rows ) return array();

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'external_id'    => (int) $r['id'],
				'gateway'        => (string) ( $r['gateway'] ?: $r['gatewayTitle'] ?: '' ),
				'transaction_id' => $r['transactionId'] ?: null,
				'amount'         => round( (float) $r['amount'], 2 ),
				'currency'       => $r['currency'] ?: $this->detect_currency(),
				'status'         => (string) ( $r['status'] ?? 'pending' ),
				'processed_at'   => $this->normalize_datetime( $r['dateTime'] ),
			);
		}
		return $out;
	}

	public function map_status( string $source_status ): string {
		switch ( strtolower( $source_status ) ) {
			case 'approved':
			case 'paid':
				return 'confirmed';
			case 'completed':
				return 'completed';
			case 'pending':
			case 'waiting':
				return 'pending';
			case 'canceled':
			case 'cancelled':
			case 'rejected':
				return 'cancelled';
			case 'no-show':
			case 'no_show':
				return 'no_show';
			default:
				return 'pending';
		}
	}

	public function map_gateway( string $source_gateway ): string {
		switch ( strtolower( $source_gateway ) ) {
			case 'stripe':
				return 'stripe';
			case 'paypal':
				return 'paypal';
			case 'authorizenet':
			case 'authorize_net':
			case 'authnet':
				return 'authnet';
			case 'mollie':
			case 'wc':
			case 'woocommerce':
			case 'onsite':
			case 'on_site':
				return 'pay_in_store';
			default:
				return 'pay_in_store';
		}
	}

	private function decode_booking_info( $raw ): array {
		if ( empty( $raw ) ) return array();
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function normalize_datetime( $raw ) {
		if ( ! $raw ) return null;
		$ts = strtotime( $raw );
		if ( ! $ts ) return null;
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private function detect_currency(): string {
		$opts = get_option( 'amelia_settings_payments' );
		if ( is_string( $opts ) ) $opts = json_decode( $opts, true );
		if ( is_array( $opts ) && ! empty( $opts['currency'] ) ) return strtoupper( substr( $opts['currency'], 0, 3 ) );

		$settings = get_option( 'amelia_settings' );
		if ( is_string( $settings ) ) $settings = json_decode( $settings, true );
		if ( is_array( $settings ) ) {
			if ( ! empty( $settings['payments']['currency'] ) ) return strtoupper( substr( $settings['payments']['currency'], 0, 3 ) );
			if ( ! empty( $settings['currency'] ) ) return strtoupper( substr( $settings['currency'], 0, 3 ) );
		}

		return 'USD';
	}

	private function select_column( $table, $column, $alias = '', $as = '', $fallback = 'NULL' ): string {
		$as = $as ?: $column;
		if ( $this->column_exists( $table, $column ) ) {
			return ( $alias ? $alias . '.' : '' ) . $column . ' AS ' . $as;
		}
		return $fallback . ' AS ' . $as;
	}
}
