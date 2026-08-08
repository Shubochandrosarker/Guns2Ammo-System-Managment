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
		// v1.1.0 — money-minting operations (store credit / loyalty
		// adjustments, gift-card issuance). Granted to the same tier as
		// g2a_pos_manage_settings via register_caps(), which runs on every
		// boot, so existing installs self-heal on upgrade.
		'g2a_pos_manage_finance',
		// v3.4.0 — taking payment on an existing order. Split out from
		// g2a_pos_manage_register because a cashier must be able to capture a
		// tender without also being able to open/close tills or void and
		// refund lines. Before this, the shipped g2a_cashier role held neither
		// g2a_pos_manage_register nor g2a_pos_process_firearm_sale, so a
		// cashier could build a cart and submit the order but was refused at
		// the moment of collecting money.
		//
		// Purely additive: the routes that already accepted manage_register or
		// process_firearm_sale still do, this is only an extra way in.
		'g2a_pos_take_payment',
	);

	/**
	 * The full capability vocabulary, for callers that need to report which of
	 * them a given user holds. The SPAs use this to decide what to render,
	 * rather than inferring permission from role names — a user's role can be
	 * customised on site, their capabilities cannot be guessed from it.
	 *
	 * @return string[]
	 */
	public static function all_caps(): array {
		return self::CAPS;
	}

	public static function register_roles(): void {
		add_role(
			'g2a_cashier',
			'G2A Cashier',
			array(
				'read'                     => true,
				'g2a_pos_access'           => true,
				'g2a_pos_use_ai'           => true,
				'g2a_pos_search_customers' => true,
				'g2a_pos_take_payment'     => true,
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
			// register_caps() strips every CAP before re-adding this list, so a
			// capability missing here is removed on the next boot even if
			// add_role() granted it.
			'g2a_cashier'            => array( 'g2a_pos_access', 'g2a_pos_use_ai', 'g2a_pos_search_customers', 'g2a_pos_take_payment' ),
			'g2a_inventory_staff'    => array( 'g2a_pos_access', 'g2a_pos_manage_inventory', 'g2a_pos_search_customers' ),
			'g2a_range_staff'        => array( 'g2a_pos_access', 'g2a_pos_search_customers', 'g2a_pos_view_waivers', 'g2a_pos_checkin_range_customer' ),
			'g2a_gunsmith'           => array( 'g2a_pos_access', 'g2a_pos_process_firearm_sale', 'g2a_pos_take_payment', 'g2a_pos_view_bound_book', 'g2a_pos_edit_bound_book', 'g2a_pos_manage_repairs' ),
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
