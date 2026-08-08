<?php
/**
 * Database schema installer.
 *
 * Defines all custom tables for Messageistic. Provider-specific external IDs
 * live on the relevant tables AND in the `provider_meta` table so a single
 * internal record can carry mappings to multiple providers over time.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Tables {

    public static function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix . 'messageistic_';

        $statements = [];

        $statements[] = "CREATE TABLE {$p}contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT UNSIGNED NULL,
            first_name VARCHAR(100) NOT NULL DEFAULT '',
            last_name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            normalized_phone VARCHAR(40) NOT NULL DEFAULT '',
            source VARCHAR(60) NOT NULL DEFAULT 'manual',
            tags TEXT NULL,
            customer_type VARCHAR(60) NOT NULL DEFAULT '',
            sms_consent TINYINT(1) NOT NULL DEFAULT 0,
            consent_source VARCHAR(60) NOT NULL DEFAULT '',
            consent_date DATETIME NULL,
            age_verified TINYINT(1) NOT NULL DEFAULT 0,
            age_verified_at DATETIME NULL,
            date_of_birth DATE NULL,
            opt_out TINYINT(1) NOT NULL DEFAULT 0,
            active_provider VARCHAR(40) NOT NULL DEFAULT '',
            provider_contact_id VARCHAR(190) NOT NULL DEFAULT '',
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY location_id (location_id),
            KEY normalized_phone (normalized_phone),
            KEY email (email),
            KEY source (source),
            KEY opt_out (opt_out)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            assigned_user_id BIGINT UNSIGNED NULL,
            last_message_at DATETIME NULL,
            unread_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY contact_id (contact_id),
            KEY location_id (location_id),
            KEY assigned_user_id (assigned_user_id),
            KEY status (status)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            campaign_id BIGINT UNSIGNED NULL,
            automation_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NULL,
            classification VARCHAR(30) NOT NULL DEFAULT 'transactional',
            idempotency_key VARCHAR(190) NULL,
            direction VARCHAR(10) NOT NULL DEFAULT 'outbound',
            body TEXT NOT NULL,
            from_number VARCHAR(40) NOT NULL DEFAULT '',
            to_number VARCHAR(40) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            provider_key VARCHAR(40) NOT NULL DEFAULT '',
            provider_message_id VARCHAR(190) NOT NULL DEFAULT '',
            provider_status VARCHAR(40) NOT NULL DEFAULT '',
            provider_raw_response LONGTEXT NULL,
            error_message TEXT NULL,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY conversation_id (conversation_id),
            KEY contact_id (contact_id),
            KEY campaign_id (campaign_id),
            KEY location_id (location_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY provider_message_id (provider_message_id),
            KEY direction_status (direction, status),
            KEY created_at (created_at)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}campaigns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            type VARCHAR(60) NOT NULL DEFAULT 'broadcast',
            classification VARCHAR(30) NOT NULL DEFAULT 'marketing',
            audience LONGTEXT NULL,
            provider_mode VARCHAR(40) NOT NULL DEFAULT 'queue',
            template_id BIGINT UNSIGNED NULL,
            body LONGTEXT NULL,
            schedule_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            delivered_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            replies_count INT UNSIGNED NOT NULL DEFAULT 0,
            optouts_count INT UNSIGNED NOT NULL DEFAULT 0,
            provider_key VARCHAR(40) NOT NULL DEFAULT '',
            provider_campaign_id VARCHAR(190) NOT NULL DEFAULT '',
            provider_payload LONGTEXT NULL,
            estimated_cost DECIMAL(10,4) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY location_id (location_id),
            KEY status (status),
            KEY schedule_at (schedule_at)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}campaign_recipients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            locked_at DATETIME NULL,
            lock_token VARCHAR(64) NOT NULL DEFAULT '',
            provider_message_id VARCHAR(190) NOT NULL DEFAULT '',
            error_message TEXT NULL,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_contact (campaign_id, contact_id),
            KEY status (status),
            KEY queue_ready (campaign_id, status, next_attempt_at)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'general',
            classification VARCHAR(30) NOT NULL DEFAULT 'transactional',
            body LONGTEXT NOT NULL,
            variables TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            approved_body_hash VARCHAR(64) NOT NULL DEFAULT '',
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            review_note TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY location_id (location_id),
            KEY category (category),
            KEY is_active (is_active),
            KEY review_status (review_status)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}automations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            trigger_key VARCHAR(80) NOT NULL DEFAULT '',
            conditions LONGTEXT NULL,
            delay_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            template_id BIGINT UNSIGNED NULL,
            actions LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            last_run_at DATETIME NULL,
            run_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY location_id (location_id),
            KEY trigger_key (trigger_key),
            KEY is_active (is_active)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(80) NOT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            contact_id BIGINT UNSIGNED NULL,
            message_id BIGINT UNSIGNED NULL,
            provider_key VARCHAR(40) NOT NULL DEFAULT '',
            provider_message_id VARCHAR(190) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT '',
            error_message TEXT NULL,
            raw_response LONGTEXT NULL,
            context LONGTEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY level (level),
            KEY provider_key (provider_key),
            KEY created_at (created_at)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}optouts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NULL,
            phone VARCHAR(40) NOT NULL DEFAULT '',
            normalized_phone VARCHAR(40) NOT NULL DEFAULT '',
            keyword VARCHAR(40) NOT NULL DEFAULT '',
            provider_key VARCHAR(40) NOT NULL DEFAULT '',
            source VARCHAR(40) NOT NULL DEFAULT '',
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY normalized_phone (normalized_phone),
            KEY contact_id (contact_id)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}provider_meta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(40) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            provider_key VARCHAR(40) NOT NULL,
            provider_object_id VARCHAR(190) NOT NULL DEFAULT '',
            meta_key VARCHAR(190) NOT NULL DEFAULT '',
            meta_value LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type, object_id, provider_key),
            KEY provider_object (provider_key, provider_object_id),
            KEY meta_key (meta_key)
        ) {$charset};";


        $statements[] = "CREATE TABLE {$p}consent_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            phone VARCHAR(40) NOT NULL DEFAULT '',
            consent_type VARCHAR(30) NOT NULL DEFAULT 'transactional',
            event_type VARCHAR(30) NOT NULL DEFAULT 'granted',
            source VARCHAR(60) NOT NULL DEFAULT 'manual',
            disclosure_version VARCHAR(100) NOT NULL DEFAULT '',
            disclosure_text LONGTEXT NULL,
            source_url TEXT NULL,
            ip_address VARCHAR(64) NOT NULL DEFAULT '',
            user_agent TEXT NULL,
            evidence LONGTEXT NULL,
            recorded_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY contact_type (contact_id, consent_type),
            KEY created_at (created_at)
        ) {$charset};";


        $statements[] = "CREATE TABLE {$p}locations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            code VARCHAR(60) NOT NULL,
            address TEXT NULL,
            city VARCHAR(100) NOT NULL DEFAULT '',
            state VARCHAR(10) NOT NULL DEFAULT '',
            postal_code VARCHAR(20) NOT NULL DEFAULT '',
            timezone VARCHAR(64) NOT NULL DEFAULT 'America/New_York',
            provider_key VARCHAR(40) NOT NULL DEFAULT '',
            sender VARCHAR(100) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY is_active (is_active)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}automation_steps (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            automation_id BIGINT UNSIGNED NOT NULL,
            step_order INT UNSIGNED NOT NULL DEFAULT 0,
            step_type VARCHAR(30) NOT NULL DEFAULT 'send_sms',
            delay_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            template_id BIGINT UNSIGNED NULL,
            config LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY automation_order (automation_id, step_order)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}automation_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            automation_id BIGINT UNSIGNED NOT NULL,
            automation_version INT UNSIGNED NOT NULL DEFAULT 1,
            contact_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            current_step INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            context LONGTEXT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY automation_status (automation_id, status),
            KEY contact_id (contact_id)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}conversation_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}conversation_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            old_value VARCHAR(190) NOT NULL DEFAULT '',
            new_value VARCHAR(190) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY event_type (event_type)
        ) {$charset};";

        $statements[] = "CREATE TABLE {$p}conversion_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            message_id BIGINT UNSIGNED NULL,
            campaign_id BIGINT UNSIGNED NULL,
            automation_id BIGINT UNSIGNED NULL,
            conversion_type VARCHAR(60) NOT NULL,
            external_id VARCHAR(190) NOT NULL DEFAULT '',
            value DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            metadata LONGTEXT NULL,
            converted_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_conversion (conversion_type, external_id),
            KEY contact_id (contact_id),
            KEY campaign_id (campaign_id),
            KEY automation_id (automation_id),
            KEY converted_at (converted_at)
        ) {$charset};";

        foreach ( $statements as $sql ) {
            dbDelta( $sql );
        }
    }
}
