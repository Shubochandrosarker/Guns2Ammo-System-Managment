<?php
/**
 * WP-CLI Stripe incident recovery commands.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_CLI_Stripe_Recovery_Command {
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'g2ab stripe-audit', array( __CLASS__, 'audit' ) );
		WP_CLI::add_command( 'g2ab stripe-reconcile', array( __CLASS__, 'reconcile' ) );
	}

	public static function audit( $args, $assoc_args ) {
		global $wpdb;
		$since = isset( $assoc_args['since'] ) ? sanitize_text_field( (string) $assoc_args['since'] ) : '2026-07-09';
		$b     = $wpdb->prefix . 'g2ab_bookings';
		$p     = $wpdb->prefix . 'g2ab_payments';
		$w     = $wpdb->prefix . 'g2ab_webhook_events';

		$paid_pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT b.id)
			 FROM {$b} b
			 INNER JOIN {$p} p ON p.booking_id = b.id
			 WHERE b.status IN ('pending','reserved','expired')
			 AND p.gateway = 'stripe'
			 AND p.transaction_id LIKE 'cs_%'
			 AND b.created_at >= %s",
			$since
		) );
		$duplicates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT gateway, transaction_id FROM {$p} WHERE gateway = 'stripe' AND transaction_id <> '' GROUP BY gateway, transaction_id HAVING COUNT(*) > 1) d" );
		$unprocessed = 0;
		if ( function_exists( 'g2ab_table_exists' ) && g2ab_table_exists( $w ) ) {
			$unprocessed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$w} WHERE gateway = 'stripe' AND status <> 'processed'" );
		}

		WP_CLI::line( 'G2A Booking Stripe audit since: ' . $since );
		WP_CLI::line( 'pending_or_expired_with_checkout_session=' . $paid_pending );
		WP_CLI::line( 'duplicate_stripe_payment_refs=' . $duplicates );
		WP_CLI::line( 'unprocessed_stripe_webhook_events=' . $unprocessed );
		WP_CLI::line( 'last_verified=' . (string) get_option( 'g2ab_stripe_webhook_last_verified_at', '' ) );
		WP_CLI::line( 'last_processed=' . (string) get_option( 'g2ab_stripe_webhook_last_processed_at', '' ) );
		WP_CLI::line( 'last_failed=' . (string) get_option( 'g2ab_stripe_webhook_last_failed_at', '' ) );
	}

	public static function reconcile( $args, $assoc_args ) {
		global $wpdb;
		$since = isset( $assoc_args['since'] ) ? sanitize_text_field( (string) $assoc_args['since'] ) : '2026-07-09';
		$apply = ! empty( $assoc_args['apply'] );
		$limit = isset( $assoc_args['limit'] ) ? max( 1, min( 100, (int) $assoc_args['limit'] ) ) : 25;
		$b     = $wpdb->prefix . 'g2ab_bookings';
		$p     = $wpdb->prefix . 'g2ab_payments';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.uuid, p.transaction_id
			 FROM {$b} b
			 INNER JOIN {$p} p ON p.booking_id = b.id
			 WHERE b.status IN ('pending','reserved','expired')
			 AND p.gateway = 'stripe'
			 AND p.transaction_id LIKE 'cs_%'
			 AND b.created_at >= %s
			 ORDER BY b.id ASC
			 LIMIT %d",
			$since,
			$limit
		), ARRAY_A ) ?: array();

		WP_CLI::line( $apply ? 'APPLY mode' : 'DRY-RUN mode' );
		foreach ( $rows as $row ) {
			$session_id = (string) $row['transaction_id'];
			if ( ! $apply ) {
				WP_CLI::line( sprintf( 'booking=%d uuid=%s checkout_session=%s action=would_check_stripe', (int) $row['id'], sanitize_text_field( (string) $row['uuid'] ), self::mask( $session_id ) ) );
				continue;
			}
			$gateway = class_exists( 'G2AB_Gateway_Manager' ) ? G2AB_Gateway_Manager::instance()->get( 'stripe' ) : null;
			if ( ! $gateway || ! method_exists( $gateway, 'confirm_payment' ) ) {
				WP_CLI::warning( 'Stripe gateway not available.' );
				return;
			}
			$result = $gateway->confirm_payment( $session_id, array( 'source' => 'wp_cli_reconcile' ) );
			$status = is_wp_error( $result ) ? 'error:' . $result->get_error_code() : ( true === $result ? 'confirmed_paid' : ( is_array( $result ) ? sanitize_key( (string) ( $result['status'] ?? 'handled' ) ) : 'not_paid' ) );
			WP_CLI::line( sprintf( 'booking=%d checkout_session=%s status=%s', (int) $row['id'], self::mask( $session_id ), $status ) );
		}
	}

	private static function mask( $id ) {
		$id = sanitize_text_field( (string) $id );
		return strlen( $id ) > 10 ? substr( $id, 0, 6 ) . '...' . substr( $id, -4 ) : $id;
	}
}
