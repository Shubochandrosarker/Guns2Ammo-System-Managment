<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Leads\Lead_Ingestion;
use WordPressistic\G2ABA\Leads\Leads_Repository;

/**
 * Fake $wpdb covering everything Lead_Ingestion touches directly (booking
 * engine / Memberistic source tables via SHOW TABLES LIKE + simple id
 * lookups) plus the `g2aba_leads` table shapes Leads_Repository issues
 * underneath it. Query routing is done on the *template* text (the literal
 * SQL string before %s/%d substitution), which is stable across test data —
 * see LeadsRepositoryTest's fake for the same technique.
 */
class Lead_Ingestion_Fake_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 0;

	/** @var array<string, bool> */
	private array $existing_tables = array();
	/** @var array<string, array<int, array<string,mixed>>> */
	private array $rows = array();
	/** @var array<string, string> */
	private array $scalars = array();
	/** @var array<int, array<string,mixed>> */
	private array $people_by_membership = array();
	/** @var array<int, array<string,mixed>> */
	private array $leads = array();
	private int $leads_next_id = 1;

	public bool $throw_on_booking_lookup = false;

	public function mark_table_exists( string $table ): void {
		$this->existing_tables[ $table ] = true;
	}

	public function seed_row( string $table, int $id, array $row ): void {
		$this->rows[ $table ][ $id ] = $row;
	}

	public function seed_scalar( string $table, int $id, string $value ): void {
		$this->scalars[ $table . '|' . $id ] = $value;
	}

	public function seed_person( int $membership_id, array $row ): void {
		$this->people_by_membership[ $membership_id ] = $row;
	}

	public function lead_count(): int {
		return count( $this->leads );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return $query . "\x00" . serialize( $args );
	}

	public function get_var( $prepared ) {
		list( $template, $args ) = $this->split( $prepared );

		if ( false !== strpos( $template, 'SHOW TABLES LIKE' ) ) {
			$table = (string) $args[0];
			return ! empty( $this->existing_tables[ $table ] ) ? $table : null;
		}

		if ( false !== strpos( $template, 'SELECT id FROM' ) && false !== strpos( $template, 'source_ref_id = %s' ) ) {
			foreach ( $this->leads as $row ) {
				if ( ( $row['source'] ?? null ) === $args[0] && ( $row['source_ref_id'] ?? null ) === $args[1] ) {
					return $row['id'];
				}
			}
			return null;
		}

		if ( false !== strpos( $template, 'SELECT name FROM' ) ) {
			$id = (int) $args[0];
			return $this->scalars[ 'plans|' . $id ] ?? null;
		}

		return null;
	}

	public function get_row( $prepared, $output = null ) {
		list( $template, $args ) = $this->split( $prepared );

		if ( $this->throw_on_booking_lookup && false !== strpos( $template, 'g2ab_bookings' ) ) {
			throw new \RuntimeException( 'simulated malformed booking row' );
		}

		if ( false !== strpos( $template, 'category, name FROM' ) ) {
			return $this->rows['booking_types'][ (int) $args[0] ] ?? null;
		}
		if ( false !== strpos( $template, 'g2ab_bookings' ) ) {
			return $this->rows['bookings'][ (int) $args[0] ] ?? null;
		}
		if ( false !== strpos( $template, 'full_name, email, phone FROM' ) ) {
			return $this->people_by_membership[ (int) $args[0] ] ?? null;
		}
		if ( false !== strpos( $template, 'memberistic_memberships' ) ) {
			return $this->rows['memberships'][ (int) $args[0] ] ?? null;
		}
		if ( false !== strpos( $template, 'g2aba_leads' ) ) {
			return $this->leads[ (int) $args[0] ] ?? null;
		}

		return null;
	}

	public function get_results( $prepared, $output = null ) {
		return array();
	}

	public bool $throw_on_insert = false;

	public function insert( $table, $data, $format = null ) {
		if ( $this->throw_on_insert ) {
			throw new \RuntimeException( 'simulated write failure' );
		}
		$id                = $this->leads_next_id++;
		$data['id']        = $id;
		$this->leads[ $id ] = $data;
		$this->insert_id   = $id;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->leads[ $id ] ) ) {
			return 0;
		}
		$this->leads[ $id ] = array_merge( $this->leads[ $id ], $data );
		return 1;
	}

	private function split( $prepared ): array {
		if ( false === strpos( (string) $prepared, "\x00" ) ) {
			return array( (string) $prepared, array() );
		}
		list( $template, $packed ) = explode( "\x00", (string) $prepared, 2 );
		$args = unserialize( $packed );
		return array( $template, is_array( $args ) ? array_values( $args ) : array() );
	}
}

class LeadIngestionTest extends TestCase {
	private Lead_Ingestion_Fake_WPDB $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new Lead_Ingestion_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_formistic_handler_creates_a_categorized_lead() {
		Lead_Ingestion::on_formistic_submission_captured(
			101,
			'Range Contact Form',
			array(
				'Your Name'  => 'Priya Singh',
				'Email'      => 'priya@example.com',
				'Phone'      => '555-1212',
				'Message'    => 'I want to book a lane for Saturday morning, thanks!',
			)
		);

		$repo = new Leads_Repository();
		$id   = $repo->find_id_by_source( 'formistic', '101' );
		$this->assertNotNull( $id );

		$lead = $repo->find( $id );
		$this->assertSame( 'range_enquiry', $lead['category'] );
		$this->assertSame( 'Priya Singh', $lead['contactName'] );
		$this->assertSame( 'priya@example.com', $lead['contactEmail'] );
		$this->assertSame( '555-1212', $lead['contactPhone'] );
		$this->assertStringStartsWith( 'I want to book a lane', $lead['excerpt'] );
	}

	public function test_formistic_handler_is_idempotent_on_resubmission_of_same_id() {
		$fields = array( 'Name' => 'Bob', 'Message' => 'ccw class question' );
		Lead_Ingestion::on_formistic_submission_captured( 55, 'Contact', $fields );
		Lead_Ingestion::on_formistic_submission_captured( 55, 'Contact', $fields );

		$this->assertSame( 1, $this->wpdb->lead_count() );
	}

	public function test_formistic_handler_ignores_non_scalar_field_values() {
		// A non-scalar field value (e.g. a file-upload array) must not blow
		// up the case-insensitive contact extraction.
		Lead_Ingestion::on_formistic_submission_captured( 1, 'Contact', array( 'Attachment' => array( 'a', 'b' ) ) );

		$repo = new Leads_Repository();
		$id   = $repo->find_id_by_source( 'formistic', '1' );
		$this->assertNotNull( $id );
		$this->assertNull( $repo->find( $id )['contactName'] );
	}

	public function test_formistic_handler_swallows_a_thrown_write_failure_without_propagating() {
		$this->wpdb->throw_on_insert = true;

		Lead_Ingestion::on_formistic_submission_captured( 2, 'Contact', array( 'Name' => 'Bob' ) );
		$this->addToAssertionCount( 1 ); // Reaching this line means nothing propagated out of the hook.

		$this->assertSame( 0, $this->wpdb->lead_count(), 'A failed write must not leave a partial lead behind.' );
	}

	public function test_booking_created_handler_ingests_lane_booking() {
		$this->wpdb->mark_table_exists( 'wp_g2ab_bookings' );
		$this->wpdb->mark_table_exists( 'wp_g2ab_booking_types' );
		$this->wpdb->seed_row(
			'bookings',
			777,
			array(
				'id'              => 777,
				'booking_type_id' => 3,
				'customer_name'   => 'Jane Doe',
				'customer_email'  => 'jane@example.com',
				'customer_phone'  => '555-0000',
				'status'          => 'pending',
				'start_at'        => '2026-08-01 10:00:00',
			)
		);
		$this->wpdb->seed_row( 'booking_types', 3, array( 'category' => 'lane', 'name' => 'Lane Booking' ) );

		Lead_Ingestion::on_booking_created( 777, array( 'uuid' => 'abc-123' ) );

		$repo = new Leads_Repository();
		$id   = $repo->find_id_by_source( 'booking_engine', '777' );
		$this->assertNotNull( $id );
		$lead = $repo->find( $id );
		$this->assertSame( 'lane_booking', $lead['category'] );
		$this->assertSame( 'Jane Doe', $lead['contactName'] );
		$this->assertSame( 'new', $lead['status'] );
	}

	public function test_booking_created_handler_noop_when_bookings_table_absent() {
		// Neither table marked as existing — the plugin simply isn't installed yet.
		Lead_Ingestion::on_booking_created( 42, array() );

		$repo = new Leads_Repository();
		$this->assertNull( $repo->find_id_by_source( 'booking_engine', '42' ) );
	}

	public function test_booking_created_handler_swallows_a_thrown_lookup_exception() {
		$this->wpdb->mark_table_exists( 'wp_g2ab_bookings' );
		$this->wpdb->mark_table_exists( 'wp_g2ab_booking_types' );
		$this->wpdb->throw_on_booking_lookup = true;

		Lead_Ingestion::on_booking_created( 9001, array() );
		$this->addToAssertionCount( 1 ); // No exception propagated out of the hook.

		$repo = new Leads_Repository();
		$this->assertNull( $repo->find_id_by_source( 'booking_engine', '9001' ) );
	}

	public function test_booking_status_changed_updates_existing_lead_only() {
		$this->wpdb->mark_table_exists( 'wp_g2ab_bookings' );
		$this->wpdb->mark_table_exists( 'wp_g2ab_booking_types' );
		$this->wpdb->seed_row( 'bookings', 5, array( 'id' => 5, 'booking_type_id' => 1, 'status' => 'pending' ) );
		$this->wpdb->seed_row( 'booking_types', 1, array( 'category' => 'lane', 'name' => 'Lane Booking' ) );
		Lead_Ingestion::on_booking_created( 5, array() );

		Lead_Ingestion::on_booking_status_changed( 5, 'paid', 'pending' );

		$repo = new Leads_Repository();
		$id   = $repo->find_id_by_source( 'booking_engine', '5' );
		$this->assertSame( 'in_progress', $repo->find( $id )['status'] );

		// A status change for a booking that was never ingested must not
		// create a new lead.
		Lead_Ingestion::on_booking_status_changed( 999, 'paid', 'pending' );
		$this->assertNull( $repo->find_id_by_source( 'booking_engine', '999' ) );
	}

	public function test_booking_cancelled_marks_lead_lost_with_int_or_object_arg() {
		$this->wpdb->mark_table_exists( 'wp_g2ab_bookings' );
		$this->wpdb->mark_table_exists( 'wp_g2ab_booking_types' );
		$this->wpdb->seed_row( 'bookings', 8, array( 'id' => 8, 'booking_type_id' => 1, 'status' => 'pending' ) );
		$this->wpdb->seed_row( 'booking_types', 1, array( 'category' => 'lane', 'name' => 'Lane Booking' ) );
		Lead_Ingestion::on_booking_created( 8, array() );

		// The booking engine's own woo-sync bridge fires this hook with the
		// full booking row object rather than an int id at one call site —
		// the handler must accept both shapes.
		$row_object     = new \stdClass();
		$row_object->id = 8;
		Lead_Ingestion::on_booking_cancelled( $row_object, array( 'reason' => 'wc_order_cancelled' ) );

		$repo = new Leads_Repository();
		$id   = $repo->find_id_by_source( 'booking_engine', '8' );
		$this->assertSame( 'lost', $repo->find( $id )['status'] );
	}

	public function test_booking_no_show_marks_lead_lost() {
		$this->wpdb->mark_table_exists( 'wp_g2ab_bookings' );
		$this->wpdb->mark_table_exists( 'wp_g2ab_booking_types' );
		$this->wpdb->seed_row( 'bookings', 9, array( 'id' => 9, 'booking_type_id' => 1, 'status' => 'pending' ) );
		$this->wpdb->seed_row( 'booking_types', 1, array( 'category' => 'lane', 'name' => 'Lane Booking' ) );
		Lead_Ingestion::on_booking_created( 9, array() );

		Lead_Ingestion::on_booking_no_show( 9 );

		$repo = new Leads_Repository();
		$id   = $repo->find_id_by_source( 'booking_engine', '9' );
		$this->assertSame( 'lost', $repo->find( $id )['status'] );
	}

	public function test_membership_created_and_activated_upsert_the_same_lead() {
		$this->wpdb->mark_table_exists( 'wp_memberistic_memberships' );
		$this->wpdb->mark_table_exists( 'wp_memberistic_plans' );
		$this->wpdb->mark_table_exists( 'wp_memberistic_people' );
		$this->wpdb->seed_row( 'memberships', 12, array( 'id' => 12, 'plan_id' => 4, 'billing_amount' => 49.00, 'status' => 'active' ) );
		$this->wpdb->seed_scalar( 'plans', 4, 'Patriot' );
		$this->wpdb->seed_person( 12, array( 'full_name' => 'Sam Rivera', 'email' => 'sam@example.com', 'phone' => '555-2222' ) );

		Lead_Ingestion::on_membership_created( 12 );
		Lead_Ingestion::on_membership_activated( 12 );

		$this->assertSame( 1, $this->wpdb->lead_count() );

		$repo = new Leads_Repository();
		$id   = $repo->find_id_by_source( 'memberistic', '12' );
		$lead = $repo->find( $id );
		$this->assertSame( 'new_member', $lead['category'] );
		$this->assertSame( 'Sam Rivera', $lead['contactName'] );
		$this->assertSame( 'Patriot membership', $lead['subject'] );
	}

	public function test_membership_handler_noop_when_memberships_table_absent() {
		Lead_Ingestion::on_membership_created( 77 );

		$repo = new Leads_Repository();
		$this->assertNull( $repo->find_id_by_source( 'memberistic', '77' ) );
	}
}
