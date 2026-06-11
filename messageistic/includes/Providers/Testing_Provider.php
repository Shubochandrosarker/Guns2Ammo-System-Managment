<?php
/**
 * Testing / Sandbox provider — simulates sends, never calls an external API.
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

defined( 'ABSPATH' ) || exit;

final class Testing_Provider extends Abstract_Provider {

    public function get_key(): string {
        return 'testing';
    }

    public function get_label(): string {
        return __( 'Testing Mode', 'messageistic' );
    }

    public function get_capabilities(): array {
        return [
            'send_sms'         => 'simulated',
            'receive_replies'  => 'simulated',
            'delivery_status'  => 'simulated',
            'campaign_push'    => 'simulated',
            'contact_push'     => 'simulated',
            'optout_handling'  => 'simulated',
            'mms'              => 'simulated',
            'email'            => 'simulated',
        ];
    }

    public function validate_settings( array $settings ) {
        return true;
    }

    public function test_connection( array $settings ) {
        return true;
    }

    public function send_sms( array $payload ) {
        return $this->normalize(
            [
                'provider_message_id' => 'test_' . wp_generate_uuid4(),
                'status'              => 'test_sent',
                'raw'                 => [
                    'simulated' => true,
                    'payload'   => $payload,
                ],
            ]
        );
    }
}
