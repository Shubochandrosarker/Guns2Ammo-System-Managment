<?php
/**
 * Phase 8 — External REST API for the G2A dashboard app.
 *
 * Routes are namespaced under messageistic/v1. Authentication uses standard
 * WordPress REST auth (cookie+nonce, application passwords, or third-party
 * JWT). Permissions are enforced via Messageistic capabilities.
 *
 * @package Messageistic\REST
 */

namespace Messageistic\REST;

use Messageistic\Core\Permissions;
use Messageistic\Database\Automation_Repository;
use Messageistic\Database\Campaign_Repository;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Conversation_Repository;
use Messageistic\Database\Message_Repository;
use Messageistic\Database\Template_Repository;
use Messageistic\Import\Import_Job_Repository;
use Messageistic\Import\Import_Service;
use Messageistic\Messaging\SMS_Service;
use Messageistic\Sync\Sync_Job_Repository;
use Messageistic\Sync\Sync_Service;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Dashboard_API {

    public function register_routes(): void {
        $ns = REST_Controller::NAMESPACE_V1;

        register_rest_route( $ns, '/settings', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_settings' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/contacts', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_contacts' ],
                'permission_callback' => [ $this, 'can_view' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_contact' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/contacts/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_contact' ],
                'permission_callback' => [ $this, 'can_view' ],
            ],
            [
                'methods'             => 'PATCH,PUT',
                'callback'            => [ $this, 'update_contact' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/messages', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_messages' ],
                'permission_callback' => [ $this, 'can_view' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'send_message' ],
                'permission_callback' => [ $this, 'can_send' ],
            ],
        ] );

        register_rest_route( $ns, '/conversations', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_conversations' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/conversations/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_conversation' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/templates', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_templates' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/campaigns', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_campaigns' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/automations', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_automations' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/reports', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'reports' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/dashboard/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'dashboard_stats' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/import', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'import_status' ],
                'permission_callback' => [ $this, 'can_view' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'import_cancel' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [
                    'kind' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/sync', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'sync_status' ],
                'permission_callback' => [ $this, 'can_view' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'sync_start' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [
                    'provider'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                    'kind'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
                    'list_id'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'campaign_id' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'sync_cancel' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [
                    'provider' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                ],
            ],
        ] );
    }

    public function can_view(): bool { return current_user_can( Permissions::VIEW ); }
    public function can_manage(): bool { return current_user_can( Permissions::MANAGE ); }
    public function can_send(): bool { return current_user_can( Permissions::SEND_SMS ); }

    public function get_settings() {
        return rest_ensure_response( [
            'general'         => (array) get_option( 'messageistic_general_settings', [] ),
            'active_provider' => (string) get_option( 'messageistic_active_provider', 'testing' ),
        ] );
    }

    public function list_contacts( WP_REST_Request $r ) {
        return rest_ensure_response( Contact_Repository::paginate( [
            'page'     => (int) $r->get_param( 'page' ) ?: 1,
            'per_page' => (int) $r->get_param( 'per_page' ) ?: 25,
            'search'   => (string) $r->get_param( 'search' ),
            'opt_out'  => (string) $r->get_param( 'opt_out' ),
            'consent'  => (string) $r->get_param( 'consent' ),
        ] ) );
    }

    public function create_contact( WP_REST_Request $r ) {
        $cc = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $id = Contact_Repository::upsert_by_phone( [
            'first_name' => sanitize_text_field( (string) $r->get_param( 'first_name' ) ),
            'last_name'  => sanitize_text_field( (string) $r->get_param( 'last_name' ) ),
            'email'      => sanitize_email( (string) $r->get_param( 'email' ) ),
            'phone'      => sanitize_text_field( (string) $r->get_param( 'phone' ) ),
            'source'     => sanitize_text_field( (string) ( $r->get_param( 'source' ) ?: 'api' ) ),
            'sms_consent' => (bool) $r->get_param( 'sms_consent' ),
        ], $cc );
        return rest_ensure_response( Contact_Repository::find( $id ) );
    }

    public function get_contact( WP_REST_Request $r ) {
        $contact = Contact_Repository::find( (int) $r['id'] );
        if ( ! $contact ) {
            return new \WP_Error( 'messageistic_not_found', __( 'Contact not found.', 'messageistic' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( $contact );
    }

    public function update_contact( WP_REST_Request $r ) {
        $id = (int) $r['id'];
        Contact_Repository::update( $id, $r->get_json_params() ?: $r->get_params() );
        return rest_ensure_response( Contact_Repository::find( $id ) );
    }

    public function list_messages( WP_REST_Request $r ) {
        $contact_id = (int) $r->get_param( 'contact_id' );
        if ( ! $contact_id ) {
            return new \WP_Error( 'messageistic_missing_contact', __( 'contact_id is required.', 'messageistic' ), [ 'status' => 400 ] );
        }
        $limit = max( 1, min( 200, (int) ( $r->get_param( 'limit' ) ?: 50 ) ) );
        return rest_ensure_response( Message_Repository::for_contact( $contact_id, $limit ) );
    }

    public function send_message( WP_REST_Request $r ) {
        $service = new SMS_Service();
        $result  = $service->send( [
            'contact_id' => (int) $r->get_param( 'contact_id' ),
            'phone'      => (string) $r->get_param( 'phone' ),
            'body'       => (string) $r->get_param( 'body' ),
            'template_id' => (int) $r->get_param( 'template_id' ),
            'template_extras' => (array) $r->get_param( 'template_extras' ),
            'classification' => sanitize_key( (string) ( $r->get_param( 'classification' ) ?: 'transactional' ) ),
            'idempotency_key' => sanitize_text_field( (string) ( $r->get_header( 'Idempotency-Key' ) ?: $r->get_param( 'idempotency_key' ) ) ),
        ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function list_conversations() {
        return rest_ensure_response( Conversation_Repository::inbox( 100 ) );
    }

    public function get_conversation( WP_REST_Request $r ) {
        $conversation = Conversation_Repository::find( (int) $r['id'] );
        if ( ! $conversation ) {
            return new \WP_Error( 'messageistic_not_found', __( 'Conversation not found.', 'messageistic' ), [ 'status' => 404 ] );
        }
        $messages = Message_Repository::for_contact( (int) $conversation['contact_id'], 200 );
        return rest_ensure_response( [ 'conversation' => $conversation, 'messages' => array_reverse( $messages ) ] );
    }

    public function list_templates() {
        return rest_ensure_response( Template_Repository::all() );
    }

    public function list_campaigns() {
        return rest_ensure_response( Campaign_Repository::all() );
    }

    public function list_automations() {
        return rest_ensure_response( Automation_Repository::all() );
    }

    public function reports() {
        global $wpdb;
        $m = $wpdb->prefix . 'messageistic_messages';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT provider_key, status, COUNT(*) AS n FROM {$m} GROUP BY provider_key, status", ARRAY_A );
        return rest_ensure_response( $rows );
    }

    public function import_status( WP_REST_Request $r ) {
        $kind = sanitize_key( (string) $r->get_param( 'kind' ) );
        if ( $kind ) {
            return rest_ensure_response( Import_Job_Repository::get( $kind ) ?? [ 'status' => 'idle' ] );
        }
        return rest_ensure_response( Import_Job_Repository::all() );
    }

    public function import_cancel( WP_REST_Request $r ) {
        Import_Service::cancel( (string) $r->get_param( 'kind' ) );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    public function sync_status( WP_REST_Request $r ) {
        $provider = sanitize_key( (string) $r->get_param( 'provider' ) );
        if ( $provider ) {
            return rest_ensure_response( Sync_Job_Repository::get( $provider ) ?? [ 'status' => 'idle' ] );
        }
        return rest_ensure_response( Sync_Job_Repository::all() );
    }

    public function sync_start( WP_REST_Request $r ) {
        $result = Sync_Service::start(
            (string) $r->get_param( 'provider' ),
            [
                'kind'        => (string) $r->get_param( 'kind' ),
                'list_id'     => (string) $r->get_param( 'list_id' ),
                'campaign_id' => (string) $r->get_param( 'campaign_id' ),
            ]
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function sync_cancel( WP_REST_Request $r ) {
        Sync_Service::cancel( (string) $r->get_param( 'provider' ) );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    public function dashboard_stats() {
        global $wpdb;
        $m  = $wpdb->prefix . 'messageistic_messages';
        $c  = $wpdb->prefix . 'messageistic_contacts';
        $cp = $wpdb->prefix . 'messageistic_campaigns';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats = [
            'total_sent'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE direction = 'outbound'" ),
            'delivered'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE status = 'delivered'" ),
            'failed'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE status = 'failed'" ),
            'replies'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE direction = 'inbound'" ),
            'contacts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE opt_out = 0" ),
            'opted_out'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE opt_out = 1" ),
            'active_camps' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cp} WHERE status IN ('scheduled','sending')" ),
        ];
        // phpcs:enable
        return rest_ensure_response( $stats );
    }
}
