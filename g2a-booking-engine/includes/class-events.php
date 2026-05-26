<?php
/**
 * Events — a custom post type with an admin tab under G2A Booking, plus
 * three front-end banner views (list, calendar, carousel) each exposed as
 * its own shortcode.
 *
 * Shortcodes:
 *   [g2a_events_list]      — vertical list view
 *   [g2a_events_calendar]  — month calendar grid
 *   [g2a_events_carousel]  — horizontal scrolling event cards
 *
 * @package G2AB
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Events {

	const CPT = 'g2a_events';

	/** @var G2AB_Events|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_cpt();

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( $this, 'admin_column_value' ), 10, 2 );

		add_shortcode( 'g2a_events_list', array( $this, 'render_list' ) );
		add_shortcode( 'g2a_events_calendar', array( $this, 'render_calendar' ) );
		add_shortcode( 'g2a_events_carousel', array( $this, 'render_carousel' ) );
		add_shortcode( 'g2a_events', array( $this, 'render_events_shortcode' ) );
		add_shortcode( 'g2a_event_countdown', array( $this, 'render_event_countdown' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Custom post type                                                   */
	/* ------------------------------------------------------------------ */

	private function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'               => __( 'Events', 'g2a-booking' ),
					'singular_name'      => __( 'Event', 'g2a-booking' ),
					'menu_name'          => __( 'Events', 'g2a-booking' ),
					'add_new'            => __( 'Add Event', 'g2a-booking' ),
					'add_new_item'       => __( 'Add New Event', 'g2a-booking' ),
					'edit_item'          => __( 'Edit Event', 'g2a-booking' ),
					'new_item'           => __( 'New Event', 'g2a-booking' ),
					'view_item'          => __( 'View Event', 'g2a-booking' ),
					'search_items'       => __( 'Search Events', 'g2a-booking' ),
					'all_items'          => __( 'Events', 'g2a-booking' ),
					'not_found'          => __( 'No events created yet.', 'g2a-booking' ),
					'not_found_in_trash' => __( 'No events in trash.', 'g2a-booking' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'g2ab-bookings',
				'show_in_rest'        => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				'menu_icon'           => 'dashicons-tickets-alt',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'capability_type'     => 'post',
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Admin — event details meta box                                     */
	/* ------------------------------------------------------------------ */

	public function add_meta_box() {
		add_meta_box(
			'g2ab_event_details',
			__( 'Event Details', 'g2a-booking' ),
			array( $this, 'render_meta_box' ),
			self::CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Field definitions: meta key => [ label, type, placeholder ].
	 */
	private function fields() {
		return array(
			'_g2ab_event_date'      => array( __( 'Event date', 'g2a-booking' ), 'date', '' ),
			'_g2ab_event_time'      => array( __( 'Start time', 'g2a-booking' ), 'text', 'e.g. 10:00 AM' ),
			'_g2ab_event_type'      => array( __( 'Event type', 'g2a-booking' ), 'text', 'e.g. ccw' ),
			'_g2ab_event_instructor'=> array( __( 'Instructor', 'g2a-booking' ), 'text', 'e.g. John Doe' ),
			'_g2ab_event_seats'     => array( __( 'Total seats', 'g2a-booking' ), 'number', 'e.g. 12' ),
			'_g2ab_event_booking_type_slug' => array( __( 'Booking type slug', 'g2a-booking' ), 'text', 'e.g. ccw-class' ),
			'_g2ab_event_end'       => array( __( 'End time', 'g2a-booking' ), 'text', 'e.g. 2:00 PM' ),
			'_g2ab_event_location'  => array( __( 'Location', 'g2a-booking' ), 'text', '6030 E Main St, Mesa, AZ' ),
			'_g2ab_event_price'     => array( __( 'Price', 'g2a-booking' ), 'text', 'e.g. $149.99 or Free' ),
			'_g2ab_event_cta_label' => array( __( 'Button label', 'g2a-booking' ), 'text', 'e.g. Reserve a Spot' ),
			'_g2ab_event_cta_url'   => array( __( 'Button link (URL)', 'g2a-booking' ), 'url', 'https://…' ),
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'g2ab_event_meta', 'g2ab_event_meta_nonce' );
		echo '<style>.g2ab-evm{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:6px;}'
			. '.g2ab-evm label{display:block;font-weight:600;margin-bottom:4px;}'
			. '.g2ab-evm input{width:100%;}.g2ab-evm .wide{grid-column:1 / -1;}</style>';
		echo '<div class="g2ab-evm">';
		foreach ( $this->fields() as $key => $field ) {
			$value = get_post_meta( $post->ID, $key, true );
			$wide  = in_array( $key, array( '_g2ab_event_location', '_g2ab_event_cta_url' ), true ) ? ' wide' : '';
			printf(
				'<div class="%s"><label for="%s">%s</label><input type="%s" id="%s" name="%s" value="%s" placeholder="%s" /></div>',
				esc_attr( trim( $wide ) ),
				esc_attr( $key ),
				esc_html( $field[0] ),
				esc_attr( $field[1] ),
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( $field[2] )
			);
		}
		$featured = get_post_meta( $post->ID, '_g2ab_event_featured', true );
		printf(
			'<div class="wide"><label><input type="checkbox" name="_g2ab_event_featured" value="1" %s /> %s</label></div>',
			checked( $featured, '1', false ),
			esc_html__( 'Feature this event (highlighted in banner views)', 'g2a-booking' )
		);
		echo '</div>';
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['g2ab_event_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['g2ab_event_meta_nonce'] ) ), 'g2ab_event_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( $this->fields() as $key => $field ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			if ( 'url' === $field[1] ) {
				$clean = esc_url_raw( $raw );
			} elseif ( 'number' === $field[1] ) {
				$clean = (string) max( 0, absint( $raw ) );
			} else {
				$clean = sanitize_text_field( $raw );
			}
			if ( '' === $clean ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $clean );
			}
		}
		update_post_meta( $post_id, '_g2ab_event_featured', empty( $_POST['_g2ab_event_featured'] ) ? '' : '1' );
	}

	public function admin_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['g2ab_event_date'] = __( 'Event Date', 'g2a-booking' );
				$out['g2ab_event_when'] = __( 'Time', 'g2a-booking' );
			}
		}
		return $out;
	}

	public function admin_column_value( $column, $post_id ) {
		if ( 'g2ab_event_date' === $column ) {
			$date = get_post_meta( $post_id, '_g2ab_event_date', true );
			echo $date ? esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ) : '—';
		}
		if ( 'g2ab_event_when' === $column ) {
			$time = get_post_meta( $post_id, '_g2ab_event_time', true );
			echo $time ? esc_html( $time ) : '—';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Query helper                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch upcoming (and today's) events ordered by event date.
	 *
	 * @param int  $limit       Max events.
	 * @param bool $include_past Include events whose date has passed.
	 * @return WP_Post[]
	 */
	private function get_events( $limit = 12, $include_past = false ) {
		$meta_query = array(
			'date_clause' => array(
				'key'  => '_g2ab_event_date',
				'type' => 'DATE',
			),
		);
		if ( ! $include_past ) {
			$meta_query['date_clause']['compare'] = '>=';
			$meta_query['date_clause']['value']   = current_time( 'Y-m-d' );
		}

		return get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $limit ),
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => array( 'date_clause' => 'ASC' ),
			)
		);
	}

	/**
	 * Normalize one event post into a flat data array for rendering.
	 */
	private function event_data( $post ) {
		$date = get_post_meta( $post->ID, '_g2ab_event_date', true );
		$ts   = $date ? strtotime( $date ) : 0;
		return array(
			'title'     => get_the_title( $post ),
			'desc'      => has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 26 ),
			'image'     => get_the_post_thumbnail_url( $post, 'large' ),
			'date'      => $date,
			'ts'        => $ts,
			'day'       => $ts ? date_i18n( 'j', $ts ) : '',
			'month'     => $ts ? date_i18n( 'M', $ts ) : '',
			'weekday'   => $ts ? date_i18n( 'D', $ts ) : '',
			'datelabel' => $ts ? date_i18n( 'l, F j, Y', $ts ) : '',
			'time'      => get_post_meta( $post->ID, '_g2ab_event_time', true ),
			'type'      => get_post_meta( $post->ID, '_g2ab_event_type', true ),
			'instructor'=> get_post_meta( $post->ID, '_g2ab_event_instructor', true ),
			'seats'     => absint( get_post_meta( $post->ID, '_g2ab_event_seats', true ) ),
			'booking_type_slug' => sanitize_title( get_post_meta( $post->ID, '_g2ab_event_booking_type_slug', true ) ),
			'end'       => get_post_meta( $post->ID, '_g2ab_event_end', true ),
			'location'  => get_post_meta( $post->ID, '_g2ab_event_location', true ),
			'price'     => get_post_meta( $post->ID, '_g2ab_event_price', true ),
			'cta_label' => get_post_meta( $post->ID, '_g2ab_event_cta_label', true ),
			'cta_url'   => get_post_meta( $post->ID, '_g2ab_event_cta_url', true ),
			'featured'  => '1' === get_post_meta( $post->ID, '_g2ab_event_featured', true ),
		);
	}

	private function time_range( $e ) {
		$out = $e['time'];
		if ( $e['time'] && $e['end'] ) {
			$out = $e['time'] . ' – ' . $e['end'];
		}
		return $out;
	}

	private function print_basic_event_ui_css() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
		.g2ab-events-shortcode{display:grid;gap:16px;max-width:980px}
		.g2ab-events-shortcode-item{background:#1e1f29;border:1px solid rgba(255,255,255,.1);padding:20px;border-radius:4px}
		.g2ab-events-shortcode-item h3{margin:0 0 12px;color:#fff;font-family:"Bebas Neue","Oswald",sans-serif;letter-spacing:.03em;font-size:34px;line-height:1}
		.g2ab-events-shortcode-item p{margin:8px 0;color:#d5d8e3;font-size:20px}
		.g2ab-events-shortcode-item a{display:inline-block;margin-top:8px;background:#e8802f;color:#fff!important;text-decoration:none;padding:10px 16px;border-radius:2px;font-family:"Barlow Condensed","Oswald",sans-serif;letter-spacing:.08em;text-transform:uppercase}
		.g2ab-events-shortcode-item a:hover{background:#f3974f}
		.g2ab-event-countdown{background:#1e1f29;border:1px solid rgba(255,255,255,.12);padding:24px;border-radius:4px;max-width:980px}
		.g2ab-event-countdown h3{margin:0 0 10px;color:#fff;font-family:"Bebas Neue","Oswald",sans-serif;font-size:46px;line-height:1}
		.g2ab-event-countdown p{margin:8px 0;color:#d5d8e3}
		.g2ab-event-countdown-timer{display:flex;gap:10px;align-items:center;margin:12px 0 16px;font-family:"Rajdhani","Oswald",sans-serif;color:#f2f3f8;font-size:32px;letter-spacing:.02em}
		.g2ab-event-countdown-timer span{display:inline-block;min-width:56px;text-align:center;background:#12131a;border:1px solid rgba(201,168,76,.45);color:#c9a84c;padding:8px 6px;border-radius:3px}
		.g2ab-event-countdown a{display:inline-block;background:#e8802f;color:#fff!important;text-decoration:none;padding:10px 16px;border-radius:2px;font-family:"Barlow Condensed","Oswald",sans-serif;letter-spacing:.08em;text-transform:uppercase}
		.g2ab-event-countdown a:hover{background:#f3974f}
		@media (max-width:768px){
			.g2ab-events-shortcode-item h3{font-size:30px}
			.g2ab-events-shortcode-item p{font-size:18px}
			.g2ab-event-countdown h3{font-size:36px}
			.g2ab-event-countdown-timer{font-size:24px;flex-wrap:wrap}
			.g2ab-event-countdown-timer span{min-width:48px}
		}
		</style>
		<?php
	}

	private function next_event_by_type( $type = '' ) {
		$events = $this->get_events( 50, false );
		foreach ( $events as $post ) {
			$event = $this->event_data( $post );
			if ( $type && sanitize_key( (string) $event['type'] ) !== sanitize_key( (string) $type ) ) {
				continue;
			}
			return $event;
		}
		return null;
	}

	public function render_events_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'type'  => '',
				'limit' => 10,
			),
			$atts,
			'g2a_events'
		);
		$type  = sanitize_key( (string) $atts['type'] );
		$limit = max( 1, min( 50, (int) $atts['limit'] ) );
		$posts = $this->get_events( $limit, false );
		$rows  = array();

		foreach ( $posts as $post ) {
			$event = $this->event_data( $post );
			$row_type = sanitize_key( (string) $event['type'] );
			if ( $type && $row_type !== $type ) {
				continue;
			}
			$rows[] = $event;
		}

		if ( empty( $rows ) ) {
			return '<div class="g2ab-events-shortcode-empty">' . esc_html__( 'No upcoming events found.', 'g2a-booking' ) . '</div>';
		}

		ob_start();
		$this->print_basic_event_ui_css();
		echo '<div class="g2ab-events-shortcode">';
		foreach ( $rows as $e ) {
			echo '<article class="g2ab-events-shortcode-item">';
			echo '<h3>' . esc_html( $e['title'] ) . '</h3>';
			echo '<p><strong>' . esc_html__( 'Date', 'g2a-booking' ) . ':</strong> ' . esc_html( $e['datelabel'] ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Time', 'g2a-booking' ) . ':</strong> ' . esc_html( $this->time_range( $e ) ) . '</p>';
			if ( ! empty( $e['instructor'] ) ) {
				echo '<p><strong>' . esc_html__( 'Instructor', 'g2a-booking' ) . ':</strong> ' . esc_html( $e['instructor'] ) . '</p>';
			}
			if ( ! empty( $e['seats'] ) ) {
				echo '<p><strong>' . esc_html__( 'Seats', 'g2a-booking' ) . ':</strong> ' . esc_html( (string) $e['seats'] ) . '</p>';
			}
			if ( ! empty( $e['price'] ) ) {
				echo '<p><strong>' . esc_html__( 'Price', 'g2a-booking' ) . ':</strong> ' . esc_html( $e['price'] ) . '</p>';
			}
			if ( ! empty( $e['cta_url'] ) ) {
				$label = ! empty( $e['cta_label'] ) ? $e['cta_label'] : __( 'Book Now', 'g2a-booking' );
				echo '<p><a href="' . esc_url( $e['cta_url'] ) . '">' . esc_html( $label ) . '</a></p>';
			}
			echo '</article>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	public function render_event_countdown( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'type'         => '',
				'title'        => __( 'Next Event Starts In', 'g2a-booking' ),
				'button_label' => __( 'Book This Event', 'g2a-booking' ),
			),
			$atts,
			'g2a_event_countdown'
		);

		$event = $this->next_event_by_type( (string) $atts['type'] );
		if ( ! $event || empty( $event['date'] ) ) {
			return '<div class="g2ab-events-shortcode-empty">' . esc_html__( 'No upcoming events found.', 'g2a-booking' ) . '</div>';
		}

		$time     = ! empty( $event['time'] ) ? $event['time'] : '09:00 AM';
		$event_ts = strtotime( trim( $event['date'] . ' ' . $time ) );
		if ( ! $event_ts ) {
			return '<div class="g2ab-events-shortcode-empty">' . esc_html__( 'Event date/time is not configured.', 'g2a-booking' ) . '</div>';
		}

		$booking_slug = ! empty( $event['booking_type_slug'] ) ? $event['booking_type_slug'] : 'ccw-class';
		$book_url     = esc_url( add_query_arg( 'type', $booking_slug, home_url( '/book-a-lane/' ) ) );
		$countdown_id = 'g2ab-countdown-' . wp_generate_password( 8, false, false );

		ob_start();
		$this->print_basic_event_ui_css();
		?>
		<div class="g2ab-event-countdown" id="<?php echo esc_attr( $countdown_id ); ?>" data-event-ts="<?php echo esc_attr( (string) $event_ts ); ?>">
			<h3><?php echo esc_html( $atts['title'] ); ?></h3>
			<p><strong><?php echo esc_html( $event['title'] ); ?></strong> - <?php echo esc_html( $event['datelabel'] . ' ' . $time ); ?></p>
			<div class="g2ab-event-countdown-timer">
				<span data-dd>00</span>d
				<span data-hh>00</span>h
				<span data-mm>00</span>m
				<span data-ss>00</span>s
			</div>
			<p><a href="<?php echo $book_url; ?>"><?php echo esc_html( $atts['button_label'] ); ?></a></p>
		</div>
		<script>
		(function(){
			var el = document.getElementById(<?php echo wp_json_encode( $countdown_id ); ?>);
			if (!el) return;
			var target = parseInt(el.getAttribute('data-event-ts') || '0', 10) * 1000;
			var dd = el.querySelector('[data-dd]'), hh = el.querySelector('[data-hh]'),
				mm = el.querySelector('[data-mm]'), ss = el.querySelector('[data-ss]');
			function pad(n){ return n < 10 ? '0' + n : '' + n; }
			function tick(){
				var now = Date.now();
				var diff = Math.max(0, target - now);
				var sec = Math.floor(diff / 1000);
				var d = Math.floor(sec / 86400); sec %= 86400;
				var h = Math.floor(sec / 3600); sec %= 3600;
				var m = Math.floor(sec / 60); sec %= 60;
				dd.textContent = pad(d); hh.textContent = pad(h); mm.textContent = pad(m); ss.textContent = pad(sec);
			}
			tick(); setInterval(tick, 1000);
		})();
		</script>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Shortcode: LIST view                                               */
	/* ------------------------------------------------------------------ */

	public function render_list( $atts ) {
		$atts   = shortcode_atts( array( 'limit' => 10, 'title' => '' ), $atts, 'g2a_events_list' );
		$events = $this->get_events( (int) $atts['limit'] );

		ob_start();
		echo '<div class="g2a-ev g2a-ev-list">';
		$this->print_css();
		if ( $atts['title'] ) {
			echo '<h2 class="g2a-ev-heading">' . esc_html( $atts['title'] ) . '</h2>';
		}
		if ( ! $events ) {
			$this->empty_state();
		} else {
			foreach ( $events as $post ) {
				$e   = $this->event_data( $post );
				$cta = $e['cta_url'] ? $e['cta_url'] : '';
				echo '<article class="g2a-ev-row' . ( $e['featured'] ? ' is-featured' : '' ) . '">';
				echo '<div class="g2a-ev-datebox"><span class="g2a-ev-d">' . esc_html( $e['day'] ) . '</span>'
					. '<span class="g2a-ev-m">' . esc_html( $e['month'] ) . '</span></div>';
				echo '<div class="g2a-ev-rowbody">';
				if ( $e['featured'] ) {
					echo '<span class="g2a-ev-flag">' . esc_html__( 'Featured', 'g2a-booking' ) . '</span>';
				}
				echo '<h3 class="g2a-ev-rowtitle">' . esc_html( $e['title'] ) . '</h3>';
				echo '<div class="g2a-ev-meta">';
				if ( $this->time_range( $e ) ) {
					echo '<span>◷ ' . esc_html( $this->time_range( $e ) ) . '</span>';
				}
				if ( $e['location'] ) {
					echo '<span>⚑ ' . esc_html( $e['location'] ) . '</span>';
				}
				if ( $e['price'] ) {
					echo '<span class="g2a-ev-price">' . esc_html( $e['price'] ) . '</span>';
				}
				echo '</div>';
				if ( $e['desc'] ) {
					echo '<p class="g2a-ev-desc">' . esc_html( $e['desc'] ) . '</p>';
				}
				echo '</div>';
				if ( $cta ) {
					echo '<a class="g2a-ev-cta" href="' . esc_url( $cta ) . '">'
						. esc_html( $e['cta_label'] ? $e['cta_label'] : __( 'Details', 'g2a-booking' ) ) . '</a>';
				}
				echo '</article>';
			}
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Shortcode: CAROUSEL view                                           */
	/* ------------------------------------------------------------------ */

	public function render_carousel( $atts ) {
		$atts   = shortcode_atts( array( 'limit' => 12, 'title' => '' ), $atts, 'g2a_events_carousel' );
		$events = $this->get_events( (int) $atts['limit'] );

		ob_start();
		echo '<div class="g2a-ev g2a-ev-carousel">';
		$this->print_css();
		echo '<div class="g2a-ev-carhd">';
		if ( $atts['title'] ) {
			echo '<h2 class="g2a-ev-heading">' . esc_html( $atts['title'] ) . '</h2>';
		}
		if ( $events ) {
			echo '<div class="g2a-ev-carnav"><button type="button" class="g2a-ev-arrow" data-ev-prev aria-label="Previous">‹</button>'
				. '<button type="button" class="g2a-ev-arrow" data-ev-next aria-label="Next">›</button></div>';
		}
		echo '</div>';

		if ( ! $events ) {
			$this->empty_state();
		} else {
			echo '<div class="g2a-ev-track" data-ev-track>';
			foreach ( $events as $post ) {
				$e   = $this->event_data( $post );
				$cta = $e['cta_url'] ? $e['cta_url'] : '';
				echo '<article class="g2a-ev-card' . ( $e['featured'] ? ' is-featured' : '' ) . '">';
				echo '<div class="g2a-ev-cardimg"' . ( $e['image'] ? ' style="background-image:url(\'' . esc_url( $e['image'] ) . '\')"' : '' ) . '>';
				echo '<span class="g2a-ev-cardchip">' . esc_html( $e['month'] . ' ' . $e['day'] ) . '</span>';
				if ( $e['featured'] ) {
					echo '<span class="g2a-ev-flag g2a-ev-flag--abs">' . esc_html__( 'Featured', 'g2a-booking' ) . '</span>';
				}
				echo '</div>';
				echo '<div class="g2a-ev-cardbody">';
				echo '<h3 class="g2a-ev-rowtitle">' . esc_html( $e['title'] ) . '</h3>';
				echo '<div class="g2a-ev-meta">';
				if ( $e['datelabel'] ) {
					echo '<span>' . esc_html( $e['datelabel'] ) . '</span>';
				}
				if ( $this->time_range( $e ) ) {
					echo '<span>◷ ' . esc_html( $this->time_range( $e ) ) . '</span>';
				}
				if ( $e['price'] ) {
					echo '<span class="g2a-ev-price">' . esc_html( $e['price'] ) . '</span>';
				}
				echo '</div>';
				if ( $e['desc'] ) {
					echo '<p class="g2a-ev-desc">' . esc_html( $e['desc'] ) . '</p>';
				}
				if ( $cta ) {
					echo '<a class="g2a-ev-cta" href="' . esc_url( $cta ) . '">'
						. esc_html( $e['cta_label'] ? $e['cta_label'] : __( 'Reserve', 'g2a-booking' ) ) . '</a>';
				}
				echo '</div>';
				echo '</article>';
			}
			echo '</div>';
		}
		echo '</div>';
		$this->print_carousel_js();
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Shortcode: CALENDAR view                                           */
	/* ------------------------------------------------------------------ */

	public function render_calendar( $atts ) {
		$atts = shortcode_atts( array( 'title' => '' ), $atts, 'g2a_events_calendar' );

		// Which month to show — ?g2a_evm=YYYY-MM, default current month.
		$req   = isset( $_GET['g2a_evm'] ) ? sanitize_text_field( wp_unslash( $_GET['g2a_evm'] ) ) : '';
		$month = preg_match( '/^\d{4}-\d{2}$/', $req ) ? $req : current_time( 'Y-m' );
		$first = strtotime( $month . '-01' );
		$days  = (int) date( 't', $first );
		$start = (int) date( 'w', $first );

		// All events (incl. past) grouped by Y-m-d for the visible month.
		$by_day = array();
		foreach ( $this->get_events( 200, true ) as $post ) {
			$e = $this->event_data( $post );
			if ( $e['date'] && 0 === strpos( $e['date'], $month ) ) {
				$by_day[ $e['date'] ][] = $e;
			}
		}

		$prev = date( 'Y-m', strtotime( '-1 month', $first ) );
		$next = date( 'Y-m', strtotime( '+1 month', $first ) );

		ob_start();
		echo '<div class="g2a-ev g2a-ev-cal">';
		$this->print_css();
		echo '<div class="g2a-ev-calhd">';
		echo '<a class="g2a-ev-arrow" href="' . esc_url( add_query_arg( 'g2a_evm', $prev ) ) . '">‹</a>';
		echo '<span class="g2a-ev-calmonth">' . esc_html( date_i18n( 'F Y', $first ) ) . '</span>';
		echo '<a class="g2a-ev-arrow" href="' . esc_url( add_query_arg( 'g2a_evm', $next ) ) . '">›</a>';
		echo '</div>';
		echo '<div class="g2a-ev-calgrid">';
		foreach ( array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ) as $dow ) {
			echo '<div class="g2a-ev-dow">' . esc_html( $dow ) . '</div>';
		}
		for ( $i = 0; $i < $start; $i++ ) {
			echo '<div class="g2a-ev-cell is-empty"></div>';
		}
		$today = current_time( 'Y-m-d' );
		for ( $d = 1; $d <= $days; $d++ ) {
			$ymd  = sprintf( '%s-%02d', $month, $d );
			$list = isset( $by_day[ $ymd ] ) ? $by_day[ $ymd ] : array();
			$cls  = 'g2a-ev-cell' . ( $list ? ' has-events' : '' ) . ( $ymd === $today ? ' is-today' : '' );
			echo '<div class="' . esc_attr( $cls ) . '">';
			echo '<span class="g2a-ev-celln">' . esc_html( $d ) . '</span>';
			foreach ( $list as $e ) {
				$cta = $e['cta_url'] ? $e['cta_url'] : '#';
				echo '<a class="g2a-ev-chip' . ( $e['featured'] ? ' is-featured' : '' ) . '" href="' . esc_url( $cta ) . '" title="' . esc_attr( $e['title'] . ( $e['time'] ? ' · ' . $e['time'] : '' ) ) . '">'
					. ( $e['time'] ? '<b>' . esc_html( $e['time'] ) . '</b> ' : '' )
					. esc_html( $e['title'] ) . '</a>';
			}
			echo '</div>';
		}
		echo '</div></div>';
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Shared output helpers                                              */
	/* ------------------------------------------------------------------ */

	private function empty_state() {
		echo '<div class="g2a-ev-empty"><p>'
			. esc_html__( 'No upcoming events are scheduled right now — check back soon.', 'g2a-booking' )
			. '</p></div>';
	}

	private function print_carousel_js() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<script>
		(function(){
			document.querySelectorAll('.g2a-ev-carousel').forEach(function(wrap){
				var track=wrap.querySelector('[data-ev-track]');
				if(!track)return;
				var step=function(){var c=track.querySelector('.g2a-ev-card');return c?c.offsetWidth+18:320;};
				var prev=wrap.querySelector('[data-ev-prev]'),next=wrap.querySelector('[data-ev-next]');
				if(prev)prev.addEventListener('click',function(){track.scrollBy({left:-step(),behavior:'smooth'});});
				if(next)next.addEventListener('click',function(){track.scrollBy({left:step(),behavior:'smooth'});});
			});
		})();
		</script>
		<?php
	}

	private function print_css() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<style>
		.g2a-ev{--ev-card:#26252C;--ev-card2:#201F26;--ev-line:rgba(255,255,255,.1);--ev-brass:#C9A84C;
			--ev-brass2:#E3C06A;--ev-ember:#E8802F;--ev-white:#F4F4F6;--ev-fog:#CBCAD2;--ev-silver:#8E8D96;
			font-family:var(--font-body,"DM Sans",-apple-system,Segoe UI,sans-serif);max-width:1280px;margin:0 auto;}
		.g2a-ev *{box-sizing:border-box;}
		.g2a-ev-heading{font-family:var(--font-display,"Bebas Neue",sans-serif);color:var(--ev-white);
			font-size:clamp(28px,4vw,44px);letter-spacing:.02em;margin:0 0 20px;line-height:1;}
		.g2a-ev-empty{background:var(--ev-card);border:1px solid var(--ev-line);border-radius:4px;
			padding:38px;text-align:center;color:var(--ev-silver);}
		.g2a-ev-flag{display:inline-block;background:var(--ev-brass);color:#1A191E;font-family:var(--font-mono,monospace);
			font-size:9px;letter-spacing:.16em;text-transform:uppercase;padding:3px 8px;border-radius:2px;margin-bottom:8px;}
		.g2a-ev-flag--abs{position:absolute;top:12px;left:12px;margin:0;}
		.g2a-ev-rowtitle{font-family:var(--font-display,"Bebas Neue",sans-serif);color:var(--ev-white);
			font-size:24px;letter-spacing:.02em;line-height:1.05;margin:0 0 8px;}
		.g2a-ev-meta{display:flex;flex-wrap:wrap;gap:8px 16px;font-family:var(--font-mono,monospace);
			font-size:11px;letter-spacing:.06em;color:var(--ev-silver);text-transform:uppercase;margin-bottom:8px;}
		.g2a-ev-price{color:var(--ev-brass2)!important;font-weight:700;}
		.g2a-ev-desc{color:var(--ev-fog);font-size:14px;line-height:1.6;margin:0;}
		.g2a-ev-cta{display:inline-block;background:var(--ev-ember);color:#fff;text-decoration:none;
			font-family:var(--font-condensed,"Barlow Condensed",sans-serif);font-weight:600;font-size:13px;
			letter-spacing:.1em;text-transform:uppercase;padding:11px 20px;border-radius:2px;white-space:nowrap;}
		.g2a-ev-cta:hover{background:#F4933F;color:#fff;}

		/* LIST */
		.g2a-ev-row{display:flex;gap:18px;align-items:center;background:var(--ev-card);
			border:1px solid var(--ev-line);border-radius:4px;padding:18px 20px;margin-bottom:12px;}
		.g2a-ev-row.is-featured{border-color:var(--ev-brass);}
		.g2a-ev-datebox{flex:0 0 64px;text-align:center;border:1px solid var(--ev-line);border-radius:3px;
			padding:10px 6px;background:var(--ev-card2);}
		.g2a-ev-d{display:block;font-family:var(--font-display,"Bebas Neue",sans-serif);font-size:30px;
			color:var(--ev-brass2);line-height:1;}
		.g2a-ev-m{display:block;font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.14em;
			text-transform:uppercase;color:var(--ev-silver);margin-top:3px;}
		.g2a-ev-rowbody{flex:1;min-width:0;}
		.g2a-ev-row .g2a-ev-cta{flex:0 0 auto;}
		@media(max-width:640px){
			.g2a-ev-row{flex-wrap:wrap;}
			.g2a-ev-row .g2a-ev-cta{width:100%;text-align:center;}
		}

		/* CAROUSEL */
		.g2a-ev-carhd{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px;}
		.g2a-ev-carnav{display:flex;gap:8px;}
		.g2a-ev-arrow{width:40px;height:40px;display:inline-grid;place-items:center;background:var(--ev-card);
			border:1px solid var(--ev-line);color:var(--ev-white);font-size:20px;cursor:pointer;text-decoration:none;
			border-radius:3px;}
		.g2a-ev-arrow:hover{border-color:var(--ev-brass);color:var(--ev-brass2);}
		.g2a-ev-track{display:flex;gap:18px;overflow-x:auto;scroll-snap-type:x mandatory;
			padding-bottom:8px;-webkit-overflow-scrolling:touch;scrollbar-width:thin;}
		.g2a-ev-card{flex:0 0 320px;scroll-snap-align:start;background:var(--ev-card);
			border:1px solid var(--ev-line);border-radius:4px;overflow:hidden;display:flex;flex-direction:column;}
		.g2a-ev-card.is-featured{border-color:var(--ev-brass);}
		.g2a-ev-cardimg{position:relative;height:150px;background:var(--ev-card2) center/cover no-repeat;}
		.g2a-ev-cardchip{position:absolute;bottom:12px;right:12px;background:rgba(26,25,30,.9);
			color:var(--ev-brass2);font-family:var(--font-mono,monospace);font-size:11px;letter-spacing:.1em;
			text-transform:uppercase;padding:5px 10px;border-radius:2px;}
		.g2a-ev-cardbody{padding:18px;display:flex;flex-direction:column;gap:0;flex:1;}
		.g2a-ev-cardbody .g2a-ev-cta{margin-top:auto;align-self:flex-start;}
		@media(max-width:480px){.g2a-ev-card{flex-basis:80vw;}}

		/* CALENDAR */
		.g2a-ev-calhd{display:flex;align-items:center;justify-content:center;gap:18px;margin-bottom:14px;}
		.g2a-ev-calmonth{font-family:var(--font-display,"Bebas Neue",sans-serif);color:var(--ev-white);
			font-size:26px;letter-spacing:.03em;min-width:200px;text-align:center;}
		.g2a-ev-calgrid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;}
		.g2a-ev-dow{font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.14em;
			text-transform:uppercase;color:var(--ev-silver);text-align:center;padding:6px 0;}
		.g2a-ev-cell{min-height:96px;background:var(--ev-card);border:1px solid var(--ev-line);
			border-radius:3px;padding:6px;}
		.g2a-ev-cell.is-empty{background:transparent;border-color:transparent;}
		.g2a-ev-cell.is-today{border-color:var(--ev-brass);}
		.g2a-ev-cell.has-events{background:var(--ev-card2);}
		.g2a-ev-celln{display:block;font-family:var(--font-mono,monospace);font-size:11px;
			color:var(--ev-silver);margin-bottom:4px;}
		.g2a-ev-cell.is-today .g2a-ev-celln{color:var(--ev-brass2);}
		.g2a-ev-chip{display:block;background:var(--ev-ember);color:#fff;text-decoration:none;
			font-size:11px;line-height:1.3;padding:4px 6px;border-radius:2px;margin-bottom:4px;
			overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
		.g2a-ev-chip.is-featured{background:var(--ev-brass);color:#1A191E;}
		.g2a-ev-chip b{font-weight:700;}
		@media(max-width:600px){
			.g2a-ev-cell{min-height:64px;}
			.g2a-ev-chip{font-size:0;padding:5px;}
			.g2a-ev-chip b{font-size:0;}
			.g2a-ev-chip::after{content:"●";font-size:11px;}
		}
		</style>
		<?php
	}
}
