<?php
/**
 * BridGistic intent router.
 *
 * Once an action is approved by the owner, we still need to know WHICH
 * action to execute. The classifier only tells us "this is an action" — the
 * intent router picks a specific handler from a small, curated list.
 *
 * As with the classifier, this is deterministic on purpose: the router
 * decides what runs, not the LLM. Adding a new handler means one new match
 * rule + one new subscriber, both under review.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\BridGistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Intent_Router {
	public const INTENT_UNKNOWN     = 'unknown';
	public const INTENT_SEND_EMAIL  = 'send_email';
	public const INTENT_CREATE_TASK = 'create_task';
	public const INTENT_CANCEL_BOOKING = 'cancel_booking';

	/**
	 * Rules are `[intent, needle_verbs, needle_nouns]`. All three columns must
	 * match — an action verb AND a topic noun — before an intent is picked.
	 * Order matters: the first matching rule wins.
	 *
	 * @var array<int, array{0:string, 1:array<int,string>, 2:array<int,string>}>
	 */
	private const RULES = array(
		array( self::INTENT_CANCEL_BOOKING, array( 'cancel' ),                    array( 'booking', 'reservation', 'lane' ) ),
		array( self::INTENT_SEND_EMAIL,     array( 'send', 'email' ),             array( 'email', 'reminder', 'follow-up', 'renewal', 'newsletter', 'customer', 'member' ) ),
		array( self::INTENT_CREATE_TASK,    array( 'create', 'add', 'schedule' ), array( 'task', 'todo', 'reminder', 'ticket', 'work-order' ) ),
	);

	public function route( string $query ): string {
		$normalized = strtolower( $query );
		foreach ( self::RULES as $rule ) {
			[ $intent, $verbs, $nouns ] = $rule;
			if ( self::any_word( $normalized, $verbs ) && self::any_word( $normalized, $nouns ) ) {
				return $intent;
			}
		}
		return self::INTENT_UNKNOWN;
	}

	private static function any_word( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $haystack ) ) {
				return true;
			}
		}
		return false;
	}
}
