<?php
/**
 * Admin: Shortcodes reference screen.
 *
 * A read-only reference tab under the G2A Booking menu listing every
 * booking, event and form shortcode the plugin provides — with a
 * description, available attributes, and one-click copy.
 *
 * @package G2AB
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Admin_Shortcodes {

	const SLUG = 'g2ab-shortcodes';
	const CAP  = 'manage_g2ab_bookings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 12 );
	}

	public function register_menu() {
		add_submenu_page(
			'g2ab-bookings',
			__( 'Shortcodes', 'g2a-booking' ),
			__( 'Shortcodes', 'g2a-booking' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The shortcode catalogue, grouped by section.
	 *
	 * @return array<string, array<int, array>>
	 */
	private function catalogue() {
		return array(
			__( 'Booking Forms', 'g2a-booking' ) => array(
				array(
					'code' => '[g2a_lane_booking]',
					'desc' => __( 'The full lane booking flow — date, time, lanes, shooter details and payment. Place it on the Book A Lane page.', 'g2a-booking' ),
					'atts' => array(
						'booking_type' => __( 'Booking type slug (default: lane-booking).', 'g2a-booking' ),
						'form'         => __( 'Form slug to use (default: default-lane-booking).', 'g2a-booking' ),
						'theme'        => __( 'Override the form theme/skin.', 'g2a-booking' ),
					),
				),
				array(
					'code' => '[g2a_booking_form]',
					'desc' => __( 'A class / course booking form for a specific booking type (e.g. CCW class, Ladies Tuesday).', 'g2a-booking' ),
					'atts' => array(
						'booking_type' => __( 'Booking type slug to book.', 'g2a-booking' ),
						'form'         => __( 'Form slug to use.', 'g2a-booking' ),
					),
				),
			),
			__( 'Events', 'g2a-booking' ) => array(
				array(
					'code' => '[g2a_events_list]',
					'desc' => __( 'Upcoming events as a vertical list — date block, details and a button per event.', 'g2a-booking' ),
					'atts' => array(
						'limit' => __( 'Maximum events to show (default: 10).', 'g2a-booking' ),
						'title' => __( 'Optional heading shown above the list.', 'g2a-booking' ),
					),
				),
				array(
					'code' => '[g2a_events_calendar]',
					'desc' => __( 'Upcoming events on a month calendar grid, with previous / next month navigation.', 'g2a-booking' ),
					'atts' => array(
						'title' => __( 'Optional heading.', 'g2a-booking' ),
					),
				),
				array(
					'code' => '[g2a_events_carousel]',
					'desc' => __( 'Upcoming events as horizontal scrolling cards with arrow navigation.', 'g2a-booking' ),
					'atts' => array(
						'limit' => __( 'Maximum events to show (default: 12).', 'g2a-booking' ),
						'title' => __( 'Optional heading.', 'g2a-booking' ),
					),
				),
				array(
					'code' => '[g2a_event_banner]',
					'desc' => __( 'A single featured event banner — auto-features your first active class booking type.', 'g2a-booking' ),
					'atts' => array(
						'type' => __( 'Pin a specific booking type by slug (e.g. ccw-class).', 'g2a-booking' ),
					),
				),
				array(
					'code' => '[g2a_upcoming_events]',
					'desc' => __( 'A compact list of upcoming class-based booking types.', 'g2a-booking' ),
					'atts' => array(
						'limit' => __( 'Maximum entries to show.', 'g2a-booking' ),
					),
				),
			),
			__( 'Front Desk & Member Actions', 'g2a-booking' ) => array(
				array(
					'code' => '[g2ab_frontdesk]',
					'desc' => __( "Staff front-desk terminal — today's roster, search and quick check-in actions. Use on a staff-only page.", 'g2a-booking' ),
					'atts' => array(),
				),
				array(
					'code' => '[g2ab_reschedule]',
					'desc' => __( 'Lets a customer reschedule their booking from a link in their confirmation email.', 'g2a-booking' ),
					'atts' => array(),
				),
				array(
					'code' => '[g2ab_cancel_booking]',
					'desc' => __( 'Lets a customer cancel their booking from a link in their confirmation email.', 'g2a-booking' ),
					'atts' => array(),
				),
			),
		);
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		}
		?>
		<div class="wrap g2ab-sc">
			<h1><?php esc_html_e( 'Shortcodes', 'g2a-booking' ); ?></h1>
			<p class="g2ab-sc__lead">
				<?php esc_html_e( 'Drop any of these shortcodes into a page or post to display booking forms, events and front-desk tools. Click a code to copy it.', 'g2a-booking' ); ?>
			</p>

			<?php foreach ( $this->catalogue() as $group => $items ) : ?>
				<h2 class="g2ab-sc__group"><?php echo esc_html( $group ); ?></h2>
				<div class="g2ab-sc__grid">
					<?php foreach ( $items as $item ) : ?>
						<div class="g2ab-sc__card">
							<button type="button" class="g2ab-sc__code" data-g2ab-copy="<?php echo esc_attr( $item['code'] ); ?>" title="<?php esc_attr_e( 'Click to copy', 'g2a-booking' ); ?>">
								<code><?php echo esc_html( $item['code'] ); ?></code>
								<span class="g2ab-sc__copy"><?php esc_html_e( 'Copy', 'g2a-booking' ); ?></span>
							</button>
							<p class="g2ab-sc__desc"><?php echo esc_html( $item['desc'] ); ?></p>
							<?php if ( ! empty( $item['atts'] ) ) : ?>
								<table class="g2ab-sc__atts">
									<?php foreach ( $item['atts'] as $name => $note ) : ?>
										<tr>
											<th><?php echo esc_html( $name ); ?></th>
											<td><?php echo esc_html( $note ); ?></td>
										</tr>
									<?php endforeach; ?>
								</table>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<style>
		.g2ab-sc{max-width:1100px;}
		.g2ab-sc__lead{font-size:14px;color:#50575e;margin:6px 0 18px;}
		.g2ab-sc__group{font-size:15px;margin:26px 0 10px;color:#1d2327;border-bottom:1px solid #dcdcde;padding-bottom:8px;}
		.g2ab-sc__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px;}
		.g2ab-sc__card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;}
		.g2ab-sc__code{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;
			background:#1d2327;border:0;border-radius:6px;padding:10px 12px;cursor:pointer;text-align:left;}
		.g2ab-sc__code code{background:transparent;color:#7ad0ff;font-size:13px;font-weight:600;padding:0;}
		.g2ab-sc__copy{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#fff;
			background:#2271b1;border-radius:3px;padding:3px 8px;flex:0 0 auto;}
		.g2ab-sc__code.is-copied .g2ab-sc__copy{background:#1e8a4c;}
		.g2ab-sc__desc{font-size:13px;color:#3c434a;line-height:1.55;margin:10px 0 0;}
		.g2ab-sc__atts{width:100%;border-collapse:collapse;margin-top:10px;}
		.g2ab-sc__atts th{text-align:left;width:120px;vertical-align:top;padding:5px 8px 5px 0;
			font-size:12px;color:#1d2327;font-family:monospace;}
		.g2ab-sc__atts td{padding:5px 0;font-size:12px;color:#50575e;border-bottom:1px solid #f0f0f1;}
		</style>
		<script>
		(function(){
			document.querySelectorAll('[data-g2ab-copy]').forEach(function(btn){
				btn.addEventListener('click',function(){
					var text=btn.getAttribute('data-g2ab-copy');
					var done=function(){
						btn.classList.add('is-copied');
						var c=btn.querySelector('.g2ab-sc__copy');
						if(c){var o=c.textContent;c.textContent='Copied';setTimeout(function(){c.textContent=o;btn.classList.remove('is-copied');},1600);}
					};
					if(navigator.clipboard&&navigator.clipboard.writeText){
						navigator.clipboard.writeText(text).then(done).catch(function(){done();});
					}else{
						var t=document.createElement('textarea');t.value=text;document.body.appendChild(t);
						t.select();try{document.execCommand('copy');}catch(e){}document.body.removeChild(t);done();
					}
				});
			});
		})();
		</script>
		<?php
	}
}
