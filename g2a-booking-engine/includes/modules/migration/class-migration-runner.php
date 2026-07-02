<?php
/**
 * Migration Runner — executes a migration plan in batches.
 *
 * Lifecycle:
 *   1. start_run( adapter_id, mode='dry'|'live', options ) → returns run_id
 *   2. process_batch( run_id ) → processes BATCH_SIZE bookings, schedules next batch
 *   3. complete_run( run_id ) → finalizes status
 *   4. rollback_run( run_id ) → deletes records with matching external_ref
 *
 * Batching uses Action Scheduler if available (bundled with WooCommerce),
 * else falls back to wp_schedule_single_event (WP-Cron).
 *
 * Idempotency: every imported record carries `external_ref` set to
 * `<adapter>:<kind>:<source_id>`. Re-running the same migration is a no-op.
 *
 * @package G2AB\Modules\Migration
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Migration_Runner {

	const BATCH_SIZE        = 50;
	const ACTION_BATCH_HOOK = 'g2ab_migration_process_batch';

	private $module;

	/** @var array<string,int> resource ID remap: external_ref → g2ab id */
	private $resource_map = array();

	/** @var array<string,int> booking_type ID remap */
	private $type_map = array();

	public function __construct( G2AB_Module_Migration $module ) {
		$this->module = $module;
		add_action( self::ACTION_BATCH_HOOK, array( $this, 'process_batch' ), 10, 1 );
	}

	// =================================================================
	// Public API — called from admin AJAX
	// =================================================================

	/**
	 * Create a migration_runs row and seed it.
	 *
	 * @return int|WP_Error  The run_id, or WP_Error on failure.
	 */
	public function start_run( $adapter_id, $mode = 'live', $options = array() ) {
		$adapter = $this->module->adapter( $adapter_id );
		if ( ! $adapter ) return new WP_Error( 'g2ab_no_adapter', __( 'Unknown migration adapter.', 'g2a-booking' ) );

		// CSV needs the upload activated first.
		if ( $adapter_id === 'csv' && ! empty( $options['csv_path'] ) ) {
			// Path will be activated in process_batch via load_csv_for_run( run_id ).
		}

		if ( ! in_array( $mode, array( 'dry', 'live' ), true ) ) $mode = 'live';
		$options['_resource_map'] = array();
		$options['_type_map']     = array();
		$options['_next_offset']  = 0;

		try {
			$counts = $adapter->get_record_counts();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'g2ab_adapter_count_failed', $e->getMessage() );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_migration_runs';
		$wpdb->insert( $table, array(
			'adapter'        => $adapter_id,
			'status'         => 'pending',
			'mode'           => $mode,
			'total_records'  => (int) ( $counts['bookings'] ?? 0 ),
			'options'        => wp_json_encode( $options ),
			'started_at'     => current_time( 'mysql' ),
			'user_id'        => get_current_user_id() ?: null,
			'created_at'     => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ) );

		$run_id = (int) $wpdb->insert_id;
		if ( ! $run_id ) return new WP_Error( 'g2ab_run_create_failed', __( 'Could not create migration run.', 'g2a-booking' ) );

		// CSV path stash bound to run_id (transient).
		if ( $adapter_id === 'csv' && ! empty( $options['csv_path'] ) ) {
			$adapter->activate_csv_for_run( $run_id, $options['csv_path'] );
		}

		G2AB_Migration_Logger::log( 'started', array(
			'run_id' => $run_id,
			'adapter' => $adapter_id,
			'mode' => $mode,
			'total' => $counts['bookings'] ?? 0,
		) );

		// First, migrate resources + booking_types synchronously (small lists).
		$this->update_run( $run_id, array( 'status' => 'running' ) );
		$this->migrate_resources_and_types( $run_id, $adapter, $mode );

		return $run_id;
	}

	public function get_run( $run_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}g2ab_migration_runs WHERE id = %d",
			(int) $run_id
		), ARRAY_A );
		return $row ?: null;
	}

	public function list_recent_runs( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, adapter, status, mode, total_records, processed_records, failed_records, skipped_records, started_at, completed_at, error_summary
			 FROM {$wpdb->prefix}g2ab_migration_runs
			 ORDER BY id DESC LIMIT %d",
			$limit
		), ARRAY_A );
	}

	// =================================================================
	// Resource + booking_type migration (synchronous; small datasets)
	// =================================================================

	private function migrate_resources_and_types( $run_id, G2AB_Migration_Adapter_Base $adapter, $mode ) {
		global $wpdb;

		// Resources
		try {
			foreach ( $adapter->get_resources() as $r ) {
				$ext_ref = $adapter->compose_external_ref( 'resource', $r['external_id'] );
				$existing_id = $this->find_by_external_ref( 'resources', $ext_ref );
				if ( $existing_id ) {
					$this->resource_map[ (string) $r['external_id'] ] = $existing_id;
					continue;
				}
				if ( $mode === 'dry' ) continue;

				$wpdb->insert( $wpdb->prefix . 'g2ab_resources', array(
					'name'         => $this->str( $r['name'], 150 ),
					'slug'         => $this->unique_slug( 'resources', $r['slug'] ?: sanitize_title( $r['name'] ) ),
					'type'         => $this->str( $r['type'] ?? 'staff', 30 ),
					'capacity'     => max( 1, (int) ( $r['capacity'] ?? 1 ) ),
					'description'  => isset( $r['description'] ) ? $r['description'] : null,
					'settings'     => wp_json_encode( $this->migration_metadata( $r['metadata'] ?? array(), $run_id ) ),
					'is_active'    => ! empty( $r['is_active'] ) ? 1 : 0,
					'external_ref' => $ext_ref,
					'created_at'   => current_time( 'mysql' ),
					'updated_at'   => current_time( 'mysql' ),
				) );
				$new_id = (int) $wpdb->insert_id;
				if ( $new_id ) {
					$this->resource_map[ (string) $r['external_id'] ] = $new_id;
				}
			}
		} catch ( \Throwable $e ) {
			G2AB_Migration_Logger::log( 'resource_migration_failed', array( 'run_id' => $run_id, 'error' => $e->getMessage() ), 'error' );
		}

		// Booking types
		try {
			foreach ( $adapter->get_booking_types() as $bt ) {
				$ext_ref = $adapter->compose_external_ref( 'booking_type', $bt['external_id'] );
				$existing_id = $this->find_by_external_ref( 'booking_types', $ext_ref );
				if ( $existing_id ) {
					$this->type_map[ (string) $bt['external_id'] ] = $existing_id;
					continue;
				}
				if ( $mode === 'dry' ) continue;

				$wpdb->insert( $wpdb->prefix . 'g2ab_booking_types', array(
					'name'           => $this->str( $bt['name'], 150 ),
					'slug'           => $this->unique_slug( 'booking_types', $bt['slug'] ?: sanitize_title( $bt['name'] ) ),
					'category'       => $this->str( $bt['category'] ?? 'lane', 30 ),
					'duration_min'   => max( 15, (int) ( $bt['duration_min'] ?? 60 ) ),
					'buffer_before'  => max( 0,  (int) ( $bt['buffer_before'] ?? 0 ) ),
					'buffer_after'   => max( 0,  (int) ( $bt['buffer_after']  ?? 0 ) ),
					'base_price'     => round( (float) ( $bt['base_price']     ?? 0 ), 2 ),
					'deposit_amount' => round( (float) ( $bt['deposit_amount'] ?? 0 ), 2 ),
					'payment_modes'  => 'full,deposit,in_store',
					'requires_waiver'=> 1,
					'members_only'   => 0,
					'member_discount'=> 0.00,
					'settings'       => wp_json_encode( $this->migration_metadata( $bt['metadata'] ?? array(), $run_id ) ),
					'is_active'      => ! empty( $bt['is_active'] ) ? 1 : 0,
					'external_ref'   => $ext_ref,
					'created_at'     => current_time( 'mysql' ),
					'updated_at'     => current_time( 'mysql' ),
				) );
				$new_id = (int) $wpdb->insert_id;
				if ( $new_id ) {
					$this->type_map[ (string) $bt['external_id'] ] = $new_id;
				}
			}
		} catch ( \Throwable $e ) {
			G2AB_Migration_Logger::log( 'type_migration_failed', array( 'run_id' => $run_id, 'error' => $e->getMessage() ), 'error' );
		}

		// Persist remap tables in run options for batch resumption.
		$run = $this->get_run( $run_id );
		$opts = json_decode( $run['options'] ?? '{}', true );
		$opts = is_array( $opts ) ? $opts : array();
		$opts['_resource_map'] = $this->resource_map;
		$opts['_type_map']     = $this->type_map;
		$this->update_run( $run_id, array( 'options' => wp_json_encode( $opts ) ) );

		G2AB_Migration_Logger::append_to_run( $run_id, sprintf(
			'Resources mapped: %d. Booking types mapped: %d.',
			count( $this->resource_map ), count( $this->type_map )
		) );
	}

	// =================================================================
	// Batch processing for bookings
	// =================================================================

	public function process_batch( $args ) {
		$args = is_array( $args ) ? $args : array( 'run_id' => (int) $args, 'offset' => 0 );
		$run_id = (int) ( $args['run_id'] ?? 0 );
		$offset = (int) ( $args['offset'] ?? 0 );
		if ( ! $run_id ) return;
		$lock_key = 'g2ab_migration_lock_' . $run_id;
		if ( get_transient( $lock_key ) ) return;
		set_transient( $lock_key, 1, 30 );

		$run = $this->get_run( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'running', 'pending' ), true ) ) {
			delete_transient( $lock_key );
			return;
		}

		$adapter = $this->module->adapter( $run['adapter'] );
		if ( ! $adapter ) {
			delete_transient( $lock_key );
			$this->fail_run( $run_id, 'Adapter no longer available: ' . $run['adapter'] );
			return;
		}

		// Restore remap tables.
		$opts = json_decode( $run['options'] ?? '{}', true );
		$opts = is_array( $opts ) ? $opts : array();
		$this->resource_map = is_array( $opts['_resource_map'] ?? null ) ? $opts['_resource_map'] : array();
		$this->type_map     = is_array( $opts['_type_map']     ?? null ) ? $opts['_type_map']     : array();
		$expected_offset    = max( 0, (int) ( $opts['_next_offset'] ?? 0 ) );
		if ( $offset < $expected_offset ) {
			delete_transient( $lock_key );
			return;
		}
		$offset = $expected_offset;

		// CSV — re-bind file path
		if ( $run['adapter'] === 'csv' && method_exists( $adapter, 'load_csv_for_run' ) ) {
			$adapter->load_csv_for_run( $run_id );
		}

		$mode = $run['mode'] === 'dry' ? 'dry' : 'live';
		$processed = 0;
		$failed = 0;
		$skipped = 0;
		$seen = 0;
		$batch_log = array();

		try {
			foreach ( $adapter->get_bookings( $offset, self::BATCH_SIZE ) as $b ) {
				$seen++;
				$result = $this->import_one_booking( $adapter, $b, $mode, $run_id );
				if ( $result === 'imported' ) $processed++;
				elseif ( $result === 'skipped' ) $skipped++;
				else { $failed++; $batch_log[] = "FAIL ext={$b['external_id']}: $result"; }
			}
		} catch ( \Throwable $e ) {
			G2AB_Migration_Logger::log( 'batch_exception', array( 'run_id' => $run_id, 'offset' => $offset, 'error' => $e->getMessage() ), 'error' );
			$batch_log[] = 'EXC: ' . $e->getMessage();
		}

		// Update run counters.
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}g2ab_migration_runs
			 SET processed_records = processed_records + %d,
			     failed_records    = failed_records    + %d,
			     skipped_records   = skipped_records   + %d
			 WHERE id = %d",
			$processed, $failed, $skipped, $run_id
		) );

		if ( $batch_log ) {
			G2AB_Migration_Logger::append_to_run( $run_id, implode( "\n", $batch_log ) );
		}
		G2AB_Migration_Logger::append_to_run( $run_id, sprintf(
			'Batch offset=%d: imported=%d skipped=%d failed=%d',
			$offset, $processed, $skipped, $failed
		) );

		// Determine if there's another batch.
		$total_seen = $offset + $processed + $skipped + $failed;
		$run = $this->get_run( $run_id );
		$total_expected = (int) $run['total_records'];
		$next_offset = $offset + self::BATCH_SIZE;
		$opts['_next_offset'] = $next_offset;
		$this->update_run( $run_id, array( 'options' => wp_json_encode( $opts ) ) );

		if ( $seen >= self::BATCH_SIZE && $total_seen < $total_expected ) {
			$this->schedule_next_batch( $run_id, $next_offset );
		} else {
			$this->complete_run( $run_id );
		}
		delete_transient( $lock_key );
	}

	public function maybe_process_next_batch( $run_id ) {
		$run_id = (int) $run_id;
		if ( ! $run_id ) return;
		$run = $this->get_run( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'running', 'pending' ), true ) ) return;

		$done = (int) $run['processed_records'] + (int) $run['skipped_records'] + (int) $run['failed_records'];
		if ( $done >= (int) $run['total_records'] ) {
			$this->complete_run( $run_id );
			return;
		}

		$opts = json_decode( $run['options'] ?? '{}', true );
		$opts = is_array( $opts ) ? $opts : array();
		$this->process_batch( array(
			'run_id' => $run_id,
			'offset' => max( $done, (int) ( $opts['_next_offset'] ?? 0 ) ),
		) );
	}

	private function import_one_booking( G2AB_Migration_Adapter_Base $adapter, array $b, string $mode, int $run_id ): string {
		global $wpdb;

		$ext_ref = $adapter->compose_external_ref( 'booking', $b['external_id'] );

		// Idempotency: skip if already imported.
		$existing = $this->find_by_external_ref( 'bookings', $ext_ref );
		if ( $existing ) return 'skipped';

		// Validate minimum fields.
		if ( empty( $b['start_at'] ) || empty( $b['end_at'] ) ) return 'missing start/end';

		// Resolve resource + booking_type from remap tables (NULL if unmapped).
		$resource_id     = $this->resolve_remap( $this->resource_map, $b['external_resource_id']     ?? null );
		$booking_type_id = $this->resolve_remap( $this->type_map,     $b['external_booking_type_id'] ?? null );

		// Booking type is required by schema; create a fallback "Imported" type if needed.
		if ( ! $booking_type_id ) {
			$booking_type_id = $this->ensure_fallback_booking_type();
		}

		if ( $mode === 'dry' ) return 'imported';

		$uuid = wp_generate_uuid4();
		$now  = current_time( 'mysql' );

		$ok = $wpdb->insert( $wpdb->prefix . 'g2ab_bookings', array(
			'uuid'            => $uuid,
			'booking_type_id' => $booking_type_id,
			'resource_id'     => $resource_id,
			'form_id'         => null,
			'user_id'         => null,
			'customer_name'   => $this->str( $b['customer_name'] ?? '', 150 ),
			'customer_email'  => $this->str( $b['customer_email'] ?? '', 150 ),
			'customer_phone'  => isset( $b['customer_phone'] ) ? $this->str( $b['customer_phone'], 30 ) : null,
			'start_at'        => $b['start_at'],
			'end_at'          => $b['end_at'],
			'duration_min'    => max( 15, (int) ( $b['duration_min'] ?? 60 ) ),
			'party_size'      => max( 1,  (int) ( $b['party_size'] ?? 1 ) ),
			'status'          => $adapter->map_status( (string) ( $b['status'] ?? 'pending' ) ),
			'payment_mode'    => 'full',
			'total_amount'    => round( (float) ( $b['total_amount'] ?? 0 ), 2 ),
			'paid_amount'     => round( (float) ( $b['paid_amount']  ?? 0 ), 2 ),
			'currency'        => $this->str( $b['currency'] ?? 'USD', 3 ),
			'form_data'       => wp_json_encode( $b['form_data'] ?? array() ),
			'metadata'        => wp_json_encode( $this->migration_metadata( $b['metadata'] ?? array(), $run_id, $adapter->id() ) ),
			'notes'           => $b['notes'] ?? null,
			'waiver_signed'   => 0,
			'source'          => 'migration',
			'created_at'      => $b['created_at'] ?: $now,
			'updated_at'      => $now,
			'external_ref'    => $ext_ref,
		) );

		if ( $ok === false ) return 'db: ' . $wpdb->last_error;
		$booking_id = (int) $wpdb->insert_id;

		// Migrate payments for this booking.
		if ( ! empty( $b['payments'] ) && is_array( $b['payments'] ) ) {
			foreach ( $b['payments'] as $pmt ) {
				$pmt_ref = $adapter->compose_external_ref( 'payment', $pmt['external_id'] ?? ( $b['external_id'] . '-pmt' ) );
				if ( $this->find_by_external_ref( 'payments', $pmt_ref ) ) continue;

				$wpdb->insert( $wpdb->prefix . 'g2ab_payments', array(
					'booking_id'      => $booking_id,
					'gateway'         => $adapter->map_gateway( (string) ( $pmt['gateway'] ?? '' ) ),
					'transaction_id'  => $pmt['transaction_id'] ?? null,
					'amount'          => round( (float) ( $pmt['amount'] ?? 0 ), 2 ),
					'currency'        => $this->str( $pmt['currency'] ?? 'USD', 3 ),
					'status'          => $this->str( $pmt['status'] ?? 'pending', 20 ),
					'payment_method'  => null,
					'refund_amount'   => 0.00,
					'gateway_response'=> null,
					'metadata'        => wp_json_encode( $this->migration_metadata( $pmt['metadata'] ?? array(), $run_id, $adapter->id() ) ),
					'processed_at'    => $pmt['processed_at'] ?? null,
					'created_at'      => $now,
					'external_ref'    => $pmt_ref,
				) );
			}
		}

		return 'imported';
	}

	// =================================================================
	// Run state transitions
	// =================================================================

	private function complete_run( $run_id ) {
		global $wpdb;
		$run = $this->get_run( $run_id );
		if ( ! $run ) return;

		$status = ( (int) $run['failed_records'] > 0 && (int) $run['processed_records'] === 0 )
			? 'failed' : 'completed';

		$wpdb->update( $wpdb->prefix . 'g2ab_migration_runs', array(
			'status'       => $status,
			'completed_at' => current_time( 'mysql' ),
		), array( 'id' => $run_id ) );

		G2AB_Migration_Logger::log( 'completed', array(
			'run_id'    => $run_id,
			'status'    => $status,
			'processed' => $run['processed_records'],
			'failed'    => $run['failed_records'],
			'skipped'   => $run['skipped_records'],
		) );

		do_action( 'g2ab_migration_completed', $run_id, $status );
	}

	private function fail_run( $run_id, $reason ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'g2ab_migration_runs', array(
			'status'        => 'failed',
			'completed_at'  => current_time( 'mysql' ),
			'error_summary' => $this->str( $reason, 1000 ),
		), array( 'id' => $run_id ) );
		G2AB_Migration_Logger::log( 'failed', array( 'run_id' => $run_id, 'reason' => $reason ), 'error' );
	}

	/**
	 * Rollback: delete G2AB records that came from this run.
	 */
	public function rollback_run( $run_id ) {
		global $wpdb;
		$run = $this->get_run( $run_id );
		if ( ! $run ) return new WP_Error( 'g2ab_no_run', __( 'Run not found.', 'g2a-booking' ) );
		if ( $run['status'] === 'rolled_back' ) {
			return new WP_Error( 'g2ab_already_rolled_back', __( 'Run already rolled back.', 'g2a-booking' ) );
		}

		$adapter_id = sanitize_key( $run['adapter'] );
		$prefix = $adapter_id . ':';
		$like = $wpdb->esc_like( $prefix ) . '%';
		$run_needle = '%"migration_run_id":' . (int) $run_id . '%';

		// Order matters: payments first, then bookings, then booking_types, then resources.
		$deleted = array();
		foreach ( array( 'payments' => 'metadata', 'bookings' => 'metadata', 'booking_types' => 'settings', 'resources' => 'settings' ) as $tbl => $meta_col ) {
			$table = $wpdb->prefix . 'g2ab_' . $tbl;
			$deleted[ $tbl ] = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE external_ref LIKE %s AND {$meta_col} LIKE %s",
				$like,
				$run_needle
			) );
		}

		$wpdb->update( $wpdb->prefix . 'g2ab_migration_runs', array(
			'status'        => 'rolled_back',
			'completed_at'  => current_time( 'mysql' ),
			'error_summary' => 'Rolled back: ' . wp_json_encode( $deleted ),
		), array( 'id' => $run_id ) );

		G2AB_Migration_Logger::log( 'rolled_back', array( 'run_id' => $run_id, 'deleted' => $deleted ) );
		return $deleted;
	}

	// =================================================================
	// Helpers
	// =================================================================

	private function schedule_next_batch( $run_id, $offset ) {
		$args = array( 'run_id' => (int) $run_id, 'offset' => (int) $offset );

		// Prefer Action Scheduler (bundled with WC). Fall back to wp_schedule_single_event.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_BATCH_HOOK, array( $args ), 'g2ab-migration' );
		} else {
			wp_schedule_single_event( time() + 1, self::ACTION_BATCH_HOOK, array( $args ) );
		}
	}

	private function update_run( $run_id, $data ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'g2ab_migration_runs', $data, array( 'id' => $run_id ) );
	}

	private function find_by_external_ref( $tbl_short, $ext_ref ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_' . $tbl_short;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE external_ref = %s LIMIT 1", $ext_ref ) );
		return $id ? (int) $id : 0;
	}

	private function unique_slug( $tbl_short, $base ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_' . $tbl_short;
		$slug = sanitize_title( $base );
		$try = $slug; $i = 2;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE slug = %s", $try ) ) ) {
			$try = $slug . '-' . $i;
			$i++;
			if ( $i > 100 ) { $try = $slug . '-' . wp_generate_password( 4, false ); break; }
		}
		return $try;
	}

	private function resolve_remap( $map, $external_id ) {
		if ( ! $external_id ) return null;
		$key = (string) $external_id;
		return isset( $map[ $key ] ) ? (int) $map[ $key ] : null;
	}

	private function migration_metadata( $metadata, $run_id, $adapter_id = null ) {
		$metadata = is_array( $metadata ) ? $metadata : array();
		$metadata['migration_run_id'] = (int) $run_id;
		if ( $adapter_id ) {
			$metadata['imported_from'] = sanitize_key( $adapter_id );
		}
		return $metadata;
	}

	private function ensure_fallback_booking_type() {
		global $wpdb;
		$existing = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}g2ab_booking_types WHERE slug = 'imported' LIMIT 1" );
		if ( $existing ) return (int) $existing;

		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'g2ab_booking_types', array(
			'name'           => __( 'Imported', 'g2a-booking' ),
			'slug'           => 'imported',
			'category'       => 'lane',
			'duration_min'   => 60,
			'buffer_before'  => 0,
			'buffer_after'   => 0,
			'base_price'     => 0.00,
			'deposit_amount' => 0.00,
			'payment_modes'  => 'full,deposit,in_store',
			'requires_waiver'=> 0,
			'members_only'   => 0,
			'member_discount'=> 0.00,
			'settings'       => wp_json_encode( array( 'imported_fallback' => true ) ),
			'is_active'      => 1,
			'external_ref'   => 'g2ab:fallback:imported',
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	private function str( $val, $max ) {
		$val = is_string( $val ) ? $val : (string) $val;
		return mb_substr( sanitize_text_field( $val ), 0, $max );
	}
}
