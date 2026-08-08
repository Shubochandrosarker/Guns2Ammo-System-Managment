<?php

namespace G2A\POS\Admin;

use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Database\WholesalerCategoryRepository;
use G2A\POS\Database\WholesalerOrderRepository;
use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerRepository;
use G2A\POS\Database\WholesalerSyncRepository;
use G2A\POS\Wholesalers\WholesalerRegistry;

defined('ABSPATH') || exit;

final class WholesalerPages
{
    private const MAX_CSV_UPLOAD_BYTES = 52428800; // 50 MB

    private const ACCEPTABLE_CSV_MIMES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream',
    ];

    private static function is_acceptable_csv_upload(array $file): bool
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_CSV_UPLOAD_BYTES) {
            return false;
        }
        $name = (string) ($file['name'] ?? '');
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return false;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return false;
        }
        $detected = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = (string) finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if ($detected === '' || in_array($detected, self::ACCEPTABLE_CSV_MIMES, true)) {
            return true;
        }
        return false;
    }

    public static function index(): void
    {
        if (!current_user_can('g2a_pos_manage_wholesalers')) {
            wp_die('Access denied');
        }

        $repo = new WholesalerRepository();
        $notice = '';
        $error = '';

        if (isset($_POST['g2a_wholesaler_save']) && check_admin_referer('g2a_pos_wholesaler')) {
            $code = sanitize_key((string) ($_POST['provider_code'] ?? ''));
            if (!WholesalerRegistry::get($code)) {
                $error = 'Unknown provider.';
            } else {
                $creds = [
                    'email' => sanitize_text_field((string) ($_POST['cred_email'] ?? '')),
                    'password' => (string) ($_POST['cred_password'] ?? ''),
                    'ftp_user' => sanitize_text_field((string) ($_POST['cred_ftp_user'] ?? '')),
                ];
                // Keep existing password if blank.
                if ($creds['password'] === '' && !empty($_POST['wholesaler_id'])) {
                    $existing = $repo->find((int) $_POST['wholesaler_id']);
                    if ($existing) {
                        $decoded = $repo->decodeCredentials($existing);
                        $creds['password'] = (string) ($decoded['password'] ?? '');
                    }
                }
                $id = $repo->upsert([
                    'provider_code' => $code,
                    'display_name' => sanitize_text_field((string) ($_POST['display_name'] ?? '')),
                    'account_number' => sanitize_text_field((string) ($_POST['account_number'] ?? '')),
                    'api_endpoint' => esc_url_raw((string) ($_POST['api_endpoint'] ?? '')),
                    'credentials' => $creds,
                    'status' => sanitize_key((string) ($_POST['status'] ?? 'active')),
                ]);
                $notice = 'Wholesaler #' . $id . ' saved.';
            }
        }

        $rows = $repo->all();

        echo '<div class="wrap"><h1>Wholesalers</h1>';
        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }
        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<h2>Configured Wholesalers</h2>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Provider</th><th>Display Name</th><th>Account</th><th>Status</th><th>Last Sync</th><th>Products</th></tr></thead><tbody>';
        $productRepo = new WholesalerProductRepository();
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['id']) . '</td>';
            echo '<td>' . esc_html((string) $row['provider_code']) . '</td>';
            echo '<td>' . esc_html((string) $row['display_name']) . '</td>';
            echo '<td>' . esc_html((string) ($row['account_number'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) $row['status']) . '</td>';
            echo '<td>' . esc_html((string) ($row['last_sync_at'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) $productRepo->countByWholesaler((int) $row['id'])) . '</td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7">No wholesalers configured yet.</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Add / Edit Wholesaler</h2><form method="post">';
        wp_nonce_field('g2a_pos_wholesaler');
        echo '<table class="form-table">';
        echo '<tr><th>Provider</th><td><select name="provider_code">';
        foreach (WholesalerRegistry::all() as $p) {
            echo '<option value="' . esc_attr($p->code()) . '">' . esc_html($p->displayName()) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Display Name</th><td><input name="display_name" value="Lipsey\'s — Production"></td></tr>';
        echo '<tr><th>Account Number</th><td><input name="account_number"><p class="description">Required when running more than one account for the same provider (e.g. separate Lipsey\'s firearms and accessories accounts) — feeds and syncs match accounts by this number. Saving without an account number always creates a new entry.</p></td></tr>';
        echo '<tr><th>API Endpoint</th><td><input name="api_endpoint" value="https://api.lipseys.com" class="regular-text"></td></tr>';
        echo '<tr><th>API Email</th><td><input name="cred_email" type="email"></td></tr>';
        echo '<tr><th>API Password</th><td><input name="cred_password" type="password" autocomplete="new-password"><p class="description">Stored encrypted (AES-256) using your AUTH_KEY salt.</p></td></tr>';
        echo '<tr><th>FTP User (optional)</th><td><input name="cred_ftp_user"></td></tr>';
        echo '<tr><th>Status</th><td><select name="status"><option value="active">active</option><option value="paused">paused</option><option value="disabled">disabled</option></select></td></tr>';
        echo '</table>';
        echo '<p class="submit"><button class="button button-primary" name="g2a_wholesaler_save" value="1">Save Wholesaler</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public static function import(): void
    {
        if (!current_user_can('g2a_pos_manage_wholesalers')) {
            wp_die('Access denied');
        }

        $notice = '';
        $error = '';
        $syncRepo = new WholesalerSyncRepository();
        $repo = new WholesalerRepository();
        $wholesalers = $repo->all();

        if (isset($_POST['g2a_csv_import']) && check_admin_referer('g2a_pos_csv_import')) {
            $wholesalerId = (int) ($_POST['wholesaler_id'] ?? 0);
            $w = $repo->find($wholesalerId);
            if (!$w) {
                $error = 'Select a wholesaler first.';
            } elseif (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                $error = 'Choose a CSV file to upload.';
            } elseif (!self::is_acceptable_csv_upload($_FILES['csv_file'])) {
                $error = 'Uploaded file must be a CSV (text/csv, up to 50 MB).';
            } else {
                $provider = WholesalerRegistry::get((string) $w['provider_code']);
                if (!$provider || !$provider->supportsCatalogCsv()) {
                    $error = 'Provider does not support CSV import.';
                } else {
                    $tmp = (string) $_FILES['csv_file']['tmp_name'];
                    $label = sanitize_file_name((string) ($_FILES['csv_file']['name'] ?? basename($tmp)));
                    $result = $provider->importCatalogCsv($wholesalerId, $tmp, ['file_label' => $label]);
                    if (!empty($result['ok'])) {
                        $repo->markSyncedNow($wholesalerId);
                        $stats = $result['stats'] ?? [];
                        $notice = sprintf(
                            'Imported %d rows (created %d, updated %d, skipped %d, failed %d). MAP rules upserted: %d. Sync run #%d.',
                            (int) ($stats['rows_total'] ?? 0),
                            (int) ($stats['rows_created'] ?? 0),
                            (int) ($stats['rows_updated'] ?? 0),
                            (int) ($stats['rows_skipped'] ?? 0),
                            (int) ($stats['rows_failed'] ?? 0),
                            (int) ($stats['map_rules_upserted'] ?? 0),
                            (int) ($result['sync_run_id'] ?? 0)
                        );
                    } else {
                        $error = 'Import failed: ' . esc_html((string) ($result['error'] ?? 'unknown'));
                    }
                }
            }
        }

        echo '<div class="wrap"><h1>Catalog CSV Import</h1>';
        echo '<p>Upload Lipsey\'s master catalog or accessories catalog CSV (the dealer download). The importer maps every column in the file, including MAP, quantity, drop-ship flag, FFL/SOT flags, and full firearm attributes.</p>';
        if ($notice) {
            echo '<div class="notice notice-success"><p>' . $notice . '</p></div>';
        }
        if ($error) {
            echo '<div class="notice notice-error"><p>' . $error . '</p></div>';
        }
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('g2a_pos_csv_import');
        echo '<table class="form-table"><tr><th>Wholesaler</th><td><select name="wholesaler_id">';
        foreach ($wholesalers as $w) {
            echo '<option value="' . esc_attr((string) $w['id']) . '">' . esc_html($w['display_name'] . ' (#' . $w['id'] . ' / ' . $w['provider_code'] . ')') . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>CSV File</th><td><input type="file" name="csv_file" accept=".csv" required></td></tr>';
        echo '</table><p class="submit"><button class="button button-primary" name="g2a_csv_import" value="1">Run Import</button></p>';
        echo '</form>';

        if ($wholesalers) {
            echo '<h2>Recent Sync Runs</h2>';
            foreach ($wholesalers as $w) {
                $runs = $syncRepo->listRecent((int) $w['id'], 10);
                if (!$runs) continue;
                echo '<h3>' . esc_html((string) $w['display_name']) . '</h3>';
                echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Type</th><th>File</th><th>Status</th><th>Total</th><th>Created</th><th>Updated</th><th>Failed</th><th>Started</th><th>Finished</th></tr></thead><tbody>';
                foreach ($runs as $r) {
                    echo '<tr>';
                    echo '<td>' . esc_html((string) $r['id']) . '</td>';
                    echo '<td>' . esc_html((string) $r['sync_type']) . '</td>';
                    echo '<td>' . esc_html((string) ($r['file_label'] ?? '')) . '</td>';
                    echo '<td>' . esc_html((string) $r['status']) . '</td>';
                    echo '<td>' . esc_html((string) $r['rows_total']) . '</td>';
                    echo '<td>' . esc_html((string) $r['rows_created']) . '</td>';
                    echo '<td>' . esc_html((string) $r['rows_updated']) . '</td>';
                    echo '<td>' . esc_html((string) $r['rows_failed']) . '</td>';
                    echo '<td>' . esc_html((string) $r['started_at']) . '</td>';
                    echo '<td>' . esc_html((string) ($r['finished_at'] ?? '')) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
        echo '</div>';
    }

    public static function catalog(): void
    {
        if (!current_user_can('g2a_pos_access')) {
            wp_die('Access denied');
        }

        $repo = new WholesalerProductRepository();
        $wRepo = new WholesalerRepository();
        $wholesalers = $wRepo->all();

        $wholesalerId = isset($_GET['wholesaler_id']) ? (int) $_GET['wholesaler_id'] : (int) ($wholesalers[0]['id'] ?? 0);
        $term = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
        $category = isset($_GET['category']) ? sanitize_text_field((string) $_GET['category']) : '';
        $itemType = isset($_GET['item_type']) ? sanitize_key((string) $_GET['item_type']) : '';
        $inStock = !empty($_GET['in_stock']);
        $page = max(1, (int) ($_GET['paged'] ?? 1));

        $rows = $repo->search([
            'wholesaler_id' => $wholesalerId,
            'term' => $term,
            'category' => $category,
            'item_type' => $itemType,
            'in_stock' => $inStock,
            'limit' => 50,
            'offset' => ($page - 1) * 50,
        ]);

        echo '<div class="wrap"><h1>Vendor Catalog</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="g2a-pos-vendor-catalog">';
        echo '<select name="wholesaler_id">';
        foreach ($wholesalers as $w) {
            echo '<option value="' . esc_attr((string) $w['id']) . '"' . selected($wholesalerId, (int) $w['id'], false) . '>' . esc_html((string) $w['display_name']) . '</option>';
        }
        echo '</select>';
        echo ' <input type="search" name="q" value="' . esc_attr($term) . '" placeholder="UPC, SKU, name, manufacturer">';
        echo ' <input type="text" name="category" value="' . esc_attr($category) . '" placeholder="Vendor Category">';
        echo ' <select name="item_type"><option value="">Any type</option>';
        foreach (['firearm', 'ammunition', 'accessory', 'optic', 'nfa'] as $t) {
            echo '<option value="' . esc_attr($t) . '"' . selected($itemType, $t, false) . '>' . esc_html($t) . '</option>';
        }
        echo '</select>';
        echo ' <label><input type="checkbox" name="in_stock" value="1"' . checked($inStock, true, false) . '> In stock</label>';
        submit_button('Filter', 'secondary', '', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr><th>SKU</th><th>UPC</th><th>Mfg</th><th>Name</th><th>Category</th><th>Type</th><th>Qty</th><th>Cost</th><th>MAP</th><th>MSRP</th><th>DropShip</th><th>FFL</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $r['vendor_sku']) . '</code></td>';
            echo '<td>' . esc_html((string) ($r['upc'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['manufacturer'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) $r['name']) . '</td>';
            echo '<td>' . esc_html((string) ($r['vendor_category'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['item_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) $r['stock_qty']) . '</td>';
            echo '<td>' . esc_html(self::fmt_money($r['current_price'] ?? $r['wholesale_price'])) . '</td>';
            echo '<td>' . esc_html(self::fmt_money($r['map_price'])) . '</td>';
            echo '<td>' . esc_html(self::fmt_money($r['msrp'])) . '</td>';
            echo '<td>' . ((int) $r['can_dropship'] ? 'Yes' : 'No') . '</td>';
            echo '<td>' . ((int) $r['ffl_required'] ? 'Yes' : 'No') . '</td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="12">No products found. Run a catalog import first.</td></tr>';
        }
        echo '</tbody></table>';

        $prev = max(1, $page - 1);
        $next = $page + 1;
        echo '<p>';
        echo '<a class="button" href="' . esc_url(add_query_arg(['paged' => $prev])) . '">&laquo; Prev</a> ';
        echo '<a class="button" href="' . esc_url(add_query_arg(['paged' => $next])) . '">Next &raquo;</a>';
        echo ' &nbsp; Page ' . (int) $page;
        echo '</p>';
        echo '</div>';
    }

    public static function categories(): void
    {
        if (!current_user_can('g2a_pos_manage_wholesalers')) {
            wp_die('Access denied');
        }
        $wRepo = new WholesalerRepository();
        $catRepo = new WholesalerCategoryRepository();
        $wholesalers = $wRepo->all();
        $wholesalerId = isset($_GET['wholesaler_id']) ? (int) $_GET['wholesaler_id'] : (int) ($wholesalers[0]['id'] ?? 0);

        if (isset($_POST['g2a_cat_save']) && check_admin_referer('g2a_pos_categories')) {
            $flags = (array) ($_POST['flags'] ?? []);
            foreach ($flags as $catId => $row) {
                $catRepo->setFlags((int) $catId, [
                    'import_enabled' => isset($row['import_enabled']) ? 1 : 0,
                    'dropship_enabled' => isset($row['dropship_enabled']) ? 1 : 0,
                    'markup_percent' => isset($row['markup_percent']) && $row['markup_percent'] !== '' ? (float) $row['markup_percent'] : null,
                    'display_label' => sanitize_text_field((string) ($row['display_label'] ?? '')),
                ]);
            }
            echo '<div class="notice notice-success"><p>Category settings updated.</p></div>';
        }

        $rows = $catRepo->all($wholesalerId);

        echo '<div class="wrap"><h1>Vendor Categories</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="g2a-pos-vendor-categories"><select name="wholesaler_id">';
        foreach ($wholesalers as $w) {
            echo '<option value="' . esc_attr((string) $w['id']) . '"' . selected($wholesalerId, (int) $w['id'], false) . '>' . esc_html((string) $w['display_name']) . '</option>';
        }
        echo '</select>';
        submit_button('Switch', 'secondary', '', false);
        echo '</form>';

        echo '<p><em>Toggle which vendor categories should be allowed to import into the store and to allow drop-shipping. Use markup % to apply a default sell-price uplift over the wholesale cost when promoting a vendor item into a WooCommerce product.</em></p>';

        echo '<form method="post">';
        wp_nonce_field('g2a_pos_categories');
        echo '<table class="widefat striped"><thead><tr><th>Vendor Category</th><th>Type</th><th>Display Label</th><th>Import?</th><th>Drop-Ship?</th><th>Markup %</th><th>Products</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $r['vendor_category']) . '</code></td>';
            echo '<td>' . esc_html((string) ($r['item_type'] ?? '')) . '</td>';
            echo '<td><input name="flags[' . $id . '][display_label]" value="' . esc_attr((string) ($r['display_label'] ?? '')) . '" class="regular-text"></td>';
            echo '<td><input type="checkbox" name="flags[' . $id . '][import_enabled]" value="1"' . checked((int) $r['import_enabled'], 1, false) . '></td>';
            echo '<td><input type="checkbox" name="flags[' . $id . '][dropship_enabled]" value="1"' . checked((int) $r['dropship_enabled'], 1, false) . '></td>';
            echo '<td><input type="number" step="0.01" name="flags[' . $id . '][markup_percent]" value="' . esc_attr((string) ($r['markup_percent'] ?? '')) . '" style="width:80px"></td>';
            echo '<td>' . esc_html((string) $r['product_count']) . '</td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7">No categories yet. Run a catalog import to discover categories.</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="submit"><button class="button button-primary" name="g2a_cat_save" value="1">Save Category Settings</button></p>';
        echo '</form></div>';
    }

    public static function map_pricing(): void
    {
        if (!current_user_can('g2a_pos_manage_pricing')) {
            wp_die('Access denied');
        }
        $repo = new MapRuleRepository();

        if (isset($_POST['g2a_map_save']) && check_admin_referer('g2a_pos_map')) {
            $repo->setForWcProduct((int) $_POST['wc_product_id'], [
                'map_price' => (float) $_POST['map_price'],
                'display_mode' => sanitize_key((string) ($_POST['display_mode'] ?? 'click_to_reveal')),
                'override_label' => sanitize_text_field((string) ($_POST['override_label'] ?? '')),
                'notes' => sanitize_textarea_field((string) ($_POST['notes'] ?? '')),
                'effective_at' => $_POST['effective_at'] ?: null,
                'expires_at' => $_POST['expires_at'] ?: null,
                'source' => 'admin_ui',
            ]);
            echo '<div class="notice notice-success"><p>MAP rule saved.</p></div>';
        }

        $rules = $repo->list(150);

        echo '<div class="wrap"><h1>MAP Pricing</h1>';
        echo '<p>Minimum Advertised Price rules are enforced at the storefront level: when the active sale price is below MAP, the public price is replaced with a click-to-reveal prompt — the actual price still applies in cart and at checkout. POS sales are not blocked. Every suppression is logged for compliance.</p>';

        echo '<h2>Add / Update Rule</h2><form method="post"><table class="form-table">';
        wp_nonce_field('g2a_pos_map');
        echo '<tr><th>WooCommerce Product ID</th><td><input type="number" name="wc_product_id" required></td></tr>';
        echo '<tr><th>MAP Price</th><td><input type="number" step="0.01" name="map_price" required></td></tr>';
        echo '<tr><th>Display Mode</th><td><select name="display_mode"><option value="click_to_reveal">Click to reveal (hide price)</option><option value="show_map">Show MAP as displayed price</option></select></td></tr>';
        echo '<tr><th>Override Label</th><td><input name="override_label" placeholder="e.g. See cart for price" class="regular-text"></td></tr>';
        echo '<tr><th>Effective</th><td><input type="date" name="effective_at"></td></tr>';
        echo '<tr><th>Expires</th><td><input type="date" name="expires_at"></td></tr>';
        echo '<tr><th>Notes</th><td><textarea name="notes" rows="2" class="large-text"></textarea></td></tr>';
        echo '</table><p class="submit"><button class="button button-primary" name="g2a_map_save" value="1">Save MAP Rule</button></p></form>';

        echo '<h2>Active Rules</h2><table class="widefat striped"><thead><tr><th>ID</th><th>WC Product</th><th>SKU</th><th>UPC</th><th>Manufacturer</th><th>MAP</th><th>Mode</th><th>Source</th><th>Effective</th><th>Expires</th></tr></thead><tbody>';
        foreach ($rules as $r) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $r['id']) . '</td>';
            echo '<td>' . esc_html((string) ($r['wc_product_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['sku'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['upc'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['manufacturer'] ?? '')) . '</td>';
            echo '<td>' . esc_html(self::fmt_money($r['map_price'])) . '</td>';
            echo '<td>' . esc_html((string) $r['display_mode']) . '</td>';
            echo '<td>' . esc_html((string) $r['source']) . '</td>';
            echo '<td>' . esc_html((string) ($r['effective_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['expires_at'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function dropship_orders(): void
    {
        if (!current_user_can('g2a_pos_manage_wholesalers')) {
            wp_die('Access denied');
        }
        $repo = new WholesalerOrderRepository();
        $rows = $repo->listRecent(100);

        echo '<div class="wrap"><h1>Drop-Ship Orders</h1>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Wholesaler</th><th>POS Order</th><th>External Ref</th><th>Status</th><th>Ship To</th><th>Submitted</th><th>Tracking</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $r['id']) . '</td>';
            echo '<td>' . esc_html((string) $r['wholesaler_id']) . '</td>';
            echo '<td>' . esc_html((string) ($r['pos_order_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['external_order_ref'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) $r['status']) . '</td>';
            echo '<td>' . esc_html(trim(($r['ship_to_name'] ?? '') . ' / ' . ($r['ship_to_city'] ?? '') . ', ' . ($r['ship_to_state'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string) ($r['submitted_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['tracking_number'] ?? '')) . '</td>';
            echo '</tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="8">No drop-ship orders yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function fmt_money($v): string
    {
        if ($v === null || $v === '') return '';
        return '$' . number_format((float) $v, 2);
    }
}
