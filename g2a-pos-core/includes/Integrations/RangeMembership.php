<?php

namespace G2A\POS\Integrations;

use G2A\POS\Integrations\Membership\MemberPressProvider;
use G2A\POS\Integrations\Membership\PmproProvider;
use G2A\POS\Integrations\Membership\Provider;
use G2A\POS\Integrations\Membership\UserMetaProvider;
use G2A\POS\Integrations\Membership\WooMembershipsProvider;

/**
 * Range membership / class-booking bridge.
 *
 * Resolution order:
 *   1. Filter override: apply_filters('g2a_pos_membership_lookup', null, $customer_id)
 *      — if the host theme/plugin (WPistic) returns a non-null array, use it.
 *      Same for 'g2a_pos_membership_bookings'.
 *   2. Adapter pinned via option g2a_pos_membership_provider (slug).
 *   3. Auto-detect: try WooCommerce Memberships → PMPro → MemberPress
 *      → custom user-meta map.
 *
 * Detection results are cached per-request.
 */
final class RangeMembership {

	/** @var Provider[]|null */
	private static ?array $providers = null;
	private static ?Provider $active = null;
	private static bool $resolved    = false;

	public static function providers(): array {
		if ( self::$providers === null ) {
			self::$providers = array(
				'wc_memberships' => new WooMembershipsProvider(),
				'pmpro'          => new PmproProvider(),
				'memberpress'    => new MemberPressProvider(),
				'user_meta'      => new UserMetaProvider(),
			);
		}
		return self::$providers;
	}

	public static function active_provider(): ?Provider {
		if ( self::$resolved ) {
			return self::$active;
		}
		self::$resolved = true;
		$pinned         = function_exists( 'get_option' ) ? (string) get_option( 'g2a_pos_membership_provider', '' ) : '';
		$providers      = self::providers();
		if ( $pinned && isset( $providers[ $pinned ] ) ) {
			self::$active = $providers[ $pinned ];
			return self::$active;
		}
		foreach ( $providers as $p ) {
			try {
				if ( $p->detect() ) {
					self::$active = $p;
					return $p;
				}
			} catch ( \Throwable ) {
				continue;
			}
		}
		self::$active = null;
		return null;
	}

	public static function status_for_customer( ?int $customer_id ): ?array {
		if ( ! $customer_id ) {
			return null;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$override = apply_filters( 'g2a_pos_membership_lookup', null, $customer_id );
			if ( is_array( $override ) ) {
				return self::normalize( $override, 'filter' );
			}
		}
		$provider = self::active_provider();
		if ( ! $provider ) {
			return null;
		}
		try {
			$payload = $provider->membership( $customer_id );
			return $payload ? self::normalize( $payload, $provider->slug() ) : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	public static function bookings_for_customer( ?int $customer_id, int $days_ahead = 30 ): array {
		if ( ! $customer_id ) {
			return array();
		}
		if ( function_exists( 'apply_filters' ) ) {
			$override = apply_filters( 'g2a_pos_membership_bookings', null, $customer_id, $days_ahead );
			if ( is_array( $override ) ) {
				return $override;
			}
		}
		$provider = self::active_provider();
		if ( ! $provider ) {
			return array();
		}
		try {
			return $provider->bookings( $customer_id, $days_ahead );
		} catch ( \Throwable ) {
			return array();
		}
	}

	private static function normalize( array $payload, string $source ): array {
		return array(
			'active'     => ! empty( $payload['active'] ),
			'plan'       => (string) ( $payload['plan'] ?? '' ),
			'plan_slug'  => (string) ( $payload['plan_slug'] ?? '' ),
			'expires_at' => $payload['expires_at'] ?? null,
			'member_id'  => $payload['member_id'] ?? null,
			'discounts'  => (array) ( $payload['discounts'] ?? array() ),
			'source'     => (string) ( $payload['source'] ?? $source ),
		);
	}
}
