<?php
/**
 * Background sender for campaigns. Pulls a small batch of pending recipients
 * and sends through the SMS service. Reschedules itself until empty.
 *
 * Uses Action Scheduler when available, otherwise WP-Cron.
 *
 * @package Messageistic\Campaigns
 */

namespace Messageistic\Campaigns;

use Messageistic\Database\Campaign_Repository;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Template_Repository;
use Messageistic\Helpers\Logger;
use Messageistic\Messaging\SMS_Service;
use Messageistic\Messaging\Template_Parser;

defined( 'ABSPATH' ) || exit;

final class Campaign_Queue {

    public const HOOK       = 'messageistic_campaign_tick';
    public const BATCH_SIZE = 25;

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'tick' ], 10, 1 );
    }

    public static function enqueue( int $campaign_id, int $delay = 0 ): void {
        if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
            if ( $delay > 0 ) {
                as_schedule_single_action( time() + $delay, self::HOOK, [ $campaign_id ], 'messageistic' );
            } else {
                as_enqueue_async_action( self::HOOK, [ $campaign_id ], 'messageistic' );
            }
            return;
        }
        wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK, [ $campaign_id ] );
    }

    public static function tick( int $campaign_id ): void {
        $campaign = Campaign_Repository::find( $campaign_id );
        if ( ! $campaign || in_array( $campaign['status'], [ 'paused', 'cancelled', 'sent', 'failed' ], true ) ) {
            return;
        }

        $pilot_settings = (array) get_option( 'messageistic_general_settings', [] );
        if ( ! empty( $pilot_settings['pilot_mode_enabled'] ) && ( 'transactional' !== (string) ( $campaign['classification'] ?? '' ) || ! Template_Repository::is_approved( (int) ( $campaign['template_id'] ?? 0 ), 'transactional' ) ) ) {
            Campaign_Repository::set_status( $campaign_id, 'failed' );
            Logger::log( 'campaign.pilot_blocked', [ 'level' => 'error', 'context' => [ 'campaign_id' => $campaign_id ] ] );
            return;
        }

        if ( 'marketing' === (string) ( $campaign['classification'] ?? '' ) && ! Template_Repository::is_approved( (int) ( $campaign['template_id'] ?? 0 ), 'marketing' ) ) {
            Campaign_Repository::set_status( $campaign_id, 'failed' );
            Logger::log( 'campaign.promotional_template_blocked', [ 'level' => 'error', 'context' => [ 'campaign_id' => $campaign_id ] ] );
            return;
        }

        if ( 'sending' !== $campaign['status'] ) {
            Campaign_Repository::set_status( $campaign_id, 'sending' );
        }

        $body = (string) $campaign['body'];
        if ( ! empty( $campaign['template_id'] ) ) {
            $template = Template_Repository::find( (int) $campaign['template_id'] );
            if ( $template ) {
                $body = (string) $template['body'];
            }
        }

        $service    = new SMS_Service();
        $recipients = Campaign_Repository::claim_recipients( $campaign_id, self::BATCH_SIZE );

        foreach ( $recipients as $recipient ) {
            $contact = Contact_Repository::find( (int) $recipient['contact_id'] );
            if ( ! $contact ) {
                Campaign_Repository::update_recipient( (int) $recipient['id'], [ 'status' => 'failed', 'error_message' => 'contact missing' ] );
                Campaign_Repository::increment_counter( $campaign_id, 'failed_count' );
                continue;
            }
            $rendered = Template_Parser::render( $body, $contact );
            $result   = $service->send( [ 'contact_id' => (int) $contact['id'], 'location_id' => (int) ( $campaign['location_id'] ?? 0 ), 'body' => $rendered, 'campaign_id' => $campaign_id, 'template_id' => (int) ( $campaign['template_id'] ?? 0 ), 'classification' => $campaign['classification'] ?? 'marketing', 'idempotency_key' => 'campaign:' . $campaign_id . ':recipient:' . (int) $recipient['id'] ] );
            if ( is_wp_error( $result ) ) {
                $permanent = in_array( $result->get_error_code(), [ 'messageistic_opted_out', 'messageistic_missing_consent', 'messageistic_age_not_verified', 'messageistic_provider_not_approved', 'messageistic_provider_firearm_restricted', 'messageistic_firearm_marketing_disabled', 'messageistic_pilot_incomplete', 'messageistic_pilot_country', 'messageistic_pilot_transactional_only', 'messageistic_pilot_provider_mismatch', 'messageistic_pilot_sender_mismatch', 'messageistic_pilot_template_unapproved', 'messageistic_pilot_limit_invalid', 'messageistic_pilot_state', 'messageistic_pilot_live_provider_required', 'messageistic_pilot_sender_pool', 'messageistic_promotions_blocked_in_pilot', 'messageistic_promotional_approval_required', 'messageistic_location_provider_mismatch' ], true );
                $attempts  = (int) ( $recipient['attempt_count'] ?? 1 );
                if ( ! $permanent && $attempts < 3 ) {
                    Campaign_Repository::update_recipient( (int) $recipient['id'], [
                        'status'          => 'pending',
                        'lock_token'      => '',
                        'locked_at'       => null,
                        'next_attempt_at' => wp_date( 'Y-m-d H:i:s', time() + ( 60 * ( 2 ** max( 0, $attempts - 1 ) ) ) ),
                        'error_message'   => $result->get_error_message(),
                    ] );
                } else {
                    Campaign_Repository::update_recipient( (int) $recipient['id'], [ 'status' => 'failed', 'lock_token' => '', 'locked_at' => null, 'error_message' => $result->get_error_message() ] );
                    Campaign_Repository::increment_counter( $campaign_id, 'failed_count' );
                }
                continue;
            }
            Campaign_Repository::update_recipient( (int) $recipient['id'], [
                'status'              => 'sent',
                'message_id'          => (int) ( $result['message_id'] ?? 0 ),
                'provider_message_id' => (string) ( $result['result']['provider_message_id'] ?? '' ),
                'sent_at'             => current_time( 'mysql' ),
                'lock_token'          => '',
                'locked_at'           => null,
            ] );
            Campaign_Repository::increment_counter( $campaign_id, 'sent_count' );
        }

        $remaining = Campaign_Repository::pending_count( $campaign_id );
        if ( $remaining > 0 ) {
            self::enqueue( $campaign_id, 30 );
            return;
        }
        Campaign_Repository::set_status( $campaign_id, 'sent' );
        Logger::log( 'campaign.completed', [ 'context' => [ 'campaign_id' => $campaign_id ] ] );
    }
}
