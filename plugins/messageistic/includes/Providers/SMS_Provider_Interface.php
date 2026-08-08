<?php
/**
 * Common contract every SMS provider must implement.
 *
 * The Provider Manager talks only to this interface; no Messageistic core
 * module imports a concrete provider class directly. This keeps the core
 * provider-independent.
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface SMS_Provider_Interface {

    public function get_key(): string;

    public function get_label(): string;

    /**
     * Capability matrix.
     *
     * Keys: send_sms, receive_replies, delivery_status, campaign_push,
     * contact_push, optout_handling, mms, email.
     * Values: bool|string ("yes"/"if_api_supports"/"simulated"/"no").
     *
     * @return array<string,bool|string>
     */
    public function get_capabilities(): array;

    /**
     * Validate stored settings for this provider.
     *
     * @param array<string,mixed> $settings
     * @return true|WP_Error
     */
    public function validate_settings( array $settings );

    /**
     * Probe the provider with the given (already-validated) settings.
     *
     * @param array<string,mixed> $settings
     * @return true|WP_Error
     */
    public function test_connection( array $settings );

    /**
     * Send an outbound SMS.
     *
     * Returns normalized payload:
     * [
     *   'provider_key'        => string,
     *   'provider_message_id' => string,
     *   'status'              => string (queued|sent|delivered|failed|test_sent),
     *   'raw'                 => mixed,
     * ]
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function send_sms( array $payload );

    /**
     * Handle an inbound webhook delivered by the provider.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function handle_inbound( array $payload );

    /**
     * Handle a delivery status callback delivered by the provider.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function handle_status_callback( array $payload );
}
