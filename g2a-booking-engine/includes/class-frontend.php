<?php
/**
 * Frontend orchestrator.
 *
 * Renders the new "Calendly-style" two-column booking experience:
 *   LEFT  — service info card (title, badges, description, feature bullets).
 *   RIGHT — date calendar + time slots → details form → confirmation.
 *
 * The visual design is driven entirely by saved options (the Form Customizer
 * tab in Settings) so the same plugin can be styled per site without code.
 *
 * Phase 1 shortcodes:
 *   [g2a_lane_booking]      — full booking flow.
 *   [g2a_booking_form]      — alias that routes a saved form/type into the flow.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend {

	private static $instance = null;
	private $enqueued = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2a_lane_booking', array( $this, 'render_lane_booking' ) );
		add_shortcode( 'g2a_ladies_tuesday_booking', array( $this, 'render_ladies_tuesday_booking' ) );
		add_shortcode( 'g2a_classes_booking', array( $this, 'render_classes_booking' ) );
		add_shortcode( 'g2a_resource_booking', array( $this, 'render_resource_booking' ) );
		add_shortcode( 'g2a_booking_form', array( $this, 'render_booking_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_return_assets' ), 20 );
	}

	public function register_assets() {
		wp_register_style(
			'g2ab-frontend',
			G2AB_URL . 'assets/css/frontend.css',
			array(),
			G2AB_VERSION
		);
		wp_register_script(
			'g2ab-frontend',
			G2AB_URL . 'assets/js/frontend.js',
			array(),
			G2AB_VERSION,
			true
		);
	}

	public function maybe_enqueue_return_assets() {
		if ( isset( $_GET['g2ab_paid'] ) || isset( $_GET['g2ab_cancel'] ) || isset( $_GET['g2ab_pay'] ) ) {
			$this->enqueue_assets();
		}
	}

	private function enqueue_assets() {
		if ( $this->enqueued ) {
			return;
		}
		wp_enqueue_style( 'g2ab-frontend' );
		wp_enqueue_script( 'g2ab-frontend' );
		wp_localize_script(
			'g2ab-frontend',
			'G2AB_DATA',
			array(
				'rest_url'       => esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) ),
				'rest_namespace' => G2AB_REST_NAMESPACE,
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'currency'       => get_option( 'g2ab_currency', 'USD' ),
			)
		);
		$this->enqueued = true;
	}

	private function mark_booking_page_dynamic() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
		if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );
		nocache_headers();
	}

	/**
	 * Read all design-token options and return a sanitised tokens array.
	 *
	 * Each token has a sensible default that matches the reference design
	 * (dark gradient, blue accent, rounded corners, Inter typography).
	 */
	private function get_design_tokens() {
		$tokens = array(
			'theme'           => sanitize_key( get_option( 'g2ab_form_theme', 'midnight' ) ),
			'layout'          => sanitize_key( get_option( 'g2ab_form_layout', 'split' ) ),
			'primary'         => $this->sanitize_color( get_option( 'g2ab_form_primary', '#5B7BFF' ) ),
			'accent'          => $this->sanitize_color( get_option( 'g2ab_form_accent', '#7C9CFF' ) ),
			'bg'              => $this->sanitize_color( get_option( 'g2ab_form_bg', '#0B1020' ) ),
			'surface'         => $this->sanitize_color( get_option( 'g2ab_form_surface', '#121833' ) ),
			'surface2'        => $this->sanitize_color( get_option( 'g2ab_form_surface2', '#0E1530' ) ),
			'text'            => $this->sanitize_color( get_option( 'g2ab_form_text', '#FFFFFF' ) ),
			'muted'           => $this->sanitize_color( get_option( 'g2ab_form_muted', '#8B93B4' ) ),
			'border'          => $this->sanitize_color( get_option( 'g2ab_form_border', 'rgba(255,255,255,0.08)' ) ),
			'radius'          => $this->sanitize_radius( get_option( 'g2ab_form_radius', '16' ) ),
			'radius_pill'     => $this->sanitize_radius( get_option( 'g2ab_form_radius_pill', '999' ) ),
			'font'            => sanitize_key( get_option( 'g2ab_form_font', 'inter' ) ),
			'animations'      => (int) get_option( 'g2ab_form_animations', 1 ),
			'show_pricing'    => (int) get_option( 'g2ab_form_show_pricing', 1 ),
			'show_lane_grid'  => (int) get_option( 'g2ab_form_show_lane_grid', 1 ),
			'title_override'  => sanitize_text_field( get_option( 'g2ab_form_title', '' ) ),
			'subtitle'        => sanitize_text_field( get_option( 'g2ab_form_subtitle', '' ) ),
			'description'     => wp_kses_post( get_option( 'g2ab_form_description', '' ) ),
			'features'        => array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) get_option( 'g2ab_form_features', "Instant confirmation email\nReschedule or cancel anytime\nNo spam — just your booking" ) ) ) ),
			'badge_duration'  => (int) get_option( 'g2ab_form_badge_duration', 1 ),
			'badge_price'     => (int) get_option( 'g2ab_form_badge_price', 1 ),
			'continue_label'  => sanitize_text_field( get_option( 'g2ab_form_continue_label', __( 'Continue', 'g2a-booking' ) ) ),
			'submit_label'    => sanitize_text_field( get_option( 'g2ab_form_submit_label', '' ) ),
			'shadow'          => sanitize_text_field( get_option( 'g2ab_form_shadow', '0 30px 80px rgba(8,12,32,0.45)' ) ),
			'support_notice'  => wp_kses_post( get_option( 'g2ab_form_support_notice', self::default_support_notice() ) ),
		);
		return apply_filters( 'g2ab_form_design_tokens', $tokens );
	}

	/**
	 * Default copy for the support note rendered under the booking widget.
	 * Editable (or blankable to hide) via Settings → Form Customizer
	 * (g2ab_form_support_notice).
	 */
	public static function default_support_notice() {
		return 'Having trouble booking your lane? Send us a quick message and our team will take care of the rest '
			. '— we want your experience at Guns 2 Ammo to be effortless from the first click to the firing line. '
			. 'Thanks for choosing Guns 2 Ammo — your range partner. '
			. '<a href="/contact/">Contact Us</a>';
	}

	private function sanitize_color( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return '';
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+(\s*,\s*[\d.]+)?\s*\)$/', $value ) ) {
			return $value;
		}
		return '';
	}

	private function sanitize_radius( $value ) {
		$value = preg_replace( '/[^0-9.]/', '', (string) $value );
		if ( '' === $value ) return '16';
		return (string) min( 999, max( 0, (float) $value ) );
	}

	private function font_family_for( $key ) {
		$stacks = array(
			'system'  => '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
			'inter'   => '"Inter","Segoe UI",Roboto,Helvetica,Arial,sans-serif',
			'manrope' => '"Manrope","Inter","Segoe UI",sans-serif',
			'rubik'   => '"Rubik","Inter","Segoe UI",sans-serif',
			'dmsans'  => '"DM Sans","Inter","Segoe UI",sans-serif',
		);
		return $stacks[ $key ] ?? $stacks['inter'];
	}

	private function font_link_for( $key ) {
		$urls = array(
			'inter'   => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
			'manrope' => 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap',
			'rubik'   => 'https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700&display=swap',
			'dmsans'  => 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap',
		);
		return $urls[ $key ] ?? '';
	}

	private function resource_type_for_booking_type( $booking_type ) {
		$settings = isset( $booking_type->settings ) ? json_decode( (string) $booking_type->settings, true ) : array();
		$configured = is_array( $settings ) && ! empty( $settings['resource_type'] ) ? sanitize_key( $settings['resource_type'] ) : '';
		if ( in_array( $configured, array( 'lane', 'classroom', 'instructor', 'package' ), true ) ) {
			return $configured;
		}
		$category = isset( $booking_type->category ) ? sanitize_key( $booking_type->category ) : 'lane';
		return 'class' === $category ? 'classroom' : 'lane';
	}

	private function resource_label_for_type( $type ) {
		$labels = array(
			'classroom'  => __( 'Classroom', 'g2a-booking' ),
			'instructor' => __( 'Instructor', 'g2a-booking' ),
			'package'    => __( 'Package', 'g2a-booking' ),
			'lane'       => __( 'Lane', 'g2a-booking' ),
		);
		return $labels[ $type ] ?? __( 'Resource', 'g2a-booking' );
	}

	private function get_form_for_booking_type( $booking_type, $form_slug = '' ) {
		global $wpdb;
		$form_table = $wpdb->prefix . 'g2ab_forms';
		if ( $form_slug ) {
			$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$form_table} WHERE slug = %s AND is_active = 1", $form_slug ) );
			if ( $form ) return $form;
		}
		if ( ! empty( $booking_type->form_id ) ) {
			$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$form_table} WHERE id = %d AND is_active = 1", (int) $booking_type->form_id ) );
			if ( $form ) return $form;
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$form_table} WHERE slug = %s AND is_active = 1", 'default-lane-booking' ) );
	}

	private function display_pricing_for_booking_type( $booking_type ) {
		$subtotal = round( (float) $booking_type->base_price, 2 );
		$pricing  = array(
			'subtotal'        => $subtotal,
			'discount_amount' => 0.0,
			'total'           => $subtotal,
			'discount_label'  => '',
		);
		$pricing = apply_filters( 'g2ab_booking_display_pricing', $pricing, $booking_type, 1, get_current_user_id() );
		$pricing['subtotal']        = max( 0, round( (float) ( $pricing['subtotal'] ?? $subtotal ), 2 ) );
		$pricing['discount_amount'] = max( 0, min( $pricing['subtotal'], round( (float) ( $pricing['discount_amount'] ?? 0 ), 2 ) ) );
		$pricing['total']           = max( 0, round( (float) ( $pricing['total'] ?? ( $pricing['subtotal'] - $pricing['discount_amount'] ) ), 2 ) );
		$pricing['discount_label']  = sanitize_text_field( $pricing['discount_label'] ?? '' );
		return $pricing;
	}

	/**
	 * Main shortcode renderer.
	 */
	public function render_lane_booking( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'booking_type' => 'lane-booking',
				'form'         => 'default-lane-booking',
				'theme'        => '',
				// Event-driven mode: when source="events", the calendar only
				// allows dates/times that have a published Event of event_type.
				'source'       => '',
				'event_type'   => '',
			),
			$atts,
			'g2a_lane_booking'
		);

		$event_source = ( 'events' === sanitize_key( $atts['source'] ) );
		$event_type   = sanitize_key( $atts['event_type'] );

		$this->mark_booking_page_dynamic();
		$this->enqueue_assets();

		global $wpdb;

		$bt_table     = $wpdb->prefix . 'g2ab_booking_types';
		$booking_type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt_table} WHERE slug = %s AND is_active = 1", $atts['booking_type'] ) );
		if ( ! $booking_type ) {
			return $this->render_error( __( 'Booking type not configured. Visit G2A Booking → Booking Types.', 'g2a-booking' ) );
		}

		$requested      = $booking_type;
		$viewer_member  = $this->current_user_is_member();
		$requested_only = ! empty( $requested->members_only );
		if ( $requested_only && ! $viewer_member ) {
			$public = $this->get_public_lane_booking_type();
			if ( $public ) {
				$booking_type = $public;
			}
		}

		$resource_type  = $this->resource_type_for_booking_type( $booking_type );
		$resource_label = $this->resource_label_for_type( $resource_type );

		// Event-gating may also be declared on the booking type's own settings
		// (or by the bundled ladies-tuesday convention), so a plain
		// [g2a_lane_booking booking_type="ladies-tuesday"] gates too. The
		// shortcode `source="events"` attribute always wins when present.
		if ( ! $event_source ) {
			$bt_settings = isset( $booking_type->settings ) ? json_decode( (string) $booking_type->settings, true ) : array();
			$bt_settings = is_array( $bt_settings ) ? $bt_settings : array();
			if ( ! empty( $bt_settings['event_source'] ) && 'events' === sanitize_key( (string) $bt_settings['event_source'] ) ) {
				$event_source = true;
				$event_type   = $event_type ?: sanitize_key( (string) ( $bt_settings['event_type'] ?? '' ) );
			} elseif ( 'ladies-tuesday' === sanitize_title( (string) $booking_type->slug ) ) {
				$event_source = true;
				$event_type   = $event_type ?: 'ladies-day';
			}
		}

		$resources = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, slug, capacity FROM {$wpdb->prefix}g2ab_resources WHERE type = %s AND is_active = 1 ORDER BY sort_order ASC, name ASC",
			$resource_type
		) );
		if ( empty( $resources ) ) {
			return $this->render_error( sprintf(
				/* translators: %s resource label */
				__( 'No %s resources are configured. Visit G2A Booking → Resources.', 'g2a-booking' ),
				strtolower( $resource_label )
			) );
		}

		$form         = $this->get_form_for_booking_type( $booking_type, $atts['form'] );
		$display_pric = $this->display_pricing_for_booking_type( $booking_type );

		$fields = array();
		if ( $form ) {
			$fields = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}g2ab_form_fields WHERE form_id = %d ORDER BY sort_order ASC",
				(int) $form->id
			) );
		}

		$tokens      = $this->get_design_tokens();
		$override    = $atts['theme'] ? sanitize_key( $atts['theme'] ) : $tokens['theme'];
		$tokens['theme'] = $override;

		$instance_id = 'g2ab-' . substr( md5( uniqid( 'g2ab', true ) ), 0, 12 );
		$today       = current_time( 'Y-m-d' );
		$max_days    = (int) get_option( 'g2ab_max_booking_advance_days', 90 );

		// Optional Google font link (rendered once per page).
		$font_url = $this->font_link_for( $tokens['font'] );
		if ( $font_url ) {
			static $printed = array();
			if ( empty( $printed[ $tokens['font'] ] ) ) {
				printf( '<link rel="stylesheet" href="%s" />', esc_url( $font_url ) );
				$printed[ $tokens['font'] ] = true;
			}
		}

		$service_title    = $tokens['title_override'] ?: $booking_type->name;
		$service_subtitle = $tokens['subtitle'] ?: '';
		$service_desc     = $tokens['description'] ?: '';
		$price_total      = (float) $display_pric['total'];
		$price_label      = $price_total <= 0 ? __( 'FREE', 'g2a-booking' ) : sprintf( '$%s', number_format( $price_total, 2 ) );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $instance_id ); ?>"
			class="g2ab g2ab-booking g2ab-theme-<?php echo esc_attr( $tokens['theme'] ); ?> g2ab-layout-<?php echo esc_attr( $tokens['layout'] ); ?> <?php echo $tokens['animations'] ? 'g2ab-animate' : 'g2ab-static'; ?>"
			data-booking-type-id="<?php echo (int) $booking_type->id; ?>"
			data-form-id="<?php echo $form ? (int) $form->id : 0; ?>"
			data-resource-label="<?php echo esc_attr( $resource_label ); ?>"
			data-resource-type="<?php echo esc_attr( $resource_type ); ?>"
			data-duration="<?php echo (int) $booking_type->duration_min; ?>"
			data-today="<?php echo esc_attr( $today ); ?>"
			data-max-days="<?php echo (int) $max_days; ?>"
			data-event-source="<?php echo $event_source ? '1' : '0'; ?>"
			data-event-type="<?php echo esc_attr( $event_type ); ?>"
			style="<?php echo esc_attr( $this->build_css_vars( $tokens ) ); ?>">

			<div class="g2ab-shell">

				<!-- LEFT: service info card -->
				<aside class="g2ab-aside">
					<div class="g2ab-card g2ab-aside__card">
						<div class="g2ab-aside__badges">
							<?php if ( $tokens['badge_duration'] ) : ?>
								<span class="g2ab-pill"><?php echo esc_html( (int) $booking_type->duration_min . ' ' . __( 'MIN', 'g2a-booking' ) ); ?></span>
							<?php endif; ?>
							<?php if ( $tokens['badge_price'] ) : ?>
								<span class="g2ab-pill g2ab-pill--accent"><?php echo esc_html( $price_label ); ?></span>
							<?php endif; ?>
						</div>

						<h2 class="g2ab-aside__title"><?php echo esc_html( $service_title ); ?></h2>

						<?php if ( $service_subtitle ) : ?>
							<p class="g2ab-aside__subtitle"><?php echo esc_html( $service_subtitle ); ?></p>
						<?php endif; ?>

						<?php if ( $service_desc ) : ?>
							<div class="g2ab-aside__desc"><?php echo wp_kses_post( wpautop( $service_desc ) ); ?></div>
						<?php endif; ?>

						<?php if ( ! empty( $tokens['features'] ) ) : ?>
							<ul class="g2ab-aside__features">
								<?php foreach ( $tokens['features'] as $feature ) : ?>
									<li>
										<span class="g2ab-dot" aria-hidden="true"></span>
										<span><?php echo esc_html( $feature ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( $tokens['show_pricing'] && (float) $display_pric['discount_amount'] > 0 ) : ?>
							<p class="g2ab-aside__discount-note">
								<?php
								printf(
									/* translators: 1: original price, 2: discount label */
									esc_html__( 'Original $%1$s. %2$s applied.', 'g2a-booking' ),
									esc_html( number_format( (float) $display_pric['subtotal'], 2 ) ),
									esc_html( $display_pric['discount_label'] ?: __( 'Member discount', 'g2a-booking' ) )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</aside>

				<!-- RIGHT: booking stage -->
				<main class="g2ab-stage">

					<!-- ─────────────────────────────────────────────────
					     STAGE 1 — Pick a time
					     ───────────────────────────────────────────────── -->
					<section class="g2ab-stage__panel is-active" data-stage="time" aria-labelledby="<?php echo esc_attr( $instance_id ); ?>-stage-title">
						<header class="g2ab-stage__head">
							<h3 id="<?php echo esc_attr( $instance_id ); ?>-stage-title" class="g2ab-stage__title"><?php esc_html_e( 'Choose a date &amp; time', 'g2a-booking' ); ?></h3>
							<p class="g2ab-stage__meta">
								<?php
								/* translators: %s site timezone */
								printf( esc_html__( 'Times shown in %s', 'g2a-booking' ), esc_html( wp_timezone_string() ) );
								?>
							</p>
						</header>

						<?php if ( count( $resources ) > 1 && $tokens['show_lane_grid'] ) : ?>
							<div class="g2ab-resource-row">
								<label class="g2ab-field-label" for="<?php echo esc_attr( $instance_id ); ?>-resource">
									<?php /* translators: %s resource label */ printf( esc_html__( 'Select %s', 'g2a-booking' ), esc_html( $resource_label ) ); ?>
								</label>
								<div class="g2ab-resource-grid">
									<?php foreach ( $resources as $idx => $res ) : ?>
										<button type="button"
											class="g2ab-chip<?php echo 0 === $idx ? ' is-selected' : ''; ?>"
											data-resource-id="<?php echo (int) $res->id; ?>"
											data-resource-name="<?php echo esc_attr( $res->name ); ?>">
											<?php echo esc_html( $res->name ); ?>
										</button>
									<?php endforeach; ?>
								</div>
							</div>
						<?php else : ?>
							<input type="hidden" data-single-resource-id value="<?php echo (int) $resources[0]->id; ?>" />
							<input type="hidden" data-single-resource-name value="<?php echo esc_attr( $resources[0]->name ); ?>" />
						<?php endif; ?>

						<div class="g2ab-pick">
							<div class="g2ab-pick__cal">
								<div class="g2ab-cal" data-calendar>
									<header class="g2ab-cal__head">
										<button type="button" class="g2ab-cal__nav" data-cal-prev aria-label="<?php esc_attr_e( 'Previous month', 'g2a-booking' ); ?>">‹</button>
										<div class="g2ab-cal__title" data-cal-title></div>
										<button type="button" class="g2ab-cal__nav" data-cal-next aria-label="<?php esc_attr_e( 'Next month', 'g2a-booking' ); ?>">›</button>
									</header>
									<div class="g2ab-cal__dow">
										<?php
										$dow_labels = array( __( 'Mon', 'g2a-booking' ), __( 'Tue', 'g2a-booking' ), __( 'Wed', 'g2a-booking' ), __( 'Thu', 'g2a-booking' ), __( 'Fri', 'g2a-booking' ), __( 'Sat', 'g2a-booking' ), __( 'Sun', 'g2a-booking' ) );
										foreach ( $dow_labels as $label ) {
											echo '<span>' . esc_html( strtoupper( $label ) ) . '</span>';
										}
										?>
									</div>
									<div class="g2ab-cal__grid" data-cal-grid></div>
								</div>
							</div>
							<div class="g2ab-pick__slots">
								<p class="g2ab-pick__hint" data-slots-hint><?php esc_html_e( 'Pick a date to see available times', 'g2a-booking' ); ?></p>
								<div class="g2ab-slots-notice" data-slots-notice hidden role="status" aria-live="polite"></div>
								<div class="g2ab-slots" data-slots></div>
							</div>
						</div>

						<footer class="g2ab-stage__foot">
							<div class="g2ab-stage__summary" data-summary-pill hidden></div>
							<button type="button" class="g2ab-btn g2ab-btn--primary" data-go-form disabled>
								<?php echo esc_html( $tokens['continue_label'] ); ?>
							</button>
						</footer>
					</section>

					<!-- ─────────────────────────────────────────────────
					     STAGE 2 — Your details
					     ───────────────────────────────────────────────── -->
					<section class="g2ab-stage__panel" data-stage="form">
						<header class="g2ab-stage__head">
							<button type="button" class="g2ab-back" data-back-time>
								<span aria-hidden="true">‹</span> <?php esc_html_e( 'Back', 'g2a-booking' ); ?>
							</button>
							<h3 class="g2ab-stage__title"><?php esc_html_e( 'Your details', 'g2a-booking' ); ?></h3>
							<p class="g2ab-stage__meta" data-summary-line></p>
						</header>

						<form class="g2ab-form" novalidate
							action="javascript:void(0);"
							method="post"
							onsubmit="return false;"
							data-g2ab-form="1">
							<?php $this->render_form_fields( $fields ); ?>

							<div class="g2ab-form__error" data-form-error hidden></div>

							<footer class="g2ab-stage__foot">
								<button type="button" class="g2ab-btn g2ab-btn--ghost" data-back-time>
									<?php esc_html_e( 'Back', 'g2a-booking' ); ?>
								</button>
								<button type="submit" class="g2ab-btn g2ab-btn--primary">
									<?php echo esc_html( $tokens['submit_label'] ?: sprintf( /* translators: %s resource label */ __( 'Reserve my %s', 'g2a-booking' ), strtolower( $resource_label ) ) ); ?>
								</button>
							</footer>
						</form>
					</section>

					<!-- ─────────────────────────────────────────────────
					     STAGE 3 — Done
					     ───────────────────────────────────────────────── -->
					<section class="g2ab-stage__panel" data-stage="done">
						<div class="g2ab-done">
							<canvas class="g2ab-done__confetti" data-confetti aria-hidden="true"></canvas>
							<div class="g2ab-done__check" aria-hidden="true">
								<svg viewBox="0 0 64 64" width="64" height="64"><circle cx="32" cy="32" r="30" fill="none" stroke="currentColor" stroke-width="3"/><path d="M20 33 L29 42 L45 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</div>
							<h3 class="g2ab-done__title"><?php esc_html_e( 'Booking confirmed', 'g2a-booking' ); ?></h3>
							<p class="g2ab-done__msg" data-done-msg></p>
							<div class="g2ab-done__recap" data-done-recap hidden>
								<div class="g2ab-done__recap-row"><span class="g2ab-done__recap-lbl"><?php esc_html_e( 'Lane', 'g2a-booking' ); ?></span><span class="g2ab-done__recap-val" data-done-resource></span></div>
								<div class="g2ab-done__recap-row"><span class="g2ab-done__recap-lbl"><?php esc_html_e( 'When', 'g2a-booking' ); ?></span><span class="g2ab-done__recap-val" data-done-when></span></div>
								<div class="g2ab-done__recap-row"><span class="g2ab-done__recap-lbl"><?php esc_html_e( 'Party', 'g2a-booking' ); ?></span><span class="g2ab-done__recap-val" data-done-party></span></div>
							</div>
							<p class="g2ab-done__id"><?php esc_html_e( 'Confirmation:', 'g2a-booking' ); ?> <code data-done-uuid></code></p>
							<div class="g2ab-done__actions">
								<button type="button" class="g2ab-btn g2ab-btn--primary g2ab-done__again" data-action="book-another">
									<span class="g2ab-done__again-icon" aria-hidden="true">
										<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
									</span>
									<span><?php esc_html_e( 'Book Another Lane', 'g2a-booking' ); ?></span>
								</button>
								<a class="g2ab-btn g2ab-btn--ghost g2ab-done__home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Done', 'g2a-booking' ); ?></a>
							</div>
						</div>
					</section>

				</main>
			</div>

			<?php if ( '' !== trim( wp_strip_all_tags( $tokens['support_notice'] ) ) ) : ?>
				<aside class="g2ab-support-note">
					<span class="g2ab-support-note__icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
					</span>
					<div class="g2ab-support-note__body"><?php echo wp_kses_post( wpautop( $tokens['support_notice'] ) ); ?></div>
				</aside>
			<?php endif; ?>
		</div>
		<?php $this->render_inline_bootstrap( $instance_id ); ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build a `style=""` attribute value with CSS custom properties.
	 */
	private function build_css_vars( $tokens ) {
		// The `site` and `ladies` skins define their full palette in CSS
		// (so they can react to the host theme's light/dark mode). Inline
		// color vars would defeat that cascade — emit shape/typography only.
		$inherits = in_array( $tokens['theme'], array( 'site', 'ladies' ), true );
		$vars = $inherits ? array(
			'--g2ab-radius'      => $tokens['radius'] . 'px',
			'--g2ab-radius-pill' => $tokens['radius_pill'] . 'px',
		) : array(
			'--g2ab-primary'  => $tokens['primary'],
			'--g2ab-accent'   => $tokens['accent'],
			'--g2ab-bg'       => $tokens['bg'],
			'--g2ab-surface'  => $tokens['surface'],
			'--g2ab-surface2' => $tokens['surface2'],
			'--g2ab-text'     => $tokens['text'],
			'--g2ab-muted'    => $tokens['muted'],
			'--g2ab-border'   => $tokens['border'],
			'--g2ab-radius'   => $tokens['radius'] . 'px',
			'--g2ab-radius-pill' => $tokens['radius_pill'] . 'px',
			'--g2ab-shadow'   => $tokens['shadow'],
			'--g2ab-font'     => $this->font_family_for( $tokens['font'] ),
		);
		$out = '';
		foreach ( $vars as $k => $v ) {
			if ( '' !== $v ) {
				$out .= $k . ':' . str_replace( array( ';', '"' ), '', (string) $v ) . ';';
			}
		}
		return $out;
	}

	/**
	 * Form fields renderer — unchanged structure, restyled by the new CSS.
	 */
	private function render_form_fields( $fields ) {
		if ( empty( $fields ) ) {
			echo '<p class="g2ab-muted">' . esc_html__( 'No form fields configured.', 'g2a-booking' ) . '</p>';
			return;
		}

		foreach ( $fields as $field ) {
			$key      = sanitize_key( $field->field_key );
			$type     = sanitize_key( $field->field_type );
			$label    = esc_html( $field->label );
			$required = (int) $field->is_required ? 'required' : '';
			$req_mark = (int) $field->is_required ? ' <span class="g2ab-req">*</span>' : '';
			$id       = 'g2ab-f-' . $key;
			$name     = 'fields[' . $key . ']';
			$ph       = $field->placeholder ? esc_attr( $field->placeholder ) : '';
			$help     = $field->help_text ? '<small class="g2ab-help">' . esc_html( $field->help_text ) . '</small>' : '';
			$opts     = json_decode( (string) $field->options, true );
			$opts     = is_array( $opts ) ? $opts : array();

			echo '<div class="g2ab-field g2ab-field--' . esc_attr( $type ) . '">';

			switch ( $type ) {
				case 'text':
				case 'email':
				case 'phone':
				case 'number':
					$input_type = 'phone' === $type ? 'tel' : $type;
					echo '<label class="g2ab-field-label" for="' . esc_attr( $id ) . '">' . $label . $req_mark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="g2ab-input" placeholder="' . $ph . '" ' . esc_attr( $required ) . ' />';
					echo $help; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'date':
				case 'time':
					echo '<label class="g2ab-field-label" for="' . esc_attr( $id ) . '">' . $label . $req_mark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="g2ab-input" ' . esc_attr( $required ) . ' />';
					break;
				case 'dropdown':
					echo '<label class="g2ab-field-label" for="' . esc_attr( $id ) . '">' . $label . $req_mark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="g2ab-input" ' . esc_attr( $required ) . '>';
					echo '<option value="">' . esc_html__( '— Select —', 'g2a-booking' ) . '</option>';
					foreach ( $opts as $opt ) {
						$v = $opt['value'] ?? '';
						$l = $opt['label'] ?? '';
						echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
					}
					echo '</select>';
					break;
				case 'radio':
					echo '<fieldset><legend class="g2ab-field-label">' . $label . $req_mark . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					foreach ( $opts as $opt ) {
						$v = $opt['value'] ?? '';
						$l = $opt['label'] ?? '';
						echo '<label class="g2ab-radio"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" ' . esc_attr( $required ) . ' /> ' . esc_html( $l ) . '</label>';
					}
					echo '</fieldset>';
					break;
				case 'checkbox':
				case 'waiver':
				case 'terms':
					echo '<label class="g2ab-checkbox"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . esc_attr( $required ) . ' /> <span>' . $label . $req_mark . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'hidden':
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $field->placeholder ?? '' ) . '" />';
					break;
				default:
					echo '<label class="g2ab-field-label" for="' . esc_attr( $id ) . '">' . $label . $req_mark . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="g2ab-input" />';
			}
			echo '</div>';
		}
	}

	/**
	 * Alias shortcode that routes a saved form/booking-type into the flow.
	 */
	public function render_booking_form( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'id'           => 0,
				'type'         => '',
				'booking_type' => '',
				'form'         => '',
				'theme'        => '',
			),
			$atts,
			'g2a_booking_form'
		);

		global $wpdb;
		$form_slug    = sanitize_title( $atts['form'] );
		$booking_slug = sanitize_title( $atts['booking_type'] ?: $atts['type'] );

		if ( ! $form_slug && (int) $atts['id'] > 0 ) {
			$form = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}g2ab_forms WHERE id = %d AND is_active = 1",
				(int) $atts['id']
			) );
			if ( $form ) $form_slug = $form->slug;
		}

		if ( ! $booking_slug && (int) $atts['id'] > 0 ) {
			$bt = $wpdb->get_row( $wpdb->prepare(
				"SELECT slug FROM {$wpdb->prefix}g2ab_booking_types WHERE form_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1",
				(int) $atts['id']
			) );
			if ( $bt ) $booking_slug = $bt->slug;
		}

		return $this->render_lane_booking( array(
			'booking_type' => $booking_slug ?: 'lane-booking',
			'form'         => $form_slug ?: 'default-lane-booking',
			'theme'        => sanitize_key( $atts['theme'] ),
		) );
	}

	public function render_ladies_tuesday_booking( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'booking_type' => 'ladies-tuesday',
				'form'         => 'ladies-tuesday-booking',
				'theme'        => '',
				// Ladies Tuesday is event-gated by default: bookings are only
				// offered on dates/times that have a published "ladies-day"
				// Event. Override with source="" to fall back to the open grid.
				'source'       => 'events',
				'event_type'   => 'ladies-day',
			),
			$atts,
			'g2a_ladies_tuesday_booking'
		);
		return $this->render_lane_booking( $atts );
	}

	public function render_classes_booking( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'booking_type' => 'ccw-class',
				'form'         => 'default-class-booking',
				'theme'        => '',
			),
			$atts,
			'g2a_classes_booking'
		);
		return $this->render_lane_booking( $atts );
	}

	public function render_resource_booking( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'booking_type' => 'resource-booking',
				'form'         => 'default-resource-booking',
				'theme'        => '',
			),
			$atts,
			'g2a_resource_booking'
		);
		return $this->render_lane_booking( $atts );
	}

	private function render_error( $msg ) {
		return '<div class="g2ab g2ab-error"><p>' . esc_html( $msg ) . '</p></div>';
	}

	private function current_user_is_member() {
		$user_id = get_current_user_id();
		$allowed = false;
		if ( $user_id ) {
			if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
				$allowed = (bool) pmpro_hasMembershipLevel( null, $user_id );
			} elseif ( function_exists( 'memberistic_user_has_active_membership' ) ) {
				$allowed = (bool) memberistic_user_has_active_membership( $user_id );
			} elseif ( get_user_meta( $user_id, 'memberistic_active_plan_id', true ) ) {
				$allowed = true;
			} else {
				$allowed = current_user_can( 'manage_g2ab_bookings' );
			}
		}
		return (bool) apply_filters( 'g2ab_user_is_member', $allowed, $user_id, (object) array( 'members_only' => 1 ), '' );
	}

	private function get_public_lane_booking_type() {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}g2ab_booking_types WHERE slug = %s AND is_active = 1 AND members_only = 0 LIMIT 1",
			'lane-booking'
		) );
	}

	/**
	 * Per-instance inline JS. Selects its root by id so page-builders and
	 * caching plugins can't break the binding by inserting nodes between
	 * the wrapper and the script.
	 */
	private function render_inline_bootstrap( $instance_id ) {
		$config = array(
			'rest_url'    => esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'instance_id' => $instance_id,
			'i18n'        => array(
				'loading'  => __( 'Loading available times…', 'g2a-booking' ),
				'no_slots' => __( 'No times available on this date.', 'g2a-booking' ),
				'load_failed' => __( 'We couldn\'t load times just now — please refresh the page and try again.', 'g2a-booking' ),
				'closed'   => __( 'Closed on this date.', 'g2a-booking' ),
				'submitting' => __( 'Reserving…', 'g2a-booking' ),
				'failed'   => __( 'Could not complete booking. Please try again.', 'g2a-booking' ),
				'pick_first' => __( 'Please choose a date and time first.', 'g2a-booking' ),
				'loading_dates' => __( 'Loading available dates…', 'g2a-booking' ),
				'no_events'  => __( 'No upcoming dates are open right now — please check back soon.', 'g2a-booking' ),
				'pick_event' => __( 'Pick a highlighted date to see available times', 'g2a-booking' ),
				/* translators: 1: number of available slots, 2: human date */
				'slots_available'    => __( '%1$s time slots available on %2$s', 'g2a-booking' ),
				/* translators: %s human date */
				'slot_available_one' => __( '1 time slot available on %s', 'g2a-booking' ),
				'fully_booked'       => __( 'Fully booked for this date — try another day', 'g2a-booking' ),
				'months'   => array(
					__( 'January', 'g2a-booking' ), __( 'February', 'g2a-booking' ), __( 'March', 'g2a-booking' ), __( 'April', 'g2a-booking' ),
					__( 'May', 'g2a-booking' ), __( 'June', 'g2a-booking' ), __( 'July', 'g2a-booking' ), __( 'August', 'g2a-booking' ),
					__( 'September', 'g2a-booking' ), __( 'October', 'g2a-booking' ), __( 'November', 'g2a-booking' ), __( 'December', 'g2a-booking' ),
				),
			),
		);
		?>
		<script>
		// ── Global safety net (runs once per page) ─────────────────────────
		// Catches submit on any .g2ab-form even if the per-instance bootstrap
		// below never runs (page-builder removes the script, JS minifier
		// breaks the IIFE, etc). Without this, the form would submit as
		// GET and dump all fields into the URL.
		//
		// IMPORTANT: only preventDefault() here — never stopPropagation().
		// This is a capture-phase listener, so stopPropagation() would block
		// the event from ever reaching the per-instance submit handler below
		// and the "Reserve" button would silently do nothing.
		if (!window.__g2abFormGuard) {
			window.__g2abFormGuard = true;
			document.addEventListener('submit', function(e){
				var form = e.target;
				if (form && form.nodeType === 1 && (form.classList.contains('g2ab-form') || form.getAttribute('data-g2ab-form') === '1')) {
					e.preventDefault();
				}
			}, true);
		}

		(function(config){
			var root = config.instance_id ? document.getElementById(config.instance_id) : null;
			if (!root || !root.classList || !root.classList.contains('g2ab-booking') || root.dataset.g2abBooted) return;
			root.dataset.g2abBooted = '1';

			var $ = function(sel, ctx){ return (ctx || root).querySelector(sel); };
			var $$ = function(sel, ctx){ return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); };
			// Public GET endpoints must NOT carry a nonce: pages are often served
			// from a page cache, so the nonce baked into the HTML can be stale —
			// and WordPress core rejects ANY REST request bearing an invalid
			// X-WP-Nonce with a 403 before the route even runs. That is what made
			// logged-out guests see "No times available" on every date while
			// logged-in users (who bypass the page cache) saw slots normally.
			function headers(hasBody){ var h = {}; if (hasBody !== false) { h['Content-Type'] = 'application/json'; if (config.nonce) h['X-WP-Nonce'] = config.nonce; } return h; }
			// Fetch a fresh wp_rest nonce just-in-time (used before POSTs so a
			// cached page's stale nonce can never fail the booking submit).
			function freshNonce(){
				return fetch(config.rest_url + 'session', { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
					.then(function(r){ return r.json(); })
					.then(function(json){
						if (json && json.success && json.data && json.data.nonce) { config.nonce = json.data.nonce; }
						return config.nonce;
					})
					.catch(function(){ return config.nonce; });
			}
			function pad(n){ return n < 10 ? '0'+n : ''+n; }
			function ymd(d){ return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }

			// ── state ───────────────────────────────────────────────
			var singleId = root.querySelector('[data-single-resource-id]');
			var singleName = root.querySelector('[data-single-resource-name]');
			var firstChip = root.querySelector('.g2ab-chip.is-selected');
			var state = {
				resourceId: singleId ? parseInt(singleId.value, 10) : (firstChip ? parseInt(firstChip.dataset.resourceId, 10) : 0),
				resourceName: singleName ? singleName.value : (firstChip ? firstChip.dataset.resourceName : ''),
				date: '',
				slot: null,
				viewYear: 0,
				viewMonth: 0
			};

			// ── event-driven mode ───────────────────────────────────
			// When data-event-source="1" the calendar only unlocks dates that
			// have a published Event of data-event-type, and time slots come
			// straight from each event's window (not the open business-hours
			// grid). Used by Ladies Tuesday.
			var eventMode    = root.dataset.eventSource === '1';
			var eventType    = root.dataset.eventType || '';
			var eventDates   = null;   // Set of 'YYYY-MM-DD' strings, null until loaded.
			var eventByDate  = {};     // { 'YYYY-MM-DD': { title, slots:[…] } }
			var eventLoading = false;

			// ── stage navigation ────────────────────────────────────
			function showStage(name){
				$$('.g2ab-stage__panel').forEach(function(p){
					var active = p.dataset.stage === name;
					p.classList.toggle('is-active', active);
				});
			}

			// ── calendar render ─────────────────────────────────────
			function renderCalendar(){
				var grid = $('[data-cal-grid]');
				var titleEl = $('[data-cal-title]');
				if (!grid || !titleEl) return;
				var months = config.i18n.months;
				var year = state.viewYear, month = state.viewMonth;
				titleEl.textContent = months[month] + ' ' + year;

				// Build day list. Week starts Monday (locale-friendly default for ranges).
				var first = new Date(year, month, 1);
				var startDay = (first.getDay() + 6) % 7; // 0 = Mon
				var daysInMonth = new Date(year, month + 1, 0).getDate();
				var today = root.dataset.today;
				var maxDays = parseInt(root.dataset.maxDays || '90', 10);
				var maxDate = new Date();
				maxDate.setDate(maxDate.getDate() + maxDays);

				var html = '';
				for (var i = 0; i < startDay; i++) {
					html += '<button class="g2ab-cal__cell is-empty" type="button" disabled></button>';
				}
				for (var d = 1; d <= daysInMonth; d++) {
					var cellDate = new Date(year, month, d);
					var iso = ymd(cellDate);
					var isPast = iso < today;
					var isTooFar = cellDate > maxDate;
					var disabled = isPast || isTooFar;
					var isEvent = false;
					if (eventMode) {
						// In event mode a date is bookable only if it's in the
						// loaded event set. Until the set loads, everything is
						// locked so users can't click a date with no times.
						isEvent = eventDates ? eventDates.has(iso) : false;
						if (!isEvent) disabled = true;
					}
					var selected = iso === state.date;
					var cls = 'g2ab-cal__cell' + (disabled ? ' is-disabled' : ' is-clickable') + (selected ? ' is-selected' : '') + (isEvent ? ' is-event' : '');
					html += '<button class="' + cls + '" type="button" data-date="' + iso + '"' + (disabled ? ' disabled' : '') + '>' + d + '</button>';
				}
				grid.innerHTML = html;
			}

			function shiftMonth(delta){
				var d = new Date(state.viewYear, state.viewMonth + delta, 1);
				state.viewYear = d.getFullYear();
				state.viewMonth = d.getMonth();
				renderCalendar();
			}

			// ── event-map loading (event mode only) ─────────────────
			// Pulls the dates + per-date slot windows for the configured event
			// type once per resource, then re-renders the calendar and jumps
			// to the first month that has a bookable date.
			function loadEventMap(done){
				if (!eventMode || !state.resourceId) { if (done) done(); return; }
				eventLoading = true;
				var hint = $('[data-slots-hint]');
				if (hint) { hint.style.display = ''; hint.textContent = config.i18n.loading_dates || config.i18n.loading; }
				var url = config.rest_url + 'event-availability'
					+ '?resource_id=' + encodeURIComponent(state.resourceId)
					+ '&event_type=' + encodeURIComponent(eventType)
					+ '&booking_type_id=' + encodeURIComponent(parseInt(root.dataset.bookingTypeId, 10) || 0);
				fetch(url, { headers: headers(false) })
					.then(function(r){ return r.json(); })
					.then(function(json){
						var data = (json && json.success) ? json.data : null;
						eventByDate = (data && data.by_date && typeof data.by_date === 'object') ? data.by_date : {};
						var list = (data && data.dates && data.dates.length) ? data.dates : [];
						eventDates = new Set(list);
						eventLoading = false;
						// Jump the calendar to the first available month.
						if (list.length) {
							var first = list[0].split('-');
							state.viewYear = parseInt(first[0], 10);
							state.viewMonth = parseInt(first[1], 10) - 1;
						}
						renderCalendar();
						if (hint) {
							hint.style.display = '';
							hint.textContent = list.length ? (config.i18n.pick_event || config.i18n.pick_first) : (config.i18n.no_events || config.i18n.no_slots);
						}
						if (done) done();
					})
					.catch(function(){
						eventLoading = false;
						eventDates = new Set();
						eventByDate = {};
						renderCalendar();
						if (hint) { hint.style.display = ''; hint.textContent = config.i18n.no_events || config.i18n.no_slots; }
						if (done) done();
					});
			}

			// Availability notice rendered above the slot grid: shows how many
			// slots are still open on the selected date, or a "fully booked"
			// line when every slot is taken. Hidden when no date is selected.
			function updateSlotsNotice(slots){
				var note = $('[data-slots-notice]');
				if (!note) return;
				if (!state.date || !slots || !slots.length) {
					note.hidden = true;
					note.textContent = '';
					note.className = 'g2ab-slots-notice';
					return;
				}
				var avail = 0;
				for (var i = 0; i < slots.length; i++) { if (slots[i].available) avail++; }
				var dateLabel = state.date;
				try {
					dateLabel = new Date(state.date + 'T12:00:00').toLocaleDateString(undefined, { weekday:'short', month:'short', day:'numeric' });
				} catch (e) {}
				if (avail === 0) {
					note.textContent = config.i18n.fully_booked;
					note.className = 'g2ab-slots-notice is-full';
				} else if (avail === 1) {
					note.textContent = (config.i18n.slot_available_one || '1 slot on %s').replace('%s', dateLabel);
					note.className = 'g2ab-slots-notice';
				} else {
					note.textContent = (config.i18n.slots_available || '%1$s slots on %2$s').replace('%1$s', avail).replace('%2$s', dateLabel);
					note.className = 'g2ab-slots-notice';
				}
				note.hidden = false;
			}

			// Render an array of slot objects into the slots box.
			function renderSlots(slots){
				var box = $('[data-slots]');
				var hint = $('[data-slots-hint]');
				if (!box) return;
				if (hint) hint.style.display = 'none';
				box.innerHTML = '';
				updateSlotsNotice(slots);
				if (!slots || !slots.length) { box.innerHTML = '<p class="g2ab-muted">' + config.i18n.no_slots + '</p>'; return; }
				slots.forEach(function(slot){
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'g2ab-slot';
					btn.textContent = slot.label;
					btn.disabled = !slot.available;
					btn.dataset.start = slot.start;
					btn.dataset.end = slot.end;
					if (slot.available) {
						btn.addEventListener('click', function(){
							$$('.g2ab-slot', box).forEach(function(s){ s.classList.remove('is-selected'); });
							btn.classList.add('is-selected');
							state.slot = { start: slot.start, end: slot.end, label: slot.label };
							updateSummary();
							var go = $('[data-go-form]');
							if (go) go.disabled = false;
						});
					}
					box.appendChild(btn);
				});
			}

			// ── slot loading ────────────────────────────────────────
			function loadSlots(){
				var box = $('[data-slots]');
				var hint = $('[data-slots-hint]');
				if (!box) return;
				if (!state.resourceId || !state.date) {
					box.innerHTML = '';
					updateSlotsNotice(null);
					if (hint) { hint.style.display = ''; hint.textContent = (eventMode ? (config.i18n.pick_event || config.i18n.pick_first) : config.i18n.pick_first) || 'Pick a date'; }
					return;
				}
				// Event mode: slots come from the pre-loaded event window — no
				// per-date round-trip, and never the open business-hours grid.
				if (eventMode) {
					var entry = eventByDate[state.date];
					renderSlots(entry ? entry.slots : []);
					return;
				}
				if (hint) { hint.style.display = ''; hint.textContent = config.i18n.loading; }
				box.innerHTML = '';
				updateSlotsNotice(null);
				fetch(config.rest_url + 'availability?resource_id=' + encodeURIComponent(state.resourceId) + '&date=' + encodeURIComponent(state.date) + '&duration=' + encodeURIComponent(root.dataset.duration || 60), { headers: headers(false) })
					.then(function(r){ return r.json(); })
					.then(function(json){
						var data = json && json.success ? json.data : null;
						// A failed request is NOT the same as an empty day — say so,
						// instead of telling the guest there are no times.
						if (!data) { if (hint) hint.style.display = 'none'; box.innerHTML = '<p class="g2ab-muted">' + (config.i18n.load_failed || config.i18n.no_slots) + '</p>'; return; }
						if (data.closed) { if (hint) hint.style.display = 'none'; box.innerHTML = '<p class="g2ab-muted">' + config.i18n.closed + '</p>'; return; }
						renderSlots(data.slots);
					})
					.catch(function(){
						if (hint) hint.style.display = 'none';
						box.innerHTML = '<p class="g2ab-muted">' + (config.i18n.load_failed || config.i18n.no_slots) + '</p>';
					});
			}

			function updateSummary(){
				var pill = $('[data-summary-pill]');
				var line = $('[data-summary-line]');
				if (!state.slot) {
					if (pill) { pill.hidden = true; pill.textContent = ''; }
					if (line) line.textContent = '';
					return;
				}
				var human = '';
				try {
					var dt = new Date(state.slot.start.replace(' ', 'T'));
					human = dt.toLocaleDateString(undefined, { weekday:'long', month:'short', day:'numeric' }) + ' · ' + state.slot.label;
				} catch (e) { human = state.slot.start; }
				if (pill) { pill.hidden = false; pill.textContent = (state.resourceName ? state.resourceName + ' · ' : '') + human; }
				if (line) line.textContent = (state.resourceName ? state.resourceName + ' · ' : '') + human;
			}

			// ── event bindings ──────────────────────────────────────
			// Lane / resource chip selector
			$$('.g2ab-chip').forEach(function(chip){
				chip.addEventListener('click', function(){
					$$('.g2ab-chip').forEach(function(c){ c.classList.remove('is-selected'); });
					chip.classList.add('is-selected');
					state.resourceId = parseInt(chip.dataset.resourceId, 10);
					state.resourceName = chip.dataset.resourceName || '';
					state.slot = null;
					var go = $('[data-go-form]'); if (go) go.disabled = true;
					if (eventMode) {
						// Availability is per-resource, so re-pull the event map.
						state.date = '';
						loadEventMap(function(){ loadSlots(); });
					} else {
						loadSlots();
					}
					updateSummary();
				});
			});

			// Calendar nav
			var prev = $('[data-cal-prev]'); if (prev) prev.addEventListener('click', function(){ shiftMonth(-1); });
			var next = $('[data-cal-next]'); if (next) next.addEventListener('click', function(){ shiftMonth(1); });

			// Calendar day click
			var grid = $('[data-cal-grid]');
			if (grid) {
				grid.addEventListener('click', function(e){
					var cell = e.target.closest('.g2ab-cal__cell.is-clickable');
					if (!cell || !grid.contains(cell)) return;
					state.date = cell.dataset.date;
					state.slot = null;
					var go = $('[data-go-form]'); if (go) go.disabled = true;
					$$('.g2ab-cal__cell', grid).forEach(function(c){ c.classList.remove('is-selected'); });
					cell.classList.add('is-selected');
					loadSlots();
				});
			}

			// Continue → form
			var goBtn = $('[data-go-form]');
			if (goBtn) goBtn.addEventListener('click', function(){
				if (!state.slot) return;
				showStage('form');
			});

			// Back from form → time
			$$('[data-back-time]').forEach(function(b){
				b.addEventListener('click', function(){ showStage('time'); });
			});

			// Submit form
			var form = $('.g2ab-form');
			if (form) {
				form.addEventListener('submit', function(event){
					event.preventDefault();
					var errEl = $('[data-form-error]', form);
					var submit = form.querySelector('button[type="submit"]');
					// Stash the resting label on the dataset on first submit
					// so the "Book Another Lane" reset can restore it after
					// the success path leaves the button in its "Reserving…"
					// state.
					if (submit && !submit.dataset.labelOriginal) {
						submit.dataset.labelOriginal = submit.textContent;
					}
					var originalLabel = submit ? (submit.dataset.labelOriginal || submit.textContent) : '';
					if (!state.resourceId || !state.slot) {
						if (errEl) { errEl.textContent = config.i18n.pick_first; errEl.hidden = false; }
						return;
					}
					var fields = {};
					new FormData(form).forEach(function(value, key){
						var m = key.match(/^fields\[(.+)\]$/);
						if (m) fields[m[1]] = value;
					});
					if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
					if (submit) { submit.disabled = true; submit.textContent = config.i18n.submitting; }
					var payload = JSON.stringify({
						booking_type_id: parseInt(root.dataset.bookingTypeId, 10) || 0,
						resource_id: state.resourceId,
						form_id: parseInt(root.dataset.formId, 10) || 0,
						start_at: state.slot.start,
						party_size: parseInt(fields.party_size || 1, 10),
						fields: fields
					});
					function postBooking(){
						return fetch(config.rest_url + 'bookings', { method: 'POST', headers: headers(true), body: payload })
							.then(function(r){ return r.json().then(function(j){ return {ok:r.ok, status:r.status, body:j}; }); });
					}
					// Always grab a fresh nonce first: the one rendered into the
					// page may be hours old if the page came from a cache, and a
					// stale nonce 403s the booking for logged-out guests.
					freshNonce().then(postBooking)
					.then(function(res){
						var nonceFailed = res && !res.ok && res.body && (res.body.code === 'g2ab_invalid_nonce' || res.body.code === 'rest_cookie_invalid_nonce');
						if (nonceFailed) { return freshNonce().then(postBooking); }
						return res;
					})
					.then(function(res){
						if (!res.ok || !res.body || !res.body.success) {
							var msg = (res.body && res.body.message) || config.i18n.failed;
							throw new Error(msg);
						}
						var data = res.body.data || {};
						if (data.redirect_url) { window.location.href = data.redirect_url; return; }
						var msgEl = $('[data-done-msg]'); var uuidEl = $('[data-done-uuid]');
						if (msgEl) msgEl.textContent = data.message || '';
						if (uuidEl) uuidEl.textContent = data.uuid || '';
						populateRecap(fields);
						showStage('done');
						fireConfetti();
					})
					.catch(function(err){
						if (errEl) { errEl.textContent = err.message || config.i18n.failed; errEl.hidden = false; }
						if (submit) { submit.disabled = false; submit.textContent = originalLabel; }
					});
				});
			}

			// ── done stage helpers ──────────────────────────────────
			// Pretty-prints the data already in `state` + the field map into the
			// confirmation recap card. Robust to missing fields: each row is
			// individually hidden when its source value is empty.
			function populateRecap(fields) {
				var recap = $('[data-done-recap]'); if (!recap) return;
				var resourceEl = $('[data-done-resource]');
				var whenEl     = $('[data-done-when]');
				var partyEl    = $('[data-done-party]');
				if (resourceEl) resourceEl.textContent = state.resourceName || '—';
				if (whenEl && state.slot) {
					try {
						var dt = new Date(state.slot.start.replace(' ', 'T'));
						whenEl.textContent = dt.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' }) + ' · ' + state.slot.label;
					} catch (e) { whenEl.textContent = state.slot.start; }
				}
				if (partyEl && fields) {
					var n = parseInt(fields.party_size || 1, 10);
					partyEl.textContent = n + (n === 1 ? ' shooter' : ' shooters');
				}
				recap.hidden = false;
			}

			// Lightweight canvas confetti. Burst once, ~110 particles, ~1.5s
			// settle. Skips on prefers-reduced-motion. No external dep.
			function fireConfetti() {
				if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
				var cvs = $('[data-confetti]'); if (!cvs) return;
				var doneEl = cvs.parentElement; if (!doneEl) return;
				var w = doneEl.clientWidth, h = doneEl.clientHeight;
				cvs.width  = w * (window.devicePixelRatio || 1);
				cvs.height = h * (window.devicePixelRatio || 1);
				cvs.style.width = w + 'px'; cvs.style.height = h + 'px';
				var ctx = cvs.getContext('2d');
				ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);
				var brand = getComputedStyle(doneEl).getPropertyValue('--g2ab-primary').trim() || '#E8802F';
				var accent = getComputedStyle(doneEl).getPropertyValue('--g2ab-accent').trim() || '#C9A84C';
				var palette = [brand, accent, '#ffffff', '#5B7BFF', '#9DE05B'];
				var parts = [];
				var N = 110;
				for (var i = 0; i < N; i++) {
					parts.push({
						x: w / 2 + (Math.random() - 0.5) * 30,
						y: h * 0.35,
						vx: (Math.random() - 0.5) * 8,
						vy: -Math.random() * 9 - 4,
						g: 0.32 + Math.random() * 0.18,
						w: 5 + Math.random() * 6,
						h: 8 + Math.random() * 10,
						r: Math.random() * Math.PI,
						vr: (Math.random() - 0.5) * 0.35,
						c: palette[(Math.random() * palette.length) | 0],
						life: 0
					});
				}
				var start = performance.now();
				var maxLife = 1500;
				function frame(t) {
					var elapsed = t - start;
					ctx.clearRect(0, 0, w, h);
					var alive = false;
					for (var i = 0; i < parts.length; i++) {
						var p = parts[i];
						p.x += p.vx; p.y += p.vy; p.vy += p.g; p.r += p.vr; p.life = elapsed;
						if (p.life >= maxLife || p.y > h + 40) continue;
						alive = true;
						ctx.save();
						ctx.globalAlpha = Math.max(0, 1 - (p.life / maxLife));
						ctx.translate(p.x, p.y); ctx.rotate(p.r);
						ctx.fillStyle = p.c;
						ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
						ctx.restore();
					}
					if (alive) requestAnimationFrame(frame);
					else ctx.clearRect(0, 0, w, h);
				}
				requestAnimationFrame(frame);
			}

			// Reset just enough state for a SECOND booking by the same user,
			// pre-filling the resource + date so the second flow takes 2
			// clicks instead of 5. Time slot is intentionally cleared (the
			// previous slot is now taken). The form values from the first
			// booking are preserved so the customer doesn't have to retype
			// their name/email/phone.
			function bookAnother() {
				// Clear the previous slot — staying on the same date so the
				// customer doesn't have to navigate the calendar again.
				state.slot = null;
				// Reset the submit button + error region so the form can
				// fire again. The "Continue" button is enabled by slot
				// selection, so it's reset implicitly.
				var form = root.querySelector('[data-form]');
				if (form) {
					var submit = form.querySelector('button[type="submit"]');
					if (submit) {
						submit.disabled = false;
						// Restore the resting label captured on first submit
						// (the success path leaves it at "Reserving…").
						if (submit.dataset.labelOriginal) {
							submit.textContent = submit.dataset.labelOriginal;
						}
					}
					var errEl = form.querySelector('[data-form-error]');
					if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
				}
				var go = $('[data-go-form]'); if (go) go.disabled = true;
				// Re-pull availability for the same date so the just-booked
				// slot is visually marked taken and the customer can pick a
				// different one.
				if (state.date) {
					try { loadSlots(); } catch (e) {}
				}
				showStage('time');
				// Smooth-scroll the booking root into view so the next pick
				// is obvious on mobile.
				try { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
			}

			var againBtn = root.querySelector('[data-action="book-another"]');
			if (againBtn) {
				againBtn.addEventListener('click', function () { bookAnother(); });
			}

			// ── boot ────────────────────────────────────────────────
			var now = new Date();
			state.viewYear = now.getFullYear();
			state.viewMonth = now.getMonth();
			renderCalendar();
			if (eventMode) { loadEventMap(); }
			showStage('time');
		})(<?php echo wp_json_encode( $config ); ?>);
		</script>
		<?php
	}
}
