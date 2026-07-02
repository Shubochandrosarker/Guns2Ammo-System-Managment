<?php
/**
 * Uninstall cleanup for Guns2Ammo Waiver Manager.
 *
 * Removes the custom kiosk role and the plugin's option. User meta
 * (waiver_signed_date / waiver_source) is intentionally retained — waiver
 * history is liability/compliance data and must survive plugin removal.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

remove_role( 'kiosk' );
delete_option( 'g2a_waiver_kiosk_document_id' );
