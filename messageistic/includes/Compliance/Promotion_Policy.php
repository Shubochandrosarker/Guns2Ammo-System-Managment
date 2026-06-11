<?php
/**
 * Fail-closed approval gate for promotional messaging.
 *
 * @package Messageistic\Compliance
 */

namespace Messageistic\Compliance;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Promotion_Policy {

    /**
     * @return true|WP_Error
     */
    public static function evaluate( string $classification, string $provider, array $settings ) {
        if ( Message_Classification::MARKETING !== Message_Classification::normalize( $classification ) ) {
            return true;
        }

        if ( ! empty( $settings['pilot_mode_enabled'] ) ) {
            return new WP_Error( 'messageistic_promotions_blocked_in_pilot', __( 'Promotional messaging is not allowed during the controlled pilot.', 'messageistic' ) );
        }

        $approved_provider = sanitize_key( (string) ( $settings['promotional_approved_provider'] ?? '' ) );
        if (
            empty( $settings['promotional_campaigns_enabled'] ) ||
            '' === $approved_provider ||
            $approved_provider !== sanitize_key( $provider ) ||
            '' === trim( (string) ( $settings['promotional_provider_approval_reference'] ?? '' ) ) ||
            '' === trim( (string) ( $settings['promotional_legal_review_reference'] ?? '' ) ) ||
            '' === trim( (string) ( $settings['promotional_approved_at'] ?? '' ) )
        ) {
            return new WP_Error( 'messageistic_promotional_approval_required', __( 'Promotional messaging requires explicit approval for the active provider and a recorded legal review.', 'messageistic' ) );
        }

        return true;
    }
}
