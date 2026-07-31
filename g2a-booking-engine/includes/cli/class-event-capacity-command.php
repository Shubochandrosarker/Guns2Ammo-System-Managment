<?php
/**
 * WP-CLI event capacity diagnostics.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_CLI_Event_Capacity_Command {

	public static function register() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		WP_CLI::add_command( 'g2ab event-capacity', array( __CLASS__, 'dispatch' ) );
	}

	/**
	 * Dispatch subcommands.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : audit or repair.
	 *
	 * --occurrence=<id>
	 * : Event occurrence ID.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public static function dispatch( $args, $assoc_args ) {
		$subcommand = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$occurrence = isset( $assoc_args['occurrence'] ) ? absint( $assoc_args['occurrence'] ) : 0;
		if ( ! $occurrence ) {
			WP_CLI::error( 'Missing --occurrence=<id>.' );
		}
		if ( ! class_exists( 'G2AB_Event_Capacity_Service' ) ) {
			WP_CLI::error( 'G2AB_Event_Capacity_Service is unavailable.' );
		}
		if ( 'repair' === $subcommand ) {
			$expired = G2AB_Event_Capacity_Service::expire_stale_holds( $occurrence );
			WP_CLI::success( sprintf( 'Expired stale online holds: %d', (int) $expired ) );
			self::print_diagnostics( $occurrence );
			return;
		}
		if ( 'audit' === $subcommand ) {
			self::print_diagnostics( $occurrence );
			return;
		}
		WP_CLI::error( 'Use audit or repair.' );
	}

	private static function print_diagnostics( $occurrence_id ) {
		$diag = G2AB_Event_Capacity_Service::diagnostics( $occurrence_id );
		if ( is_wp_error( $diag ) ) {
			WP_CLI::error( $diag->get_error_message() );
		}
		WP_CLI::log( sprintf( 'Occurrence: %d', (int) $diag['occurrence_id'] ) );
		WP_CLI::log( sprintf( 'Capacity: %d (%s)', (int) $diag['capacity'], (string) $diag['capacity_source'] ) );
		WP_CLI::log( sprintf( 'Reserved: %d', (int) $diag['reserved'] ) );
		WP_CLI::log( sprintf( 'Seats left: %d', (int) $diag['seats_left'] ) );
		WP_CLI::log( sprintf( 'Calculated at: %s', (string) $diag['calculated_at'] ) );

		$rows = array();
		foreach ( $diag['bookings'] as $booking ) {
			$rows[] = array(
				'id'              => (int) $booking['id'],
				'status'          => (string) $booking['status'],
				'party_size'      => (int) $booking['party_size'],
				'payment_mode'    => (string) $booking['payment_mode'],
				'hold_expires_at' => (string) $booking['hold_expires_at'],
				'consumes'        => ! empty( $booking['consumes'] ) ? 'yes' : 'no',
				'reason'          => (string) $booking['reason'],
			);
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'status', 'party_size', 'payment_mode', 'hold_expires_at', 'consumes', 'reason' ) );
		} else {
			WP_CLI::log( 'No bookings found for this occurrence.' );
		}
	}
}
