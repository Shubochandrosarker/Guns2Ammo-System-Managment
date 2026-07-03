<?php
/**
 * Persistent AI agent store.
 *
 * Each agent record carries the metadata the dashboard renders (name,
 * department, model, status, confidence, assigned tasks, last output) plus
 * a prompt template — the string handed to the model when the agent runs.
 *
 * Prompt templates keep the "what does this agent DO" question in one
 * reviewable place. Editing a prompt is an operator activity, not a
 * developer one, so future PRs can put it behind a WP-admin UI.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Store {
	private const OPTION = 'g2aba_agents';

	public const STATUS_ACTIVE       = 'active';
	public const STATUS_PAUSED       = 'paused';
	public const STATUS_NEEDS_REVIEW = 'needs_review';

	public static function seed_defaults(): void {
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		foreach ( self::defaults() as $id => $rec ) {
			if ( isset( $existing[ $id ] ) && is_array( $existing[ $id ] ) ) {
				// Preserve operator-mutated fields; refresh the rest from seed.
				$existing[ $id ] = array_merge(
					$rec,
					array(
						'status'         => (string) ( $existing[ $id ]['status'] ?? $rec['status'] ),
						'model'          => (string) ( $existing[ $id ]['model'] ?? $rec['model'] ),
						'promptTemplate' => (string) ( $existing[ $id ]['promptTemplate'] ?? $rec['promptTemplate'] ),
						'lastRun'        => $existing[ $id ]['lastRun']    ?? null,
						'lastOutput'     => (string) ( $existing[ $id ]['lastOutput']    ?? $rec['lastOutput'] ),
						'confidence'     => (float) ( $existing[ $id ]['confidence']     ?? $rec['confidence'] ),
						'assignedTasks'  => (int) ( $existing[ $id ]['assignedTasks']  ?? $rec['assignedTasks'] ),
					)
				);
			} else {
				$existing[ $id ] = $rec;
			}
		}
		update_option( self::OPTION, $existing, false );
	}

	/**
	 * @return array<int, array>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	public function find( string $id ): ?array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) && isset( $raw[ $id ] ) ? $raw[ $id ] : null;
	}

	/**
	 * Update the record with the last-run output. `output` becomes
	 * `lastOutput`; a full snapshot is appended to the per-agent history.
	 *
	 * @return array|null Updated record, or null if no such agent.
	 */
	public function record_run( string $id, string $output, float $confidence = 0.0 ): ?array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $id ] ) ) {
			return null;
		}

		$now                              = gmdate( 'c' );
		$raw[ $id ]['lastRun']            = $now;
		$raw[ $id ]['lastOutput']         = $output;
		$raw[ $id ]['confidence']         = round( max( 0.0, min( 1.0, $confidence ) ), 2 );
		update_option( self::OPTION, $raw, false );

		Agent_History::append( $id, $output, $raw[ $id ]['confidence'] );
		return $raw[ $id ];
	}

	/**
	 * @return array<string, array>
	 */
	public static function defaults(): array {
		return array(
			'ag-seo' => array(
				'id'             => 'ag-seo',
				'name'           => 'SEO Growth Agent',
				'department'     => 'seo',
				'status'         => self::STATUS_ACTIVE,
				'model'          => 'anthropic-primary',
				'assignedTasks'  => 6,
				'lastRun'        => null,
				'lastOutput'     => 'No runs yet.',
				'confidence'     => 0.0,
				'reviewRequired' => false,
				'promptTemplate' =>
					"You are the SEO analyst for Guns2Ammo (indoor range + retail + training in Mesa, AZ).\n"
					. "Given this GSC snapshot, name 2 pages losing clicks + 2 pages with rising impressions but low CTR, and one concrete fix each.\n\n"
					. "SNAPSHOT:\n{{snapshot}}",
			),
			'ag-analyst' => array(
				'id'             => 'ag-analyst',
				'name'           => 'Business Analyst Agent',
				'department'     => 'analyst',
				'status'         => self::STATUS_ACTIVE,
				'model'          => 'anthropic-primary',
				'assignedTasks'  => 4,
				'lastRun'        => null,
				'lastOutput'     => 'No runs yet.',
				'confidence'     => 0.0,
				'reviewRequired' => false,
				'promptTemplate' =>
					"You are the business analyst. Given the 30-day snapshot, name the single most impactful lever the owner should pull this week and quantify the expected impact.\n\n"
					. "SNAPSHOT:\n{{snapshot}}",
			),
			'ag-booking' => array(
				'id'             => 'ag-booking',
				'name'           => 'Booking Agent',
				'department'     => 'booking',
				'status'         => self::STATUS_ACTIVE,
				'model'          => 'anthropic-primary',
				'assignedTasks'  => 3,
				'lastRun'        => null,
				'lastOutput'     => 'No runs yet.',
				'confidence'     => 0.0,
				'reviewRequired' => true,
				'promptTemplate' =>
					"You are the booking-flow analyst. From this snapshot, identify one booking type with rising demand and suggest a capacity or promotion change.\n\n"
					. "SNAPSHOT:\n{{snapshot}}",
			),
			'ag-member' => array(
				'id'             => 'ag-member',
				'name'           => 'Membership Agent',
				'department'     => 'membership',
				'status'         => self::STATUS_ACTIVE,
				'model'          => 'anthropic-primary',
				'assignedTasks'  => 5,
				'lastRun'        => null,
				'lastOutput'     => 'No runs yet.',
				'confidence'     => 0.0,
				'reviewRequired' => true,
				'promptTemplate' =>
					"You are the membership retention analyst. From the snapshot, propose the retention tactic most likely to recover the current churn queue.\n\n"
					. "SNAPSHOT:\n{{snapshot}}",
			),
			'ag-inventory' => array(
				'id'             => 'ag-inventory',
				'name'           => 'Inventory Insight Agent',
				'department'     => 'inventory',
				'status'         => self::STATUS_ACTIVE,
				'model'          => 'anthropic-primary',
				'assignedTasks'  => 1,
				'lastRun'        => null,
				'lastOutput'     => 'No runs yet.',
				'confidence'     => 0.0,
				'reviewRequired' => false,
				'promptTemplate' =>
					"You are the inventory analyst. From the snapshot, name one clearance bundle worth building this week (SKUs + bundle price).\n\n"
					. "SNAPSHOT:\n{{snapshot}}",
			),
			'ag-reports' => array(
				'id'             => 'ag-reports',
				'name'           => 'Report Agent',
				'department'     => 'reports',
				'status'         => self::STATUS_ACTIVE,
				'model'          => 'anthropic-primary',
				'assignedTasks'  => 1,
				'lastRun'        => null,
				'lastOutput'     => 'No runs yet.',
				'confidence'     => 0.0,
				'reviewRequired' => false,
				'promptTemplate' =>
					"You are the weekly reporter. Summarize the week in 5 bullet points: revenue, top movers, misses, biggest risk, top action.\n\n"
					. "SNAPSHOT:\n{{snapshot}}",
			),
		);
	}
}
