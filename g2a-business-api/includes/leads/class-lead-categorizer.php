<?php
/**
 * Deterministic lead categorizer.
 *
 * Mirrors the BridGistic Classifier's philosophy (see
 * includes/bridgistic/class-classifier.php): no LLM call, pure rule-based
 * keyword matching, ordered most-specific-first so ambiguous text (e.g. a
 * form titled "CCW Class Transfer Question") resolves sensibly. Every
 * branch is a pure static function — string(s) in, string out — so it is
 * fully unit-testable without WordPress loaded.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lead_Categorizer {
	public const CATEGORY_LANE_BOOKING   = 'lane_booking';
	public const CATEGORY_CCW_CLASS      = 'ccw_class';
	public const CATEGORY_RANGE_ENQUIRY  = 'range_enquiry';
	public const CATEGORY_NEW_MEMBER     = 'new_member';
	public const CATEGORY_FFL_TRANSFER   = 'ffl_transfer';
	public const CATEGORY_NFA            = 'nfa';
	public const CATEGORY_MEMBERSHIP     = 'membership';
	public const CATEGORY_EVENT_BOOKING  = 'event_booking';
	public const CATEGORY_GENERAL        = 'general';

	// Ordered most-specific-first, matching the priority the business rules
	// were specified in: nfa, then ffl_transfer, then ccw_class, then
	// membership, then range_enquiry, else general. Checking ffl_transfer
	// before ccw_class means ambiguous text like "CCW transfer paperwork"
	// resolves to ffl_transfer — the "transfer" signal is the actionable,
	// narrower intent (an FFL transfer desk task) even when "ccw" is also
	// present in the same string.
	private const NFA_KEYWORDS = array( 'nfa', 'suppressor', 'silencer', 'sbr', 'short barrel', 'trust' );

	private const FFL_KEYWORDS = array( 'ffl', 'transfer' );

	private const CCW_KEYWORDS = array( 'ccw', 'concealed', 'class', 'course', 'training' );

	private const MEMBERSHIP_KEYWORDS = array( 'membership', 'member', 'defender', 'patriot', 'guardian' );

	private const RANGE_KEYWORDS = array( 'lane', 'reservation', 'book', 'booking', 'range' );

	/**
	 * Categorize a Formistic submission from its form name + field values.
	 *
	 * @param array<string, mixed> $fields Label => value map.
	 */
	public static function from_formistic( string $form_name, array $fields ): string {
		$haystack = strtolower( $form_name );
		foreach ( $fields as $value ) {
			if ( is_scalar( $value ) ) {
				$haystack .= ' ' . strtolower( (string) $value );
			}
		}

		if ( self::has_any( $haystack, self::NFA_KEYWORDS ) ) {
			return self::CATEGORY_NFA;
		}
		if ( self::has_any( $haystack, self::FFL_KEYWORDS ) ) {
			return self::CATEGORY_FFL_TRANSFER;
		}
		if ( self::has_any( $haystack, self::CCW_KEYWORDS ) ) {
			return self::CATEGORY_CCW_CLASS;
		}
		if ( self::has_any( $haystack, self::MEMBERSHIP_KEYWORDS ) ) {
			return self::CATEGORY_MEMBERSHIP;
		}
		if ( self::has_any( $haystack, self::RANGE_KEYWORDS ) ) {
			return self::CATEGORY_RANGE_ENQUIRY;
		}

		return self::CATEGORY_GENERAL;
	}

	/**
	 * Categorize a booking-engine lead from its booking type's category +
	 * name (`{$wpdb->prefix}g2ab_booking_types.category` / `.name`).
	 */
	public static function from_booking_type( string $type_category, string $type_name ): string {
		$category = strtolower( trim( $type_category ) );

		if ( 'event' === $category ) {
			return self::CATEGORY_EVENT_BOOKING;
		}
		if ( 'lane' === $category ) {
			return self::CATEGORY_LANE_BOOKING;
		}
		if ( 'class' === $category ) {
			// Every booking type on this range with category=class is a
			// training/CCW-style class — there is no separate "class" lead
			// category, so both branches land on ccw_class.
			return self::CATEGORY_CCW_CLASS;
		}

		return self::CATEGORY_RANGE_ENQUIRY;
	}

	/**
	 * Always new_member — kept as a method for symmetry/testability with
	 * the other two categorizers and so a future phase can branch on plan
	 * tier without touching call sites.
	 */
	public static function from_membership(): string {
		return self::CATEGORY_NEW_MEMBER;
	}

	private static function has_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
