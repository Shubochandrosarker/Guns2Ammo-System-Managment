<?php

namespace G2A\POS\Integrations\Membership;

/**
 * Last-resort adapter: reads from user meta keys configured in the
 * g2a_pos_membership_meta_map option. Use this when the host theme/plugin
 * (e.g. WPistic) doesn't use one of the standard plugin schemas.
 *
 * Expected option shape:
 * [
 *   'active_key'      => 'g2a_member_active',     // truthy = active
 *   'plan_key'        => 'g2a_member_plan',       // string
 *   'expires_key'     => 'g2a_member_expires',    // ISO date
 *   'member_id_key'   => 'g2a_member_id',         // string
 * ]
 */
final class UserMetaProvider implements Provider {

	public function slug(): string {
		return 'user_meta'; }
	public function label(): string {
		return 'User meta (custom)'; }

	public function detect(): bool {
		$map = (array) get_option( 'g2a_pos_membership_meta_map', array() );
		return ! empty( $map['active_key'] );
	}

	public function membership( int $customer_id ): ?array {
		$map = (array) get_option( 'g2a_pos_membership_meta_map', array() );
		if ( empty( $map['active_key'] ) ) {
			return null;
		}
		$active = get_user_meta( $customer_id, (string) $map['active_key'], true );
		if ( ! $active || $active === '0' || $active === 'no' || $active === 'false' ) {
			return null;
		}
		return array(
			'active'     => true,
			'plan'       => (string) get_user_meta( $customer_id, (string) ( $map['plan_key'] ?? '' ), true ),
			'plan_slug'  => (string) get_user_meta( $customer_id, (string) ( $map['plan_key'] ?? '' ), true ),
			'expires_at' => (string) get_user_meta( $customer_id, (string) ( $map['expires_key'] ?? '' ), true ) ?: null,
			'member_id'  => (string) get_user_meta( $customer_id, (string) ( $map['member_id_key'] ?? '' ), true ),
			'discounts'  => array(),
			'source'     => $this->slug(),
		);
	}

	public function bookings( int $customer_id, int $days_ahead = 30 ): array {
		return array(); }
}
