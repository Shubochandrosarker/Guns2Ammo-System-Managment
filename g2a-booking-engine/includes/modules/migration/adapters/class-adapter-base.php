<?php
/**
 * Migration Adapter — abstract base class.
 *
 * Each source booking plugin (Amelia, Bookly, BookingPress, CSV) implements
 * this contract. The runner calls these methods; the adapter knows nothing
 * about how the runner persists data into g2ab_* tables.
 *
 * Iterator pattern (yield-style) so we can stream through 100,000+ records
 * without loading everything into memory.
 *
 * @package G2AB\Modules\Migration\Adapters
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

abstract class G2AB_Migration_Adapter_Base {

	/** Stable adapter identifier (used in external_ref prefix). */
	abstract public function id(): string;

	/** Human-readable label shown in the wizard. */
	abstract public function label(): string;

	/** Marketing icon — ASCII single-char fallback (matches module convention). */
	public function icon(): string { return 'M'; }

	/** Brand color for the source plugin. */
	public function color(): string { return '#1B5E20'; }

	/** Short description shown on detect step. */
	public function description(): string { return ''; }

	/**
	 * Detect whether this source plugin is installed/configured on this site.
	 * Should be cheap (a SHOW TABLES query, an option check, etc.).
	 */
	abstract public function is_available(): bool;

	/**
	 * Return high-level counts of what will be migrated.
	 * Used in the wizard's detect screen and the dry-run preview.
	 *
	 * @return array{resources:int, booking_types:int, bookings:int, payments:int, customers:int}
	 */
	abstract public function get_record_counts(): array;

	/**
	 * Yield resources (lanes/employees/locations) one at a time.
	 *
	 * Each yield = associative array with normalized keys:
	 *   external_id   (string|int)  — original ID in source system
	 *   name          (string)
	 *   slug          (string|null) — auto-derived if null
	 *   type          (string)      — 'lane' | 'staff' | 'room' | 'equipment'
	 *   capacity      (int)
	 *   description   (string|null)
	 *   is_active     (bool)
	 *   metadata      (array)       — anything else, JSON-encoded into settings
	 *
	 * @return iterable<array>
	 */
	abstract public function get_resources(): iterable;

	/**
	 * Yield booking types (services).
	 *
	 *   external_id   (string|int)
	 *   name          (string)
	 *   slug          (string|null)
	 *   category      (string)
	 *   duration_min  (int)
	 *   buffer_before (int)
	 *   buffer_after  (int)
	 *   base_price    (float)
	 *   deposit_amount (float)
	 *   description   (string|null)
	 *   is_active     (bool)
	 *   metadata      (array)
	 *
	 * @return iterable<array>
	 */
	abstract public function get_booking_types(): iterable;

	/**
	 * Yield bookings in batches. Runner pulls one batch per Action Scheduler tick.
	 *
	 *   external_id    (string|int)
	 *   external_resource_id     (string|int|null)
	 *   external_booking_type_id (string|int|null)
	 *   customer_name  (string)
	 *   customer_email (string)
	 *   customer_phone (string|null)
	 *   start_at       (string Y-m-d H:i:s)
	 *   end_at         (string Y-m-d H:i:s)
	 *   duration_min   (int)
	 *   party_size     (int)
	 *   status         (string raw — adapter::map_status maps it)
	 *   total_amount   (float)
	 *   paid_amount    (float)
	 *   currency       (string)
	 *   form_data      (array)
	 *   notes          (string|null)
	 *   created_at     (string Y-m-d H:i:s|null)
	 *   payments       (array of payment dicts — see below)
	 *
	 * Payment dict:
	 *   external_id    (string|int)
	 *   gateway        (string raw — adapter::map_gateway maps it)
	 *   transaction_id (string|null)
	 *   amount         (float)
	 *   currency       (string)
	 *   status         (string)
	 *   processed_at   (string|null)
	 *
	 * @param int $offset
	 * @param int $limit
	 * @return iterable<array>
	 */
	abstract public function get_bookings( int $offset, int $limit ): iterable;

	/**
	 * Map the source plugin's status string to a g2ab status.
	 * G2AB statuses: pending | confirmed | cancelled | no_show | completed
	 */
	abstract public function map_status( string $source_status ): string;

	/**
	 * Map the source plugin's gateway slug to a g2ab gateway slug.
	 * G2AB gateways: stripe | paypal | fortis | authnet | pay_in_store
	 */
	abstract public function map_gateway( string $source_gateway ): string;

	/**
	 * Default field map for the wizard's mapping screen.
	 *
	 * Returns an array of [g2ab_field => source_field] pairs that the user
	 * can review/override before running. Optional — adapters with no
	 * configurable mapping return an empty array.
	 *
	 * @return array<string, string>
	 */
	public function default_field_map(): array {
		return array();
	}

	/**
	 * Return any preflight warnings for the user — missing data, deprecated
	 * fields, etc. Empty array means "all clear".
	 *
	 * @return array<string>  human-readable strings
	 */
	public function preflight_warnings(): array {
		return array();
	}

	// -----------------------------------------------------------------
	// Helpers usable by all adapters
	// -----------------------------------------------------------------

	/**
	 * Compose a stable external_ref from (this adapter's id) + source primary key.
	 * Used as the unique fingerprint for idempotency/rollback.
	 */
	public function compose_external_ref( $kind, $source_id ) {
		return sprintf( '%s:%s:%s', $this->id(), sanitize_key( $kind ), (string) $source_id );
	}

	/**
	 * Safe table-existence check used by is_available() in many adapters.
	 */
	protected function table_exists( $table_name ) {
		global $wpdb;
		$full = $wpdb->prefix . ltrim( $table_name, $wpdb->prefix );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
	}

	protected function column_exists( $table_name, $column_name ) {
		global $wpdb;
		$full = $wpdb->prefix . ltrim( $table_name, $wpdb->prefix );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$full} LIKE %s", $column_name ) );
	}

	/**
	 * Safe option presence check.
	 */
	protected function option_exists( $option_name ) {
		return get_option( $option_name, '__missing__' ) !== '__missing__';
	}
}
