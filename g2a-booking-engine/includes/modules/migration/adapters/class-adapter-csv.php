<?php
/**
 * CSV → G2AB Migration Adapter.
 *
 * Universal fallback for any booking plugin that can export to CSV.
 * The user uploads a CSV via the wizard; the file path is stashed in
 * a transient keyed by the run_id, and the adapter streams rows from there.
 *
 * Column names are matched through a flexible alias map (case-insensitive),
 * so exports from Amelia, Bookly, BookingPress and generic tools all import
 * without hand-editing headers. Only a customer identifier and a start time
 * are truly required — everything else is optional.
 *
 * Recognised columns (any of the aliases below, in any order):
 *   external_id        ← external_id, id, booking_id, appointment_id
 *   customer_name      ← customer_name, customer, customers, name, client
 *   customer_email     ← customer_email, email
 *   customer_phone     ← customer_phone, phone
 *   start_at           ← start_at, start_time, start, start_date
 *   end_at             ← end_at, end_time, end, end_date
 *   status             ← status, booking_status
 *   total_amount       ← total_amount, total, price, amount
 *   paid_amount        ← paid_amount, payment_amount
 *   resource_name      ← resource_name, employee, provider, staff
 *   booking_type_name  ← booking_type_name, service, service_name
 *   party_size         ← party_size, people, persons, number_of_people
 *   payment_status, payment_gateway/payment_method/gateway,
 *   currency, notes/note, transaction_id/payment_transaction_id
 *
 * When a single combined "customer" field carries name + email + phone
 * (as Amelia exports), the adapter splits it automatically.
 *
 * Date columns accept any strtotime-parseable format.
 *
 * @package G2AB\Modules\Migration\Adapters
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Migration_Adapter_CSV extends G2AB_Migration_Adapter_Base {

	const TRANSIENT_PREFIX = 'g2ab_csv_path_';

	public function id(): string    { return 'csv'; }
	public function label(): string { return 'CSV Import'; }
	public function icon(): string  { return 'C'; }
	public function color(): string { return '#388E3C'; }

	public function description(): string {
		return __( 'Universal fallback. Upload a CSV exported from Amelia, Bookly, BookingPress or any booking plugin. Column names are matched automatically.', 'g2a-booking' );
	}

	public function is_available(): bool {
		// Always available; the wizard's "csv" option appears regardless.
		return true;
	}

	/** @var string|null Currently active CSV file path (set per-run via stash_csv_path). */
	private $active_csv = null;

	/**
	 * Logical column => accepted header aliases (all lowercase).
	 *
	 * @return array<string, string[]>
	 */
	private function alias_map(): array {
		return array(
			'external_id'       => array( 'external_id', 'id', 'booking_id', 'appointment_id' ),
			'customer_name'     => array( 'customer_name', 'customer', 'customers', 'name', 'client' ),
			'customer_email'    => array( 'customer_email', 'email' ),
			'customer_phone'    => array( 'customer_phone', 'phone' ),
			'start_at'          => array( 'start_at', 'start_time', 'start', 'start_date', 'bookingstart' ),
			'end_at'            => array( 'end_at', 'end_time', 'end', 'end_date', 'bookingend' ),
			'status'            => array( 'status', 'booking_status' ),
			'total_amount'      => array( 'total_amount', 'total', 'price', 'amount' ),
			'paid_amount'       => array( 'paid_amount', 'payment_amount' ),
			'resource_name'     => array( 'resource_name', 'employee', 'provider', 'staff' ),
			'booking_type_name' => array( 'booking_type_name', 'service', 'service_name' ),
			'party_size'        => array( 'party_size', 'people', 'persons', 'number_of_people', 'number of people' ),
			'payment_status'    => array( 'payment_status' ),
			'payment_gateway'   => array( 'payment_gateway', 'payment_method', 'gateway' ),
			'currency'          => array( 'currency' ),
			'notes'             => array( 'notes', 'note' ),
			'transaction_id'    => array( 'transaction_id', 'payment_transaction_id' ),
		);
	}

	public function activate_csv_for_run( $run_id, $path ) {
		set_transient( self::TRANSIENT_PREFIX . $run_id, $path, HOUR_IN_SECONDS * 6 );
	}

	public function load_csv_for_run( $run_id ) {
		$path = get_transient( self::TRANSIENT_PREFIX . $run_id );
		if ( ! $path || ! file_exists( $path ) ) return false;
		$this->active_csv = $path;
		return true;
	}

	public function get_record_counts(): array {
		$count = 0;
		if ( $this->active_csv && is_readable( $this->active_csv ) ) {
			$fh = fopen( $this->active_csv, 'r' );
			if ( $fh ) {
				fgetcsv( $fh ); // skip header
				while ( fgetcsv( $fh ) !== false ) $count++;
				fclose( $fh );
			}
		}
		return array(
			'resources'     => 0,
			'booking_types' => 0,
			'bookings'      => $count,
			'payments'      => 0,
			'customers'     => 0,
		);
	}

	public function preflight_warnings(): array {
		$out = array();
		if ( ! $this->active_csv ) {
			$out[] = __( 'No CSV file uploaded yet — upload one in the wizard.', 'g2a-booking' );
			return $out;
		}
		$header = $this->read_header();
		if ( ! $header ) {
			$out[] = __( 'CSV file appears empty or unreadable.', 'g2a-booking' );
			return $out;
		}

		$resolved = $this->resolve_columns( $header );

		// Truly required: a customer identifier and a start time.
		if ( ! isset( $resolved['customer_name'] ) && ! isset( $resolved['customer_email'] ) ) {
			$out[] = __( 'CSV needs a customer column (customer_name, customer, name or email).', 'g2a-booking' );
		}
		if ( ! isset( $resolved['start_at'] ) ) {
			$out[] = __( 'CSV needs a start column (start_at, start_time or start).', 'g2a-booking' );
		}
		if ( ! isset( $resolved['end_at'] ) ) {
			$out[] = __( 'CSV has no end column (end_at / end_time) — booking length will default to 60 minutes.', 'g2a-booking' );
		}
		if ( ! isset( $resolved['external_id'] ) ) {
			$out[] = __( 'No external_id column — row numbers will be used as stable import keys.', 'g2a-booking' );
		}
		return $out;
	}

	private function read_header() {
		if ( ! $this->active_csv || ! is_readable( $this->active_csv ) ) return null;
		$fh = fopen( $this->active_csv, 'r' );
		if ( ! $fh ) return null;
		$header = fgetcsv( $fh );
		fclose( $fh );
		return is_array( $header ) ? $header : null;
	}

	/**
	 * Map logical column keys to the 0-based index found in the CSV header.
	 *
	 * @param array $header Raw header row.
	 * @return array<string,int>
	 */
	private function resolve_columns( array $header ): array {
		$lower = array();
		foreach ( $header as $i => $col ) {
			$lower[ strtolower( trim( (string) $col ) ) ] = $i;
		}
		$resolved = array();
		foreach ( $this->alias_map() as $logical => $aliases ) {
			foreach ( $aliases as $alias ) {
				if ( isset( $lower[ $alias ] ) ) {
					$resolved[ $logical ] = $lower[ $alias ];
					break;
				}
			}
		}
		return $resolved;
	}

	public function get_resources(): iterable     { return; yield; } // CSV doesn't import resources
	public function get_booking_types(): iterable { return; yield; }

	public function get_bookings( int $offset, int $limit ): iterable {
		if ( ! $this->active_csv || ! is_readable( $this->active_csv ) ) return;

		$fh = fopen( $this->active_csv, 'r' );
		if ( ! $fh ) return;

		$header = fgetcsv( $fh );
		if ( ! $header ) { fclose( $fh ); return; }

		$resolved = $this->resolve_columns( $header );

		$current_offset = 0;
		$emitted = 0;
		$row_num = 0;

		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$row_num++;
			if ( $current_offset++ < $offset ) continue;
			if ( $emitted >= $limit ) break;

			$get = function ( $key, $default = null ) use ( $row, $resolved ) {
				if ( ! isset( $resolved[ $key ] ) ) return $default;
				$v = $row[ $resolved[ $key ] ] ?? $default;
				return ( null === $v || '' === $v ) ? $default : $v;
			};

			$start = $this->normalize_datetime( $get( 'start_at' ) );
			$needs_review = false;
			$review_notes = array();
			if ( ! $start ) {
				$start = gmdate( 'Y-m-d H:i:s' );
				$needs_review = true;
				$review_notes[] = 'Missing CSV start time; fallback start applied during migration.';
			}
			$end = $this->normalize_datetime( $get( 'end_at' ) );
			if ( ! $end ) {
				$end = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS );
				$needs_review = true;
				$review_notes[] = 'Missing CSV end time; fallback 60-minute duration applied during migration.';
			}

			$duration_min = max( 15, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 ) );

			// Customer — split a combined "Name email phone" field when needed.
			$name   = (string) $get( 'customer_name', '' );
			$email  = sanitize_email( (string) $get( 'customer_email', '' ) );
			$phone  = $get( 'customer_phone' );
			$parsed = $this->split_customer( $name );
			if ( '' === $email && '' !== $parsed['email'] ) $email = $parsed['email'];
			if ( ! $phone && '' !== $parsed['phone'] ) $phone = $parsed['phone'];
			if ( '' !== $parsed['name'] ) $name = $parsed['name'];
			if ( '' === trim( (string) $name ) ) $name = $email ?: __( 'Anonymous', 'g2a-booking' );

			$external_id = (string) $get( 'external_id', 'csv-row-' . $row_num );

			$total = $this->parse_amount( $get( 'total_amount', 0 ) );
			$paid  = $this->parse_amount( $get( 'paid_amount', 0 ) );

			$payments = array();
			if ( $paid > 0 || $get( 'transaction_id' ) || $get( 'payment_gateway' ) ) {
				$payments[] = array(
					'external_id'    => (string) ( $get( 'transaction_id' ) ?: ( 'csv-' . $external_id ) ),
					'gateway'        => (string) $get( 'payment_gateway', '' ),
					'transaction_id' => $get( 'transaction_id' ),
					'amount'         => $paid > 0 ? $paid : $total,
					'currency'       => (string) $get( 'currency', 'USD' ),
					'status'         => (string) $get( 'payment_status', 'pending' ),
					'processed_at'   => $start,
				);
			}

			yield array(
				'external_id'              => $external_id,
				'external_resource_id'     => $get( 'resource_name' ) ?: null,
				'external_booking_type_id' => $get( 'booking_type_name' ) ?: null,
				'customer_name'            => $name,
				'customer_email'           => $email,
				'customer_phone'           => $phone,
				'start_at'                 => $start,
				'end_at'                   => $end,
				'duration_min'             => $duration_min,
				'party_size'               => max( 1, (int) $get( 'party_size', 1 ) ),
				'status'                   => (string) $get( 'status', 'pending' ),
				'total_amount'             => $total,
				'paid_amount'              => $paid,
				'currency'                 => (string) $get( 'currency', 'USD' ),
				'form_data'                => array(),
				'notes'                    => trim( implode( "\n", array_filter( array_merge( array( $get( 'notes' ) ), $review_notes ) ) ) ) ?: null,
				'created_at'               => $start,
				'payments'                 => $payments,
				'metadata'                 => array(
					'needs_review'  => $needs_review,
					'review_reason' => $review_notes,
				),
			);
			$emitted++;
		}

		fclose( $fh );
	}

	/**
	 * Split a combined customer string ("Jane Doe jane@x.com +16025550199")
	 * into name / email / phone parts.
	 *
	 * @param string $raw Combined field.
	 * @return array{name:string,email:string,phone:string}
	 */
	private function split_customer( string $raw ): array {
		$raw   = trim( $raw );
		$email = '';
		$phone = '';

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m ) ) {
			$email = sanitize_email( $m[0] );
			$raw   = trim( str_replace( $m[0], '', $raw ) );
		}
		if ( preg_match( '/\+?[0-9][0-9\-\s().]{6,}[0-9]/', $raw, $m ) ) {
			$phone = trim( $m[0] );
			$raw   = trim( str_replace( $m[0], '', $raw ) );
		}
		return array(
			'name'  => trim( preg_replace( '/\s{2,}/', ' ', $raw ) ),
			'email' => $email,
			'phone' => $phone,
		);
	}

	/**
	 * Parse a money value, stripping currency symbols and thousands separators.
	 *
	 * @param mixed $raw Raw amount.
	 * @return float
	 */
	private function parse_amount( $raw ): float {
		if ( null === $raw || '' === $raw ) return 0.0;
		$clean = preg_replace( '/[^0-9.\-]/', '', (string) $raw );
		return round( (float) $clean, 2 );
	}

	public function map_status( string $source_status ): string {
		$s = strtolower( trim( $source_status ) );
		if ( in_array( $s, array( 'approved', 'confirmed', 'booked', 'paid' ), true ) ) return 'confirmed';
		if ( in_array( $s, array( 'cancelled', 'canceled', 'rejected' ), true ) ) return 'cancelled';
		if ( in_array( $s, array( 'no_show', 'no-show', 'noshow' ), true ) ) return 'no_show';
		if ( in_array( $s, array( 'completed', 'done', 'finished' ), true ) ) return 'completed';
		return 'pending';
	}

	public function map_gateway( string $source_gateway ): string {
		$s = strtolower( trim( $source_gateway ) );
		if ( strpos( $s, 'stripe' ) !== false ) return 'stripe';
		if ( strpos( $s, 'paypal' ) !== false ) return 'paypal';
		if ( strpos( $s, 'authoriz' ) !== false || $s === 'authnet' ) return 'authnet';
		if ( strpos( $s, 'fortis' ) !== false ) return 'fortis';
		return 'pay_in_store';
	}

	private function normalize_datetime( $raw ) {
		if ( ! $raw ) return null;
		$ts = strtotime( $raw );
		if ( ! $ts ) return null;
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
