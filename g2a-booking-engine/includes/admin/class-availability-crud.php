<?php
/**
 * Admin Availability CRUD — business hours editor + blackout dates.
 *
 * Manages wp_g2ab_availability_rules. Two modes:
 *   - business_hours: per day-of-week open/close
 *   - blackout: date range when ALL bookings blocked
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Availability_Crud {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-availability';
	const DAYS = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'override_menu' ), 11 );
		add_action( 'admin_post_g2ab_save_hours', array( $this, 'handle_save_hours' ) );
		add_action( 'admin_post_g2ab_add_blackout', array( $this, 'handle_add_blackout' ) );
		add_action( 'admin_post_g2ab_delete_blackout', array( $this, 'handle_delete_blackout' ) );
	}

	public function override_menu() {
		global $submenu;
		$idx = -1;
		if ( isset( $submenu['g2ab-bookings'] ) ) {
			foreach ( $submenu['g2ab-bookings'] as $i => $entry ) {
				if ( $entry[2] === self::PAGE_SLUG ) { $idx = $i; break; }
			}
		}
		remove_submenu_page( 'g2ab-bookings', self::PAGE_SLUG );
		add_submenu_page( 'g2ab-bookings', __( 'Availability', 'g2a-booking' ), __( 'Availability', 'g2a-booking' ), 'manage_g2ab_settings', self::PAGE_SLUG, array( $this, 'render' ) );
		if ( $idx >= 0 && isset( $submenu['g2ab-bookings'] ) ) {
			$new = array_pop( $submenu['g2ab-bookings'] );
			array_splice( $submenu['g2ab-bookings'], $idx, 0, array( $new ) );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		global $wpdb;
		$tbl = $wpdb->prefix . 'g2ab_availability_rules';

		// Load business hours per day.
		$hours = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$tbl} WHERE rule_type = 'business_hours' AND day_of_week = %d AND is_active = 1 ORDER BY priority DESC LIMIT 1", $d
			) );
			$hours[ $d ] = $row;
		}

		// Load blackouts.
		$blackouts = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE rule_type = 'blackout' ORDER BY start_date DESC LIMIT 50" );

		$msg = $this->get_notice();
		$this->print_styles();
		?>
		<div class="wrap g2ab-admin g2ab-av">
			<div class="g2ab-av__header">
				<div>
					<h1><span class="g2ab-av__stencil"><?php esc_html_e( 'AVAILABILITY', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-av__sub"><?php esc_html_e( 'Range hours · blackout dates · maintenance windows', 'g2a-booking' ); ?></p>
				</div>
			</div>

			<?php echo $msg; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-av__form">
				<?php wp_nonce_field( 'g2ab_save_hours', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_hours" />
				<div class="g2ab-av__panel">
					<h3><?php esc_html_e( 'BUSINESS HOURS', 'g2a-booking' ); ?></h3>
					<p class="g2ab-av__desc"><?php esc_html_e( 'Set per-day open/close times. Uncheck to mark a day closed.', 'g2a-booking' ); ?></p>
					<div class="g2ab-av__hours">
						<?php for ( $d = 0; $d <= 6; $d++ ) : $h = $hours[ $d ]; ?>
							<div class="g2ab-av__day">
								<label class="g2ab-av__day-name">
									<input type="checkbox" name="open[<?php echo $d; ?>]" value="1" <?php checked( ! empty( $h ) ); ?> />
									<span><?php echo esc_html( self::DAYS[ $d ] ); ?></span>
								</label>
								<input type="time" name="start[<?php echo $d; ?>]" value="<?php echo esc_attr( $h ? substr( (string) $h->start_time, 0, 5 ) : '10:00' ); ?>" />
								<span class="g2ab-av__sep">→</span>
								<input type="time" name="end[<?php echo $d; ?>]" value="<?php echo esc_attr( $h ? substr( (string) $h->end_time, 0, 5 ) : '20:00' ); ?>" />
							</div>
						<?php endfor; ?>
					</div>
					<div class="g2ab-av__actions">
						<button type="submit" class="g2ab-av__btn g2ab-av__btn--primary"><?php esc_html_e( 'SAVE HOURS', 'g2a-booking' ); ?></button>
					</div>
				</div>
			</form>

			<div class="g2ab-av__panel">
				<h3><?php esc_html_e( 'BLACKOUT DATES', 'g2a-booking' ); ?></h3>
				<p class="g2ab-av__desc"><?php esc_html_e( 'Block all bookings during a date range — holidays, private events, training, weather closures.', 'g2a-booking' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-av__blackout-form">
					<?php wp_nonce_field( 'g2ab_add_blackout', '_g2ab_nonce' ); ?>
					<input type="hidden" name="action" value="g2ab_add_blackout" />
					<input type="date" name="start_date" required />
					<span class="g2ab-av__sep">→</span>
					<input type="date" name="end_date" required />
					<input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason (Christmas, Maintenance, Private Event…)', 'g2a-booking' ); ?>" maxlength="200" />
					<button type="submit" class="g2ab-av__btn g2ab-av__btn--primary">+ <?php esc_html_e( 'ADD BLACKOUT', 'g2a-booking' ); ?></button>
				</form>

				<?php if ( empty( $blackouts ) ) : ?>
					<p class="g2ab-av__empty"><em><?php esc_html_e( 'No blackouts scheduled.', 'g2a-booking' ); ?></em></p>
				<?php else : ?>
					<table class="g2ab-av__table">
						<thead><tr><th>FROM</th><th>TO</th><th>DAYS</th><th>REASON</th><th></th></tr></thead>
						<tbody>
							<?php foreach ( $blackouts as $b ) : $days = max( 1, ( strtotime( $b->end_date ) - strtotime( $b->start_date ) ) / 86400 + 1 ); ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $b->start_date ) ) ); ?></td>
									<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $b->end_date ) ) ); ?></td>
									<td><?php echo (int) $days; ?></td>
									<td><?php echo esc_html( $b->reason ?: '—' ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this blackout?', 'g2a-booking' ) ); ?>');">
											<?php wp_nonce_field( 'g2ab_delete_blackout_' . $b->id, '_g2ab_nonce' ); ?>
											<input type="hidden" name="action" value="g2ab_delete_blackout" />
											<input type="hidden" name="id" value="<?php echo (int) $b->id; ?>" />
											<button class="g2ab-av__btn g2ab-av__btn--danger"><?php esc_html_e( 'DELETE', 'g2a-booking' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="g2ab-av__panel g2ab-av__panel--info">
				<h3><?php esc_html_e( 'HOW IT WORKS', 'g2a-booking' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Business hours apply to every resource unless a per-resource override is configured (Phase 2).', 'g2a-booking' ); ?></li>
					<li><?php esc_html_e( 'Blackout dates block all bookings — frontend slot grid will hide those days.', 'g2a-booking' ); ?></li>
					<li><?php esc_html_e( 'Existing bookings inside a blackout are NOT auto-cancelled — review them manually.', 'g2a-booking' ); ?></li>
					<li><?php esc_html_e( 'Time zone follows site setting in WordPress → Settings → General.', 'g2a-booking' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	public function handle_save_hours() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_hours', '_g2ab_nonce' );

		global $wpdb;
		$tbl  = $wpdb->prefix . 'g2ab_availability_rules';
		$open  = isset( $_POST['open'] ) && is_array( $_POST['open'] ) ? wp_unslash( $_POST['open'] ) : array();
		$start = isset( $_POST['start'] ) && is_array( $_POST['start'] ) ? wp_unslash( $_POST['start'] ) : array();
		$end   = isset( $_POST['end'] ) && is_array( $_POST['end'] ) ? wp_unslash( $_POST['end'] ) : array();
		$now   = current_time( 'mysql' );

		// Pre-build sanitised rows so we never enter the transaction with garbage data.
		$rows = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			if ( empty( $open[ $d ] ) ) continue;
			$st = isset( $start[ $d ] ) ? sanitize_text_field( $start[ $d ] ) : '10:00';
			$et = isset( $end[ $d ] ) ? sanitize_text_field( $end[ $d ] ) : '20:00';
			if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $st ) ) $st = '10:00';
			if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $et ) ) $et = '20:00';
			if ( strtotime( $et ) <= strtotime( $st ) ) {
				$this->set_notice( 'error', __( 'End time must be after start time.', 'g2a-booking' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
				exit;
			}
			$rows[] = array(
				'rule_type'   => 'business_hours',
				'day_of_week' => $d,
				'start_time'  => $st . ':00',
				'end_time'    => $et . ':00',
				'priority'    => 10,
				'is_active'   => 1,
				'created_at'  => $now,
			);
		}

		// Wrap the wipe + re-insert in a transaction so a PHP fatal can't leave
		// the site with zero business hours.
		$wpdb->query( 'START TRANSACTION' );
		$deleted = $wpdb->query( "DELETE FROM {$tbl} WHERE rule_type = 'business_hours'" );
		if ( false === $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			$this->set_notice( 'error', __( 'Could not update business hours (delete step failed).', 'g2a-booking' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
		foreach ( $rows as $row ) {
			if ( false === $wpdb->insert( $tbl, $row ) ) {
				$wpdb->query( 'ROLLBACK' );
				$this->set_notice( 'error', __( 'Could not save business hours (insert step failed).', 'g2a-booking' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
				exit;
			}
		}
		$wpdb->query( 'COMMIT' );

		$this->set_notice( 'success', __( 'Business hours saved.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function handle_add_blackout() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_add_blackout', '_g2ab_nonce' );

		$start = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
		$end   = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );
		$reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			$this->set_notice( 'error', __( 'Invalid dates.', 'g2a-booking' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
		if ( strtotime( $end ) < strtotime( $start ) ) {
			$this->set_notice( 'error', __( 'End date must be on or after start date.', 'g2a-booking' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'g2ab_availability_rules', array(
			'rule_type'  => 'blackout',
			'start_date' => $start,
			'end_date'   => $end,
			'reason'     => $reason,
			'priority'   => 20,
			'is_active'  => 1,
			'created_at' => current_time( 'mysql' ),
		) );

		$this->set_notice( 'success', __( 'Blackout added.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function handle_delete_blackout() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( 'No permission.' );
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'g2ab_delete_blackout_' . $id, '_g2ab_nonce' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'g2ab_availability_rules', array( 'id' => $id, 'rule_type' => 'blackout' ), array( '%d', '%s' ) );
		$this->set_notice( 'success', __( 'Blackout removed.', 'g2a-booking' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	private function set_notice( $type, $msg ) { set_transient( 'g2ab_av_notice_' . get_current_user_id(), array( 'type' => $type, 'msg' => $msg ), 30 ); }
	private function get_notice() {
		$key = 'g2ab_av_notice_' . get_current_user_id();
		$n = get_transient( $key );
		if ( ! $n ) return '';
		delete_transient( $key );
		$cls = 'notice-' . ( 'error' === $n['type'] ? 'error' : 'success' );
		return '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $n['msg'] ) . '</p></div>';
	}

	private function print_styles() {
		echo '<style>
.g2ab-av{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-av__header{background:linear-gradient(135deg,#0F1115 0%,#1A1F26 100%);color:#E8E8E8;padding:24px 28px;margin:20px 0 16px;border-left:4px solid #D2691E;}
.g2ab-av__stencil{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;letter-spacing:.04em;color:#fff;}
.g2ab-av__sub{margin:4px 0 0;color:#8A95A5;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
.g2ab-av__panel{background:#fff;border:1px solid #d0d4d9;padding:24px 28px;margin-bottom:14px;}
.g2ab-av__panel h3{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:13px;letter-spacing:.12em;color:#D2691E;margin:0 0 8px;font-weight:700;}
.g2ab-av__desc{color:#8A95A5;font-size:12px;margin:0 0 16px;}
.g2ab-av__hours{display:grid;gap:10px;}
.g2ab-av__day{display:grid;grid-template-columns:180px 130px 30px 130px;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f0f1f3;}
.g2ab-av__day-name{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#0F1115;cursor:pointer;}
.g2ab-av__day-name input{accent-color:#D2691E;}
.g2ab-av__sep{text-align:center;color:#8A95A5;font-weight:700;}
.g2ab-av__day input[type="time"]{padding:7px 10px;border:1px solid #d0d4d9;font-size:13px;border-radius:2px;}
.g2ab-av__actions{margin-top:16px;}
.g2ab-av__btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;color:#0F1115;border:1px solid #d0d4d9;text-decoration:none;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:2px;cursor:pointer;}
.g2ab-av__btn:hover{background:#f8f9fa;color:#D2691E;}
.g2ab-av__btn--primary{background:#D2691E;color:#fff;border-color:#D2691E;padding:10px 20px;}
.g2ab-av__btn--primary:hover{background:#0F1115;color:#fff;border-color:#0F1115;}
.g2ab-av__btn--danger{color:#C62828;border-color:#fdd;padding:6px 12px;font-size:10px;}
.g2ab-av__btn--danger:hover{background:#C62828;color:#fff;border-color:#C62828;}
.g2ab-av__blackout-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f8f9fa;padding:14px;border-left:3px solid #D2691E;margin-bottom:18px;}
.g2ab-av__blackout-form input[type="date"],.g2ab-av__blackout-form input[type="text"]{padding:8px 12px;border:1px solid #d0d4d9;border-radius:2px;font-size:13px;}
.g2ab-av__blackout-form input[type="text"]{flex:1;min-width:200px;}
.g2ab-av__table{width:100%;border-collapse:collapse;}
.g2ab-av__table th{text-align:left;padding:10px 8px;font-size:10px;letter-spacing:.08em;color:#8A95A5;border-bottom:2px solid #d0d4d9;}
.g2ab-av__table td{padding:10px 8px;border-bottom:1px solid #f0f1f3;font-size:13px;}
.g2ab-av__empty{padding:18px;text-align:center;color:#8A95A5;background:#f8f9fa;}
.g2ab-av__panel--info ul{margin:0;padding-left:20px;font-size:13px;color:#3c434a;}
.g2ab-av__panel--info li{margin-bottom:6px;}
</style>';
	}
}
