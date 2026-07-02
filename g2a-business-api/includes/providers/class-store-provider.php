<?php
/**
 * WooCommerce analytics for the dashboard.
 *
 * We use `wc_get_orders()` and the built-in reports rather than raw SQL so
 * the plugin stays compatible with future Woo versions. When Woo isn't
 * activated the provider returns a zeroed skeleton — never throws.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Store_Provider {
	public function analytics( Range $range ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->empty_payload( $range );
		}

		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => array( 'wc-completed', 'wc-processing' ),
				'date_created' => $range->from . '...' . $range->to,
				'return'       => 'objects',
			)
		);

		if ( ! is_array( $orders ) ) {
			$orders = array();
		}

		$revenue        = 0;
		$order_count    = 0;
		$refund_amount  = 0;
		$refund_count   = 0;
		$category_totals = array();
		$brand_totals    = array();
		$product_totals  = array();
		$customer_order_counts = array();

		foreach ( $orders as $order ) {
			if ( ! ( $order instanceof \WC_Order ) ) {
				continue;
			}
			$order_count++;
			$revenue       += Money::to_cents_from_string( (string) $order->get_total() );
			$refund_amount += Money::to_cents_from_string( (string) $order->get_total_refunded() );
			if ( 0 < $order->get_total_refunded() ) {
				$refund_count++;
			}

			$customer_id = (int) $order->get_customer_id();
			if ( $customer_id > 0 ) {
				$customer_order_counts[ $customer_id ] = ( $customer_order_counts[ $customer_id ] ?? 0 ) + 1;
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
					continue;
				}
				$product_id = (int) $item->get_product_id();
				$line_total = Money::to_cents_from_string( (string) $item->get_total() );
				$qty        = (int) $item->get_quantity();

				if ( ! isset( $product_totals[ $product_id ] ) ) {
					$product = $item->get_product();
					$product_totals[ $product_id ] = array(
						'id'      => $product_id,
						'name'    => $product ? $product->get_name() : '#' . $product_id,
						'sku'     => $product ? $product->get_sku() : '',
						'revenue' => 0,
						'units'   => 0,
					);
				}
				$product_totals[ $product_id ]['revenue'] += $line_total;
				$product_totals[ $product_id ]['units']   += $qty;

				$product = $item->get_product();
				if ( $product ) {
					$categories = wc_get_product_term_ids( $product_id, 'product_cat' );
					foreach ( $categories as $tid ) {
						$term = get_term( $tid, 'product_cat' );
						if ( $term && ! is_wp_error( $term ) ) {
							$category_totals[ $term->name ] = ( $category_totals[ $term->name ] ?? 0 ) + $line_total;
						}
					}

					if ( taxonomy_exists( 'product_brand' ) ) {
						$brand_ids = wc_get_product_term_ids( $product_id, 'product_brand' );
						foreach ( $brand_ids as $bid ) {
							$term = get_term( $bid, 'product_brand' );
							if ( $term && ! is_wp_error( $term ) ) {
								$brand_totals[ $term->name ] = ( $brand_totals[ $term->name ] ?? 0 ) + $line_total;
							}
						}
					}
				}
			}
		}

		usort(
			$product_totals,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		$top_products = array_slice( array_values( $product_totals ), 0, 10 );

		$repeat_customers = 0;
		foreach ( $customer_order_counts as $count ) {
			if ( $count > 1 ) {
				$repeat_customers++;
			}
		}
		$repeat_pct = count( $customer_order_counts ) > 0
			? round( ( $repeat_customers / count( $customer_order_counts ) ) * 100, 1 )
			: 0.0;

		return array(
			'range'             => $range->to_array(),
			'orders'            => $order_count,
			'revenue'           => $revenue,
			'averageOrderValue' => $order_count > 0 ? (int) round( $revenue / $order_count ) : 0,
			'repeatCustomerPct' => $repeat_pct,
			'refundCount'       => $refund_count,
			'refundAmount'      => $refund_amount,
			'topProducts'       => $top_products,
			'categoryRevenue'   => $this->to_series( $category_totals, 'category' ),
			'brandRevenue'      => $this->to_series( $brand_totals, 'brand' ),
			'slowMovers'        => $this->slow_movers(),
		);
	}

	private function empty_payload( Range $range ): array {
		return array(
			'range'             => $range->to_array(),
			'orders'            => 0,
			'revenue'           => 0,
			'averageOrderValue' => 0,
			'repeatCustomerPct' => 0.0,
			'refundCount'       => 0,
			'refundAmount'      => 0,
			'topProducts'       => array(),
			'categoryRevenue'   => array(),
			'brandRevenue'      => array(),
			'slowMovers'        => array(),
		);
	}

	private function to_series( array $totals, string $key ): array {
		$out = array();
		foreach ( $totals as $label => $revenue ) {
			$out[] = array(
				$key      => $label,
				'revenue' => (int) $revenue,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);
		return array_slice( $out, 0, 8 );
	}

	private function slow_movers(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$products = wc_get_products(
			array(
				'limit'   => 25,
				'orderby' => 'date',
				'order'   => 'ASC',
				'status'  => 'publish',
			)
		);
		if ( ! is_array( $products ) ) {
			return array();
		}
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- comparing against post_modified below.
		$out = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$modified = get_post_field( 'post_modified_gmt', $product->get_id() );
			$ts       = $modified ? strtotime( $modified ) : $now;
			$days     = (int) floor( ( $now - $ts ) / DAY_IN_SECONDS );
			if ( $days < 30 ) {
				continue;
			}
			$out[] = array(
				'id'              => $product->get_id(),
				'name'            => $product->get_name(),
				'daysWithoutSale' => $days,
			);
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}
}
