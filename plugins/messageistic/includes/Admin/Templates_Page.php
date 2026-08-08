<?php
/**
 * Templates list/edit.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Database\Template_Repository;
use Messageistic\Locations\Location_Repository;

defined( 'ABSPATH' ) || exit;

final class Templates_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE_CAMPAIGNS ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $notice = '';

        if ( 'save' === $action ) {
            check_admin_referer( 'messageistic_save_template' );
            $id   = (int) ( $_POST['id'] ?? 0 );
            $data = [
                'location_id' => (int) ( $_POST['location_id'] ?? 0 ),
                'name'      => wp_unslash( $_POST['name'] ?? '' ),
                'classification' => sanitize_key( wp_unslash( $_POST['classification'] ?? 'transactional' ) ),
                'category'  => wp_unslash( $_POST['category'] ?? 'general' ),
                'body'      => wp_unslash( $_POST['body'] ?? '' ),
                'is_active' => ! empty( $_POST['is_active'] ),
            ];
            if ( $id ) {
                Template_Repository::update( $id, $data );
                $notice = __( 'Template updated.', 'messageistic' );
            } else {
                $id     = Template_Repository::create( $data );
                $notice = __( 'Template created.', 'messageistic' );
                wp_safe_redirect( admin_url( 'admin.php?page=messageistic-templates&view=edit&id=' . $id ) );
                exit;
            }
        } elseif ( in_array( $action, [ 'approve', 'reject' ], true ) ) {
            if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
                wp_die( esc_html__( 'You do not have permission to review templates.', 'messageistic' ) );
            }
            check_admin_referer( 'messageistic_review_template' );
            $result = Template_Repository::review( (int) ( $_REQUEST['id'] ?? 0 ), 'approve' === $action ? 'approved' : 'rejected', wp_unslash( $_POST['review_note'] ?? '' ) );
            $notice = is_wp_error( $result ) ? $result->get_error_message() : ( 'approve' === $action ? __( 'Template approved.', 'messageistic' ) : __( 'Template rejected.', 'messageistic' ) );
        } elseif ( 'delete' === $action ) {
            check_admin_referer( 'messageistic_delete_template' );
            Template_Repository::delete( (int) ( $_REQUEST['id'] ?? 0 ) );
            $notice = __( 'Template deleted.', 'messageistic' );
        }

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
        if ( 'new' === $view || 'edit' === $view ) {
            $template = isset( $_GET['id'] ) ? Template_Repository::find( (int) $_GET['id'] ) : [];
            $locations = Location_Repository::all( true );
            include MESSAGEISTIC_DIR . 'templates/admin/template-edit.php';
            return;
        }

        $rows = Template_Repository::all();
        include MESSAGEISTIC_DIR . 'templates/admin/templates.php';
    }
}
