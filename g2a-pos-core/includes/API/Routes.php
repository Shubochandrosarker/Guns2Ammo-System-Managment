<?php

namespace G2A\POS\API;

final class Routes {

	public static function register(): void {
		register_rest_route(
			'g2a-pos/v1',
			'/auth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( AuthController::class, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/auth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( AuthController::class, 'revoke' ),
				'permission_callback' => static fn() => is_user_logged_in() || get_current_user_id() > 0,
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/auth/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( AuthController::class, 'refresh' ),
				'permission_callback' => static fn() => is_user_logged_in() || get_current_user_id() > 0,
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/inventory/adjust',
			array(
				'methods'             => 'POST',
				'callback'            => array( InventoryController::class, 'adjust' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/inventory/product',
			array(
				'methods'             => 'GET',
				'callback'            => array( InventoryController::class, 'product' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( OrderController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( OrderController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473',
			array(
				'methods'             => 'GET',
				'callback'            => array( Form4473Controller::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_bound_book' ) || current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( OrderController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)/states',
			array(
				'methods'             => 'POST',
				'callback'            => array( OrderController::class, 'update_states' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/products/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( ProductController::class, 'search' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/cart',
			array(
				'methods'             => 'GET',
				'callback'            => array( CartController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cart/context',
			array(
				'methods'             => 'POST',
				'callback'            => array( CartController::class, 'set_context' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cart/items',
			array(
				'methods'             => 'POST',
				'callback'            => array( CartController::class, 'add_item' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cart/items/(?P<id>[\w\-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( CartController::class, 'update_item' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cart/items/(?P<id>[\w\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( CartController::class, 'remove_item' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cart/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( CartController::class, 'clear' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/checkout/quote',
			array(
				'methods'             => 'GET',
				'callback'            => array( CheckoutController::class, 'quote' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/checkout/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( CheckoutController::class, 'submit' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/customers/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( CustomerController::class, 'search' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_search_customers' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/barcode/find',
			array(
				'methods'             => 'GET',
				'callback'            => array( BarcodeController::class, 'find' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/barcode/scan-to-cart',
			array(
				'methods'             => 'POST',
				'callback'            => array( BarcodeController::class, 'scan_to_cart' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)/payment',
			array(
				'methods'             => 'POST',
				'callback'            => array( PaymentController::class, 'collect' ),
				// g2a_pos_take_payment added in 3.4.0 — see Roles::CAPS. Purely
				// additive; the two original capabilities still grant access.
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ) || current_user_can( 'g2a_pos_process_firearm_sale' ) || current_user_can( 'g2a_pos_take_payment' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)/refund',
			array(
				'methods'             => 'POST',
				'callback'            => array( PaymentController::class, 'refund' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);

		// --- v1.6.0 — Order-sheet sourcing panel ---
		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)/sourcing',
			array(
				'methods'             => 'GET',
				'callback'            => array( OrderSourcingController::class, 'for_order' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cart/sourcing',
			array(
				'methods'             => 'POST',
				'callback'            => array( OrderSourcingController::class, 'for_cart' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- v1.1.0 — split-tender checkout ---
		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)/tenders',
			array(
				'methods'             => 'GET',
				'callback'            => array( TenderController::class, 'balance' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)/tenders',
			array(
				'methods'             => 'POST',
				'callback'            => array( TenderController::class, 'add' ),
				// g2a_pos_take_payment added in 3.4.0 — see Roles::CAPS. Voids
				// and refunds below deliberately stay manager-only.
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ) || current_user_can( 'g2a_pos_process_firearm_sale' ) || current_user_can( 'g2a_pos_take_payment' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/tenders/(?P<tender_id>\d+)/void',
			array(
				'methods'             => 'POST',
				'callback'            => array( TenderController::class, 'void' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/tenders/(?P<tender_id>\d+)/refund',
			array(
				'methods'             => 'POST',
				'callback'            => array( TenderController::class, 'refund' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/tenders/methods',
			array(
				'methods'             => 'GET',
				'callback'            => array( TenderController::class, 'methods' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/orders/(?P<id>\d+)/receipt',
			array(
				'methods'             => 'GET',
				'callback'            => array( ReceiptController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/register/open',
			array(
				'methods'             => 'POST',
				'callback'            => array( RegisterController::class, 'open' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/register/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( RegisterController::class, 'close' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/register/current',
			array(
				'methods'             => 'GET',
				'callback'            => array( RegisterController::class, 'current' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/register/open-list',
			array(
				'methods'             => 'GET',
				'callback'            => array( RegisterController::class, 'open_list' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/system/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( SystemController::class, 'health' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'manage_options' ),
			)
		);

		// --- ATF Bound Book (A&D) ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/bound-book',
			array(
				'methods'             => 'GET',
				'callback'            => array( BoundBookController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/bound-book/acquire',
			array(
				'methods'             => 'POST',
				'callback'            => array( BoundBookController::class, 'acquire' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/bound-book/dispose',
			array(
				'methods'             => 'POST',
				'callback'            => array( BoundBookController::class, 'dispose' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/bound-book/verify',
			array(
				'methods'             => 'GET',
				'callback'            => array( BoundBookController::class, 'verify' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);

		// --- ATF Form 4473 ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473',
			array(
				'methods'             => 'POST',
				'callback'            => array( Form4473Controller::class, 'start' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( Form4473Controller::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_bound_book' ) || current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)/sign-transferee',
			array(
				'methods'             => 'POST',
				'callback'            => array( Form4473Controller::class, 'sign_transferee' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)/sign-transferor',
			array(
				'methods'             => 'POST',
				'callback'            => array( Form4473Controller::class, 'sign_transferor' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)/transfer',
			array(
				'methods'             => 'POST',
				'callback'            => array( Form4473Controller::class, 'transfer' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);

		// --- NICS ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/nics',
			array(
				'methods'             => 'GET',
				'callback'            => array( NICSController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/nics',
			array(
				'methods'             => 'POST',
				'callback'            => array( NICSController::class, 'initiate' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/nics/(?P<id>\d+)/response',
			array(
				'methods'             => 'POST',
				'callback'            => array( NICSController::class, 'response' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);

		// --- Compliance reports ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/reports',
			array(
				'methods'             => 'GET',
				'callback'            => array( ComplianceReportController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/reports/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( ComplianceReportController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/reports/(?P<id>\d+)/submitted',
			array(
				'methods'             => 'POST',
				'callback'            => array( ComplianceReportController::class, 'mark_submitted' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/reports/theft-loss',
			array(
				'methods'             => 'POST',
				'callback'            => array( ComplianceReportController::class, 'create_theft_loss' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/audit/verify',
			array(
				'methods'             => 'GET',
				'callback'            => array( ComplianceReportController::class, 'audit_verify' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);

		// --- State rules ---
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/states',
			array(
				'methods'             => 'GET',
				'callback'            => array( StateRulesController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/states/(?P<state>[A-Za-z]{2})',
			array(
				'methods'             => 'GET',
				'callback'            => array( StateRulesController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/states/(?P<state>[A-Za-z]{2})',
			array(
				'methods'             => 'POST',
				'callback'            => array( StateRulesController::class, 'update' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/evaluate',
			array(
				'methods'             => 'POST',
				'callback'            => array( StateRulesController::class, 'evaluate' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- v1.3.0 — Out-of-state customer firearm-sale policy ---
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/oos-policy',
			array(
				'methods'             => 'GET',
				'callback'            => array( OutOfStatePolicyController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/oos-policy',
			array(
				'methods'             => 'POST',
				'callback'            => array( OutOfStatePolicyController::class, 'set_global' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/oos-policy/location',
			array(
				'methods'             => 'POST',
				'callback'            => array( OutOfStatePolicyController::class, 'set_location' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/oos-policy/evaluate',
			array(
				'methods'             => 'GET',
				'callback'            => array( OutOfStatePolicyController::class, 'evaluate' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/oos-policy/override',
			array(
				'methods'             => 'POST',
				'callback'            => array( OutOfStatePolicyController::class, 'override' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// --- v1.4.0 — CCW NICS-bypass (18 USC 922(t)(3)) ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/ccw/qualifying-states',
			array(
				'methods'             => 'GET',
				'callback'            => array( CcwExemptionController::class, 'qualifying_states' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/ccw/evaluate',
			array(
				'methods'             => 'POST',
				'callback'            => array( CcwExemptionController::class, 'evaluate' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)/ccw-exemption',
			array(
				'methods'             => 'POST',
				'callback'            => array( CcwExemptionController::class, 'record_on_form' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)/ccw-exemption',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( CcwExemptionController::class, 'clear_on_form' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ) || current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);

		// --- v1.5.0 — Unified Item Identity Graph ---
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities',
			array(
				'methods'             => 'GET',
				'callback'            => array( ItemIdentityController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities',
			array(
				'methods'             => 'POST',
				'callback'            => array( ItemIdentityController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/match',
			array(
				'methods'             => 'POST',
				'callback'            => array( ItemIdentityController::class, 'match' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/resolve',
			array(
				'methods'             => 'POST',
				'callback'            => array( ItemIdentityController::class, 'resolve' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/backfill',
			array(
				'methods'             => 'POST',
				'callback'            => array( ItemIdentityController::class, 'backfill' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( ItemIdentityController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( ItemIdentityController::class, 'update' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/(?P<id>\d+)/links',
			array(
				'methods'             => 'POST',
				'callback'            => array( ItemIdentityController::class, 'add_link' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/(?P<id>\d+)/sources',
			array(
				'methods'             => 'GET',
				'callback'            => array( ItemIdentityController::class, 'sources' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/catalog/identities/(?P<from>\d+)/merge/(?P<into>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( ItemIdentityController::class, 'merge' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);

		// --- v1.5.0 — Used-firearm intake ---
		register_rest_route(
			'g2a-pos/v1',
			'/used-firearms',
			array(
				'methods'             => 'GET',
				'callback'            => array( UsedFirearmIntakeController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/used-firearms',
			array(
				'methods'             => 'POST',
				'callback'            => array( UsedFirearmIntakeController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ) || current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/used-firearms/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( UsedFirearmIntakeController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/used-firearms/(?P<id>\d+)/payout',
			array(
				'methods'             => 'POST',
				'callback'            => array( UsedFirearmIntakeController::class, 'payout' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/used-firearms/(?P<id>\d+)/list',
			array(
				'methods'             => 'POST',
				'callback'            => array( UsedFirearmIntakeController::class, 'list_for_sale' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/used-firearms/(?P<id>\d+)/sell',
			array(
				'methods'             => 'POST',
				'callback'            => array( UsedFirearmIntakeController::class, 'sell' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);

		// --- FFL-to-FFL transfers ---
		register_rest_route(
			'g2a-pos/v1',
			'/ffl/transfers',
			array(
				'methods'             => 'GET',
				'callback'            => array( FflTransferController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ffl/transfers/inbound',
			array(
				'methods'             => 'POST',
				'callback'            => array( FflTransferController::class, 'inbound' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ffl/transfers/outbound',
			array(
				'methods'             => 'POST',
				'callback'            => array( FflTransferController::class, 'outbound' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ffl/partners',
			array(
				'methods'             => 'GET',
				'callback'            => array( FflTransferController::class, 'partners' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ffl/partners',
			array(
				'methods'             => 'POST',
				'callback'            => array( FflTransferController::class, 'upsert_partner' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);

		// --- Transferee directory ---
		register_rest_route(
			'g2a-pos/v1',
			'/transferees/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( TransfereeController::class, 'search' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/transferees/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( TransfereeController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/transferees',
			array(
				'methods'             => 'POST',
				'callback'            => array( TransfereeController::class, 'upsert' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/transferees/scan-aamva',
			array(
				'methods'             => 'POST',
				'callback'            => array( TransfereeController::class, 'scan_aamva' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);

		// --- Reporting ---
		register_rest_route(
			'g2a-pos/v1',
			'/reports/register/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( ReportingController::class, 'register_report' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/reports/sales-by-employee',
			array(
				'methods'             => 'GET',
				'callback'            => array( ReportingController::class, 'sales_by_employee' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/reports/attach-rate',
			array(
				'methods'             => 'GET',
				'callback'            => array( ReportingController::class, 'attach_rate' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/reports/tax',
			array(
				'methods'             => 'GET',
				'callback'            => array( ReportingController::class, 'tax_summary' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/reports/bound-book.csv',
			array(
				'methods'             => 'GET',
				'callback'            => array( ReportingController::class, 'bound_book_csv' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);

		// --- Stripe Terminal ---
		register_rest_route(
			'g2a-pos/v1',
			'/payments/stripe/connection-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( StripeController::class, 'connection_token' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/payments/stripe/intents',
			array(
				'methods'             => 'POST',
				'callback'            => array( StripeController::class, 'create_intent' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/payments/stripe/intents/capture',
			array(
				'methods'             => 'POST',
				'callback'            => array( StripeController::class, 'capture' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/payments/stripe/refunds',
			array(
				'methods'             => 'POST',
				'callback'            => array( StripeController::class, 'refund' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ),
			)
		);

		// --- 4473 PDF ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)/pdf',
			array(
				'methods'             => 'GET',
				'callback'            => array( Form4473PdfController::class, 'render' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);

		// --- TOTP 2FA ---
		register_rest_route(
			'g2a-pos/v1',
			'/security/totp/enroll',
			array(
				'methods'             => 'POST',
				'callback'            => array( TotpController::class, 'enroll' ),
				'permission_callback' => static fn() => is_user_logged_in(),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/security/totp/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( TotpController::class, 'confirm' ),
				'permission_callback' => static fn() => is_user_logged_in(),
			)
		);

		// --- Webhooks ---
		register_rest_route(
			'g2a-pos/v1',
			'/webhooks',
			array(
				'methods'             => 'GET',
				'callback'            => array( WebhookController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/webhooks',
			array(
				'methods'             => 'POST',
				'callback'            => array( WebhookController::class, 'save' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// --- Inventory importer + distributors ---
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/import/adapters',
			array(
				'methods'             => 'GET',
				'callback'            => array( InventoryImportController::class, 'adapters' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/import/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( InventoryImportController::class, 'preview' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/import/commit',
			array(
				'methods'             => 'POST',
				'callback'            => array( InventoryImportController::class, 'commit' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/order',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'submit_warehouse' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_submit_dropship' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/items/validate',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'validate_item' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/items/upc',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'lookup_upc' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/shipping/one-day',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'one_day_shipping' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/orders/(?P<external_ref>[\w\-\/]+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'order_status' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			// Real vendor SKUs (Lipsey's and others) routinely contain
			// spaces, #, &, and other characters outside \w\-\. — the admin
			// UI sends them encodeURIComponent()'d, but the narrower
			// pattern still rejected any SKU containing one of those
			// characters with a bare "no route found" 404. Match anything
			// but the path separator, same as external_ref above.
			'/wholesalers/(?P<wholesaler_id>\d+)/products/(?P<vendor_sku>[^/]+)/mirror-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'mirror_image' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			// SKU-in-the-body twin of the route above, and what the admin UI
			// calls. Percent-encoding a vendor SKU into a path segment is not
			// survivable for the SKUs vendors actually ship: Apache/nginx
			// decode %2F back to '/' before WP ever sees the path, so any SKU
			// containing a slash 404'd with "No route was found matching the
			// URL and request method" no matter how the client encoded it.
			'/wholesalers/(?P<wholesaler_id>\d+)/mirror-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'mirror_image' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			// Bulk: mirror a whole vendor catalog's imagery in batches.
			'/wholesalers/(?P<wholesaler_id>\d+)/mirror-images',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'mirror_images' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			// Same vendor-sku character-set issue as mirror-image above.
			'/wholesalers/(?P<wholesaler_id>\d+)/products/(?P<vendor_sku>[^/]+)/promote',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'promote' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/route',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'route_upc' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- Wholesalers (CRUD + catalog ingestion + drop-ship) ---
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'list' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'upsert' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/catalog/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'import_csv' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/catalog/api-sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'sync_catalog_api' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/inventory/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'sync_inventory' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/test-credentials',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'test_credentials' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'browse' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'categories' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/categories/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'update_category' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<wholesaler_id>\d+)/dropship',
			array(
				'methods'             => 'POST',
				'callback'            => array( WholesalerController::class, 'submit_dropship' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_submit_dropship' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( WholesalerController::class, 'orders' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- MAP pricing ---
		register_rest_route(
			'g2a-pos/v1',
			'/pricing/map',
			array(
				'methods'             => 'GET',
				'callback'            => array( MapController::class, 'list' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_pricing' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/pricing/map',
			array(
				'methods'             => 'POST',
				'callback'            => array( MapController::class, 'upsert' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_pricing' ) || current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/pricing/map/evaluate',
			array(
				'methods'             => 'GET',
				'callback'            => array( MapController::class, 'evaluate' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- Range waivers ---
		register_rest_route(
			'g2a-pos/v1',
			'/range/waivers',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeWaiverController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_waivers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/waivers/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeWaiverController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_waivers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/waivers/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeWaiverController::class, 'find_for_customer' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_waivers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/waivers/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeWaiverController::class, 'import' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/waivers/(?P<id>\d+)/checkin',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeWaiverController::class, 'checkin' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_checkin_range_customer' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/waivers/(?P<id>\d+)/mirror-pdf',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeWaiverController::class, 'mirror_pdf' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// The list handler existed but was never routed, so the Distributors
		// screen 404'd on load in both front-ends while POST/PUT worked.
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/distributors',
			array(
				'methods'             => 'GET',
				'callback'            => array( InventoryImportController::class, 'distributors' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/distributors',
			array(
				'methods'             => 'POST',
				'callback'            => array( InventoryImportController::class, 'create_distributor' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/distributors/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( InventoryImportController::class, 'update_distributor' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/distributors/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( InventoryImportController::class, 'delete_distributor' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/distributors/(?P<id>\d+)/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( InventoryImportController::class, 'sync_now' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/inventory/distributors/(?P<id>\d+)/runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( InventoryImportController::class, 'sync_runs' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);

		// --- Range membership ---
		register_rest_route(
			'g2a-pos/v1',
			'/membership/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( MembershipController::class, 'providers' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/membership/configure',
			array(
				'methods'             => 'POST',
				'callback'            => array( MembershipController::class, 'configure' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/membership/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( MembershipController::class, 'lookup' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- 4473 PDF template + calibration ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/template/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( Form4473CalibrationController::class, 'status' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/template/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( Form4473CalibrationController::class, 'upload_template' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/template/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( Form4473CalibrationController::class, 'get_fields' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/template/fields',
			array(
				'methods'             => 'POST',
				'callback'            => array( Form4473CalibrationController::class, 'save_fields' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ),
			)
		);

		// --- v0.9.0 — CRM ---
		register_rest_route(
			'g2a-pos/v1',
			'/crm/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( CrmController::class, 'search' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/crm/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( CrmController::class, 'profile' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/crm/(?P<id>\d+)/profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( CrmController::class, 'update_profile' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_crm' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/crm/(?P<id>\d+)/recompute',
			array(
				'methods'             => 'POST',
				'callback'            => array( CrmController::class, 'recompute' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_crm' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/crm/export\.csv',
			array(
				'methods'             => 'GET',
				'callback'            => array( CrmController::class, 'export_csv' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_crm' ) || current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'manage_woocommerce' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/orders/export\.csv',
			array(
				'methods'             => 'GET',
				'callback'            => array( OrderController::class, 'export_csv' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'manage_woocommerce' ),
			)
		);

		// --- v3.1.0 — Sibling-plugin integrations (Woo catalog scan, FFL checkout bridge) ---
		register_rest_route(
			'g2a-pos/v1',
			'/integrations/woo/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( IntegrationsController::class, 'woo_scan' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'manage_woocommerce' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/integrations/woo/scan',
			array(
				'methods'             => 'GET',
				'callback'            => array( IntegrationsController::class, 'woo_scan_status' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'manage_woocommerce' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/integrations/ffl/transfers',
			array(
				'methods'             => 'GET',
				'callback'            => array( IntegrationsController::class, 'ffl_transfers' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_crm' ) || current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'manage_woocommerce' ),
			)
		);

		// --- v0.9.0 — Repair / Gunsmith tickets ---
		register_rest_route(
			'g2a-pos/v1',
			'/repairs',
			array(
				'methods'             => 'GET',
				'callback'            => array( RepairController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_repairs' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/repairs',
			array(
				'methods'             => 'POST',
				'callback'            => array( RepairController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_repairs' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/repairs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( RepairController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_repairs' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/repairs/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( RepairController::class, 'update' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_repairs' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/repairs/(?P<id>\d+)/lines',
			array(
				'methods'             => 'POST',
				'callback'            => array( RepairController::class, 'add_line' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_repairs' ),
			)
		);

		// --- v0.9.0 — Layaway / Special Order ---
		register_rest_route(
			'g2a-pos/v1',
			'/layaways',
			array(
				'methods'             => 'GET',
				'callback'            => array( LayawayController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/layaways',
			array(
				'methods'             => 'POST',
				'callback'            => array( LayawayController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/layaways/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( LayawayController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/layaways/(?P<id>\d+)/pay',
			array(
				'methods'             => 'POST',
				'callback'            => array( LayawayController::class, 'pay' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/layaways/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( LayawayController::class, 'cancel' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// --- v0.9.0 — Consignment ---
		register_rest_route(
			'g2a-pos/v1',
			'/consignments',
			array(
				'methods'             => 'GET',
				'callback'            => array( ConsignmentController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_crm' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/consignments',
			array(
				'methods'             => 'POST',
				'callback'            => array( ConsignmentController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/consignments/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( ConsignmentController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_crm' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/consignments/(?P<id>\d+)/sell',
			array(
				'methods'             => 'POST',
				'callback'            => array( ConsignmentController::class, 'sell' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/consignments/(?P<id>\d+)/payout',
			array(
				'methods'             => 'POST',
				'callback'            => array( ConsignmentController::class, 'payout' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// --- v0.9.0 — Range memberships + lane reservations ---
		register_rest_route(
			'g2a-pos/v1',
			'/range/memberships',
			array(
				'methods'             => 'GET',
				'callback'            => array( MembershipBillingController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/memberships',
			array(
				'methods'             => 'POST',
				'callback'            => array( MembershipBillingController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/memberships/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( MembershipBillingController::class, 'cancel' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/lanes',
			array(
				'methods'             => 'GET',
				'callback'            => array( MembershipBillingController::class, 'lanes_for_day' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/lanes/reserve',
			array(
				'methods'             => 'POST',
				'callback'            => array( MembershipBillingController::class, 'reserve_lane' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/lanes/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( MembershipBillingController::class, 'lane_status' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- v0.9.0 — Classes & training ---
		register_rest_route(
			'g2a-pos/v1',
			'/classes',
			array(
				'methods'             => 'GET',
				'callback'            => array( ClassController::class, 'classes' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/classes',
			array(
				'methods'             => 'POST',
				'callback'            => array( ClassController::class, 'upsert_class' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/classes/sessions',
			array(
				'methods'             => 'GET',
				'callback'            => array( ClassController::class, 'sessions' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/classes/sessions',
			array(
				'methods'             => 'POST',
				'callback'            => array( ClassController::class, 'create_session' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/classes/sessions/(?P<id>\d+)/enroll',
			array(
				'methods'             => 'POST',
				'callback'            => array( ClassController::class, 'enroll' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/classes/sessions/(?P<id>\d+)/roster',
			array(
				'methods'             => 'GET',
				'callback'            => array( ClassController::class, 'roster' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- v0.9.0 — Loyalty + Gift Cards ---
		register_rest_route(
			'g2a-pos/v1',
			'/loyalty/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( LoyaltyController::class, 'balance' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/loyalty/(?P<id>\d+)/adjust',
			array(
				'methods'             => 'POST',
				'callback'            => array( LoyaltyController::class, 'adjust' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_finance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/loyalty/(?P<id>\d+)/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( LoyaltyController::class, 'history' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/gift-cards',
			array(
				'methods'             => 'GET',
				'callback'            => array( LoyaltyController::class, 'gift_card_list' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/gift-cards/issue',
			array(
				'methods'             => 'POST',
				'callback'            => array( LoyaltyController::class, 'gift_card_issue' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_finance' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/gift-cards/balance',
			array(
				'methods'             => 'GET',
				'callback'            => array( LoyaltyController::class, 'gift_card_balance' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/gift-cards/redeem',
			array(
				'methods'             => 'POST',
				'callback'            => array( LoyaltyController::class, 'gift_card_redeem' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- v0.9.0 — Purchase Orders ---
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( PurchaseOrderController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( PurchaseOrderController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( PurchaseOrderController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( PurchaseOrderController::class, 'submit' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders/(?P<id>\d+)/receive',
			array(
				'methods'             => 'POST',
				'callback'            => array( PurchaseOrderController::class, 'receive' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);

		// --- v0.9.0 — Cycle Counts ---
		register_rest_route(
			'g2a-pos/v1',
			'/cycle-counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( CycleCountController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cycle-counts',
			array(
				'methods'             => 'POST',
				'callback'            => array( CycleCountController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cycle-counts/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( CycleCountController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cycle-counts/(?P<id>\d+)/record',
			array(
				'methods'             => 'POST',
				'callback'            => array( CycleCountController::class, 'record' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/cycle-counts/(?P<id>\d+)/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( CycleCountController::class, 'close' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);

		// --- v0.9.0 — e-Signature capture ---
		register_rest_route(
			'g2a-pos/v1',
			'/signatures',
			array(
				'methods'             => 'POST',
				'callback'            => array( SignatureController::class, 'capture' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/signatures',
			array(
				'methods'             => 'GET',
				'callback'            => array( SignatureController::class, 'for_subject' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- v0.9.0 — ATF eForms eBound Book export ---
		register_rest_route(
			'g2a-pos/v1',
			'/atf/bound-book/export-eforms',
			array(
				'methods'             => 'GET',
				'callback'            => array( BoundBookController::class, 'export_eforms' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);

		// --- v0.9.0 — Shipping labels ---
		register_rest_route(
			'g2a-pos/v1',
			'/shipping/carriers',
			array(
				'methods'             => 'GET',
				'callback'            => array( ShippingController::class, 'carriers' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/shipping/labels',
			array(
				'methods'             => 'GET',
				'callback'            => array( ShippingController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/shipping/labels',
			array(
				'methods'             => 'POST',
				'callback'            => array( ShippingController::class, 'create_label' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_shipping' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/shipping/labels/(?P<id>\d+)/void',
			array(
				'methods'             => 'POST',
				'callback'            => array( ShippingController::class, 'void' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_shipping' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// --- v0.9.0 — Messaging outbox + templates ---
		register_rest_route(
			'g2a-pos/v1',
			'/messaging/outbox',
			array(
				'methods'             => 'GET',
				'callback'            => array( MessagingController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_messaging' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/messaging/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( MessagingController::class, 'queue' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_messaging' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/messaging/flush',
			array(
				'methods'             => 'POST',
				'callback'            => array( MessagingController::class, 'flush' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/messaging/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( MessagingController::class, 'templates' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_messaging' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/messaging/templates',
			array(
				'methods'             => 'POST',
				'callback'            => array( MessagingController::class, 'upsert_template' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_messaging' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// --- v0.9.0 — KPI dashboard ---
		register_rest_route(
			'g2a-pos/v1',
			'/reports/kpi',
			array(
				'methods'             => 'GET',
				'callback'            => array( KpiController::class, 'snapshot' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/reports/kpi/vendor-scorecard',
			array(
				'methods'             => 'GET',
				'callback'            => array( KpiController::class, 'vendor_scorecard' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'g2a_pos_manage_wholesalers' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/reports/kpi/trend',
			array(
				'methods'             => 'GET',
				'callback'            => array( KpiController::class, 'trend' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// ---- v0.10.0 — deep firearm-business modules ----

		// NFA Title-II inventory + Form 3/4/5 + trusts.
		register_rest_route(
			'g2a-pos/v1',
			'/nfa/items',
			array(
				'methods'             => 'GET',
				'callback'            => array( NfaController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_nfa' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/nfa/items',
			array(
				'methods'             => 'POST',
				'callback'            => array( NfaController::class, 'create_item' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_nfa' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/nfa/items/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( NfaController::class, 'get_item' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_nfa' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/nfa/items/(?P<id>\d+)/forms',
			array(
				'methods'             => 'POST',
				'callback'            => array( NfaController::class, 'file_form' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_nfa' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/nfa/forms/(?P<form_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( NfaController::class, 'update_form' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_nfa' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/nfa/trusts',
			array(
				'methods'             => 'GET',
				'callback'            => array( NfaController::class, 'trusts' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_nfa' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/nfa/trusts',
			array(
				'methods'             => 'POST',
				'callback'            => array( NfaController::class, 'create_trust' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_nfa' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);

		// Location transfers.
		register_rest_route(
			'g2a-pos/v1',
			'/transfers/location',
			array(
				'methods'             => 'GET',
				'callback'            => array( LocationTransferController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/transfers/location',
			array(
				'methods'             => 'POST',
				'callback'            => array( LocationTransferController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/transfers/location/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( LocationTransferController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/transfers/location/(?P<id>\d+)/ship',
			array(
				'methods'             => 'POST',
				'callback'            => array( LocationTransferController::class, 'ship' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/transfers/location/(?P<id>\d+)/receive',
			array(
				'methods'             => 'POST',
				'callback'            => array( LocationTransferController::class, 'receive' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_edit_bound_book' ),
			)
		);

		// Range Ops — RSOs, ammo rentals, brass buyback.
		register_rest_route(
			'g2a-pos/v1',
			'/range/rso',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeOpsController::class, 'rso_index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/rso',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeOpsController::class, 'rso_assign' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/rso/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeOpsController::class, 'rso_status' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/rso/on-duty',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeOpsController::class, 'rso_on_duty' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/ammo-rentals',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeOpsController::class, 'ammo_open' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/ammo-rentals',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeOpsController::class, 'ammo_issue' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/ammo-rentals/(?P<id>\d+)/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeOpsController::class, 'ammo_close' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		// Firearm rentals. Separate lifecycle from ammo rentals — a serialised
		// firearm goes out and must come back, so it has an explicit return
		// route rather than ammo's "close".
		register_rest_route(
			'g2a-pos/v1',
			'/range/firearm-rentals',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeOpsController::class, 'firearm_open' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/firearm-rentals/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeOpsController::class, 'firearm_recent' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/firearm-rentals',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeOpsController::class, 'firearm_issue' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/firearm-rentals/(?P<id>\d+)/return',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeOpsController::class, 'firearm_return' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/brass',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeOpsController::class, 'brass_list' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/brass',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeOpsController::class, 'brass_record' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// --- v1.7.0 — Range maintenance + incident log ---
		register_rest_route(
			'g2a-pos/v1',
			'/range/maintenance',
			array(
				'methods'             => 'GET',
				'callback'            => array( LaneMaintenanceController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/maintenance',
			array(
				'methods'             => 'POST',
				'callback'            => array( LaneMaintenanceController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/maintenance/lanes-out',
			array(
				'methods'             => 'GET',
				'callback'            => array( LaneMaintenanceController::class, 'lanes_out' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/maintenance/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( LaneMaintenanceController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/maintenance/(?P<id>\d+)/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( LaneMaintenanceController::class, 'start' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/maintenance/(?P<id>\d+)/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( LaneMaintenanceController::class, 'close' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		register_rest_route(
			'g2a-pos/v1',
			'/range/incidents',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeIncidentController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/incidents',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeIncidentController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/incidents/severities',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeIncidentController::class, 'severities' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/incidents/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeIncidentController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/incidents/(?P<id>\d+)/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( RangeIncidentController::class, 'close' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/range/incidents/customer/(?P<customer_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( RangeIncidentController::class, 'customer_history' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// Trade-in firearms.
		register_rest_route(
			'g2a-pos/v1',
			'/tradeins',
			array(
				'methods'             => 'GET',
				'callback'            => array( TradeInController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/tradeins',
			array(
				'methods'             => 'POST',
				'callback'            => array( TradeInController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_edit_bound_book' ) || current_user_can( 'g2a_pos_process_firearm_sale' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/tradeins/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( TradeInController::class, 'get' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// Hardware kit (printer / drawer / customer display).
		register_rest_route(
			'g2a-pos/v1',
			'/hardware/profiles',
			array(
				'methods'             => 'GET',
				'callback'            => array( HardwareController::class, 'profiles' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'g2a_pos_manage_register' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/hardware/profiles',
			array(
				'methods'             => 'POST',
				'callback'            => array( HardwareController::class, 'upsert_profile' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/hardware/profile',
			array(
				'methods'             => 'GET',
				'callback'            => array( HardwareController::class, 'get_profile' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/hardware/receipt',
			array(
				'methods'             => 'POST',
				'callback'            => array( HardwareController::class, 'render_receipt' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/hardware/drawer/kick',
			array(
				'methods'             => 'POST',
				'callback'            => array( HardwareController::class, 'kick_drawer' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ) || current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/hardware/customer-display',
			array(
				'methods'             => 'POST',
				'callback'            => array( HardwareController::class, 'customer_display' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// Compliance calendar.
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/calendar',
			array(
				'methods'             => 'GET',
				'callback'            => array( ComplianceCalendarController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/calendar',
			array(
				'methods'             => 'POST',
				'callback'            => array( ComplianceCalendarController::class, 'create' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/compliance/calendar/(?P<id>\d+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( ComplianceCalendarController::class, 'complete' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// PO three-way match (ASN + Invoice) + backorders.
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders/(?P<id>\d+)/asn',
			array(
				'methods'             => 'POST',
				'callback'            => array( PoMatchController::class, 'record_asn' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders/(?P<id>\d+)/invoice',
			array(
				'methods'             => 'POST',
				'callback'            => array( PoMatchController::class, 'record_invoice' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders/(?P<id>\d+)/bundle',
			array(
				'methods'             => 'GET',
				'callback'            => array( PoMatchController::class, 'bundle' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/purchase-orders/backorders',
			array(
				'methods'             => 'GET',
				'callback'            => array( PoMatchController::class, 'backorders' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_inventory' ),
			)
		);

		// Classes — partial payments.
		register_rest_route(
			'g2a-pos/v1',
			'/classes/enrollments/(?P<id>\d+)/payments',
			array(
				'methods'             => 'GET',
				'callback'            => array( ClassPaymentController::class, 'index' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/classes/enrollments/(?P<id>\d+)/payments',
			array(
				'methods'             => 'POST',
				'callback'            => array( ClassPaymentController::class, 'record' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_register' ) || current_user_can( 'g2a_pos_access' ),
			)
		);

		// ACE audit pack + 4473 XML export.
		register_rest_route(
			'g2a-pos/v1',
			'/atf/audit/ace-pack',
			array(
				'methods'             => 'GET',
				'callback'            => array( AceAuditController::class, 'pack' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/atf/4473/(?P<id>\d+)/xml',
			array(
				'methods'             => 'GET',
				'callback'            => array( AceAuditController::class, 'form_xml' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_compliance' ) || current_user_can( 'g2a_pos_view_bound_book' ),
			)
		);

		// FFL routing for customer shipments.
		register_rest_route(
			'g2a-pos/v1',
			'/ffl/route',
			array(
				'methods'             => 'GET',
				'callback'            => array( FflRoutingController::class, 'suggest' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_access' ),
			)
		);

		// Vendor cost-drift / price history.
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<id>\d+)/price-history/capture',
			array(
				'methods'             => 'POST',
				'callback'            => array( VendorAnalyticsController::class, 'capture' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/wholesalers/(?P<id>\d+)/price-history/drift',
			array(
				'methods'             => 'GET',
				'callback'            => array( VendorAnalyticsController::class, 'drift' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_wholesalers' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);

		// ---- v1.0.0 — AI Agent ----
		register_rest_route(
			'g2a-pos/v1',
			'/ai/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'settings' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'save_settings' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'conversations' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/conversations',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'start_conversation' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/conversations/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'get_conversation' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/conversations/(?P<id>\d+)/messages',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'send_message' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/pending-actions',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'pending_actions' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/pending-actions/(?P<id>\d+)/decide',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'decide_action' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'record_feedback' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'brain_documents' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/ingest-text',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'brain_ingest_text' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/ingest-url',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'brain_ingest_url' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( AiController::class, 'brain_delete' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/refresh-site',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'brain_refresh_site' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'brain_search' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/gateway/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'gateway_test' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/seed-defaults',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'brain_seed_defaults' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		// Business-state refresh: pushes the operational picture (trading,
		// compliance, range, inventory, membership, suppliers) into the brain.
		// Runs hourly on cron; this is the manual trigger and its status.
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/refresh-business',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'brain_refresh_business' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/business-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'brain_business_status' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'brain_stats' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_use_ai' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/cloudflare/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'brain_cloudflare_test' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/brain/migrate-cloudflare',
			array(
				'methods'             => 'POST',
				'callback'            => array( AiController::class, 'brain_migrate_cloudflare' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'audit_recent' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
		register_rest_route(
			'g2a-pos/v1',
			'/ai/audit/verify',
			array(
				'methods'             => 'GET',
				'callback'            => array( AiController::class, 'audit_verify' ),
				'permission_callback' => static fn() => current_user_can( 'g2a_pos_manage_ai' ) || current_user_can( 'g2a_pos_manage_settings' ),
			)
		);
	}
}
