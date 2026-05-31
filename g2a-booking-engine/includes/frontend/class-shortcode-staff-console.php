<?php
/**
 * [g2ab_staff_console] — full-page front-end staff dashboard.
 *
 * Renders the console shell + bootstraps the JS app. Heavy work happens
 * inside G2AB_REST_Staff_Controller; this class is just shell + asset
 * wiring + capability gate.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Staff_Console {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2ab_staff_console', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="g2ab-sc-gate">' . esc_html__( 'Staff sign-in required.', 'g2a-booking' )
				. ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'g2a-booking' ) . '</a></div>';
		}
		if ( ! current_user_can( 'manage_g2ab_bookings' ) ) {
			return '<div class="g2ab-sc-gate">' . esc_html__( 'You do not have permission to use the staff console.', 'g2a-booking' ) . '</div>';
		}

		$user = wp_get_current_user();
		$role = '';
		if ( current_user_can( 'manage_options' ) ) {
			$role = __( 'Manager', 'g2a-booking' );
		} elseif ( current_user_can( 'manage_g2ab_bookings' ) ) {
			$role = __( 'Range Officer', 'g2a-booking' );
		}

		// Enqueue assets only on pages where the shortcode actually rendered.
		wp_enqueue_style(
			'g2ab-staff-console',
			G2AB_URL . 'assets/css/staff-console.css',
			array(),
			G2AB_VERSION
		);
		wp_enqueue_script(
			'g2ab-staff-console',
			G2AB_URL . 'assets/js/staff-console.js',
			array(),
			G2AB_VERSION,
			true
		);
		wp_localize_script( 'g2ab-staff-console', 'g2abStaff', array(
			'root'   => esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'user'   => array(
				'name' => $user->display_name ?: $user->user_login,
				'role' => $role,
			),
			'i18n'   => array(
				'scanHint'       => __( 'Hold the member card steady in the frame', 'g2a-booking' ),
				'scanSuccess'    => __( 'Member found', 'g2a-booking' ),
				'noCamera'       => __( 'Camera access denied or unavailable', 'g2a-booking' ),
				'searchPrompt'   => __( 'Search by email or first / last name', 'g2a-booking' ),
				'doneTitle'      => __( 'Done', 'g2a-booking' ),
				'errorTitle'     => __( 'Could not complete action', 'g2a-booking' ),
				'waiverCurrent'  => __( 'Waiver current', 'g2a-booking' ),
				'waiverExpired'  => __( 'Waiver expired', 'g2a-booking' ),
				'waiverMissing'  => __( 'No waiver on file', 'g2a-booking' ),
				'requireMember'  => __( 'Member required for this action', 'g2a-booking' ),
			),
		) );

		ob_start();
		?>
		<div class="g2ab-sc" id="g2ab-sc" data-current-time="<?php echo esc_attr( current_time( 'H:i' ) ); ?>">
			<aside class="g2ab-sc__side">
				<div class="g2ab-sc__who">
					<div class="g2ab-sc__role">◆ <?php echo esc_html( $role ); ?></div>
					<div class="g2ab-sc__nm"><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></div>
				</div>
				<nav class="g2ab-sc__nav">
					<a class="g2ab-sc__navi is-cur" data-pane="dashboard" href="#"><span class="g2ab-sc__navi-ic">▭</span><?php esc_html_e( 'Dashboard', 'g2a-booking' ); ?></a>
					<a class="g2ab-sc__navi" data-pane="waivers" href="#"><span class="g2ab-sc__navi-ic">✓</span><?php esc_html_e( 'Waivers', 'g2a-booking' ); ?></a>
					<a class="g2ab-sc__navi" data-pane="scan" href="#"><span class="g2ab-sc__navi-ic">⌖</span><?php esc_html_e( 'QR Scan', 'g2a-booking' ); ?></a>
					<a class="g2ab-sc__navi" data-pane="reservations" href="#"><span class="g2ab-sc__navi-ic">▤</span><?php esc_html_e( 'Reservations', 'g2a-booking' ); ?></a>
					<a class="g2ab-sc__sign-out" href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>"><span class="g2ab-sc__navi-ic">⏏</span><?php esc_html_e( 'Sign out', 'g2a-booking' ); ?></a>
				</nav>
			</aside>

			<main class="g2ab-sc__main">
				<header class="g2ab-sc__h">
					<div>
						<div class="g2ab-sc__crumb"><?php echo esc_html( wp_date( 'l, F j, Y' ) ); ?> · <b><?php echo esc_html( current_time( 'H:i' ) ); ?></b></div>
						<h1 id="g2ab-sc-pane-title"><?php esc_html_e( 'Range Status', 'g2a-booking' ); ?></h1>
					</div>
					<div class="g2ab-sc__h-actions">
						<button class="g2ab-sc__btn" data-open-modal="walkin">⊕ <?php esc_html_e( 'Walk-In Check-In', 'g2a-booking' ); ?></button>
					</div>
				</header>

				<!-- DASHBOARD pane -->
				<section class="g2ab-sc__pane is-cur" data-pane="dashboard">
					<div class="g2ab-sc__kpis" id="g2ab-sc-kpis"></div>
					<div class="g2ab-sc__row">
						<div class="g2ab-sc__panel">
							<h3><?php esc_html_e( 'Live Lane Map', 'g2a-booking' ); ?></h3>
							<div class="g2ab-sc__lanes" id="g2ab-sc-lanes"></div>
							<div class="g2ab-sc__legend">
								<span><i class="g2ab-sc__sw in_use"></i> <?php esc_html_e( 'In use', 'g2a-booking' ); ?></span>
								<span><i class="g2ab-sc__sw reserved"></i> <?php esc_html_e( 'Reserved', 'g2a-booking' ); ?></span>
								<span><i class="g2ab-sc__sw open"></i> <?php esc_html_e( 'Open', 'g2a-booking' ); ?></span>
							</div>
						</div>
						<div class="g2ab-sc__panel">
							<h3><?php esc_html_e( 'Live Feed', 'g2a-booking' ); ?> <span class="g2ab-sc__pill">REALTIME</span></h3>
							<div class="g2ab-sc__feed" id="g2ab-sc-feed"></div>
						</div>
					</div>
				</section>

				<!-- WAIVERS pane -->
				<section class="g2ab-sc__pane" data-pane="waivers" hidden>
					<div class="g2ab-sc__panel">
						<h3><?php esc_html_e( 'Look up a waiver', 'g2a-booking' ); ?></h3>
						<form class="g2ab-sc__search" id="g2ab-sc-waiver-form" autocomplete="off">
							<input type="text" name="q" id="g2ab-sc-waiver-q" class="g2ab-sc__input" placeholder="<?php esc_attr_e( 'Email or First Last', 'g2a-booking' ); ?>" required>
							<button type="submit" class="g2ab-sc__btn"><?php esc_html_e( 'Search', 'g2a-booking' ); ?></button>
						</form>
						<div class="g2ab-sc__hint"><?php esc_html_e( 'Results show waiver status only. No PDFs, no DOB, no personal documents.', 'g2a-booking' ); ?></div>
						<div class="g2ab-sc__results" id="g2ab-sc-waiver-results" aria-live="polite"></div>
					</div>
				</section>

				<!-- SCAN pane -->
				<section class="g2ab-sc__pane" data-pane="scan" hidden>
					<div class="g2ab-sc__panel">
						<h3><?php esc_html_e( 'Scan member card', 'g2a-booking' ); ?></h3>
						<div class="g2ab-sc__scan-wrap">
							<video id="g2ab-sc-video" playsinline muted autoplay></video>
							<canvas id="g2ab-sc-canvas" hidden></canvas>
							<div class="g2ab-sc__scan-overlay">
								<div class="g2ab-sc__scan-frame"></div>
							</div>
						</div>
						<div class="g2ab-sc__scan-actions">
							<button class="g2ab-sc__btn" id="g2ab-sc-scan-start"><?php esc_html_e( 'Start camera', 'g2a-booking' ); ?></button>
							<button class="g2ab-sc__btn g2ab-sc__btn--ghost" id="g2ab-sc-scan-stop" hidden><?php esc_html_e( 'Stop', 'g2a-booking' ); ?></button>
							<span class="g2ab-sc__scan-status" id="g2ab-sc-scan-status"></span>
						</div>
					</div>
				</section>

				<!-- RESERVATIONS pane -->
				<section class="g2ab-sc__pane" data-pane="reservations" hidden>
					<div class="g2ab-sc__panel">
						<h3><?php esc_html_e( "Today's reservations", 'g2a-booking' ); ?></h3>
						<div class="g2ab-sc__roster" id="g2ab-sc-roster"></div>
					</div>
				</section>
			</main>

			<!-- Toast container -->
			<div class="g2ab-sc__toasts" id="g2ab-sc-toasts" aria-live="polite"></div>

			<!-- Walk-in modal template -->
			<div class="g2ab-sc__modal" id="g2ab-sc-modal-walkin" hidden role="dialog" aria-labelledby="g2ab-sc-mw-title">
				<div class="g2ab-sc__modal-back" data-close></div>
				<div class="g2ab-sc__modal-card">
					<header><h3 id="g2ab-sc-mw-title"><?php esc_html_e( 'Walk-In Check-In', 'g2a-booking' ); ?></h3><button class="g2ab-sc__x" data-close aria-label="<?php esc_attr_e( 'Close', 'g2a-booking' ); ?>">✕</button></header>
					<form id="g2ab-sc-walkin-form" autocomplete="off">
						<label><?php esc_html_e( 'Customer name', 'g2a-booking' ); ?>
							<input name="name" type="text" maxlength="80" required>
						</label>
						<div class="g2ab-sc__grid2">
							<label><?php esc_html_e( 'Email (optional)', 'g2a-booking' ); ?>
								<input name="email" type="email" maxlength="120">
							</label>
							<label><?php esc_html_e( 'Phone (optional)', 'g2a-booking' ); ?>
								<input name="phone" type="tel" maxlength="30">
							</label>
						</div>
						<div class="g2ab-sc__grid3">
							<label><?php esc_html_e( 'Lane', 'g2a-booking' ); ?>
								<select name="lane" id="g2ab-sc-walkin-lane" required></select>
							</label>
							<label><?php esc_html_e( 'Shooters', 'g2a-booking' ); ?>
								<input name="party_size" type="number" min="1" max="8" value="1" required>
							</label>
							<label><?php esc_html_e( 'Minutes', 'g2a-booking' ); ?>
								<input name="minutes" type="number" min="30" max="240" step="15" value="60" required>
							</label>
						</div>
						<footer>
							<button type="button" class="g2ab-sc__btn g2ab-sc__btn--ghost" data-close><?php esc_html_e( 'Cancel', 'g2a-booking' ); ?></button>
							<button type="submit" class="g2ab-sc__btn"><?php esc_html_e( 'Check in', 'g2a-booking' ); ?></button>
						</footer>
					</form>
				</div>
			</div>

			<!-- Result modal (success / fail finishing popup) -->
			<div class="g2ab-sc__modal" id="g2ab-sc-modal-result" hidden role="dialog" aria-labelledby="g2ab-sc-mr-title">
				<div class="g2ab-sc__modal-back" data-close></div>
				<div class="g2ab-sc__modal-card g2ab-sc__modal-card--finish">
					<div class="g2ab-sc__finish-ic" id="g2ab-sc-mr-ic">✓</div>
					<h3 id="g2ab-sc-mr-title"></h3>
					<p id="g2ab-sc-mr-body"></p>
					<button class="g2ab-sc__btn" data-close><?php esc_html_e( 'Continue', 'g2a-booking' ); ?></button>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
