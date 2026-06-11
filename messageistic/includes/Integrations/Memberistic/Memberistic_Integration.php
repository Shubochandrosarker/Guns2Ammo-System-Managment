<?php
/**
 * Memberistic → Messageistic bridge.
 *
 * Listens to the lifecycle hooks Memberistic actually fires — they pass a
 * membership ID, not a member array — hydrates the primary person + plan
 * from the Memberistic tables, then emits a `messageistic_trigger` for the
 * Automation engine.
 *
 * The whole bridge is gated by the "SMS Notifications (Messageistic)"
 * module toggle on the Memberistic → Integrations screen, so staff control
 * membership texting from one place.
 *
 * @package Messageistic\Integrations\Memberistic
 */

namespace Messageistic\Integrations\Memberistic;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class Memberistic_Integration {

    public function register(): void {
        add_action( 'memberistic_membership_created',   [ $this, 'on_created' ], 10, 1 );
        add_action( 'memberistic_membership_activated', [ $this, 'on_activated' ], 10, 1 );
        add_action( 'memberistic_membership_expiring',  [ $this, 'on_expiring' ], 10, 2 );
        add_action( 'memberistic_membership_expired',   [ $this, 'on_expired' ], 10, 1 );
    }

    public function on_created( $membership_id ): void {
        $this->emit( 'membership.created', (int) $membership_id );
    }

    public function on_activated( $membership_id ): void {
        $this->emit( 'membership.activated', (int) $membership_id );
    }

    public function on_expiring( $membership_id, $days_left = 0 ): void {
        $this->emit( 'membership.expiring_soon', (int) $membership_id, [ 'days_left' => (string) (int) $days_left ] );
    }

    public function on_expired( $membership_id ): void {
        $this->emit( 'membership.expired', (int) $membership_id );
    }

    /**
     * Membership SMS is controlled from Memberistic → Integrations →
     * "SMS Notifications (Messageistic)". Default off.
     */
    private function enabled(): bool {
        $registry = '\\WordPressistic\\Memberistic\\Integrations\\Integrations_Registry';
        if ( class_exists( $registry ) && method_exists( $registry, 'is_enabled' ) ) {
            return (bool) $registry::is_enabled( 'sms_reminders' );
        }
        return false;
    }

    private function emit( string $trigger, int $membership_id, array $extra = [] ): void {
        if ( $membership_id <= 0 || ! $this->enabled() ) {
            return;
        }

        $member = $this->hydrate( $membership_id );
        if ( ! $member || '' === $member['phone'] ) {
            return;
        }

        $cc         = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $contact_id = Contact_Repository::upsert_by_phone( [
            'first_name'    => $member['first_name'],
            'last_name'     => $member['last_name'],
            'email'         => $member['email'],
            'phone'         => $member['phone'],
            'source'        => 'memberistic',
            'customer_type' => 'member',
        ], $cc );

        do_action( 'messageistic_trigger', $trigger, [
            'contact_id'    => $contact_id,
            'membership_id' => $membership_id,
            'extras'        => array_merge( [
                'membership_name'   => $member['plan'],
                'membership_status' => $member['status'],
                'membership_expiry' => $member['expires_at'],
            ], $extra ),
        ] );
    }

    /**
     * Load primary person + plan for a membership straight from the
     * Memberistic tables (the hooks only carry the ID).
     *
     * @return array{first_name:string,last_name:string,email:string,phone:string,plan:string,status:string,expires_at:string}|null
     */
    private function hydrate( int $membership_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.status, m.renewal_date, m.end_date, pl.name AS plan_name,
                    p.full_name, p.email, p.phone
             FROM {$wpdb->prefix}memberistic_memberships m
             LEFT JOIN {$wpdb->prefix}memberistic_plans pl ON pl.id = m.plan_id
             LEFT JOIN {$wpdb->prefix}memberistic_people p
                    ON p.membership_id = m.id AND p.role = 'primary'
             WHERE m.id = %d
             LIMIT 1",
            $membership_id
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        // Fall back to the first linked person when no explicit primary exists.
        if ( empty( $row['full_name'] ) && empty( $row['phone'] ) ) {
            $person = $wpdb->get_row( $wpdb->prepare(
                "SELECT full_name, email, phone FROM {$wpdb->prefix}memberistic_people
                 WHERE membership_id = %d ORDER BY id ASC LIMIT 1",
                $membership_id
            ), ARRAY_A );
            if ( $person ) {
                $row = array_merge( $row, $person );
            }
        }

        $full  = trim( (string) ( $row['full_name'] ?? '' ) );
        $parts = '' === $full ? [] : preg_split( '/\s+/', $full );
        $first = $parts ? array_shift( $parts ) : '';
        $last  = $parts ? implode( ' ', $parts ) : '';

        return [
            'first_name' => (string) $first,
            'last_name'  => (string) $last,
            'email'      => (string) ( $row['email'] ?? '' ),
            'phone'      => (string) ( $row['phone'] ?? '' ),
            'plan'       => (string) ( $row['plan_name'] ?? '' ),
            'status'     => (string) ( $row['status'] ?? '' ),
            'expires_at' => (string) ( $row['end_date'] ?: ( $row['renewal_date'] ?? '' ) ),
        ];
    }
}
