<?php
/**
 * Database installer and version upgrade router.
 *
 * @package Messageistic\Core
 */

namespace Messageistic\Core;

use Messageistic\Database\Tables;
use Messageistic\Database\Consent_Event_Repository;

defined( 'ABSPATH' ) || exit;

final class Installer {

    public static function install(): void {
        Tables::install();
        self::migrate_legacy_consent();
        update_option( 'messageistic_db_version', MESSAGEISTIC_DB_VERSION, false );
    }

    private static function migrate_legacy_consent(): void {
        if ( get_option( 'messageistic_consent_migrated' ) ) {
            return;
        }
        global $wpdb;
        $contacts = $wpdb->prefix . 'messageistic_contacts';
        $rows = $wpdb->get_results( "SELECT id, normalized_phone, consent_source, consent_date FROM {$contacts} WHERE sms_consent = 1", ARRAY_A );
        foreach ( (array) $rows as $row ) {
            Consent_Event_Repository::record( [
                'contact_id'         => (int) $row['id'],
                'phone'              => (string) $row['normalized_phone'],
                'consent_type'       => 'transactional',
                'event_type'         => 'granted',
                'source'             => 'legacy_migration',
                'disclosure_version' => 'legacy-unverified',
                'disclosure_text'    => 'Migrated from the legacy sms_consent field; evidence should be reviewed before production use.',
                'evidence'           => [ 'legacy_source' => $row['consent_source'], 'legacy_date' => $row['consent_date'], 'review_required' => true ],
                'recorded_by'        => 0,
            ] );
        }
        update_option( 'messageistic_consent_migrated', 1, false );
    }

    public static function maybe_upgrade(): void {
        $installed = get_option( 'messageistic_db_version' );
        if ( $installed === MESSAGEISTIC_DB_VERSION ) {
            return;
        }
        self::install();
        Permissions::add_capabilities();
    }
}
