<?php
/**
 * Message classifications and consent requirements.
 *
 * @package Messageistic\Compliance
 */

namespace Messageistic\Compliance;

defined( 'ABSPATH' ) || exit;

final class Message_Classification {
    public const TRANSACTIONAL = 'transactional';
    public const MARKETING     = 'marketing';
    public const CUSTOMER_CARE = 'customer_care';
    public const SECURITY      = 'security';

    public static function all(): array {
        return [ self::TRANSACTIONAL, self::MARKETING, self::CUSTOMER_CARE, self::SECURITY ];
    }

    public static function normalize( string $classification ): string {
        $classification = sanitize_key( $classification );
        return in_array( $classification, self::all(), true ) ? $classification : self::TRANSACTIONAL;
    }

    public static function requires_marketing_consent( string $classification ): bool {
        return self::MARKETING === self::normalize( $classification );
    }
}
