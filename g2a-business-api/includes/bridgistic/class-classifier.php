<?php
/**
 * BridGistic command classifier.
 *
 * Deterministic — deliberately does NOT call an LLM. The classifier's only
 * job is to decide whether a natural-language request is safe to execute
 * immediately (read), safe to draft for owner review (draft), or must go
 * through the approval queue (action).
 *
 * A deterministic rule set is testable in phpunit and cannot be prompt-
 * injected into approving a destructive command.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\BridGistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Classifier {
	public const CATEGORY_READ   = 'read';
	public const CATEGORY_DRAFT  = 'draft';
	public const CATEGORY_ACTION = 'action';

	// Verbs that cause state change. Anything on this list forces `action`
	// unless a stronger draft signal wins first.
	private const ACTION_VERBS = array(
		'send', 'email', 'sms',
		'create', 'add', 'schedule', 'book',
		'update', 'change', 'edit', 'modify',
		'delete', 'remove', 'cancel',
		'refund', 'charge', 'issue',
		'approve', 'reject', 'ban', 'suspend',
	);

	// Verbs that imply "prepare something a human will send/approve later".
	private const DRAFT_VERBS = array(
		'draft', 'prepare', 'write', 'compose', 'propose', 'suggest',
	);

	public function classify( string $query ): string {
		$normalized = strtolower( trim( $query ) );

		if ( '' === $normalized ) {
			return self::CATEGORY_READ;
		}

		// Draft verbs take priority — "draft a refund email" is a draft, not
		// an action, even though "refund" and "email" match ACTION_VERBS.
		foreach ( self::DRAFT_VERBS as $v ) {
			if ( self::has_word( $normalized, $v ) ) {
				return self::CATEGORY_DRAFT;
			}
		}

		foreach ( self::ACTION_VERBS as $v ) {
			if ( self::has_word( $normalized, $v ) ) {
				return self::CATEGORY_ACTION;
			}
		}

		return self::CATEGORY_READ;
	}

	private static function has_word( string $haystack, string $needle ): bool {
		return (bool) preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/i', $haystack );
	}
}
