<?php
/**
 * Boots G2A-specific integration classes when their host plugin/CPT is present.
 *
 * Each integration is a thin adapter that listens to its source's hooks and
 * emits Messageistic triggers via do_action( 'messageistic_trigger', $key, $context ).
 * The Automation_Manager handles the rest — no integration ever touches the
 * provider system directly.
 *
 * @package Messageistic\Integrations
 */

namespace Messageistic\Integrations;

use Messageistic\Integrations\CRMistic\CRMistic_Integration;
use Messageistic\Integrations\FFL_Checkout\FFL_Integration;
use Messageistic\Integrations\G2A_Booking\Booking_Integration;
use Messageistic\Integrations\Kiosk\Kiosk_Integration;
use Messageistic\Integrations\Memberistic\Memberistic_Integration;
use Messageistic\Integrations\WooCommerce\WooCommerce_Integration;

defined( 'ABSPATH' ) || exit;

final class Integration_Loader {

    public static function boot(): void {
        ( new WooCommerce_Integration() )->register();
        ( new Booking_Integration() )->register();
        ( new Memberistic_Integration() )->register();
        ( new FFL_Integration() )->register();
        ( new Kiosk_Integration() )->register();
        ( new CRMistic_Integration() )->register();
    }
}
