<?php
/**
 * Capability registry for Messageistic.
 *
 * @package Messageistic\Core
 */

namespace Messageistic\Core;

defined( 'ABSPATH' ) || exit;

final class Permissions {

    public const MANAGE           = 'manage_messageistic';
    public const VIEW             = 'view_messageistic';
    public const SEND_SMS         = 'send_messageistic_sms';
    public const MANAGE_CAMPAIGNS = 'manage_messageistic_campaigns';
    public const MANAGE_SETTINGS  = 'manage_messageistic_settings';
    public const MANAGE_PROVIDERS = 'manage_messageistic_providers';
    public const MANAGE_INBOX     = 'manage_messageistic_inbox';

    public static function all(): array {
        return [
            self::MANAGE,
            self::VIEW,
            self::SEND_SMS,
            self::MANAGE_CAMPAIGNS,
            self::MANAGE_SETTINGS,
            self::MANAGE_PROVIDERS,
            self::MANAGE_INBOX,
        ];
    }

    public static function add_capabilities(): void {
        $role = get_role( 'administrator' );
        if ( ! $role ) {
            return;
        }
        foreach ( self::all() as $cap ) {
            $role->add_cap( $cap );
        }
    }
}
