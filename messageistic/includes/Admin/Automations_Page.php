<?php
/**
 * Automations list/edit.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Automations\Trigger_Registry;
use Messageistic\Core\Permissions;
use Messageistic\Database\Automation_Repository;
use Messageistic\Database\Automation_Step_Repository;
use Messageistic\Locations\Location_Repository;
use Messageistic\Database\Template_Repository;

defined( 'ABSPATH' ) || exit;

final class Automations_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE_CAMPAIGNS ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $notice = '';

        if ( 'save' === $action ) {
            check_admin_referer( 'messageistic_save_automation' );
            $id   = (int) ( $_POST['id'] ?? 0 );
            $conditions = [];
            foreach ( (array) ( $_POST['conditions'] ?? [] ) as $kind ) {
                $kind = sanitize_key( $kind );
                if ( $kind ) {
                    $conditions[] = [ 'kind' => $kind ];
                }
            }
            $pilot_mode = ! empty( ( (array) get_option( 'messageistic_general_settings', [] ) )['pilot_mode_enabled'] );
            $data = [
                'name'          => wp_unslash( $_POST['name'] ?? '' ),
                'trigger_key'   => wp_unslash( $_POST['trigger_key'] ?? '' ),
                'conditions'    => $conditions,
                'delay_seconds' => (int) ( $_POST['delay_seconds'] ?? 0 ),
                'template_id'   => (int) ( $_POST['template_id'] ?? 0 ),
                'actions'       => [ 'body' => wp_unslash( $_POST['body'] ?? '' ), 'classification' => sanitize_key( $_POST['classification'] ?? 'transactional' ) ],
                'is_active'     => ! empty( $_POST['is_active'] ),
            ];
            $steps = [];
            foreach ( (array) ( $_POST['steps'] ?? [] ) as $step ) {
                $type = sanitize_key( (string) ( $step['step_type'] ?? '' ) );
                if ( ! in_array( $type, [ 'send_sms', 'wait', 'condition', 'add_tag', 'assign_inbox' ], true ) ) { continue; }
                $steps[] = [ 'step_type' => $type, 'delay_seconds' => (int) ( $step['delay_seconds'] ?? 0 ), 'template_id' => (int) ( $step['template_id'] ?? 0 ), 'config' => [ 'condition' => sanitize_key( (string) ( $step['condition'] ?? '' ) ), 'value' => sanitize_text_field( (string) ( $step['value'] ?? '' ) ), 'tag' => sanitize_text_field( (string) ( $step['tag'] ?? $step['value'] ?? '' ) ), 'user_id' => (int) ( $step['user_id'] ?? 0 ), 'priority' => sanitize_key( (string) ( $step['priority'] ?? 'normal' ) ) ] ];
            }
            $data['location_id'] = (int) ( $_POST['location_id'] ?? 0 );
            $current = $id ? Automation_Repository::find( $id ) : null;
            $data['version'] = $current ? ( (int) ( $current['version'] ?? 1 ) + 1 ) : 1;

            if ( $data['is_active'] ) {
                foreach ( $steps as $step ) {
                    if ( 'send_sms' === $step['step_type'] && ! Template_Repository::is_approved( (int) $step['template_id'] ) ) {
                        $data['is_active'] = false;
                        $notice = __( 'Automation saved inactive: every journey SMS step requires a currently approved template.', 'messageistic' );
                        break;
                    }
                }
            }

            if ( $pilot_mode ) {
                $data['actions']['classification'] = 'transactional';
                $data['actions']['body'] = '';
                if ( $data['is_active'] && ! Template_Repository::is_approved( (int) $data['template_id'], 'transactional' ) ) {
                    $data['is_active'] = false;
                    $notice = __( 'Automation saved inactive: pilot automations require an approved transactional template.', 'messageistic' );
                }
            }
            if ( $id ) {
                Automation_Repository::update( $id, $data );
                Automation_Step_Repository::replace( $id, $steps );
                if ( '' === $notice ) {
                    $notice = __( 'Automation updated.', 'messageistic' );
                }
            } else {
                $id     = Automation_Repository::create( $data );
                Automation_Step_Repository::replace( $id, $steps );
                wp_safe_redirect( admin_url( 'admin.php?page=messageistic-automations&view=edit&id=' . $id ) );
                exit;
            }
        } elseif ( 'delete' === $action ) {
            check_admin_referer( 'messageistic_delete_automation' );
            Automation_Repository::delete( (int) ( $_REQUEST['id'] ?? 0 ) );
            $notice = __( 'Automation deleted.', 'messageistic' );
        }

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
        if ( 'new' === $view || 'edit' === $view ) {
            $automation = isset( $_GET['id'] ) ? Automation_Repository::find( (int) $_GET['id'] ) : [];
            $triggers   = Trigger_Registry::all();
            $templates  = Template_Repository::all();
            $steps      = ! empty( $automation['id'] ) ? Automation_Step_Repository::for_automation( (int) $automation['id'] ) : [];
            $locations  = Location_Repository::all( true );
            $staff      = get_users( [ 'capability' => Permissions::VIEW ] );
            $pilot_mode = ! empty( ( (array) get_option( 'messageistic_general_settings', [] ) )['pilot_mode_enabled'] );
            include MESSAGEISTIC_DIR . 'templates/admin/automation-edit.php';
            return;
        }

        $rows     = Automation_Repository::all();
        $triggers = Trigger_Registry::all();
        include MESSAGEISTIC_DIR . 'templates/admin/automations.php';
    }
}
