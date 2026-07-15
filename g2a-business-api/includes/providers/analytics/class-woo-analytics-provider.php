<?php
/**
 * WooCommerce order aggregates via the Woo APIs (HPOS-safe).
 *
 * REVENUE RECOGNITION — order statuses included: `completed` +
 * `processing` (paid, awaiting fulfilment) — the same pair Woo's own
 * revenue reports treat as paid. Everything else (pending, on-hold,
 * cancelled, refunded, failed, draft) is excluded. Refunds are measured
 * per included order via WC_Order::get_total_refunded(), so a partially
 * refunded processing/completed order contributes its refund to
 * refundCents while keeping its full total in grossCents;
 * net = gross - refunds.
 *
 * Orders are fetched through wc_get_orders() (never raw SQL) with a
 * bounded date window + status filter, paged at PAGE_SIZE and hard-capped
 * at MAX_ORDERS rows; if the cap trips, `truncated: true` is set so the
 * dashboard can say "showing first N orders" instead of lying.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Analytics_Provider extends Analytics_Provider_Base {
	public const INCLUDED_STATUSES = array( 'wc-completed', 'wc-processing' );

	public const PAGE_SIZE  = 200;
	public const MAX_ORDERS = 5000;

	public function source_slug(): string {
		return 'woocommerce';
	}

	public function is_available(): bool {
		return class_exists( '\WooCommerce' ) && function_exists( 'wc_get_orders' );
	}

	protected function empty_summary(): array {
		return array(
			// Frontend contract keys (dashboard-app types/api.ts).
			'orders'           => 0,
			'revenueCents'     => 0,
			// Richer detail.
			'statusesIncluded' => array( 'completed', 'processing' ),
			'orderCount'       => 0,
			'revenue'          => array(
				'grossCents'  => 0,
				'refundCents' => 0,
				'netCents'    => 0,
			),
			'aovCents'         => 0,
			'truncated'        => false,
		);
	}

	protected function build_summary( Range $range ): array {
		$gross        = 0;
		$refunds      = 0;
		$order_count  = 0;
		$truncated    = false;
		$last_created = null;

		$page = 1;
		while ( true ) {
			$orders = wc_get_orders(
				array(
					'limit'        => self::PAGE_SIZE,
					'page'         => $page,
					'status'       => self::INCLUDED_STATUSES,
					'date_created' => $range->from . '...' . $range->to,
					'return'       => 'objects',
				)
			);
			if ( ! is_array( $orders ) || empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
					continue;
				}
				$order_count++;
				$gross   += Money::to_cents_from_string( (string) $order->get_total() );
				$refunds += Money::to_cents_from_string( (string) $order->get_total_refunded() );

				$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
				if ( $created && method_exists( $created, 'getTimestamp' ) ) {
					$ts = (int) $created->getTimestamp();
					if ( null === $last_created || $ts > $last_created ) {
						$last_created = $ts;
					}
				}
			}

			if ( $order_count >= self::MAX_ORDERS ) {
				$truncated = true;
				break;
			}
			if ( count( $orders ) < self::PAGE_SIZE ) {
				break;
			}
			$page++;
		}

		return array(
			'orders'           => $order_count,
			'revenueCents'     => $gross - $refunds,
			'statusesIncluded' => array( 'completed', 'processing' ),
			'orderCount'       => $order_count,
			'revenue'          => array(
				'grossCents'  => $gross,
				'refundCents' => $refunds,
				'netCents'    => $gross - $refunds,
			),
			'aovCents'         => self::aov_cents( $gross, $order_count ),
			'truncated'        => $truncated,
			'lastUpdatedAt'    => null !== $last_created ? gmdate( 'Y-m-d\TH:i:s\Z', $last_created ) : null,
		);
	}

	/**
	 * @internal Exposed (pure) for tests.
	 */
	public static function aov_cents( int $gross_cents, int $order_count ): int {
		return $order_count > 0 ? (int) round( $gross_cents / $order_count ) : 0;
	}
}
