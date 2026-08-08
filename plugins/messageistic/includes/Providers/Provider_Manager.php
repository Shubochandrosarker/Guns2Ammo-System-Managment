<?php
/**
 * Provider Manager — registers, selects, and routes through SMS providers.
 *
 * @package Messageistic\Providers
 */

namespace Messageistic\Providers;

use Messageistic\Helpers\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Provider_Manager {

    /** @var array<string,SMS_Provider_Interface> */
    private array $providers = [];

    public function register_defaults(): void {
        $this->register( new Testing_Provider() );
        $this->register( new OtterText_Provider() );
        $this->register( new Twilio_Provider() );
        $this->register( new Jasmin_Provider() );
        $this->register( new SMSGate_Provider() );

        // Side effects of an active-provider switch live on the option's own
        // update action so every write path (Settings form, REST, WP-CLI)
        // triggers them exactly once — and never from inside the option's
        // sanitize callback, where an update_option() call would recurse.
        add_action( 'update_option_messageistic_active_provider', [ $this, 'on_active_provider_changed' ], 10, 2 );

        /**
         * Filter the registered providers. Third-party code may add adapters here.
         *
         * @param Provider_Manager $manager
         */
        do_action( 'messageistic_register_providers', $this );
    }

    public function register( SMS_Provider_Interface $provider ): void {
        $this->providers[ $provider->get_key() ] = $provider;
    }

    /** @return array<string,SMS_Provider_Interface> */
    public function all(): array {
        return $this->providers;
    }

    public function get( string $key ): ?SMS_Provider_Interface {
        return $this->providers[ $key ] ?? null;
    }

    public function get_active_key(): string {
        $key = (string) get_option( 'messageistic_active_provider', 'testing' );
        return isset( $this->providers[ $key ] ) ? $key : 'testing';
    }

    public function get_active(): SMS_Provider_Interface {
        return $this->providers[ $this->get_active_key() ] ?? $this->providers['testing'];
    }

    /**
     * Switch the active provider. Logging and health-cache invalidation fire
     * via on_active_provider_changed() when the option actually changes.
     *
     * @return true|WP_Error
     */
    public function set_active( string $key, ?int $actor_user_id = null ) {
        if ( ! isset( $this->providers[ $key ] ) ) {
            return new WP_Error( 'messageistic_unknown_provider', __( 'Unknown provider.', 'messageistic' ) );
        }
        if ( $this->get_active_key() === $key ) {
            return true;
        }
        update_option( 'messageistic_active_provider', $key, false );
        return true;
    }

    /**
     * update_option_messageistic_active_provider callback.
     *
     * @param mixed $old_value
     * @param mixed $value
     */
    public function on_active_provider_changed( $old_value, $value ): void {
        Logger::log(
            'provider.switched',
            [
                'level'   => 'info',
                'status'  => 'switched',
                'context' => [ 'from' => (string) $old_value, 'to' => (string) $value ],
                'user_id' => get_current_user_id(),
            ]
        );
        delete_option( 'messageistic_provider_health' );
    }

    /**
     * Persist a provider's settings (after the provider validates them).
     *
     * @param array<string,mixed> $settings
     * @return true|WP_Error
     */
    public function save_settings( string $key, array $settings ) {
        $provider = $this->get( $key );
        if ( ! $provider ) {
            return new WP_Error( 'messageistic_unknown_provider', __( 'Unknown provider.', 'messageistic' ) );
        }
        $validated = $provider->validate_settings( $settings );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }
        $all         = get_option( 'messageistic_provider_settings', [] );
        $all[ $key ] = $settings;
        update_option( 'messageistic_provider_settings', $all, false );
        return true;
    }

    /**
     * Route an outbound send through the active provider.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function send( array $payload ) {
        $provider = $this->get_active();
        $result   = $provider->send_sms( $payload );

        if ( is_wp_error( $result ) ) {
            Logger::log(
                'sms.send_failed',
                [
                    'level'         => 'error',
                    'provider_key'  => $provider->get_key(),
                    'status'        => 'failed',
                    'error_message' => $result->get_error_message(),
                    'context'       => $payload,
                ]
            );
            return $result;
        }

        Logger::log(
            'sms.sent',
            [
                'provider_key'        => $provider->get_key(),
                'provider_message_id' => $result['provider_message_id'] ?? '',
                'status'              => $result['status'] ?? 'queued',
                'context'             => $payload,
                'raw_response'        => $result['raw'] ?? null,
            ]
        );

        return $result;
    }

    /**
     * Cached health snapshot used by the dashboard card.
     *
     * @return array{key:string,label:string,status:string,message:string,capabilities:array<string,mixed>}
     */
    public function get_health(): array {
        $cached = get_option( 'messageistic_provider_health' );
        if ( is_array( $cached ) && ! empty( $cached['cached_at'] ) && ( time() - (int) $cached['cached_at'] ) < 5 * MINUTE_IN_SECONDS ) {
            return $cached;
        }

        $provider = $this->get_active();
        $settings = $provider instanceof Abstract_Provider ? $provider->get_settings() : [];
        $validate = $provider->validate_settings( $settings );

        if ( 'testing' === $provider->get_key() ) {
            $status  = 'simulation';
            $message = __( 'Testing Provider Active — No real SMS sent.', 'messageistic' );
        } elseif ( is_wp_error( $validate ) ) {
            $status  = 'missing_settings';
            $message = $validate->get_error_message();
        } else {
            $status  = 'configured';
            $message = __( 'Provider configured. Run Test Connection to confirm.', 'messageistic' );
        }

        $health = [
            'key'          => $provider->get_key(),
            'label'        => $provider->get_label(),
            'status'       => $status,
            'message'      => $message,
            'capabilities' => $provider->get_capabilities(),
            'cached_at'    => time(),
        ];

        update_option( 'messageistic_provider_health', $health, false );
        return $health;
    }
}
