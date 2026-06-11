<?php
/**
 * Bulk import page — CSV upload → mapping preview → kicks off Import_Service.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Import\Import_File_Handler;
use Messageistic\Import\Import_Job_Repository;
use Messageistic\Import\Import_Service;

defined( 'ABSPATH' ) || exit;

final class Import_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $action       = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $notice       = '';
        $preview      = null;
        $upload       = null;
        $current_kind = sanitize_key( (string) ( $_REQUEST['kind'] ?? 'contacts' ) );

        if ( 'sample' === $action ) {
            self::stream_sample( sanitize_key( (string) ( $_GET['kind'] ?? 'contacts' ) ) );
            return;
        }

        if ( 'upload' === $action ) {
            check_admin_referer( 'messageistic_import_upload' );
            $current_kind   = sanitize_key( (string) ( $_POST['kind'] ?? 'contacts' ) );
            $upload_result  = Import_File_Handler::accept( $_FILES['csv'] ?? [] );
            if ( is_wp_error( $upload_result ) ) {
                $notice = $upload_result->get_error_message();
            } else {
                $upload  = $upload_result;
                $preview = Import_Service::preview( $current_kind, $upload['path'] );
                if ( is_wp_error( $preview ) ) {
                    $notice  = $preview->get_error_message();
                    $preview = null;
                }
            }
        } elseif ( 'start' === $action ) {
            check_admin_referer( 'messageistic_import_start' );
            $kind      = sanitize_key( (string) ( $_POST['kind'] ?? 'contacts' ) );
            $file_path = (string) ( $_POST['file_path'] ?? '' );
            $file_name = sanitize_file_name( (string) ( $_POST['file_name'] ?? 'import.csv' ) );
            $mapping   = self::sanitize_mapping( $_POST['mapping'] ?? [] );
            $result    = Import_Service::start( $kind, $file_path, $file_name, $mapping );
            $notice    = is_wp_error( $result )
                ? sprintf( /* translators: %s: error */ __( 'Import failed to start: %s', 'messageistic' ), $result->get_error_message() )
                : __( 'Import started.', 'messageistic' );
        } elseif ( 'cancel' === $action ) {
            check_admin_referer( 'messageistic_import_cancel' );
            Import_Service::cancel( sanitize_key( (string) ( $_REQUEST['kind'] ?? '' ) ) );
            $notice = __( 'Import cancelled.', 'messageistic' );
        }

        $jobs = Import_Job_Repository::all();
        include MESSAGEISTIC_DIR . 'templates/admin/import.php';
    }

    /**
     * @param array<string,string> $raw
     * @return array<string,string>
     */
    private static function sanitize_mapping( array $raw ): array {
        $out = [];
        foreach ( $raw as $source => $target ) {
            $source = sanitize_text_field( (string) $source );
            $target = sanitize_key( (string) $target );
            if ( '' !== $source ) {
                $out[ $source ] = $target;
            }
        }
        return $out;
    }

    private static function stream_sample( string $kind ): void {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="messageistic-' . $kind . '-sample.csv"' );

        $out = fopen( 'php://output', 'w' );
        switch ( $kind ) {
            case 'templates':
                fputcsv( $out, [ 'name', 'category', 'body', 'is_active' ] );
                fputcsv( $out, [ 'Booking Confirmation', 'booking', 'Hi {first_name}, your booking is confirmed for {booking_date} at {booking_time}.', '1' ] );
                fputcsv( $out, [ 'Review Request', 'review', 'Thanks for visiting, {first_name}! Please leave us a review: {review_link}', '1' ] );
                break;
            case 'campaigns':
                fputcsv( $out, [ 'name', 'type', 'body', 'audience_kind', 'audience_tag', 'provider_mode', 'schedule_at' ] );
                fputcsv( $out, [ 'Memorial Day Promo', 'broadcast', 'Memorial Day sale — 15% off all in-stock ammo. Reply STOP to opt out.', 'all', '', 'queue', '' ] );
                fputcsv( $out, [ 'VIP Member Promo', 'broadcast', 'Hi {first_name}, members-only preview tomorrow at 10am.', 'tag', 'vip', 'queue', '' ] );
                break;
            case 'contacts':
            default:
                fputcsv( $out, [ 'first_name', 'last_name', 'email', 'phone', 'tags', 'customer_type', 'source', 'sms_consent', 'opt_out' ] );
                fputcsv( $out, [ 'Jane', 'Doe', 'jane@example.com', '+14805551234', 'vip,member', 'member', 'csv', '1', '0' ] );
                fputcsv( $out, [ 'John', 'Smith', 'john@example.com', '4805559876', 'lead', 'lead', 'csv', '1', '0' ] );
        }
        fclose( $out );
        exit;
    }
}
