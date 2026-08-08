<?php
/**
 * Shared functionality for concrete providers.
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

use Messageistic\Helpers\Secret_Store;

defined( 'ABSPATH' ) || exit;

abstract class Abstract_Provider implements SMS_Provider_Interface {

    /**
     * Read this provider's stored settings.
     *
     * @return array<string,mixed>
     */
    public function get_settings(): array {
        $all = get_option( 'messageistic_provider_settings', [] );
        $key = $this->get_key();
        return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? Secret_Store::decrypt_array( $all[ $key ] ) : [];
    }

    public function get_capabilities(): array {
        return [
            'send_sms'         => false,
            'receive_replies'  => false,
            'delivery_status'  => false,
            'campaign_push'    => false,
            'contact_push'     => false,
            'optout_handling'  => false,
            'mms'              => false,
            'email'            => false,
        ];
    }

    /**
     * Normalize a provider response into the shape downstream code expects.
     *
     * @param array<string,mixed> $data
     */
    protected function normalize( array $data ): array {
        return wp_parse_args(
            $data,
            [
                'provider_key'        => $this->get_key(),
                'provider_message_id' => '',
                'status'              => 'queued',
                'raw'                 => null,
            ]
        );
    }

    public function handle_inbound( array $payload ) {
        return $this->normalize( [ 'status' => 'received', 'raw' => $payload ] );
    }

    public function handle_status_callback( array $payload ) {
        return $this->normalize( [ 'status' => 'unknown', 'raw' => $payload ] );
    }
}
