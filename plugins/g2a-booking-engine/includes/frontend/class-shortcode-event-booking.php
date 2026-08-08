<?php
/**
 * [g2a_event_booking] — self-contained event-seat booking widget.
 *
 * Pick an event → pick a date/time (with live seats + price) → enter
 * details → reserve a seat. Posts to REST /events/book. Has its own
 * inline JS so it never touches the lane booking flow. Styled to match
 * the Guns2Ammo dark/orange UI.
 *
 * Used directly via the shortcode, embedded on the /event/{slug}/ landing
 * page, and mounted as the "Events" mode of the unified booking form.
 *
 * @package G2AB
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Event_Booking {

	private static $instance = null;
	private static $printed_css = false;
	private static $boot_queue = array();
	private static $boot_hooked = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2a_event_booking', array( $this, 'render' ) );
	}

	/**
	 * Format an amount in the SITE currency (never a hardcoded '$').
	 */
	private function format_price( $amount ) {
		if ( function_exists( 'g2ab_format_currency' ) ) {
			return g2ab_format_currency( (float) $amount );
		}
		return '$' . number_format( (float) $amount, 2 );
	}

	/**
	 * Currency prefix for client-side price rendering, derived from the same
	 * map g2ab_format_currency() uses so PHP and JS agree.
	 */
	private function currency_prefix() {
		if ( function_exists( 'g2ab_format_currency' ) ) {
			$prefix = str_replace( number_format_i18n( 0, 2 ), '', g2ab_format_currency( 0 ) );
			if ( '' !== $prefix ) {
				return $prefix;
			}
		}
		return '$';
	}

	/**
	 * URL to return to after logging in — the page embedding this widget.
	 */
	private function current_page_url() {
		if ( is_singular() ) {
			$permalink = get_permalink();
			if ( $permalink ) {
				return $permalink;
			}
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $request_uri );
	}

	/**
	 * @param array $atts event (id|slug to preselect), theme, heading.
	 */
	public function render( $atts = array() ) {
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'event'    => '',          // id or slug to lock the widget to one event
			'category' => '',          // limit the picker to a category
			'heading'  => '',          // optional heading above the widget
			'intro'    => '',          // optional sub-line under the heading
			'theme'    => 'dark',      // 'dark' | 'light'
			'show_picker' => 'auto',   // 'auto' | 'yes' | 'no'
		), $atts, 'g2a_event_booking' );
		$theme = ( 'light' === $atts['theme'] ) ? 'light' : 'dark';

		// Resolve a preselected event, if any.
		$preselect = null;
		if ( '' !== $atts['event'] ) {
			$preselect = G2AB_Events::get_event( is_numeric( $atts['event'] ) ? (int) $atts['event'] : sanitize_title( $atts['event'] ) );
			if ( $preselect && 'publish' !== $preselect->status ) {
				$preselect = null;
			}
		}

		// Build the event list for the picker (events with upcoming dates only).
		$events_payload = array();
		if ( ! $preselect ) {
			$events = G2AB_Events::get_events( array(
				'status'   => 'publish',
				'category' => $atts['category'] ? sanitize_key( $atts['category'] ) : '',
				'limit'    => 100,
			) );
			foreach ( $events as $ev ) {
				$next = G2AB_Events::next_occurrence( $ev->id );
				if ( ! $next ) {
					continue;
				}
				$events_payload[] = array(
					'id'      => (int) $ev->id,
					'title'   => $ev->title,
					'summary' => $ev->summary,
					'is_free' => (int) $ev->is_free === 1,
				);
			}
			if ( empty( $events_payload ) ) {
				return '<div class="g2ab-evb g2ab-evb--empty"><p>' . esc_html__( 'No upcoming events are open for booking right now.', 'g2a-booking' ) . '</p></div>';
			}
		}

		$this->enqueue();
		$instance_id = 'g2ab-evb-' . substr( md5( uniqid( 'evb', true ) ), 0, 10 );
		$config = array(
			'rest_url'   => esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) ),
			'ajax_url'   => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'instance'   => $instance_id,
			'preselect'  => $preselect ? (int) $preselect->id : 0,
			'events'     => $events_payload,
			'currency_prefix' => $this->currency_prefix(),
			'i18n'       => array(
				'loading'    => __( 'Loading dates…', 'g2a-booking' ),
				'no_dates'   => __( 'No upcoming dates for this event.', 'g2a-booking' ),
				'sold_out'   => __( 'Sold out', 'g2a-booking' ),
				'left'       => __( 'left', 'g2a-booking' ),
				'free'       => __( 'Free', 'g2a-booking' ),
				'pick_event' => __( 'Choose an event to see dates.', 'g2a-booking' ),
				'pick_date'  => __( 'Pick a date above to continue.', 'g2a-booking' ),
				'submitting' => __( 'Reserving…', 'g2a-booking' ),
				'failed'     => __( 'Could not complete your reservation. Please try again.', 'g2a-booking' ),
				'waiver_req' => __( 'Please accept the waiver to continue.', 'g2a-booking' ),
				'not_enough_seats' => __( 'Not enough seats left for this date. Please reduce the number of seats or pick another date.', 'g2a-booking' ),
				'confirm_reservation' => __( 'Confirm reservation', 'g2a-booking' ),
				/* translators: %s formatted total amount */
				'pay_seat'        => __( 'Reserve seat — Pay %s', 'g2a-booking' ),
				'invalid_email'   => __( 'Please enter a valid email address.', 'g2a-booking' ),
				'required_fields' => __( 'Please fill in the highlighted required fields.', 'g2a-booking' ),
				'checkout_hold'   => __( 'Checkout hold — payment required. Your seats are held for a short time while you complete secure checkout.', 'g2a-booking' ),
				'redirecting'     => __( 'Redirecting to secure checkout…', 'g2a-booking' ),
				'hold_note'       => __( 'Your seats are not confirmed until payment is completed.', 'g2a-booking' ),
				'total_due'       => __( 'Total due today', 'g2a-booking' ),
			),
		);

		// The boot script is emitted in wp_footer (not inline) so it can never be
		// mangled by the_content filters when this widget is nested inside another
		// shortcode (e.g. the unified booking form) — raw "&&" would otherwise be
		// entity-encoded into a JS syntax error.
		$this->enqueue_boot( $config );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $instance_id ); ?>" class="g2ab-evb g2ab-evb--theme-<?php echo esc_attr( $theme ); ?>" data-preselect="<?php echo $preselect ? (int) $preselect->id : 0; ?>">
			<?php
			$intro = $atts['intro'];
			if ( '' === $intro && $preselect ) {
				$intro = $preselect->requires_waiver
					? __( 'Pick a date below to reserve your seat. A signed waiver is required.', 'g2a-booking' )
					: __( 'Pick a date below to reserve your seat — it only takes a moment.', 'g2a-booking' );
			} elseif ( '' === $intro && ! $preselect ) {
				$intro = __( 'Choose an event, then a date, and reserve your seat.', 'g2a-booking' );
			}
			if ( $atts['heading'] || $intro ) : ?>
				<header class="g2ab-evb__header">
					<span class="g2ab-evb__eyebrow"><?php esc_html_e( 'BOOK NOW', 'g2a-booking' ); ?></span>
					<?php if ( $atts['heading'] ) : ?><h3 class="g2ab-evb__heading"><?php echo esc_html( $atts['heading'] ); ?></h3><?php endif; ?>
					<?php if ( $intro ) : ?><p class="g2ab-evb__intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
					<?php if ( $preselect ) :
						$next  = G2AB_Events::next_occurrence( $preselect->id );
						$dates = count( G2AB_Events::get_occurrences( $preselect->id, array( 'upcoming_only' => true, 'with_seats' => false, 'limit' => 60 ) ) );
						$free  = ( (int) $preselect->is_free === 1 || (float) $preselect->price <= 0 );
						?>
						<div class="g2ab-evb__facts">
							<span class="g2ab-evb__fact"><b><?php echo $free ? esc_html__( 'FREE', 'g2a-booking' ) : esc_html( $this->format_price( (float) $preselect->price ) ); ?></b><small><?php esc_html_e( 'per seat', 'g2a-booking' ); ?></small></span>
							<span class="g2ab-evb__fact"><b><?php echo (int) $dates; ?></b><small><?php echo esc_html( _n( 'date', 'dates', $dates, 'g2a-booking' ) ); ?></small></span>
							<?php if ( $next ) : ?><span class="g2ab-evb__fact"><b><?php echo (int) $next->seats_left; ?></b><small><?php esc_html_e( 'seats left', 'g2a-booking' ); ?></small></span><?php endif; ?>
						</div>
					<?php endif; ?>
				</header>
			<?php endif; ?>

			<!-- STAGE: pick -->
			<section class="g2ab-evb__stage is-active" data-evb-stage="pick">
				<?php if ( ! $preselect ) : ?>
					<div class="g2ab-evb__field">
						<label class="g2ab-evb__label" for="<?php echo esc_attr( $instance_id ); ?>-event"><?php esc_html_e( 'Event', 'g2a-booking' ); ?></label>
						<select id="<?php echo esc_attr( $instance_id ); ?>-event" class="g2ab-evb__select" data-evb-event>
							<option value=""><?php esc_html_e( '— Select an event —', 'g2a-booking' ); ?></option>
							<?php foreach ( $events_payload as $ep ) : ?>
								<option value="<?php echo (int) $ep['id']; ?>"><?php echo esc_html( $ep['title'] ); ?><?php echo $ep['is_free'] ? ' · ' . esc_html__( 'FREE', 'g2a-booking' ) : ''; ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<div class="g2ab-evb__dates">
					<p class="g2ab-evb__hint" data-evb-hint role="status" aria-live="polite"><?php echo $preselect ? esc_html__( 'Pick a date & time:', 'g2a-booking' ) : esc_html__( 'Choose an event to see dates.', 'g2a-booking' ); ?></p>
					<div class="g2ab-evb__date-grid" data-evb-dates></div>
				</div>
			</section>

			<!-- STAGE: details -->
			<section class="g2ab-evb__stage" data-evb-stage="details">
				<button type="button" class="g2ab-evb__back" data-evb-back>‹ <?php esc_html_e( 'Back to dates', 'g2a-booking' ); ?></button>
				<div class="g2ab-evb__summary" data-evb-summary></div>
				<?php if ( ! is_user_logged_in() ) : ?>
					<p class="g2ab-evb__login">
						<?php esc_html_e( 'Already a member?', 'g2a-booking' ); ?>
						<a href="<?php echo esc_url( wp_login_url( $this->current_page_url() ) ); ?>"><?php esc_html_e( 'Log in to use your benefit', 'g2a-booking' ); ?></a>
					</p>
				<?php endif; ?>
				<form class="g2ab-evb__form" data-evb-form novalidate onsubmit="return false;">
					<div class="g2ab-evb__field">
						<label class="g2ab-evb__label"><?php esc_html_e( 'Full name', 'g2a-booking' ); ?> <span class="g2ab-evb__req">*</span></label>
						<input type="text" class="g2ab-evb__input" name="customer_name" required />
					</div>
					<div class="g2ab-evb__row">
						<div class="g2ab-evb__field">
							<label class="g2ab-evb__label"><?php esc_html_e( 'Email', 'g2a-booking' ); ?> <span class="g2ab-evb__req">*</span></label>
							<input type="email" class="g2ab-evb__input" name="customer_email" required />
						</div>
						<div class="g2ab-evb__field">
							<label class="g2ab-evb__label"><?php esc_html_e( 'Phone', 'g2a-booking' ); ?> <span class="g2ab-evb__req">*</span></label>
							<input type="tel" class="g2ab-evb__input" name="customer_phone" required />
						</div>
					</div>
					<div class="g2ab-evb__field g2ab-evb__field--seats">
						<label class="g2ab-evb__label" for="<?php echo esc_attr( $instance_id ); ?>-seats"><?php esc_html_e( 'Number of seats', 'g2a-booking' ); ?></label>
						<input type="number" id="<?php echo esc_attr( $instance_id ); ?>-seats" class="g2ab-evb__input" name="seats" min="1" value="1" data-evb-seats />
					</div>
					<div class="g2ab-evb__order" data-evb-order hidden>
						<span class="g2ab-evb__order-line" data-evb-order-line></span>
						<span class="g2ab-evb__order-total"><span data-evb-order-total-label><?php esc_html_e( 'Total due today', 'g2a-booking' ); ?></span> <b data-evb-order-total></b></span>
					</div>
					<label class="g2ab-evb__check" data-evb-waiver-wrap hidden>
						<input type="checkbox" name="waiver_acceptance" value="1" data-evb-waiver />
						<span><?php esc_html_e( 'I accept the range liability waiver and safety rules.', 'g2a-booking' ); ?></span>
					</label>
					<div class="g2ab-evb__error" data-evb-error role="alert" hidden></div>
					<div class="g2ab-evb__status" data-evb-status role="status" aria-live="polite" hidden></div>
					<button type="submit" class="g2ab-evb__cta" data-evb-submit>
						<span data-evb-submit-label><?php esc_html_e( 'RESERVE SEAT', 'g2a-booking' ); ?></span>
					</button>
				</form>
			</section>

			<!-- STAGE: done -->
			<section class="g2ab-evb__stage" data-evb-stage="done">
				<div class="g2ab-evb__done">
					<div class="g2ab-evb__done-check">✓</div>
					<h3 class="g2ab-evb__done-title"><?php esc_html_e( 'Seat reserved!', 'g2a-booking' ); ?></h3>
					<p class="g2ab-evb__done-msg" data-evb-done-msg></p>
					<p class="g2ab-evb__done-id"><?php esc_html_e( 'Confirmation:', 'g2a-booking' ); ?> <code data-evb-done-uuid></code></p>
				</div>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	private function enqueue() {
		if ( self::$printed_css ) {
			return;
		}
		self::$printed_css = true;
		add_action( 'wp_footer', array( $this, 'print_css' ) );
		// In case wp_footer already fired (e.g. inline render), also print now.
		if ( did_action( 'wp_footer' ) ) {
			$this->print_css();
		}
	}

	/**
	 * Queue a widget instance's boot config for emission in wp_footer.
	 */
	private function enqueue_boot( $config ) {
		self::$boot_queue[] = $config;
		if ( ! self::$boot_hooked ) {
			self::$boot_hooked = true;
			add_action( 'wp_footer', array( $this, 'print_boots' ), 20 );
		}
		if ( did_action( 'wp_footer' ) ) {
			$this->print_boots();
		}
	}

	public function print_css() {
		?>
		<style id="g2ab-evb-styles">
		.g2ab-evb{--evb-bg:#1A191E;--evb-surface:#26252C;--evb-surface2:#26252C;--evb-border:#2A323D;--evb-text:#F7F7F9;--evb-muted:#A7A6AE;--evb-orange:#E8802F;--evb-green:#2E9E54;--evb-danger:#C62828;--evb-head:#fff;background:var(--evb-surface);border:1px solid var(--evb-border);border-top:4px solid var(--evb-orange);border-radius:12px;padding:24px;max-width:640px;margin:24px auto;color:var(--evb-text);font-family:'Inter',system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;box-sizing:border-box;box-shadow:0 14px 40px rgba(0,0,0,.18);}
		.g2ab-evb--theme-light{--evb-bg:#FFFFFF;--evb-surface:#FFFFFF;--evb-surface2:#F4F6FA;--evb-border:#E3E8F0;--evb-text:#1C2533;--evb-muted:#697587;--evb-green:#1F9E54;--evb-head:#161B22;}
		.g2ab-evb *{box-sizing:border-box;}
		.g2ab-evb--empty{padding:26px;text-align:center;color:var(--evb-muted);}
		.g2ab-evb__header{margin:0 0 18px;padding-bottom:16px;border-bottom:1px solid var(--evb-border);}
		.g2ab-evb__eyebrow{display:block;color:var(--evb-orange);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:11px;font-weight:700;letter-spacing:.2em;}
		.g2ab-evb__intro{margin:6px 0 0;color:var(--evb-muted);font-size:13.5px;line-height:1.5;}
		.g2ab-evb__facts{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;}
		.g2ab-evb__fact{background:var(--evb-surface2);border:1px solid var(--evb-border);border-radius:9px;padding:9px 14px;text-align:center;min-width:84px;}
		.g2ab-evb__fact b{display:block;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:20px;color:var(--evb-orange);line-height:1;}
		.g2ab-evb__fact small{display:block;font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:var(--evb-muted);margin-top:3px;}
		.g2ab-evb__heading{margin:4px 0 0;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:24px;letter-spacing:.03em;text-transform:uppercase;color:var(--evb-head);line-height:1.1;}
		.g2ab-evb__stage{display:none;}
		.g2ab-evb__stage.is-active{display:block;}
		.g2ab-evb__field{margin-bottom:14px;}
		.g2ab-evb__row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
		@media (max-width:520px){.g2ab-evb__row{grid-template-columns:1fr;}}
		.g2ab-evb__label{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--evb-muted);font-weight:700;margin-bottom:6px;}
		.g2ab-evb__req{color:var(--evb-orange);}
		.g2ab-evb__select,.g2ab-evb__input{width:100%;background:var(--evb-bg);border:1px solid var(--evb-border);border-radius:5px;color:var(--evb-text);padding:11px 12px;font-size:15px;}
		.g2ab-evb__select:focus,.g2ab-evb__input:focus{outline:none;border-color:var(--evb-orange);}
		.g2ab-evb__field--seats{max-width:180px;}
		.g2ab-evb__hint{color:var(--evb-muted);font-size:13px;margin:4px 0 12px;}
		.g2ab-evb__date-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
		.g2ab-evb__date{background:var(--evb-bg);border:1px solid var(--evb-border);border-radius:6px;padding:12px 14px;cursor:pointer;text-align:left;transition:all .12s ease;color:var(--evb-text);}
		.g2ab-evb__date:hover:not(:disabled){border-color:var(--evb-orange);transform:translateY(-2px);}
		.g2ab-evb__date.is-selected{border-color:var(--evb-orange);box-shadow:0 0 0 2px var(--evb-orange) inset;}
		.g2ab-evb__date:disabled{opacity:.4;cursor:not-allowed;}
		.g2ab-evb__date-day{display:block;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:16px;font-weight:700;color:var(--evb-head);letter-spacing:.02em;}
		.g2ab-evb__date-time{display:block;font-size:12px;color:var(--evb-muted);margin-top:2px;}
		.g2ab-evb__date-foot{display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:11px;}
		.g2ab-evb__date-seats{color:var(--evb-green);font-weight:700;}
		.g2ab-evb__date-seats.is-low{color:var(--evb-orange);}
		.g2ab-evb__date-seats.is-out{color:var(--evb-danger);}
		.g2ab-evb__date-price{color:var(--evb-orange);font-weight:700;}
		.g2ab-evb__back{background:none;border:none;color:var(--evb-muted);cursor:pointer;font-size:13px;padding:0;margin-bottom:12px;}
		.g2ab-evb__back:hover{color:var(--evb-orange);}
		.g2ab-evb__summary{background:var(--evb-bg);border:1px solid var(--evb-border);border-left:3px solid var(--evb-orange);border-radius:5px;padding:12px 14px;margin-bottom:16px;font-size:14px;color:var(--evb-text);}
		.g2ab-evb__summary strong{color:var(--evb-head);}
		.g2ab-evb__check{display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--evb-text);margin:6px 0 14px;cursor:pointer;line-height:1.4;}
		.g2ab-evb__check input{margin-top:2px;}
		.g2ab-evb__error{background:rgba(198,40,40,.15);border:1px solid rgba(198,40,40,.4);color:#F08A8A;border-radius:5px;padding:10px 12px;font-size:13px;margin-bottom:12px;}
		.g2ab-evb__status{background:var(--evb-bg);border:1px solid var(--evb-orange);border-radius:5px;padding:10px 12px;font-size:13px;color:var(--evb-text);margin-bottom:12px;}
		.g2ab-evb__login{margin:0 0 14px;padding:9px 12px;background:var(--evb-bg);border:1px dashed var(--evb-border);border-radius:5px;font-size:13px;color:var(--evb-muted);}
		.g2ab-evb__login a{color:var(--evb-orange);font-weight:700;text-decoration:underline;}
		.g2ab-evb__order{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:6px 12px;background:var(--evb-bg);border:1px solid var(--evb-border);border-radius:5px;padding:10px 12px;margin:0 0 14px;font-size:13px;color:var(--evb-muted);}
		.g2ab-evb__order-total b{color:var(--evb-orange);font-size:15px;}
		.g2ab-evb__input[aria-invalid="true"]{border-color:var(--evb-danger);}
		.g2ab-evb__cta{width:100%;background:var(--evb-orange);color:#111;border:2px solid var(--evb-orange);border-radius:5px;padding:14px 18px;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:16px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:all .15s ease;}
		.g2ab-evb__cta:hover:not(:disabled){background:transparent;color:var(--evb-orange);}
		.g2ab-evb__cta:disabled{opacity:.6;cursor:not-allowed;}
		.g2ab-evb__done{text-align:center;padding:18px 8px;}
		.g2ab-evb__done-check{width:64px;height:64px;line-height:64px;margin:0 auto 14px;border-radius:50%;background:rgba(76,175,80,.15);color:var(--evb-green);font-size:34px;border:2px solid var(--evb-green);}
		.g2ab-evb__done-title{margin:0 0 8px;color:var(--evb-head);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;letter-spacing:.04em;font-size:24px;}
		.g2ab-evb__done-msg{color:var(--evb-muted);font-size:14px;margin:0 0 10px;}
		.g2ab-evb__done-id code{background:var(--evb-bg);padding:3px 8px;border-radius:4px;color:var(--evb-orange);}
		@media (prefers-reduced-motion: reduce){
			.g2ab-evb *{animation:none !important;transition:none !important;}
			.g2ab-evb__date:hover:not(:disabled){transform:none;}
		}
		</style>
		<?php
	}

	public function print_boots() {
		if ( empty( self::$boot_queue ) ) {
			return;
		}
		$queue            = self::$boot_queue;
		self::$boot_queue = array();
		?>
		<script>
		(<?php echo wp_json_encode( $queue ); ?>).forEach(function(cfg){
			var root = document.getElementById(cfg.instance);
			if (!root || root.dataset.evbBooted) return;
			root.dataset.evbBooted = '1';
			var $ = function(s){ return root.querySelector(s); };
			var $$ = function(s){ return Array.prototype.slice.call(root.querySelectorAll(s)); };

			var state = { eventId: cfg.preselect || 0, occ: null, requiresWaiver: false };

			function headers(nonce){ var h = { 'Content-Type':'application/json', 'Accept':'application/json' }; if (nonce) h['X-WP-Nonce'] = nonce; return h; }
			function pad(n){ return n<10 ? '0'+n : ''+n; }
			// Site-currency formatter (prefix localised from PHP — no hardcoded '$').
			function money(n){ return (cfg.currency_prefix || '$') + (Math.round(Number(n) * 100) / 100).toFixed(2); }
			function priceLabel(o){ return o.is_free ? cfg.i18n.free : money(o.price); }
			// Keep uuid → confirm_token so a ?g2ab_cancel return (no token in the
			// URL) can still offer "Try payment again" via resume-payment.
			function storePayCtx(uuid, token){
				if (!uuid || !token) return;
				try {
					var raw = window.sessionStorage.getItem('g2abPayCtx');
					var map = raw ? JSON.parse(raw) : {};
					map[uuid] = { t: token, ts: Date.now() };
					window.sessionStorage.setItem('g2abPayCtx', JSON.stringify(map));
				} catch (e) { /* storage unavailable */ }
			}
			function fmtDate(sql){
				var d = new Date(String(sql).replace(' ','T'));
				if (isNaN(d.getTime())) return { day: sql, time: '' };
				return {
					day: d.toLocaleDateString(undefined,{weekday:'short',month:'short',day:'numeric'}),
					time: d.toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'})
				};
			}
			function showStage(name){ $$('.g2ab-evb__stage').forEach(function(s){ s.classList.toggle('is-active', s.getAttribute('data-evb-stage')===name); }); }

			function fetchOccurrences(){
				if (!state.eventId) return Promise.resolve(null);
				return fetch(cfg.rest_url + 'events/' + state.eventId + '/occurrences?_=' + Date.now(), { headers: headers(cfg.nonce), credentials:'same-origin', cache:'no-store' })
					.then(function(r){ return r.json(); })
					.then(function(j){ return j && j.success ? j.data : null; });
			}

			function applyOccurrenceUpdate(o){
				if (!o) return null;
				state.occ = o;
				var f = fmtDate(o.start_at);
				var btn = root.querySelector('[data-evb-occurrence-id="'+o.id+'"]');
				if (btn) {
					btn.disabled = !!o.sold_out;
					var seat = btn.querySelector('.g2ab-evb__date-seats');
					if (seat) {
						seat.classList.toggle('is-out', !!o.sold_out);
						seat.classList.toggle('is-low', !o.sold_out && o.seats_left <= 3);
						seat.textContent = o.sold_out ? cfg.i18n.sold_out : (o.seats_left + ' ' + cfg.i18n.left);
					}
					var price = btn.querySelector('.g2ab-evb__date-price');
					if (price) price.textContent = priceLabel(o);
				}
				goDetails(o, f);
				document.dispatchEvent(new CustomEvent('g2ab:content-ready', { detail: { root: document } }));
				return o;
			}

			function refreshSelectedOccurrence(){
				if (!state.eventId || !state.occ) return Promise.resolve(state.occ);
				var selectedId = parseInt(state.occ.id, 10);
				return fetchOccurrences().then(function(data){
					var occs = data && data.occurrences ? data.occurrences : [];
					for (var i = 0; i < occs.length; i++) {
						if (parseInt(occs[i].id, 10) === selectedId) return applyOccurrenceUpdate(occs[i]);
					}
					throw new Error(cfg.i18n.pick_date);
				});
			}

			// Monotonic request counter: a slow occurrences response for a
			// PREVIOUS event selection must never overwrite the newer one.
			var occReq = 0;
			function loadOccurrences(){
				var box = $('[data-evb-dates]'); var hint = $('[data-evb-hint]');
				var seq = ++occReq;
				if (!state.eventId) { box.innerHTML=''; if (hint){ hint.textContent = cfg.i18n.pick_event; } return; }
				if (hint) hint.textContent = cfg.i18n.loading;
				box.innerHTML = '';
				fetchOccurrences()
					.then(function(data){
						if (seq !== occReq) return; // stale response — a newer selection won.
						if (!data) { if (hint) hint.textContent = cfg.i18n.no_dates; return; }
						state.requiresWaiver = !!(data.event && data.event.requires_waiver);
						var occs = data.occurrences || [];
						if (!occs.length) { if (hint) hint.textContent = cfg.i18n.no_dates; return; }
						if (hint) hint.textContent = '';
						occs.forEach(function(o){
							var f = fmtDate(o.start_at);
							var btn = document.createElement('button');
							btn.type = 'button'; btn.className = 'g2ab-evb__date'; btn.disabled = !!o.sold_out;
							btn.setAttribute('data-evb-occurrence-id', o.id);
							var seatCls = o.sold_out ? 'is-out' : (o.seats_left <= 3 ? 'is-low' : '');
							var seatTxt = o.sold_out ? cfg.i18n.sold_out : (o.seats_left + ' ' + cfg.i18n.left);
							btn.innerHTML = '<span class="g2ab-evb__date-day">'+f.day+'</span>'
								+ '<span class="g2ab-evb__date-time">'+f.time+'</span>'
								+ '<span class="g2ab-evb__date-foot"><span class="g2ab-evb__date-seats '+seatCls+'">'+seatTxt+'</span>'
								+ '<span class="g2ab-evb__date-price">'+priceLabel(o)+'</span></span>';
							if (!o.sold_out) {
								btn.addEventListener('click', function(){
									$$('.g2ab-evb__date').forEach(function(b){ b.classList.remove('is-selected'); });
									btn.classList.add('is-selected');
									state.occ = o;
									goDetails(o, f);
								});
							}
							box.appendChild(btn);
						});
					})
					.catch(function(){ if (seq !== occReq) return; if (hint) hint.textContent = cfg.i18n.no_dates; });
			}

			// Seats × unit-price recap + payment-aware CTA. Re-run whenever the
			// seat count or the selected occurrence changes.
			function updateOrder(){
				var o = state.occ;
				var box = $('[data-evb-order]');
				var lbl = $('[data-evb-submit-label]');
				if (!o) { if (box) box.hidden = true; return; }
				var seatsEl = $('[data-evb-seats]');
				var n = seatsEl ? parseInt(seatsEl.value, 10) : 1;
				if (!(n > 0)) n = 1;
				if (o.is_free) {
					if (box) box.hidden = true;
					if (lbl) lbl.textContent = cfg.i18n.confirm_reservation;
					return;
				}
				var total = Number(o.price) * n;
				var line = $('[data-evb-order-line]');
				if (line) line.textContent = money(o.price) + ' × ' + n;
				var tot = $('[data-evb-order-total]');
				if (tot) tot.textContent = money(total);
				if (box) box.hidden = false;
				if (lbl) lbl.textContent = cfg.i18n.pay_seat.replace('%s', money(total));
			}

			function goDetails(o, f){
				var sum = $('[data-evb-summary]');
				if (sum) sum.innerHTML = '<strong>'+f.day+' · '+f.time+'</strong> — '+priceLabel(o)+' '+ (o.is_free ? '' : '/ seat') +' · '+o.seats_left+' '+cfg.i18n.left;
				var wrap = $('[data-evb-waiver-wrap]'); if (wrap) wrap.hidden = !state.requiresWaiver;
				var seats = $('[data-evb-seats]'); if (seats) { seats.max = o.seats_left; if (parseInt(seats.value,10) > o.seats_left) seats.value = o.seats_left; }
				updateOrder();
				showStage('details');
			}

			// Bind event picker.
			var sel = $('[data-evb-event]');
			if (sel) sel.addEventListener('change', function(){ state.eventId = parseInt(sel.value,10) || 0; state.occ=null; loadOccurrences(); });

			// Seat-count changes re-price the recap + CTA live.
			var seatsInput = $('[data-evb-seats]');
			if (seatsInput) ['change','input'].forEach(function(ev){ seatsInput.addEventListener(ev, updateOrder); });

			// Back.
			var back = $('[data-evb-back]'); if (back) back.addEventListener('click', function(){ showStage('pick'); });

			// Client-side validation (required fields, email format). The server
			// still re-validates everything; this only saves a round-trip and
			// moves focus to the first offending control.
			function validateDetails(form, err){
				var firstBad = null; var badEmail = false;
				$$('.g2ab-evb__input').forEach(function(el){
					var bad = false;
					if (el.required && !String(el.value || '').trim()) bad = true;
					if (!bad && el.type === 'email' && String(el.value || '').trim()) {
						bad = !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(el.value).trim());
						if (bad) badEmail = true;
					}
					if (bad) { el.setAttribute('aria-invalid','true'); if (!firstBad) firstBad = el; }
					else el.removeAttribute('aria-invalid');
				});
				if (firstBad) {
					if (err) { err.textContent = badEmail && firstBad.type === 'email' ? cfg.i18n.invalid_email : cfg.i18n.required_fields; err.hidden = false; }
					try { firstBad.focus(); } catch (ex) { /* noop */ }
					return false;
				}
				return true;
			}

			// Submit.
			var form = $('[data-evb-form]');
			if (form) form.addEventListener('submit', function(e){
				e.preventDefault();
				var err = $('[data-evb-error]'); var btn = $('[data-evb-submit]'); var lbl = $('[data-evb-submit-label]');
				var statusBox = $('[data-evb-status]');
				var origLbl = lbl ? lbl.textContent : '';
				if (statusBox) { statusBox.hidden = true; statusBox.textContent = ''; }
				if (!state.occ) { if (err){ err.textContent = cfg.i18n.pick_date; err.hidden=false; } return; }
				if (!validateDetails(form, err)) return;
				var fields = {};
				new FormData(form).forEach(function(v,k){ fields[k]=v; });
				var seats = parseInt(fields.seats||'1',10) || 1;
				if (state.requiresWaiver && !fields.waiver_acceptance) {
					if (err){ err.textContent = cfg.i18n.waiver_req; err.hidden=false; }
					var waiver = $('[data-evb-waiver]');
					if (waiver) { try { waiver.focus(); } catch (ex) { /* noop */ } }
					return;
				}
				if (err) err.hidden = true;
				if (btn) btn.disabled = true; if (lbl) lbl.textContent = cfg.i18n.submitting;
				function post(nonce){
					var payload = JSON.stringify({ occurrence_id: state.occ.id, seats: seats, fields: fields });
					return fetch(cfg.rest_url + 'events/book', { method:'POST', headers: headers(nonce), credentials:'same-origin', body: payload })
						.then(function(r){ return r.json().then(function(j){ return {ok:r.ok,status:r.status,body:j}; }, function(){ return {ok:r.ok,status:r.status,body:null}; }); });
				}
				function isStale(res){ return res && res.status===403 && res.body && (res.body.code==='g2ab_invalid_nonce' || res.body.code==='rest_cookie_invalid_nonce'); }
				refreshSelectedOccurrence().then(function(fresh){
					if (!fresh || fresh.sold_out || seats > parseInt(fresh.seats_left, 10)) {
						throw new Error(cfg.i18n.not_enough_seats);
					}
					return post(cfg.nonce);
				}).then(function(res){
					if (!isStale(res)) return res;
					var base = cfg.ajax_url || (location.origin + '/wp-admin/admin-ajax.php');
					return fetch(base + (base.indexOf('?')===-1?'?':'&') + 'action=g2ab_refresh_nonce', { headers:{Accept:'application/json'}, credentials:'same-origin' })
						.then(function(r){ return r.json(); }).then(function(j){ var n = j&&j.data&&j.data.nonce?j.data.nonce:''; if (n) cfg.nonce=n; return post(n||cfg.nonce); })
						.catch(function(){ return res; });
				}).then(function(res){
					if (!res.ok || !res.body || !res.body.success) { throw new Error((res.body && res.body.message) || cfg.i18n.failed); }
					var d = res.body.data || {};
					var isHold = !!d.payment_required || d.state === 'checkout_hold';
					var isConfirmed = d.state === 'confirmed' || d.status === 'confirmed' || d.status === 'paid';
					if (isHold) {
						// Checkout hold — seats are NOT confirmed yet. Announce the
						// hold, keep the confirm_token for a possible cancel return,
						// and hand off to the gateway.
						storePayCtx(d.uuid, d.confirm_token);
						if (statusBox) {
							statusBox.textContent = (d.message || cfg.i18n.checkout_hold) + ' ' + (d.redirect_url ? cfg.i18n.redirecting : cfg.i18n.hold_note);
							statusBox.hidden = false;
						}
						if (d.redirect_url) {
							window.setTimeout(function(){ window.location.href = d.redirect_url; }, 150);
							return;
						}
						// No checkout URL (e.g. idempotent replay of a pending
						// attempt): never show the confirmed panel.
						if (btn) btn.disabled = false; if (lbl) lbl.textContent = origLbl;
						return;
					}
					if (!isConfirmed) {
						// Unknown/unpaid state — never render "Seat reserved!".
						if (statusBox) { statusBox.textContent = d.message || cfg.i18n.hold_note; statusBox.hidden = false; }
						if (btn) btn.disabled = false; if (lbl) lbl.textContent = origLbl;
						return;
					}
					var m = $('[data-evb-done-msg]'); var u = $('[data-evb-done-uuid]');
					if (m) m.textContent = d.message || '';
					if (u) u.textContent = d.uuid || '';
					showStage('done');
				}).catch(function(ex){
					if (ex && ex.message === cfg.i18n.not_enough_seats) {
						refreshSelectedOccurrence().catch(function(){});
					}
					if (err){ err.textContent = ex.message || cfg.i18n.failed; err.hidden=false; }
					if (btn) btn.disabled = false; if (lbl) lbl.textContent = origLbl;
				});
			});

			// Boot: if locked to one event, load its dates immediately.
			if (state.eventId) loadOccurrences();
		});
		</script>
		<?php
	}
}
