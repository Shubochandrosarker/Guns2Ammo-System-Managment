<?php
/**
 * G2A — State compliance rules admin (versioned, filterable data feed).
 *
 * Before v1.10.0 the only way to add, correct, or retire a state compliance
 * notice was to edit `Compliance::seed_state_rules()` / `G2A_State_Laws`'s
 * PHP source and redeploy — the checkout-notice text was baked into the
 * plugin, not a maintained data feed. State rules now live entirely in the
 * `state_rules` table (as they always did), but this page makes that table
 * genuinely admin-editable, and `wpistic_ffl_state_rules_seed` /
 * `wpistic_ffl_state_rules_seed_topup` (see DB::seed_state_rules() and
 * G2A_State_Laws::maybe_seed()) let a future maintained legal-data-feed
 * integration push updates without touching plugin source at all.
 *
 * Still explicitly NOT legal advice — same disclaimer this plugin has
 * always carried for state rules; this page changes who can edit the text
 * and how, not the legal weight of the text itself.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_State_Rules_Admin {

	public function __construct() {
		add_action( 'admin_menu',    [ $this, 'register_admin_page' ], 46 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		add_action( 'wp_ajax_wpistic_ffl_state_rule_save',   [ __CLASS__, 'ajax_save' ] );
		add_action( 'wp_ajax_wpistic_ffl_state_rule_delete', [ __CLASS__, 'ajax_delete' ] );
	}

	private static function us_states(): array {
		return [ 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC' ];
	}

	// ── Admin page ────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'State Compliance Rules', 'advanced-ffl-checkout' ),
			__( '🗺️ State Rules', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-state-rules',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$nonce   = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$version = get_option( 'wpistic_ffl_state_rules_version', '1.0' );
		$rules   = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'state_rules' ) . ' ORDER BY state_code ASC, id ASC'
		);
		$by_state = [];
		foreach ( $rules as $r ) {
			$by_state[ $r->state_code ][] = $r;
		}
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🗺️</span>
				<?php esc_html_e( 'State Compliance Rules', 'advanced-ffl-checkout' ); ?>
				<span style="margin-left:auto;font-size:12px;color:#666;font-weight:400;"><?php printf( esc_html__( 'Seed feed version %s', 'advanced-ffl-checkout' ), esc_html( $version ) ); ?></span>
			</h1>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'These notices show at checkout for firearms shipping to the listed state and, in strict mode, block checkout until acknowledged. This is a checkout-notice helper, NOT legal advice — verify with your own counsel before relying on any entry here. Rows marked "builtin" came from the plugin\'s default seed; edit or deactivate them freely, your changes are never overwritten by a future seed update.', 'advanced-ffl-checkout' ); ?>
			</p>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin:16px 0;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;"><?php esc_html_e( 'Add a rule', 'advanced-ffl-checkout' ); ?></h2>
				<form id="wpistic-ffl-state-rule-add" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<div>
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;">State</label>
						<select name="state_code" required>
							<?php foreach ( self::us_states() as $st ) : ?>
								<option value="<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;">Rule type</label>
						<input type="text" name="rule_type" placeholder="e.g. waiting_period" required>
					</div>
					<div style="flex:1;min-width:280px;">
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;">Description</label>
						<input type="text" name="description" style="width:100%;" required>
					</div>
					<div>
						<label style="display:block;font-size:11px;text-transform:uppercase;color:#666;">Item types</label>
						<input type="text" name="item_types" placeholder="handgun,rifle,shotgun" value="handgun,rifle,shotgun">
					</div>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-state-rule-add-result" style="font-weight:600;"></span>
				</form>
			</div>

			<?php foreach ( self::us_states() as $st ) :
				$state_rules = $by_state[ $st ] ?? [];
				if ( empty( $state_rules ) ) { continue; }
				?>
				<h3 style="margin:20px 0 6px;"><?php echo esc_html( $st ); ?></h3>
				<table class="widefat striped">
					<thead><tr>
						<th style="width:140px;"><?php esc_html_e( 'Rule Type', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Description', 'advanced-ffl-checkout' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'Item Types', 'advanced-ffl-checkout' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Source', 'advanced-ffl-checkout' ); ?></th>
						<th style="width:60px;"><?php esc_html_e( 'Active', 'advanced-ffl-checkout' ); ?></th>
						<th style="width:110px;"></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $state_rules as $r ) : ?>
						<tr data-rule-id="<?php echo esc_attr( $r->id ); ?>">
							<td><code><?php echo esc_html( $r->rule_type ); ?></code></td>
							<td><textarea class="wpistic-ffl-rule-desc" rows="2" style="width:100%;"><?php echo esc_textarea( $r->description ); ?></textarea></td>
							<td><input type="text" class="wpistic-ffl-rule-items" value="<?php echo esc_attr( $r->item_types ); ?>" style="width:100%;"></td>
							<td><span style="font-size:11px;color:#666;"><?php echo esc_html( $r->source ); ?></span></td>
							<td><label><input type="checkbox" class="wpistic-ffl-rule-active" <?php checked( (int) $r->is_active, 1 ); ?>></label></td>
							<td>
								<button type="button" class="button wpistic-ffl-rule-save"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button wpistic-ffl-rule-delete" style="color:#DC2626;"><?php esc_html_e( '✕', 'advanced-ffl-checkout' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;

			document.getElementById('wpistic-ffl-state-rule-add').addEventListener('submit', function(e){
				e.preventDefault();
				var result = document.getElementById('wpistic-ffl-state-rule-add-result');
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_state_rule_save');
				result.textContent = 'Saving…';
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						if (j && j.success) { location.reload(); }
						else { result.textContent = '✗ ' + (j && j.data && j.data.message ? j.data.message : 'Save failed'); result.style.color = '#DC2626'; }
					});
			});

			document.querySelectorAll('.wpistic-ffl-rule-save').forEach(function(btn){
				btn.addEventListener('click', function(){
					var tr = btn.closest('tr');
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_state_rule_save');
					fd.append('nonce', nonce);
					fd.append('rule_id', tr.getAttribute('data-rule-id'));
					fd.append('description', tr.querySelector('.wpistic-ffl-rule-desc').value);
					fd.append('item_types', tr.querySelector('.wpistic-ffl-rule-items').value);
					fd.append('is_active', tr.querySelector('.wpistic-ffl-rule-active').checked ? '1' : '0');
					btn.textContent = 'Saving…';
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(j){ btn.textContent = j && j.success ? '✓ Saved' : '✗ Failed'; });
				});
			});

			document.querySelectorAll('.wpistic-ffl-rule-delete').forEach(function(btn){
				btn.addEventListener('click', function(){
					if (! confirm('<?php echo esc_js( __( 'Delete this rule?', 'advanced-ffl-checkout' ) ); ?>')) return;
					var tr = btn.closest('tr');
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_state_rule_delete');
					fd.append('nonce', nonce);
					fd.append('rule_id', tr.getAttribute('data-rule-id'));
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(j){ if (j && j.success) { tr.remove(); } });
				});
			});
		})();
		</script>
		<?php
	}

	public static function ajax_save(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$rule_id     = (int) ( $_POST['rule_id'] ?? 0 );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$item_types  = sanitize_text_field( wp_unslash( $_POST['item_types'] ?? '' ) );
		$is_active   = isset( $_POST['is_active'] ) ? ( '1' === $_POST['is_active'] ? 1 : 0 ) : 1;
		// phpcs:enable

		global $wpdb;
		$user = wp_get_current_user();

		if ( $rule_id ) {
			if ( '' === $description ) {
				wp_send_json_error( [ 'message' => 'Description is required.' ], 400 );
			}
			$wpdb->update( DB::table( 'state_rules' ), [ // phpcs:ignore WordPress.DB
				'description'  => $description,
				'item_types'   => $item_types,
				'is_active'    => $is_active,
				'source'       => 'admin',
				'updated_at'   => current_time( 'mysql' ),
			], [ 'id' => $rule_id ] );
			$notes = 'State rule #' . $rule_id . ' edited.';
		} else {
			// phpcs:disable WordPress.Security.NonceVerification
			$state_code = strtoupper( sanitize_text_field( wp_unslash( $_POST['state_code'] ?? '' ) ) );
			$rule_type  = sanitize_key( wp_unslash( $_POST['rule_type'] ?? '' ) );
			// phpcs:enable
			if ( 2 !== strlen( $state_code ) || '' === $rule_type || '' === $description ) {
				wp_send_json_error( [ 'message' => 'State, rule type, and description are required.' ], 400 );
			}
			$wpdb->insert( DB::table( 'state_rules' ), [ // phpcs:ignore WordPress.DB
				'state_code'   => $state_code,
				'rule_type'    => $rule_type,
				'description'  => $description,
				'item_types'   => $item_types ?: 'handgun,rifle,shotgun',
				'is_active'    => 1,
				'source'       => 'admin',
				'rule_version' => (string) get_option( 'wpistic_ffl_state_rules_version', '1.0' ),
			] );
			$notes = 'State rule added for ' . $state_code . ' (' . $rule_type . ').';
		}

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'state_rule_changed',
			'notes'       => $notes,
			'actor'       => $user->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success();
	}

	public static function ajax_delete(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$rule_id = (int) ( $_POST['rule_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $rule_id ) {
			wp_send_json_error( [ 'message' => 'Missing rule.' ], 400 );
		}
		global $wpdb;
		$wpdb->delete( DB::table( 'state_rules' ), [ 'id' => $rule_id ] ); // phpcs:ignore WordPress.DB
		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'state_rule_changed',
			'notes'       => 'State rule #' . $rule_id . ' deleted.',
			'actor'       => wp_get_current_user()->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );
		wp_send_json_success();
	}

	// ── REST read endpoint ────────────────────────────────────

	public function register_routes(): void {
		register_rest_route( WPISTIC_FFL_REST_NS, '/compliance/state-rules', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'rest_state_rules' ],
			'args'                => [
				'state' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	public function rest_state_rules( \WP_REST_Request $req ): \WP_REST_Response {
		$state = strtoupper( (string) $req->get_param( 'state' ) );
		global $wpdb;
		if ( $state ) {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT state_code, rule_type, description, item_types, is_active, source, rule_version FROM ' . DB::table( 'state_rules' ) . ' WHERE state_code = %s AND is_active = 1',
				$state
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				'SELECT state_code, rule_type, description, item_types, is_active, source, rule_version FROM ' . DB::table( 'state_rules' ) . ' WHERE is_active = 1 ORDER BY state_code',
				ARRAY_A
			);
		}
		return rest_ensure_response( [
			'feed_version' => get_option( 'wpistic_ffl_state_rules_version', '1.0' ),
			'rules'        => $rows,
			'disclaimer'   => 'Checkout-notice helper text, not legal advice.',
			'generated_at' => gmdate( 'c' ),
		] );
	}
}
