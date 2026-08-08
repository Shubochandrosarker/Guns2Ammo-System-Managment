<?php
/**
 * Conversations inbox.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Core\Plugin;
use Messageistic\Database\Conversation_Repository;
use Messageistic\Database\Conversation_Note_Repository;
use Messageistic\Locations\Location_Repository;
use Messageistic\Database\Message_Repository;
use Messageistic\Messaging\SMS_Service;

defined( 'ABSPATH' ) || exit;

final class Conversations_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::VIEW ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $notice = '';
        $action = sanitize_key( wp_unslash( $_POST['action'] ?? '' ) );
        if ( 'reply' === $action && current_user_can( Permissions::SEND_SMS ) ) {
            $notice = self::handle_reply();
        } elseif ( 'workflow' === $action && current_user_can( Permissions::MANAGE_INBOX ) ) {
            check_admin_referer( 'messageistic_inbox_workflow' );
            $id = (int) ( $_POST['conversation_id'] ?? 0 );
            Conversation_Repository::update_workflow( $id, [ 'status' => $_POST['status'] ?? 'open', 'assigned_user_id' => (int) ( $_POST['assigned_user_id'] ?? 0 ), 'priority' => $_POST['priority'] ?? 'normal', 'location_id' => (int) ( $_POST['location_id'] ?? 0 ) ] );
            $notice = __( 'Conversation workflow updated.', 'messageistic' );
        } elseif ( 'note' === $action && current_user_can( Permissions::MANAGE_INBOX ) ) {
            check_admin_referer( 'messageistic_inbox_note' );
            Conversation_Note_Repository::add( (int) ( $_POST['conversation_id'] ?? 0 ), wp_unslash( $_POST['note'] ?? '' ) );
            $notice = __( 'Internal note added.', 'messageistic' );
        }

        $filter_status   = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
        $filter_assignee = isset( $_GET['mine'] ) ? get_current_user_id() : 0;
        $filter_location = (int) ( $_GET['location_id'] ?? 0 );
        $threads        = Conversation_Repository::inbox( 100, $filter_status, $filter_assignee, $filter_location );
        $active_id      = isset( $_GET['conversation'] ) ? (int) $_GET['conversation'] : ( $threads[0]['id'] ?? 0 );
        $active_thread  = $active_id ? Conversation_Repository::find( $active_id ) : null;
        $messages       = $active_thread ? Message_Repository::for_contact( (int) $active_thread['contact_id'], 100 ) : [];
        $messages       = array_reverse( $messages );
        $notes          = $active_id ? Conversation_Note_Repository::notes( $active_id ) : [];
        $events         = $active_id ? Conversation_Note_Repository::events( $active_id ) : [];
        $staff          = get_users( [ 'capability' => Permissions::VIEW ] );
        $locations      = Location_Repository::all( true );
        $providers      = Plugin::instance()->providers();
        $can_inbound    = (bool) ( $providers->get_active()->get_capabilities()['receive_replies'] ?? false );

        if ( $active_id ) {
            Conversation_Repository::mark_read( $active_id );
        }

        include MESSAGEISTIC_DIR . 'templates/admin/conversations.php';
    }

    private static function handle_reply(): string {
        check_admin_referer( 'messageistic_reply' );
        $contact_id = (int) ( $_POST['contact_id'] ?? 0 );
        $body       = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
        $service    = new SMS_Service();
        $result     = $service->send( [ 'contact_id' => $contact_id, 'body' => $body, 'classification' => 'customer_care', 'idempotency_key' => 'reply:' . $contact_id . ':' . wp_generate_uuid4() ] );
        if ( is_wp_error( $result ) ) {
            return sprintf( /* translators: %s: error message */ __( 'Reply failed: %s', 'messageistic' ), $result->get_error_message() );
        }
        return __( 'Reply sent.', 'messageistic' );
    }
}
