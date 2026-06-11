<?php
/**
 * Fail-closed provider eligibility rules for restricted industries.
 *
 * @package Messageistic\Compliance
 */

namespace Messageistic\Compliance;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Provider_Eligibility {
    public static function check( string $provider, string $classification, array $settings ) {
        $industry = sanitize_key( (string) ( $settings['industry'] ?? 'general' ) );
        if ( 'firearms' !== $industry ) {
            return true;
        }

        $raw = $settings['approved_firearm_providers'] ?? [];
        $approved = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
        $approved = array_filter( array_map( 'sanitize_key', $approved ) );
        if ( ! in_array( $provider, $approved, true ) ) {
            return new WP_Error(
                'messageistic_provider_not_approved',
                __( 'The active provider has not been explicitly approved for firearm-industry messaging.', 'messageistic' )
            );
        }

        if ( 'twilio' === $provider ) {
            return new WP_Error(
                'messageistic_provider_firearm_restricted',
                __( 'Twilio is blocked for firearm-industry traffic by the Messageistic compliance policy.', 'messageistic' )
            );
        }

        if ( Message_Classification::MARKETING === $classification && empty( $settings['firearm_marketing_enabled'] ) ) {
            return new WP_Error(
                'messageistic_firearm_marketing_disabled',
                __( 'Firearm marketing messages are disabled until provider and legal approval are recorded.', 'messageistic' )
            );
        }
        return true;
    }
}
