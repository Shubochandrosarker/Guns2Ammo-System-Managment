<?php

namespace G2A\POS\Admin;

use G2A\POS\Database\OrderRepository;
use G2A\POS\Database\RegisterSessionRepository;
use G2A\POS\Domain\InventoryEngine;
use WP_Error;

defined('ABSPATH') || exit;

final class Pages
{
    public static function dashboard(): void
    {
        global $wpdb;

        $tables = [
            'g2a_pos_orders',
            'g2a_pos_order_items',
            'g2a_inventory_logs',
            'g2a_serial_registry',
            'g2a_register_sessions',
            'g2a_cash_movements',
            'g2a_compliance_logs',
            'g2a_audit_logs',
            'g2a_sync_queue',
            'g2a_barcodes',
        ];

        $checks = [];
        foreach ($tables as $table) {
            $full = $wpdb->prefix . $table;
            $checks[$table] = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full));
        }

        $orders_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'g2a_pos_orders');
        $open_registers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}g2a_register_sessions WHERE status='open'");
        $queue_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}g2a_sync_queue WHERE status='pending'");

        echo '<div class="wrap"><h1>G2A POS Dashboard</h1>';
        echo '<p><strong>DB Version:</strong> ' . esc_html((string) get_option('g2a_pos_core_db_version')) . '</p>';
        echo '<p><strong>POS Orders:</strong> ' . esc_html((string) $orders_count) . ' | <strong>Open Registers:</strong> ' . esc_html((string) $open_registers) . ' | <strong>Queue Pending:</strong> ' . esc_html((string) $queue_pending) . '</p>';
        echo '<h2>System Health</h2><table class="widefat striped"><thead><tr><th>Table</th><th>Status</th></tr></thead><tbody>';
        foreach ($checks as $table => $ok) {
            echo '<tr><td>' . esc_html($table) . '</td><td>' . ($ok ? '<span style="color:green">OK</span>' : '<span style="color:red">Missing</span>') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function registers(): void
    {
        if (!current_user_can('g2a_pos_manage_register')) {
            wp_die('Access denied');
        }

        $repo = new RegisterSessionRepository();
        $notice = '';
        $error = '';

        if (isset($_POST['g2a_register_action']) && check_admin_referer('g2a_pos_register_action')) {
            $action = sanitize_key((string) $_POST['g2a_register_action']);
            if ($action === 'open') {
                $id = $repo->open(
                    sanitize_text_field((string) $_POST['register_code']),
                    (int) $_POST['location_id'],
                    (float) $_POST['opening_cash']
                );
                $notice = 'Register opened (or reused existing open session) ID #' . $id;
            } elseif ($action === 'close') {
                $actor_id = get_current_user_id();
                $force_close = current_user_can('g2a_pos_manage_settings') || current_user_can('manage_options');
                $ok = $repo->close(
                    (int) $_POST['register_session_id'],
                    (float) $_POST['closing_cash'],
                    $actor_id,
                    $force_close,
                    sanitize_text_field((string) $_POST['notes'])
                );
                if ($ok) {
                    $notice = 'Register session closed successfully.';
                } else {
                    $error = 'Unable to close register session.';
                }
            }
        }

        $open = $repo->list_open_sessions(0);

        echo '<div class="wrap"><h1>G2A POS Registers</h1>';
        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<h2>Open Register</h2><form method="post">';
        wp_nonce_field('g2a_pos_register_action');
        echo '<input type="hidden" name="g2a_register_action" value="open" />';
        echo '<table class="form-table"><tr><th>Register Code</th><td><input name="register_code" value="COUNTER-1" required></td></tr>';
        echo '<tr><th>Location ID</th><td><input type="number" name="location_id" value="1" required></td></tr>';
        echo '<tr><th>Opening Cash</th><td><input type="number" step="0.01" name="opening_cash" value="500" required></td></tr></table>';
        submit_button('Open Register');
        echo '</form>';

        echo '<h2>Close Register</h2><form method="post">';
        wp_nonce_field('g2a_pos_register_action');
        echo '<input type="hidden" name="g2a_register_action" value="close" />';
        echo '<table class="form-table"><tr><th>Session ID</th><td><input type="number" name="register_session_id" required></td></tr>';
        echo '<tr><th>Closing Cash</th><td><input type="number" step="0.01" name="closing_cash" required></td></tr>';
        echo '<tr><th>Notes</th><td><input name="notes"></td></tr></table>';
        submit_button('Close Register');
        echo '</form>';

        echo '<h2>Open Sessions</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Register</th><th>Location</th><th>Opened By</th><th>Opening Cash</th><th>Opened At</th></tr></thead><tbody>';
        foreach ($open as $row) {
            echo '<tr><td>' . esc_html((string) $row['id']) . '</td><td>' . esc_html((string) $row['register_code']) . '</td><td>' . esc_html((string) $row['location_id']) . '</td><td>' . esc_html((string) $row['opened_by']) . '</td><td>' . esc_html((string) $row['opening_cash']) . '</td><td>' . esc_html((string) $row['opened_at']) . '</td></tr>';
        }
        if (!$open) {
            echo '<tr><td colspan="6">No open sessions.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function orders(): void
    {
        if (!current_user_can('g2a_pos_access')) {
            wp_die('Access denied');
        }

        $notice = '';
        $error = '';
        if (isset($_POST['g2a_order_update_states']) && check_admin_referer('g2a_pos_order_states')) {
            $order_id = (int) ($_POST['order_id'] ?? 0);
            $repo = new OrderRepository();
            $order = $repo->find_with_items($order_id);
            if (!$order) {
                $error = 'Order not found.';
            } else {
                $status = sanitize_key((string) ($_POST['status'] ?? $order['status']));
                $payment = sanitize_key((string) ($_POST['payment_status'] ?? $order['payment_status']));
                $compliance = sanitize_key((string) ($_POST['compliance_state'] ?? $order['compliance_state']));
                $has_firearm = false;
                foreach (($order['items'] ?? []) as $item) {
                    if (($item['item_type'] ?? '') === 'firearm') {
                        $has_firearm = true;
                        break;
                    }
                }
                if ($has_firearm && in_array($payment, ['paid', 'completed'], true) && !in_array($compliance, ['approved', 'cleared'], true)) {
                    $error = 'Firearm order cannot be finalized until compliance is approved.';
                } else {
                    $ok = $repo->update_states($order_id, [
                        'status' => $status,
                        'payment_status' => $payment,
                        'compliance_state' => $compliance,
                    ]);
                    if ($ok) {
                        $notice = 'Order states updated.';
                    } else {
                        $error = 'Unable to update order states.';
                    }
                }
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'g2a_pos_orders';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 50", ARRAY_A) ?: [];

        echo '<div class="wrap"><h1>G2A POS Orders</h1>';
        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $view_id = isset($_GET['view_order']) ? (int) $_GET['view_order'] : 0;
        if ($view_id > 0) {
            $repo = new OrderRepository();
            $order = $repo->find_with_items($view_id);
            if ($order) {
                echo '<h2>Order #' . esc_html((string) $order['id']) . '</h2>';
                echo '<p><strong>Compliance State:</strong> ' . esc_html((string) $order['compliance_state']) . ' | <strong>Payment:</strong> ' . esc_html((string) $order['payment_status']) . '</p>';
                echo '<p><strong>Register Session:</strong> ' . esc_html((string) $order['register_session_id']) . ' | <strong>Woo Order:</strong> ' . esc_html((string) $order['wc_order_id']) . '</p>';

                echo '<h3>Update Order States</h3><form method="post">';
                wp_nonce_field('g2a_pos_order_states');
                echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order['id']) . '" />';
                echo '<table class="form-table">';
                echo '<tr><th>Status</th><td><select name="status">';
                foreach (['open', 'hold', 'completed', 'cancelled'] as $opt) {
                    echo '<option value="' . esc_attr($opt) . '"' . selected($order['status'], $opt, false) . '>' . esc_html($opt) . '</option>';
                }
                echo '</select></td></tr>';
                echo '<tr><th>Payment Status</th><td><select name="payment_status">';
                foreach (['pending', 'authorized', 'paid', 'refunded', 'voided'] as $opt) {
                    echo '<option value="' . esc_attr($opt) . '"' . selected($order['payment_status'], $opt, false) . '>' . esc_html($opt) . '</option>';
                }
                echo '</select></td></tr>';
                echo '<tr><th>Compliance State</th><td><select name="compliance_state">';
                foreach (['pending_review', 'documents_required', 'approved', 'cleared', 'rejected'] as $opt) {
                    echo '<option value="' . esc_attr($opt) . '"' . selected($order['compliance_state'], $opt, false) . '>' . esc_html($opt) . '</option>';
                }
                echo '</select></td></tr>';
                echo '</table><p class="submit"><button class="button button-primary" name="g2a_order_update_states" value="1">Update States</button></p></form><hr />';

                echo '<h3>Items</h3><table class="widefat striped"><thead><tr><th>Product</th><th>Type</th><th>Serial</th><th>Qty</th><th>Line Total</th></tr></thead><tbody>';
                foreach ($order['items'] as $item) {
                    echo '<tr><td>' . esc_html((string) $item['product_id']) . '</td><td>' . esc_html((string) $item['item_type']) . '</td><td>' . esc_html((string) $item['serial_number']) . '</td><td>' . esc_html((string) $item['quantity']) . '</td><td>' . esc_html((string) $item['line_total']) . '</td></tr>';
                }
                echo '</tbody></table><hr />';
            }
        }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>Compliance</th><th>Total</th><th>Register Session</th><th>Woo Order</th><th>Created</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = add_query_arg(['page' => 'g2a-pos-orders', 'view_order' => (int) $row['id']], admin_url('admin.php'));
            echo '<tr><td>' . esc_html((string) $row['id']) . '</td><td>' . esc_html((string) $row['status']) . '</td><td>' . esc_html((string) $row['compliance_state']) . '</td><td>' . esc_html((string) $row['grand_total']) . '</td><td>' . esc_html((string) $row['register_session_id']) . '</td><td>' . esc_html((string) $row['wc_order_id']) . '</td><td>' . esc_html((string) $row['created_at']) . '</td><td><a class="button button-small" href="' . esc_url($url) . '">View</a></td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="8">No POS orders yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function settings(): void
    {
        if (!current_user_can('g2a_pos_manage_settings')) {
            wp_die('Access denied');
        }

        $notice = '';
        if (isset($_POST['g2a_pos_settings_save']) && check_admin_referer('g2a_pos_settings')) {
            update_option('g2a_pos_default_register_code', sanitize_text_field((string) $_POST['default_register_code']));
            update_option('g2a_pos_default_location_id', (int) $_POST['default_location_id']);
            $notice = 'Settings saved.';
        }

        $register = (string) get_option('g2a_pos_default_register_code', 'COUNTER-1');
        $location = (int) get_option('g2a_pos_default_location_id', 1);

        echo '<div class="wrap"><h1>G2A POS Settings</h1>';
        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<form method="post">';
        wp_nonce_field('g2a_pos_settings');
        echo '<table class="form-table"><tr><th>Default Register Code</th><td><input name="default_register_code" value="' . esc_attr($register) . '"></td></tr>';
        echo '<tr><th>Default Location ID</th><td><input type="number" name="default_location_id" value="' . esc_attr((string) $location) . '"></td></tr></table>';
        echo '<p class="submit"><button class="button button-primary" name="g2a_pos_settings_save" value="1">Save Settings</button></p>';
        echo '</form></div>';
    }

    public static function inventory(): void
    {
        if (!current_user_can('g2a_pos_manage_inventory')) {
            wp_die('Access denied');
        }

        $notice = '';
        $error = '';
        $product_id = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

        if (isset($_POST['g2a_inventory_adjust']) && check_admin_referer('g2a_pos_inventory_adjust')) {
            $product_id = (int) ($_POST['product_id'] ?? 0);
            $location_id = (int) ($_POST['location_id'] ?? (int) get_option('g2a_pos_default_location_id', 1));
            $delta = (float) ($_POST['quantity_delta'] ?? 0);
            $before_qty = (float) ($_POST['before_qty'] ?? 0);

            if ($product_id <= 0 || $location_id <= 0 || $delta == 0.0) {
                $error = 'product_id, location_id, and non-zero quantity delta are required.';
            } else {
                $res = InventoryEngine::adjust([
                    'product_id' => $product_id,
                    'location_id' => $location_id,
                    'quantity_delta' => $delta,
                    'before_qty' => $before_qty,
                    'event_type' => 'manual_adjustment',
                    'source_ref' => 'admin_inventory',
                ]);
                $notice = 'Inventory adjusted. Log ID #' . (int) ($res['inventory_log_id'] ?? 0) . ' | After Qty: ' . (string) ($res['after_qty'] ?? '');
            }
        }

        $repo = new \G2A\POS\Database\InventoryRepository();
        $logs = $product_id > 0 ? $repo->latest_for_product($product_id, 50) : [];
        $woo_stock = null;
        if ($product_id > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) {
                $woo_stock = $product->get_stock_quantity();
            }
        }

        echo '<div class="wrap"><h1>G2A POS Inventory</h1>';
        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('g2a_pos_inventory_adjust');
        echo '<table class="form-table">';
        echo '<tr><th>Product ID</th><td><input type="number" name="product_id" value="' . esc_attr((string) ($product_id ?: 0)) . '" required></td></tr>';
        echo '<tr><th>Location ID</th><td><input type="number" name="location_id" value="' . esc_attr((string) (int) get_option('g2a_pos_default_location_id', 1)) . '" required></td></tr>';
        echo '<tr><th>Before Qty (known)</th><td><input type="number" step="0.001" name="before_qty" value="0"></td></tr>';
        echo '<tr><th>Quantity Delta</th><td><input type="number" step="0.001" name="quantity_delta" required><p class="description">Use negative for sale shrink, positive for receiving/restock.</p></td></tr>';
        echo '</table><p class="submit"><button class="button button-primary" name="g2a_inventory_adjust" value="1">Apply Adjustment</button></p>';
        echo '</form>';

        echo '<hr><h2>Product Stock Snapshot</h2>';
        if ($product_id > 0) {
            echo '<p><strong>Product ID:</strong> ' . esc_html((string) $product_id) . ' | <strong>Woo Stock Qty:</strong> ' . esc_html((string) ($woo_stock ?? 'n/a')) . '</p>';
        } else {
            echo '<p>Set Product ID to inspect logs and Woo stock.</p>';
        }

        echo '<h2>Recent Inventory Logs</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Event</th><th>Delta</th><th>Before</th><th>After</th><th>Source</th><th>Created</th></tr></thead><tbody>';
        foreach ($logs as $row) {
            echo '<tr><td>' . esc_html((string) $row['id']) . '</td><td>' . esc_html((string) $row['event_type']) . '</td><td>' . esc_html((string) $row['quantity_delta']) . '</td><td>' . esc_html((string) $row['before_qty']) . '</td><td>' . esc_html((string) $row['after_qty']) . '</td><td>' . esc_html((string) $row['source_ref']) . '</td><td>' . esc_html((string) $row['created_at']) . '</td></tr>';
        }
        if (!$logs) {
            echo '<tr><td colspan="7">No logs for this product yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function dashboard_app(): void { self::render_spa('dashboard'); }
    public static function spa_orders(): void     { self::render_spa('orders'); }
    public static function spa_inventory(): void         { self::render_spa('inventory'); }
    public static function spa_catalog_identities(): void { self::render_spa('catalog_identities'); }
    public static function spa_used_firearms(): void      { self::render_spa('used_firearms'); }
    public static function spa_inventory_import(): void  { self::render_spa('inventory_import'); }
    public static function spa_distributors(): void      { self::render_spa('distributors'); }
    public static function spa_4473_calibration(): void  { self::render_spa('forms_4473_calibration'); }
    public static function spa_membership(): void        { self::render_spa('membership'); }
    public static function spa_bound_book(): void { self::render_spa('bound_book'); }
    public static function spa_4473(): void       { self::render_spa('forms_4473'); }
    public static function spa_ccw_exemption(): void { self::render_spa('ccw_exemption'); }
    public static function spa_registers(): void  { self::render_spa('registers'); }
    public static function spa_reports(): void    { self::render_spa('reports'); }
    public static function spa_webhooks(): void   { self::render_spa('webhooks'); }
    public static function spa_settings(): void   { self::render_spa('settings'); }
    public static function spa_wholesalers(): void      { self::render_spa('wholesalers'); }
    public static function spa_vendor_catalog(): void   { self::render_spa('vendor_catalog'); }
    public static function spa_map_pricing(): void      { self::render_spa('map_pricing'); }
    public static function spa_dropship_orders(): void  { self::render_spa('dropship_orders'); }
    public static function spa_waivers(): void          { self::render_spa('waivers'); }
    public static function spa_kpis(): void             { self::render_spa('kpis'); }
    public static function spa_customers(): void        { self::render_spa('customers'); }
    public static function spa_repairs(): void          { self::render_spa('repairs'); }
    public static function spa_split_tender(): void     { self::render_spa('split_tender'); }
    public static function spa_order_sourcing(): void   { self::render_spa('order_sourcing'); }
    public static function spa_layaways(): void         { self::render_spa('layaways'); }
    public static function spa_consignments(): void     { self::render_spa('consignments'); }
    public static function spa_lanes(): void            { self::render_spa('lane_reservations'); }
    public static function spa_classes(): void          { self::render_spa('classes'); }
    public static function spa_loyalty(): void          { self::render_spa('loyalty'); }
    public static function spa_gift_cards(): void       { self::render_spa('gift_cards'); }
    public static function spa_purchase_orders(): void  { self::render_spa('purchase_orders'); }
    public static function spa_cycle_counts(): void     { self::render_spa('cycle_counts'); }
    public static function spa_shipping(): void         { self::render_spa('shipping'); }
    public static function spa_messaging(): void        { self::render_spa('messaging'); }
    public static function spa_nfa(): void               { self::render_spa('nfa'); }
    public static function spa_location_transfers(): void{ self::render_spa('location_transfers'); }
    public static function spa_range_ops(): void         { self::render_spa('range_ops'); }
    public static function spa_range_safety(): void      { self::render_spa('range_safety'); }
    public static function spa_tradeins(): void          { self::render_spa('tradeins'); }
    public static function spa_hardware(): void          { self::render_spa('hardware'); }
    public static function spa_compliance_calendar(): void { self::render_spa('compliance_calendar'); }
    public static function spa_ace_audit(): void         { self::render_spa('ace_audit'); }
    public static function spa_ffl_routing(): void       { self::render_spa('ffl_routing'); }
    public static function spa_ai_settings(): void       { self::render_spa('ai_settings'); }
    public static function spa_ai_brain(): void          { self::render_spa('ai_brain'); }
    public static function spa_ai_audit(): void          { self::render_spa('ai_audit'); }

    private static function render_spa(string $initial_view): void
    {
        $user = wp_get_current_user();
        $cfg = [
            'restBase' => esc_url_raw(rest_url('g2a-pos/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => defined('G2A_POS_CORE_VERSION') ? G2A_POS_CORE_VERSION : '',
            'currentUser' => [
                'id' => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'roles' => array_values((array) $user->roles),
            ],
            'initialView' => $initial_view,
        ];
        $base = G2A_POS_CORE_URL . 'assets/admin/';
        $version = defined('G2A_POS_CORE_VERSION') ? G2A_POS_CORE_VERSION : '0';
        // Mark body so the SPA can override WordPress admin chrome (margins,
        // max-widths, .wrap padding) without using :has() which isn't
        // universally supported. Set BEFORE the React bundle loads so there's
        // no flash of mis-laid-out content.
        echo '<script>document.body.classList.add("g2a-pos-fullwidth");</script>';
        echo '<style id="g2a-pos-fullwidth-css">'
            . 'body.g2a-pos-fullwidth #wpcontent{padding-left:0!important;}'
            . 'body.g2a-pos-fullwidth.auto-fold #wpcontent{padding-left:0!important;}'
            . 'body.g2a-pos-fullwidth #wpbody-content{padding-bottom:0!important;float:none!important;width:auto!important;}'
            . 'body.g2a-pos-fullwidth #wpbody-content > .wrap{margin:0!important;padding:0!important;max-width:none!important;width:auto!important;}'
            . 'body.g2a-pos-fullwidth #wpfooter{display:none!important;}'
            . 'body.g2a-pos-fullwidth #g2a-pos-dashboard{width:100%;max-width:100%;}'
            // WP core admin CSS ships its own `.card { max-width: 520px;
            // margin-top: 20px; padding: .7em 2em 1em }` (common.css). The
            // SPA's Tailwind `.card` never resets max-width/margin, so EVERY
            // card + records table in the POS was silently capped at 520px —
            // the "half-width blocks" bug. Neutralize the core rule inside
            // the app root; Tailwind's own p-*/m-* utilities still apply.
            . 'body.g2a-pos-fullwidth #g2a-pos-dashboard .card{max-width:none!important;min-width:0!important;margin-top:0!important;padding:0!important;}'
            // Restore the Tailwind padding utilities the !important reset
            // above would otherwise defeat (p-3/p-4/p-5/p-6 are the sizes
            // the SPA uses on cards).
            . 'body.g2a-pos-fullwidth #g2a-pos-dashboard .card.p-3{padding:.75rem!important;}'
            . 'body.g2a-pos-fullwidth #g2a-pos-dashboard .card.p-4{padding:1rem!important;}'
            . 'body.g2a-pos-fullwidth #g2a-pos-dashboard .card.p-5{padding:1.25rem!important;}'
            . 'body.g2a-pos-fullwidth #g2a-pos-dashboard .card.p-6{padding:1.5rem!important;}'
            . '</style>';
        echo '<div class="wrap" style="margin:0;padding:0">';
        echo '<script>window.G2A_POS_ADMIN = ' . wp_json_encode($cfg) . ';';
        echo 'if (location.hash === "" || location.hash === "#") location.hash = ' . wp_json_encode($initial_view) . ';';
        echo '</script>';
        echo '<link rel="stylesheet" href="' . esc_url($base . 'admin.css?v=' . $version) . '">';
        echo '<div id="g2a-pos-dashboard" style="min-height:calc(100vh - 32px);width:100%"></div>';
        echo '<script type="module" src="' . esc_url($base . 'admin.js?v=' . $version) . '"></script>';
        echo '</div>';
    }
}
