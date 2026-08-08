<?php
/**
 * G2A — Bill Hicks & Co. distributor integration.
 *
 * Like RSR Group, Bill Hicks has no REST/SOAP API -- catalog and order
 * exchange are both plain FTP file transfers. Confirmed by triangulating
 * three independent, unrelated open-source codebases (github.com/
 * ammoready/bill_hicks -- MIT-licensed Ruby gem, the most complete real
 * reference; github.com/NArun412/coreware's class.billhicks.php; and
 * github.com/mb03minecrafter/ffl-hub's BillHicks module) -- but, unlike
 * RSR, none of this was ever officially published by Bill Hicks itself,
 * and the three sources don't fully agree on current filenames. Treat
 * this integration as confirmed-by-triangulation, not confirmed-by-
 * primary-source -- verify current filenames/paths with your own Bill
 * Hicks dropship account before relying on a sync or an order.
 *
 * Confirmed:
 *   - Host billhicksco.hostedftp.com, plain FTP (not FTPS), port 21.
 *   - Catalog: a header-row CSV (parsed by column name, not position,
 *     since the exact column order was never independently verified --
 *     confirmed fields include universal_product_code (UPC), product_name,
 *     product_price, product_weight, category_description, marp (MAP
 *     price)) plus a separate minimal inventory CSV (product, qty_avail).
 *   - Orders: a tab-delimited flat file, header row
 *     HL;Customer#;Ship to#;Ship to Name;Address;City;State;Zip;
 *     cust po;ship method;notes;FFL#, followed by an LL items block
 *     (Item;Description;Qty;Price). Filename
 *     `{purchase_order}-order.txt`, uploaded to a `toBHC` directory.
 *     Drop-ship must be manually provisioned by Bill Hicks per dealer --
 *     it is not self-serve like Lipsey's.
 *   - Bill Hicks returns EDI-style acknowledgement/ship-notice files in a
 *     `fromBHC` directory -- their existence is confirmed, but the exact
 *     filename/field convention for telling an acceptance from a
 *     rejection was NOT independently confirmed in this session. This
 *     class therefore only lists what landed in fromBHC for a human to
 *     read -- it does not auto-classify order status the way RSR's
 *     ECONF/EERR check does, because doing so here would mean guessing a
 *     convention this plugin never actually confirmed.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Bill_Hicks {

	const OPTION_KEY = 'wpistic_ffl_bill_hicks_settings';
	const CONTEXT    = 'bill-hicks';

	public function __construct() {
		add_action( 'wp_ajax_wpistic_ffl_bill_hicks_save_settings',    [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_bill_hicks_test_connection',  [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_wpistic_ffl_bill_hicks_sync_catalog',     [ __CLASS__, 'ajax_sync_catalog' ] );
		add_action( 'wp_ajax_wpistic_ffl_bill_hicks_submit_order',     [ __CLASS__, 'ajax_submit_order' ] );
		add_action( 'wp_ajax_wpistic_ffl_bill_hicks_check_responses',  [ __CLASS__, 'ajax_check_responses' ] );
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public static function settings(): array {
		$settings = wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), [
			'username'                => '',
			'password'                => '',
			'customer_number'         => '',
			'dropship_customer_number'=> '',
			'ftp_host'                => 'billhicksco.hostedftp.com',
			'ftp_port'                => 21,
			'catalog_file'            => 'billhickscatalog.csv',
			'inventory_file'          => 'billhicksinventory.csv',
			'order_dir'               => 'toBHC',
			'response_dir'            => 'fromBHC',
		] );
		return G2A_Distributor_Support::decrypt_settings_secrets( $settings, [ 'password' ], self::CONTEXT, self::OPTION_KEY );
	}

	public static function is_configured(): bool {
		$s = self::settings();
		return '' !== $s['username'] && '' !== $s['password'] && '' !== $s['customer_number'];
	}

	// ── FTP connection (thin wrapper -- not unit-tested, see class docblock) ──

	/** @return resource|\WP_Error FTP stream, or WP_Error. */
	private static function connect() {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'bill_hicks_not_configured', __( 'Bill Hicks & Co. FTP credentials are not configured.', 'advanced-ffl-checkout' ) );
		}
		if ( ! function_exists( 'ftp_connect' ) ) {
			return new \WP_Error( 'bill_hicks_no_ftp_extension', __( 'The PHP FTP extension is not available on this server.', 'advanced-ffl-checkout' ) );
		}
		$s    = self::settings();
		$conn = @ftp_connect( $s['ftp_host'], (int) $s['ftp_port'], 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $conn ) {
			return new \WP_Error( 'bill_hicks_unreachable', __( 'Could not reach Bill Hicks & Co.\'s FTP server.', 'advanced-ffl-checkout' ) );
		}
		if ( ! @ftp_login( $conn, $s['username'], $s['password'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			ftp_close( $conn );
			return new \WP_Error( 'bill_hicks_login_failed', __( 'Bill Hicks & Co. FTP login failed -- check the username/password.', 'advanced-ffl-checkout' ) );
		}
		ftp_pasv( $conn, true );
		return $conn;
	}

	// ── Catalog sync ──────────────────────────────────────────────────────

	/** @return array{total:int,imported:int,skipped:int}|\WP_Error */
	public static function sync_catalog(): array|\WP_Error {
		$conn = self::connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$s = self::settings();

		$tmp = tmpfile();
		if ( ! $tmp || ! @ftp_fget( $conn, $tmp, $s['catalog_file'], FTP_ASCII ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			ftp_close( $conn );
			if ( $tmp ) {
				fclose( $tmp );
			}
			return new \WP_Error( 'bill_hicks_catalog_fetch_failed', sprintf(
				/* translators: %s: remote filename */
				__( 'Could not download %s from Bill Hicks & Co.', 'advanced-ffl-checkout' ), $s['catalog_file']
			) );
		}

		// Inventory quantities live in a second, smaller file -- merge by
		// item identifier before upserting so a single sync captures both
		// price/description and current stock level.
		$qty_by_item = [];
		$inv_tmp     = tmpfile();
		if ( $inv_tmp && @ftp_fget( $conn, $inv_tmp, $s['inventory_file'], FTP_ASCII ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			rewind( $inv_tmp );
			$header = fgetcsv( $inv_tmp );
			if ( is_array( $header ) ) {
				while ( ( $row = fgetcsv( $inv_tmp ) ) !== false ) {
					$mapped = @array_combine( $header, $row ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( is_array( $mapped ) && isset( $mapped['product'] ) ) {
						$qty_by_item[ trim( (string) $mapped['product'] ) ] = (int) ( $mapped['qty_avail'] ?? 0 );
					}
				}
			}
		}
		if ( $inv_tmp ) {
			fclose( $inv_tmp );
		}
		ftp_close( $conn );

		rewind( $tmp );
		$header = fgetcsv( $tmp );
		if ( ! is_array( $header ) ) {
			fclose( $tmp );
			return new \WP_Error( 'bill_hicks_bad_catalog', __( 'Bill Hicks & Co. catalog file has no header row to parse.', 'advanced-ffl-checkout' ) );
		}

		$limit    = (int) apply_filters( 'wpistic_ffl_bill_hicks_catalog_sync_limit', 5000 );
		$total    = 0;
		$imported = 0;
		while ( ( $row = fgetcsv( $tmp ) ) !== false && $total < ( $limit + 1 ) ) {
			++$total;
			if ( $total > $limit ) {
				break;
			}
			$mapped = @array_combine( $header, $row ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! is_array( $mapped ) ) {
				continue;
			}
			$item = self::normalize_catalog_row( $mapped, $qty_by_item );
			if ( $item ) {
				G2A_Distributor_Support::upsert_catalog_item( 'bill_hicks', $item );
				++$imported;
			}
		}
		fclose( $tmp );

		update_option( 'wpistic_ffl_bill_hicks_last_sync', current_time( 'mysql' ), false );

		return [
			'total'    => $total,
			'imported' => $imported,
			'skipped'  => max( 0, $total - $imported ),
		];
	}

	/**
	 * Normalizes one already-header-mapped CSV row. Pure function -- no
	 * I/O -- directly unit testable against a sample row without a live
	 * FTP account.
	 */
	public static function normalize_catalog_row( array $row, array $qty_by_item = [] ): ?array {
		$item_no = trim( (string) ( $row['product'] ?? $row['universal_product_code'] ?? '' ) );
		if ( '' === $item_no ) {
			return null;
		}

		// A real catalog row always has the `marp` column, blank for the
		// many items with no manufacturer-mandated MAP price -- `??` alone
		// treats '' as present and would zero out the price for those, so
		// blank/whitespace-only values are treated the same as "absent."
		$marp = trim( (string) ( $row['marp'] ?? '' ) );
		$list_price = trim( (string) ( $row['product_price'] ?? '' ) );

		// The inventory file's own identifier scheme for `product` was
		// never independently confirmed against this catalog file's --
		// try every plausible key (this row's own `product_no`/item_no
		// value, then its UPC) before falling back to an inline qty_avail
		// column or 0, rather than assuming the first key always matches.
		$upc = (string) ( $row['universal_product_code'] ?? '' );
		$quantity = $qty_by_item[ $item_no ] ?? ( '' !== $upc ? ( $qty_by_item[ $upc ] ?? null ) : null ) ?? $row['qty_avail'] ?? 0;

		return [
			'item_no'      => $item_no,
			'description'  => (string) ( $row['product_name'] ?? '' ),
			'manufacturer' => (string) ( $row['manufacturer'] ?? $row['category_description'] ?? '' ),
			'upc'          => $upc,
			'price'        => (float) ( '' !== $marp ? $marp : $list_price ),
			'quantity'     => (int) $quantity,
			'is_firearm'   => (bool) apply_filters( 'wpistic_ffl_bill_hicks_is_firearm', false !== stripos( (string) ( $row['category_description'] ?? '' ), 'firearm' )
				|| false !== stripos( (string) ( $row['category_description'] ?? '' ), 'pistol' )
				|| false !== stripos( (string) ( $row['category_description'] ?? '' ), 'rifle' )
				|| false !== stripos( (string) ( $row['category_description'] ?? '' ), 'shotgun' ), $row ),
		];
	}

	// ── Order submission (explicit admin action only) ───────────────────

	/** @return array|\WP_Error */
	public static function submit_order( int $transfer_id, string $item_no, string $description, int $quantity, float $price = 0.0 ) {
		$t = G2A_Distributor_Support::transfer_with_dealer( $transfer_id );
		if ( ! $t ) {
			return new \WP_Error( 'bill_hicks_no_transfer', __( 'Transfer not found.', 'advanced-ffl-checkout' ) );
		}
		if ( ! $t->license_number ) {
			return new \WP_Error( 'bill_hicks_no_dealer_ffl', __( 'This transfer has no dealer FFL on file to ship to.', 'advanced-ffl-checkout' ) );
		}

		$conn = self::connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$s = self::settings();

		// The order filename is derived from the transfer ref alone, which
		// stays the same across a resubmission for the same transfer --
		// without a per-attempt discriminator, a second submission would
		// silently overwrite the first upload at the same remote path
		// before Bill Hicks ever picks it up. Count this transfer's prior
		// attempts so each submission gets its own filename.
		global $wpdb;
		$attempt = 1 + (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT COUNT(*) FROM ' . DB::table( 'distributor_orders' ) . ' WHERE distributor = %s AND transfer_id = %d',
			'bill_hicks', $transfer_id
		) );

		$po_number = $t->transfer_ref . '-' . $attempt;
		$built     = self::build_order_file( $t, $item_no, $description, $quantity, $price, (string) $s['customer_number'], (string) $s['dropship_customer_number'], $attempt );
		$filename  = $built['filename'];

		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, $built['contents'] );
		rewind( $stream );

		$remote_path = rtrim( (string) $s['order_dir'], '/' ) . '/' . $filename;
		$ok          = @ftp_fput( $conn, $remote_path, $stream, FTP_ASCII ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		fclose( $stream );
		ftp_close( $conn );

		if ( ! $ok ) {
			G2A_Distributor_Support::record_distributor_order( 'bill_hicks', [
				'transfer_id' => $transfer_id, 'po_number' => $po_number, 'item_no' => $item_no,
				'quantity'    => $quantity, 'ship_to_ffl' => $t->license_number, 'status' => 'failed',
				'note'        => 'Bill Hicks & Co. order file upload failed (FTP STOR of ' . $filename . ').',
			] );
			return new \WP_Error( 'bill_hicks_upload_failed', __( 'Uploading the order file to Bill Hicks & Co. failed.', 'advanced-ffl-checkout' ) );
		}

		G2A_Distributor_Support::record_distributor_order( 'bill_hicks', [
			'transfer_id'           => $transfer_id,
			'po_number'             => $po_number,
			'item_no'               => $item_no,
			'quantity'              => $quantity,
			'ship_to_ffl'           => $t->license_number,
			'status'                => 'submitted',
			'distributor_order_ref' => $filename,
			'note'                  => sprintf( 'Bill Hicks & Co. order file %s uploaded; check %s for an acknowledgement.', $filename, $s['response_dir'] ),
		] );

		return [ 'status' => 'submitted', 'ref' => $filename ];
	}

	/**
	 * Builds the tab-delimited order file: an HL header line and one LL
	 * item line per line item (this plugin only ever submits one line at
	 * a time, matching the "one explicit admin click per item" pattern
	 * used by every other distributor client). Pure function -- no I/O.
	 *
	 * $attempt (1-based) is appended to both the PO number and filename so
	 * a resubmission for the same transfer never overwrites a prior
	 * upload -- see the caller's comment in submit_order().
	 *
	 * @return array{filename:string,contents:string}
	 */
	public static function build_order_file( object $t, string $item_no, string $description, int $quantity, float $price, string $customer_number, string $dropship_customer_number, int $attempt = 1 ): array {
		$po_number = $t->transfer_ref . '-' . max( 1, $attempt );
		$ship_to   = '' !== $dropship_customer_number ? $dropship_customer_number : $customer_number;
		$header    = implode( "\t", [
			'HL', $customer_number, $ship_to, (string) $t->business_name,
			(string) $t->premise_street, (string) $t->premise_city, (string) $t->premise_state, (string) $t->premise_zip,
			$po_number, 'Ground', '', (string) $t->license_number,
		] );
		$item = implode( "\t", [ 'LL', $item_no, $description, (string) $quantity, number_format( $price, 2, '.', '' ) ] );

		return [
			'filename' => $po_number . '-order.txt',
			'contents' => $header . "\r\n" . $item . "\r\n",
		];
	}

	// ── Response listing (does NOT auto-classify -- see class docblock) ──

	/**
	 * Lists whatever landed in the response directory since order
	 * submission -- for a human to open and cross-reference, not an
	 * automatic status transition. See class docblock for why: this
	 * plugin never confirmed Bill Hicks's ack/reject file-naming
	 * convention closely enough to automate that call honestly.
	 *
	 * @return array{files:string[]}|\WP_Error
	 */
	public static function check_responses(): array|\WP_Error {
		$conn = self::connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$s    = self::settings();
		$list = @ftp_nlist( $conn, rtrim( (string) $s['response_dir'], '/' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		ftp_close( $conn );

		if ( false === $list ) {
			return new \WP_Error( 'bill_hicks_response_dir_unreadable', __( 'Could not list the Bill Hicks & Co. response directory -- check the Response Dir setting and that this FTP account has permission to list it.', 'advanced-ffl-checkout' ) );
		}

		return [ 'files' => array_map( 'basename', $list ) ];
	}

	// ── Admin panel (rendered inside the shared "📦 Distributors" page — see G2A_Distributors_Admin) ──

	public static function render_panel(): void {
		global $wpdb;
		$nonce     = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$settings  = self::settings();
		$last_sync = get_option( 'wpistic_ffl_bill_hicks_last_sync', '' );

		$catalog = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'distributor_products' ) . " WHERE distributor = 'bill_hicks' ORDER BY last_synced DESC LIMIT 50"
		);
		$orders = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT o.*, t.transfer_ref FROM ' . DB::table( 'distributor_orders' ) . ' o
			 LEFT JOIN ' . DB::table( 'transfers' ) . " t ON t.id = o.transfer_id
			 WHERE o.distributor = 'bill_hicks' ORDER BY o.created_at DESC LIMIT 50"
		);
		$open_transfers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, transfer_ref, item_sku, item_description FROM ' . DB::table( 'transfers' ) . "
			 WHERE status NOT IN ('transferred','cancelled') ORDER BY created_at DESC LIMIT 100"
		);
		?>
		<p class="description" style="max-width:780px;">
			<?php esc_html_e( 'FTP file exchange (billhicksco.hostedftp.com) — no REST/SOAP API, and drop-ship must be manually enabled by Bill Hicks per dealer before it will work. This integration is confirmed by triangulating three independent open-source implementations, not a Bill Hicks developer portal — verify current filenames with your own account before relying on it. Order acknowledgements land in the response directory as raw files; this plugin lists them for a human to read rather than auto-classifying accept/reject, since that exact convention was never independently confirmed.', 'advanced-ffl-checkout' ); ?>
		</p>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚙️ <?php esc_html_e( 'FTP Credentials', 'advanced-ffl-checkout' ); ?></h2>
			<form id="wpistic-ffl-bill-hicks-settings-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Username', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="username" value="<?php echo esc_attr( $settings['username'] ); ?>" style="width:150px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Password', 'advanced-ffl-checkout' ); ?></label>
					<input type="password" name="password" placeholder="<?php echo $settings['password'] ? esc_attr__( '•••••••• (unchanged)', 'advanced-ffl-checkout' ) : ''; ?>" style="width:160px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Customer #', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="customer_number" value="<?php echo esc_attr( $settings['customer_number'] ); ?>" style="width:110px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Dropship Customer #', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="dropship_customer_number" value="<?php echo esc_attr( $settings['dropship_customer_number'] ); ?>" placeholder="(same as above)" style="width:150px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></p>
				<p style="margin:0;"><button type="button" class="button" id="wpistic-ffl-bill-hicks-test"><?php esc_html_e( 'Test Connection', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-bill-hicks-settings-msg" style="font-weight:600;"></span>
			</form>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📋 <?php esc_html_e( 'Catalog', 'advanced-ffl-checkout' ); ?></h2>
			<p>
				<?php esc_html_e( 'Last synced:', 'advanced-ffl-checkout' ); ?> <?php echo esc_html( $last_sync ?: __( 'Never', 'advanced-ffl-checkout' ) ); ?>
				&nbsp;·&nbsp;
				<button type="button" class="button" id="wpistic-ffl-bill-hicks-sync"><?php esc_html_e( 'Sync Catalog', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-bill-hicks-sync-msg" style="font-weight:600;"></span>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Item #', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Description', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Category', 'advanced-ffl-checkout' ); ?></th>
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
			<p class="description"><?php esc_html_e( 'Ships to the transfer\'s own dealer FFL on file. This uploads a real order file to Bill Hicks & Co. — double check the item number, description, and price first.', 'advanced-ffl-checkout' ); ?></p>
			<form id="wpistic-ffl-bill-hicks-order-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></label>
					<select name="transfer_id" style="width:220px;">
						<option value=""><?php esc_html_e( '— Select —', 'advanced-ffl-checkout' ); ?></option>
						<?php foreach ( $open_transfers as $t ) : ?>
							<option value="<?php echo (int) $t->id; ?>" data-sku="<?php echo esc_attr( $t->item_sku ); ?>" data-desc="<?php echo esc_attr( $t->item_description ); ?>"><?php echo esc_html( $t->transfer_ref . ' — ' . $t->item_description ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Item #', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="item_no" style="width:120px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Description', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="description" style="width:200px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></label>
					<input type="number" name="quantity" value="1" min="1" style="width:70px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Price', 'advanced-ffl-checkout' ); ?></label>
					<input type="number" name="price" step="0.01" min="0" style="width:90px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Order', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-bill-hicks-order-msg" style="font-weight:600;"></span>
			</form>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🧾 <?php esc_html_e( 'Order History', 'advanced-ffl-checkout' ); ?></h2>
			<p>
				<button type="button" class="button" id="wpistic-ffl-bill-hicks-check-responses"><?php esc_html_e( 'List Response Files', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-bill-hicks-responses-msg" style="font-weight:600;"></span>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Item', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Ship-to FFL', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Order File', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'When', 'advanced-ffl-checkout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $orders as $o ) : ?>
					<tr>
						<td><?php echo esc_html( $o->transfer_ref ?: '#' . (int) $o->transfer_id ); ?></td>
						<td><code><?php echo esc_html( $o->item_no ); ?></code></td>
						<td><?php echo esc_html( (string) $o->quantity ); ?></td>
						<td><?php echo esc_html( $o->ship_to_ffl ); ?></td>
						<?php
					// Bill Hicks orders never get auto-classified (see class
					// docblock) -- 'submitted' only ever means "uploaded,"
					// never "accepted," so it's shown amber rather than the
					// green a genuinely confirmed order would get elsewhere.
					$bh_color = 'submitted' === $o->status ? '#D97706' : '#DC2626';
					?>
					<td><span style="color:<?php echo esc_attr( $bh_color ); ?>;font-weight:700;"><?php echo esc_html( ucfirst( $o->status ) ); ?></span></td>
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

			document.getElementById('wpistic-ffl-bill-hicks-settings-form').addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('wpistic-ffl-bill-hicks-settings-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_bill_hicks_save_settings');
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Saved' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-bill-hicks-test').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-bill-hicks-settings-msg');
				msg.textContent = 'Testing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_bill_hicks_test_connection');
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Connected' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-bill-hicks-sync').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-bill-hicks-sync-msg');
				msg.textContent = 'Syncing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_bill_hicks_sync_catalog');
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			var transferSelect = document.querySelector('#wpistic-ffl-bill-hicks-order-form select[name="transfer_id"]');
			var itemInput = document.querySelector('#wpistic-ffl-bill-hicks-order-form input[name="item_no"]');
			var descInput = document.querySelector('#wpistic-ffl-bill-hicks-order-form input[name="description"]');
			transferSelect.addEventListener('change', function(){
				var opt = transferSelect.options[transferSelect.selectedIndex];
				if (opt && opt.dataset.sku && !itemInput.value) { itemInput.value = opt.dataset.sku; }
				if (opt && opt.dataset.desc && !descInput.value) { descInput.value = opt.dataset.desc; }
			});

			document.getElementById('wpistic-ffl-bill-hicks-order-form').addEventListener('submit', function(e){
				e.preventDefault();
				if (!window.confirm('Upload this as a real order file to Bill Hicks & Co.?')) { return; }
				var msg = document.getElementById('wpistic-ffl-bill-hicks-order-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_bill_hicks_submit_order');
				msg.textContent = 'Submitting…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			document.getElementById('wpistic-ffl-bill-hicks-check-responses').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-bill-hicks-responses-msg');
				msg.textContent = 'Checking…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_bill_hicks_check_responses');
				postJson(fd).then(function(j){
					if (j.success) {
						msg.style.color = '#16A34A';
						msg.textContent = (j.data.files || []).length ? '✓ ' + j.data.files.join(', ') : '✓ Nothing in the response directory yet.';
					} else {
						msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed');
					}
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
		$username                  = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
		$password                  = (string) ( $_POST['password'] ?? '' );
		$customer_number           = sanitize_text_field( wp_unslash( $_POST['customer_number'] ?? '' ) );
		$dropship_customer_number  = sanitize_text_field( wp_unslash( $_POST['dropship_customer_number'] ?? '' ) );
		// phpcs:enable

		$current = self::settings();
		if ( '' === $password ) {
			$password = $current['password'];
		}

		update_option( self::OPTION_KEY, array_merge( $current, [
			'username'                 => $username,
			'password'                 => G2A_Distributor_Support::encrypt_secret( $password, self::CONTEXT ),
			'customer_number'          => $customer_number,
			'dropship_customer_number' => $dropship_customer_number,
		] ) );
		wp_send_json_success();
	}

	public static function ajax_test_connection(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$conn = self::connect();
		if ( is_wp_error( $conn ) ) {
			wp_send_json_error( [ 'message' => $conn->get_error_message() ], 400 );
		}
		ftp_close( $conn );
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
		$description = sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) );
		$quantity    = max( 1, (int) ( $_POST['quantity'] ?? 1 ) );
		$price       = (float) ( $_POST['price'] ?? 0 );
		// phpcs:enable

		if ( ! $transfer_id || ! $item_no ) {
			wp_send_json_error( [ 'message' => 'Transfer and item number are required.' ], 400 );
		}

		$result = self::submit_order( $transfer_id, $item_no, $description, $quantity, $price );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_check_responses(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$result = self::check_responses();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( $result );
	}
}
