<?php
/** Multi-location administration. @package Messageistic\Admin */
namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Locations\Location_Repository;

defined( 'ABSPATH' ) || exit;

final class Locations_Page {
    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
            wp_die( esc_html__( 'Permission denied.', 'messageistic' ) );
        }
        $notice = '';
        if ( 'save' === sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) ) {
            check_admin_referer( 'messageistic_save_location' );
            $id = Location_Repository::save( wp_unslash( $_POST ) );
            $notice = $id ? __( 'Location saved.', 'messageistic' ) : __( 'Location could not be saved. Confirm that its code is unique.', 'messageistic' );
        }
        $edit_id = (int) ( $_GET['location_id'] ?? 0 );
        $location = $edit_id ? Location_Repository::find( $edit_id ) : [];
        $rows = Location_Repository::all();
        include MESSAGEISTIC_DIR . 'templates/admin/locations.php';
    }
}
