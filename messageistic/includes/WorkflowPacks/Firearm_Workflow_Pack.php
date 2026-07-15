<?php
/**
 * Safe firearm transactional workflow pack.
 *
 * `status` values below must match `extras.ffl_status` as set by
 * `FFL_Integration` — one of advanced-ffl-checkout's actual transfer
 * statuses (`payment_confirmed`, `shipped_to_dealer`, `received_by_dealer`,
 * `background_check`, `delayed`, `approved`, `denied`, `transferred`,
 * `cancelled` — see that plugin's `Api::update_transfer_status()`
 * `$valid_statuses`), or the `context_equals` condition below can never pass.
 *
 * @package Messageistic\WorkflowPacks
 */
namespace Messageistic\WorkflowPacks;

use Messageistic\Database\Automation_Repository;
use Messageistic\Database\Automation_Step_Repository;
use Messageistic\Database\Template_Repository;

defined( 'ABSPATH' ) || exit;

final class Firearm_Workflow_Pack {
    public static function definitions(): array {
        return [
            'ffl_received' => [ 'name' => 'FFL information received', 'trigger' => 'ffl.status_changed', 'status' => 'payment_confirmed', 'body' => '{first_name}, {business_name} has received the FFL information for your transaction. We will contact you when the next step is ready. Reply STOP to opt out.' ],
            'transfer_arrived' => [ 'name' => 'Transfer arrived', 'trigger' => 'ffl.status_changed', 'status' => 'received_by_dealer', 'body' => '{first_name}, your transfer has arrived at {business_name}. Please follow your store instructions to schedule the required in-person process. Reply STOP to opt out.' ],
            'pickup_ready' => [ 'name' => 'Ready for pickup appointment', 'trigger' => 'ffl.status_changed', 'status' => 'approved', 'body' => '{first_name}, your transaction is ready for the next in-person appointment at {business_name}. Bring required identification. No eligibility decision is communicated by text. Reply STOP to opt out.' ],
            'document_needed' => [ 'name' => 'Documentation required', 'trigger' => 'ffl.status_changed', 'status' => 'delayed', 'body' => '{first_name}, {business_name} needs additional documentation to continue your transaction. Please contact the store; do not send sensitive documents by SMS. Reply STOP to opt out.' ],
            'class_reminder' => [ 'name' => 'Training class reminder', 'trigger' => 'booking.reminder_24h', 'body' => '{first_name}, reminder: your training appointment is {booking_date} at {booking_time} with {business_name}. Reply STOP to opt out.' ],
            'waiver_reminder' => [ 'name' => 'Waiver incomplete', 'trigger' => 'waiver.incomplete', 'body' => '{first_name}, your required waiver is incomplete. Complete it before arrival: {waiver_link}. Reply STOP to opt out.' ],
            'waiver_signed_confirm' => [ 'name' => 'Waiver signed confirmation', 'trigger' => 'waiver.signed', 'body' => '{first_name}, your {business_name} range waiver is on file and valid through {waiver_expires}. See you at the range. Reply STOP to opt out.' ],
            'waiver_renewal_sms' => [ 'name' => 'Waiver renewal due', 'trigger' => 'waiver.renewal_due', 'body' => '{first_name}, your {business_name} range waiver expires {waiver_expires}. Re-sign in under a minute, no login needed: {waiver_link}. Reply STOP to opt out.' ],
        ];
    }

    public static function install( int $location_id = 0 ): array {
        global $wpdb;
        $created = [];
        $templates_table = Template_Repository::table();
        foreach ( self::definitions() as $key => $definition ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$templates_table} WHERE name=%s AND COALESCE(location_id,0)=%d LIMIT 1", $definition['name'], $location_id ) );
            if ( $existing ) {
                continue;
            }
            $template_id = Template_Repository::create( [
                'location_id' => $location_id,
                'name' => $definition['name'],
                'category' => 'firearm_transactional',
                'classification' => 'transactional',
                'body' => $definition['body'],
                'is_active' => 1,
            ] );
            $conditions = [ [ 'kind' => 'has_consent' ], [ 'kind' => 'not_opted_out' ] ];
            if ( isset( $definition['status'] ) ) {
                $conditions[] = [ 'kind' => 'context_equals', 'field' => 'ffl_status', 'value' => $definition['status'] ];
            }
            $automation_id = Automation_Repository::create( [
                'location_id' => $location_id,
                'name' => $definition['name'],
                'trigger_key' => $definition['trigger'],
                'conditions' => $conditions,
                'is_active' => 0,
                'version' => 1,
            ] );
            Automation_Step_Repository::replace( $automation_id, [ [ 'step_type' => 'send_sms', 'template_id' => $template_id, 'delay_seconds' => 0 ] ] );
            $created[] = [ 'template_id' => $template_id, 'automation_id' => $automation_id, 'key' => $key ];
        }
        return $created;
    }
}
