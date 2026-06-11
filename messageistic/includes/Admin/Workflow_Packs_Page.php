<?php
/** Workflow pack installer UI. @package Messageistic\Admin */
namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Locations\Location_Repository;
use Messageistic\WorkflowPacks\Firearm_Workflow_Pack;

defined( 'ABSPATH' ) || exit;

final class Workflow_Packs_Page {
    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
            wp_die( esc_html__( 'Permission denied.', 'messageistic' ) );
        }
        $notice = '';
        if ( 'install_firearm' === sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) ) {
            check_admin_referer( 'messageistic_install_pack' );
            $created = Firearm_Workflow_Pack::install( (int) ( $_POST['location_id'] ?? 0 ) );
            $notice = sprintf( __( 'Created %d draft workflows and pending-review templates.', 'messageistic' ), count( $created ) );
        }
        $locations = Location_Repository::all( true );
        include MESSAGEISTIC_DIR . 'templates/admin/workflow-packs.php';
    }
}
