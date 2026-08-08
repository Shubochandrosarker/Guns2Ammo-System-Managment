<?php
/**
 * Activation handler — installs tables and seeds defaults.
 *
 * @package Messageistic\Core
 */

namespace Messageistic\Core;

defined( 'ABSPATH' ) || exit;

final class Activator {

    public static function activate(): void {
        Installer::install();
        Permissions::add_capabilities();

        if ( ! get_option( 'messageistic_active_provider' ) ) {
            update_option( 'messageistic_active_provider', 'testing', false );
        }

        if ( ! get_option( 'messageistic_general_settings' ) ) {
            update_option(
                'messageistic_general_settings',
                [
                    'business_name'           => get_bloginfo( 'name' ),
                    'business_phone'          => '',
                    'business_location'       => '',
                    'default_country_code'    => '+1',
                    'default_timezone'        => wp_timezone_string(),
                    'admin_notification_email' => get_option( 'admin_email' ),
                    'quiet_hours_start'       => '21:00',
                    'quiet_hours_end'         => '08:00',
                    'daily_sending_limit'     => 1000,
                    'contact_daily_limit'     => 3,
                    'industry'                => 'general',
                    'approved_firearm_providers' => '',
                    'firearm_marketing_enabled' => 0,
                    'minimum_verified_age'    => 21,
                    'message_retention_days'  => 365,
                    'log_retention_days'      => 90,
                    'consent_retention_days'  => 2555,
                    'pilot_mode_enabled'      => 0,
                    'pilot_location_name'     => '',
                    'pilot_location_state'    => '',
                    'pilot_country'           => 'US',
                    'pilot_provider'          => '',
                    'pilot_sender'            => '',
                    'pilot_approval_reference' => '',
                    'pilot_daily_limit'       => 100,
                    'promotional_campaigns_enabled' => 0,
                    'promotional_approved_provider' => '',
                    'promotional_provider_approval_reference' => '',
                    'promotional_legal_review_reference' => '',
                    'promotional_approved_at' => '',
                ],
                false
            );
        }

        if ( ! wp_next_scheduled( 'messageistic_provider_health_check' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'messageistic_provider_health_check' );
        }
    }
}
