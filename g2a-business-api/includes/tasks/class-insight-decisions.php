<?php
/**
 * Tracks operator decisions on AI insights so the same insight doesn't
 * reappear on the dashboard after Approve or Dismiss.
 *
 * The insight cache (`g2aba_insights_cache`) is regenerated hourly by the
 * Anthropic runner. The runner's output is filtered against this store
 * before it ships to the dashboard — dismissed ids stay hidden, and
 * approved ids get a `converted` flag so the frontend can render a small
 * "converted to task {task_id}" indicator instead of the full card.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Tasks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insight_Decisions {
	private const OPTION = 'g2aba_insight_decisions';

	public const DECISION_APPROVED  = 'approved';
	public const DECISION_DISMISSED = 'dismissed';

	public function approve( string $insight_id, string $task_id ): void {
		$this->record( $insight_id, self::DECISION_APPROVED, array( 'taskId' => $task_id ) );
	}

	public function dismiss( string $insight_id ): void {
		$this->record( $insight_id, self::DECISION_DISMISSED, array() );
	}

	public function decision_for( string $insight_id ): ?array {
		$raw = $this->raw();
		return $raw[ $insight_id ] ?? null;
	}

	public function is_dismissed( string $insight_id ): bool {
		$d = $this->decision_for( $insight_id );
		return is_array( $d ) && ( $d['decision'] ?? '' ) === self::DECISION_DISMISSED;
	}

	public function is_approved( string $insight_id ): bool {
		$d = $this->decision_for( $insight_id );
		return is_array( $d ) && ( $d['decision'] ?? '' ) === self::DECISION_APPROVED;
	}

	/**
	 * Filter a raw insights list against stored decisions. Dismissed
	 * insights drop out; approved insights carry the `taskId` they
	 * created.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<int, array> $insights
	 * @return array<int, array>
	 */
	public function apply( array $insights ): array {
		$decisions = $this->raw();
		$out       = array();
		foreach ( $insights as $ins ) {
			$id = (string) ( $ins['id'] ?? '' );
			if ( '' === $id ) {
				$out[] = $ins;
				continue;
			}
			$d = $decisions[ $id ] ?? null;
			if ( is_array( $d ) && ( $d['decision'] ?? '' ) === self::DECISION_DISMISSED ) {
				continue; // Drop.
			}
			if ( is_array( $d ) && ( $d['decision'] ?? '' ) === self::DECISION_APPROVED ) {
				$ins['converted'] = true;
				$ins['taskId']    = (string) ( $d['meta']['taskId'] ?? '' );
			}
			$out[] = $ins;
		}
		return $out;
	}

	public function reset( string $insight_id ): void {
		$raw = $this->raw();
		unset( $raw[ $insight_id ] );
		update_option( self::OPTION, $raw, false );
	}

	private function record( string $insight_id, string $decision, array $meta ): void {
		$raw                     = $this->raw();
		$raw[ $insight_id ]      = array(
			'decision' => $decision,
			'decidedAt'=> gmdate( 'c' ),
			'meta'     => $meta,
		);
		update_option( self::OPTION, $raw, false );
	}

	/**
	 * @return array<string, array>
	 */
	private function raw(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
