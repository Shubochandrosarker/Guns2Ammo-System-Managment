<?php

namespace G2A\POS\Admin;

defined('ABSPATH') || exit;

final class Menu
{
    public static function register(): void
    {
        $cap = 'g2a_pos_access';

        add_menu_page(
            'G2A POS',
            'G2A POS',
            $cap,
            'g2a-pos-dashboard',
            [Pages::class, 'dashboard_app'],
            'dashicons-store',
            56
        );

        // Single SPA — all "tabs" are routed inside the React app via the hash.
        add_submenu_page('g2a-pos-dashboard', 'Dashboard',    'Dashboard',    $cap, 'g2a-pos-dashboard',  [Pages::class, 'dashboard_app']);
        add_submenu_page('g2a-pos-dashboard', 'Sales',        'Sales',        $cap, 'g2a-pos-sales',      [Pages::class, 'spa_orders']);
        add_submenu_page('g2a-pos-dashboard', 'Inventory',    'Inventory',    'g2a_pos_manage_inventory', 'g2a-pos-inventory', [Pages::class, 'spa_inventory']);
        add_submenu_page('g2a-pos-dashboard', 'Identity Graph', 'Identity Graph', 'g2a_pos_manage_inventory', 'g2a-pos-identities', [Pages::class, 'spa_catalog_identities']);
        add_submenu_page('g2a-pos-dashboard', 'Used Firearms',  'Used Firearms',  'g2a_pos_edit_bound_book',   'g2a-pos-used-firearms', [Pages::class, 'spa_used_firearms']);
        add_submenu_page('g2a-pos-dashboard', 'Import CSV',   'Import CSV',   'g2a_pos_manage_inventory', 'g2a-pos-inventory-import', [Pages::class, 'spa_inventory_import']);
        add_submenu_page('g2a-pos-dashboard', 'Distributors', 'Distributors', 'g2a_pos_manage_inventory', 'g2a-pos-distributors', [Pages::class, 'spa_distributors']);
        add_submenu_page('g2a-pos-dashboard', 'Bound Book',   'Bound Book',   'g2a_pos_view_bound_book',  'g2a-pos-bound-book', [Pages::class, 'spa_bound_book']);
        add_submenu_page('g2a-pos-dashboard', '4473s',        '4473s',        'g2a_pos_process_firearm_sale', 'g2a-pos-4473s', [Pages::class, 'spa_4473']);
        add_submenu_page('g2a-pos-dashboard', 'CCW Bypass',   'CCW Bypass',   'g2a_pos_process_firearm_sale', 'g2a-pos-ccw',   [Pages::class, 'spa_ccw_exemption']);
        add_submenu_page('g2a-pos-dashboard', '4473 Template', '4473 Template', 'g2a_pos_manage_compliance',   'g2a-pos-4473-template', [Pages::class, 'spa_4473_calibration']);
        add_submenu_page('g2a-pos-dashboard', 'Membership',    'Membership',    'g2a_pos_manage_settings',     'g2a-pos-membership',    [Pages::class, 'spa_membership']);
        add_submenu_page('g2a-pos-dashboard', 'Registers',    'Registers',    'g2a_pos_manage_register',  'g2a-pos-registers', [Pages::class, 'spa_registers']);
        add_submenu_page('g2a-pos-dashboard', 'Reports',      'Reports',      'g2a_pos_manage_settings',  'g2a-pos-reports',   [Pages::class, 'spa_reports']);
        add_submenu_page('g2a-pos-dashboard', 'Webhooks',     'Webhooks',     'g2a_pos_manage_settings',  'g2a-pos-webhooks',  [Pages::class, 'spa_webhooks']);

        // Wholesalers, Vendor Catalog, MAP Pricing, and Drop-Ship now load the
        // React SPA (v0.4.3). Catalog Import and Vendor Categories still use
        // the legacy server-rendered PHP UI — no SPA views yet.
        add_submenu_page('g2a-pos-dashboard', 'Wholesalers',       'Wholesalers',       'g2a_pos_manage_wholesalers', 'g2a-pos-wholesalers',       [Pages::class, 'spa_wholesalers']);
        add_submenu_page('g2a-pos-dashboard', 'Catalog Import',    'Catalog Import',    'g2a_pos_manage_wholesalers', 'g2a-pos-catalog-import',    [WholesalerPages::class, 'import']);
        add_submenu_page('g2a-pos-dashboard', 'Vendor Catalog',    'Vendor Catalog',    $cap,                          'g2a-pos-vendor-catalog',    [Pages::class, 'spa_vendor_catalog']);
        add_submenu_page('g2a-pos-dashboard', 'Vendor Categories', 'Vendor Categories', 'g2a_pos_manage_wholesalers', 'g2a-pos-vendor-categories', [WholesalerPages::class, 'categories']);
        add_submenu_page('g2a-pos-dashboard', 'MAP Pricing',       'MAP Pricing',       'g2a_pos_manage_pricing',     'g2a-pos-map',               [Pages::class, 'spa_map_pricing']);
        add_submenu_page('g2a-pos-dashboard', 'Drop-Ship Orders',  'Drop-Ship Orders',  'g2a_pos_manage_wholesalers', 'g2a-pos-dropship',          [Pages::class, 'spa_dropship_orders']);
        add_submenu_page('g2a-pos-dashboard', 'Range Waivers',     'Range Waivers',     'g2a_pos_view_waivers',                          'g2a-pos-waivers',           [Pages::class, 'spa_waivers']);

        // v0.9.0 — new modules
        add_submenu_page('g2a-pos-dashboard', 'KPI Board',         'KPI Board',         $cap,                          'g2a-pos-kpis',              [Pages::class, 'spa_kpis']);
        add_submenu_page('g2a-pos-dashboard', 'Customers',         'Customers',         'g2a_pos_search_customers',                          'g2a-pos-customers',         [Pages::class, 'spa_customers']);
        add_submenu_page('g2a-pos-dashboard', 'Gunsmithing',       'Gunsmithing',       $cap,                          'g2a-pos-repairs',           [Pages::class, 'spa_repairs']);
        add_submenu_page('g2a-pos-dashboard', 'Split Tender',      'Split Tender',      'g2a_pos_manage_register',     'g2a-pos-split-tender',      [Pages::class, 'spa_split_tender']);
        add_submenu_page('g2a-pos-dashboard', 'Order Sourcing',    'Order Sourcing',    $cap,                          'g2a-pos-order-sourcing',    [Pages::class, 'spa_order_sourcing']);
        add_submenu_page('g2a-pos-dashboard', 'Layaway',           'Layaway',           $cap,                          'g2a-pos-layaways',          [Pages::class, 'spa_layaways']);
        add_submenu_page('g2a-pos-dashboard', 'Consignments',      'Consignments',      'g2a_pos_view_bound_book',                          'g2a-pos-consignments',      [Pages::class, 'spa_consignments']);
        add_submenu_page('g2a-pos-dashboard', 'Lane Reservations', 'Lane Reservations', $cap,                          'g2a-pos-lanes',             [Pages::class, 'spa_lanes']);
        add_submenu_page('g2a-pos-dashboard', 'Classes',           'Classes',           $cap,                          'g2a-pos-classes',           [Pages::class, 'spa_classes']);
        add_submenu_page('g2a-pos-dashboard', 'Loyalty',           'Loyalty',           $cap,                          'g2a-pos-loyalty',           [Pages::class, 'spa_loyalty']);
        add_submenu_page('g2a-pos-dashboard', 'Gift Cards',        'Gift Cards',        $cap,                          'g2a-pos-gift-cards',        [Pages::class, 'spa_gift_cards']);
        add_submenu_page('g2a-pos-dashboard', 'Purchase Orders',   'Purchase Orders',   'g2a_pos_manage_inventory',    'g2a-pos-purchase-orders',   [Pages::class, 'spa_purchase_orders']);
        add_submenu_page('g2a-pos-dashboard', 'Cycle Counts',      'Cycle Counts',      'g2a_pos_manage_inventory',    'g2a-pos-cycle-counts',      [Pages::class, 'spa_cycle_counts']);
        add_submenu_page('g2a-pos-dashboard', 'Shipping',          'Shipping',          $cap,                          'g2a-pos-shipping',          [Pages::class, 'spa_shipping']);
        add_submenu_page('g2a-pos-dashboard', 'Messaging',         'Messaging',         'g2a_pos_manage_settings',     'g2a-pos-messaging',         [Pages::class, 'spa_messaging']);

        // v0.10.0 — deep firearm modules.
        add_submenu_page('g2a-pos-dashboard', 'NFA Items',           'NFA Items',           'g2a_pos_view_bound_book',  'g2a-pos-nfa',                  [Pages::class, 'spa_nfa']);
        add_submenu_page('g2a-pos-dashboard', 'Location Transfers',  'Loc Transfers',       'g2a_pos_manage_inventory', 'g2a-pos-location-transfers',   [Pages::class, 'spa_location_transfers']);
        add_submenu_page('g2a-pos-dashboard', 'Range Operations',    'Range Ops',           $cap,                        'g2a-pos-range-ops',            [Pages::class, 'spa_range_ops']);
        add_submenu_page('g2a-pos-dashboard', 'Range Safety',        'Range Safety',        $cap,                        'g2a-pos-range-safety',         [Pages::class, 'spa_range_safety']);
        add_submenu_page('g2a-pos-dashboard', 'Trade-Ins',           'Trade-Ins',           $cap,                        'g2a-pos-tradeins',             [Pages::class, 'spa_tradeins']);
        add_submenu_page('g2a-pos-dashboard', 'Hardware',            'Hardware',            'g2a_pos_manage_settings',   'g2a-pos-hardware',             [Pages::class, 'spa_hardware']);
        add_submenu_page('g2a-pos-dashboard', 'Compliance Calendar', 'Compliance Calendar', $cap,                        'g2a-pos-compliance-calendar',  [Pages::class, 'spa_compliance_calendar']);
        add_submenu_page('g2a-pos-dashboard', 'ACE Audit Pack',      'ACE Audit',           'g2a_pos_view_bound_book',  'g2a-pos-ace-audit',            [Pages::class, 'spa_ace_audit']);
        add_submenu_page('g2a-pos-dashboard', 'FFL Routing',         'FFL Routing',         $cap,                        'g2a-pos-ffl-routing',          [Pages::class, 'spa_ffl_routing']);

        // v1.0.0 — AI agent.
        add_submenu_page('g2a-pos-dashboard', 'AI Settings',         'AI Settings',         'g2a_pos_manage_ai',         'g2a-pos-ai-settings',          [Pages::class, 'spa_ai_settings']);
        add_submenu_page('g2a-pos-dashboard', 'AI Brain',            'AI Brain',            'g2a_pos_manage_ai',         'g2a-pos-ai-brain',             [Pages::class, 'spa_ai_brain']);
        add_submenu_page('g2a-pos-dashboard', 'AI Audit',            'AI Audit',            'g2a_pos_manage_ai',         'g2a-pos-ai-audit',             [Pages::class, 'spa_ai_audit']);

        add_submenu_page('g2a-pos-dashboard', 'Settings',     'Settings',     'g2a_pos_manage_settings',  'g2a-pos-settings',  [Pages::class, 'spa_settings']);
    }
}
