<?php
/**
 * WooCommerce checkout integration.
 *
 * - Adds an "FFL Required" product meta field (admin checkbox)
 * - Detects FFL items in the cart at checkout
 * - Injects the FFL dealer selector widget below the billing fields
 * - Validates that a dealer is selected before the order can be placed
 * - Saves the dealer selection to order meta + creates a transfer record
 * - Enqueues the vanilla JS widget + CSS only when FFL items are in cart
 *
 * Supports both Classic Checkout and WooCommerce Blocks (HPOS compatible).
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Checkout {

	/** Order meta key that stores the selected dealer ID */
	const META_DEALER_ID   = '_wpistic_ffl_dealer_id';
	const META_DEALER_NAME = '_wpistic_ffl_dealer_name';
	const META_DEALER_ZIP  = '_wpistic_ffl_dealer_zip';
	const META_TRANSFER_ID = '_wpistic_ffl_transfer_id';

	public function __construct() {
		// Product admin: add FFL checkbox to product data panel
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_ffl_product_field' ] );
		add_action( 'woocommerce_process_product_meta',                 [ $this, 'save_ffl_product_field' ] );
		add_action( 'woocommerce_product_after_variable_attributes',    [ $this, 'add_ffl_variation_field' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation',               [ $this, 'save_ffl_variation_field' ], 10, 2 );

		// Checkout widget (Classic)
		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_ffl_widget' ] );
		add_action( 'woocommerce_checkout_process',            [ $this, 'validate_ffl_selection' ] );

		// Save order meta — use both the legacy hook (WC < 8) and the modern hook (WC 8+)
		// to ensure compatibility across WooCommerce versions.
		add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_order_ffl_meta' ] );         // WC < 8
		add_action( 'woocommerce_checkout_order_created',     [ $this, 'save_order_ffl_meta_order' ] );   // WC 8+

		// Order placed — create transfer record
		add_action( 'woocommerce_payment_complete',            [ $this, 'create_transfer_on_payment' ] );
		add_action( 'woocommerce_order_status_processing',     [ $this, 'create_transfer_on_payment' ] );

		// Display dealer info in order admin
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'display_dealer_in_admin' ] );
		add_action( 'woocommerce_order_details_after_order_table',         [ $this, 'display_dealer_in_customer_email' ] );

		// Enqueue JS + CSS
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
	}

	// ── Product meta ───────────────────────────────────────────────

	/** Allowed values for _wpistic_ffl_item_type — canonical vocabulary used across compliance, A&D, and multi-sale detection. */
	const ITEM_TYPES = [ 'handgun', 'rifle', 'shotgun' ];

	public function add_ffl_product_field(): void {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( [
			'id'          => '_wpistic_ffl_required',
			'label'       => __( 'FFL Transfer Required', 'advanced-ffl-checkout' ),
			'description' => __( 'Check if this product requires an FFL dealer transfer (firearms, regulated items).', 'advanced-ffl-checkout' ),
		] );
		// v1.10.0 — these were previously read (state compliance rules, transfer
		// record, 4473 worksheet, A&D ledger, multi-sale detection) but had no
		// admin field to set them, so every product silently defaulted to
		// item_type=handgun. Added so store owners can classify products for
		// real instead of the default masking every long-gun as a handgun.
		woocommerce_wp_select( [
			'id'          => '_wpistic_ffl_item_type',
			'label'       => __( 'FFL Item Type', 'advanced-ffl-checkout' ),
			'description' => __( 'Used for state compliance rules, the 4473 worksheet, the A&D ledger, and federal multi-sale (Form 3310.4) detection. Defaults to Handgun when unset.', 'advanced-ffl-checkout' ),
			'options'     => [
				'handgun' => __( 'Handgun (pistol/revolver)', 'advanced-ffl-checkout' ),
				'rifle'   => __( 'Rifle', 'advanced-ffl-checkout' ),
				'shotgun' => __( 'Shotgun', 'advanced-ffl-checkout' ),
			],
		] );
		woocommerce_wp_text_input( [
			'id'          => '_wpistic_ffl_item_make',
			'label'       => __( 'Manufacturer / Importer', 'advanced-ffl-checkout' ),
			'description' => __( 'Pre-fills Section A of the 4473 worksheet and the A&D ledger.', 'advanced-ffl-checkout' ),
		] );
		woocommerce_wp_text_input( [
			'id'    => '_wpistic_ffl_item_model',
			'label' => __( 'Model', 'advanced-ffl-checkout' ),
		] );
		woocommerce_wp_text_input( [
			'id'    => '_wpistic_ffl_item_caliber',
			'label' => __( 'Caliber / Gauge', 'advanced-ffl-checkout' ),
		] );
		woocommerce_wp_checkbox( [
			'id'          => '_wpistic_ffl_excise_taxable',
			'label'       => __( 'Federal Excise Tax (Pittman-Robertson)', 'advanced-ffl-checkout' ),
			'description' => __( 'Only check this if YOU are the manufacturer or importer of record for this item — the 10%/11% federal excise tax under 26 U.S.C. § 4181 is owed by manufacturers/importers, not by a reseller. Not legal or tax advice; confirm with counsel.', 'advanced-ffl-checkout' ),
		] );
		woocommerce_wp_select( [
			'id'          => '_wpistic_ffl_excise_rate',
			'label'       => __( 'Excise Tax Rate', 'advanced-ffl-checkout' ),
			'options'     => [
				'10' => __( '10% — pistols & revolvers', 'advanced-ffl-checkout' ),
				'11' => __( '11% — other firearms, ammunition', 'advanced-ffl-checkout' ),
			],
		] );
		woocommerce_wp_checkbox( [
			'id'          => '_wpistic_ffl_age_restricted',
			'label'       => __( 'Age-Restricted Item', 'advanced-ffl-checkout' ),
			'description' => __( 'Ammunition, magazines, or accessories that ship directly to the buyer (no FFL transfer) but are still legally age-restricted. Triggers ID/age verification in states that require it, independent of the FFL-transfer flag above.', 'advanced-ffl-checkout' ),
		] );
		echo '</div>';
	}

	public function save_ffl_product_field( int $post_id ): void {
		$value = isset( $_POST['_wpistic_ffl_required'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( $post_id, '_wpistic_ffl_required', $value );

		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce handles the product-save nonce.
		$item_type = sanitize_key( wp_unslash( $_POST['_wpistic_ffl_item_type'] ?? '' ) );
		update_post_meta( $post_id, '_wpistic_ffl_item_type', in_array( $item_type, self::ITEM_TYPES, true ) ? $item_type : 'handgun' );
		update_post_meta( $post_id, '_wpistic_ffl_item_make', sanitize_text_field( wp_unslash( $_POST['_wpistic_ffl_item_make'] ?? '' ) ) );
		update_post_meta( $post_id, '_wpistic_ffl_item_model', sanitize_text_field( wp_unslash( $_POST['_wpistic_ffl_item_model'] ?? '' ) ) );
		update_post_meta( $post_id, '_wpistic_ffl_item_caliber', sanitize_text_field( wp_unslash( $_POST['_wpistic_ffl_item_caliber'] ?? '' ) ) );
		update_post_meta( $post_id, '_wpistic_ffl_excise_taxable', isset( $_POST['_wpistic_ffl_excise_taxable'] ) ? 'yes' : 'no' );
		$excise_rate = sanitize_text_field( wp_unslash( $_POST['_wpistic_ffl_excise_rate'] ?? '11' ) );
		update_post_meta( $post_id, '_wpistic_ffl_excise_rate', in_array( $excise_rate, [ '10', '11' ], true ) ? $excise_rate : '11' );
		update_post_meta( $post_id, '_wpistic_ffl_age_restricted', isset( $_POST['_wpistic_ffl_age_restricted'] ) ? 'yes' : 'no' );
		// phpcs:enable
	}

	/**
	 * WooCommerce passes $loop as int (0, 1, 2...) and $variation as WP_Post.
	 * Use untyped parameters for broad compatibility across WC versions.
	 *
	 * @param int|string   $loop           Variation loop index.
	 * @param array        $variation_data Variation data array.
	 * @param \WP_Post     $variation      Variation post object.
	 */
	public function add_ffl_variation_field( $loop, $variation_data, $variation ): void {
		if ( ! isset( $variation->ID ) ) {
			return;
		}
		woocommerce_wp_checkbox( [
			'id'    => "_wpistic_ffl_required_variation_{$loop}",
			'name'  => "_wpistic_ffl_required_variation[{$loop}]",
			'label' => __( 'FFL Required', 'advanced-ffl-checkout' ),
			'value' => get_post_meta( (int) $variation->ID, '_wpistic_ffl_required', true ),
		] );
	}

	public function save_ffl_variation_field( int $variation_id, int $loop ): void {
		$value = isset( $_POST['_wpistic_ffl_required_variation'][ $loop ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( $variation_id, '_wpistic_ffl_required', $value );
	}

	// ── Checkout widget ────────────────────────────────────────────

	public function render_ffl_widget(): void {
		if ( ! $this->cart_has_ffl_items() ) {
			return;
		}

		$settings    = get_option( 'wpistic_ffl_settings', [] );
		$default_zip = WC()->customer ? WC()->customer->get_billing_postcode() : '';
		$radius      = (int) ( $settings['default_radius'] ?? 50 );
		$gmaps_key   = (string) ( $settings['google_maps_key'] ?? '' );

		// US state list for filter dropdown
		$states = [ 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC' ];
		?>
		<div id="wpistic-ffl-widget" class="wpistic-ffl-widget" data-radius="<?php echo esc_attr( (string) $radius ); ?>" data-has-gmaps="<?php echo $gmaps_key ? '1' : '0'; ?>">

			<div class="wpistic-ffl-widget__header">
				<h3 class="wpistic-ffl-widget__heading">
					<svg class="wpistic-ffl-widget__shield" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 1l9 4v6c0 5.55-3.84 10.74-9 12-5.16-1.26-9-6.45-9-12V5l9-4z"/></svg>
					<?php esc_html_e( 'Select Your FFL Dealer', 'advanced-ffl-checkout' ); ?>
				</h3>
				<p class="wpistic-ffl-widget__lede"><?php esc_html_e( 'This order contains firearms that must transfer through a licensed FFL dealer. Search by ZIP, name, license number, or phone — recommended dealers shown first.', 'advanced-ffl-checkout' ); ?></p>
			</div>

			<!-- Saved-dealer Quick Pick (hydrated by JS when the user has any). -->
			<div id="wpistic-ffl-saved" class="wpistic-ffl-saved" style="display:none;"></div>

			<!-- Filter tabs -->
			<div class="wpistic-ffl-tabs" role="tablist">
				<button type="button" class="wpistic-ffl-tab is-active" data-tab="zip" role="tab" aria-selected="true"><?php esc_html_e( '📍 By ZIP', 'advanced-ffl-checkout' ); ?></button>
				<button type="button" class="wpistic-ffl-tab" data-tab="name"    role="tab"><?php esc_html_e( '🏪 By Name', 'advanced-ffl-checkout' ); ?></button>
				<button type="button" class="wpistic-ffl-tab" data-tab="license" role="tab"><?php esc_html_e( '🪪 License #', 'advanced-ffl-checkout' ); ?></button>
				<button type="button" class="wpistic-ffl-tab" data-tab="phone"   role="tab"><?php esc_html_e( '📞 Phone', 'advanced-ffl-checkout' ); ?></button>
			</div>

			<!-- Filter panels -->
			<div class="wpistic-ffl-filters">
				<!-- ZIP -->
				<div class="wpistic-ffl-filter-panel is-active" data-panel="zip">
					<div class="wpistic-ffl-input-group">
						<label class="wpistic-ffl-label" for="wpistic-ffl-zip"><?php esc_html_e( 'ZIP code', 'advanced-ffl-checkout' ); ?></label>
						<input type="text" id="wpistic-ffl-zip" class="wpistic-ffl-input" placeholder="<?php esc_attr_e( 'e.g. 85001', 'advanced-ffl-checkout' ); ?>" value="<?php echo esc_attr( $default_zip ); ?>" maxlength="5" inputmode="numeric">
					</div>
					<div class="wpistic-ffl-input-group wpistic-ffl-input-group--narrow">
						<label class="wpistic-ffl-label" for="wpistic-ffl-radius"><?php esc_html_e( 'Within', 'advanced-ffl-checkout' ); ?></label>
						<select id="wpistic-ffl-radius" class="wpistic-ffl-select">
							<?php foreach ( [ 10, 25, 50, 100, 200 ] as $r ) : ?>
								<option value="<?php echo esc_attr( (string) $r ); ?>" <?php selected( $radius, $r ); ?>><?php echo esc_html( $r ); ?> mi</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- Name -->
				<div class="wpistic-ffl-filter-panel" data-panel="name">
					<div class="wpistic-ffl-input-group">
						<label class="wpistic-ffl-label" for="wpistic-ffl-name"><?php esc_html_e( 'Dealer or business name', 'advanced-ffl-checkout' ); ?></label>
						<input type="text" id="wpistic-ffl-name" class="wpistic-ffl-input" placeholder="<?php esc_attr_e( 'e.g. G2A Tactical', 'advanced-ffl-checkout' ); ?>">
					</div>
					<div class="wpistic-ffl-input-group wpistic-ffl-input-group--narrow">
						<label class="wpistic-ffl-label" for="wpistic-ffl-state"><?php esc_html_e( 'State', 'advanced-ffl-checkout' ); ?></label>
						<select id="wpistic-ffl-state" class="wpistic-ffl-select">
							<option value=""><?php esc_html_e( 'Any', 'advanced-ffl-checkout' ); ?></option>
							<?php foreach ( $states as $st ) : ?>
								<option value="<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- License -->
				<div class="wpistic-ffl-filter-panel" data-panel="license">
					<div class="wpistic-ffl-input-group wpistic-ffl-input-group--full">
						<label class="wpistic-ffl-label" for="wpistic-ffl-license"><?php esc_html_e( 'FFL license number (full or partial)', 'advanced-ffl-checkout' ); ?></label>
						<input type="text" id="wpistic-ffl-license" class="wpistic-ffl-input" placeholder="<?php esc_attr_e( 'e.g. 9-86-XX-X-X-XXXXX', 'advanced-ffl-checkout' ); ?>">
					</div>
				</div>

				<!-- Phone -->
				<div class="wpistic-ffl-filter-panel" data-panel="phone">
					<div class="wpistic-ffl-input-group wpistic-ffl-input-group--full">
						<label class="wpistic-ffl-label" for="wpistic-ffl-phone"><?php esc_html_e( 'Dealer phone number', 'advanced-ffl-checkout' ); ?></label>
						<input type="tel" id="wpistic-ffl-phone" class="wpistic-ffl-input" placeholder="<?php esc_attr_e( 'e.g. 480-555-0100', 'advanced-ffl-checkout' ); ?>">
					</div>
				</div>

				<button type="button" id="wpistic-ffl-search-btn" class="wpistic-ffl-btn wpistic-ffl-btn--primary">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
					<?php esc_html_e( 'Search Dealers', 'advanced-ffl-checkout' ); ?>
				</button>
			</div>

			<!-- View toggle (List / Map) — only when GMaps key configured -->
			<?php if ( $gmaps_key ) : ?>
			<div class="wpistic-ffl-viewtoggle" role="tablist" style="display:none;">
				<button type="button" class="wpistic-ffl-view-btn is-active" data-view="list"><?php esc_html_e( '📋 List', 'advanced-ffl-checkout' ); ?></button>
				<button type="button" class="wpistic-ffl-view-btn" data-view="map"><?php esc_html_e( '🗺️ Map', 'advanced-ffl-checkout' ); ?></button>
			</div>
			<?php endif; ?>

			<!-- Results count + sort -->
			<div class="wpistic-ffl-resultsbar" style="display:none;">
				<span class="wpistic-ffl-resultsbar__count" id="wpistic-ffl-count"></span>
			</div>

			<!-- Map -->
			<?php if ( $gmaps_key ) : ?>
				<div id="wpistic-ffl-map" class="wpistic-ffl-map" style="display:none;"></div>
			<?php endif; ?>

			<!-- Results list -->
			<div id="wpistic-ffl-results" class="wpistic-ffl-results" aria-live="polite"></div>

			<!-- Selected -->
			<div id="wpistic-ffl-selected" class="wpistic-ffl-selected" style="display:none;">
				<div class="wpistic-ffl-selected__header">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
					<?php esc_html_e( 'Selected Dealer', 'advanced-ffl-checkout' ); ?>
				</div>
				<div id="wpistic-ffl-selected-info" class="wpistic-ffl-selected__info"></div>
				<button type="button" id="wpistic-ffl-change-btn" class="wpistic-ffl-btn wpistic-ffl-btn--ghost"><?php esc_html_e( 'Change Dealer', 'advanced-ffl-checkout' ); ?></button>
			</div>

			<!-- Honeypot — real users never see/fill this; bots do. -->
			<label class="wpistic-ffl-hp" aria-hidden="true" style="position:absolute;left:-99999px;width:1px;height:1px;overflow:hidden;">
				<?php esc_html_e( 'Dealer URL (leave blank)', 'advanced-ffl-checkout' ); ?>
				<input type="text" name="wpistic_ffl_hp" value="" tabindex="-1" autocomplete="off">
			</label>

			<!-- Hidden fields -->
			<input type="hidden" id="wpistic_ffl_dealer_id"   name="wpistic_ffl_dealer_id"   value="">
			<input type="hidden" id="wpistic_ffl_dealer_name" name="wpistic_ffl_dealer_name" value="">
			<input type="hidden" id="wpistic_ffl_dealer_zip"  name="wpistic_ffl_dealer_zip"  value="">
			<input type="hidden" id="wpistic_ffl_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
		</div>
		<?php
	}

	public function validate_ffl_selection(): void {
		if ( ! $this->cart_has_ffl_items() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		// Honeypot — bots fill any visible-looking text input. Silent reject.
		if ( ! empty( $_POST['wpistic_ffl_hp'] ) ) {
			wc_add_notice(
				__( 'Your submission was flagged by our spam filter. Please reload and try again.', 'advanced-ffl-checkout' ),
				'error'
			);
			return;
		}

		$dealer_id = (int) ( $_POST['wpistic_ffl_dealer_id'] ?? 0 );
		// phpcs:enable
		if ( ! $dealer_id ) {
			wc_add_notice(
				__( 'Please select an FFL dealer before placing your order.', 'advanced-ffl-checkout' ),
				'error'
			);
		}
	}

	/**
	 * Save FFL meta — WooCommerce < 8 (legacy hook, passes order_id int).
	 * Routes through the WC order API so HPOS-only stores keep working.
	 */
	public function save_order_ffl_meta( int $order_id ): void {
		if ( ! $this->cart_has_ffl_items() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$this->persist_dealer_meta_on_order( $order );
	}

	/**
	 * Save FFL meta — WooCommerce 8+ (modern hook, passes WC_Order object).
	 *
	 * @param \WC_Order $order
	 */
	public function save_order_ffl_meta_order( $order ): void {
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}
		if ( ! $this->cart_has_ffl_items() ) {
			return;
		}
		$this->persist_dealer_meta_on_order( $order );
	}

	/**
	 * Shared persistence path — HPOS-safe via $order->update_meta_data().
	 * Also validates the submitted dealer_id exists in the dealers table to
	 * prevent attackers from poisoning order meta with a forged ID.
	 */
	private function persist_dealer_meta_on_order( \WC_Order $order ): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$dealer_id   = (int) ( $_POST['wpistic_ffl_dealer_id'] ?? 0 );
		$dealer_name = sanitize_text_field( wp_unslash( $_POST['wpistic_ffl_dealer_name'] ?? '' ) );
		$dealer_zip  = sanitize_text_field( wp_unslash( $_POST['wpistic_ffl_dealer_zip'] ?? '' ) );
		// phpcs:enable

		if ( ! $dealer_id ) {
			return;
		}

		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d AND is_active = 1 LIMIT 1',
			$dealer_id
		) );
		if ( ! $exists ) {
			return;
		}

		$order->update_meta_data( self::META_DEALER_ID,   $dealer_id );
		$order->update_meta_data( self::META_DEALER_NAME, $dealer_name );
		$order->update_meta_data( self::META_DEALER_ZIP,  $dealer_zip );
		$order->save();
	}

	// ── Transfer record creation ─────────────────────────────────────

	/**
	 * Every FFL-flagged line item in the order, one entry per physical unit
	 * (a line with quantity 2 yields the item twice) — each unit becomes its
	 * own transfer/bound-book record. Order meta only carries a single dealer
	 * selection, so all units in an order still go to the same dealer.
	 *
	 * @return array<int, array{item: \WC_Order_Item_Product, product: \WC_Product}>
	 */
	public static function get_ffl_items( \WC_Order $order ): array {
		$units = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! self::product_requires_ffl( $product->get_id() ) ) {
				continue;
			}
			$qty = max( 1, (int) $item->get_quantity() );
			for ( $i = 0; $i < $qty; $i++ ) {
				$units[] = [ 'item' => $item, 'product' => $product ];
			}
		}
		return $units;
	}

	/**
	 * Creates one `transfers` row per physical FFL unit in the order (see
	 * get_ffl_items()). This method is hooked on both woocommerce_payment_
	 * complete and woocommerce_order_status_processing, which many gateways
	 * fire close together for the same order — insert_one_transfer_atomic()
	 * below is what makes a duplicate call for the same physical unit safe,
	 * not any check performed here.
	 */
	public function create_transfer_on_payment( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$dealer_id = (int) $order->get_meta( self::META_DEALER_ID );
		if ( ! $dealer_id ) {
			return;
		}

		$ffl_units = self::get_ffl_items( $order );
		if ( ! $ffl_units ) {
			return;
		}

		global $wpdb;

		// Advisory: log when dealer state and buyer billing state don't match.
		// Once per order — the dealer/buyer pair doesn't vary per line item.
		$dealer_row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, state FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d LIMIT 1',
			$dealer_id
		) );
		if ( $dealer_row ) {
			Compliance::validate_dealer_for_buyer( $dealer_row, $order );
		}

		$portal_settings   = get_option( 'wpistic_ffl_portal_settings', [] );
		$can_notify_dealer = $order->is_paid() && ! empty( $portal_settings['enabled'] ) && ! empty( $portal_settings['notify_dealer_on_order'] );

		$units_by_item = [];
		foreach ( $ffl_units as $unit ) {
			$units_by_item[ $unit['item']->get_id() ][] = $unit['product'];
		}

		foreach ( $units_by_item as $order_item_id => $products ) {
			foreach ( $products as $i => $product ) {
				$this->insert_one_transfer_atomic( $order, $order_id, $order_item_id, $i + 1, $product, $dealer_id, $can_notify_dealer );
			}
		}
	}

	/**
	 * $order_item_unit is the 1-based position of this unit within its line
	 * item's quantity (a qty-2 line inserts unit 1 then unit 2).
	 *
	 * Atomicity note: `transfers.uidx_order_item_unit` is UNIQUE on
	 * (order_id, order_item_id, order_item_unit). Two near-simultaneous
	 * calls for the same order (see create_transfer_on_payment() docblock)
	 * can both reach this method for the same unit; the DB — not a PHP-side
	 * check — is what guarantees only one of them actually creates a row.
	 * The losing call's insert fails here, so it looks up the winning call's
	 * transfer_id and links the order meta to it, but skips every side
	 * effect (event/email/dealer-notify/action) so they only fire once, for
	 * whichever call actually created the row. Backports a fix independently
	 * made in the guns2ammo repo for the pre-v1.12.0 single-transfer model
	 * (`UNIQUE KEY (order_id)`), adapted here to the multi-unit model.
	 */
	private function insert_one_transfer_atomic( \WC_Order $order, int $order_id, int $order_item_id, int $order_item_unit, \WC_Product $ffl_product, int $dealer_id, bool $can_notify_dealer ): void {
		global $wpdb;
		$ref = 'G2A-' . strtoupper( substr( uniqid( '', true ), -8 ) );

		$inserted = $wpdb->insert( DB::table( 'transfers' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_ref'     => $ref,
			'order_id'         => $order_id,
			'order_item_id'    => $order_item_id,
			'order_item_unit'  => $order_item_unit,
			'customer_id'      => $order->get_customer_id() ?: null,
			'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'customer_email'   => $order->get_billing_email(),
			'customer_phone'   => $order->get_billing_phone(),
			'customer_ip'      => self::ip_to_binary( $order->get_customer_ip_address() ),
			'dealer_id'        => $dealer_id,
			'status'           => 'payment_confirmed',
			'item_description' => $ffl_product->get_name(),
			'item_sku'         => $ffl_product->get_sku(),
			'item_type'        => get_post_meta( $ffl_product->get_id(), '_wpistic_ffl_item_type', true ) ?: 'handgun',
			'item_make'        => get_post_meta( $ffl_product->get_id(), '_wpistic_ffl_item_make', true ) ?: '',
			'item_model'       => get_post_meta( $ffl_product->get_id(), '_wpistic_ffl_item_model', true ) ?: '',
			'item_caliber'     => get_post_meta( $ffl_product->get_id(), '_wpistic_ffl_item_caliber', true ) ?: '',
		] );

		$transfer_id = (int) $wpdb->insert_id;
		$is_new      = (bool) ( $inserted && $transfer_id );

		if ( ! $is_new ) {
			$transfer_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT id FROM ' . DB::table( 'transfers' ) . '
				 WHERE order_id = %d AND order_item_id = %d AND order_item_unit = %d LIMIT 1',
				$order_id, $order_item_id, $order_item_unit
			) );
		}
		if ( ! $transfer_id ) {
			return;
		}

		// Non-unique on purpose — one meta row per transfer this order owns.
		// Read all of them back with $order->get_meta( self::META_TRANSFER_ID, false ).
		$order->add_meta_data( self::META_TRANSFER_ID, $transfer_id, false );
		$order->save();

		if ( ! $is_new ) {
			return; // Another concurrent call already created this row and ran the side effects below.
		}

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => $transfer_id,
			'event_type'  => 'created',
			'new_status'  => 'payment_confirmed',
			'notes'       => "Transfer created automatically from Order #{$order_id}",
			'actor'       => 'system',
			'actor_ip'    => '',
		] );

		Mailer::send_status_update( $transfer_id, 'payment_confirmed' );

		// G2A: dealer notification is gated on actual payment (avoids COD /
		// failed-payment leakage), and async-scheduled so a slow SMTP doesn't
		// stall checkout. Filterable for special-case integrations.
		$notify = (bool) apply_filters( 'wpistic_ffl_can_notify_dealer_on_order', $can_notify_dealer, $order, $transfer_id );
		if ( $notify ) {
			// Async — never block checkout on SMTP. Routes through
			// Action Scheduler when available, WP-Cron otherwise.
			G2A_Scheduler::async( 'wpistic_ffl_async_issue_dealer_token', [ $transfer_id ] );
		}

		do_action( 'wpistic_ffl_transfer_created', $transfer_id, $order_id );
	}

	// ── Admin + customer display ────────────────────────────────────

	/**
	 * Display FFL info in WP admin order screen.
	 * Uses untyped $order param for HPOS + legacy compatibility.
	 *
	 * @param mixed $order WC_Order object passed by WooCommerce.
	 */
	public function display_dealer_in_admin( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$dealer_name = $order->get_meta( self::META_DEALER_NAME );
		if ( ! $dealer_name ) {
			return;
		}

		// Queried from the DB rather than order meta — an order can now own
		// several transfers (one per FFL unit), and the DB is authoritative.
		global $wpdb;
		$transfer_ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id FROM ' . DB::table( 'transfers' ) . ' WHERE order_id = %d ORDER BY id ASC',
			$order->get_id()
		) );

		echo '<div class="wpistic-ffl-order-meta">';
		echo '<h4>' . esc_html__( 'FFL Transfer', 'advanced-ffl-checkout' ) . '</h4>';
		echo '<p><strong>' . esc_html__( 'Dealer:', 'advanced-ffl-checkout' ) . '</strong> ' . esc_html( $dealer_name ) . '</p>';
		if ( $transfer_ids ) {
			$label = count( $transfer_ids ) > 1 ? __( 'Transfer IDs:', 'advanced-ffl-checkout' ) : __( 'Transfer ID:', 'advanced-ffl-checkout' );
			$ids   = implode( ', ', array_map( static fn( $id ) => '#' . (string) $id, $transfer_ids ) );
			echo '<p><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $ids ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Display FFL info in customer order confirmation / email.
	 * Uses untyped $order param for HPOS + legacy compatibility.
	 *
	 * @param mixed $order WC_Order object passed by WooCommerce.
	 */
	public function display_dealer_in_customer_email( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$dealer_name = $order->get_meta( self::META_DEALER_NAME );
		if ( ! $dealer_name ) {
			return;
		}
		// G2A: pull colors from the Theming engine so the order-details block
		// matches the rest of the FFL emails + dealer portal.
		$theme  = Theming::settings();
		$accent = $theme['color_primary'];
		$muted  = $theme['color_text_muted'];

		echo '<section class="wpistic-ffl-email-section" style="margin-top:24px;padding:16px;border-left:4px solid ' . esc_attr( $accent ) . ';background:rgba(0,0,0,.03);">';
		echo '<h2 style="margin:0 0 8px;font-size:16px;">' . esc_html__( 'FFL Dealer Selected', 'advanced-ffl-checkout' ) . '</h2>';
		echo '<p style="margin:0 0 4px;">' . esc_html__( 'Your firearm will be transferred through:', 'advanced-ffl-checkout' ) . ' <strong>' . esc_html( $dealer_name ) . '</strong></p>';
		echo '<p style="margin:0;font-size:12px;color:' . esc_attr( $muted ) . ';">' . esc_html__( 'You will be notified when your item arrives at the dealer and is ready for pickup.', 'advanced-ffl-checkout' ) . '</p>';
		echo '</section>';
	}

	// ── Asset enqueueing ───────────────────────────────────────────

	public function enqueue_checkout_assets(): void {
		if ( ! is_checkout() || ! $this->cart_has_ffl_items() ) {
			return;
		}

		wp_enqueue_style(
			'wpistic-ffl-checkout',
			WPISTIC_FFL_URL . 'assets/css/ffl-checkout.css',
			[],
			WPISTIC_FFL_VERSION
		);

		// Depend on jquery — most WooCommerce themes require it, and our JS
		// calls jQuery('body').trigger('update_checkout') for the review refresh.
		wp_enqueue_script(
			'wpistic-ffl-checkout',
			WPISTIC_FFL_URL . 'assets/js/ffl-checkout.js',
			[ 'jquery' ],
			WPISTIC_FFL_VERSION,
			true  // load in footer so DOM is ready
		);

		$settings   = get_option( 'wpistic_ffl_settings', [] );
		$gmaps_key  = (string) ( $settings['google_maps_key'] ?? '' );

		// Lazy-load Google Maps JS only if a key is configured.
		// Async + defer prevents render blocking; "weekly" channel pins to the latest stable.
		if ( $gmaps_key ) {
			wp_enqueue_script(
				'wpistic-ffl-gmaps',
				'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( $gmaps_key ) . '&v=weekly&loading=async',
				[],
				null,
				[ 'in_footer' => true, 'strategy' => 'async' ]
			);
		}

		// Filter-able payload so feature classes (e.g. saved dealers) can
		// extend the localized data without touching Checkout.
		$localized = apply_filters( 'wpistic_ffl_checkout_localize', [
			'api_url'   => rest_url( WPISTIC_FFL_REST_NS . '/dealers/search' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'radius'    => (int) ( $settings['default_radius'] ?? 50 ),
			'gmaps_key' => $gmaps_key,
			'i18n'      => [
				'searching'    => __( 'Searching dealers…', 'advanced-ffl-checkout' ),
				'no_results'   => __( 'No FFL dealers match. Try a different filter, larger radius, or a different ZIP/state.', 'advanced-ffl-checkout' ),
				'error'        => __( 'Error searching for dealers. Please try again.', 'advanced-ffl-checkout' ),
				'select_btn'   => __( 'Select', 'advanced-ffl-checkout' ),
				'selected'     => __( 'Selected', 'advanced-ffl-checkout' ),
				'search_btn'   => __( 'Search Dealers', 'advanced-ffl-checkout' ),
				'miles_away'   => __( 'miles away', 'advanced-ffl-checkout' ),
				'preferred'    => __( 'Recommended', 'advanced-ffl-checkout' ),
				'fee_free'     => __( 'Contact Dealer for Fee', 'advanced-ffl-checkout' ),
				'fee_label'    => __( 'Transfer Fee', 'advanced-ffl-checkout' ),
				'invalid_zip'  => __( 'Please enter a valid 5-digit ZIP code.', 'advanced-ffl-checkout' ),
				'min_filter'   => __( 'Enter at least one filter to search.', 'advanced-ffl-checkout' ),
				'count_one'    => __( 'dealer found', 'advanced-ffl-checkout' ),
				'count_many'   => __( 'dealers found', 'advanced-ffl-checkout' ),
				'no_geo'       => __( 'Address only — not yet on the map', 'advanced-ffl-checkout' ),
			],
		] );
		wp_localize_script( 'wpistic-ffl-checkout', 'wpistic_ffl', $localized );
	}

	// ── Helpers ───────────────────────────────────────────────────

	public function cart_has_ffl_items(): bool {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = $item['variation_id'] ?: $item['product_id'];
			if ( self::product_requires_ffl( $product_id ) ) {
				return true;
			}
		}
		return false;
	}

	public static function product_requires_ffl( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, '_wpistic_ffl_required', true );
	}

	/** Same packed-binary storage as dealer_tokens.created_ip / Token::ip_to_binary(). */
	private static function ip_to_binary( string $ip ): ?string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $packed ? null : $packed;
	}
}
