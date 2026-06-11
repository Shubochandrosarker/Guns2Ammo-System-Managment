<?php

namespace G2A\POS\Permissions;

final class Roles {

	private const CAPS = array(
		'g2a_pos_access',
		'g2a_pos_manage_register',
		'g2a_pos_manage_inventory',
		'g2a_pos_process_firearm_sale',
		'g2a_pos_manage_compliance',
		'g2a_pos_manage_settings',
		'g2a_pos_view_bound_book',
		'g2a_pos_edit_bound_book',
		'g2a_pos_manage_wholesalers',
		'g2a_pos_manage_pricing',
		'g2a_pos_submit_dropship',
		// v0.9.0
		'g2a_pos_manage_crm',
		'g2a_pos_manage_repairs',
		'g2a_pos_manage_shipping',
		'g2a_pos_manage_messaging',
		// v0.10.0
		'g2a_pos_manage_nfa',
		// v1.0.0
		'g2a_pos_use_ai',
		'g2a_pos_manage_ai',
		'g2a_pos_search_customers',
		'g2a_pos_view_customer_contact',
		'g2a_pos_view_waivers',
		'g2a_pos_view_waiver_sensitive_data',
		'g2a_pos_checkin_range_customer',
	);

	public static function register_roles(): void {
		add_role(
			'g2a_cashier',
			'G2A Cashier',
			array(
				'read'                     => true,
				'g2a_pos_access'           => true,
				'g2a_pos_use_ai'           => true,
				'g2a_pos_search_customers' => true,
			)
		);
		add_role(
			'g2a_inventory_staff',
			'G2A Inventory Staff',
			array(
				'read'                       => true,
				'g2a_pos_access'             => true,
				'g2a_pos_manage_inventory'   => true,
				'g2a_pos_view_bound_book'    => true,
				'g2a_pos_manage_wholesalers' => true,
				'g2a_pos_manage_pricing'     => true,
				'g2a_pos_submit_dropship'    => true,
				'g2a_pos_search_customers'   => true,
			)
		);
		add_role(
			'g2a_range_staff',
			'G2A Range Staff',
			array(
				'read'                           => true,
				'g2a_pos_access'                 => true,
				'g2a_pos_search_customers'       => true,
				'g2a_pos_view_waivers'           => true,
				'g2a_pos_checkin_range_customer' => true,
			)
		);
		add_role(
			'g2a_gunsmith',
			'G2A Gunsmith',
			array(
				'read'                         => true,
				'g2a_pos_access'               => true,
				'g2a_pos_process_firearm_sale' => true,
				'g2a_pos_view_bound_book'      => true,
				'g2a_pos_edit_bound_book'      => true,
				'g2a_pos_manage_repairs'       => true,
			)
		);
		add_role(
			'g2a_compliance_officer',
			'G2A Compliance Officer',
			array(
				'read'                      => true,
				'g2a_pos_access'            => true,
				'g2a_pos_manage_compliance' => true,
				'g2a_pos_view_bound_book'   => true,
				'g2a_pos_edit_bound_book'   => true,
			)
		);
		add_role(
			'g2a_pos_manager',
			'G2A POS Manager',
			array(
				'read'                               => true,
				'g2a_pos_access'                     => true,
				'g2a_pos_manage_register'            => true,
				'g2a_pos_manage_inventory'           => true,
				'g2a_pos_process_firearm_sale'       => true,
				'g2a_pos_manage_compliance'          => true,
				'g2a_pos_view_bound_book'            => true,
				'g2a_pos_edit_bound_book'            => true,
				'g2a_pos_manage_wholesalers'         => true,
				'g2a_pos_manage_pricing'             => true,
				'g2a_pos_submit_dropship'            => true,
				'g2a_pos_manage_crm'                 => true,
				'g2a_pos_manage_repairs'             => true,
				'g2a_pos_manage_shipping'            => true,
				'g2a_pos_manage_messaging'           => true,
				'g2a_pos_manage_nfa'                 => true,
				'g2a_pos_use_ai'                     => true,
				'g2a_pos_manage_ai'                  => true,
				'g2a_pos_search_customers'           => true,
				'g2a_pos_view_customer_contact'      => true,
				'g2a_pos_view_waivers'               => true,
				'g2a_pos_view_waiver_sensitive_data' => true,
				'g2a_pos_checkin_range_customer'     => true,
			)
		);
	}

	public static function register_caps(): void {
		foreach ( array( 'administrator', 'g2a_pos_manager' ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}

		// WooCommerce shop managers keep commerce/POS access but do not receive
		// regulated, credential, AI-admin, or compliance capabilities.
		$shopManager = get_role( 'shop_manager' );
		if ( $shopManager ) {
			foreach ( self::CAPS as $cap ) {
				$shopManager->remove_cap( $cap );
			}
			foreach ( array(
				'g2a_pos_access',
				'g2a_pos_manage_register',
				'g2a_pos_manage_inventory',
				'g2a_pos_search_customers',
				'g2a_pos_view_customer_contact',
			) as $cap ) {
				$shopManager->add_cap( $cap );
			}
		}

		$customRoles = array(
			'g2a_cashier'            => array( 'g2a_pos_access', 'g2a_pos_use_ai', 'g2a_pos_search_customers' ),
			'g2a_inventory_staff'    => array( 'g2a_pos_access', 'g2a_pos_manage_inventory', 'g2a_pos_search_customers' ),
			'g2a_range_staff'        => array( 'g2a_pos_access', 'g2a_pos_search_customers', 'g2a_pos_view_waivers', 'g2a_pos_checkin_range_customer' ),
			'g2a_gunsmith'           => array( 'g2a_pos_access', 'g2a_pos_process_firearm_sale', 'g2a_pos_view_bound_book', 'g2a_pos_edit_bound_book', 'g2a_pos_manage_repairs' ),
			'g2a_compliance_officer' => array( 'g2a_pos_access', 'g2a_pos_manage_compliance', 'g2a_pos_view_bound_book', 'g2a_pos_edit_bound_book', 'g2a_pos_view_waivers', 'g2a_pos_view_waiver_sensitive_data' ),
		);
		foreach ( $customRoles as $name => $allowed ) {
			$role = get_role( $name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
			foreach ( $allowed as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}
}
