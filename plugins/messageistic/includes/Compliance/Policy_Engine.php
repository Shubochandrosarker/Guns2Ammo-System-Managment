<?php
/**
 * Central fail-closed outbound messaging policy engine.
 *
 * @package Messageistic\Compliance
 */

namespace Messageistic\Compliance;

use DateTimeImmutable;
use DateTimeZone;
use Messageistic\Database\Consent_Event_Repository;
use Messageistic\Database\Message_Repository;
use Messageistic\Database\Optout_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Policy_Engine {
    public static function evaluate( array $contact, array $args, string $provider ) {
        $settings       = (array) get_option( 'messageistic_general_settings', [] );
        $classification = Message_Classification::normalize( (string) ( $args['classification'] ?? '' ) );
        if ( ! empty( $args['location_timezone'] ) ) {
            $settings['default_timezone'] = sanitize_text_field( (string) $args['location_timezone'] );
        }

        if ( ! empty( $contact['opt_out'] ) || Optout_Repository::is_opted_out_phone( (string) $contact['normalized_phone'] ) ) {
            return new WP_Error( 'messageistic_opted_out', __( 'Contact is opted out.', 'messageistic' ) );
        }

        $consent_type = Message_Classification::requires_marketing_consent( $classification ) ? 'marketing' : 'transactional';
        $ledger_ok    = Consent_Event_Repository::has_active_consent( (int) $contact['id'], $consent_type );
        if ( ! $ledger_ok ) {
            return new WP_Error( 'messageistic_missing_consent', __( 'Active consent evidence is required for this message classification.', 'messageistic' ) );
        }

        if ( 'firearms' === sanitize_key( (string) ( $settings['industry'] ?? '' ) ) ) {
            $minimum_age = max( 18, (int) ( $settings['minimum_verified_age'] ?? 21 ) );
            $birth_date  = (string) ( $contact['date_of_birth'] ?? '' );
            $birth       = DateTimeImmutable::createFromFormat( '!Y-m-d', $birth_date );
            $age         = $birth ? (int) $birth->diff( new DateTimeImmutable( 'today' ) )->y : 0;
            if ( empty( $contact['age_verified_at'] ) || (int) ( $contact['age_verified'] ?? 0 ) !== 1 || $age < $minimum_age ) {
                return new WP_Error( 'messageistic_age_not_verified', __( 'Active age verification and the configured minimum age are required for firearm-industry messaging.', 'messageistic' ) );
            }
        }

        $eligibility = Provider_Eligibility::check( $provider, $classification, $settings );
        if ( is_wp_error( $eligibility ) ) {
            return $eligibility;
        }

        $promotion = Promotion_Policy::evaluate( $classification, $provider, $settings );
        if ( is_wp_error( $promotion ) ) {
            return $promotion;
        }

        $pilot = Pilot_Policy::evaluate( $settings, [
            'classification'    => (string) ( $args['classification'] ?? '' ),
            'provider'          => $provider,
            'sender'            => (string) ( $args['sender'] ?? '' ),
            'template_id'       => (int) ( $args['template_id'] ?? 0 ),
            'template_approved' => ! empty( $args['template_approved'] ),
            'provider_settings' => (array) ( $args['provider_settings'] ?? [] ),
        ] );
        if ( is_wp_error( $pilot ) ) {
            return $pilot;
        }

        if ( empty( $args['bypass_quiet_hours'] ) && self::is_quiet_hours( $settings ) ) {
            return new WP_Error( 'messageistic_quiet_hours', __( 'The message is blocked during configured quiet hours.', 'messageistic' ) );
        }

        $daily_limit = max( 0, (int) ( $settings['daily_sending_limit'] ?? 0 ) );
        if ( ! empty( $settings['pilot_mode_enabled'] ) ) {
            $daily_limit = max( 100, min( 500, (int) ( $settings['pilot_daily_limit'] ?? 100 ) ) );
        }
        $daily_count = ! empty( $settings['pilot_mode_enabled'] )
            ? Message_Repository::outbound_attempt_count_since( current_time( 'Y-m-d 00:00:00' ) )
            : Message_Repository::outbound_count_since( current_time( 'Y-m-d 00:00:00' ) );
        if ( $daily_limit && $daily_count >= $daily_limit ) {
            return new WP_Error( 'messageistic_daily_limit', __( 'The site-wide daily sending limit has been reached.', 'messageistic' ) );
        }

        $contact_limit = max( 0, (int) ( $settings['contact_daily_limit'] ?? 3 ) );
        if ( $contact_limit && Message_Repository::contact_outbound_count_since( (int) $contact['id'], current_time( 'Y-m-d 00:00:00' ) ) >= $contact_limit ) {
            return new WP_Error( 'messageistic_contact_frequency_limit', __( 'The contact daily frequency cap has been reached.', 'messageistic' ) );
        }

        return [ 'classification' => $classification, 'consent_type' => $consent_type ];
    }

    private static function is_quiet_hours( array $settings ): bool {
        $start = (string) ( $settings['quiet_hours_start'] ?? '' );
        $end   = (string) ( $settings['quiet_hours_end'] ?? '' );
        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $start ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $end ) || $start === $end ) {
            return false;
        }
        try {
            $zone = new DateTimeZone( (string) ( $settings['default_timezone'] ?? wp_timezone_string() ) );
        } catch ( \Exception $e ) {
            $zone = wp_timezone();
        }
        $now = ( new DateTimeImmutable( 'now', $zone ) )->format( 'H:i' );
        return $start < $end ? $now >= $start && $now < $end : $now >= $start || $now < $end;
    }
}
