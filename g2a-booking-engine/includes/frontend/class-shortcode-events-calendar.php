<?php
/**
 * [g2a_events_calendar] — public month calendar of events + upcoming list.
 *
 * An Amelia-style events calendar: a month grid with an event pill on each
 * scheduled day, an "upcoming events" sidebar, search, and month/year nav —
 * all client-side from an embedded dataset (no per-click server round trips).
 * Clicking an event opens its /event/{slug}/ landing page to book.
 *
 * Guns2Ammo dark/orange by default (theme="light" available). CSS + JS are
 * emitted in wp_footer so the_content can't mangle them when nested.
 *
 * @package G2AB
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Shortcode_Events_Calendar {

	private static $instance = null;
	private static $assets_queued = false;
	private static $boot_queue = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2a_events_calendar', array( $this, 'render' ) );
	}

	public function render( $atts = array() ) {
		if ( ! class_exists( 'G2AB_Events' ) ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'theme'         => 'dark',     // 'dark' | 'light'
			'category'      => '',
			'months_ahead'  => 8,
			'heading'       => '',
			'show_sidebar'  => 'yes',
		), $atts, 'g2a_events_calendar' );

		$events = G2AB_Events::get_events( array(
			'status'   => 'publish',
			'category' => $atts['category'] ? sanitize_key( $atts['category'] ) : '',
			'limit'    => 200,
		) );

		$data = array();
		foreach ( $events as $ev ) {
			$occs = G2AB_Events::get_occurrences( $ev->id, array( 'upcoming_only' => true, 'limit' => 200 ) );
			foreach ( $occs as $o ) {
				$free = ( (int) $ev->is_free === 1 || (float) $o->price_effective <= 0 );
				$data[] = array(
					'date'     => substr( $o->start_at, 0, 10 ),
					'time'     => G2AB_Events::format_local( $o->start_at, 'g:i A' ),
					'ts'       => G2AB_Events::timestamp( $o->start_at ),
					'title'    => $ev->title,
					'url'      => home_url( '/event/' . $ev->slug . '/' ),
					'color'    => $ev->color ? $ev->color : '#E8802F',
					'free'     => $free,
					'price'    => $free ? 0 : round( (float) $o->price_effective, 2 ),
					'cat'      => G2AB_Events::category_label( $ev->category ),
					'loc'      => $ev->location,
					'left'     => (int) $o->seats_left,
					'soldout'  => (int) $o->seats_left <= 0,
				);
			}
		}
		usort( $data, static function ( $a, $b ) { return $a['ts'] <=> $b['ts']; } );

		$theme = ( 'light' === $atts['theme'] ) ? 'light' : 'dark';
		$id    = 'g2ab-ecal-' . substr( md5( uniqid( 'ecal', true ) ), 0, 10 );
		$config = array(
			'id'           => $id,
			'events'       => $data,
			'show_sidebar' => ( 'no' !== $atts['show_sidebar'] ),
			'i18n'         => array(
				'today'    => __( 'Today', 'g2a-booking' ),
				'upcoming' => __( 'Upcoming events', 'g2a-booking' ),
				'none'     => __( 'No events this month.', 'g2a-booking' ),
				'none_up'  => __( 'No upcoming events.', 'g2a-booking' ),
				'search'   => __( 'Search events…', 'g2a-booking' ),
				'free'     => __( 'Free', 'g2a-booking' ),
				'left'     => __( 'left', 'g2a-booking' ),
				'soldout'  => __( 'Sold out', 'g2a-booking' ),
				'more'     => __( 'more', 'g2a-booking' ),
				'dows'     => array(
					__( 'Mon', 'g2a-booking' ), __( 'Tue', 'g2a-booking' ), __( 'Wed', 'g2a-booking' ),
					__( 'Thu', 'g2a-booking' ), __( 'Fri', 'g2a-booking' ), __( 'Sat', 'g2a-booking' ), __( 'Sun', 'g2a-booking' ),
				),
				'months'   => array(
					__( 'January', 'g2a-booking' ), __( 'February', 'g2a-booking' ), __( 'March', 'g2a-booking' ), __( 'April', 'g2a-booking' ),
					__( 'May', 'g2a-booking' ), __( 'June', 'g2a-booking' ), __( 'July', 'g2a-booking' ), __( 'August', 'g2a-booking' ),
					__( 'September', 'g2a-booking' ), __( 'October', 'g2a-booking' ), __( 'November', 'g2a-booking' ), __( 'December', 'g2a-booking' ),
				),
			),
		);

		$this->enqueue( $config );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="g2ab-ecal g2ab-ecal--<?php echo esc_attr( $theme ); ?>">
			<?php if ( $atts['heading'] ) : ?>
				<h2 class="g2ab-ecal__heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
			<?php endif; ?>
			<div class="g2ab-ecal__layout">
				<div class="g2ab-ecal__main">
					<div class="g2ab-ecal__toolbar">
						<div class="g2ab-ecal__search-wrap">
							<input type="search" class="g2ab-ecal__search" data-ecal-search placeholder="<?php echo esc_attr( $config['i18n']['search'] ); ?>" />
						</div>
						<div class="g2ab-ecal__nav">
							<button type="button" class="g2ab-ecal__btn" data-ecal-today><?php echo esc_html( $config['i18n']['today'] ); ?></button>
							<button type="button" class="g2ab-ecal__icon" data-ecal-prev aria-label="prev">‹</button>
							<button type="button" class="g2ab-ecal__icon" data-ecal-next aria-label="next">›</button>
							<span class="g2ab-ecal__title" data-ecal-title></span>
						</div>
					</div>
					<div class="g2ab-ecal__dows">
						<?php foreach ( $config['i18n']['dows'] as $d ) : ?><span><?php echo esc_html( strtoupper( $d ) ); ?></span><?php endforeach; ?>
					</div>
					<div class="g2ab-ecal__grid" data-ecal-grid></div>
				</div>
				<?php if ( $config['show_sidebar'] ) : ?>
				<aside class="g2ab-ecal__side">
					<h3 class="g2ab-ecal__side-title"><?php echo esc_html( $config['i18n']['upcoming'] ); ?></h3>
					<div class="g2ab-ecal__upcoming" data-ecal-upcoming></div>
				</aside>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function enqueue( $config ) {
		self::$boot_queue[] = $config;
		if ( ! self::$assets_queued ) {
			self::$assets_queued = true;
			add_action( 'wp_footer', array( $this, 'print_assets' ), 20 );
		}
		if ( did_action( 'wp_footer' ) ) {
			$this->print_assets();
		}
	}

	public function print_assets() {
		if ( empty( self::$boot_queue ) ) {
			return;
		}
		$queue            = self::$boot_queue;
		self::$boot_queue = array();
		static $css_done = false;
		if ( ! $css_done ) {
			$css_done = true;
			$this->print_css();
		}
		?>
		<script>
		(<?php echo wp_json_encode( $queue ); ?>).forEach(function(cfg){
			var root = document.getElementById(cfg.id);
			if (!root || root.dataset.ecalBooted) return;
			root.dataset.ecalBooted = '1';
			var I = cfg.i18n, EV = cfg.events || [];
			var grid = root.querySelector('[data-ecal-grid]');
			var title = root.querySelector('[data-ecal-title]');
			var upc = root.querySelector('[data-ecal-upcoming]');
			var search = root.querySelector('[data-ecal-search]');
			var now = new Date();
			var viewY = now.getFullYear(), viewM = now.getMonth();
			var query = '';

			function ymd(d){ return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
			function matches(e){ return !query || e.title.toLowerCase().indexOf(query) !== -1 || (e.cat||'').toLowerCase().indexOf(query) !== -1; }
			function priceLabel(e){ return e.free ? I.free : ('$'+Number(e.price).toFixed(2)); }

			function renderGrid(){
				title.textContent = I.months[viewM] + ' ' + viewY;
				var first = new Date(viewY, viewM, 1);
				var startDay = (first.getDay()+6)%7; // Mon=0
				var days = new Date(viewY, viewM+1, 0).getDate();
				var todayStr = ymd(new Date());
				var byDate = {};
				EV.forEach(function(e){ if(matches(e)){ (byDate[e.date]=byDate[e.date]||[]).push(e); } });
				var html = '';
				for (var i=0;i<startDay;i++){ html += '<div class="g2ab-ecal__cell is-empty"></div>'; }
				for (var d=1; d<=days; d++){
					var ds = viewY+'-'+('0'+(viewM+1)).slice(-2)+'-'+('0'+d).slice(-2);
					var list = byDate[ds] || [];
					var cls = 'g2ab-ecal__cell' + (ds===todayStr?' is-today':'') + (list.length?' has-ev':'');
					html += '<div class="'+cls+'"><span class="g2ab-ecal__num">'+d+'</span>';
					list.slice(0,3).forEach(function(e){
						html += '<a class="g2ab-ecal__pill'+(e.soldout?' is-out':'')+'" href="'+e.url+'" style="--p:'+e.color+'" title="'+escapeAttr(e.title)+'">'
							+ '<span class="g2ab-ecal__pill-t">'+e.time+'</span>'
							+ '<span class="g2ab-ecal__pill-n">'+escapeHtml(e.title)+'</span>'
							+ '<span class="g2ab-ecal__pill-s">'+(e.soldout?I.soldout:(e.left+' '+I.left))+'</span></a>';
					});
					if (list.length>3){ html += '<span class="g2ab-ecal__moreN">+'+(list.length-3)+' '+I.more+'</span>'; }
					html += '</div>';
				}
				grid.innerHTML = html;
				var anyThis = Object.keys(byDate).some(function(k){ return k.indexOf(viewY+'-'+('0'+(viewM+1)).slice(-2))===0; });
				if (!anyThis){ grid.innerHTML += '<div class="g2ab-ecal__empty">'+I.none+'</div>'; }
			}

			function renderUpcoming(){
				if (!upc) return;
				var nowTs = Date.now()/1000;
				var list = EV.filter(function(e){ return e.ts >= nowTs - 86400 && matches(e); }).slice(0,8);
				if (!list.length){ upc.innerHTML = '<p class="g2ab-ecal__none">'+I.none_up+'</p>'; return; }
				upc.innerHTML = list.map(function(e){
					var dt = new Date(e.ts*1000);
					var d = ('0'+dt.getDate()).slice(-2), mo = I.months[dt.getMonth()].slice(0,3).toUpperCase();
					return '<a class="g2ab-ecal__up" href="'+e.url+'" style="--p:'+e.color+'">'
						+ '<span class="g2ab-ecal__up-date"><b>'+d+'</b><small>'+mo+'</small></span>'
						+ '<span class="g2ab-ecal__up-body"><span class="g2ab-ecal__up-time">'+e.time+(e.loc?(' · '+escapeHtml(e.loc)):'')+'</span>'
						+ '<span class="g2ab-ecal__up-name">'+escapeHtml(e.title)+'</span>'
						+ '<span class="g2ab-ecal__up-meta"><b class="'+(e.soldout?'is-out':'')+'">'+(e.soldout?I.soldout:(e.left+' '+I.left))+'</b> · '+priceLabel(e)+'</span></span></a>';
				}).join('');
			}
			function escapeHtml(s){ return String(s).replace(/[&<>]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}); }
			function escapeAttr(s){ return String(s).replace(/"/g,'&quot;'); }

			function rerender(){ renderGrid(); renderUpcoming(); }
			root.querySelector('[data-ecal-prev]').addEventListener('click', function(){ viewM--; if(viewM<0){viewM=11;viewY--;} renderGrid(); });
			root.querySelector('[data-ecal-next]').addEventListener('click', function(){ viewM++; if(viewM>11){viewM=0;viewY++;} renderGrid(); });
			root.querySelector('[data-ecal-today]').addEventListener('click', function(){ var t=new Date(); viewY=t.getFullYear(); viewM=t.getMonth(); renderGrid(); });
			if (search){ search.addEventListener('input', function(){ query = search.value.trim().toLowerCase(); rerender(); }); }
			rerender();
		});
		</script>
		<?php
	}

	private function print_css() {
		?>
		<style id="g2ab-ecal-styles">
		.g2ab-ecal{--bg:#1A191E;--surface:#26252C;--surface2:#26252C;--border:#2A323D;--text:#F7F7F9;--muted:#A7A6AE;--orange:#E8802F;--green:#4CAF50;--danger:#FF6A55;font-family:'Inter',system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--text);margin:24px 0;}
		.g2ab-ecal--light{--bg:#FFFFFF;--surface:#FFFFFF;--surface2:#F4F6FA;--border:#E2E6EE;--text:#1F2733;--muted:#6B7686;--danger:#C62828;}
		.g2ab-ecal *{box-sizing:border-box;}
		.g2ab-ecal__heading{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:clamp(22px,3vw,30px);letter-spacing:.04em;text-transform:uppercase;text-align:center;margin:0 0 18px;}
		.g2ab-ecal__layout{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;}
		@media (max-width:900px){.g2ab-ecal__layout{grid-template-columns:1fr;}}
		.g2ab-ecal__main{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px;}
		.g2ab-ecal__toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:14px;}
		.g2ab-ecal__search{background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 12px;font-size:14px;min-width:200px;}
		.g2ab-ecal__search:focus{outline:none;border-color:var(--orange);}
		.g2ab-ecal__nav{display:flex;align-items:center;gap:8px;}
		.g2ab-ecal__btn{background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer;}
		.g2ab-ecal__btn:hover{border-color:var(--orange);}
		.g2ab-ecal__icon{background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;width:34px;height:34px;font-size:18px;line-height:1;cursor:pointer;}
		.g2ab-ecal__icon:hover{border-color:var(--orange);color:var(--orange);}
		.g2ab-ecal__title{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:19px;font-weight:700;letter-spacing:.03em;margin-left:6px;color:var(--text);}
		.g2ab-ecal__dows{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:6px;}
		.g2ab-ecal__dows span{text-align:center;font-size:10px;letter-spacing:.08em;color:var(--muted);font-weight:700;padding:4px 0;}
		.g2ab-ecal__grid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;position:relative;}
		.g2ab-ecal__cell{min-height:96px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:6px;display:flex;flex-direction:column;gap:3px;transition:border-color .15s ease;}
		.g2ab-ecal__cell.is-empty{background:transparent;border:none;}
		.g2ab-ecal__cell.is-today{border-color:var(--orange);box-shadow:0 0 0 1px var(--orange) inset;}
		.g2ab-ecal__cell.has-ev:hover{border-color:var(--muted);}
		.g2ab-ecal__num{font-size:12px;color:var(--muted);font-weight:700;align-self:flex-end;}
		.g2ab-ecal__cell.is-today .g2ab-ecal__num{color:var(--orange);}
		.g2ab-ecal__pill{display:block;text-decoration:none;background:color-mix(in srgb, var(--p,#E8802F) 16%, var(--surface2));border-left:3px solid var(--p,#E8802F);border-radius:5px;padding:4px 6px;animation:g2ab-ecal-in .3s ease both;}
		.g2ab-ecal__pill:hover{background:color-mix(in srgb, var(--p,#E8802F) 30%, var(--surface2));}
		.g2ab-ecal__pill.is-out{opacity:.5;}
		.g2ab-ecal__pill-t{display:block;font-size:10px;color:var(--muted);font-weight:700;}
		.g2ab-ecal__pill-n{display:block;font-size:12px;color:var(--text);font-weight:600;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
		.g2ab-ecal__pill-s{display:block;font-size:9px;color:var(--green);font-weight:700;}
		.g2ab-ecal__pill.is-out .g2ab-ecal__pill-s{color:var(--danger);}
		.g2ab-ecal__moreN{font-size:10px;color:var(--orange);font-weight:700;padding-left:4px;}
		.g2ab-ecal__empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:14px;pointer-events:none;}
		.g2ab-ecal__side{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px;}
		.g2ab-ecal__side-title{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:17px;letter-spacing:.04em;text-transform:uppercase;color:var(--text);margin:0 0 14px;}
		.g2ab-ecal__up{display:flex;gap:12px;text-decoration:none;padding:11px;border-radius:9px;border:1px solid var(--border);border-left:3px solid var(--p,#E8802F);margin-bottom:10px;background:var(--bg);transition:transform .12s ease,border-color .12s ease;animation:g2ab-ecal-in .3s ease both;}
		.g2ab-ecal__up:hover{transform:translateX(2px);border-left-color:var(--orange);}
		.g2ab-ecal__up-date{text-align:center;min-width:38px;}
		.g2ab-ecal__up-date b{display:block;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:22px;color:var(--p,#E8802F);line-height:1;}
		.g2ab-ecal__up-date small{display:block;font-size:9px;color:var(--muted);letter-spacing:.06em;font-weight:700;}
		.g2ab-ecal__up-body{display:flex;flex-direction:column;gap:2px;min-width:0;}
		.g2ab-ecal__up-time{font-size:11px;color:var(--muted);}
		.g2ab-ecal__up-name{font-size:14px;color:var(--text);font-weight:700;line-height:1.2;}
		.g2ab-ecal__up-meta{font-size:11px;color:var(--muted);}
		.g2ab-ecal__up-meta b{color:var(--green);}
		.g2ab-ecal__up-meta b.is-out{color:var(--danger);}
		.g2ab-ecal__none{color:var(--muted);font-size:13px;}
		@keyframes g2ab-ecal-in{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}
		</style>
		<?php
	}
}
