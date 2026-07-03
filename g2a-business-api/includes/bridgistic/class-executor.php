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
		// draft in Email_Draft_Store; the WP-admin Email Drafts screen (or
		// the /email-drafts REST route) is where a human reviews + sends.
		$draft = ( new \WordPressistic\G2ABA\Ops\Email_Draft_Store() )->enqueue( $query );
		return sprintf(
			'Drafted email %s. Review + send from Settings → G2A Business API → Email Drafts.',
			$draft['id']
		);
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
		// query names one, records a cancellation *request* in the
		// Cancellation_Queue for a human to finalize.
		$booking_id = self::extract_booking_id( $query );
		$entry      = ( new \WordPressistic\G2ABA\Ops\Cancellation_Queue() )->enqueue( $booking_id, $query );

		return $booking_id
			? sprintf( 'Cancellation request %s queued for booking %s. Complete the cancel in the Booking Engine.', $entry['id'], $booking_id )
			: sprintf( 'Cancellation request %s queued. Booking id was not detected in the query — resolve manually.', $entry['id'] );
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
