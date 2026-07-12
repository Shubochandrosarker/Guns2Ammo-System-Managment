<?php
/**
 * Admin: Shortcodes reference.
 *
 * One place that lists every shortcode the plugin ships — booking forms,
 * event banners (all formats), the events calendar, front-desk terminal and
 * customer self-service — with a description, the useful attributes, and a
 * one-click "copy" button. Saves staff from hunting through docs.
 *
 * @package G2AB
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Shortcodes {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-shortcodes';
	const CAP       = 'manage_g2ab_settings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Shortcodes', 'g2a-booking' ),
			__( 'Shortcodes', 'g2a-booking' ),
			self::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	private function groups() {
		return array(
			array(
				'label' => __( 'Booking forms', 'g2a-booking' ),
				'items' => array(
					array(
						'code'  => '[g2a_lane_booking]',
						'title' => __( 'Unified booking form', 'g2a-booking' ),
						'desc'  => __( 'The main two-column booking form. Shows a “What do you want to book?” switch (Lane / Events) automatically when you have upcoming events; otherwise it is just the lane flow.', 'g2a-booking' ),
						'attrs' => array(
							'booking_type' => __( 'Lane booking-type slug (default lane-booking).', 'g2a-booking' ),
							'theme'        => __( 'Override the form theme.', 'g2a-booking' ),
						),
					),
					array(
						'code'  => '[g2a_event_booking event="ladies-tuesday"]',
						'title' => __( 'Event seat booking', 'g2a-booking' ),
						'desc'  => __( 'Standalone event booking widget — pick an event, pick a date (live seats + price), reserve a seat. Lock it to one event with the event attribute; it then shows a header, intro and quick facts.', 'g2a-booking' ),
						'attrs' => array(
							'event'    => __( 'Event id or slug to lock the widget to one event.', 'g2a-booking' ),
							'category' => __( 'Limit the event picker to a category.', 'g2a-booking' ),
							'heading'  => __( 'Heading shown above the widget.', 'g2a-booking' ),
							'intro'    => __( 'Optional sub-line under the heading.', 'g2a-booking' ),
							'theme'    => __( 'dark (default) or light.', 'g2a-booking' ),
						),
					),
				),
			),
			array(
				'label' => __( 'Events display', 'g2a-booking' ),
				'items' => array(
					array(
						'code'  => '[g2a_events_calendar]',
						'title' => __( 'Events calendar', 'g2a-booking' ),
						'desc'  => __( 'A full month calendar of your events with an “upcoming events” sidebar and search. Clicking an event opens its landing page to book.', 'g2a-booking' ),
						'attrs' => array(
							'theme'        => __( 'dark (default) or light.', 'g2a-booking' ),
							'category'     => __( 'Only show events in this category.', 'g2a-booking' ),
							'show_sidebar' => __( 'yes (default) or no.', 'g2a-booking' ),
							'heading'      => __( 'Optional heading above the calendar.', 'g2a-booking' ),
						),
					),
					array(
						'code'  => '[g2a_event_banner style="spotlight"]',
						'title' => __( 'Featured event banner', 'g2a-booking' ),
						'desc'  => __( 'A hero banner for one featured event with a live countdown. Three formats: spotlight (full hero), strip (compact one-row), ticket (event-ticket card).', 'g2a-booking' ),
						'attrs' => array(
							'style'     => __( 'spotlight | strip | ticket.', 'g2a-booking' ),
							'id / slug' => __( 'Pick a specific event (otherwise the soonest is used).', 'g2a-booking' ),
							'category'  => __( 'Pick the soonest event in a category.', 'g2a-booking' ),
							'countdown' => __( 'yes (default) or no.', 'g2a-booking' ),
							'accent'    => __( 'Override the accent colour, e.g. #E8802F.', 'g2a-booking' ),
							'cta'       => __( 'Button text (default RESERVE A SEAT).', 'g2a-booking' ),
						),
					),
					array(
						'code'  => '[g2a_upcoming_events layout="list"]',
						'title' => __( 'Upcoming events list', 'g2a-booking' ),
						'desc'  => __( 'A clean, premium listing of upcoming events. Scope it per page with category or event so a CCW page shows only CCW dates and a Ladies page shows only Ladies nights.', 'g2a-booking' ),
						'attrs' => array(
							'layout'   => __( 'list (Amelia-style rows, default) or card.', 'g2a-booking' ),
							'category' => __( 'Only this category — e.g. ccw-class, ladies, competition.', 'g2a-booking' ),
							'event'    => __( 'A single event id/slug — shows that event\'s upcoming dates.', 'g2a-booking' ),
							'theme'    => __( 'dark (default) or light.', 'g2a-booking' ),
							'limit'    => __( 'How many to show (default 12).', 'g2a-booking' ),
							'search'   => __( 'yes to force a search box (list auto-shows it past 6).', 'g2a-booking' ),
						),
					),
				),
			),
			array(
				'label' => __( 'Staff & customer', 'g2a-booking' ),
				'items' => array(
					array(
						'code'  => '[g2ab_frontdesk]',
						'title' => __( 'Front desk terminal', 'g2a-booking' ),
						'desc'  => __( 'Mounts the staff Front Desk (today’s roster, search, check-in, payments) on a public page for a desk laptop. Staff-capability gated. Alias: [g2ab_staff_console].', 'g2a-booking' ),
						'attrs' => array(),
					),
					array(
						'code'  => '[g2ab_range_console]',
						'title' => __( 'Range Status console', 'g2a-booking' ),
						'desc'  => __( 'Live lane map (in use / reserved / open), KPI cards, walk-in check-in, reservations and a payment feed for a desk laptop. Also at G2A Booking → Range Status. Staff-capability gated.', 'g2a-booking' ),
						'attrs' => array(),
					),
					array(
						'code'  => '[g2ab_self_checkin]',
						'title' => __( 'Customer self check-in', 'g2a-booking' ),
						'desc'  => __( 'The page customers reach from the QR poster: they find their booking (code, or name + phone), confirm their waiver, and check in. Also reachable at /?g2ab_checkin=1.', 'g2a-booking' ),
						'attrs' => array(),
					),
					array(
						'code'  => '[g2ab_reschedule]',
						'title' => __( 'Reschedule booking', 'g2a-booking' ),
						'desc'  => __( 'Customer self-service reschedule page (signed-token protected). Pair with the link in your confirmation emails.', 'g2a-booking' ),
						'attrs' => array(),
					),
					array(
						'code'  => '[g2ab_cancel_booking]',
						'title' => __( 'Cancel booking', 'g2a-booking' ),
						'desc'  => __( 'Customer self-service cancellation page (signed-token protected).', 'g2a-booking' ),
						'attrs' => array(),
					),
				),
			),
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		$this->print_styles();
		?>
		<div class="wrap g2ab-admin g2ab-sc">
			<div class="g2ab-sc__header">
				<div>
					<h1><span class="g2ab-sc__stencil"><?php esc_html_e( 'SHORTCODES', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-sc__sub"><?php esc_html_e( 'Drop these into any page or post. Click a code to copy it.', 'g2a-booking' ); ?></p>
				</div>
			</div>

			<?php foreach ( $this->groups() as $group ) : ?>
				<h2 class="g2ab-sc__group"><?php echo esc_html( $group['label'] ); ?></h2>
				<div class="g2ab-sc__grid">
					<?php foreach ( $group['items'] as $item ) : ?>
						<article class="g2ab-sc__card">
							<div class="g2ab-sc__code-row">
								<code class="g2ab-sc__code"><?php echo esc_html( $item['code'] ); ?></code>
								<button type="button" class="g2ab-sc__copy" data-copy="<?php echo esc_attr( $item['code'] ); ?>"><?php esc_html_e( 'Copy', 'g2a-booking' ); ?></button>
							</div>
							<h3 class="g2ab-sc__title"><?php echo esc_html( $item['title'] ); ?></h3>
							<p class="g2ab-sc__desc"><?php echo esc_html( $item['desc'] ); ?></p>
							<?php if ( ! empty( $item['attrs'] ) ) : ?>
								<table class="g2ab-sc__attrs">
									<?php foreach ( $item['attrs'] as $name => $desc ) : ?>
										<tr><th><?php echo esc_html( $name ); ?></th><td><?php echo esc_html( $desc ); ?></td></tr>
									<?php endforeach; ?>
								</table>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<script>
		(function(){
			document.querySelectorAll('.g2ab-sc__copy').forEach(function(btn){
				btn.addEventListener('click', function(){
					var t = btn.getAttribute('data-copy');
					var done = function(){ var o = btn.textContent; btn.textContent = '<?php echo esc_js( __( 'Copied!', 'g2a-booking' ) ); ?>'; btn.classList.add('is-ok'); setTimeout(function(){ btn.textContent = o; btn.classList.remove('is-ok'); }, 1400); };
					if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function(){ fallback(t); done(); }); }
					else { fallback(t); done(); }
				});
			});
			function fallback(t){ var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try{ document.execCommand('copy'); }catch(e){} document.body.removeChild(ta); }
		})();
		</script>
		<?php
	}

	private function print_styles() {
		?>
		<style id="g2ab-sc-styles">
		.g2ab-sc{--bg:#1A191E;--surface:#26252C;--surface2:#26252C;--border:#2A323D;--text:#F7F7F9;--muted:#A7A6AE;--orange:#E8802F;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
		.g2ab-sc *{box-sizing:border-box;}
		.g2ab-sc__header{margin:18px 0 8px;padding-bottom:14px;border-bottom:2px solid var(--border);}
		.g2ab-sc__stencil{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;letter-spacing:.06em;color:#fff;text-transform:uppercase;}
		.g2ab-sc h1{margin:0;padding:0;}
		.g2ab-sc__sub{margin:4px 0 0;color:var(--muted);font-size:13px;}
		.g2ab-sc h2.g2ab-sc__group{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:16px;letter-spacing:.06em;text-transform:uppercase;color:var(--orange)!important;margin:26px 0 12px;}
		.g2ab-sc__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px;}
		.g2ab-sc__card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:16px 18px;}
		.g2ab-sc__code-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
		.g2ab-sc__code{flex:1;background:var(--bg);border:1px solid var(--border);border-left:3px solid var(--orange);border-radius:5px;padding:9px 11px;font-family:"SF Mono",Monaco,Consolas,monospace;font-size:13px;color:#F2C18A;overflow-x:auto;white-space:nowrap;}
		.g2ab-sc__copy{flex:none;background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:5px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;transition:all .12s ease;}
		.g2ab-sc__copy:hover{border-color:var(--orange);color:#fff;}
		.g2ab-sc__copy.is-ok{background:#1f7a37;border-color:#1f7a37;color:#fff;}
		.g2ab-sc h3.g2ab-sc__title{margin:0 0 6px;color:#fff!important;font-size:16px;font-weight:700;}
		.g2ab-sc__desc{margin:0 0 12px;color:var(--muted);font-size:13px;line-height:1.5;}
		.g2ab-sc__attrs{width:100%;border-collapse:collapse;font-size:12px;}
		.g2ab-sc__attrs th{text-align:left;vertical-align:top;color:var(--orange);font-family:"SF Mono",Monaco,Consolas,monospace;font-weight:600;padding:4px 10px 4px 0;white-space:nowrap;}
		.g2ab-sc__attrs td{vertical-align:top;color:var(--text);padding:4px 0;}
		</style>
		<?php
	}
}
