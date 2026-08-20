<?php
/**
 * Membership offer stacking.
 *
 * A friend arriving through a referral could otherwise combine three things
 * at once: the referral reward, the "join and save" banner offer, and a
 * member discount. Best single offer wins — never combine — enforced
 * server-side at checkout, plus a floor a membership can never resolve
 * below.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Database\Events_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stacking {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		// Runs last so it sees every offer any other plugin has added.
		add_filter( 'memberistic_checkout_pricing', array( self::class, 'enforce' ), 99, 2 );
		add_filter( 'g2ar_resolve_membership_offers', array( self::class, 'resolve' ), 10, 2 );
	}

	/**
	 * Collapse a set of candidate offers to the single best one.
	 *
	 * @param array $offers    Each: array{ id:string, label:string, amount:float }.
	 * @param float $list_price Membership list price.
	 * @return array{offer:array|null,price:float,rejected:array}
	 */
	public static function resolve( array $offers, $list_price ) {
		$list_price = max( 0, round( (float) $list_price, 2 ) );
		$floor      = max( 0, Settings::get_float( 'membership_price_floor' ) );

		$best     = null;
		$rejected = array();

		foreach ( $offers as $offer ) {
			$amount = max( 0, round( (float) ( $offer['amount'] ?? 0 ), 2 ) );

			if ( $amount <= 0 ) {
				continue;
			}

			if ( null === $best || $amount > (float) $best['amount'] ) {
				if ( null !== $best ) {
					$rejected[] = $best;
				}
				$best           = $offer;
				$best['amount'] = $amount;
				continue;
			}

			$rejected[] = $offer;
		}

		if ( null === $best ) {
			return array(
				'offer'    => null,
				'price'    => $list_price,
				'rejected' => $rejected,
			);
		}

		$price = round( $list_price - (float) $best['amount'], 2 );

		// The floor is absolute: no combination of offers, and no single
		// oversized one, may take a membership below it.
		if ( $price < $floor ) {
			$price          = $floor;
			$best['amount'] = round( $list_price - $floor, 2 );
			$best['capped'] = true;
		}

		return array(
			'offer'    => $best,
			'price'    => max( 0, $price ),
			'rejected' => $rejected,
		);
	}

	/**
	 * Enforce the rule on a Memberistic checkout pricing array.
	 *
	 * @param array $pricing Pricing array with list_price and offers.
	 * @param array $context Checkout context.
	 * @return array
	 */
	public static function enforce( $pricing, $context = array() ) {
		$pricing = (array) $pricing;

		if ( 'best_single' !== (string) Settings::get( 'stacking_mode' ) ) {
			return $pricing;
		}

		$offers = isset( $pricing['offers'] ) && is_array( $pricing['offers'] ) ? $pricing['offers'] : array();

		if ( count( $offers ) < 1 ) {
			return $pricing;
		}

		$list_price = (float) ( $pricing['list_price'] ?? $pricing['subtotal'] ?? 0 );
		$resolved   = self::resolve( $offers, $list_price );

		$pricing['offers']          = $resolved['offer'] ? array( $resolved['offer'] ) : array();
		$pricing['rejected_offers'] = $resolved['rejected'];
		$pricing['total']           = $resolved['price'];
		$pricing['discount_amount'] = $resolved['offer'] ? (float) $resolved['offer']['amount'] : 0.0;

		if ( $resolved['rejected'] ) {
			Events_Repository::log(
				'offers_collapsed',
				array(
					'object_type' => 'checkout',
					'object_id'   => (int) ( $context['membership_id'] ?? 0 ),
					'payload'     => array(
						'kept'     => $resolved['offer']['id'] ?? '',
						'rejected' => wp_list_pluck( $resolved['rejected'], 'id' ),
						'price'    => $resolved['price'],
					),
				)
			);
		}

		return $pricing;
	}
}
