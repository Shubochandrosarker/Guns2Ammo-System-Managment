<?php
/**
 * Settings page — General + Provider selector + per-provider settings.
 *
 * Uses the Settings API for general settings and per-provider option groups.
 * Each provider's settings go through the provider's own validator before save.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Core\Plugin;
use Messageistic\Helpers\Sanitizer;
use Messageistic\Helpers\Secret_Store;

defined( 'ABSPATH' ) || exit;

final class Settings_Page {

    public const OPTION_GENERAL          = 'messageistic_general_settings';
    public const OPTION_ACTIVE_PROVIDER  = 'messageistic_active_provider';
    public const OPTION_PROVIDER_SETTINGS = 'messageistic_provider_settings';

    public static function register_settings(): void {
        register_setting(
            'messageistic_general',
            self::OPTION_GENERAL,
            [
                'type'              => 'array',
                'sanitize_callback' => [ self::class, 'sanitize_general' ],
                'default'           => [],
            ]
        );

        register_setting(
            'messageistic_provider',
            self::OPTION_ACTIVE_PROVIDER,
            [
                'type'              => 'string',
                'sanitize_callback' => [ self::class, 'sanitize_active_provider' ],
                'default'           => 'testing',
            ]
        );

        register_setting(
            'messageistic_provider',
            self::OPTION_PROVIDER_SETTINGS,
            [
                'type'              => 'array',
                'sanitize_callback' => [ self::class, 'sanitize_provider_settings' ],
                'default'           => [],
            ]
        );
    }

    public static function sanitize_general( $input ): array {
        if ( ! is_array( $input ) ) {
            $input = [];
        }
        $sanitized = Sanitizer::fields(
            $input,
            [
                'business_name'            => 'text',
                'business_phone'           => 'tel',
                'business_location'        => 'text',
                'default_country_code'     => 'text',
                'default_timezone'         => 'text',
                'admin_notification_email' => 'email',
                'quiet_hours_start'        => 'text',
                'quiet_hours_end'          => 'text',
                'daily_sending_limit'      => 'int',
                'contact_daily_limit'      => 'int',
                'industry'                 => 'key',
                'approved_firearm_providers' => 'text',
                'firearm_marketing_enabled' => 'bool',
                'minimum_verified_age'     => 'int',
                'message_retention_days'   => 'int',
                'log_retention_days'       => 'int',
                'consent_retention_days'   => 'int',
                'pilot_mode_enabled'       => 'bool',
                'pilot_location_name'      => 'text',
                'pilot_location_state'     => 'text',
                'pilot_country'            => 'text',
                'pilot_provider'           => 'key',
                'pilot_sender'             => 'text',
                'pilot_approval_reference' => 'text',
                'pilot_daily_limit'        => 'int',
                'promotional_campaigns_enabled' => 'bool',
                'promotional_approved_provider' => 'key',
                'promotional_provider_approval_reference' => 'text',
                'promotional_legal_review_reference' => 'text',
                'promotional_approved_at' => 'text',
            ]
        );
        $sanitized['pilot_country']     = strtoupper( (string) $sanitized['pilot_country'] );
        $sanitized['pilot_daily_limit'] = max( 100, min( 500, (int) $sanitized['pilot_daily_limit'] ) );
        if ( ! empty( $sanitized['pilot_mode_enabled'] ) ) {
            $sanitized['daily_sending_limit'] = $sanitized['pilot_daily_limit'];
            $sanitized['firearm_marketing_enabled'] = 0;
        }
        return $sanitized;
    }

    public static function sanitize_active_provider( $input ): string {
        $providers = Plugin::instance()->providers();
        $key       = sanitize_key( (string) $input );
        if ( ! $providers->get( $key ) ) {
            add_settings_error( self::OPTION_ACTIVE_PROVIDER, 'unknown_provider', __( 'Unknown provider selected.', 'messageistic' ) );
            return $providers->get_active_key();
        }
        // Validate only — never write the option being sanitized here.
        // update_option() runs this sanitizer, so calling set_active() (which
        // updates this same option) from here recurses until PHP dies. Switch
        // side effects run on the update_option_{option} action instead.
        return $key;
    }

    public static function sanitize_provider_settings( $input ): array {
        $stored = get_option( self::OPTION_PROVIDER_SETTINGS, [] );
        $out    = is_array( $stored ) ? $stored : [];
        if ( ! is_array( $input ) ) {
            return $out;
        }

        if ( isset( $input['ottertext'] ) && is_array( $input['ottertext'] ) ) {
            $ottertext = Sanitizer::fields(
                $input['ottertext'],
                [
                    'api_base_url'           => 'url',
                    'api_key'                => 'text',
                    'account_id'             => 'text',
                    'location_id'            => 'text',
                    'default_list_id'        => 'text',
                    'default_campaign_id'    => 'text',
                    'webhook_secret'         => 'text',
                    'enable_contact_sync'    => 'bool',
                    'enable_message_sending' => 'bool',
                    'enable_inbound_sync'    => 'bool',
                    'enable_campaign_push'   => 'bool',
                ]
            );
            $out['ottertext'] = self::preserve_secrets(
                $ottertext,
                is_array( $out['ottertext'] ?? null ) ? $out['ottertext'] : [],
                [ 'api_key', 'webhook_secret' ]
            );
        }

        if ( isset( $input['twilio'] ) && is_array( $input['twilio'] ) ) {
            $twilio = Sanitizer::fields(
                $input['twilio'],
                [
                    'account_sid'           => 'text',
                    'auth_token'            => 'text',
                    'messaging_service_sid' => 'text',
                    'from_number'           => 'tel',
                    'validate_signature'    => 'bool',
                ]
            );
            $twilio['validate_signature'] = true;
            $out['twilio'] = self::preserve_secrets(
                $twilio,
                is_array( $out['twilio'] ?? null ) ? $out['twilio'] : [],
                [ 'auth_token' ]
            );
        }

        if ( isset( $input['jasmin'] ) && is_array( $input['jasmin'] ) ) {
            $jasmin = Sanitizer::fields(
                $input['jasmin'],
                [
                    'base_url'           => 'url',
                    'username'           => 'text',
                    'password'           => 'text',
                    'default_sender'     => 'text',
                    'coding'             => 'text',
                    'webhook_secret'     => 'text',
                    'validate_signature' => 'bool',
                ]
            );
            $coding = (string) ( $jasmin['coding'] ?? '' );
            $jasmin['coding'] = in_array( $coding, [ '0', '8' ], true ) ? $coding : '0';
            $out['jasmin'] = self::preserve_secrets(
                $jasmin,
                is_array( $out['jasmin'] ?? null ) ? $out['jasmin'] : [],
                [ 'password', 'webhook_secret' ]
            );
        }

        if ( isset( $input['smsgate'] ) && is_array( $input['smsgate'] ) ) {
            $smsgate = Sanitizer::fields(
                $input['smsgate'],
                [
                    'base_url'             => 'url',
                    'username'             => 'text',
                    'password'             => 'text',
                    'sim_number'           => 'int',
                    'with_delivery_report' => 'bool',
                    'webhook_secret'       => 'text',
                    'signing_key'          => 'text',
                    'validate_signature'   => 'bool',
                ]
            );
            $smsgate['sim_number'] = max( 0, min( 3, (int) $smsgate['sim_number'] ) );
            $out['smsgate'] = self::preserve_secrets(
                $smsgate,
                is_array( $out['smsgate'] ?? null ) ? $out['smsgate'] : [],
                [ 'password', 'webhook_secret', 'signing_key' ]
            );
        }

        if ( isset( $input['testing'] ) && is_array( $input['testing'] ) ) {
            $out['testing'] = Sanitizer::fields(
                $input['testing'],
                [
                    'fake_delivery_status' => 'bool',
                    'fake_delivery_delay'  => 'int',
                    'fake_reply_simulation' => 'bool',
                    'show_testing_banner'  => 'bool',
                ]
            );
        }

        return $out;
    }

    /**
     * Keep an existing credential when its password field is submitted blank.
     *
     * @param array<string,mixed> $sanitized
     * @param array<string,mixed> $stored
     * @param array<int,string>   $keys
     * @return array<string,mixed>
     */
    private static function preserve_secrets( array $sanitized, array $stored, array $keys ): array {
        foreach ( $keys as $key ) {
            if ( '' === (string) ( $sanitized[ $key ] ?? '' ) && isset( $stored[ $key ] ) ) {
                $sanitized[ $key ] = $stored[ $key ];
            } elseif ( '' !== (string) ( $sanitized[ $key ] ?? '' ) ) {
                $sanitized[ $key ] = Secret_Store::encrypt( (string) $sanitized[ $key ] );
            }
        }
        return $sanitized;
    }

    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        $providers = Plugin::instance()->providers();
        $general   = (array) get_option( self::OPTION_GENERAL, [] );
        $active    = $providers->get_active_key();
        // Decrypt for display: webhook URLs embed the plaintext secret, and the
        // encrypted blob would never match what providers compare on callbacks.
        // Password fields never echo stored values, so nothing secret leaks
        // beyond the intentional ?token=… shown to the administrator.
        $all_set   = Secret_Store::decrypt_array( (array) get_option( self::OPTION_PROVIDER_SETTINGS, [] ) );

        include MESSAGEISTIC_DIR . 'templates/admin/settings.php';
    }
}
