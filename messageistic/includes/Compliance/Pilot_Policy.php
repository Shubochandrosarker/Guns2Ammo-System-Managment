<?php
/**
 * Controlled-pilot restrictions applied on top of the compliance policy.
 *
 * @package Messageistic\Compliance
 */

namespace Messageistic\Compliance;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Pilot_Policy {

    /**
     * @param array<string,mixed> $settings General settings.
     * @param array<string,mixed> $context  Provider, sender and template context.
     * @return true|WP_Error
     */
    public static function evaluate( array $settings, array $context ) {
        if ( empty( $settings['pilot_mode_enabled'] ) ) {
            return true;
        }

        $required = [
            'business_name'       => __( 'Pilot business name is required.', 'messageistic' ),
            'pilot_location_name' => __( 'Pilot location name is required.', 'messageistic' ),
            'pilot_location_state' => __( 'Pilot U.S. state is required.', 'messageistic' ),
            'pilot_provider'      => __( 'One approved pilot provider must be configured.', 'messageistic' ),
            'pilot_sender'        => __( 'One approved pilot sender must be configured.', 'messageistic' ),
            'pilot_approval_reference' => __( 'Provider approval or registration reference is required.', 'messageistic' ),
        ];
        foreach ( $required as $field => $message ) {
            if ( '' === trim( (string) ( $settings[ $field ] ?? '' ) ) ) {
                return new WP_Error( 'messageistic_pilot_incomplete', $message );
            }
        }

        if ( 'US' !== strtoupper( (string) ( $settings['pilot_country'] ?? 'US' ) ) ) {
            return new WP_Error( 'messageistic_pilot_country', __( 'Controlled pilot mode is limited to one U.S. location.', 'messageistic' ) );
        }

        $state = strtoupper( (string) $settings['pilot_location_state'] );
        $states = [ 'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC' ];
        if ( ! in_array( $state, $states, true ) ) {
            return new WP_Error( 'messageistic_pilot_state', __( 'A valid two-letter U.S. state or DC is required for the pilot location.', 'messageistic' ) );
        }

        if ( Message_Classification::TRANSACTIONAL !== sanitize_key( (string) ( $context['classification'] ?? '' ) ) ) {
            return new WP_Error( 'messageistic_pilot_transactional_only', __( 'Controlled pilot mode permits transactional messages only.', 'messageistic' ) );
        }

        if ( 'testing' === sanitize_key( (string) $settings['pilot_provider'] ) ) {
            return new WP_Error( 'messageistic_pilot_live_provider_required', __( 'Controlled pilot mode requires an approved live provider; the Testing provider is not permitted.', 'messageistic' ) );
        }

        $provider_settings = (array) ( $context['provider_settings'] ?? [] );
        if ( 'twilio' === sanitize_key( (string) $settings['pilot_provider'] ) && ! empty( $provider_settings['messaging_service_sid'] ) ) {
            return new WP_Error( 'messageistic_pilot_sender_pool', __( 'Twilio Messaging Service sender pools are not allowed during the one-sender pilot.', 'messageistic' ) );
        }

        if ( sanitize_key( (string) ( $context['provider'] ?? '' ) ) !== sanitize_key( (string) $settings['pilot_provider'] ) ) {
            return new WP_Error( 'messageistic_pilot_provider_mismatch', __( 'The active provider does not match the approved pilot provider.', 'messageistic' ) );
        }

        if ( self::normalize_sender( (string) ( $context['sender'] ?? '' ) ) !== self::normalize_sender( (string) $settings['pilot_sender'] ) ) {
            return new WP_Error( 'messageistic_pilot_sender_mismatch', __( 'The outbound sender does not match the approved pilot sender.', 'messageistic' ) );
        }

        if ( empty( $context['template_id'] ) || empty( $context['template_approved'] ) ) {
            return new WP_Error( 'messageistic_pilot_template_unapproved', __( 'Controlled pilot messages must use a currently approved template.', 'messageistic' ) );
        }

        $limit = (int) ( $settings['pilot_daily_limit'] ?? 100 );
        if ( $limit < 100 || $limit > 500 ) {
            return new WP_Error( 'messageistic_pilot_limit_invalid', __( 'Pilot daily limit must be between 100 and 500 messages.', 'messageistic' ) );
        }

        return true;
    }

    private static function normalize_sender( string $sender ): string {
        return strtolower( preg_replace( '/[^a-z0-9+]/i', '', trim( $sender ) ) );
    }
}
