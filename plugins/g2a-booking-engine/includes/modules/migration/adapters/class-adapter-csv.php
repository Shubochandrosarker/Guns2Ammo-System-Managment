<?php
/**
 * CSV → G2AB Migration Adapter.
 *
 * Universal fallback for any booking plugin that can export to CSV.
 * The user uploads a CSV via the wizard; the file path is stashed in
 * a transient keyed by the run_id, and the adapter streams rows from there.
 *
 * Required CSV columns (case-insensitive, in any order):
 *   external_id, customer_name, customer_email, start_at, end_at, status, total_amount
 *
 * Optional columns:
 *   resource_name, booking_type_name, customer_phone, party_size,
 *   paid_amount, currency, notes, payment_gateway, payment_status,
 *   payment_amount, transaction_id
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
		return __( 'Universal fallback. Upload a CSV exported from any booking plugin. Maps required columns automatically.', 'g2a-booking' );
	}

	public function is_available(): bool {
		// Always available; the wizard's "csv" option appears regardless.
		return true;
	}

	/** @var string|null Currently active CSV file path (set per-run via stash_csv_path). */
	private $active_csv = null;

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
		$required = array( 'external_id', 'customer_name', 'customer_email', 'start_at', 'end_at', 'status', 'total_amount' );
		$lower = array_map( 'strtolower', $header );
		$missing = array_diff( $required, $lower );
		foreach ( $missing as $col ) {
			$out[] = sprintf( __( 'Missing required CSV column: %s', 'g2a-booking' ), $col );
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

	public function get_resources(): iterable     { return; yield; } // CSV doesn't import resources
	public function get_booking_types(): iterable { return; yield; }

	public function get_bookings( int $offset, int $limit ): iterable {
		if ( ! $this->active_csv || ! is_readable( $this->active_csv ) ) return;

		$fh = fopen( $this->active_csv, 'r' );
		if ( ! $fh ) return;

		$header = fgetcsv( $fh );
		if ( ! $header ) { fclose( $fh ); return; }

		$idx = array();
		foreach ( $header as $i => $col ) $idx[ strtolower( trim( $col ) ) ] = $i;

		$current_offset = 0;
		$emitted = 0;

		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			if ( $current_offset++ < $offset ) continue;
			if ( $emitted >= $limit ) break;

			$get = function ( $key, $default = null ) use ( $row, $idx ) {
				if ( ! isset( $idx[ $key ] ) ) return $default;
				return $row[ $idx[ $key ] ] ?? $default;
			};

			$start = $this->normalize_datetime( $get( 'start_at' ) );
			$end   = $this->normalize_datetime( $get( 'end_at' ) );
			if ( ! $start || ! $end ) continue;

			$duration_min = max( 15, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 ) );

			$payments = array();
			if ( $get( 'payment_amount' ) || $get( 'transaction_id' ) || $get( 'payment_gateway' ) ) {
				$payments[] = array(
					'external_id'    => (string) ( $get( 'transaction_id' ) ?: ( 'csv-' . $get( 'external_id' ) ) ),
					'gateway'        => (string) $get( 'payment_gateway', '' ),
					'transaction_id' => $get( 'transaction_id' ),
					'amount'         => round( (float) $get( 'payment_amount', 0 ), 2 ),
					'currency'       => (string) $get( 'currency', 'USD' ),
					'status'         => (string) $get( 'payment_status', 'pending' ),
					'processed_at'   => $start,
				);
			}

			yield array(
				'external_id'              => (string) $get( 'external_id' ),
				'external_resource_id'     => $get( 'resource_name' ) ?: null,
				'external_booking_type_id' => $get( 'booking_type_name' ) ?: null,
				'customer_name'            => (string) $get( 'customer_name', __( 'Anonymous', 'g2a-booking' ) ),
				'customer_email'           => sanitize_email( (string) $get( 'customer_email', '' ) ),
				'customer_phone'           => $get( 'customer_phone' ),
				'start_at'                 => $start,
				'end_at'                   => $end,
				'duration_min'             => $duration_min,
				'party_size'               => max( 1, (int) $get( 'party_size', 1 ) ),
				'status'                   => (string) $get( 'status', 'pending' ),
				'total_amount'             => round( (float) $get( 'total_amount', 0 ), 2 ),
				'paid_amount'              => round( (float) $get( 'paid_amount', 0 ), 2 ),
				'currency'                 => (string) $get( 'currency', 'USD' ),
				'form_data'                => array(),
				'notes'                    => $get( 'notes' ),
				'created_at'               => $start,
				'payments'                 => $payments,
			);
			$emitted++;
		}

		fclose( $fh );
	}

	public function map_status( string $source_status ): string {
		$s = strtolower( trim( $source_status ) );
		if ( in_array( $s, array( 'approved', 'confirmed', 'booked' ), true ) ) return 'confirmed';
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
