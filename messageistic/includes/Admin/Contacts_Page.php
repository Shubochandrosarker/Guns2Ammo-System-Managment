<?php
/**
 * Contacts list + add/edit + manual SMS send.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Consent_Event_Repository;
use Messageistic\Database\Message_Repository;
use Messageistic\Database\Template_Repository;
use Messageistic\Helpers\Phone_Normalizer;
use Messageistic\Messaging\SMS_Service;
use Messageistic\Locations\Location_Repository;

defined( 'ABSPATH' ) || exit;

final class Contacts_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::VIEW ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $notice = '';

        if ( 'save' === $action && current_user_can( Permissions::MANAGE ) ) {
            $notice = self::handle_save();
        } elseif ( 'send' === $action && current_user_can( Permissions::SEND_SMS ) ) {
            $notice = self::handle_send();
        }

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

        if ( 'edit' === $view || 'new' === $view ) {
            $contact_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
            $contact    = $contact_id ? Contact_Repository::find( $contact_id ) : [];
            $messages   = $contact_id ? Message_Repository::for_contact( $contact_id, 25 ) : [];
            $templates  = Template_Repository::all();
            $pilot_settings = (array) get_option( 'messageistic_general_settings', [] );
            $pilot_mode = ! empty( $pilot_settings['pilot_mode_enabled'] );
            $locations = Location_Repository::all( true );
            include MESSAGEISTIC_DIR . 'templates/admin/contact-edit.php';
            return;
        }

        $args = [
            'page'     => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
            'per_page' => 25,
            'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'opt_out'  => isset( $_GET['opt_out'] ) ? sanitize_text_field( wp_unslash( $_GET['opt_out'] ) ) : '',
            'consent'  => isset( $_GET['consent'] ) ? sanitize_text_field( wp_unslash( $_GET['consent'] ) ) : '',
        ];
        $page_data    = Contact_Repository::paginate( $args );
        $rows         = $page_data['rows'];
        $total        = $page_data['total'];
        $total_pages  = max( 1, (int) ceil( $total / $args['per_page'] ) );
        $current_page = $args['page'];

        include MESSAGEISTIC_DIR . 'templates/admin/contacts.php';
    }

    private static function handle_save(): string {
        check_admin_referer( 'messageistic_save_contact' );
        $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $cc   = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $data = [
            'location_id'     => (int) ( $_POST['location_id'] ?? 0 ),
            'first_name'      => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
            'last_name'       => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
            'email'           => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
            'phone'           => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
            'source'          => sanitize_text_field( wp_unslash( $_POST['source'] ?? 'manual' ) ),
            'customer_type'   => sanitize_text_field( wp_unslash( $_POST['customer_type'] ?? '' ) ),
            'sms_consent'     => ! empty( $_POST['sms_consent'] ),
            'consent_source'  => sanitize_text_field( wp_unslash( $_POST['consent_source'] ?? '' ) ),
            'opt_out'         => ! empty( $_POST['opt_out'] ),
            'age_verified'     => ! empty( $_POST['age_verified'] ),
            'age_verified_at'  => ! empty( $_POST['age_verified'] ) ? current_time( 'mysql' ) : null,
            'date_of_birth'    => sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ?? '' ) ),
            'normalized_phone' => Phone_Normalizer::normalize( (string) ( $_POST['phone'] ?? '' ), $cc ),
        ];
        if ( $id ) {
            Contact_Repository::update( $id, $data );
            self::record_consent( $id, $data );
            return __( 'Contact updated.', 'messageistic' );
        }
        $new_id = Contact_Repository::create( $data );
        self::record_consent( $new_id, $data );
        wp_safe_redirect( admin_url( 'admin.php?page=messageistic-contacts&view=edit&id=' . $new_id . '&saved=1' ) );
        exit;
    }

    private static function record_consent( int $contact_id, array $data ): void {
        $event = sanitize_key( wp_unslash( $_POST['consent_event'] ?? '' ) );
        if ( '' === $event ) {
            return;
        }
        $disclosure_version = sanitize_text_field( wp_unslash( $_POST['disclosure_version'] ?? '' ) );
        $disclosure_text    = sanitize_textarea_field( wp_unslash( $_POST['disclosure_text'] ?? '' ) );
        if ( in_array( $event, [ 'grant_transactional', 'grant_marketing' ], true ) && ( '' === $disclosure_version || '' === $disclosure_text ) ) {
            return;
        }
        $types = 'revoke_all' === $event ? [ 'transactional', 'marketing' ] : [ str_replace( 'grant_', '', $event ) ];
        foreach ( $types as $type ) {
            Consent_Event_Repository::record( [
                'contact_id'         => $contact_id,
                'phone'              => $data['normalized_phone'],
                'consent_type'       => $type,
                'event_type'         => 'revoke_all' === $event ? 'revoked' : 'granted',
                'source'             => $data['consent_source'] ?: 'admin',
                'disclosure_version' => $disclosure_version,
                'disclosure_text'    => $disclosure_text,
                'source_url'         => admin_url( 'admin.php?page=messageistic-contacts' ),
                'evidence'           => [ 'age_verified' => ! empty( $data['age_verified'] ), 'date_of_birth' => $data['date_of_birth'] ],
            ] );
        }
    }

    private static function handle_send(): string {
        check_admin_referer( 'messageistic_manual_send' );
        $contact_id = (int) ( $_POST['contact_id'] ?? 0 );
        $body       = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
        $template_id = (int) ( $_POST['template_id'] ?? 0 );

        $service = new SMS_Service();
        $result  = $service->send( [
            'contact_id' => $contact_id,
            'body'       => $body,
            'template_id' => $template_id,
            'classification' => sanitize_key( wp_unslash( $_POST['classification'] ?? 'transactional' ) ),
            'idempotency_key' => 'manual:' . $contact_id . ':' . wp_generate_uuid4(),
        ] );
        if ( is_wp_error( $result ) ) {
            return sprintf( /* translators: %s: error message */ __( 'Send failed: %s', 'messageistic' ), $result->get_error_message() );
        }
        return __( 'Message sent.', 'messageistic' );
    }
}
