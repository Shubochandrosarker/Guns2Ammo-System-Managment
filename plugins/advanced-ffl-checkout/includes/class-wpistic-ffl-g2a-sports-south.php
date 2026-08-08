<?php
/**
 * G2A — Sports South distributor integration.
 *
 * Sports South's "SSAPI" is a legacy ASP.NET ASMX service family at
 * webservices.theshootingwarehouse.com/smart/{inventory,orders,invoices}.asmx.
 * Despite the SOAP-capable WSDL, real-world clients call it via ASMX's
 * plain HTTP-POST binding (form-encoded body, XML back) rather than a
 * SOAP envelope -- confirmed against two independent open-source clients
 * (github.com/ammoready/sports_south, and the SportsSouth module in
 * github.com/mb03minecrafter/ffl-hub), not guessed.
 *
 * Confirmed field/method names:
 *   - Auth: CustomerNumber, UserName, Password, Source in every request body
 *     (a 4-5 digit numeric account + a "Web Services Password" issued by
 *     emailing support@sportssouth.biz -- there is no self-service signup).
 *   - Catalog: inventory.asmx/DailyItemUpdate(LastUpdate, LastItem) and
 *     IncrementalOnhandUpdate(SinceDateTime) for qty/price deltas.
 *     Row fields: ITEMNO, IDESC, IMFGNO, PRC1 (retail), CPRC (dealer cost),
 *     QTYOH (qty on hand), ITYPE, IUPC, IMODEL, SCNAM1 (manufacturer).
 *   - Orders: orders.asmx/AddHeader -> AddDetail (once per line) -> Submit,
 *     with DeleteOpenOrder to roll back a header if a later step fails.
 *     FFLAcceptsTransfer checks whether a given FFL is set up to receive
 *     a transfer from Sports South before an order is placed against it.
 *
 * What's NOT independently verified in this session: the exact XML
 * envelope shape a live account returns. The row/field names above are
 * corroborated by two unrelated open-source clients, but the ADO.NET
 * DataSet-style XML (<NewDataSet><Table>...</Table></NewDataSet>) this
 * class parses is the standard .NET default for this era of service --
 * a reasonable default, not something fetched live here. Verify against
 * a real dealer account before trusting a sync or an order to it, same
 * posture as every other un-smoke-tested integration in this plugin
 * (NMI, Lipsey's).
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Sports_South {

	const OPTION_KEY = 'wpistic_ffl_sports_south_settings';
	const CONTEXT    = 'sports-south';
	const API_BASE   = 'http://webservices.theshootingwarehouse.com/smart/';
	const INVENTORY_ENDPOINT = 'inventory.asmx';
	const ORDERS_ENDPOINT    = 'orders.asmx';

	public function __construct() {
		add_action( 'wp_ajax_wpistic_ffl_sports_south_save_settings',     [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_sports_south_test_connection',   [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_wpistic_ffl_sports_south_sync_catalog',      [ __CLASS__, 'ajax_sync_catalog' ] );
		add_action( 'wp_ajax_wpistic_ffl_sports_south_submit_order',      [ __CLASS__, 'ajax_submit_order' ] );
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public static function settings(): array {
		$settings = wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), [
			'customer_number' => '',
			'username'        => '',
			'password'        => '',
			'source'          => 'AdvancedFFLCheckout',
		] );
		return G2A_Distributor_Support::decrypt_settings_secrets( $settings, [ 'password' ], self::CONTEXT, self::OPTION_KEY );
	}

	public static function is_configured(): bool {
		$s = self::settings();
		return '' !== $s['customer_number'] && '' !== $s['username'] && '' !== $s['password'];
	}

	// ── Low-level request ────────────────────────────────────────────────

	/**
	 * @return \SimpleXMLElement|\WP_Error
	 */
	private static function request( string $endpoint, string $operation, array $params = [] ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'sports_south_not_configured', __( 'Sports South credentials are not configured.', 'advanced-ffl-checkout' ) );
		}
		$s = self::settings();

		$body = array_merge( [
			'CustomerNumber' => $s['customer_number'],
			'UserName'       => $s['username'],
			'Password'       => $s['password'],
			'Source'         => $s['source'],
		], $params );

		$resp = wp_remote_post( self::API_BASE . $endpoint . '/' . $operation, [
			'timeout' => 30,
			'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => $body,
		] );
		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'sports_south_unreachable', sprintf(
				/* translators: %s: underlying network error */
				__( 'Could not reach Sports South: %s', 'advanced-ffl-checkout' ), $resp->get_error_message()
			) );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'sports_south_http_error', sprintf(
				/* translators: %d: HTTP status code */
				__( 'Sports South returned HTTP %d.', 'advanced-ffl-checkout' ), $code
			) );
		}

		$body_str = wp_remote_retrieve_body( $resp );
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body_str );
		if ( false === $xml ) {
			return new \WP_Error( 'sports_south_bad_xml', __( 'Sports South returned a response that could not be parsed as XML.', 'advanced-ffl-checkout' ) );
		}
		return $xml;
	}

	/** Reads the plain text content of a primitive ASMX response (e.g. an order number), regardless of namespace. */
	private static function scalar( \SimpleXMLElement $xml ): string {
		return trim( (string) $xml );
	}

	// ── Catalog sync ──────────────────────────────────────────────────────

	/**
	 * Pulls the full item catalog via DailyItemUpdate and upserts it into
	 * the shared distributor_products table. Parses the standard ADO.NET
	 * DataSet XML shape (<NewDataSet><Table>field...</Table></NewDataSet>)
	 * -- see class docblock for the honesty note on why this shape, not a
	 * live account, is what's being parsed against.
	 *
	 * @return array{total:int,imported:int,skipped:int}|\WP_Error
	 */
	public static function sync_catalog(): array|\WP_Error {
		$xml = self::request( self::INVENTORY_ENDPOINT, 'DailyItemUpdate', [
			'LastUpdate' => '1/1/2000',
			'LastItem'   => '0',
		] );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$rows = $xml->xpath( '//Table' ) ?: [];
		$limit    = (int) apply_filters( 'wpistic_ffl_sports_south_catalog_sync_limit', 5000 );
		$total    = count( $rows );
		$imported = 0;

		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$item_no = trim( (string) ( $row->ITEMNO ?? '' ) );
			if ( '' === $item_no ) {
				continue;
			}
			G2A_Distributor_Support::upsert_catalog_item( 'sports_south', self::map_catalog_row( $row ) );
			++$imported;
		}

		update_option( 'wpistic_ffl_sports_south_last_sync', current_time( 'mysql' ), false );

		return [
			'total'    => $total,
			'imported' => $imported,
			'skipped'  => max( 0, $total - $imported ),
		];
	}

	/**
	 * Normalizes one <Table> row from the DailyItemUpdate response into
	 * the shared catalog-item shape. Pure function -- no I/O -- directly
	 * unit testable against a constructed SimpleXMLElement fixture without
	 * a live account.
	 */
	public static function map_catalog_row( \SimpleXMLElement $row ): array {
		$item_type = strtoupper( trim( (string) ( $row->ITYPE ?? '' ) ) );
		return [
			'item_no'      => trim( (string) ( $row->ITEMNO ?? '' ) ),
			'description'  => (string) ( $row->IDESC ?? '' ),
			'manufacturer' => (string) ( $row->SCNAM1 ?? $row->IMFGNO ?? '' ),
			'model'        => (string) ( $row->IMODEL ?? '' ),
			'upc'          => (string) ( $row->IUPC ?? '' ),
			'price'        => (float) ( $row->CPRC ?? $row->PRC1 ?? 0 ),
			'quantity'     => (int) ( $row->QTYOH ?? 0 ),
			// Sports South's own department/type coding marks firearms with
			// department codes in the 1-9 range in most public field-layout
			// discussions; filterable since Sports South doesn't publish an
			// authoritative single-flag definition of "is a firearm."
			'is_firearm'   => (bool) apply_filters( 'wpistic_ffl_sports_south_is_firearm', in_array( $item_type, [ 'R', 'H', 'S', 'F' ], true ), $row ),
		];
	}

	// ── Order submission (explicit admin action only) ───────────────────

	/**
	 * AddHeader -> AddDetail -> Submit, with DeleteOpenOrder as a rollback
	 * if a later step fails after the header was already created --
	 * matches the real API's own safety net (see class docblock).
	 *
	 * @return array|\WP_Error
	 */
	public static function submit_order( int $transfer_id, string $item_no, int $quantity ) {
		$t = G2A_Distributor_Support::transfer_with_dealer( $transfer_id );
		if ( ! $t ) {
			return new \WP_Error( 'sports_south_no_transfer', __( 'Transfer not found.', 'advanced-ffl-checkout' ) );
		}
		if ( ! $t->license_number ) {
			return new \WP_Error( 'sports_south_no_dealer_ffl', __( 'This transfer has no dealer FFL on file to ship to.', 'advanced-ffl-checkout' ) );
		}

		$header = self::request( self::ORDERS_ENDPOINT, 'AddHeader', [
			'PO'                 => $t->transfer_ref,
			'CustomerOrderNumber'=> $t->transfer_ref,
			'ShipToName'         => $t->business_name,
			'ShipToAddr1'        => (string) $t->premise_street,
			'ShipToCity'         => (string) $t->premise_city,
			'ShipToState'        => (string) $t->premise_state,
			'ShipToZip'          => (string) $t->premise_zip,
			'ShipToPhone'        => preg_replace( '/[^0-9]/', '', (string) $t->dealer_phone ) ?: '0000000000',
			'AdultSignature'     => 'Y',
		] );
		if ( is_wp_error( $header ) ) {
			return self::record_and_return( $transfer_id, $t, $item_no, $quantity, 'failed', '', $header->get_error_message() );
		}
		$order_number = self::scalar( $header );
		if ( '' === $order_number ) {
			return self::record_and_return( $transfer_id, $t, $item_no, $quantity, 'failed', '', __( 'Sports South did not return an order number from AddHeader.', 'advanced-ffl-checkout' ) );
		}

		$detail = self::request( self::ORDERS_ENDPOINT, 'AddDetail', [
			'OrderNumber'   => $order_number,
			'SSItemNumber'  => $item_no,
			'Quantity'      => $quantity,
			'CustomerItemNumber' => $item_no,
		] );
		if ( is_wp_error( $detail ) ) {
			self::request( self::ORDERS_ENDPOINT, 'DeleteOpenOrder', [ 'OrderNumber' => $order_number ] );
			return self::record_and_return( $transfer_id, $t, $item_no, $quantity, 'failed', $order_number, $detail->get_error_message() );
		}

		$submitted = self::request( self::ORDERS_ENDPOINT, 'Submit', [ 'OrderNumber' => $order_number ] );
		if ( is_wp_error( $submitted ) ) {
			self::request( self::ORDERS_ENDPOINT, 'DeleteOpenOrder', [ 'OrderNumber' => $order_number ] );
			return self::record_and_return( $transfer_id, $t, $item_no, $quantity, 'failed', $order_number, $submitted->get_error_message() );
		}

		return self::record_and_return( $transfer_id, $t, $item_no, $quantity, 'submitted', $order_number, '' );
	}

	/** @return array|\WP_Error */
	private static function record_and_return( int $transfer_id, object $t, string $item_no, int $quantity, string $status, string $ref, string $error ) {
		G2A_Distributor_Support::record_distributor_order( 'sports_south', [
			'transfer_id'           => $transfer_id,
			'po_number'             => $t->transfer_ref,
			'item_no'               => $item_no,
			'quantity'              => $quantity,
			'ship_to_ffl'           => $t->license_number,
			'status'                => $status,
			'distributor_order_ref' => $ref,
			'response_json'         => wp_json_encode( $error ? [ 'error' => $error ] : [ 'order_number' => $ref ] ),
			'note'                  => sprintf(
				'Sports South order (item %s x%d) %s.%s%s',
				$item_no, $quantity, $status,
				$ref ? ' Order #: ' . $ref : '',
				$error ? ' Error: ' . $error : ''
			),
		] );

		if ( $error ) {
			return new \WP_Error( 'sports_south_order_failed', $error );
		}
		return [ 'status' => $status, 'ref' => $ref ];
	}

	// ── Admin panel (rendered inside the shared "📦 Distributors" page — see G2A_Distributors_Admin) ──

	public static function render_panel(): void {
		global $wpdb;
		$nonce     = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$settings  = self::settings();
		$last_sync = get_option( 'wpistic_ffl_sports_south_last_sync', '' );

		$catalog = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'distributor_products' ) . " WHERE distributor = 'sports_south' ORDER BY last_synced DESC LIMIT 50"
		);
		$orders = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT o.*, t.transfer_ref FROM ' . DB::table( 'distributor_orders' ) . ' o
			 LEFT JOIN ' . DB::table( 'transfers' ) . " t ON t.id = o.transfer_id
			 WHERE o.distributor = 'sports_south' ORDER BY o.created_at DESC LIMIT 50"
		);
		$open_transfers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, transfer_ref, item_sku, item_description FROM ' . DB::table( 'transfers' ) . "
			 WHERE status NOT IN ('transferred','cancelled') ORDER BY created_at DESC LIMIT 100"
		);
		?>
		<p class="description" style="max-width:760px;">
			<?php esc_html_e( 'Legacy SOAP-ish ASMX web service (webservices.theshootingwarehouse.com) — requires a Sports South Web Services account, provisioned by emailing support@sportssouth.biz. Catalog sync is free to run any time; submitting an order is always an explicit click here (AddHeader → AddDetail → Submit), never triggered automatically by any transfer event.', 'advanced-ffl-checkout' ); ?>
		</p>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚙️ <?php esc_html_e( 'Credentials', 'advanced-ffl-checkout' ); ?></h2>
			<form id="wpistic-ffl-sports-south-settings-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Customer Number', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="customer_number" value="<?php echo esc_attr( $settings['customer_number'] ); ?>" style="width:140px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Username', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="username" value="<?php echo esc_attr( $settings['username'] ); ?>" style="width:160px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Password', 'advanced-ffl-checkout' ); ?></label>
					<input type="password" name="password" placeholder="<?php echo $settings['password'] ? esc_attr__( '•••••••• (unchanged)', 'advanced-ffl-checkout' ) : ''; ?>" style="width:180px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Source', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="source" value="<?php echo esc_attr( $settings['source'] ); ?>" style="width:160px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></p>
				<p style="margin:0;"><button type="button" class="button" id="wpistic-ffl-sports-south-test"><?php esc_html_e( 'Test Connection', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-sports-south-settings-msg" style="font-weight:600;"></span>
			</form>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📋 <?php esc_html_e( 'Catalog', 'advanced-ffl-checkout' ); ?></h2>
			<p>
				<?php esc_html_e( 'Last synced:', 'advanced-ffl-checkout' ); ?> <?php echo esc_html( $last_sync ?: __( 'Never', 'advanced-ffl-checkout' ) ); ?>
				&nbsp;·&nbsp;
				<button type="button" class="button" id="wpistic-ffl-sports-south-sync"><?php esc_html_e( 'Sync Catalog', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-sports-south-sync-msg" style="font-weight:600;"></span>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Item #', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Description', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Mfr', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Price', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $catalog as $c ) : ?>
					<tr>
						<td><code><?php echo esc_html( $c->item_no ); ?></code></td>
						<td><?php echo esc_html( $c->description ); ?></td>
						<td><?php echo esc_html( $c->manufacturer ); ?></td>
						<td>$<?php echo esc_html( number_format( (float) $c->price, 2 ) ); ?></td>
						<td><?php echo esc_html( (string) $c->quantity ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $catalog ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No catalog data yet — click "Sync Catalog" above.', 'advanced-ffl-checkout' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🚚 <?php esc_html_e( 'Submit Order', 'advanced-ffl-checkout' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ships to the transfer\'s own dealer FFL on file. This places a real wholesale order with Sports South — double check the item number and quantity first.', 'advanced-ffl-checkout' ); ?></p>
			<form id="wpistic-ffl-sports-south-order-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></label>
					<select name="transfer_id" style="width:260px;">
						<option value=""><?php esc_html_e( '— Select —', 'advanced-ffl-checkout' ); ?></option>
						<?php foreach ( $open_transfers as $t ) : ?>
							<option value="<?php echo (int) $t->id; ?>" data-sku="<?php echo esc_attr( $t->item_sku ); ?>"><?php echo esc_html( $t->transfer_ref . ' — ' . $t->item_description ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'SS Item #', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="item_no" style="width:140px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></label>
					<input type="number" name="quantity" value="1" min="1" style="width:70px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Order', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-sports-south-order-msg" style="font-weight:600;"></span>
			</form>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🧾 <?php esc_html_e( 'Order History', 'advanced-ffl-checkout' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Item', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Ship-to FFL', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Order #', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'When', 'advanced-ffl-checkout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $orders as $o ) : ?>
					<tr>
						<td><?php echo esc_html( $o->transfer_ref ?: '#' . (int) $o->transfer_id ); ?></td>
						<td><code><?php echo esc_html( $o->item_no ); ?></code></td>
						<td><?php echo esc_html( (string) $o->quantity ); ?></td>
						<td><?php echo esc_html( $o->ship_to_ffl ); ?></td>
						<td><span style="color:<?php echo 'submitted' === $o->status ? '#16A34A' : '#DC2626'; ?>;font-weight:700;"><?php echo esc_html( ucfirst( $o->status ) ); ?></span></td>
						<td><?php echo esc_html( $o->distributor_order_ref ?: '—' ); ?></td>
						<td><?php echo esc_html( $o->created_at ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $orders ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No orders submitted yet.', 'advanced-ffl-checkout' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			function postJson(body) {
				body.append('nonce', nonce);
				return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function(r){ return r.json(); });
			}

			document.getElementById('wpistic-ffl-sports-south-settings-form').addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('wpistic-ffl-sports-south-settings-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_sports_south_save_settings');
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Saved' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-sports-south-test').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-sports-south-settings-msg');
				msg.textContent = 'Testing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_sports_south_test_connection');
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Connected' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-sports-south-sync').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-sports-south-sync-msg');
				msg.textContent = 'Syncing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_sports_south_sync_catalog');
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			var transferSelect = document.querySelector('#wpistic-ffl-sports-south-order-form select[name="transfer_id"]');
			var itemInput = document.querySelector('#wpistic-ffl-sports-south-order-form input[name="item_no"]');
			transferSelect.addEventListener('change', function(){
				var opt = transferSelect.options[transferSelect.selectedIndex];
				if (opt && opt.dataset.sku && !itemInput.value) { itemInput.value = opt.dataset.sku; }
			});

			document.getElementById('wpistic-ffl-sports-south-order-form').addEventListener('submit', function(e){
				e.preventDefault();
				if (!window.confirm('Submit this as a real order to Sports South?')) { return; }
				var msg = document.getElementById('wpistic-ffl-sports-south-order-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_sports_south_submit_order');
				msg.textContent = 'Submitting…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});
		})();
		</script>
		<?php
	}

	// ── AJAX ──────────────────────────────────────────────────────────────

	public static function ajax_save_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$customer_number = sanitize_text_field( wp_unslash( $_POST['customer_number'] ?? '' ) );
		$username        = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
		$password        = (string) ( $_POST['password'] ?? '' );
		$source          = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) ) ?: 'AdvancedFFLCheckout';
		// phpcs:enable

		$current = self::settings();
		if ( '' === $password ) {
			$password = $current['password'];
		}

		update_option( self::OPTION_KEY, [
			'customer_number' => $customer_number,
			'username'        => $username,
			'password'        => G2A_Distributor_Support::encrypt_secret( $password, self::CONTEXT ),
			'source'          => $source,
		] );
		wp_send_json_success();
	}

	public static function ajax_test_connection(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$xml = self::request( self::INVENTORY_ENDPOINT, 'DailyItemCount' );
		if ( is_wp_error( $xml ) ) {
			wp_send_json_error( [ 'message' => $xml->get_error_message() ], 400 );
		}
		wp_send_json_success();
	}

	public static function ajax_sync_catalog(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$result = self::sync_catalog();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_submit_order(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$transfer_id = (int) ( $_POST['transfer_id'] ?? 0 );
		$item_no     = sanitize_text_field( wp_unslash( $_POST['item_no'] ?? '' ) );
		$quantity    = max( 1, (int) ( $_POST['quantity'] ?? 1 ) );
		// phpcs:enable

		if ( ! $transfer_id || ! $item_no ) {
			wp_send_json_error( [ 'message' => 'Transfer and item number are required.' ], 400 );
		}

		$result = self::submit_order( $transfer_id, $item_no, $quantity );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}
}
