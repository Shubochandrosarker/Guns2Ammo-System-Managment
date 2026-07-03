<?php
/**
 * BridGistic action executor.
 *
 * Registers a single subscriber against `g2aba_bridgistic_action_approved`.
 * When an owner approves an action:
 *
 *  1. Route the query to a specific intent.
 *  2. Delegate to the handler for that intent.
 *  3. Persist a human-readable result string on the Action_Queue entry.
 *
 * Handlers here are deliberately CONSERVATIVE — none of them makes a
 * destructive network or DB change without hard evidence the query is safe.
 * The default for anything ambiguous is "draft, don't send." Adding a real
 * destructive handler is a follow-up PR with the specific evidence rules.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\BridGistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Executor {
	public static function register(): void {
		add_action( 'g2aba_bridgistic_action_approved', array( __CLASS__, 'on_approved' ), 10, 1 );
	}

	public static function on_approved( $entry ): void {
		if ( ! is_array( $entry ) || empty( $entry['id'] ) || empty( $entry['query'] ) ) {
			return;
		}

		$intent = ( new Intent_Router() )->route( (string) $entry['query'] );
		$result = self::handle( $intent, (string) $entry['query'] );

		// Update the queue entry with a human-readable execution result. We
		// re-read the option because another request may have touched it.
		$queue = new Action_Queue();
		$fresh = $queue->find( (string) $entry['id'] );
		if ( null === $fresh ) {
			return;
		}
		$queue->attach_result( (string) $entry['id'], $result );
	}

	private static function handle( string $intent, string $query ): string {
		switch ( $intent ) {
			case Intent_Router::INTENT_SEND_EMAIL:
				return self::handle_email_draft( $query );

			case Intent_Router::INTENT_CREATE_TASK:
				return self::handle_create_task( $query );

			case Intent_Router::INTENT_CANCEL_BOOKING:
				return self::handle_cancel_booking( $query );

			case Intent_Router::INTENT_UNKNOWN:
			default:
				return 'Approved, but no specific handler is registered for this intent yet. No side effects.';
		}
	}

	private static function handle_email_draft( string $query ): string {
		// SAFE by default — we never auto-send. The approved action creates a
		// draft in the plugin's own draft store; a follow-up admin action
		// sends it after human review. Storing here is intentionally cheap.
		$drafts    = get_option( 'g2aba_bridgistic_email_drafts', array() );
		$drafts    = is_array( $drafts ) ? $drafts : array();
		$drafts[]  = array(
			'query'     => $query,
			'createdAt' => gmdate( 'c' ),
			'status'    => 'pending_send',
		);
		update_option( 'g2aba_bridgistic_email_drafts', array_slice( $drafts, -50 ), false );
		return 'Drafted an email for the request. Nothing was sent — draft is queued for manual send.';
	}

	private static function handle_create_task( string $query ): string {
		$tasks   = get_option( 'g2aba_bridgistic_tasks', array() );
		$tasks   = is_array( $tasks ) ? $tasks : array();
		$tasks[] = array(
			'title'     => 'BridGistic: ' . self::first_words( $query, 12 ),
			'body'      => $query,
			'createdAt' => gmdate( 'c' ),
			'status'    => 'open',
		);
		update_option( 'g2aba_bridgistic_tasks', array_slice( $tasks, -100 ), false );
		return 'Task created and stored under g2aba_bridgistic_tasks.';
	}

	private static function handle_cancel_booking( string $query ): string {
		// Cancelling a real booking touches money. We deliberately do NOT
		// auto-cancel here — the executor extracts the booking id if the
		// query names one, records a cancellation request in a review
		// queue, and returns a clear "requires manual step" result.
		$id = self::extract_booking_id( $query );
		$q  = get_option( 'g2aba_bridgistic_cancel_queue', array() );
		$q  = is_array( $q ) ? $q : array();
		$q[] = array(
			'bookingId' => $id,
			'query'     => $query,
			'createdAt' => gmdate( 'c' ),
			'status'    => 'awaiting_manual_action',
		);
		update_option( 'g2aba_bridgistic_cancel_queue', array_slice( $q, -50 ), false );
		return $id
			? sprintf( 'Cancellation request queued for booking %s. Complete the cancel in the Booking Engine.', $id )
			: 'Cancellation request queued. Booking id was not detected in the query — resolve manually.';
	}

	private static function first_words( string $s, int $count ): string {
		$parts = preg_split( '/\s+/', trim( $s ) );
		if ( ! is_array( $parts ) ) {
			return $s;
		}
		return implode( ' ', array_slice( $parts, 0, $count ) );
	}

	/**
	 * @internal Exposed for tests. Pulls the first numeric id or #-prefixed
	 * id out of a natural-language query, returns null if none found.
	 */
	public static function extract_booking_id( string $query ): ?string {
		if ( preg_match( '/#\s*(\d+)/', $query, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/\b(booking|reservation|order)\s+(\d+)/i', $query, $m ) ) {
			return $m[2];
		}
		return null;
	}
}
