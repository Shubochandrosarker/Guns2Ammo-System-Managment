<?php
/**
 * Per-agent history log.
 *
 * Each agent has its own bounded ring in `g2aba_agent_history_<id>` — kept
 * separate from the agent record so a heavy history doesn't bloat the main
 * agents option.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_History {
	private const OPTION_PREFIX = 'g2aba_agent_history_';
	private const CAP           = 50;

	public static function append( string $agent_id, string $output, float $confidence ): void {
		$key = self::key( $agent_id );
		$raw = get_option( $key, array() );
		$raw = is_array( $raw ) ? $raw : array();

		array_unshift(
			$raw,
			array(
				'ts'         => gmdate( 'c' ),
				'output'     => $output,
				'confidence' => round( max( 0.0, min( 1.0, $confidence ) ), 2 ),
			)
		);
		if ( count( $raw ) > self::CAP ) {
			$raw = array_slice( $raw, 0, self::CAP );
		}
		update_option( $key, $raw, false );
	}

	/**
	 * @return array<int, array>
	 */
	public static function for_agent( string $agent_id ): array {
		$raw = get_option( self::key( $agent_id ), array() );
		return is_array( $raw ) ? $raw : array();
	}

	private static function key( string $agent_id ): string {
		return self::OPTION_PREFIX . preg_replace( '/[^a-z0-9_-]/i', '', $agent_id );
	}
}
