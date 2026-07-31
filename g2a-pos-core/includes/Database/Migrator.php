<?php

namespace G2A\POS\Database;

use G2A\POS\Compliance\State\StateSeeder;

final class Migrator {

	public static function run(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql   = array();
		$sql[] = "CREATE TABLE {$p}g2a_pos_orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wc_order_id BIGINT UNSIGNED NULL,
            register_session_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL,
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            discount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            payment_status VARCHAR(40) NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(80) NULL,
            compliance_state VARCHAR(40) NOT NULL DEFAULT 'pending_review',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_status (status),
            KEY idx_location (location_id),
            KEY idx_created (created_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_pos_order_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pos_order_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NULL,
            sku VARCHAR(100) NULL,
            serial_number VARCHAR(120) NULL,
            item_type VARCHAR(40) NOT NULL,
            quantity DECIMAL(10,3) NOT NULL,
            unit_price DECIMAL(14,2) NOT NULL,
            line_total DECIMAL(14,2) NOT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_order (pos_order_id),
            KEY idx_product (product_id),
            KEY idx_serial (serial_number)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_inventory_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NULL,
            serial_number VARCHAR(120) NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            quantity_delta DECIMAL(10,3) NOT NULL,
            before_qty DECIMAL(10,3) NOT NULL,
            after_qty DECIMAL(10,3) NOT NULL,
            source_ref VARCHAR(120) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_product_location (product_id, location_id),
            KEY idx_event (event_type),
            KEY idx_created (created_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_serial_registry (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            serial_number VARCHAR(120) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            manufacturer VARCHAR(120) NULL,
            acquisition_date DATETIME NULL,
            disposition_date DATETIME NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'in_stock',
            location_id BIGINT UNSIGNED NOT NULL,
            transfer_state VARCHAR(50) NOT NULL DEFAULT 'none',
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_serial (serial_number),
            KEY idx_status (status),
            KEY idx_location (location_id)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_register_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            register_code VARCHAR(50) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            opened_by BIGINT UNSIGNED NOT NULL,
            closed_by BIGINT UNSIGNED NULL,
            opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
            closing_cash DECIMAL(14,2) NULL,
            expected_cash DECIMAL(14,2) NULL,
            variance_amount DECIMAL(14,2) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'open',
            opened_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            notes TEXT NULL,
            KEY idx_status (status),
            KEY idx_register (register_code)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_cash_movements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            register_session_id BIGINT UNSIGNED NOT NULL,
            movement_type VARCHAR(40) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            reason VARCHAR(255) NULL,
            approved_by BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_session (register_session_id),
            KEY idx_type (movement_type)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_compliance_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            rule_code VARCHAR(80) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            status VARCHAR(30) NOT NULL,
            details LONGTEXT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_entity (entity_type, entity_id),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(120) NOT NULL,
            object_type VARCHAR(80) NOT NULL,
            object_id VARCHAR(120) NOT NULL,
            before_data LONGTEXT NULL,
            after_data LONGTEXT NULL,
            ip_address VARCHAR(60) NULL,
            user_agent VARCHAR(255) NULL,
            prev_hash CHAR(64) NULL,
            row_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_actor (actor_id),
            KEY idx_action (action),
            KEY idx_created (created_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_sync_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            queue_type VARCHAR(60) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            run_after DATETIME NOT NULL,
            locked_at DATETIME NULL,
            processed_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_status_run (status, run_after),
            KEY idx_type (queue_type)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_barcodes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NULL,
            serial_number VARCHAR(120) NULL,
            barcode_value VARCHAR(160) NOT NULL,
            barcode_type VARCHAR(40) NOT NULL DEFAULT 'code128',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_barcode (barcode_value),
            KEY idx_product (product_id)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_atf_bound_book (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entry_number BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(20) NOT NULL,
            manufacturer VARCHAR(120) NOT NULL,
            importer VARCHAR(120) NULL,
            model VARCHAR(120) NOT NULL,
            serial_number VARCHAR(120) NOT NULL,
            firearm_type VARCHAR(40) NOT NULL,
            caliber VARCHAR(40) NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            acq_date DATE NULL,
            acq_source_name VARCHAR(200) NULL,
            acq_source_address VARCHAR(255) NULL,
            acq_source_ffl VARCHAR(40) NULL,
            disp_date DATE NULL,
            disp_buyer_name VARCHAR(200) NULL,
            disp_buyer_address VARCHAR(255) NULL,
            disp_buyer_ffl VARCHAR(40) NULL,
            disp_form4473_id BIGINT UNSIGNED NULL,
            disp_pos_order_id BIGINT UNSIGNED NULL,
            disp_method VARCHAR(40) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            prev_hash CHAR(64) NULL,
            row_hash CHAR(64) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_entry_serial (entry_number, serial_number),
            KEY idx_serial (serial_number),
            KEY idx_acq_date (acq_date),
            KEY idx_disp_date (disp_date),
            KEY idx_entry_type (entry_type)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_form_4473 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_serial VARCHAR(40) NOT NULL,
            transaction_status VARCHAR(40) NOT NULL DEFAULT 'in_progress',
            pos_order_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            transferee_last VARCHAR(120) NOT NULL,
            transferee_first VARCHAR(120) NOT NULL,
            transferee_middle VARCHAR(120) NULL,
            transferee_suffix VARCHAR(20) NULL,
            transferee_dob DATE NOT NULL,
            transferee_place_of_birth VARCHAR(160) NULL,
            transferee_address VARCHAR(255) NOT NULL,
            transferee_city VARCHAR(120) NOT NULL,
            transferee_state CHAR(2) NOT NULL,
            transferee_zip VARCHAR(20) NOT NULL,
            transferee_county VARCHAR(120) NULL,
            transferee_phone VARCHAR(40) NULL,
            transferee_email VARCHAR(160) NULL,
            transferee_height_in SMALLINT NULL,
            transferee_weight_lb SMALLINT NULL,
            transferee_sex CHAR(1) NULL,
            transferee_ethnicity VARCHAR(40) NULL,
            transferee_race VARCHAR(80) NULL,
            us_citizen TINYINT(1) NOT NULL DEFAULT 1,
            country_of_citizenship VARCHAR(80) NULL,
            uscis_number VARCHAR(40) NULL,
            i94_number VARCHAR(40) NULL,
            id_type VARCHAR(40) NOT NULL,
            id_number VARCHAR(80) NOT NULL,
            id_issuer VARCHAR(80) NOT NULL,
            id_expiration DATE NULL,
            supplemental_id_type VARCHAR(40) NULL,
            supplemental_id_number VARCHAR(80) NULL,
            section_b_answers LONGTEXT NULL,
            firearms LONGTEXT NULL,
            transaction_type VARCHAR(40) NOT NULL DEFAULT 'over_counter',
            is_private_sale TINYINT(1) NOT NULL DEFAULT 0,
            is_pawn_redemption TINYINT(1) NOT NULL DEFAULT 0,
            is_loan_pledge TINYINT(1) NOT NULL DEFAULT 0,
            nics_transaction_id BIGINT UNSIGNED NULL,
            nics_ntn VARCHAR(40) NULL,
            nics_response VARCHAR(30) NULL,
            nics_response_date DATETIME NULL,
            default_proceed_eligible_at DATETIME NULL,
            nics_exempt TINYINT(1) NOT NULL DEFAULT 0,
            nics_exempt_reason VARCHAR(120) NULL,
            nics_exempt_permit_type VARCHAR(40) NULL,
            nics_exempt_permit_number VARCHAR(80) NULL,
            nics_exempt_permit_state CHAR(2) NULL,
            nics_exempt_permit_issued_on DATE NULL,
            nics_exempt_permit_expires_on DATE NULL,
            nics_exempt_recorded_by BIGINT UNSIGNED NULL,
            nics_exempt_recorded_at DATETIME NULL,
            transferred_at DATETIME NULL,
            transferor_user_id BIGINT UNSIGNED NULL,
            transferor_certification_signed TINYINT(1) NOT NULL DEFAULT 0,
            transferee_certification_signed TINYINT(1) NOT NULL DEFAULT 0,
            transferee_signature_at DATETIME NULL,
            transferor_signature_at DATETIME NULL,
            state_form_attached TINYINT(1) NOT NULL DEFAULT 0,
            state_form_reference VARCHAR(120) NULL,
            voided_reason VARCHAR(255) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            prev_hash CHAR(64) NULL,
            row_hash CHAR(64) NULL,
            retention_until DATE NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_form_serial (form_serial),
            KEY idx_status (transaction_status),
            KEY idx_pos_order (pos_order_id),
            KEY idx_transferee (transferee_last, transferee_first, transferee_dob),
            KEY idx_transferred_at (transferred_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_nics_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_4473_id BIGINT UNSIGNED NOT NULL,
            ntn VARCHAR(40) NULL,
            state_transaction_id VARCHAR(40) NULL,
            nics_method VARCHAR(20) NOT NULL DEFAULT 'fbi',
            initiated_at DATETIME NOT NULL,
            response_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            response_received_at DATETIME NULL,
            delayed_until DATETIME NULL,
            default_proceed_eligible_at DATETIME NULL,
            transferred TINYINT(1) NOT NULL DEFAULT 0,
            transferred_at DATETIME NULL,
            denied_at DATETIME NULL,
            denied_reason VARCHAR(255) NULL,
            appeal_filed TINYINT(1) NOT NULL DEFAULT 0,
            appeal_filed_at DATETIME NULL,
            operator_id BIGINT UNSIGNED NOT NULL,
            raw_response LONGTEXT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_form (form_4473_id),
            KEY idx_status (response_status),
            KEY idx_ntn (ntn)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_atf_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_type VARCHAR(40) NOT NULL,
            trigger_reason VARCHAR(120) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            transferee_form_4473_ids LONGTEXT NULL,
            firearm_serial_numbers LONGTEXT NULL,
            submitted_at DATETIME NULL,
            submission_ref VARCHAR(120) NULL,
            payload LONGTEXT NULL,
            actor_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NULL,
            due_by DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_report_type (report_type),
            KEY idx_status (status),
            KEY idx_due (due_by)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_state_compliance_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            state_code CHAR(2) NOT NULL,
            state_name VARCHAR(80) NOT NULL,
            handgun_min_age SMALLINT NOT NULL DEFAULT 21,
            long_gun_min_age SMALLINT NOT NULL DEFAULT 18,
            ammo_handgun_min_age SMALLINT NOT NULL DEFAULT 21,
            ammo_long_gun_min_age SMALLINT NOT NULL DEFAULT 18,
            waiting_period_hours_handgun SMALLINT NOT NULL DEFAULT 0,
            waiting_period_hours_long_gun SMALLINT NOT NULL DEFAULT 0,
            requires_permit_to_purchase_handgun TINYINT(1) NOT NULL DEFAULT 0,
            requires_permit_to_purchase_long_gun TINYINT(1) NOT NULL DEFAULT 0,
            permit_label VARCHAR(80) NULL,
            requires_foid TINYINT(1) NOT NULL DEFAULT 0,
            requires_dros TINYINT(1) NOT NULL DEFAULT 0,
            requires_state_nics_check TINYINT(1) NOT NULL DEFAULT 0,
            state_nics_label VARCHAR(80) NULL,
            requires_safety_certificate TINYINT(1) NOT NULL DEFAULT 0,
            safety_certificate_label VARCHAR(80) NULL,
            roster_required TINYINT(1) NOT NULL DEFAULT 0,
            one_handgun_per_30_days TINYINT(1) NOT NULL DEFAULT 0,
            assault_weapon_restricted TINYINT(1) NOT NULL DEFAULT 0,
            magazine_capacity_limit SMALLINT NULL,
            requires_long_gun_multi_sale_report TINYINT(1) NOT NULL DEFAULT 0,
            ffl_to_ffl_required_for_nonresident TINYINT(1) NOT NULL DEFAULT 1,
            ccw_bypasses_nics TINYINT(1) NOT NULL DEFAULT 0,
            ccw_max_age_years SMALLINT NOT NULL DEFAULT 5,
            ccw_permit_label VARCHAR(80) NULL,
            ccw_qualifying_citation VARCHAR(160) NULL,
            ccw_handgun_only TINYINT(1) NOT NULL DEFAULT 0,
            rules_payload LONGTEXT NULL,
            effective_at DATE NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_state (state_code)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_wholesalers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider_code VARCHAR(40) NOT NULL,
            display_name VARCHAR(160) NOT NULL,
            account_number VARCHAR(80) NULL,
            api_endpoint VARCHAR(255) NULL,
            credentials LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            settings LONGTEXT NULL,
            last_sync_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_provider (provider_code, account_number),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_wholesaler_products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wholesaler_id BIGINT UNSIGNED NOT NULL,
            vendor_sku VARCHAR(80) NOT NULL,
            upc VARCHAR(40) NULL,
            mfg_part VARCHAR(120) NULL,
            manufacturer VARCHAR(160) NULL,
            model VARCHAR(160) NULL,
            family VARCHAR(160) NULL,
            item_group VARCHAR(160) NULL,
            vendor_category VARCHAR(160) NULL,
            vendor_type VARCHAR(80) NULL,
            item_type VARCHAR(40) NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            caliber VARCHAR(80) NULL,
            msrp DECIMAL(14,2) NULL,
            wholesale_price DECIMAL(14,2) NULL,
            current_price DECIMAL(14,2) NULL,
            map_price DECIMAL(14,2) NULL,
            stock_qty INT NOT NULL DEFAULT 0,
            allocated TINYINT(1) NOT NULL DEFAULT 0,
            can_dropship TINYINT(1) NOT NULL DEFAULT 0,
            on_sale TINYINT(1) NOT NULL DEFAULT 0,
            ffl_required TINYINT(1) NOT NULL DEFAULT 0,
            sot_required TINYINT(1) NOT NULL DEFAULT 0,
            exclusive TINYINT(1) NOT NULL DEFAULT 0,
            country_of_origin VARCHAR(80) NULL,
            shipping_weight DECIMAL(10,3) NULL,
            image_filename VARCHAR(255) NULL,
            attributes LONGTEXT NULL,
            wc_product_id BIGINT UNSIGNED NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_vendor_sku (wholesaler_id, vendor_sku),
            KEY idx_upc (upc),
            KEY idx_mfg_part (mfg_part),
            KEY idx_wc_product (wc_product_id),
            KEY idx_category (vendor_category),
            KEY idx_dropship (can_dropship)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_wholesaler_categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wholesaler_id BIGINT UNSIGNED NOT NULL,
            vendor_category VARCHAR(160) NOT NULL,
            item_type VARCHAR(40) NULL,
            display_label VARCHAR(200) NULL,
            wc_category_id BIGINT UNSIGNED NULL,
            import_enabled TINYINT(1) NOT NULL DEFAULT 1,
            dropship_enabled TINYINT(1) NOT NULL DEFAULT 1,
            markup_percent DECIMAL(6,2) NULL,
            product_count INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_vendor_cat (wholesaler_id, vendor_category),
            KEY idx_enabled (import_enabled)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_wholesaler_orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wholesaler_id BIGINT UNSIGNED NOT NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            wc_order_id BIGINT UNSIGNED NULL,
            external_order_ref VARCHAR(120) NULL,
            order_type VARCHAR(30) NOT NULL DEFAULT 'dropship',
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            ship_to_ffl VARCHAR(40) NULL,
            ship_to_name VARCHAR(200) NULL,
            ship_to_address VARCHAR(255) NULL,
            ship_to_city VARCHAR(120) NULL,
            ship_to_state CHAR(2) NULL,
            ship_to_zip VARCHAR(20) NULL,
            ship_method VARCHAR(80) NULL,
            tracking_number VARCHAR(120) NULL,
            subtotal DECIMAL(14,2) NULL,
            shipping_total DECIMAL(14,2) NULL,
            grand_total DECIMAL(14,2) NULL,
            items LONGTEXT NULL,
            request_payload LONGTEXT NULL,
            response_payload LONGTEXT NULL,
            error_message TEXT NULL,
            submitted_at DATETIME NULL,
            confirmed_at DATETIME NULL,
            shipped_at DATETIME NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_status (status),
            KEY idx_wholesaler (wholesaler_id),
            KEY idx_pos_order (pos_order_id),
            KEY idx_ext_ref (external_order_ref)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_wholesaler_sync_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wholesaler_id BIGINT UNSIGNED NOT NULL,
            sync_type VARCHAR(40) NOT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'csv',
            file_label VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'running',
            rows_total INT NOT NULL DEFAULT 0,
            rows_created INT NOT NULL DEFAULT 0,
            rows_updated INT NOT NULL DEFAULT 0,
            rows_skipped INT NOT NULL DEFAULT 0,
            rows_failed INT NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            summary LONGTEXT NULL,
            error_message TEXT NULL,
            KEY idx_wholesaler (wholesaler_id),
            KEY idx_status (status),
            KEY idx_started (started_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_map_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wc_product_id BIGINT UNSIGNED NULL,
            sku VARCHAR(120) NULL,
            upc VARCHAR(40) NULL,
            manufacturer VARCHAR(160) NULL,
            map_price DECIMAL(14,2) NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            wholesaler_id BIGINT UNSIGNED NULL,
            display_mode VARCHAR(40) NOT NULL DEFAULT 'click_to_reveal',
            override_label VARCHAR(120) NULL,
            effective_at DATE NULL,
            expires_at DATE NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_product (wc_product_id),
            KEY idx_sku (sku),
            KEY idx_upc (upc),
            KEY idx_source (source)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_map_violations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wc_product_id BIGINT UNSIGNED NULL,
            sku VARCHAR(120) NULL,
            channel VARCHAR(40) NOT NULL,
            map_price DECIMAL(14,2) NOT NULL,
            attempted_price DECIMAL(14,2) NOT NULL,
            action_taken VARCHAR(60) NOT NULL,
            context LONGTEXT NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_product (wc_product_id),
            KEY idx_channel (channel),
            KEY idx_created (created_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_transferee_holds (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_4473_id BIGINT UNSIGNED NOT NULL,
            hold_type VARCHAR(40) NOT NULL,
            hold_reason VARCHAR(255) NULL,
            holds_since DATETIME NOT NULL,
            releases_at DATETIME NULL,
            released_at DATETIME NULL,
            released_by BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_form (form_4473_id),
            KEY idx_status (status),
            KEY idx_releases (releases_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_transferees (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blind_index CHAR(64) NOT NULL,
            display_name VARCHAR(180) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            transferee_state CHAR(2) NULL,
            pii_payload LONGTEXT NULL,
            id_scan_payload LONGTEXT NULL,
            photo_url VARCHAR(255) NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_blind (blind_index),
            KEY idx_customer (customer_id),
            KEY idx_state (transferee_state)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_ffl_partners (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ffl_number VARCHAR(40) NOT NULL,
            business_name VARCHAR(180) NOT NULL,
            contact_name VARCHAR(180) NULL,
            address VARCHAR(255) NULL,
            city VARCHAR(120) NULL,
            state CHAR(2) NULL,
            zip VARCHAR(20) NULL,
            phone VARCHAR(40) NULL,
            email VARCHAR(160) NULL,
            license_expires DATE NULL,
            verified_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_ffl_number (ffl_number),
            KEY idx_state (state),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_ffl_transfers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transfer_type VARCHAR(20) NOT NULL,
            direction VARCHAR(20) NOT NULL,
            partner_ffl_id BIGINT UNSIGNED NULL,
            partner_ffl_number VARCHAR(40) NULL,
            partner_name VARCHAR(180) NULL,
            customer_id BIGINT UNSIGNED NULL,
            transferee_id BIGINT UNSIGNED NULL,
            manufacturer VARCHAR(120) NOT NULL,
            model VARCHAR(120) NOT NULL,
            serial_number VARCHAR(120) NOT NULL,
            firearm_type VARCHAR(40) NOT NULL,
            caliber VARCHAR(40) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            fee_charged DECIMAL(10,2) NULL,
            received_at DATETIME NULL,
            shipped_at DATETIME NULL,
            tracking_number VARCHAR(120) NULL,
            shipper VARCHAR(60) NULL,
            bound_book_id BIGINT UNSIGNED NULL,
            form_4473_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_serial_direction_partner (serial_number, direction, partner_ffl_number),
            KEY idx_status (status),
            KEY idx_serial (serial_number),
            KEY idx_direction (direction)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_user_totp (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            secret_encrypted LONGTEXT NOT NULL,
            confirmed TINYINT(1) NOT NULL DEFAULT 0,
            backup_codes LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_distributors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            adapter VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'http',
            endpoint_url VARCHAR(500) NULL,
            credentials_encrypted LONGTEXT NULL,
            schedule VARCHAR(20) NOT NULL DEFAULT 'daily',
            default_markup_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
            auto_publish TINYINT(1) NOT NULL DEFAULT 0,
            last_synced_at DATETIME NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_enabled (enabled),
            KEY idx_adapter (adapter)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_distributor_sync_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            distributor_id BIGINT UNSIGNED NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            rows_seen INT UNSIGNED NOT NULL DEFAULT 0,
            rows_created INT UNSIGNED NOT NULL DEFAULT 0,
            rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
            rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
            errors_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_summary TEXT NULL,
            KEY idx_distributor (distributor_id),
            KEY idx_started (started_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_inventory_external_refs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            upc VARCHAR(40) NULL,
            manufacturer_sku VARCHAR(120) NULL,
            source VARCHAR(40) NOT NULL,
            source_ref VARCHAR(120) NULL,
            last_seen_at DATETIME NOT NULL,
            metadata LONGTEXT NULL,
            UNIQUE KEY uniq_source_ref (source, source_ref),
            UNIQUE KEY uniq_source_upc (source, upc),
            KEY idx_product (product_id),
            KEY idx_upc (upc),
            KEY idx_mfg_sku (manufacturer_sku)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_range_waivers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(40) NOT NULL DEFAULT 'otterwaiver',
            unique_ref VARCHAR(120) NULL,
            first_name VARCHAR(120) NOT NULL,
            last_name VARCHAR(120) NOT NULL,
            dob DATE NULL,
            age SMALLINT NULL,
            email VARCHAR(160) NULL,
            email_optin TINYINT(1) NOT NULL DEFAULT 0,
            phone VARCHAR(60) NULL,
            phone_optin TINYINT(1) NOT NULL DEFAULT 0,
            country VARCHAR(8) NULL,
            city VARCHAR(120) NULL,
            state VARCHAR(40) NULL,
            street VARCHAR(255) NULL,
            postal_code VARCHAR(20) NULL,
            participant_type VARCHAR(40) NOT NULL DEFAULT 'Adult',
            waiver_date DATETIME NULL,
            title VARCHAR(255) NULL,
            flag VARCHAR(60) NULL,
            signed_on DATETIME NULL,
            document_url VARCHAR(500) NULL,
            certificate_url VARCHAR(500) NULL,
            certificate_attachment_id BIGINT UNSIGNED NULL,
            emergency_contact_name VARCHAR(200) NULL,
            emergency_contact_phone VARCHAR(60) NULL,
            minor_name VARCHAR(200) NULL,
            minor_age SMALLINT NULL,
            questions LONGTEXT NULL,
            check_ins LONGTEXT NULL,
            check_in_count SMALLINT NOT NULL DEFAULT 0,
            second_signer_name VARCHAR(200) NULL,
            second_signer_email VARCHAR(160) NULL,
            countersign_status VARCHAR(40) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            pos_customer_id BIGINT UNSIGNED NULL,
            valid_until DATE NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_source_ref (source, unique_ref),
            KEY idx_email (email),
            KEY idx_phone (phone),
            KEY idx_name_dob (last_name, first_name, dob),
            KEY idx_waiver_date (waiver_date),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_range_checkins (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            waiver_id BIGINT UNSIGNED NOT NULL,
            checked_in_at DATETIME NOT NULL,
            register_session_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_waiver (waiver_id),
            KEY idx_checked_in (checked_in_at)
        ) $charset";

		// ---- v0.9.0 — closing the 13 functional gaps ----

		// 1) Customer CRM extension — consent flags, lifecycle, do-not-sell, denied buyer.
		$sql[] = "CREATE TABLE {$p}g2a_customer_profiles (
            customer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            marketing_email_optin TINYINT(1) NOT NULL DEFAULT 0,
            marketing_sms_optin TINYINT(1) NOT NULL DEFAULT 0,
            marketing_optin_source VARCHAR(80) NULL,
            do_not_sell TINYINT(1) NOT NULL DEFAULT 0,
            denied_buyer TINYINT(1) NOT NULL DEFAULT 0,
            denied_reason VARCHAR(255) NULL,
            lifetime_spend DECIMAL(14,2) NOT NULL DEFAULT 0,
            lifetime_orders INT UNSIGNED NOT NULL DEFAULT 0,
            first_order_at DATETIME NULL,
            last_order_at DATETIME NULL,
            preferred_caliber VARCHAR(60) NULL,
            preferred_brand VARCHAR(120) NULL,
            internal_notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_denied (denied_buyer),
            KEY idx_dns (do_not_sell)
        ) $charset";

		// 2) Gunsmithing / repair tickets
		$sql[] = "CREATE TABLE {$p}g2a_repair_tickets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_number VARCHAR(40) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_phone VARCHAR(60) NULL,
            customer_email VARCHAR(160) NULL,
            firearm_make VARCHAR(120) NULL,
            firearm_model VARCHAR(120) NULL,
            firearm_serial VARCHAR(120) NULL,
            firearm_caliber VARCHAR(60) NULL,
            firearm_type VARCHAR(40) NULL,
            intake_photo_urls LONGTEXT NULL,
            problem_description TEXT NOT NULL,
            diagnosis TEXT NULL,
            estimate_amount DECIMAL(14,2) NULL,
            estimate_approved TINYINT(1) NOT NULL DEFAULT 0,
            estimate_approved_at DATETIME NULL,
            parts_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            labor_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'intake',
            assigned_to BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            bound_book_id BIGINT UNSIGNED NULL,
            received_at DATETIME NOT NULL,
            promised_at DATETIME NULL,
            completed_at DATETIME NULL,
            returned_at DATETIME NULL,
            signature_data LONGTEXT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_ticket (ticket_number),
            KEY idx_status (status),
            KEY idx_serial (firearm_serial),
            KEY idx_customer (customer_id),
            KEY idx_received (received_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_repair_lines (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            line_type VARCHAR(20) NOT NULL,
            description VARCHAR(255) NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            quantity DECIMAL(10,3) NOT NULL DEFAULT 1,
            unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            technician_id BIGINT UNSIGNED NULL,
            performed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_ticket (ticket_id),
            KEY idx_type (line_type)
        ) $charset";

		// 3) Layaway / Special Order / Consignment
		$sql[] = "CREATE TABLE {$p}g2a_layaways (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            layaway_number VARCHAR(40) NOT NULL,
            layaway_type VARCHAR(20) NOT NULL DEFAULT 'layaway',
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_phone VARCHAR(60) NULL,
            customer_email VARCHAR(160) NULL,
            items LONGTEXT NOT NULL,
            grand_total DECIMAL(14,2) NOT NULL,
            deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            balance_due DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            opened_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            cancellation_reason VARCHAR(255) NULL,
            forfeited_amount DECIMAL(14,2) NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            actor_id BIGINT UNSIGNED NOT NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_layaway (layaway_number),
            KEY idx_status (status),
            KEY idx_customer (customer_id),
            KEY idx_expires (expires_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_layaway_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            layaway_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            payment_method VARCHAR(60) NOT NULL,
            reference VARCHAR(120) NULL,
            register_session_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            received_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_layaway (layaway_id),
            KEY idx_received (received_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_consignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            consignment_number VARCHAR(40) NOT NULL,
            consignor_name VARCHAR(200) NOT NULL,
            consignor_id_type VARCHAR(40) NULL,
            consignor_id_number VARCHAR(80) NULL,
            consignor_phone VARCHAR(60) NULL,
            consignor_email VARCHAR(160) NULL,
            consignor_address VARCHAR(255) NULL,
            firearm_make VARCHAR(120) NULL,
            firearm_model VARCHAR(120) NULL,
            firearm_serial VARCHAR(120) NULL,
            firearm_type VARCHAR(40) NULL,
            firearm_caliber VARCHAR(60) NULL,
            condition_notes TEXT NULL,
            asking_price DECIMAL(14,2) NOT NULL,
            min_acceptable_price DECIMAL(14,2) NULL,
            commission_percent DECIMAL(6,2) NOT NULL DEFAULT 20.00,
            sold_price DECIMAL(14,2) NULL,
            sold_at DATETIME NULL,
            payout_amount DECIMAL(14,2) NULL,
            payout_paid_at DATETIME NULL,
            payout_reference VARCHAR(120) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'received',
            bound_book_id BIGINT UNSIGNED NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            received_at DATETIME NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_consignment (consignment_number),
            KEY idx_status (status),
            KEY idx_serial (firearm_serial)
        ) $charset";

		// 4) Range membership billing + lane reservations
		$sql[] = "CREATE TABLE {$p}g2a_memberships (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NULL,
            waiver_id BIGINT UNSIGNED NULL,
            tier_code VARCHAR(40) NOT NULL,
            tier_label VARCHAR(120) NOT NULL,
            price_amount DECIMAL(14,2) NOT NULL,
            billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL,
            renews_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            external_provider VARCHAR(60) NULL,
            external_subscription_id VARCHAR(120) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_status (status),
            KEY idx_customer (customer_id),
            KEY idx_renews (renews_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_membership_invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            membership_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            paid_at DATETIME NULL,
            payment_method VARCHAR(60) NULL,
            payment_reference VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_membership (membership_id),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_lane_reservations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lane_code VARCHAR(40) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            customer_id BIGINT UNSIGNED NULL,
            waiver_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_phone VARCHAR(60) NULL,
            customer_email VARCHAR(160) NULL,
            party_size SMALLINT NOT NULL DEFAULT 1,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'reserved',
            source VARCHAR(40) NOT NULL DEFAULT 'pos',
            checked_in_at DATETIME NULL,
            checked_out_at DATETIME NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_lane_starts (lane_code, starts_at),
            KEY idx_status (status),
            KEY idx_starts (starts_at)
        ) $charset";

		// 5) Classes & training
		$sql[] = "CREATE TABLE {$p}g2a_classes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            class_code VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            instructor_name VARCHAR(200) NULL,
            instructor_user_id BIGINT UNSIGNED NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 120,
            capacity SMALLINT NOT NULL DEFAULT 12,
            price_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            deposit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            prerequisites VARCHAR(255) NULL,
            requires_waiver TINYINT(1) NOT NULL DEFAULT 1,
            certificate_template VARCHAR(120) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_code (class_code),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_class_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            class_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            instructor_user_id BIGINT UNSIGNED NULL,
            seats_taken SMALLINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_class (class_id),
            KEY idx_starts (starts_at),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_class_enrollments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_phone VARCHAR(60) NULL,
            customer_email VARCHAR(160) NULL,
            paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            amount_due DECIMAL(14,2) NOT NULL DEFAULT 0,
            waiver_id BIGINT UNSIGNED NULL,
            attendance VARCHAR(20) NOT NULL DEFAULT 'enrolled',
            completion_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            certificate_url VARCHAR(500) NULL,
            certificate_issued_at DATETIME NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_session (session_id),
            KEY idx_customer (customer_id),
            KEY idx_attendance (attendance)
        ) $charset";

		// 6) Loyalty + store credit + gift cards
		$sql[] = "CREATE TABLE {$p}g2a_loyalty_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            balance_points INT NOT NULL DEFAULT 0,
            store_credit_cents BIGINT NOT NULL DEFAULT 0,
            tier_code VARCHAR(40) NOT NULL DEFAULT 'standard',
            lifetime_points INT NOT NULL DEFAULT 0,
            lifetime_credit_earned_cents BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_customer (customer_id),
            KEY idx_tier (tier_code)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_loyalty_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            tx_type VARCHAR(40) NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'points',
            delta_points INT NOT NULL DEFAULT 0,
            delta_credit_cents BIGINT NOT NULL DEFAULT 0,
            balance_points_after INT NOT NULL DEFAULT 0,
            balance_credit_cents_after BIGINT NOT NULL DEFAULT 0,
            pos_order_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NULL,
            reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_customer (customer_id),
            KEY idx_type (tx_type),
            KEY idx_created (created_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_gift_cards (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(60) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            initial_balance_cents BIGINT NOT NULL,
            balance_cents BIGINT NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            customer_id BIGINT UNSIGNED NULL,
            issued_by BIGINT UNSIGNED NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            last_used_at DATETIME NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_code (code),
            UNIQUE KEY uniq_code_hash (code_hash),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_gift_card_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gift_card_id BIGINT UNSIGNED NOT NULL,
            tx_type VARCHAR(20) NOT NULL,
            delta_cents BIGINT NOT NULL,
            balance_after_cents BIGINT NOT NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NULL,
            reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_card (gift_card_id),
            KEY idx_created (created_at)
        ) $charset";

		// 7) Purchase orders + receiving
		$sql[] = "CREATE TABLE {$p}g2a_purchase_orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(40) NOT NULL,
            wholesaler_id BIGINT UNSIGNED NULL,
            vendor_name VARCHAR(200) NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            shipping_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            expected_at DATETIME NULL,
            submitted_at DATETIME NULL,
            received_at DATETIME NULL,
            closed_at DATETIME NULL,
            external_ref VARCHAR(120) NULL,
            tracking_number VARCHAR(120) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_po (po_number),
            KEY idx_status (status),
            KEY idx_wholesaler (wholesaler_id)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_purchase_order_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            po_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            vendor_sku VARCHAR(120) NULL,
            upc VARCHAR(40) NULL,
            description VARCHAR(255) NOT NULL,
            quantity_ordered DECIMAL(10,3) NOT NULL,
            quantity_received DECIMAL(10,3) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            received_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_po (po_id),
            KEY idx_product (product_id),
            KEY idx_upc (upc)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_po_receipts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            po_id BIGINT UNSIGNED NOT NULL,
            po_item_id BIGINT UNSIGNED NOT NULL,
            quantity DECIMAL(10,3) NOT NULL,
            serial_numbers LONGTEXT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            received_at DATETIME NOT NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_po (po_id),
            KEY idx_item (po_item_id)
        ) $charset";

		// 8) Cycle count / physical inventory
		$sql[] = "CREATE TABLE {$p}g2a_cycle_counts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            count_code VARCHAR(40) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            scope_type VARCHAR(40) NOT NULL DEFAULT 'category',
            scope_value VARCHAR(160) NULL,
            count_type VARCHAR(20) NOT NULL DEFAULT 'cycle',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            opened_by BIGINT UNSIGNED NOT NULL,
            closed_by BIGINT UNSIGNED NULL,
            opened_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            items_total INT UNSIGNED NOT NULL DEFAULT 0,
            items_counted INT UNSIGNED NOT NULL DEFAULT 0,
            variance_units DECIMAL(14,3) NOT NULL DEFAULT 0,
            variance_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_code (count_code),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_cycle_count_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            count_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(120) NULL,
            serial_number VARCHAR(120) NULL,
            expected_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
            counted_qty DECIMAL(14,3) NULL,
            variance_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(14,2) NULL,
            variance_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            counted_by BIGINT UNSIGNED NULL,
            counted_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_count (count_id),
            KEY idx_product (product_id),
            KEY idx_serial (serial_number),
            KEY idx_status (status)
        ) $charset";

		// 9) e-Signature capture (used by 4473, repair tickets, consignments, classes)
		$sql[] = "CREATE TABLE {$p}g2a_signatures (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject_type VARCHAR(60) NOT NULL,
            subject_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(40) NOT NULL,
            signer_name VARCHAR(200) NOT NULL,
            image_data LONGTEXT NOT NULL,
            image_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(60) NULL,
            user_agent VARCHAR(255) NULL,
            signed_at DATETIME NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            prev_hash CHAR(64) NULL,
            row_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_subject (subject_type, subject_id),
            KEY idx_role (role),
            KEY idx_signed (signed_at)
        ) $charset";

		// 11) Shipping labels
		$sql[] = "CREATE TABLE {$p}g2a_shipping_labels (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            carrier VARCHAR(40) NOT NULL,
            service VARCHAR(80) NULL,
            tracking_number VARCHAR(120) NULL,
            label_url VARCHAR(500) NULL,
            label_format VARCHAR(20) NOT NULL DEFAULT 'pdf',
            from_name VARCHAR(200) NOT NULL,
            from_address VARCHAR(255) NOT NULL,
            from_city VARCHAR(120) NOT NULL,
            from_state CHAR(2) NOT NULL,
            from_zip VARCHAR(20) NOT NULL,
            to_name VARCHAR(200) NOT NULL,
            to_address VARCHAR(255) NOT NULL,
            to_city VARCHAR(120) NOT NULL,
            to_state CHAR(2) NOT NULL,
            to_zip VARCHAR(20) NOT NULL,
            weight_oz DECIMAL(10,2) NULL,
            length_in DECIMAL(8,2) NULL,
            width_in DECIMAL(8,2) NULL,
            height_in DECIMAL(8,2) NULL,
            insured_value DECIMAL(14,2) NULL,
            adult_signature_required TINYINT(1) NOT NULL DEFAULT 1,
            requires_21 TINYINT(1) NOT NULL DEFAULT 1,
            cost_amount DECIMAL(14,2) NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            related_order_id BIGINT UNSIGNED NULL,
            related_transfer_id BIGINT UNSIGNED NULL,
            request_payload LONGTEXT NULL,
            response_payload LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'created',
            voided_at DATETIME NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_carrier (carrier),
            KEY idx_tracking (tracking_number),
            KEY idx_status (status),
            KEY idx_order (related_order_id)
        ) $charset";

		// 12) Messaging / transactional outbox
		$sql[] = "CREATE TABLE {$p}g2a_messaging_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(80) NOT NULL,
            channel VARCHAR(20) NOT NULL,
            subject VARCHAR(255) NULL,
            body LONGTEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_template_channel (template_key, channel),
            KEY idx_enabled (enabled)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_messaging_outbox (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(20) NOT NULL,
            template_key VARCHAR(80) NULL,
            to_address VARCHAR(255) NOT NULL,
            from_address VARCHAR(255) NULL,
            subject VARCHAR(255) NULL,
            body LONGTEXT NOT NULL,
            related_entity_type VARCHAR(60) NULL,
            related_entity_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            scheduled_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            last_error TEXT NULL,
            provider VARCHAR(40) NULL,
            provider_message_id VARCHAR(160) NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_status_sched (status, scheduled_at),
            KEY idx_entity (related_entity_type, related_entity_id),
            KEY idx_channel (channel)
        ) $charset";

		// 13) KPI snapshots (nightly cache for dashboard speed)
		$sql[] = "CREATE TABLE {$p}g2a_kpi_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            snapshot_date DATE NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            metric_key VARCHAR(80) NOT NULL,
            metric_value DECIMAL(18,4) NOT NULL DEFAULT 0,
            metric_meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_snap (snapshot_date, location_id, metric_key),
            KEY idx_metric (metric_key)
        ) $charset";

		// ---- v0.10.0 — deep firearm-business modules ----

		// NFA item handling (Form 3/4/5, tax stamps, trusts, two-stamp ownership).
		$sql[] = "CREATE TABLE {$p}g2a_nfa_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nfa_class VARCHAR(20) NOT NULL,
            manufacturer VARCHAR(120) NOT NULL,
            model VARCHAR(120) NOT NULL,
            serial_number VARCHAR(120) NOT NULL,
            caliber VARCHAR(60) NULL,
            barrel_length_in DECIMAL(6,2) NULL,
            overall_length_in DECIMAL(6,2) NULL,
            two_stamp TINYINT(1) NOT NULL DEFAULT 0,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            current_holder VARCHAR(200) NULL,
            holder_type VARCHAR(40) NULL,
            trust_id BIGINT UNSIGNED NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'in_inventory',
            bound_book_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_serial (serial_number),
            KEY idx_status (status),
            KEY idx_class (nfa_class),
            KEY idx_holder (current_holder)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_nfa_forms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nfa_item_id BIGINT UNSIGNED NOT NULL,
            form_type VARCHAR(10) NOT NULL,
            form_serial VARCHAR(60) NULL,
            tax_stamp_amount DECIMAL(8,2) NULL,
            tax_paid_at DATETIME NULL,
            cle_notified_at DATETIME NULL,
            submitted_at DATETIME NULL,
            approved_at DATETIME NULL,
            disapproved_at DATETIME NULL,
            stamp_pdf_url VARCHAR(500) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            transferee_name VARCHAR(200) NULL,
            transferee_address VARCHAR(255) NULL,
            transferor_ffl VARCHAR(40) NULL,
            transferee_ffl VARCHAR(40) NULL,
            payload LONGTEXT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_item (nfa_item_id),
            KEY idx_status (status),
            KEY idx_type (form_type)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_nfa_trusts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trust_name VARCHAR(200) NOT NULL,
            trust_type VARCHAR(40) NOT NULL DEFAULT 'gun_trust',
            grantor_name VARCHAR(200) NOT NULL,
            grantor_phone VARCHAR(60) NULL,
            grantor_email VARCHAR(160) NULL,
            trust_address VARCHAR(255) NULL,
            trust_state CHAR(2) NULL,
            trustees LONGTEXT NULL,
            documents LONGTEXT NULL,
            customer_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_grantor (grantor_name),
            KEY idx_customer (customer_id),
            KEY idx_status (status)
        ) $charset";

		// Multi-location transfers (serialized firearms between stores).
		$sql[] = "CREATE TABLE {$p}g2a_location_transfers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transfer_number VARCHAR(40) NOT NULL,
            from_location_id BIGINT UNSIGNED NOT NULL,
            to_location_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            shipped_at DATETIME NULL,
            received_at DATETIME NULL,
            carrier VARCHAR(60) NULL,
            tracking_number VARCHAR(120) NULL,
            shipped_by BIGINT UNSIGNED NULL,
            received_by BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_transfer (transfer_number),
            KEY idx_status (status),
            KEY idx_from (from_location_id),
            KEY idx_to (to_location_id)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_location_transfer_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transfer_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            sku VARCHAR(120) NULL,
            serial_number VARCHAR(120) NULL,
            description VARCHAR(255) NULL,
            quantity DECIMAL(10,3) NOT NULL DEFAULT 1,
            bound_book_debit_id BIGINT UNSIGNED NULL,
            bound_book_credit_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_transfer (transfer_id),
            KEY idx_serial (serial_number)
        ) $charset";

		// Range Operations: RSO assignments + ammo rental + brass buyback.
		$sql[] = "CREATE TABLE {$p}g2a_rso_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            shift_start DATETIME NOT NULL,
            shift_end DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            checked_in_at DATETIME NULL,
            checked_out_at DATETIME NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_user (user_id),
            KEY idx_shift_start (shift_start),
            KEY idx_status (status)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_ammo_rentals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reservation_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            caliber VARCHAR(60) NOT NULL,
            rounds_issued INT NOT NULL,
            rounds_returned INT NOT NULL DEFAULT 0,
            unit_price_cents INT NOT NULL DEFAULT 0,
            total_charged_cents BIGINT NOT NULL DEFAULT 0,
            issued_by BIGINT UNSIGNED NOT NULL,
            issued_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_reservation (reservation_id),
            KEY idx_issued_at (issued_at)
        ) $charset";

		// Firearm rentals. Deliberately a separate table from g2a_ammo_rentals:
		// a rented firearm is a serialised item that physically leaves the
		// counter, so it needs its own out/in lifecycle, the identity check that
		// authorised it, and a record of who took it back. Ammo and the gun are
		// routinely returned at different moments (the customer buys the rounds
		// they didn't shoot and hands the gun back), so one shared record cannot
		// close them independently.
		//
		// NOTE: this records the rental only. A loan for on-premises use is not a
		// transfer, so this is NOT an acquisition/disposition record and must not
		// be read as a bound-book entry — see the BoundBook module for A&D/4473.
		$sql[] = "CREATE TABLE {$p}g2a_firearm_rentals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reservation_id BIGINT UNSIGNED NULL,
            lane_id BIGINT UNSIGNED NULL,
            inventory_item_id BIGINT UNSIGNED NULL,
            serial_number VARCHAR(100) NOT NULL,
            manufacturer VARCHAR(120) NULL,
            model VARCHAR(120) NULL,
            caliber VARCHAR(60) NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            id_type VARCHAR(40) NOT NULL DEFAULT '',
            id_last4 VARCHAR(8) NOT NULL DEFAULT '',
            id_verified TINYINT(1) NOT NULL DEFAULT 0,
            waiver_id BIGINT UNSIGNED NULL,
            waiver_verified TINYINT(1) NOT NULL DEFAULT 0,
            rate_cents INT NOT NULL DEFAULT 0,
            deposit_cents INT NOT NULL DEFAULT 0,
            total_charged_cents BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'out',
            issued_by BIGINT UNSIGNED NOT NULL,
            issued_at DATETIME NOT NULL,
            due_back_at DATETIME NULL,
            returned_by BIGINT UNSIGNED NULL,
            returned_at DATETIME NULL,
            condition_out TEXT NULL,
            condition_in TEXT NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_serial (serial_number),
            KEY idx_status (status),
            KEY idx_issued_at (issued_at),
            KEY idx_reservation (reservation_id),
            KEY idx_inventory_item (inventory_item_id)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_brass_buybacks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_name VARCHAR(200) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            caliber VARCHAR(60) NOT NULL,
            weight_lbs DECIMAL(8,3) NULL,
            count_rounds INT NULL,
            rate_per_lb_cents INT NULL,
            rate_per_round_cents INT NULL,
            payout_cents BIGINT NOT NULL,
            payout_method VARCHAR(40) NOT NULL DEFAULT 'store_credit',
            store_credit_tx_id BIGINT UNSIGNED NULL,
            received_by BIGINT UNSIGNED NOT NULL,
            received_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_customer (customer_id),
            KEY idx_received_at (received_at)
        ) $charset";

		// Classes: prerequisites enforcement + partial payments + certificate templates.
		$sql[] = "CREATE TABLE {$p}g2a_class_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            enrollment_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            payment_method VARCHAR(60) NOT NULL,
            reference VARCHAR(120) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            received_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_enrollment (enrollment_id)
        ) $charset";

		// PO: ASN tracking + invoice match.
		$sql[] = "CREATE TABLE {$p}g2a_po_asns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            po_id BIGINT UNSIGNED NOT NULL,
            asn_number VARCHAR(60) NULL,
            carrier VARCHAR(60) NULL,
            tracking_number VARCHAR(120) NULL,
            ship_date DATE NULL,
            eta DATETIME NULL,
            items_payload LONGTEXT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL,
            KEY idx_po (po_id),
            KEY idx_asn (asn_number)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_po_invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            po_id BIGINT UNSIGNED NOT NULL,
            invoice_number VARCHAR(80) NOT NULL,
            invoice_date DATE NOT NULL,
            invoice_total DECIMAL(14,2) NOT NULL,
            match_status VARCHAR(30) NOT NULL DEFAULT 'unmatched',
            match_variance DECIMAL(14,2) NULL,
            attachment_url VARCHAR(500) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_po (po_id),
            KEY idx_match (match_status)
        ) $charset";

		// Loyalty: trade-in credit ledger (extension via tx_type='tradein_credit',
		// plus a tradein record for serial + appraisal trail).
		$sql[] = "CREATE TABLE {$p}g2a_tradeins (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tradein_number VARCHAR(40) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_id_type VARCHAR(40) NULL,
            customer_id_number VARCHAR(80) NULL,
            manufacturer VARCHAR(120) NOT NULL,
            model VARCHAR(120) NOT NULL,
            serial_number VARCHAR(120) NOT NULL,
            firearm_type VARCHAR(40) NULL,
            caliber VARCHAR(60) NULL,
            condition_grade VARCHAR(20) NULL,
            appraisal_amount DECIMAL(14,2) NOT NULL,
            credit_amount DECIMAL(14,2) NOT NULL,
            credit_applied_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            store_credit_tx_id BIGINT UNSIGNED NULL,
            bound_book_id BIGINT UNSIGNED NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'received',
            received_by BIGINT UNSIGNED NOT NULL,
            received_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_tradein (tradein_number),
            KEY idx_serial (serial_number),
            KEY idx_status (status)
        ) $charset";

		// Hardware kit: register-level hardware profile (printer, drawer, scanner,
		// customer display, signature pad, ID scanner driver+endpoint).
		$sql[] = "CREATE TABLE {$p}g2a_hardware_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            register_code VARCHAR(50) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            receipt_printer_driver VARCHAR(40) NULL,
            receipt_printer_endpoint VARCHAR(255) NULL,
            cash_drawer_kick_code VARCHAR(40) NULL,
            cash_drawer_via_printer TINYINT(1) NOT NULL DEFAULT 1,
            barcode_scanner_profile VARCHAR(40) NULL,
            customer_display_driver VARCHAR(40) NULL,
            customer_display_endpoint VARCHAR(255) NULL,
            signature_pad_driver VARCHAR(40) NULL,
            signature_pad_endpoint VARCHAR(255) NULL,
            id_scanner_driver VARCHAR(40) NULL,
            id_scanner_endpoint VARCHAR(255) NULL,
            settings LONGTEXT NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_register (register_code, location_id)
        ) $charset";

		// Compliance calendar.
		$sql[] = "CREATE TABLE {$p}g2a_compliance_calendar (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(60) NOT NULL,
            scope_type VARCHAR(40) NOT NULL DEFAULT 'business',
            scope_value VARCHAR(120) NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            due_at DATETIME NOT NULL,
            advance_notice_days SMALLINT NOT NULL DEFAULT 30,
            recurrence VARCHAR(40) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            completed_at DATETIME NULL,
            completed_by BIGINT UNSIGNED NULL,
            related_entity_type VARCHAR(60) NULL,
            related_entity_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NULL,
            last_reminder_at DATETIME NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_due (due_at),
            KEY idx_status (status),
            KEY idx_type (event_type)
        ) $charset";

		// Vendor cost-drift (snapshot vendor SKU pricing weekly for drift trend).
		$sql[] = "CREATE TABLE {$p}g2a_vendor_price_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wholesaler_id BIGINT UNSIGNED NOT NULL,
            vendor_sku VARCHAR(80) NOT NULL,
            captured_on DATE NOT NULL,
            wholesale_price DECIMAL(14,2) NULL,
            current_price DECIMAL(14,2) NULL,
            msrp DECIMAL(14,2) NULL,
            map_price DECIMAL(14,2) NULL,
            stock_qty INT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_capture (wholesaler_id, vendor_sku, captured_on),
            KEY idx_sku (vendor_sku),
            KEY idx_captured (captured_on)
        ) $charset";

		// ---- v1.0.0 — AI Agent (RAG brain + tool calls + audit) ----

		// Brain documents (ingested PDFs / URLs / CSVs / pasted text).
		$sql[] = "CREATE TABLE {$p}g2a_ai_brain_documents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_type VARCHAR(40) NOT NULL,
            source_label VARCHAR(255) NOT NULL,
            source_uri VARCHAR(500) NULL,
            content_hash CHAR(64) NOT NULL,
            language VARCHAR(10) NOT NULL DEFAULT 'en',
            chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
            tags VARCHAR(255) NULL,
            scope VARCHAR(40) NOT NULL DEFAULT 'public',
            metadata LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'ingested',
            actor_id BIGINT UNSIGNED NULL,
            ingested_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_hash (content_hash),
            KEY idx_source (source_type),
            KEY idx_status (status)
        ) $charset";

		// Brain chunks (text + embedding stored locally; vector DB optional).
		$sql[] = "CREATE TABLE {$p}g2a_ai_brain_chunks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id BIGINT UNSIGNED NOT NULL,
            chunk_index INT UNSIGNED NOT NULL,
            text_content MEDIUMTEXT NOT NULL,
            token_count INT UNSIGNED NULL,
            embedding LONGBLOB NULL,
            embedding_model VARCHAR(80) NULL,
            embedding_dim SMALLINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_doc (document_id),
            KEY idx_model (embedding_model)
        ) $charset";

		// Agent conversations.
		$sql[] = "CREATE TABLE {$p}g2a_ai_conversations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(40) NOT NULL DEFAULT 'pos',
            register_session_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            model_name VARCHAR(80) NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_user (user_id),
            KEY idx_channel (channel),
            KEY idx_status (status)
        ) $charset";

		// Messages inside a conversation.
		$sql[] = "CREATE TABLE {$p}g2a_ai_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            content MEDIUMTEXT NULL,
            tool_calls LONGTEXT NULL,
            tool_results LONGTEXT NULL,
            citations LONGTEXT NULL,
            prompt_tokens INT UNSIGNED NULL,
            completion_tokens INT UNSIGNED NULL,
            latency_ms INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_conv (conversation_id),
            KEY idx_role (role)
        ) $charset";

		// Concrete tool calls the agent issued (one row each).
		$sql[] = "CREATE TABLE {$p}g2a_ai_tool_calls (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            tool_name VARCHAR(120) NOT NULL,
            arguments LONGTEXT NULL,
            result LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,
            confirmed_by BIGINT UNSIGNED NULL,
            confirmed_at DATETIME NULL,
            denied_at DATETIME NULL,
            error_message TEXT NULL,
            latency_ms INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_conv (conversation_id),
            KEY idx_status (status),
            KEY idx_tool (tool_name)
        ) $charset";

		// Pending action queue (tool calls awaiting human confirmation popup).
		$sql[] = "CREATE TABLE {$p}g2a_ai_pending_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            tool_call_id BIGINT UNSIGNED NOT NULL,
            tool_name VARCHAR(120) NOT NULL,
            arguments LONGTEXT NOT NULL,
            preview_payload LONGTEXT NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_status (status),
            KEY idx_conv (conversation_id)
        ) $charset";

		// Tamper-evident agent audit ledger (chained like the bound book).
		$sql[] = "CREATE TABLE {$p}g2a_ai_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(60) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            tool_name VARCHAR(120) NULL,
            payload LONGTEXT NULL,
            ip_address VARCHAR(60) NULL,
            user_agent VARCHAR(255) NULL,
            prev_hash CHAR(64) NULL,
            row_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_conv (conversation_id),
            KEY idx_event (event_type),
            KEY idx_created (created_at)
        ) $charset";

		// Cached feedback for self-training distillation.
		$sql[] = "CREATE TABLE {$p}g2a_ai_feedback (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT NOT NULL,
            comment VARCHAR(500) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_msg_actor (message_id, actor_id)
        ) $charset";

		// ---- v1.1.0 — split-tender checkout ----
		// One row per tender line on an order. An order is fully paid when
		// SUM(captured amount) - SUM(refunded amount) >= grand_total.
		// tender_method: cash | card | gift_card | store_credit | tradein_credit | check | house_charge
		$sql[] = "CREATE TABLE {$p}g2a_pos_tender_lines (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pos_order_id BIGINT UNSIGNED NOT NULL,
            tender_method VARCHAR(40) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            change_due DECIMAL(14,2) NOT NULL DEFAULT 0,
            reference VARCHAR(160) NULL,
            external_ref VARCHAR(160) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'captured',
            refunded_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            register_session_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            captured_at DATETIME NOT NULL,
            voided_at DATETIME NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_order (pos_order_id),
            KEY idx_method (tender_method),
            KEY idx_status (status),
            KEY idx_session (register_session_id)
        ) $charset";

		// ---- v1.5.0 — Unified Item Identity Graph + Used-firearm intake ----
		// Single canonical record per real-world item, regardless of how
		// many vendor SKUs / UPCs / Woo products / mfg part numbers / used
		// intakes refer to it. The catalog truth across the supply chain.
		$sql[] = "CREATE TABLE {$p}g2a_item_identities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            canonical_code VARCHAR(40) NOT NULL,
            item_type VARCHAR(40) NOT NULL DEFAULT 'firearm',
            firearm_type VARCHAR(40) NULL,
            manufacturer VARCHAR(160) NOT NULL,
            model VARCHAR(160) NOT NULL,
            family VARCHAR(160) NULL,
            caliber VARCHAR(80) NULL,
            barrel_length_in DECIMAL(6,2) NULL,
            capacity_rounds SMALLINT NULL,
            country_of_origin VARCHAR(80) NULL,
            msrp DECIMAL(14,2) NULL,
            default_image_url VARCHAR(500) NULL,
            description TEXT NULL,
            tags VARCHAR(255) NULL,
            attributes LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            review_required TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_canonical (canonical_code),
            KEY idx_manufacturer_model (manufacturer, model),
            KEY idx_caliber (caliber),
            KEY idx_status (status),
            KEY idx_review (review_required)
        ) $charset";

		// Every external reference that resolves to a canonical identity.
		// source_type: woo_product | wholesaler_product | vendor_sku | upc |
		//              mfg_part | consignment | tradein | used_intake |
		//              barcode | nfa | manual
		$sql[] = "CREATE TABLE {$p}g2a_item_identity_links (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identity_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(40) NOT NULL,
            source_ref VARCHAR(160) NOT NULL,
            wholesaler_id BIGINT UNSIGNED NULL,
            confidence DECIMAL(4,3) NOT NULL DEFAULT 1.000,
            matched_by VARCHAR(60) NOT NULL DEFAULT 'manual',
            metadata LONGTEXT NULL,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_source (source_type, source_ref, wholesaler_id),
            KEY idx_identity (identity_id),
            KEY idx_source_type (source_type),
            KEY idx_confidence (confidence)
        ) $charset";

		// Merge audit — when two canonical identities are collapsed.
		$sql[] = "CREATE TABLE {$p}g2a_item_identity_merges (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            from_identity_id BIGINT UNSIGNED NOT NULL,
            into_identity_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(255) NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_from (from_identity_id),
            KEY idx_into (into_identity_id)
        ) $charset";

		// Used-firearm intake — customer brings a gun, we buy it outright
		// (separate from consignment, separate from trade-in credit). Logs
		// into the bound book on receipt and resolves to a canonical item
		// identity via the IdentityService.
		$sql[] = "CREATE TABLE {$p}g2a_used_firearm_intakes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            intake_number VARCHAR(40) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            customer_id_type VARCHAR(40) NULL,
            customer_id_number VARCHAR(80) NULL,
            customer_phone VARCHAR(60) NULL,
            customer_email VARCHAR(160) NULL,
            customer_address VARCHAR(255) NULL,
            customer_state CHAR(2) NULL,
            manufacturer VARCHAR(160) NOT NULL,
            model VARCHAR(160) NOT NULL,
            serial_number VARCHAR(120) NOT NULL,
            firearm_type VARCHAR(40) NULL,
            caliber VARCHAR(80) NULL,
            barrel_length_in DECIMAL(6,2) NULL,
            condition_grade VARCHAR(20) NULL,
            condition_notes TEXT NULL,
            intake_photos LONGTEXT NULL,
            appraised_value DECIMAL(14,2) NULL,
            payout_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            payout_method VARCHAR(40) NULL,
            payout_reference VARCHAR(120) NULL,
            payout_paid_at DATETIME NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'received',
            identity_id BIGINT UNSIGNED NULL,
            identity_confidence DECIMAL(4,3) NULL,
            bound_book_id BIGINT UNSIGNED NULL,
            wc_product_id BIGINT UNSIGNED NULL,
            pos_order_id BIGINT UNSIGNED NULL,
            sold_at DATETIME NULL,
            sold_price DECIMAL(14,2) NULL,
            received_by BIGINT UNSIGNED NOT NULL,
            received_at DATETIME NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_intake (intake_number),
            KEY idx_serial (serial_number),
            KEY idx_status (status),
            KEY idx_customer (customer_id),
            KEY idx_identity (identity_id)
        ) $charset";

		// ---- v1.7.0 — Range maintenance + incident log ----
		$sql[] = "CREATE TABLE {$p}g2a_lane_maintenance (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_number VARCHAR(40) NOT NULL,
            lane_code VARCHAR(40) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            ticket_type VARCHAR(40) NOT NULL DEFAULT 'cleaning',
            severity VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            description TEXT NULL,
            equipment_failed VARCHAR(255) NULL,
            parts_used LONGTEXT NULL,
            labor_minutes INT UNSIGNED NULL,
            cost_amount DECIMAL(14,2) NULL,
            lane_out_of_service TINYINT(1) NOT NULL DEFAULT 0,
            opened_by BIGINT UNSIGNED NOT NULL,
            assigned_to BIGINT UNSIGNED NULL,
            opened_at DATETIME NOT NULL,
            in_progress_at DATETIME NULL,
            closed_at DATETIME NULL,
            closed_by BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_ticket (ticket_number),
            KEY idx_lane (lane_code),
            KEY idx_status (status),
            KEY idx_oos (lane_out_of_service)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_range_incidents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            incident_number VARCHAR(40) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            lane_code VARCHAR(40) NULL,
            incident_type VARCHAR(60) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'warning',
            description TEXT NOT NULL,
            occurred_at DATETIME NOT NULL,
            rso_user_id BIGINT UNSIGNED NULL,
            rso_name VARCHAR(160) NULL,
            witness_names TEXT NULL,
            action_taken VARCHAR(255) NULL,
            escalated_to_law_enforcement TINYINT(1) NOT NULL DEFAULT 0,
            police_report_number VARCHAR(120) NULL,
            attachments LONGTEXT NULL,
            customer_flag_applied TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            closed_at DATETIME NULL,
            closed_by BIGINT UNSIGNED NULL,
            reported_by BIGINT UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_incident (incident_number),
            KEY idx_severity (severity),
            KEY idx_lane (lane_code),
            KEY idx_status (status),
            KEY idx_occurred (occurred_at)
        ) $charset";

		$sql[] = "CREATE TABLE {$p}g2a_range_incident_customers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            incident_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(200) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'involved',
            waiver_id BIGINT UNSIGNED NULL,
            flag_kind VARCHAR(40) NULL,
            flag_applied_at DATETIME NULL,
            notes VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_incident (incident_id),
            KEY idx_customer (customer_id),
            KEY idx_flag (flag_kind)
        ) $charset";

		// The log table must exist BEFORE the schema loop so progress can be
		// recorded per table: an interrupted run (shared-host time limit,
		// killed request) then RESUMES where it stopped instead of re-running
		// every dbDelta from scratch on the next attempt.
		self::ensure_migration_log_table( $charset, $p );

		// Each statement is keyed by table + schema hash: unchanged tables are
		// skipped entirely on later runs (no dbDelta DESCRIBE storm), while a
		// table whose definition changed in a new version gets exactly one
		// fresh dbDelta pass.
		$applied = self::applied_steps();
		foreach ( $sql as $statement ) {
			$step = self::statement_step_id( $statement );
			if ( isset( $applied[ $step ] ) ) {
				continue;
			}
			dbDelta( $statement );
			self::record_step( $step, G2A_POS_CORE_VERSION );
		}

		self::record_step( 'baseline_schema', G2A_POS_CORE_VERSION );

		StateSeeder::seed_if_empty();

		update_option( 'g2a_pos_core_db_version', G2A_POS_CORE_VERSION );
	}

	/**
	 * Stable id for one CREATE TABLE statement: table name + definition hash.
	 * The hash changes when the table definition changes, which naturally
	 * re-queues just that table for dbDelta on the next migration run.
	 */
	private static function statement_step_id( string $statement ): string {
		$table = 'unknown';
		if ( preg_match( '/CREATE TABLE\s+`?(\S+?)`?\s*\(/i', $statement, $m ) ) {
			$table = $m[1];
		}
		return 'schema:' . $table . ':' . substr( md5( $statement ), 0, 12 );
	}

	/**
	 * All recorded step ids, keyed for O(1) lookup. One query instead of a
	 * SHOW TABLES + SELECT per step.
	 *
	 * @return array<string,true>
	 */
	private static function applied_steps(): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'g2a_pos_migrations';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return array();
		}
		$ids = $wpdb->get_col( "SELECT step_id FROM {$table}" ) ?: array();
		return array_fill_keys( array_map( 'strval', $ids ), true );
	}

	/**
	 * Idempotently apply a versioned migration step. New schema changes should be
	 * added as new entries here and tracked in the migration log so we can move off
	 * the monolithic dbDelta path incrementally.
	 */
	public static function apply_step( string $step_id, callable $migration ): void {
		if ( self::is_applied( $step_id ) ) {
			return;
		}
		$migration();
		self::record_step( $step_id, G2A_POS_CORE_VERSION );
	}

	public static function is_applied( string $step_id ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'g2a_pos_migrations';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return false;
		}
		$hit = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT step_id FROM {$table} WHERE step_id = %s LIMIT 1",
				$step_id
			)
		);
		return $hit !== null;
	}

	private static function ensure_migration_log_table( string $charset, string $prefix ): void {
		$table = $prefix . 'g2a_pos_migrations';
		$stmt  = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            step_id VARCHAR(120) NOT NULL,
            plugin_version VARCHAR(40) NOT NULL,
            applied_at DATETIME NOT NULL,
            UNIQUE KEY uniq_step (step_id)
        ) {$charset}";
		dbDelta( $stmt );
	}

	private static function record_step( string $step_id, string $version ): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'g2a_pos_migrations';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (step_id, plugin_version, applied_at) VALUES (%s, %s, %s)",
				$step_id,
				$version,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
