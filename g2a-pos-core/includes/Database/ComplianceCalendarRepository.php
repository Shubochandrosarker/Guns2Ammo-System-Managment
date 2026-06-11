<?php

namespace G2A\POS\Database;

/**
 * Compliance calendar: FFL renewal, employee training refresh, CA APPS due
 * dates, NY SAFE annual return, multi-sale long-gun reports. Combined with
 * a daily reminder cron that fires SMS + email N days before due.
 */
final class ComplianceCalendarRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_compliance_calendar' ),
			array(
				'event_type'          => sanitize_key( $data['event_type'] ?? 'custom' ),
				'scope_type'          => sanitize_key( $data['scope_type'] ?? 'business' ),
				'scope_value'         => sanitize_text_field( $data['scope_value'] ?? '' ),
				'title'               => sanitize_text_field( $data['title'] ?? '' ),
				'description'         => sanitize_textarea_field( $data['description'] ?? '' ),
				'due_at'              => sanitize_text_field( $data['due_at'] ?? $now ),
				'advance_notice_days' => (int) ( $data['advance_notice_days'] ?? 30 ),
				'recurrence'          => sanitize_text_field( $data['recurrence'] ?? '' ),
				'status'              => 'open',
				'related_entity_type' => sanitize_key( $data['related_entity_type'] ?? '' ),
				'related_entity_id'   => isset( $data['related_entity_id'] ) ? (int) $data['related_entity_id'] : null,
				'location_id'         => isset( $data['location_id'] ) ? (int) $data['location_id'] : null,
				'actor_id'            => get_current_user_id() ?: null,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function complete( int $id ): bool {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT due_at, recurrence FROM {$this->table('g2a_compliance_calendar')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return false;
		}

		$wpdb->update(
			$this->table( 'g2a_compliance_calendar' ),
			array(
				'status'       => 'completed',
				'completed_at' => $this->now(),
				'completed_by' => get_current_user_id() ?: null,
				'updated_at'   => $this->now(),
			),
			array( 'id' => $id )
		);

		// If recurring, auto-create the next occurrence.
		if ( ! empty( $row['recurrence'] ) ) {
			$next = $this->next_due( $row['due_at'], $row['recurrence'] );
			if ( $next ) {
				$current = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$this->table('g2a_compliance_calendar')} WHERE id = %d",
						$id
					),
					ARRAY_A
				);
				if ( $current ) {
					unset( $current['id'], $current['completed_at'], $current['completed_by'] );
					$current['due_at']           = $next;
					$current['status']           = 'open';
					$current['last_reminder_at'] = null;
					$current['created_at']       = $this->now();
					$current['updated_at']       = $this->now();
					$wpdb->insert( $this->table( 'g2a_compliance_calendar' ), $current );
				}
			}
		}
		return true;
	}

	public function list( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_compliance_calendar' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY due_at ASC LIMIT 200';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	public function due_for_reminder( \DateTimeInterface $now ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_compliance_calendar')}
             WHERE status = 'open'
             AND DATE_SUB(due_at, INTERVAL advance_notice_days DAY) <= %s
             AND (last_reminder_at IS NULL OR last_reminder_at < DATE_SUB(%s, INTERVAL 7 DAY))",
				$now->format( 'Y-m-d H:i:s' ),
				$now->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		) ?: array();
	}

	public function mark_reminded( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_compliance_calendar' ),
			array(
				'last_reminder_at' => $this->now(),
				'updated_at'       => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	private function next_due( string $current_due, string $recurrence ): ?string {
		$map  = array(
			'weekly'    => '+1 week',
			'monthly'   => '+1 month',
			'quarterly' => '+3 months',
			'annual'    => '+1 year',
			'biannual'  => '+6 months',
			'triennial' => '+3 years',
		);
		$expr = $map[ strtolower( $recurrence ) ] ?? null;
		if ( ! $expr ) {
			return null;
		}
		$ts = strtotime( $expr, strtotime( $current_due ) );
		return $ts ? date( 'Y-m-d H:i:s', $ts ) : null;
	}
}
