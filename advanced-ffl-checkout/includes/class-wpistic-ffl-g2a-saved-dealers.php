<?php
/**
 * G2A — Per-customer saved dealer registry.
 *
 * After a successful order, the chosen dealer is auto-saved to the buyer's
 * profile as a "recent" entry (cap 5, most-recent first). At their next
 * checkout we surface those entries as a "Quick pick" section above the
 * full dealer search — repeat customers get one tap to re-select.
 *
 * The customer can manage the list from the "My FFL Transfers" My Account
 * tab: pin one as the default, remove, or clear all.
 *
 * Privacy: stored as plain WP user meta (no PII beyond the dealer IDs +
 * timestamps the customer chose themselves). Exported / deleted by the
 * core WP personal-data tools automatically via the standard usermeta path.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Saved_Dealers {

	const META_RECENT   = '_wpistic_ffl_saved_dealers';
	const META_PINNED   = '_wpistic_ffl_pinned_dealer';
	const MAX_RECENT    = 5;

	public function __construct() {
		// Auto-save after the transfer record is created.
		add_action( 'wpistic_ffl_transfer_created', [ __CLASS__, 'auto_save_from_transfer' ], 20, 2 );

		// Localize saved dealers into the checkout widget JS.
		add_filter( 'wpistic_ffl_checkout_localize',  [ __CLASS__, 'inject_saved_dealers' ] );

		// REST endpoints for the account tab + dashboard app.
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	// ── Data access ────────────────────────────────────────────

	/**
	 * Return the user's saved-dealer list — most-recent first.
	 * Hydrates each ID with the dealer row so the UI can render the full card.
	 *
	 * @return array<int,array{id:int, business_name:string, premise_city:string, premise_state:string, premise_zip:string, phone:string, license_number:string, last_used_at:string, pinned:bool}>
	 */
	public static function for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}
		$recent  = (array) get_user_meta( $user_id, self::META_RECENT, true );
		$pinned  = (int) get_user_meta( $user_id, self::META_PINNED, true );
		$recent  = array_values( array_filter( $recent, fn( $r ) => is_array( $r ) && ! empty( $r['dealer_id'] ) ) );
		if ( empty( $recent ) && ! $pinned ) {
			return [];
		}

		$ids = array_unique( array_map( fn( $r ) => (int) $r['dealer_id'], $recent ) );
		if ( $pinned && ! in_array( $pinned, $ids, true ) ) {
			array_unshift( $ids, $pinned );
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, business_name, license_number, premise_city, premise_state, premise_zip, phone, transfer_fee, is_preferred
			 FROM ' . DB::table( 'dealers' ) . " WHERE id IN ($placeholders) AND is_active = 1",
			...$ids
		), ARRAY_A );

		$by_id = [];
		foreach ( (array) $rows as $r ) {
			$by_id[ (int) $r['id'] ] = $r;
		}

		$out = [];
		// Pinned first.
		if ( $pinned && isset( $by_id[ $pinned ] ) ) {
			$out[] = self::hydrate( $by_id[ $pinned ], null, true );
		}
		foreach ( $recent as $r ) {
			$id = (int) $r['dealer_id'];
			if ( $id === $pinned ) {
				continue; // already added
			}
			if ( ! isset( $by_id[ $id ] ) ) {
				continue; // dealer was removed from ATF sync — silently drop
			}
			$out[] = self::hydrate( $by_id[ $id ], (string) ( $r['last_used_at'] ?? '' ), false );
		}
		return $out;
	}

	private static function hydrate( array $row, ?string $last_used_at, bool $pinned ): array {
		return [
			'id'             => (int) $row['id'],
			'business_name'  => (string) $row['business_name'],
			'license_number' => (string) $row['license_number'],
			'premise_city'   => (string) $row['premise_city'],
			'premise_state'  => (string) $row['premise_state'],
			'premise_zip'    => (string) $row['premise_zip'],
			'phone'          => (string) $row['phone'],
			'transfer_fee'   => (float) $row['transfer_fee'],
			'is_preferred'   => (int) $row['is_preferred'],
			'last_used_at'   => (string) $last_used_at,
			'pinned'         => $pinned,
		];
	}

	// ── Auto-save after a transfer is created ─────────────────────

	/**
	 * Hooked into wpistic_ffl_transfer_created. We look up the dealer ID
	 * from the freshly-inserted transfer row + the WC customer ID, then
	 * push onto the user's recent list.
	 */
	public static function auto_save_from_transfer( int $transfer_id, int $order_id ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT customer_id, dealer_id FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$transfer_id
		) );
		if ( ! $row || empty( $row->customer_id ) || empty( $row->dealer_id ) ) {
			return;
		}
		self::push( (int) $row->customer_id, (int) $row->dealer_id );
	}

	/**
	 * Push a (user, dealer) onto the recent list. Dedupes (existing entry
	 * is bumped to the top with a fresh timestamp) and caps at MAX_RECENT.
	 */
	public static function push( int $user_id, int $dealer_id ): void {
		if ( $user_id <= 0 || $dealer_id <= 0 ) {
			return;
		}
		$recent = (array) get_user_meta( $user_id, self::META_RECENT, true );
		$recent = array_values( array_filter( $recent, fn( $r ) =>
			is_array( $r ) && (int) ( $r['dealer_id'] ?? 0 ) !== $dealer_id
		) );
		array_unshift( $recent, [
			'dealer_id'    => $dealer_id,
			'last_used_at' => current_time( 'mysql' ),
		] );
		$recent = array_slice( $recent, 0, self::MAX_RECENT );
		update_user_meta( $user_id, self::META_RECENT, $recent );
	}

	public static function remove( int $user_id, int $dealer_id ): void {
		$recent = (array) get_user_meta( $user_id, self::META_RECENT, true );
		$recent = array_values( array_filter( $recent, fn( $r ) =>
			is_array( $r ) && (int) ( $r['dealer_id'] ?? 0 ) !== $dealer_id
		) );
		update_user_meta( $user_id, self::META_RECENT, $recent );
		if ( (int) get_user_meta( $user_id, self::META_PINNED, true ) === $dealer_id ) {
			delete_user_meta( $user_id, self::META_PINNED );
		}
	}

	public static function pin( int $user_id, int $dealer_id ): void {
		if ( $dealer_id > 0 ) {
			update_user_meta( $user_id, self::META_PINNED, $dealer_id );
		} else {
			delete_user_meta( $user_id, self::META_PINNED );
		}
	}

	// ── Checkout integration ────────────────────────────────

	/**
	 * Inject the saved-dealers list into wp_localize_script data so the
	 * vanilla-JS widget can render a "Quick pick" section without an extra
	 * REST round-trip on page load.
	 *
	 * Routed through a filter so the Checkout class stays decoupled from
	 * the saved-dealers feature.
	 */
	public static function inject_saved_dealers( array $data ): array {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			$data['saved_dealers'] = [];
			return $data;
		}
		$data['saved_dealers']  = self::for_user( $uid );
		$data['saved_dealers_i18n'] = [
			'heading'   => __( 'Your saved dealers', 'advanced-ffl-checkout' ),
			'pin'       => __( 'Set as default', 'advanced-ffl-checkout' ),
			'pinned'    => __( 'Default', 'advanced-ffl-checkout' ),
			'remove'    => __( 'Remove', 'advanced-ffl-checkout' ),
			'last_used' => __( 'last used', 'advanced-ffl-checkout' ),
		];
		return $data;
	}

	// ── REST ──────────────────────────────────────────────────

	public function register_routes(): void {
		$ns = WPISTIC_FFL_REST_NS;

		register_rest_route( $ns, '/me/saved-dealers', [
			[
				'methods'             => 'GET',
				'permission_callback' => [ $this, 'require_logged_in' ],
				'callback'            => [ $this, 'rest_list' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => [ $this, 'require_logged_in' ],
				'callback'            => [ $this, 'rest_remove' ],
				'args'                => [ 'dealer_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => [ $this, 'require_logged_in' ],
				'callback'            => [ $this, 'rest_pin' ],
				'args'                => [ 'dealer_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
			],
		] );
	}

	public function require_logged_in(): bool {
		return is_user_logged_in();
	}

	public function rest_list(): \WP_REST_Response {
		return rest_ensure_response( [
			'items' => self::for_user( get_current_user_id() ),
		] );
	}

	public function rest_remove( \WP_REST_Request $req ): \WP_REST_Response {
		self::remove( get_current_user_id(), (int) $req->get_param( 'dealer_id' ) );
		return rest_ensure_response( [ 'ok' => true ] );
	}

	public function rest_pin( \WP_REST_Request $req ): \WP_REST_Response {
		self::pin( get_current_user_id(), (int) $req->get_param( 'dealer_id' ) );
		return rest_ensure_response( [ 'ok' => true ] );
	}
}
