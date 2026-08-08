<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Leads\Leads_Repository;

/**
 * Minimal in-memory $wpdb double for the `g2aba_leads` table.
 *
 * Rather than a generic SQL engine, this recognizes the exact literal query
 * shapes Leads_Repository emits (they're PHP string constants in that file)
 * and answers them against an in-memory row store. `prepare()` packs the
 * template + args together (mirroring EmailOverviewProviderTest's simpler
 * fakes elsewhere in this suite) so get_var/get_row/get_results can route on
 * the *template* text — which never changes across test data — instead of
 * re-parsing a substituted SQL string.
 */
class Leads_Fake_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $queries   = array();

	/** @var array<int, array<string,mixed>> */
	private array $rows    = array();
	private int $next_id   = 1;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return $query . "\x00" . serialize( $args );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function insert( $table, $data, $format = null ) {
		$id                 = $this->next_id++;
		$data['id']         = $id;
		$this->rows[ $id ]  = $data;
		$this->insert_id    = $id;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return 1;
	}

	public function get_var( $prepared ) {
		$this->queries[] = $prepared;
		list( $template, $args ) = $this->split( $prepared );

		if ( false !== strpos( $template, 'SELECT id FROM' ) && false !== strpos( $template, 'source_ref_id = %s' ) ) {
			foreach ( $this->rows as $row ) {
				if ( ( $row['source'] ?? null ) === $args[0] && ( $row['source_ref_id'] ?? null ) === $args[1] ) {
					return $row['id'];
				}
			}
			return null;
		}

		if ( false !== strpos( $template, 'SELECT COUNT(*)' ) ) {
			if ( false !== strpos( $template, 'CURDATE()' ) ) {
				$today = gmdate( 'Y-m-d' );
				return count(
					array_filter(
						$this->rows,
						static fn( $r ) => 0 === strpos( (string) ( $r['created_at'] ?? '' ), $today )
					)
				);
			}
			if ( false !== strpos( $template, 'INTERVAL 7 DAY' ) || false !== strpos( $template, 'INTERVAL 30 DAY' ) ) {
				// Every row in these tests is "now", so it always counts.
				return count( $this->rows );
			}
			return count( $this->apply_filters( $template, $args ) );
		}

		return null;
	}

	public function get_row( $prepared, $output = null ) {
		$this->queries[] = $prepared;
		list( $template, $args ) = $this->split( $prepared );

		if ( false !== strpos( $template, 'WHERE id = %d' ) ) {
			$id = (int) $args[0];
			return $this->rows[ $id ] ?? null;
		}
		return null;
	}

	public function get_results( $prepared, $output = null ) {
		$this->queries[] = $prepared;
		list( $template, $args ) = $this->split( $prepared );

		if ( false !== strpos( $template, 'GROUP BY category' ) ) {
			$counts = array();
			foreach ( $this->rows as $row ) {
				$c            = (string) ( $row['category'] ?? '' );
				$counts[ $c ] = ( $counts[ $c ] ?? 0 ) + 1;
			}
			$out = array();
			foreach ( $counts as $category => $n ) {
				$out[] = array( 'category' => $category, 'n' => $n );
			}
			return $out;
		}

		if ( false !== strpos( $template, 'GROUP BY status' ) ) {
			$counts = array();
			foreach ( $this->rows as $row ) {
				$s            = (string) ( $row['status'] ?? '' );
				$counts[ $s ] = ( $counts[ $s ] ?? 0 ) + 1;
			}
			$out = array();
			foreach ( $counts as $status => $n ) {
				$out[] = array( 'status' => $status, 'n' => $n );
			}
			return $out;
		}

		if ( false !== strpos( $template, 'SELECT * FROM' ) ) {
			// Last two args are always [per_page, offset] appended in that
			// order (see Leads_Repository::query()), so the last element
			// popped off is the offset and the one before it is the limit.
			$offset = (int) array_pop( $args );
			$limit  = (int) array_pop( $args );

			$filtered = $this->apply_filters( $template, $args );
			usort( $filtered, static fn( $a, $b ) => strcmp( (string) $b['created_at'], (string) $a['created_at'] ) );
			return array_slice( array_values( $filtered ), $offset, $limit );
		}

		return array();
	}

	private function apply_filters( string $template, array $args ): array {
		$i        = 0;
		$filtered = $this->rows;

		if ( false !== strpos( $template, 'category = %s' ) ) {
			$val      = $args[ $i++ ];
			$filtered = array_filter( $filtered, static fn( $r ) => ( $r['category'] ?? '' ) === $val );
		}
		if ( false !== strpos( $template, 'status = %s' ) ) {
			$val      = $args[ $i++ ];
			$filtered = array_filter( $filtered, static fn( $r ) => ( $r['status'] ?? '' ) === $val );
		}
		// apply_filters() is only ever reached from the query()/stats() call
		// sites, neither of which pairs "source = %s" with "source_ref_id"
		// (that lookup is handled by its own branch in get_var()), so a
		// plain substring check is unambiguous here.
		if ( false !== strpos( $template, 'source = %s' ) ) {
			$val      = $args[ $i++ ];
			$filtered = array_filter( $filtered, static fn( $r ) => ( $r['source'] ?? '' ) === $val );
		}
		if ( false !== strpos( $template, 'contact_name LIKE' ) ) {
			$like = (string) $args[ $i++ ];
			$i   += 2; // Two more identical LIKE args for email/subject.
			$needle   = trim( $like, '%' );
			$filtered = array_filter(
				$filtered,
				static fn( $r ) => false !== stripos( (string) ( $r['contact_name'] ?? '' ), $needle )
					|| false !== stripos( (string) ( $r['contact_email'] ?? '' ), $needle )
					|| false !== stripos( (string) ( $r['subject'] ?? '' ), $needle )
			);
		}
		if ( false !== strpos( $template, 'created_at >= %s' ) ) {
			$val      = $args[ $i++ ];
			$filtered = array_filter( $filtered, static fn( $r ) => (string) ( $r['created_at'] ?? '' ) >= $val );
		}
		if ( false !== strpos( $template, 'created_at <= %s' ) ) {
			$val      = $args[ $i++ ];
			$filtered = array_filter( $filtered, static fn( $r ) => (string) ( $r['created_at'] ?? '' ) <= $val );
		}

		return $filtered;
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

class LeadsRepositoryTest extends TestCase {
	private Leads_Fake_WPDB $wpdb;

	protected function setUp(): void {
		$this->wpdb       = new Leads_Fake_WPDB();
		$GLOBALS['wpdb']  = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_upsert_by_source_inserts_a_new_lead() {
		$repo = new Leads_Repository();
		$id   = $repo->upsert_by_source(
			'formistic',
			'42',
			array(
				'category'      => 'range_enquiry',
				'contact_name'  => 'Priya',
				'contact_email' => 'priya@example.com',
			)
		);

		$this->assertGreaterThan( 0, $id );
		$lead = $repo->find( $id );
		$this->assertSame( 'range_enquiry', $lead['category'] );
		$this->assertSame( 'formistic', $lead['source'] );
		$this->assertSame( '42', $lead['sourceRefId'] );
		$this->assertSame( 'new', $lead['status'] );
		$this->assertSame( 'Priya', $lead['contactName'] );
	}

	public function test_upsert_by_source_updates_the_same_row_on_repeat_calls() {
		$repo    = new Leads_Repository();
		$first   = $repo->upsert_by_source( 'booking_engine', '900', array( 'category' => 'lane_booking', 'status' => 'new' ) );
		$second  = $repo->upsert_by_source( 'booking_engine', '900', array( 'status' => 'in_progress' ) );

		$this->assertSame( $first, $second, 'Re-firing the hook must update, not duplicate.' );
		$lead = $repo->find( $first );
		$this->assertSame( 'in_progress', $lead['status'] );
		// Category set on the first call survives an update that doesn't touch it.
		$this->assertSame( 'lane_booking', $lead['category'] );
	}

	public function test_upsert_by_source_json_encodes_meta() {
		$repo = new Leads_Repository();
		$id   = $repo->upsert_by_source( 'memberistic', '5', array( 'meta' => array( 'plan' => 'Patriot' ) ) );

		$lead = $repo->find( $id );
		$this->assertSame( array( 'plan' => 'Patriot' ), $lead['meta'] );
	}

	public function test_update_status_rejects_unknown_status() {
		$repo = new Leads_Repository();
		$id   = $repo->upsert_by_source( 'formistic', '1', array() );

		$this->assertFalse( $repo->update_status( $id, 'not_a_real_status' ) );
		$this->assertSame( 'new', $repo->find( $id )['status'] );
	}

	public function test_update_status_accepts_every_allowed_status() {
		$repo = new Leads_Repository();
		$id   = $repo->upsert_by_source( 'formistic', '2', array() );

		foreach ( Leads_Repository::STATUSES as $status ) {
			$this->assertTrue( $repo->update_status( $id, $status ) );
			$this->assertSame( $status, $repo->find( $id )['status'] );
		}
	}

	public function test_assign_sets_and_clears_agent() {
		$repo = new Leads_Repository();
		$id   = $repo->upsert_by_source( 'formistic', '3', array() );

		$this->assertTrue( $repo->assign( $id, 'ag-booking' ) );
		$this->assertSame( 'ag-booking', $repo->find( $id )['assignedAgent'] );

		$this->assertTrue( $repo->assign( $id, null ) );
		$this->assertNull( $repo->find( $id )['assignedAgent'] );
	}

	public function test_find_returns_null_for_missing_id() {
		$repo = new Leads_Repository();
		$this->assertNull( $repo->find( 999 ) );
	}

	public function test_find_id_by_source_only_finds_existing() {
		$repo = new Leads_Repository();
		$this->assertNull( $repo->find_id_by_source( 'booking_engine', '404' ) );

		$id = $repo->upsert_by_source( 'booking_engine', '404', array() );
		$this->assertSame( $id, $repo->find_id_by_source( 'booking_engine', '404' ) );
	}

	public function test_query_filters_by_category_and_status() {
		$repo = new Leads_Repository();
		$repo->upsert_by_source( 'formistic', '1', array( 'category' => 'nfa', 'status' => 'new' ) );
		$repo->upsert_by_source( 'formistic', '2', array( 'category' => 'nfa', 'status' => 'won' ) );
		$repo->upsert_by_source( 'formistic', '3', array( 'category' => 'general', 'status' => 'new' ) );

		$result = $repo->query( array( 'category' => 'nfa', 'status' => 'new' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'nfa', $result['items'][0]['category'] );
	}

	public function test_query_search_matches_contact_fields() {
		$repo = new Leads_Repository();
		$repo->upsert_by_source( 'formistic', '1', array( 'contact_name' => 'Priya Singh' ) );
		$repo->upsert_by_source( 'formistic', '2', array( 'contact_name' => 'Bob Jones' ) );

		$result = $repo->query( array( 'search' => 'priya' ) );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_query_caps_per_page_at_100() {
		$repo = new Leads_Repository();
		for ( $i = 1; $i <= 5; $i++ ) {
			$repo->upsert_by_source( 'formistic', (string) $i, array() );
		}
		$result = $repo->query( array( 'per_page' => 5000 ) );
		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 5, $result['items'] );
	}

	public function test_stats_counts_by_category_and_status() {
		$repo = new Leads_Repository();
		$repo->upsert_by_source( 'formistic', '1', array( 'category' => 'nfa', 'status' => 'new' ) );
		$repo->upsert_by_source( 'formistic', '2', array( 'category' => 'nfa', 'status' => 'won' ) );
		$repo->upsert_by_source( 'formistic', '3', array( 'category' => 'general', 'status' => 'new' ) );

		$stats = $repo->stats();
		$this->assertSame( 2, $stats['byCategory']['nfa'] );
		$this->assertSame( 1, $stats['byCategory']['general'] );
		$this->assertSame( 2, $stats['byStatus']['new'] );
		$this->assertSame( 1, $stats['byStatus']['won'] );
		$this->assertSame( 3, $stats['today'] );
	}
}
