<?php
/**
 * G2A — Customer "My FFL Transfers" tab in WooCommerce My Account.
 *
 * Adds an endpoint at /my-account/ffl-transfers/ that lists every transfer
 * belonging to the logged-in customer (matched on customer_id and email).
 * Shows status badge, item, dealer card, tracking, and the federal 3-day
 * counter when a NICS delay is in flight. No PII leaks — only transfers
 * that match the logged-in user.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Account {

	const ENDPOINT = 'ffl-transfers';

	public function __construct() {
		add_action( 'init',                                [ $this, 'register_endpoint' ] );
		add_filter( 'query_vars',                          [ $this, 'add_query_var' ], 0 );
		add_filter( 'woocommerce_account_menu_items',      [ $this, 'add_menu_item' ] );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render' ] );
	}

	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public function add_menu_item( array $items ): array {
		// Insert before "Logout" so the tab sits with the other action tabs.
		$new = [];
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT ] = __( 'My FFL Transfers', 'advanced-ffl-checkout' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'My FFL Transfers', 'advanced-ffl-checkout' );
		}
		return $new;
	}

	public function render(): void {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			esc_html_e( 'Please log in to view your transfers.', 'advanced-ffl-checkout' );
			return;
		}

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.*, d.business_name AS dealer_name,
			        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
			        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip,
			        d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.customer_id = %d OR t.customer_email = %s
			 ORDER BY t.created_at DESC
			 LIMIT 50',
			$user->ID,
			$user->user_email
		) );

		$theme  = Theming::settings();
		$accent = $theme['color_primary'];
		$muted  = $theme['color_text_muted'];

		if ( empty( $rows ) ) {
			echo '<p style="padding:24px;border:1px dashed ' . esc_attr( $theme['color_border'] ) . ';border-radius:8px;color:' . esc_attr( $muted ) . ';">';
			esc_html_e( 'You don\'t have any FFL transfers yet. When you purchase a firearm, your transfer will appear here.', 'advanced-ffl-checkout' );
			echo '</p>';
			return;
		}

		echo '<div class="g2a-ffl-account">';
		foreach ( (array) $rows as $row ) {
			self::render_transfer_card( $row, $accent, $muted, $theme );
		}
		echo '</div>';
	}

	private static function render_transfer_card( object $row, string $accent, string $muted, array $theme ): void {
		$status_labels = [
			'payment_confirmed'  => __( 'Order Confirmed', 'advanced-ffl-checkout' ),
			'dealer_selected'    => __( 'Dealer Selected', 'advanced-ffl-checkout' ),
			'shipped_to_dealer'  => __( 'Shipped to Dealer', 'advanced-ffl-checkout' ),
			'received_by_dealer' => __( 'Received by Dealer', 'advanced-ffl-checkout' ),
			'dealer_received'    => __( 'Received by Dealer', 'advanced-ffl-checkout' ),
			'background_check'   => __( 'Background Check', 'advanced-ffl-checkout' ),
			'nics_pending'       => __( 'Background Check', 'advanced-ffl-checkout' ),
			'delayed'            => __( 'NICS Delayed', 'advanced-ffl-checkout' ),
			'nics_delayed'       => __( 'NICS Delayed', 'advanced-ffl-checkout' ),
			'approved'           => __( 'NICS Approved', 'advanced-ffl-checkout' ),
			'denied'             => __( 'NICS Denied', 'advanced-ffl-checkout' ),
			'nics_denied'        => __( 'NICS Denied', 'advanced-ffl-checkout' ),
			'transferred'        => __( 'Transfer Complete', 'advanced-ffl-checkout' ),
			'cancelled'          => __( 'Cancelled', 'advanced-ffl-checkout' ),
			'issue_reported'     => __( 'Issue Reported', 'advanced-ffl-checkout' ),
		];

		$status_colors = [
			'transferred'        => $theme['color_success'],
			'denied'             => $theme['color_danger'],
			'nics_denied'        => $theme['color_danger'],
			'cancelled'          => $muted,
			'delayed'            => $theme['color_warning'],
			'nics_delayed'       => $theme['color_warning'],
		];

		$status        = (string) $row->status;
		$status_label  = $status_labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
		$status_color  = $status_colors[ $status ] ?? $accent;
		$item          = trim( ( $row->item_make ?: '' ) . ' ' . ( $row->item_model ?: '' ) ) ?: ( $row->item_description ?: '—' );
		$tracking      = trim( strtoupper( (string) $row->shipment_carrier ) . ' ' . (string) $row->shipment_tracking );

		echo '<article style="margin-bottom:16px;padding:20px;border:1px solid ' . esc_attr( $theme['color_border'] ) . ';border-radius:10px;background:' . esc_attr( $theme['color_surface'] ) . ';color:' . esc_attr( $theme['color_text'] ) . ';">';

		// Header row
		echo '<header style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;">';
		echo '<div>';
		echo '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:' . esc_attr( $muted ) . ';">Transfer Reference</div>';
		echo '<div style="font-family:ui-monospace,SFMono-Regular,monospace;font-size:18px;font-weight:700;color:' . esc_attr( $accent ) . ';">#' . esc_html( $row->transfer_ref ) . '</div>';
		echo '</div>';
		echo '<span style="display:inline-block;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;background:' . esc_attr( $status_color ) . ';color:#0F0E12;text-transform:uppercase;letter-spacing:.06em;">' . esc_html( $status_label ) . '</span>';
		echo '</header>';

		// Body grid
		echo '<dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 16px;margin:0;">';
		self::row( __( 'Item', 'advanced-ffl-checkout' ), $item . ( $row->item_caliber ? ' · ' . $row->item_caliber : '' ), $muted );
		if ( $row->dealer_name ) {
			$dealer_addr = trim( ( $row->dealer_street ?? '' ) . ', ' . ( $row->dealer_city ?? '' ) . ', ' . ( $row->dealer_state ?? '' ) . ' ' . ( $row->dealer_zip ?? '' ), ', ' );
			self::row( __( 'Dealer', 'advanced-ffl-checkout' ), $row->dealer_name . ( $dealer_addr ? '<br><span style="color:' . esc_attr( $muted ) . ';font-size:13px;">' . esc_html( $dealer_addr ) . '</span>' : '' ), $muted, true );
		}
		if ( $tracking ) {
			self::row( __( 'Tracking', 'advanced-ffl-checkout' ), $tracking, $muted );
		}
		if ( $row->shipped_date ) {
			self::row( __( 'Shipped', 'advanced-ffl-checkout' ), date_i18n( 'M j, Y', strtotime( (string) $row->shipped_date ) ), $muted );
		}
		if ( $row->dealer_received_date ) {
			self::row( __( 'Arrived', 'advanced-ffl-checkout' ), date_i18n( 'M j, Y', strtotime( (string) $row->dealer_received_date ) ), $muted );
		}
		if ( in_array( $status, [ 'delayed', 'nics_delayed' ], true ) && $row->nics_delay_expires ) {
			$days = max( 0, (int) round( ( strtotime( $row->nics_delay_expires ) - time() ) / DAY_IN_SECONDS ) );
			self::row( __( '3-Day Rule', 'advanced-ffl-checkout' ), sprintf( esc_html__( 'Window expires %s (in %d days)', 'advanced-ffl-checkout' ), esc_html( $row->nics_delay_expires ), $days ), $theme['color_warning'] );
		}
		echo '</dl>';

		echo '</article>';
	}

	private static function row( string $label, string $value, string $muted, bool $allow_html = false ): void {
		echo '<div>';
		echo '<dt style="font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:' . esc_attr( $muted ) . ';margin:0 0 2px;">' . esc_html( $label ) . '</dt>';
		echo '<dd style="margin:0;font-weight:600;">' . ( $allow_html ? wp_kses_post( $value ) : esc_html( $value ) ) . '</dd>';
		echo '</div>';
	}

	/**
	 * Activation helper — call once to flush rewrite rules so the new endpoint
	 * URL works without manual permalink resave. The bootstrap calls
	 * flush_rewrite_rules() at install, which picks this up.
	 */
	public static function flush_on_activation(): void {
		( new self() )->register_endpoint();
		flush_rewrite_rules();
	}
}
