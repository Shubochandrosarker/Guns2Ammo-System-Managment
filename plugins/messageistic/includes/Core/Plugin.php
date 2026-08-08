<?php
/**
 * Messageistic main plugin bootstrapper.
 *
 * @package Messageistic\Core
 */

namespace Messageistic\Core;

use Messageistic\Admin\Admin_Assets;
use Messageistic\Admin\Admin_Menu;
use Messageistic\Automations\Automation_Manager;
use Messageistic\Campaigns\Campaign_Queue;
use Messageistic\Campaigns\Campaign_Scheduler;
use Messageistic\Conversions\Conversion_Manager;
use Messageistic\Helpers\Raw_Body_Cache;
use Messageistic\Import\Import_Service;
use Messageistic\Privacy\Privacy_Manager;
use Messageistic\Integrations\Integration_Loader;
use Messageistic\Monitoring\Pilot_Monitor;
use Messageistic\Sync\Sync_Service;
use Messageistic\Providers\Provider_Manager;
use Messageistic\REST\Dashboard_API;
use Messageistic\REST\REST_Controller;

defined( 'ABSPATH' ) || exit;

final class Plugin {

    private static ?self $instance = null;

    private Provider_Manager $providers;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->providers = new Provider_Manager();
    }

    public function boot(): void {
        load_plugin_textdomain( 'messageistic', false, dirname( MESSAGEISTIC_BASENAME ) . '/languages' );

        Raw_Body_Cache::register();
        Cron_Manager::register();
        Privacy_Manager::register();
        Pilot_Monitor::register();
        $this->providers->register_defaults();
        Sync_Service::register();

        if ( is_admin() ) {
            ( new Admin_Menu() )->register();
            ( new Admin_Assets() )->register();
        }

        add_action( 'rest_api_init', function () {
            ( new REST_Controller( $this->providers ) )->register_routes();
            ( new Dashboard_API() )->register_routes();
        } );

        Campaign_Queue::register();
        Campaign_Scheduler::register();
        Automation_Manager::register();
        Conversion_Manager::register();
        Import_Service::register();
        Integration_Loader::boot();

        Installer::maybe_upgrade();
    }

    public function providers(): Provider_Manager {
        return $this->providers;
    }
}
