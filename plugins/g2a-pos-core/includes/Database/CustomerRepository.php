<?php

namespace G2A\POS\Database;

final class CustomerRepository {

	public function search( string $term, int $limit = 20 ): array {
		$users = get_users(
			array(
				'number'         => max( 1, min( 50, $limit ) ),
				'search'         => '*' . esc_attr( $term ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			)
		);

		$items = array();
		foreach ( $users as $user ) {
			$stats   = $this->order_stats( (int) $user->ID );
			$items[] = array(
				'id'                => (int) $user->ID,
				'name'              => (string) $user->display_name,
				'email'             => current_user_can( 'g2a_pos_view_customer_contact' ) ? (string) $user->user_email : self::maskEmail( (string) $user->user_email ),
				'phone'             => current_user_can( 'g2a_pos_view_customer_contact' ) ? (string) get_user_meta( $user->ID, 'billing_phone', true ) : self::maskPhone( (string) get_user_meta( $user->ID, 'billing_phone', true ) ),
				'pos_order_count'   => (int) $stats['count'],
				'last_pos_order_at' => $stats['last_created_at'],
			);
		}

		return $items;
	}

	private function order_stats( int $customer_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'g2a_pos_orders';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS c, MAX(created_at) AS last_created_at FROM {$table} WHERE customer_id = %d",
				$customer_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array(
				'count'           => 0,
				'last_created_at' => null,
			);
		}
		return array(
			'count'           => (int) ( $row['c'] ?? 0 ),
			'last_created_at' => $row['last_created_at'] ?? null,
		);
	}

	private static function maskEmail( string $email ): string {
		$parts = explode( '@', $email, 2 );
		if ( count( $parts ) !== 2 ) {
			return '';
		}
		return substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
	}

	private static function maskPhone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?: '';
		return $digits === '' ? '' : '***-***-' . substr( $digits, -4 );
	}
}
