<?php
/**
 * Memberistic settings tab.
 *
 * @package G2AB\Modules\Memberistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Memberistic_Settings {

	public function __construct() {
		add_filter( 'g2ab_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'g2ab_settings_render_memberistic', array( $this, 'render' ) );
		add_action( 'admin_post_g2ab_save_memberistic', array( $this, 'save' ) );
	}

	public function register_tab( $tabs ) {
		$tabs['memberistic'] = 'Memberistic';
		return $tabs;
	}

	public function render() {
		$module        = G2AB_Module_Memberistic::instance();
		$active        = $module->memberistic_active();
		$plans         = $module->get_plans();
		$rules         = $module->get_rules();
		$enabled       = (int) get_option( G2AB_Module_Memberistic::OPT_ENABLED, 1 );
		$mode          = get_option( G2AB_Module_Memberistic::OPT_MEMBERS_ONLY_MODE, 'any_active' );
		$booking_types = $this->booking_types();
		$msg           = ! empty( $_GET['saved'] ) ? '<div class="notice notice-success is-dismissible"><p>Memberistic booking rules saved.</p></div>' : '';
		?>
		<style>
			.g2ab-mst__panel{background:#fff;border:1px solid #d0d4d9;padding:24px;margin-bottom:18px;border-radius:6px;}
			.g2ab-mst__status{display:inline-block;padding:4px 10px;border-radius:2px;color:#fff;font-size:11px;letter-spacing:.06em;font-weight:700;}
			.g2ab-mst__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;}
			.g2ab-mst__card{border:1px solid #e2e5e9;background:#fff;padding:18px;border-radius:6px;}
			.g2ab-mst__row{margin-bottom:14px;}
			.g2ab-mst__row label{display:block;font-weight:700;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#3c434a;}
			.g2ab-mst__row input[type=number],.g2ab-mst__row select{width:100%;max-width:260px;}
			.g2ab-mst__checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px 12px;margin-top:8px;}
			.g2ab-mst__checks label,.g2ab-mst__inline{display:flex;gap:6px;align-items:center;font-weight:400;text-transform:none;letter-spacing:0;}
			.g2ab-mst__hint{color:#646970;font-size:12px;margin:6px 0 0;}
			.g2ab-mst__example{background:#f6f7f7;border-left:4px solid #C9A84C;padding:12px 14px;margin:12px 0 0;}
		</style>
		<?php echo $msg; // phpcs:ignore ?>

		<div class="g2ab-mst__panel">
			<h2 style="margin-top:0;">Memberistic Connection
				<?php if ( $active ) : ?>
					<span class="g2ab-mst__status" style="background:#4CAF50;">CONNECTED</span>
				<?php else : ?>
					<span class="g2ab-mst__status" style="background:#C62828;">NOT DETECTED</span>
				<?php endif; ?>
			</h2>
			<?php if ( ! $active ) : ?>
				<p>The Memberistic plugin / class was not detected. Install Memberistic and create plans, then return here to map plan-specific discounts.</p>
			<?php else : ?>
				<p>Map each Memberistic plan to a booking discount percentage. Plans are picked up automatically from Memberistic.</p>
				<div class="g2ab-mst__example">
					<strong>Example:</strong> Set the "Range Member" plan to <code>50</code>% discount applied to all booking types. A logged-in customer on that plan sees lane prices at half off. Set <code>100</code> for free member bookings.
				</div>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'g2ab_save_memberistic', '_g2ab_nonce' ); ?>
			<input type="hidden" name="action" value="g2ab_save_memberistic" />

			<div class="g2ab-mst__panel">
				<h2 style="margin-top:0;">Global Rules</h2>
				<div class="g2ab-mst__row">
					<label class="g2ab-mst__inline"><input type="checkbox" name="enabled" value="1" <?php checked( 1, $enabled ); ?> /> Enable Memberistic booking discounts</label>
					<p class="g2ab-mst__hint">When disabled, booking prices use the normal price (and the legacy member_discount column).</p>
				</div>
				<div class="g2ab-mst__row">
					<label>Members-only booking access</label>
					<select name="members_only_mode">
						<option value="any_active" <?php selected( $mode, 'any_active' ); ?>>Any active paid Memberistic plan can book members-only types</option>
						<option value="configured_only" <?php selected( $mode, 'configured_only' ); ?>>Only plans configured below can book members-only types</option>
					</select>
				</div>
			</div>

			<div class="g2ab-mst__panel">
				<h2 style="margin-top:0;">Per-Plan Discounts</h2>
				<?php if ( empty( $plans ) ) : ?>
					<p>No Memberistic plans discovered yet. Create plans in Memberistic, then refresh this page.</p>
				<?php else : ?>
					<div class="g2ab-mst__grid">
						<?php foreach ( $plans as $plan_id => $plan ) :
							$rule           = isset( $rules[ $plan_id ] ) && is_array( $rules[ $plan_id ] ) ? $rules[ $plan_id ] : array();
							$discount       = isset( $rule['discount'] ) ? (float) $rule['discount'] : 0;
							$all            = ! empty( $rule['all'] );
							$selected_types = isset( $rule['booking_types'] ) && is_array( $rule['booking_types'] ) ? array_map( 'absint', $rule['booking_types'] ) : array();
						?>
							<div class="g2ab-mst__card">
								<h3 style="margin-top:0;"><?php echo esc_html( $plan->name ); ?> <small>#<?php echo (int) $plan_id; ?></small></h3>
								<div class="g2ab-mst__row">
									<label>Discount percentage</label>
									<input type="number" name="rules[<?php echo (int) $plan_id; ?>][discount]" min="0" max="100" step="0.01" value="<?php echo esc_attr( $discount ); ?>" />
									<p class="g2ab-mst__hint">Use <strong>100</strong> for free member bookings.</p>
								</div>
								<div class="g2ab-mst__row">
									<label class="g2ab-mst__inline"><input type="checkbox" name="rules[<?php echo (int) $plan_id; ?>][all]" value="1" <?php checked( $all ); ?> /> Apply to all booking types</label>
								</div>
								<div class="g2ab-mst__row">
									<label>Or select specific booking types</label>
									<div class="g2ab-mst__checks">
										<?php foreach ( $booking_types as $bt ) : ?>
											<label><input type="checkbox" name="rules[<?php echo (int) $plan_id; ?>][booking_types][]" value="<?php echo (int) $bt->id; ?>" <?php checked( in_array( (int) $bt->id, $selected_types, true ) ); ?> /> <?php echo esc_html( $bt->name ); ?></label>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<button class="button button-primary">Save Memberistic Booking Rules</button>
		</form>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_memberistic', '_g2ab_nonce' );

		update_option( G2AB_Module_Memberistic::OPT_ENABLED, isset( $_POST['enabled'] ) ? 1 : 0 );

		$mode = sanitize_key( $_POST['members_only_mode'] ?? 'any_active' );
		if ( ! in_array( $mode, array( 'any_active', 'configured_only' ), true ) ) {
			$mode = 'any_active';
		}
		update_option( G2AB_Module_Memberistic::OPT_MEMBERS_ONLY_MODE, $mode );

		$rules_in = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
		$rules    = array();
		foreach ( $rules_in as $plan_id => $rule ) {
			$plan_id = absint( $plan_id );
			if ( ! $plan_id || ! is_array( $rule ) ) continue;
			$discount = min( 100, max( 0, (float) ( $rule['discount'] ?? 0 ) ) );
			$type_ids = isset( $rule['booking_types'] ) && is_array( $rule['booking_types'] ) ? array_values( array_unique( array_map( 'absint', $rule['booking_types'] ) ) ) : array();
			$rules[ $plan_id ] = array(
				'discount'      => $discount,
				'all'           => ! empty( $rule['all'] ) ? 1 : 0,
				'booking_types' => $type_ids,
			);
		}
		update_option( G2AB_Module_Memberistic::OPT_RULES, $rules );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'memberistic', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function booking_types() {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_booking_types';
		return $wpdb->get_results( "SELECT id, name, category FROM {$table} WHERE is_active = 1 ORDER BY category ASC, name ASC" );
	}
}
