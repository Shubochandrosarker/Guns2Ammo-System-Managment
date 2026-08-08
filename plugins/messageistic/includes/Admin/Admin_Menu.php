<?php
/**
 * Registers Messageistic's top-level admin menu and submenus.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;

defined( 'ABSPATH' ) || exit;

final class Admin_Menu {

    public const SLUG_DASHBOARD    = 'messageistic';
    public const SLUG_CONTACTS     = 'messageistic-contacts';
    public const SLUG_CONVERSATIONS = 'messageistic-conversations';
    public const SLUG_CAMPAIGNS    = 'messageistic-campaigns';
    public const SLUG_TEMPLATES    = 'messageistic-templates';
    public const SLUG_AUTOMATIONS  = 'messageistic-automations';
    public const SLUG_LOGS         = 'messageistic-logs';
    public const SLUG_REPORTS      = 'messageistic-reports';
    public const SLUG_IMPORT       = 'messageistic-import';
    public const SLUG_SETTINGS     = 'messageistic-settings';
    public const SLUG_HEALTH       = 'messageistic-provider-health';
    public const SLUG_LOCATIONS    = 'messageistic-locations';
    public const SLUG_WORKFLOW_PACKS = 'messageistic-workflow-packs';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ Settings_Page::class, 'register_settings' ] );
    }

    public function add_menu(): void {
        $cap = Permissions::VIEW;

        add_menu_page(
            __( 'Messageistic', 'messageistic' ),
            __( 'Messageistic', 'messageistic' ),
            $cap,
            self::SLUG_DASHBOARD,
            [ Dashboard_Page::class, 'render' ],
            'dashicons-format-chat',
            26
        );

        add_submenu_page( self::SLUG_DASHBOARD, __( 'Dashboard', 'messageistic' ), __( 'Dashboard', 'messageistic' ), $cap, self::SLUG_DASHBOARD, [ Dashboard_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Contacts', 'messageistic' ), __( 'Contacts', 'messageistic' ), $cap, self::SLUG_CONTACTS, [ Contacts_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Conversations', 'messageistic' ), __( 'Conversations', 'messageistic' ), $cap, self::SLUG_CONVERSATIONS, [ Conversations_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Campaigns', 'messageistic' ), __( 'Campaigns', 'messageistic' ), Permissions::MANAGE_CAMPAIGNS, self::SLUG_CAMPAIGNS, [ Campaigns_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Templates', 'messageistic' ), __( 'Templates', 'messageistic' ), Permissions::MANAGE_CAMPAIGNS, self::SLUG_TEMPLATES, [ Templates_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Automations', 'messageistic' ), __( 'Automations', 'messageistic' ), Permissions::MANAGE_CAMPAIGNS, self::SLUG_AUTOMATIONS, [ Automations_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Logs', 'messageistic' ), __( 'Logs', 'messageistic' ), Permissions::MANAGE, self::SLUG_LOGS, [ Logs_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Reports', 'messageistic' ), __( 'Reports', 'messageistic' ), Permissions::VIEW, self::SLUG_REPORTS, [ Reports_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Import', 'messageistic' ), __( 'Import', 'messageistic' ), Permissions::MANAGE, self::SLUG_IMPORT, [ Import_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Workflow Packs', 'messageistic' ), __( 'Workflow Packs', 'messageistic' ), Permissions::MANAGE_SETTINGS, self::SLUG_WORKFLOW_PACKS, [ Workflow_Packs_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Locations', 'messageistic' ), __( 'Locations', 'messageistic' ), Permissions::MANAGE_SETTINGS, self::SLUG_LOCATIONS, [ Locations_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Settings', 'messageistic' ), __( 'Settings', 'messageistic' ), Permissions::MANAGE_SETTINGS, self::SLUG_SETTINGS, [ Settings_Page::class, 'render' ] );
        add_submenu_page( self::SLUG_DASHBOARD, __( 'Provider Health & Sync', 'messageistic' ), __( 'Provider Health', 'messageistic' ), Permissions::MANAGE_PROVIDERS, self::SLUG_HEALTH, [ Provider_Health_Page::class, 'render' ] );
    }

    /**
     * Set of admin page slugs Messageistic owns. Used to scope asset loading.
     *
     * @return string[]
     */
    public static function page_slugs(): array {
        return [
            self::SLUG_DASHBOARD,
            self::SLUG_CONTACTS,
            self::SLUG_CONVERSATIONS,
            self::SLUG_CAMPAIGNS,
            self::SLUG_TEMPLATES,
            self::SLUG_AUTOMATIONS,
            self::SLUG_LOGS,
            self::SLUG_REPORTS,
            self::SLUG_IMPORT,
            self::SLUG_WORKFLOW_PACKS,
            self::SLUG_LOCATIONS,
            self::SLUG_SETTINGS,
            self::SLUG_HEALTH,
        ];
    }
}
