<?php
/**
 * Abandoned inquiry handler.
 *
 * Reads the WPistic Contact Form submissions table for entries older than
 * 48 hours that don't have a `replied_at` timestamp. For each, drafts a
 * "please follow up" email to internal staff (owner + shop_manager) with
 * the original inquiry inline.
 *
 * Staff opt-outs are NOT honoured — this is transactional operations
 * routing, not marketing.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Abandoned_Inquiry_Handler extends Handler_Base {
	public static function slug(): string {
		return 'abandoned-inquiry-alert';
	}

	public static function run(): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'prefix' ) ) {
			return 'Skipped — WordPress DB layer unavailable.';
		}
		$table = $wpdb->prefix . 'wpistic_contact_submissions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return 'Skipped — WPistic Contact Form table not present.';
		}

		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-48 hours' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, name, subject, message, created_at FROM {$table} WHERE replied_at IS NULL AND created_at <= %s ORDER BY created_at ASC LIMIT 25",
				$threshold
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			return 'No abandoned inquiries.';
		}

		$owner_email = self::owner_email();
		if ( '' === $owner_email ) {
			return sprintf( 'Found %d abandoned inquiries but no owner email is configured.', count( $rows ) );
		}

		( new Email_Draft_Store() )->enqueue(
			sprintf( 'Abandoned inquiry alert (%d unanswered)', count( $rows ) ),
			$owner_email,
			sprintf( '%d inquiry%s awaiting reply', count( $rows ), 1 === count( $rows ) ? '' : 's' ),
			self::compose_body( $rows ),
			\WordPressistic\G2ABA\Ops\Opt_Out_Store::CATEGORY_INTERNAL
		);

		return sprintf( 'Drafted staff alert for %d abandoned inquir%s.', count( $rows ), 1 === count( $rows ) ? 'y' : 'ies' );
	}

	/**
	 * @internal Exposed for tests.
	 * @param array<int, array{email?:string,name?:string,subject?:string,message?:string,created_at?:string}> $rows
	 */
	public static function compose_body( array $rows ): string {
		$lines = array( sprintf(
			'The following %d %s been sitting unanswered for 48h+:',
			count( $rows ),
			1 === count( $rows ) ? 'inquiry has' : 'inquiries have'
		), '' );
		foreach ( $rows as $r ) {
			$lines[] = sprintf(
				'- %s <%s> (%s): "%s"',
				(string) ( $r['name'] ?? '(no name)' ),
				(string) ( $r['email'] ?? '(no email)' ),
				substr( (string) ( $r['created_at'] ?? '' ), 0, 16 ),
				self::excerpt( (string) ( $r['subject'] ?? '' ), (string) ( $r['message'] ?? '' ) )
			);
		}
		$lines[] = '';
		$lines[] = 'Reply in WP-admin: /wp-admin/admin.php?page=wpistic-contact';
		return implode( "\n", $lines );
	}

	private static function excerpt( string $subject, string $message ): string {
		if ( '' !== $subject ) {
			return $subject;
		}
		$msg = trim( (string) $message );
		if ( '' === $msg ) {
			return '(empty message)';
		}
		return strlen( $msg ) > 120 ? substr( $msg, 0, 120 ) . '…' : $msg;
	}

	private static function owner_email(): string {
		return (string) get_option( 'admin_email', '' );
	}
}
