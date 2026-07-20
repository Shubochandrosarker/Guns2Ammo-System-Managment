<?php
/**
 * G2A — RSR Group distributor integration.
 *
 * RSR has no REST/SOAP API; both catalog and order exchange are real,
 * confirmed FTPS file transfers -- verified against the actual source of
 * the open-source `rsr_group` Ruby gem (github.com/ammoready/rsr_group),
 * fetched line-by-line in this session, not guessed or inferred from
 * marketing copy.
 *
 * Confirmed:
 *   - Host ftps.rsrgroup.com:2222, explicit FTPS (AUTH TLS), passive mode.
 *   - Catalog: download the semicolon-delimited `fulfillment-inv-new.txt`
 *     (the dropship-eligible subset of RSR's inventory -- there's also a
 *     `rsrinventory-new.txt` for the full dealer catalog, not used here
 *     since this plugin only ever drop-ships to the transfer's own FFL).
 *     Field order confirmed from RSR's own published field-layout doc,
 *     mirrored by github.com/codearachnid/woocommerce-import-rsrgroup:
 *     stock#, UPC, description, department#, mfr ID, retail price,
 *     RSR price, weight (oz), qty, model, mfr name, mfr part#,
 *     allocated/closeout/deleted flag, expanded description, image name,
 *     followed by 51 per-state restriction columns (unused here).
 *   - Orders: a fixed-format flat file, line types confirmed from
 *     rsr_group's own constants.rb (LINE_TYPES): '00' file header,
 *     '10' order header (ship-to), '11' FFL dealer (optional),
 *     '20' order detail (one per item), '90' order trailer,
 *     '99' file trailer. Filename `EORD-{merchant#}-{YYYYMMDD}-{seq}.txt`,
 *     uploaded via FTP STOR to the account's configured submission
 *     directory (RSR provisions this per-account -- there is no universal
 *     default, so it's a required setting here, not guessed).
 *   - RSR deposits asynchronous ECONF (confirmed)/EERR (rejected)/ESHIP
 *     (shipped) response files into a separate response directory --
 *     order status is NOT known at submission time, only after polling.
 *
 * What's NOT independently verified in this session: the exact field
 * layout of RSR's own response files (ECONF/EERR/ESHIP) -- their
 * existence and naming convention is confirmed, but this class parses
 * them permissively (looks for our own PO number inside the file, treats
 * an ECONF-prefixed name as success and EERR-prefixed as failure) rather
 * than asserting a specific field-by-field format that was never fetched.
 * Verify against a real dealer/dropship account before trusting this with
 * a real order -- same posture as every other un-smoke-tested integration
 * in this plugin.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Rsr {

	const OPTION_KEY   = 'wpistic_ffl_rsr_settings';
	const CONTEXT      = 'rsr';
	const SEQUENCE_OPT = 'wpistic_ffl_rsr_next_sequence';
	const CRON_HOOK    = 'wpistic_ffl_rsr_check_responses';

	public function __construct() {
		add_action( 'wp_ajax_wpistic_ffl_rsr_save_settings',    [ __CLASS__, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_rsr_test_connection',  [ __CLASS__, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_wpistic_ffl_rsr_sync_catalog',     [ __CLASS__, 'ajax_sync_catalog' ] );
		add_action( 'wp_ajax_wpistic_ffl_rsr_submit_order',     [ __CLASS__, 'ajax_submit_order' ] );
		add_action( 'wp_ajax_wpistic_ffl_rsr_check_responses',  [ __CLASS__, 'ajax_check_responses' ] );

		// Daily poll so a "submitted" order doesn't sit un-confirmed forever
		// just because nobody clicked "Check Responses" -- matches what
		// check_responses()'s own docblock already promised ("an admin (or
		// the daily cron)"), which had no actual cron wired up until now.
		add_action( self::CRON_HOOK, [ __CLASS__, 'cron_check_responses' ] );
		if ( self::is_configured() ) {
			G2A_Scheduler::recurring( DAY_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/** Cron entry point -- same call as the admin "Check Responses" button, errors just logged rather than surfaced to a user. */
	public static function cron_check_responses(): void {
		$result = self::check_responses();
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Advanced FFL Checkout] RSR daily check_responses() failed: ' . $result->get_error_message() );
		}
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public static function settings(): array {
		$settings = wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), [
			'username'        => '',
			'password'        => '',
			'merchant_number' => '',
			'ftp_host'        => 'ftps.rsrgroup.com',
			'ftp_port'        => 2222,
			'submission_dir'  => '',
			'response_dir'    => '',
			'catalog_file'    => 'fulfillment-inv-new.txt',
		] );
		return G2A_Distributor_Support::decrypt_settings_secrets( $settings, [ 'password' ], self::CONTEXT, self::OPTION_KEY );
	}

	public static function is_configured(): bool {
		$s = self::settings();
		return '' !== $s['username'] && '' !== $s['password'] && '' !== $s['merchant_number'];
	}

	// ── FTPS connection (thin wrapper -- not unit-tested, see class docblock) ──

	/** @return resource|\WP_Error FTP stream, or WP_Error. */
	private static function connect() {
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'rsr_not_configured', __( 'RSR Group FTP credentials are not configured.', 'advanced-ffl-checkout' ) );
		}
		if ( ! function_exists( 'ftp_ssl_connect' ) ) {
			return new \WP_Error( 'rsr_no_ftp_extension', __( 'The PHP FTP extension (with SSL support) is not available on this server.', 'advanced-ffl-checkout' ) );
		}
		$s    = self::settings();
		$conn = @ftp_ssl_connect( $s['ftp_host'], (int) $s['ftp_port'], 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $conn ) {
			return new \WP_Error( 'rsr_unreachable', __( 'Could not reach RSR Group\'s FTPS server.', 'advanced-ffl-checkout' ) );
		}
		if ( ! @ftp_login( $conn, $s['username'], $s['password'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			ftp_close( $conn );
			return new \WP_Error( 'rsr_login_failed', __( 'RSR Group FTP login failed -- check the username/password.', 'advanced-ffl-checkout' ) );
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
			return new \WP_Error( 'rsr_catalog_fetch_failed', sprintf(
				/* translators: %s: remote filename */
				__( 'Could not download %s from RSR Group.', 'advanced-ffl-checkout' ), $s['catalog_file']
			) );
		}
		ftp_close( $conn );

		rewind( $tmp );
		$limit    = (int) apply_filters( 'wpistic_ffl_rsr_catalog_sync_limit', 5000 );
		$total    = 0;
		$imported = 0;
		while ( ( $line = fgets( $tmp ) ) !== false && $total < ( $limit + 1 ) ) {
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			++$total;
			if ( $total > $limit ) {
				break;
			}
			$item = self::parse_catalog_line( $line );
			if ( $item ) {
				G2A_Distributor_Support::upsert_catalog_item( 'rsr', $item );
				++$imported;
			}
		}
		fclose( $tmp );

		update_option( 'wpistic_ffl_rsr_last_sync', current_time( 'mysql' ), false );

		return [
			'total'    => $total,
			'imported' => $imported,
			'skipped'  => max( 0, $total - $imported ),
		];
	}

	/**
	 * Parses one semicolon-delimited RSR inventory line into the normalized
	 * catalog-item shape. Pure function -- no I/O -- so it's directly unit
	 * testable against a real sample line without a live FTP account.
	 */
	public static function parse_catalog_line( string $line ): ?array {
		$f = explode( ';', $line );
		if ( count( $f ) < 15 || '' === trim( $f[0] ) ) {
			return null;
		}
		return [
			'item_no'      => trim( $f[0] ),
			'upc'          => trim( $f[1] ),
			'description'  => trim( $f[2] ),
			'manufacturer' => trim( $f[10] ), // Full Manufacturer Name
			'model'        => trim( $f[9] ),
			'price'        => (float) trim( $f[6] ), // RSR Regular Price (dealer cost)
			'quantity'     => (int) trim( $f[8] ),
			'is_firearm'   => (bool) apply_filters( 'wpistic_ffl_rsr_is_firearm', in_array( trim( $f[3] ), [ '1', '2', '3', '5', '6', '7', '8', '9', '10', '11', '13', '14', '18', '20', '21', '24', '26', '30', '35', '41', '42', '43' ], true ), $f ),
		];
	}

	// ── Order submission (explicit admin action only) ───────────────────

	/** @return array|\WP_Error */
	public static function submit_order( int $transfer_id, string $item_no, int $quantity, string $carrier = 'UPS', string $method = 'GROUND' ) {
		$t = G2A_Distributor_Support::transfer_with_dealer( $transfer_id );
		if ( ! $t ) {
			return new \WP_Error( 'rsr_no_transfer', __( 'Transfer not found.', 'advanced-ffl-checkout' ) );
		}
		if ( ! $t->license_number ) {
			return new \WP_Error( 'rsr_no_dealer_ffl', __( 'This transfer has no dealer FFL on file to ship to.', 'advanced-ffl-checkout' ) );
		}

		$conn = self::connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$s        = self::settings();
		$sequence = (int) get_option( self::SEQUENCE_OPT, 1 );

		$built    = self::build_order_file( $t, $item_no, $quantity, $carrier, $method, (string) $s['merchant_number'], $sequence );
		$filename = $built['filename'];
		$contents = $built['contents'];

		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, $contents );
		rewind( $stream );

		$remote_path = ( '' !== $s['submission_dir'] ? rtrim( $s['submission_dir'], '/' ) . '/' : '' ) . $filename;
		$ok          = @ftp_fput( $conn, $remote_path, $stream, FTP_ASCII ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		fclose( $stream );
		ftp_close( $conn );

		if ( ! $ok ) {
			G2A_Distributor_Support::record_distributor_order( 'rsr', [
				'transfer_id' => $transfer_id, 'po_number' => $t->transfer_ref, 'item_no' => $item_no,
				'quantity'    => $quantity, 'ship_to_ffl' => $t->license_number, 'status' => 'failed',
				'note'        => 'RSR order file upload failed (FTP STOR of ' . $filename . ').',
			] );
			return new \WP_Error( 'rsr_upload_failed', __( 'Uploading the order file to RSR Group failed.', 'advanced-ffl-checkout' ) );
		}

		update_option( self::SEQUENCE_OPT, $sequence + 1, false );

		// RSR confirms/rejects asynchronously via a separate response file --
		// status stays 'submitted' (pending) until an admin (or the daily
		// cron) checks for a matching ECONF/EERR file. See check_responses().
		G2A_Distributor_Support::record_distributor_order( 'rsr', [
			'transfer_id'           => $transfer_id,
			'po_number'             => $t->transfer_ref,
			'item_no'               => $item_no,
			'quantity'              => $quantity,
			'ship_to_ffl'           => $t->license_number,
			'status'                => 'submitted',
			'distributor_order_ref' => $filename,
			'note'                  => sprintf( 'RSR order file %s uploaded; awaiting ECONF/EERR confirmation.', $filename ),
		] );

		return [ 'status' => 'submitted', 'ref' => $filename ];
	}

	/**
	 * Builds the exact RSR order file: file header ('00'), order/ship-to
	 * header ('10'), FFL dealer line ('11'), one order-detail line ('20')
	 * per item, order trailer ('90'), file trailer ('99'). Pure function --
	 * no I/O -- so the file format itself is unit testable independent of
	 * a live FTP account.
	 *
	 * @return array{filename:string,contents:string}
	 */
	public static function build_order_file( object $t, string $item_no, int $quantity, string $carrier, string $method, string $merchant_number, int $sequence ): array {
		$merchant_padded = str_pad( substr( $merchant_number, 0, 5 ), 5, '0', STR_PAD_LEFT );
		$sequence_padded = str_pad( (string) $sequence, 4, '0', STR_PAD_LEFT );
		$timestamp       = gmdate( 'Ymd' );
		$identifier      = substr( (string) $t->transfer_ref, 0, 20 );
		$phone           = preg_replace( '/[^0-9]/', '', (string) $t->dealer_phone ) ?: '';

		$lines   = [];
		$lines[] = implode( ';', [ 'FILEHEADER', '00', $merchant_padded, $timestamp, $sequence_padded ] );
		$lines[] = implode( ';', [
			$identifier, '10', (string) $t->business_name, '', (string) $t->premise_street, '',
			(string) $t->premise_city, (string) $t->premise_state, (string) $t->premise_zip,
			$phone, 'N', '', '', '',
		] );
		$lines[] = implode( ';', [
			$identifier, '11', (string) $t->license_number, (string) $t->business_name,
			(string) $t->premise_zip, (string) $t->customer_name, $phone,
		] );
		$lines[] = implode( ';', [ $identifier, '20', $item_no, (string) $quantity, $carrier, $method ] );
		$lines[] = implode( ';', [ $identifier, '90', str_pad( (string) $quantity, 7, '0', STR_PAD_LEFT ) ] );
		$lines[] = implode( ';', [ 'FILETRAILER', '99', '00001' ] );

		return [
			'filename' => "EORD-{$merchant_padded}-{$timestamp}-{$sequence_padded}.txt",
			'contents' => implode( "\r\n", $lines ) . "\r\n",
		];
	}

	// ── Response polling (confirm/reject a previously-submitted order) ──

	/**
	 * Scans this account's response directory for ECONF/EERR files whose
	 * content references one of our still-pending orders, and advances
	 * that order's status accordingly. Matches against either the order's
	 * own PO/transfer identifier (embedded in the order file's '10'/'11'/
	 * '20' lines) or the uploaded filename itself, since which one RSR's
	 * real response files actually echo back was never independently
	 * confirmed in this session -- see class docblock. Each response file
	 * is downloaded at most once regardless of how many orders are
	 * pending, not once per (order, file) pair.
	 *
	 * @return array{checked:int,confirmed:int,rejected:int}|\WP_Error
	 */
	public static function check_responses(): array|\WP_Error {
		global $wpdb;
		$pending = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, po_number, distributor_order_ref FROM ' . DB::table( 'distributor_orders' ) . "
			 WHERE distributor = %s AND status = 'submitted' AND distributor_order_ref != ''
			 ORDER BY created_at DESC LIMIT 100",
			'rsr'
		) );
		if ( ! $pending ) {
			return [ 'checked' => 0, 'confirmed' => 0, 'rejected' => 0 ];
		}

		$conn = self::connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		$s    = self::settings();
		$dir  = '' !== $s['response_dir'] ? rtrim( $s['response_dir'], '/' ) : '.';
		$list = @ftp_nlist( $conn, $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $list ) {
			ftp_close( $conn );
			return new \WP_Error( 'rsr_response_dir_unreadable', __( 'Could not list the RSR response directory -- check the Response Dir setting and that this FTP account has permission to list it.', 'advanced-ffl-checkout' ) );
		}

		// Download each ECONF/EERR file's content exactly once, then match
		// every pending order against that cache in a single pass, rather
		// than re-downloading the same handful of files once per order.
		$responses = [];
		foreach ( $list as $remote_file ) {
			$base = basename( $remote_file );
			if ( 0 !== stripos( $base, 'ECONF' ) && 0 !== stripos( $base, 'EERR' ) ) {
				continue;
			}
			$tmp = tmpfile();
			if ( ! $tmp || ! @ftp_fget( $conn, $tmp, $remote_file, FTP_ASCII ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $tmp ) {
					fclose( $tmp );
				}
				continue;
			}
			rewind( $tmp );
			$responses[] = [ 'base' => $base, 'content' => stream_get_contents( $tmp ) ];
			fclose( $tmp );
		}
		ftp_close( $conn );

		$confirmed = 0;
		$rejected  = 0;
		foreach ( $pending as $order ) {
			foreach ( $responses as $response ) {
				$matches = ( '' !== (string) $order->po_number && false !== strpos( $response['content'], (string) $order->po_number ) )
					|| ( '' !== (string) $order->distributor_order_ref && false !== strpos( $response['content'], (string) $order->distributor_order_ref ) );
				if ( ! $matches ) {
					continue;
				}

				$is_confirm = 0 === stripos( $response['base'], 'ECONF' );
				$wpdb->update( DB::table( 'distributor_orders' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					'status'        => $is_confirm ? 'confirmed' : 'rejected',
					'response_json' => wp_json_encode( $response ),
				], [ 'id' => (int) $order->id ] );
				$is_confirm ? ++$confirmed : ++$rejected;
				break;
			}
		}

		return [ 'checked' => count( $pending ), 'confirmed' => $confirmed, 'rejected' => $rejected ];
	}

	// ── Admin panel (rendered inside the shared "📦 Distributors" page — see G2A_Distributors_Admin) ──

	public static function render_panel(): void {
		global $wpdb;
		$nonce     = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$settings  = self::settings();
		$last_sync = get_option( 'wpistic_ffl_rsr_last_sync', '' );

		$catalog = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'distributor_products' ) . " WHERE distributor = 'rsr' ORDER BY last_synced DESC LIMIT 50"
		);
		$orders = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT o.*, t.transfer_ref FROM ' . DB::table( 'distributor_orders' ) . ' o
			 LEFT JOIN ' . DB::table( 'transfers' ) . " t ON t.id = o.transfer_id
			 WHERE o.distributor = 'rsr' ORDER BY o.created_at DESC LIMIT 50"
		);
		$open_transfers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, transfer_ref, item_sku, item_description FROM ' . DB::table( 'transfers' ) . "
			 WHERE status NOT IN ('transferred','cancelled') ORDER BY created_at DESC LIMIT 100"
		);
		?>
		<p class="description" style="max-width:760px;">
			<?php esc_html_e( 'FTPS file exchange (ftps.rsrgroup.com) — no REST/SOAP API. Catalog sync downloads the dropship-eligible inventory file; submitting an order uploads a fixed-format order file. RSR confirms or rejects an order asynchronously — use "Check Responses" to poll for an ECONF/EERR file before assuming it went through.', 'advanced-ffl-checkout' ); ?>
		</p>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚙️ <?php esc_html_e( 'FTP Credentials', 'advanced-ffl-checkout' ); ?></h2>
			<form id="wpistic-ffl-rsr-settings-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Username', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="username" value="<?php echo esc_attr( $settings['username'] ); ?>" style="width:150px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Password', 'advanced-ffl-checkout' ); ?></label>
					<input type="password" name="password" placeholder="<?php echo $settings['password'] ? esc_attr__( '•••••••• (unchanged)', 'advanced-ffl-checkout' ) : ''; ?>" style="width:160px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Merchant #', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="merchant_number" value="<?php echo esc_attr( $settings['merchant_number'] ); ?>" style="width:100px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'FTP Host', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="ftp_host" value="<?php echo esc_attr( $settings['ftp_host'] ); ?>" style="width:170px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'FTP Port', 'advanced-ffl-checkout' ); ?></label>
					<input type="number" name="ftp_port" value="<?php echo (int) $settings['ftp_port']; ?>" style="width:80px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Submission Dir', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="submission_dir" value="<?php echo esc_attr( $settings['submission_dir'] ); ?>" placeholder="(account root)" style="width:140px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Response Dir', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="response_dir" value="<?php echo esc_attr( $settings['response_dir'] ); ?>" placeholder="(account root)" style="width:140px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></p>
				<p style="margin:0;"><button type="button" class="button" id="wpistic-ffl-rsr-test"><?php esc_html_e( 'Test Connection', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-rsr-settings-msg" style="font-weight:600;"></span>
			</form>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'RSR provisions the submission/response directory names per account — there is no universal default. Leave blank to use your FTP login\'s root directory, or enter the subdirectory RSR gave you.', 'advanced-ffl-checkout' ); ?>
			</p>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📋 <?php esc_html_e( 'Catalog', 'advanced-ffl-checkout' ); ?></h2>
			<p>
				<?php esc_html_e( 'Last synced:', 'advanced-ffl-checkout' ); ?> <?php echo esc_html( $last_sync ?: __( 'Never', 'advanced-ffl-checkout' ) ); ?>
				&nbsp;·&nbsp;
				<button type="button" class="button" id="wpistic-ffl-rsr-sync"><?php esc_html_e( 'Sync Catalog', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-rsr-sync-msg" style="font-weight:600;"></span>
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
			<p class="description"><?php esc_html_e( 'Ships to the transfer\'s own dealer FFL on file. This uploads a real wholesale order file to RSR — double check the item number and quantity first.', 'advanced-ffl-checkout' ); ?></p>
			<form id="wpistic-ffl-rsr-order-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
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
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'RSR Stock #', 'advanced-ffl-checkout' ); ?></label>
					<input type="text" name="item_no" style="width:140px;">
				</p>
				<p style="margin:0;">
					<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;"><?php esc_html_e( 'Qty', 'advanced-ffl-checkout' ); ?></label>
					<input type="number" name="quantity" value="1" min="1" style="width:70px;">
				</p>
				<p style="margin:0;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Submit Order', 'advanced-ffl-checkout' ); ?></button></p>
				<span id="wpistic-ffl-rsr-order-msg" style="font-weight:600;"></span>
			</form>
		</div>

		<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
			<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🧾 <?php esc_html_e( 'Order History', 'advanced-ffl-checkout' ); ?></h2>
			<p>
				<button type="button" class="button" id="wpistic-ffl-rsr-check-responses"><?php esc_html_e( 'Check Responses (ECONF/EERR)', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-rsr-responses-msg" style="font-weight:600;"></span>
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
					// 'submitted' means the file uploaded, NOT that RSR
					// accepted it -- amber until check_responses() actually
					// finds a matching ECONF, so it's never confused with a
					// genuinely confirmed order at a glance.
					$rsr_color = 'confirmed' === $o->status ? '#16A34A' : ( 'submitted' === $o->status ? '#D97706' : '#DC2626' );
					?>
					<td><span style="color:<?php echo esc_attr( $rsr_color ); ?>;font-weight:700;"><?php echo esc_html( ucfirst( $o->status ) ); ?></span></td>
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

			document.getElementById('wpistic-ffl-rsr-settings-form').addEventListener('submit', function(e){
				e.preventDefault();
				var msg = document.getElementById('wpistic-ffl-rsr-settings-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_rsr_save_settings');
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Saved' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-rsr-test').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-rsr-settings-msg');
				msg.textContent = 'Testing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_rsr_test_connection');
				postJson(fd).then(function(j){
					msg.style.color = j.success ? '#16A34A' : '#DC2626';
					msg.textContent = j.success ? '✓ Connected' : '✗ ' + ((j.data && j.data.message) || 'Failed');
				});
			});

			document.getElementById('wpistic-ffl-rsr-sync').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-rsr-sync-msg');
				msg.textContent = 'Syncing…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_rsr_sync_catalog');
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			var transferSelect = document.querySelector('#wpistic-ffl-rsr-order-form select[name="transfer_id"]');
			var itemInput = document.querySelector('#wpistic-ffl-rsr-order-form input[name="item_no"]');
			transferSelect.addEventListener('change', function(){
				var opt = transferSelect.options[transferSelect.selectedIndex];
				if (opt && opt.dataset.sku && !itemInput.value) { itemInput.value = opt.dataset.sku; }
			});

			document.getElementById('wpistic-ffl-rsr-order-form').addEventListener('submit', function(e){
				e.preventDefault();
				if (!window.confirm('Upload this as a real order file to RSR?')) { return; }
				var msg = document.getElementById('wpistic-ffl-rsr-order-msg');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_rsr_submit_order');
				msg.textContent = 'Submitting…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					if (j.success) { location.reload(); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
				});
			});

			document.getElementById('wpistic-ffl-rsr-check-responses').addEventListener('click', function(){
				var msg = document.getElementById('wpistic-ffl-rsr-responses-msg');
				msg.textContent = 'Checking…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_rsr_check_responses');
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
		$username        = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
		$password        = (string) ( $_POST['password'] ?? '' );
		$merchant_number = sanitize_text_field( wp_unslash( $_POST['merchant_number'] ?? '' ) );
		$ftp_host        = sanitize_text_field( wp_unslash( $_POST['ftp_host'] ?? '' ) ) ?: 'ftps.rsrgroup.com';
		$ftp_port        = (int) ( $_POST['ftp_port'] ?? 2222 ) ?: 2222;
		$submission_dir  = sanitize_text_field( wp_unslash( $_POST['submission_dir'] ?? '' ) );
		$response_dir    = sanitize_text_field( wp_unslash( $_POST['response_dir'] ?? '' ) );
		// phpcs:enable

		$current = self::settings();
		if ( '' === $password ) {
			$password = $current['password'];
		}

		update_option( self::OPTION_KEY, [
			'username'        => $username,
			'password'        => G2A_Distributor_Support::encrypt_secret( $password, self::CONTEXT ),
			'merchant_number' => $merchant_number,
			'ftp_host'        => $ftp_host,
			'ftp_port'        => $ftp_port,
			'submission_dir'  => $submission_dir,
			'response_dir'    => $response_dir,
			'catalog_file'    => $current['catalog_file'],
		] );
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
