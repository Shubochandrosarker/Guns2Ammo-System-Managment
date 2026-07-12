<?php
/**
 * Customer self check-in (QR target).
 *
 * A mobile-first page where a customer who scanned the range's QR poster
 * finds their booking (confirmation code, or name + phone), confirms their
 * waiver status, and checks themselves in. Talks to REST /range/lookup and
 * /range/self-checkin.
 *
 * Reachable two ways:
 *   - /?g2ab_checkin=1            (the QR poster URL — standalone page)
 *   - [g2ab_self_checkin]         (shortcode, to embed on any page)
 *
 * @package G2AB
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Frontend_Self_Checkin {

	private static $instance = null;
	private static $assets_done = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'g2ab_self_checkin', array( $this, 'shortcode' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_standalone' ), 6 );
	}

	public function maybe_render_standalone() {
		if ( empty( $_GET['g2ab_checkin'] ) ) {
			return;
		}
		nocache_headers();
		get_header();
		echo '<div class="g2ab-sci-wrap" style="max-width:560px;margin:30px auto;padding:0 16px;">';
		echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		get_footer();
		exit;
	}

	public function shortcode( $atts ) {
		return $this->markup();
	}

	private function markup() {
		$this->enqueue();
		$biz = g2ab_business_name();
		ob_start();
		?>
		<div class="g2ab-sci" id="g2ab-sci">
			<div class="g2ab-sci__head">
				<span class="g2ab-sci__eyebrow"><?php echo esc_html( strtoupper( $biz ) ); ?></span>
				<h2 class="g2ab-sci__title"><?php esc_html_e( 'Range check-in', 'g2a-booking' ); ?></h2>
				<p class="g2ab-sci__sub"><?php esc_html_e( "Find your booking to check in. We'll confirm your waiver too.", 'g2a-booking' ); ?></p>
			</div>

			<!-- STEP: find -->
			<section class="g2ab-sci__step is-active" data-sci="find">
				<div class="g2ab-sci__field">
					<label><?php esc_html_e( 'Confirmation code', 'g2a-booking' ); ?></label>
					<input type="text" data-sci-code placeholder="<?php esc_attr_e( 'e.g. 3F9A1C7B', 'g2a-booking' ); ?>" autocomplete="off" />
				</div>
				<div class="g2ab-sci__or"><?php esc_html_e( 'or', 'g2a-booking' ); ?></div>
				<div class="g2ab-sci__field">
					<label><?php esc_html_e( 'Name on booking', 'g2a-booking' ); ?></label>
					<input type="text" data-sci-name autocomplete="off" />
				</div>
				<div class="g2ab-sci__field">
					<label><?php esc_html_e( 'Phone number', 'g2a-booking' ); ?></label>
					<input type="tel" data-sci-phone autocomplete="off" />
				</div>
				<div class="g2ab-sci__err" data-sci-err hidden></div>
				<button type="button" class="g2ab-sci__btn" data-sci-find><?php esc_html_e( 'Find my booking', 'g2a-booking' ); ?></button>
			</section>

			<!-- STEP: pick -->
			<section class="g2ab-sci__step" data-sci="pick">
				<p class="g2ab-sci__picklabel"><?php esc_html_e( 'Tap your booking:', 'g2a-booking' ); ?></p>
				<div class="g2ab-sci__list" data-sci-list></div>
				<button type="button" class="g2ab-sci__back" data-sci-back>‹ <?php esc_html_e( 'Search again', 'g2a-booking' ); ?></button>
			</section>

			<!-- STEP: done -->
			<section class="g2ab-sci__step" data-sci="done">
				<div class="g2ab-sci__done">
					<div class="g2ab-sci__done-check" data-sci-done-icon>✓</div>
					<h3 class="g2ab-sci__done-title" data-sci-done-title></h3>
					<p class="g2ab-sci__done-msg" data-sci-done-msg></p>
				</div>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	private function enqueue() {
		if ( self::$assets_done ) {
			return;
		}
		self::$assets_done = true;
		$hook = is_admin() ? 'admin_footer' : 'wp_footer';
		add_action( $hook, array( __CLASS__, 'print_assets' ) );
		if ( did_action( $hook ) ) {
			self::print_assets();
		}
	}

	public static function print_assets() {
		$cfg = array(
			'rest'  => esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'  => array(
				'need'    => __( 'Enter your confirmation code, or your name and phone.', 'g2a-booking' ),
				'none'    => __( 'We could not find a booking for today. Please see the front desk.', 'g2a-booking' ),
				'failed'  => __( 'Something went wrong. Please try again or see the front desk.', 'g2a-booking' ),
				'working' => __( 'Checking…', 'g2a-booking' ),
			),
		);
		?>
		<style id="g2ab-sci-styles">
		.g2ab-sci{--bg:#1A191E;--surface:#26252C;--border:#2A323D;--text:#EDEFF2;--muted:#9AA4B2;--orange:#E8802F;--green:#36C26B;background:var(--surface);border:1px solid var(--border);border-top:4px solid var(--orange);border-radius:16px;padding:24px;color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
		.g2ab-sci *{box-sizing:border-box;}
		.g2ab-sci__eyebrow{display:block;color:var(--orange);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:12px;letter-spacing:.2em;font-weight:700;}
		.g2ab-sci__title{margin:6px 0 4px;color:#fff;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:30px;letter-spacing:.02em;text-transform:uppercase;}
		.g2ab-sci__sub{margin:0 0 18px;color:var(--muted);font-size:14px;line-height:1.5;}
		.g2ab-sci__step{display:none;}
		.g2ab-sci__step.is-active{display:block;animation:g2ab-sci-in .25s ease both;}
		@keyframes g2ab-sci-in{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
		.g2ab-sci__field{margin-bottom:12px;}
		.g2ab-sci__field label{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:6px;}
		.g2ab-sci__field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:9px;color:var(--text);padding:13px 14px;font-size:16px;}
		.g2ab-sci__field input:focus{outline:none;border-color:var(--orange);}
		.g2ab-sci__or{text-align:center;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.1em;margin:6px 0;}
		.g2ab-sci__err{background:rgba(229,72,77,.16);border:1px solid rgba(229,72,77,.4);color:#F2A0A2;border-radius:9px;padding:11px 13px;font-size:14px;margin-bottom:12px;}
		.g2ab-sci__btn{width:100%;background:var(--orange);color:#fff;border:2px solid var(--orange);border-radius:10px;padding:15px;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:17px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;transition:all .15s ease;}
		.g2ab-sci__btn:hover{background:transparent;color:var(--orange);}
		.g2ab-sci__btn:disabled{opacity:.6;cursor:not-allowed;}
		.g2ab-sci__picklabel{color:var(--muted);font-size:13px;margin:0 0 10px;}
		.g2ab-sci__pick{display:flex;justify-content:space-between;align-items:center;width:100%;text-align:left;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:10px;color:var(--text);cursor:pointer;transition:border-color .12s ease;}
		.g2ab-sci__pick:hover{border-color:var(--orange);}
		.g2ab-sci__pick b{display:block;font-size:16px;}
		.g2ab-sci__pick small{color:var(--muted);font-size:12px;}
		.g2ab-sci__pick .g2ab-sci__pick-go{color:var(--orange);font-size:20px;}
		.g2ab-sci__back{background:none;border:none;color:var(--muted);font-size:14px;cursor:pointer;padding:6px 0;margin-top:4px;}
		.g2ab-sci__back:hover{color:var(--orange);}
		.g2ab-sci__done{text-align:center;padding:14px 6px;}
		.g2ab-sci__done-check{width:78px;height:78px;line-height:78px;margin:0 auto 14px;border-radius:50%;background:rgba(54,194,107,.16);color:var(--green);font-size:42px;border:2px solid var(--green);}
		.g2ab-sci__done-check.is-warn{background:rgba(242,179,61,.16);color:#F2B33D;border-color:#F2B33D;}
		.g2ab-sci__done-title{margin:0 0 8px;color:#fff;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:26px;letter-spacing:.02em;}
		.g2ab-sci__done-msg{color:var(--muted);font-size:15px;line-height:1.55;max-width:40ch;margin:0 auto;}
		</style>
		<script>
		(function(cfg){
			var root = document.getElementById('g2ab-sci');
			if (!root || root.dataset.sciBooted) return;
			root.dataset.sciBooted = '1';
			var $ = function(s){ return root.querySelector(s); };
			var I = cfg.i18n;
			function headers(){ return { 'Content-Type':'application/json', 'Accept':'application/json', 'X-WP-Nonce': cfg.nonce }; }
			function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
			function step(n){ root.querySelectorAll('.g2ab-sci__step').forEach(function(s){ s.classList.toggle('is-active', s.getAttribute('data-sci')===n); }); }
			function err(t){ var e=$('[data-sci-err]'); e.textContent=t; e.hidden=false; }
			function payload(){ return { code: $('[data-sci-code]').value.trim(), name: $('[data-sci-name]').value.trim(), phone: $('[data-sci-phone]').value.trim() }; }

			$('[data-sci-find]').addEventListener('click', function(){
				$('[data-sci-err]').hidden=true;
				var btn=$('[data-sci-find]'); btn.disabled=true; var orig=btn.textContent; btn.textContent=I.working;
				fetch(cfg.rest + 'range/lookup', { method:'POST', headers: headers(), credentials:'same-origin', body: JSON.stringify(payload()) })
					.then(function(r){ return r.json(); })
					.then(function(j){
						btn.disabled=false; btn.textContent=orig;
						if (!j || !j.success){ err((j && j.message) || I.need); return; }
						var list = (j.data && j.data.bookings) || [];
						if (!list.length){ err(I.none); return; }
						renderList(list);
						step('pick');
					})
					.catch(function(){ btn.disabled=false; btn.textContent=orig; err(I.failed); });
			});

			function renderList(list){
				var box=$('[data-sci-list]');
				box.innerHTML = list.map(function(b){
					return '<button type="button" class="g2ab-sci__pick" data-id="'+b.id+'"><span><b>'+esc(b.name)+'</b><small>'+esc(b.time)+' · #'+esc(b.code)+'</small></span><span class="g2ab-sci__pick-go">→</span></button>';
				}).join('');
				box.querySelectorAll('.g2ab-sci__pick').forEach(function(btn){ btn.addEventListener('click', function(){ checkin(parseInt(btn.getAttribute('data-id'),10)); }); });
			}

			function checkin(id){
				var body = payload(); body.booking_id = id;
				fetch(cfg.rest + 'range/self-checkin', { method:'POST', headers: headers(), credentials:'same-origin', body: JSON.stringify(body) })
					.then(function(r){ return r.json(); })
					.then(function(j){
						if (!j || !j.success){ step('find'); err((j && j.message) || I.failed); return; }
						var d=j.data;
						$('[data-sci-done-title]').textContent = d.name ? ('Welcome, ' + d.name.split(' ')[0] + '!') : 'Checked in';
						$('[data-sci-done-msg]').textContent = d.message || '';
						var icon=$('[data-sci-done-icon]');
						if (d.waiver_ok){ icon.textContent='✓'; icon.classList.remove('is-warn'); } else { icon.textContent='!'; icon.classList.add('is-warn'); }
						step('done');
					})
					.catch(function(){ step('find'); err(I.failed); });
			}

			$('[data-sci-back]').addEventListener('click', function(){ step('find'); });
		})(<?php echo wp_json_encode( $cfg ); ?>);
		</script>
		<?php
	}
}
