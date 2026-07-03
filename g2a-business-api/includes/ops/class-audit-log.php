<?php
/**
 * Small bounded audit log for operator-visible actions.
 *
 * Every mutation that leaves the plugin (send an email, hand a
 * cancellation to the Booking Engine, mark a queue entry completed) is
 * recorded here so the owner has a chronological "who did what, when" list.
 *
 * The log is capped — this is not a system-of-record; it's for reassurance.
 * Long-term persistence belongs in Messageistic's own log, not here.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit_Log {
	private const OPTION = 'g2aba_audit_log';
	private const MAX    = 500;

	public function record( string $kind, string $summary, array $meta = array() ): void {
		$all = $this->all();
		array_unshift(
			$all,
			array(
				'ts'      => gmdate( 'c' ),
				'kind'    => $kind,
				'summary' => $summary,
				'actor'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'meta'    => $meta,
			)
		);
		if ( count( $all ) > self::MAX ) {
			$all = array_slice( $all, 0, self::MAX );
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @return array<int, array>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
