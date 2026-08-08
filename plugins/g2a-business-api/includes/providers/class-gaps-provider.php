<?php
/**
 * Business gap detector.
 *
 * Each rule is intentionally small and self-contained — the point is not to
 * be clever, it's to give the owner a deterministic checklist of leaks the
 * dashboard has evidence for. Every returned gap must include an evidence
 * string so the owner can verify the finding.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gaps_Provider {
	public function detect( array $bookings, array $memberships, array $store, array $seo, array $gbp = array() ): array {
		$gaps = array();

		// Rule 1 — renewal underperformance.
		$renewals = (int) ( $memberships['renewals'] ?? 0 );
		$expired  = (int) ( $memberships['expired'] ?? 0 );
		if ( $renewals + $expired > 5 ) {
			$rate = round( ( $renewals / ( $renewals + $expired ) ) * 100, 1 );
			if ( $rate < 65.0 ) {
				$gaps[] = array(
					'id'       => 'gap-membership-renewal',
					'problem'  => 'Membership renewals underperforming',
					'evidence' => sprintf( '%d expired vs %d renewed = %.1f%% renewal rate (target ≥ 65%%).', $expired, $renewals, $rate ),
					'impact'   => 'Recover expected 15–20 memberships/mo based on current expiries.',
					'fix'      => 'Enable 30/7/1-day renewal reminders + a 14-day post-expiry win-back offer.',
					'priority' => 'high',
					'owner'    => 'Membership Ops',
				);
			}
		}

		// Rule 2 — high no-show rate.
		$no_show = (float) ( $bookings['noShowRate'] ?? 0.0 );
		if ( $no_show >= 8.0 ) {
			$gaps[] = array(
				'id'       => 'gap-booking-no-show',
				'problem'  => 'Booking no-show rate elevated',
				'evidence' => sprintf( 'No-show rate is %.1f%% — above the 5%% threshold.', $no_show ),
				'impact'   => 'Wasted lane capacity + lost revenue.',
				'fix'      => 'Require deposits above capacity threshold; enable 24h SMS reminders.',
				'priority' => 'medium',
				'owner'    => 'Booking Ops',
			);
		}

		// Rule 3 — slow-mover pile-up.
		$slow = (array) ( $store['slowMovers'] ?? array() );
		if ( count( $slow ) >= 3 ) {
			$gaps[] = array(
				'id'       => 'gap-store-slow-movers',
				'problem'  => 'Slow-moving SKUs tying up shelf space',
				'evidence' => sprintf( '%d SKUs unsold ≥30 days.', count( $slow ) ),
				'impact'   => 'Free shelf space + recover cash.',
				'fix'      => 'Bundle with best-sellers or run a clearance email to the newsletter list.',
				'priority' => 'low',
				'owner'    => 'Ecommerce',
			);
		}

		// Rule 4 — refund rate above 2% of store revenue.
		$revenue = (int) ( $store['revenue'] ?? 0 );
		$refunds = (int) ( $store['refundAmount'] ?? 0 );
		if ( $revenue > 0 && ( $refunds / $revenue ) * 100 >= 2.0 ) {
			$gaps[] = array(
				'id'       => 'gap-store-refunds',
				'problem'  => 'Refund rate above 2% of store revenue',
				'evidence' => sprintf( '$%d refunded on $%d revenue (%.1f%%).', $refunds / 100, $revenue / 100, ( $refunds / $revenue ) * 100 ),
				'impact'   => 'Investigate for defective products, sizing, or misleading descriptions.',
				'fix'      => 'Sample recent refunded orders; identify top refund reasons.',
				'priority' => 'medium',
				'owner'    => 'Ecommerce',
			);
		}

		// Rule 5 — SEO clicks below plausible floor for a live site (>7 days of data).
		$clicks = (int) ( $seo['clicks'] ?? 0 );
		if ( $clicks > 0 && $clicks < 200 ) {
			$gaps[] = array(
				'id'       => 'gap-seo-visibility',
				'problem'  => 'Organic search traffic well below expectations',
				'evidence' => sprintf( 'Only %d organic clicks in the last 30 days.', $clicks ),
				'impact'   => 'High-intent local traffic being missed.',
				'fix'      => 'Audit landing pages for target queries + local-pack signals (GBP, citations).',
				'priority' => 'medium',
				'owner'    => 'Content',
			);
		}

		// Rule 6 — Google Business Profile action rate is very low.
		// If we see ≥ 1000 profile views but < 10 calls + directions +
		// website clicks combined, the listing is being seen but not acted on.
		$views_total   = (int) ( $gbp['viewsSearch'] ?? 0 ) + (int) ( $gbp['viewsMaps'] ?? 0 );
		$actions_total = (int) ( $gbp['callsClicked'] ?? 0 )
			+ (int) ( $gbp['directionsRequested'] ?? 0 )
			+ (int) ( $gbp['websiteClicked'] ?? 0 );
		if ( $views_total >= 1000 && $actions_total < 10 ) {
			$gaps[] = array(
				'id'       => 'gap-gbp-actions',
				'problem'  => 'GBP listing gets views but no actions',
				'evidence' => sprintf( '%d listing views vs %d actions (calls + directions + website).', $views_total, $actions_total ),
				'impact'   => 'Local searchers see the store but aren\'t routed anywhere.',
				'fix'      => 'Refresh hours, primary photo, and post a recent update; verify phone + website links.',
				'priority' => 'high',
				'owner'    => 'Marketing',
			);
		}

		// Rule 7 — low review count relative to review-eligible visits.
		// If ≥ 20 direction-request signals but < 3 total reviews, ask
		// customers for reviews after their visit.
		$reviews = (int) ( $gbp['reviewCount'] ?? 0 );
		$dir_req = (int) ( $gbp['directionsRequested'] ?? 0 );
		if ( $dir_req >= 20 && $reviews < 3 ) {
			$gaps[] = array(
				'id'       => 'gap-gbp-reviews',
				'problem'  => 'Low review velocity relative to visitor demand',
				'evidence' => sprintf( '%d direction requests but only %d Google reviews.', $dir_req, $reviews ),
				'impact'   => 'Every unlisted 5-star review reduces future click-through.',
				'fix'      => 'Add a post-visit review-ask (kiosk QR + email) and reply to existing reviews.',
				'priority' => 'medium',
				'owner'    => 'Marketing',
			);
		}

		return $gaps;
	}
}
