<?php
/**
 * CRMistic → Messageistic bridge.
 *
 * Two-way: pulls contacts in, pushes notes out via filter hook for CRMistic
 * to consume.
 *
 * @package Messageistic\Integrations\CRMistic
 */

namespace Messageistic\Integrations\CRMistic;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class CRMistic_Integration {

    public function register(): void {
        add_action( 'crmistic_contact_created', [ $this, 'on_contact_created' ], 10, 1 );
        add_action( 'crmistic_contact_updated', [ $this, 'on_contact_created' ], 10, 1 );
    }

    public function on_contact_created( array $contact ): void {
        $cc = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        Contact_Repository::upsert_by_phone( [
            'first_name'    => (string) ( $contact['first_name'] ?? '' ),
            'last_name'     => (string) ( $contact['last_name'] ?? '' ),
            'email'         => (string) ( $contact['email'] ?? '' ),
            'phone'         => (string) ( $contact['phone'] ?? '' ),
            'source'        => 'crmistic',
            'customer_type' => (string) ( $contact['type'] ?? '' ),
        ], $cc );
    }
}
