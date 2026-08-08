<?php
/**
 * Campaigns list/edit + actions (queue, send-now, pause, cancel).
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Campaigns\Campaign_Queue;
use Messageistic\Core\Permissions;
use Messageistic\Core\Plugin;
use Messageistic\Database\Campaign_Repository;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Template_Repository;
use Messageistic\Locations\Location_Repository;
use Messageistic\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

final class Campaigns_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE_CAMPAIGNS ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $notice = '';

        if ( 'save' === $action ) {
            $notice = self::handle_save();
        } elseif ( 'queue' === $action ) {
            check_admin_referer( 'messageistic_campaign_action' );
            $id       = (int) ( $_REQUEST['id'] ?? 0 );
            $campaign = Campaign_Repository::find( $id );
            if ( $campaign && ! self::pilot_campaign_ready( $campaign ) ) {
                $notice = __( 'Pilot campaigns must be transactional and use a currently approved transactional template.', 'messageistic' );
            } elseif ( $campaign && 'marketing' === (string) ( $campaign['classification'] ?? '' ) && ! Template_Repository::is_approved( (int) ( $campaign['template_id'] ?? 0 ), 'marketing' ) ) {
                $notice = __( 'Promotional campaigns require a currently approved marketing template.', 'messageistic' );
            } elseif ( $campaign && 'provider_push' === $campaign['provider_mode'] ) {
                $notice = self::handle_provider_push( $campaign );
            } else {
                self::populate_audience( $id );
                Campaign_Queue::enqueue( $id );
                $notice = __( 'Campaign queued.', 'messageistic' );
            }
        } elseif ( 'pause' === $action ) {
            check_admin_referer( 'messageistic_campaign_action' );
            Campaign_Repository::set_status( (int) ( $_REQUEST['id'] ?? 0 ), 'paused' );
            $notice = __( 'Campaign paused.', 'messageistic' );
        } elseif ( 'resume' === $action ) {
            check_admin_referer( 'messageistic_campaign_action' );
            $id = (int) ( $_REQUEST['id'] ?? 0 );
            Campaign_Repository::set_status( $id, 'sending' );
            Campaign_Queue::enqueue( $id );
            $notice = __( 'Campaign resumed.', 'messageistic' );
        } elseif ( 'cancel' === $action ) {
            check_admin_referer( 'messageistic_campaign_action' );
            Campaign_Repository::set_status( (int) ( $_REQUEST['id'] ?? 0 ), 'cancelled' );
            $notice = __( 'Campaign cancelled.', 'messageistic' );
        }

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
        if ( 'new' === $view || 'edit' === $view ) {
            $campaign  = isset( $_GET['id'] ) ? Campaign_Repository::find( (int) $_GET['id'] ) : [];
            $templates = Template_Repository::all();
            $locations = Location_Repository::all( true );
            $pilot_mode = ! empty( ( (array) get_option( 'messageistic_general_settings', [] ) )['pilot_mode_enabled'] );
            include MESSAGEISTIC_DIR . 'templates/admin/campaign-edit.php';
            return;
        }

        $rows = Campaign_Repository::all();
        include MESSAGEISTIC_DIR . 'templates/admin/campaigns.php';
    }

    private static function handle_save(): string {
        check_admin_referer( 'messageistic_save_campaign' );
        $id   = (int) ( $_POST['id'] ?? 0 );
        $pilot_mode = ! empty( ( (array) get_option( 'messageistic_general_settings', [] ) )['pilot_mode_enabled'] );
        $data = [
            'location_id'   => (int) ( $_POST['location_id'] ?? 0 ),
            'name'          => wp_unslash( $_POST['name'] ?? '' ),
            'classification' => sanitize_key( wp_unslash( $_POST['classification'] ?? 'marketing' ) ),
            'type'          => wp_unslash( $_POST['type'] ?? 'broadcast' ),
            'audience'      => [
                'kind'   => sanitize_key( $_POST['audience_kind'] ?? 'all' ),
                'tag'    => sanitize_text_field( wp_unslash( $_POST['audience_tag'] ?? '' ) ),
            ],
            'provider_mode' => wp_unslash( $_POST['provider_mode'] ?? 'queue' ),
            'template_id'   => (int) ( $_POST['template_id'] ?? 0 ),
            'body'          => wp_unslash( $_POST['body'] ?? '' ),
            'schedule_at'   => sanitize_text_field( wp_unslash( $_POST['schedule_at'] ?? '' ) ) ?: null,
            'status'        => isset( $_POST['schedule_at'] ) && '' !== $_POST['schedule_at'] ? 'scheduled' : 'draft',
        ];
        if ( $pilot_mode ) {
            $data['classification'] = 'transactional';
            $data['provider_mode']  = 'queue';
            $data['body']           = '';
        }
        if ( $id ) {
            Campaign_Repository::update( $id, $data );
            return __( 'Campaign updated.', 'messageistic' );
        }
        $id = Campaign_Repository::create( $data );
        wp_safe_redirect( admin_url( 'admin.php?page=messageistic-campaigns&view=edit&id=' . $id ) );
        exit;
    }

    /** @param array<string,mixed> $campaign */
    private static function pilot_campaign_ready( array $campaign ): bool {
        $settings = (array) get_option( 'messageistic_general_settings', [] );
        if ( empty( $settings['pilot_mode_enabled'] ) ) {
            return true;
        }
        return 'transactional' === (string) ( $campaign['classification'] ?? '' )
            && Template_Repository::is_approved( (int) ( $campaign['template_id'] ?? 0 ), 'transactional' );
    }

    /**
     * Push the campaign payload to the active provider (OtterText only for now).
     *
     * @param array<string,mixed> $campaign
     */
    private static function handle_provider_push( array $campaign ): string {
        if ( ! apply_filters( 'messageistic_allow_provider_campaign_push', false, $campaign ) ) {
            return __( 'Direct provider campaign push is disabled because it bypasses per-recipient compliance checks. Use the internal queue.', 'messageistic' );
        }
        $provider = Plugin::instance()->providers()->get_active();
        if ( ! method_exists( $provider, 'push_campaign' ) ) {
            return __( 'Active provider does not support campaign push.', 'messageistic' );
        }
        $body = (string) $campaign['body'];
        if ( ! empty( $campaign['template_id'] ) ) {
            $template = Template_Repository::find( (int) $campaign['template_id'] );
            if ( $template ) {
                $body = (string) $template['body'];
            }
        }
        $result = $provider->push_campaign( [
            'name'        => (string) $campaign['name'],
            'body'        => $body,
            'list_id'     => null,
            'schedule_at' => $campaign['schedule_at'] ?? null,
        ] );
        if ( is_wp_error( $result ) ) {
            return sprintf( /* translators: %s: error */ __( 'Provider push failed: %s', 'messageistic' ), $result->get_error_message() );
        }
        Campaign_Repository::set_status( (int) $campaign['id'], 'sent', [
            'provider_key'         => $provider->get_key(),
            'provider_campaign_id' => (string) $result,
        ] );
        Logger::log( 'campaign.pushed', [
            'provider_key' => $provider->get_key(),
            'context'      => [ 'campaign_id' => (int) $campaign['id'], 'provider_campaign_id' => (string) $result ],
        ] );
        return __( 'Campaign pushed to provider.', 'messageistic' );
    }

    /**
     * Resolve a saved campaign's audience definition into recipient rows.
     */
    private static function populate_audience( int $campaign_id ): void {
        $campaign = Campaign_Repository::find( $campaign_id );
        if ( ! $campaign ) {
            return;
        }
        $audience = json_decode( (string) $campaign['audience'], true );
        $kind     = $audience['kind'] ?? 'all';
        $args     = [ 'per_page' => 100, 'consent' => '1', 'opt_out' => '0' ];
        if ( ! empty( $campaign['location_id'] ) ) {
            $args['location_id'] = (int) $campaign['location_id'];
        }
        $page_data = Contact_Repository::paginate( $args );
        $contact_ids = array_map( static fn ( $r ) => (int) $r['id'], $page_data['rows'] );
        if ( 'tag' === $kind && ! empty( $audience['tag'] ) ) {
            $tag         = $audience['tag'];
            $contact_ids = array_values( array_filter( $contact_ids, static function ( $id ) use ( $tag ) {
                $row = \Messageistic\Database\Contact_Repository::find( $id );
                return $row && false !== strpos( (string) $row['tags'], $tag );
            } ) );
        }
        Campaign_Repository::add_recipients( $campaign_id, $contact_ids );
    }
}
