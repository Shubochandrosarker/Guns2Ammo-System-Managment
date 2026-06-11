<?php
/**
 * Enqueues admin styles and scripts only on Messageistic pages.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin_Assets {

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue( string $hook ): void {
        if ( ! $this->is_messageistic_page( $hook ) ) {
            return;
        }

        wp_enqueue_style(
            'messageistic-admin',
            MESSAGEISTIC_URL . 'assets/css/admin.css',
            [],
            MESSAGEISTIC_VERSION
        );

        wp_enqueue_script(
            'messageistic-admin',
            MESSAGEISTIC_URL . 'assets/js/admin.js',
            [ 'wp-api-fetch' ],
            MESSAGEISTIC_VERSION,
            true
        );

        wp_localize_script(
            'messageistic-admin',
            'messageisticAdmin',
            [
                'restRoot' => esc_url_raw( rest_url( 'messageistic/v1/' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'i18n'     => [
                    'testing'             => __( 'Testing…', 'messageistic' ),
                    'testSuccess'         => __( 'Connection OK.', 'messageistic' ),
                    'testFailed'          => __( 'Connection failed: %s', 'messageistic' ),
                    'registering'         => __( 'Registering webhooks…', 'messageistic' ),
                    'webhooksRegistered'  => __( '%d webhooks registered on the device.', 'messageistic' ),
                    'webhooksFailed'      => __( 'Webhook registration failed: %s', 'messageistic' ),
                ],
            ]
        );
    }

    private function is_messageistic_page( string $hook ): bool {
        foreach ( Admin_Menu::page_slugs() as $slug ) {
            if ( str_contains( $hook, $slug ) ) {
                return true;
            }
        }
        return false;
    }
}
