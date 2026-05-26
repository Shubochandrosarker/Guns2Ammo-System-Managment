<?php
/**
 * Plugin template for /event/{booking-type-slug}/ landing pages.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

do_action( 'g2ab_render_event_landing' );

get_footer();
