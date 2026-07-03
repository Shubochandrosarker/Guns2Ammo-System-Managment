<?php
/**
 * Insight generator — runs on WP-Cron, never on the REST hot path.
 *
 * On each tick it gathers a compact analytics snapshot via existing
 * providers, sends it to Anthropic with a strict JSON-only prompt, then
 * validates and persists the result to `g2aba_insights_cache`. The
 * `/ai/insights` endpoint reads that option — no LLM roundtrip per request.
 *
 * Any error is caught, recorded to `g2aba_insights_last_error`, and the
 * previous cache is preserved.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\AI;

use WordPressistic\G2ABA\Integrations\Anthropic_Client;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insight_Generator {
	public const CRON_HOOK = 'g2aba_generate_insights';

	public static function register_cron(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	public static function run(): void {
		$client = new Anthropic_Client();
		if ( ! $client->is_configured() ) {
			update_option( 'g2aba_insights_last_error', 'Anthropic connection not configured', false );
			return;
		}

		try {
			$range   = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
			$snapshot = self::snapshot( $range );

			$raw = $client->complete( self::prompt( $snapshot ) );
			$out = self::extract_json_array( $raw );

			update_option( 'g2aba_insights_cache', self::normalize( $out ), false );
			update_option( 'g2aba_insights_last_error', '', false );
			update_option( 'g2aba_insights_last_run', gmdate( 'c' ), false );
		} catch ( \Throwable $e ) {
			update_option( 'g2aba_insights_last_error', $e->getMessage(), false );
		}
	}

	private static function snapshot( Range $range ): array {
		return array(
			'range'       => $range->to_array(),
			'overview'    => ( new Revenue_Provider() )->overview( $range ),
			'bookings'    => ( new Booking_Provider() )->analytics( $range ),
			'memberships' => ( new Membership_Provider() )->analytics( $range ),
			'store'       => ( new Store_Provider() )->analytics( $range ),
			'seo'         => ( new SEO_Provider() )->analytics( $range ),
		);
	}

	public static function prompt( array $snapshot ): string {
		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return implode(
			"\n",
			array(
				'You are the business analyst for Guns2Ammo (indoor range + firearms retail + FFL + training).',
				'Analyze the 30-day snapshot below and produce 3–5 concrete, evidence-backed insights.',
				'',
				'Return ONLY a JSON array. No prose, no code fence. Each element must match this shape:',
				'{',
				'  "id": "kebab-case-slug",',
				'  "title": "short imperative sentence",',
				'  "category": "seo|booking|membership|store|operations|customer|revenue",',
				'  "summary": "1 sentence problem statement citing a number from the snapshot",',
				'  "reason": "1 sentence causal hypothesis",',
				'  "action": "1 imperative sentence — a specific step someone can do this week",',
				'  "priority": "high|medium|low",',
				'  "expectedImpact": "quantified when possible, e.g. \\"+12 bookings/mo\\""',
				'}',
				'',
				'SNAPSHOT:',
				(string) $json,
			)
		);
	}

	/**
	 * Best-effort JSON-array extractor. Anthropic is instructed to return
	 * only JSON but occasionally wraps it in a code fence — this pulls the
	 * first `[...]` array out either way.
	 *
	 * @return array<int, array>
	 */
	public static function extract_json_array( string $raw ): array {
		$raw = trim( $raw );

		// Strip ```json fences if present.
		if ( 0 === strpos( $raw, '```' ) ) {
			$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $raw ) ?? '';
		}

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( $decoded, 'is_array' ) );
		}

		// Fallback: find the first top-level array literal.
		if ( preg_match( '/\[.*\]/s', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( $decoded, 'is_array' ) );
			}
		}
		throw new \RuntimeException( 'Insight generator: LLM output did not contain a JSON array' );
	}

	public static function normalize( array $insights ): array {
		$out = array();
		$now = gmdate( 'Y-m-d' );
		foreach ( $insights as $ins ) {
			$id = isset( $ins['id'] ) && '' !== $ins['id']
				? sanitize_title( (string) $ins['id'] )
				: 'ins-' . substr( md5( wp_json_encode( $ins ) ), 0, 8 );

			$priority = strtolower( (string) ( $ins['priority'] ?? 'medium' ) );
			if ( ! in_array( $priority, array( 'high', 'medium', 'low' ), true ) ) {
				$priority = 'medium';
			}

			$category = strtolower( (string) ( $ins['category'] ?? 'operations' ) );
			$allowed  = array( 'seo', 'booking', 'membership', 'store', 'operations', 'customer', 'revenue' );
			if ( ! in_array( $category, $allowed, true ) ) {
				$category = 'operations';
			}

			$out[] = array(
				'id'             => $id,
				'title'          => (string) ( $ins['title'] ?? 'Untitled insight' ),
				'category'       => $category,
				'summary'        => (string) ( $ins['summary'] ?? '' ),
				'reason'         => (string) ( $ins['reason'] ?? '' ),
				'action'         => (string) ( $ins['action'] ?? '' ),
				'priority'       => $priority,
				'expectedImpact' => (string) ( $ins['expectedImpact'] ?? '' ),
				'createdAt'      => $now,
			);
		}
		return $out;
	}
}
