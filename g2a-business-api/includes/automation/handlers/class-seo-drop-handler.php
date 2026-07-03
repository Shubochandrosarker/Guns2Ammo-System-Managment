<?php
/**
 * SEO click-drop alert handler.
 *
 * Compares the last 7 days of GSC clicks against the previous 7 days. Any
 * top page whose clicks dropped 25% or more triggers a draft flagging the
 * regression. When GSC isn't configured, the handler no-ops with a
 * diagnostic result — it does NOT throw.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Drop_Handler extends Handler_Base {
	public static function slug(): string {
		return 'seo-click-drop-alert';
	}

	public static function run(): string {
		$now  = new Range( gmdate( 'Y-m-d', strtotime( '-6 days' ) ), gmdate( 'Y-m-d' ) );
		$prev = new Range( gmdate( 'Y-m-d', strtotime( '-13 days' ) ), gmdate( 'Y-m-d', strtotime( '-7 days' ) ) );

		$provider = new SEO_Provider();
		$now_data  = $provider->analytics( $now );
		$prev_data = $provider->analytics( $prev );

		$drops = self::compute_drops( (array) ( $now_data['topPages'] ?? array() ), (array) ( $prev_data['topPages'] ?? array() ), 25.0 );

		if ( empty( $drops ) ) {
			return 'No page clicks dropped ≥25% this run.';
		}

		( new Email_Draft_Store() )->enqueue(
			'SEO click-drop alert',
			'',
			sprintf( 'SEO click drop: %d page%s down ≥25%%', count( $drops ), 1 === count( $drops ) ? '' : 's' ),
			self::compose_body( $drops )
		);
		return sprintf( 'Drafted SEO alert for %d page%s.', count( $drops ), 1 === count( $drops ) ? '' : 's' );
	}

	/**
	 * @internal Exposed for tests.
	 * @param array<int, array{url?:string, clicks?:int}> $now_pages
	 * @param array<int, array{url?:string, clicks?:int}> $prev_pages
	 * @return array<int, array{url:string, before:int, after:int, deltaPct:float}>
	 */
	public static function compute_drops( array $now_pages, array $prev_pages, float $threshold_pct ): array {
		$by_url_prev = array();
		foreach ( $prev_pages as $p ) {
			$url = (string) ( $p['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$by_url_prev[ $url ] = (int) ( $p['clicks'] ?? 0 );
		}

		$out = array();
		foreach ( $now_pages as $p ) {
			$url  = (string) ( $p['url'] ?? '' );
			$now  = (int) ( $p['clicks'] ?? 0 );
			$prev = (int) ( $by_url_prev[ $url ] ?? 0 );
			if ( '' === $url || $prev <= 0 ) {
				continue;
			}
			$delta = ( ( $now - $prev ) / $prev ) * 100;
			if ( $delta > -1 * $threshold_pct ) {
				continue;
			}
			$out[] = array(
				'url'      => $url,
				'before'   => $prev,
				'after'    => $now,
				'deltaPct' => round( $delta, 1 ),
			);
		}
		return $out;
	}

	public static function compose_body( array $drops ): string {
		$lines = array( 'These pages lost 25% or more of their clicks this week:', '' );
		foreach ( $drops as $d ) {
			$lines[] = sprintf( '- %s: %d → %d (%s%.1f%%)', $d['url'], $d['before'], $d['after'], $d['deltaPct'] >= 0 ? '+' : '', $d['deltaPct'] );
		}
		$lines[] = '';
		$lines[] = 'Dig in: https://app.guns2ammo.com/seo-growth';
		return implode( "\n", $lines );
	}
}
