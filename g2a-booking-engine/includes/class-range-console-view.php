<?php
/**
 * G2AB_Range_Console_View — shared markup + CSS + JS for the Range Status
 * console. Used by the admin page and the [g2ab_range_console] shortcode so a
 * desk laptop on the public site behaves identically to wp-admin.
 *
 * The live data comes from REST /range/status (polled). The boot script is
 * printed in the footer (admin_footer / wp_footer) so it can never be mangled
 * by the_content when mounted via the shortcode.
 *
 * @package G2AB
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Range_Console_View {

	private static $booted = false;

	/**
	 * Queue the console assets. Call once per request before markup().
	 */
	public static function enqueue() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		// QR generator for the self check-in poster (public domain, vendored).
		wp_enqueue_script( 'g2ab-qrcode', G2AB_URL . 'assets/vendor/qrcode/qrcode.js', array(), G2AB_VERSION, true );
		$hook = is_admin() ? 'admin_footer' : 'wp_footer';
		add_action( $hook, array( __CLASS__, 'print_assets' ), 20 );
		if ( did_action( $hook ) ) {
			self::print_assets();
		}
	}

	public static function markup( $context = 'admin' ) {
		$checkin_url = home_url( '/?g2ab_checkin=1' );
		?>
		<div class="g2ab-rc g2ab-rc--<?php echo esc_attr( $context ); ?>" data-checkin-url="<?php echo esc_url( $checkin_url ); ?>">
			<header class="g2ab-rc__top">
				<div class="g2ab-rc__brand">
					<span class="g2ab-rc__dot"></span>
					<h1 class="g2ab-rc__title"><?php esc_html_e( 'RANGE STATUS', 'g2a-booking' ); ?></h1>
					<span class="g2ab-rc__updated" data-rc-updated></span>
				</div>
				<div class="g2ab-rc__top-actions">
					<button type="button" class="g2ab-rc__btn" data-rc-refresh><?php esc_html_e( 'Refresh', 'g2a-booking' ); ?></button>
					<button type="button" class="g2ab-rc__btn g2ab-rc__btn--qr" data-rc-qr-toggle><?php esc_html_e( 'Self check-in QR', 'g2a-booking' ); ?></button>
				</div>
			</header>

			<div class="g2ab-rc__msg" data-rc-msg role="status" aria-live="polite"></div>

			<div class="g2ab-rc__kpis">
				<div class="g2ab-rc__kpi g2ab-rc__kpi--use"><span class="g2ab-rc__kpi-n" data-kpi-in_use>0</span><span class="g2ab-rc__kpi-l"><?php esc_html_e( 'Lanes in use', 'g2a-booking' ); ?></span></div>
				<div class="g2ab-rc__kpi g2ab-rc__kpi--open"><span class="g2ab-rc__kpi-n" data-kpi-open>0</span><span class="g2ab-rc__kpi-l"><?php esc_html_e( 'Open lanes', 'g2a-booking' ); ?></span></div>
				<div class="g2ab-rc__kpi g2ab-rc__kpi--in"><span class="g2ab-rc__kpi-n" data-kpi-checked_in>0</span><span class="g2ab-rc__kpi-l"><?php esc_html_e( 'Checked in today', 'g2a-booking' ); ?></span></div>
				<div class="g2ab-rc__kpi g2ab-rc__kpi--rev"><span class="g2ab-rc__kpi-n"><span class="g2ab-rc__cur">$</span><span data-kpi-revenue>0.00</span></span><span class="g2ab-rc__kpi-l"><?php esc_html_e( "Today's revenue", 'g2a-booking' ); ?></span></div>
			</div>

			<div class="g2ab-rc__qr" data-rc-qr hidden>
				<div class="g2ab-rc__qr-box" data-rc-qr-box></div>
				<div class="g2ab-rc__qr-info">
					<h3><?php esc_html_e( 'Customer self check-in', 'g2a-booking' ); ?></h3>
					<p><?php esc_html_e( 'Print this and put it at the door. Customers scan it to check in and confirm their waiver from their phone.', 'g2a-booking' ); ?></p>
					<a class="g2ab-rc__qr-link" data-rc-qr-link href="#" target="_blank" rel="noopener"></a>
				</div>
			</div>

			<div class="g2ab-rc__body">
				<section class="g2ab-rc__lanes-wrap">
					<h2 class="g2ab-rc__h2"><?php esc_html_e( 'Lanes', 'g2a-booking' ); ?></h2>
					<div class="g2ab-rc__lanes" data-rc-lanes></div>
				</section>
				<aside class="g2ab-rc__side">
					<section class="g2ab-rc__panel">
						<h2 class="g2ab-rc__h2"><?php esc_html_e( 'Reservations today', 'g2a-booking' ); ?></h2>
						<div class="g2ab-rc__res" data-rc-res></div>
					</section>
					<section class="g2ab-rc__panel">
						<h2 class="g2ab-rc__h2"><?php esc_html_e( 'Live payments', 'g2a-booking' ); ?></h2>
						<div class="g2ab-rc__feed" data-rc-feed></div>
					</section>
				</aside>
			</div>

			<!-- Walk-in modal -->
			<div class="g2ab-rc__modal" data-rc-modal hidden>
				<div class="g2ab-rc__modal-card">
					<button type="button" class="g2ab-rc__modal-x" data-rc-modal-close aria-label="Close">×</button>
					<h3 class="g2ab-rc__modal-title"><?php esc_html_e( 'Walk-in', 'g2a-booking' ); ?> — <span data-rc-modal-lane></span></h3>
					<div class="g2ab-rc__modal-field"><label><?php esc_html_e( 'Customer name', 'g2a-booking' ); ?> *</label><input type="text" data-rc-w-name /></div>
					<div class="g2ab-rc__modal-row">
						<div class="g2ab-rc__modal-field"><label><?php esc_html_e( 'Phone', 'g2a-booking' ); ?></label><input type="tel" data-rc-w-phone /></div>
						<div class="g2ab-rc__modal-field"><label><?php esc_html_e( 'Shooters', 'g2a-booking' ); ?></label><input type="number" min="1" value="1" data-rc-w-party /></div>
						<div class="g2ab-rc__modal-field"><label><?php esc_html_e( 'Minutes', 'g2a-booking' ); ?></label><input type="number" min="15" step="15" placeholder="<?php esc_attr_e( 'default', 'g2a-booking' ); ?>" data-rc-w-dur /></div>
					</div>
					<label class="g2ab-rc__modal-check"><input type="checkbox" data-rc-w-waiver checked /> <?php esc_html_e( 'Waiver signed', 'g2a-booking' ); ?></label>
					<div class="g2ab-rc__modal-err" data-rc-w-err hidden></div>
					<button type="button" class="g2ab-rc__btn g2ab-rc__btn--primary g2ab-rc__modal-go" data-rc-w-go><?php esc_html_e( 'Book & check in', 'g2a-booking' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	public static function print_assets() {
		$cfg = array(
			'rest'  => esc_url_raw( rest_url( G2AB_REST_NAMESPACE . '/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'  => array(
				'in_use'   => __( 'IN USE', 'g2a-booking' ),
				'reserved' => __( 'RESERVED', 'g2a-booking' ),
				'open'     => __( 'OPEN', 'g2a-booking' ),
				'book'     => __( 'Book walk-in', 'g2a-booking' ),
				'ends'     => __( 'ends', 'g2a-booking' ),
				'next'     => __( 'next', 'g2a-booking' ),
				'no_res'   => __( 'No reservations left for today.', 'g2a-booking' ),
				'no_feed'  => __( 'No payments yet today.', 'g2a-booking' ),
				'name_req' => __( 'Customer name is required.', 'g2a-booking' ),
				'booked'   => __( 'Walk-in checked in on', 'g2a-booking' ),
				'failed'   => __( 'Action failed. Please try again.', 'g2a-booking' ),
				'waiver_y' => __( 'waiver ok', 'g2a-booking' ),
				'waiver_n' => __( 'waiver?', 'g2a-booking' ),
			),
		);
		self::print_css();
		?>
		<script>
		(function(cfg){
			var root = document.querySelector('.g2ab-rc');
			if (!root || root.dataset.rcBooted) return;
			root.dataset.rcBooted = '1';
			var $ = function(s){ return root.querySelector(s); };
			var I = cfg.i18n, REST = cfg.rest, NONCE = cfg.nonce;
			var modalLane = 0;

			function headers(body){ var h = { 'Accept':'application/json', 'X-WP-Nonce': NONCE }; if (body) h['Content-Type']='application/json'; return h; }
			function api(method, path, body){
				return fetch(REST + path, { method: method, headers: headers(body), credentials:'same-origin', body: body?JSON.stringify(body):undefined })
					.then(function(r){ return r.json().then(function(j){ return {ok:r.ok, body:j}; }, function(){ return {ok:r.ok, body:null}; }); });
			}
			function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
			function msg(t, kind){ var m=$('[data-rc-msg]'); if(!m) return; m.textContent=t; m.className='g2ab-rc__msg is-'+(kind||'ok'); if(kind!=='err'){ setTimeout(function(){ m.className='g2ab-rc__msg'; },3500);} }

			function load(){
				api('GET','range/status').then(function(res){
					if (!res.ok || !res.body || !res.body.success){ return; }
					render(res.body.data);
				});
			}

			function render(d){
				$('[data-rc-updated]').textContent = '· ' + (d.updated||'');
				var k=d.kpis||{};
				['in_use','open','checked_in','revenue'].forEach(function(key){ var el=root.querySelector('[data-kpi-'+key+']'); if(el) el.textContent=k[key]; });
				// lanes
				var lw=$('[data-rc-lanes]'); lw.innerHTML='';
				(d.lanes||[]).forEach(function(L){
					var card=document.createElement('div'); card.className='g2ab-rc__lane is-'+L.state;
					var label = L.state==='in_use'?I.in_use:(L.state==='reserved'?I.reserved:I.open);
					var inner='<div class="g2ab-rc__lane-h"><span class="g2ab-rc__lane-n">'+esc(L.name)+'</span><span class="g2ab-rc__lane-b">'+label+'</span></div>';
					if (L.state!=='open'){
						inner+='<div class="g2ab-rc__lane-occ">'+esc(L.occupant||'')+(L.party>1?(' ·×'+L.party):'')+'</div>';
						inner+='<div class="g2ab-rc__lane-meta">'+I.ends+' '+esc(L.ends)+' · <span class="'+(L.waiver_ok?'ok':'warn')+'">'+(L.waiver_ok?I.waiver_y:I.waiver_n)+'</span></div>';
					} else {
						if (L.next_at){ inner+='<div class="g2ab-rc__lane-meta">'+I.next+': '+esc(L.next_at)+' '+esc(L.next_name)+'</div>'; }
						else { inner+='<div class="g2ab-rc__lane-meta g2ab-rc__lane-free">—</div>'; }
						inner+='<button type="button" class="g2ab-rc__lane-book" data-lane="'+L.id+'" data-name="'+esc(L.name)+'">'+I.book+'</button>';
					}
					card.innerHTML=inner;
					lw.appendChild(card);
				});
				lw.querySelectorAll('.g2ab-rc__lane-book').forEach(function(btn){ btn.addEventListener('click',function(){ openModal(btn.getAttribute('data-lane'), btn.getAttribute('data-name')); }); });
				// reservations
				var rw=$('[data-rc-res]');
				if (!d.reservations || !d.reservations.length){ rw.innerHTML='<p class="g2ab-rc__empty">'+I.no_res+'</p>'; }
				else { rw.innerHTML=d.reservations.map(function(r){ return '<div class="g2ab-rc__res-row"><span class="g2ab-rc__res-t">'+esc(r.time)+'</span><span class="g2ab-rc__res-n">'+esc(r.name)+'</span><span class="g2ab-rc__res-w">'+esc(r.where)+'</span></div>'; }).join(''); }
				// feed
				var fw=$('[data-rc-feed]');
				if (!d.feed || !d.feed.length){ fw.innerHTML='<p class="g2ab-rc__empty">'+I.no_feed+'</p>'; }
				else { fw.innerHTML=d.feed.map(function(f){ return '<div class="g2ab-rc__feed-row"><span class="g2ab-rc__feed-a">$'+esc(f.amount)+'</span><span class="g2ab-rc__feed-n">'+esc(f.name)+'</span><span class="g2ab-rc__feed-m">'+esc(f.method||'')+' · '+esc(f.time)+'</span></div>'; }).join(''); }
			}

			function openModal(id, name){ modalLane=parseInt(id,10); $('[data-rc-modal-lane]').textContent=name; $('[data-rc-w-err]').hidden=true; $('[data-rc-w-name]').value=''; $('[data-rc-w-phone]').value=''; $('[data-rc-w-party]').value='1'; $('[data-rc-w-dur]').value=''; $('[data-rc-w-waiver]').checked=true; $('[data-rc-modal]').hidden=false; $('[data-rc-w-name]').focus(); }
			function closeModal(){ $('[data-rc-modal]').hidden=true; }

			$('[data-rc-refresh]').addEventListener('click', load);
			$('[data-rc-modal-close]').addEventListener('click', closeModal);
			$('[data-rc-modal]').addEventListener('click', function(e){ if(e.target===$('[data-rc-modal]')) closeModal(); });
			$('[data-rc-w-go]').addEventListener('click', function(){
				var name=$('[data-rc-w-name]').value.trim();
				if(!name){ var er=$('[data-rc-w-err]'); er.textContent=I.name_req; er.hidden=false; return; }
				var go=$('[data-rc-w-go]'); go.disabled=true;
				api('POST','range/walkin',{ resource_id:modalLane, customer_name:name, customer_phone:$('[data-rc-w-phone]').value.trim(), party_size:parseInt($('[data-rc-w-party]').value,10)||1, duration:parseInt($('[data-rc-w-dur]').value,10)||0, waiver_ok:$('[data-rc-w-waiver]').checked })
				.then(function(res){ go.disabled=false; if(res.ok && res.body && res.body.success){ closeModal(); msg(I.booked+' '+res.body.data.lane,'ok'); load(); } else { var er=$('[data-rc-w-err]'); er.textContent=(res.body && res.body.message)||I.failed; er.hidden=false; } });
			});

			// QR toggle
			$('[data-rc-qr-toggle]').addEventListener('click', function(){
				var box=$('[data-rc-qr]'); box.hidden=!box.hidden;
				if(!box.hidden && !box.dataset.drawn){ box.dataset.drawn='1'; drawQR(); }
			});
			function drawQR(){
				var url=root.getAttribute('data-checkin-url');
				var link=$('[data-rc-qr-link]'); link.textContent=url; link.href=url;
				var holder=$('[data-rc-qr-box]');
				try {
					if (typeof window.qrcode === 'function'){
						var qr = window.qrcode(0, 'M'); qr.addData(url); qr.make();
						holder.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 1, scalable: true });
						return;
					}
				} catch(e){}
				holder.innerHTML='<a href="'+esc(url)+'" target="_blank" class="g2ab-rc__qr-fallback">'+esc(url)+'</a>';
			}

			load();
			setInterval(load, 20000);
		})(<?php echo wp_json_encode( $cfg ); ?>);
		</script>
		<?php
	}

	private static function print_css() {
		?>
		<style id="g2ab-rc-styles">
		.g2ab-rc{--bg:#0B0D11;--surface:#151A21;--surface2:#1C232C;--border:#2A323D;--text:#EDEFF2;--muted:#A7A6AE;--orange:#E8802F;--green:#36C26B;--red:#E5484D;--amber:#F2B33D;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--text);background:var(--bg);border-radius:14px;padding:20px;margin-top:14px;}
		.g2ab-rc *{box-sizing:border-box;}
		.g2ab-rc--frontend{max-width:1240px;margin:24px auto;}
		.g2ab-rc__top{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px;}
		.g2ab-rc__brand{display:flex;align-items:center;gap:10px;}
		.g2ab-rc__dot{width:10px;height:10px;border-radius:50%;background:var(--green);box-shadow:0 0 0 4px color-mix(in srgb,var(--green) 25%,transparent);animation:g2ab-rc-pulse 2s ease-in-out infinite;}
		@keyframes g2ab-rc-pulse{0%,100%{opacity:1;}50%{opacity:.45;}}
		.g2ab-rc h1.g2ab-rc__title{margin:0;padding:0;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:26px;letter-spacing:.06em;color:#fff!important;text-transform:uppercase;}
		.g2ab-rc__updated{font-size:12px;color:var(--muted);}
		.g2ab-rc__btn{background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:9px 15px;font-size:13px;font-weight:700;cursor:pointer;transition:all .12s ease;}
		.g2ab-rc__btn:hover{border-color:var(--orange);color:#fff;}
		.g2ab-rc__btn--qr{border-color:var(--orange);color:var(--orange);}
		.g2ab-rc__btn--primary{background:var(--orange);border-color:var(--orange);color:#fff;}
		.g2ab-rc__msg{display:none;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:14px;}
		.g2ab-rc__msg.is-ok{display:block;background:rgba(54,194,107,.16);color:#7BE0A0;border:1px solid rgba(54,194,107,.4);}
		.g2ab-rc__msg.is-err{display:block;background:rgba(229,72,77,.16);color:#F2A0A2;border:1px solid rgba(229,72,77,.4);}
		.g2ab-rc__kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;}
		@media (max-width:720px){.g2ab-rc__kpis{grid-template-columns:repeat(2,1fr);}}
		.g2ab-rc__kpi{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px 18px;position:relative;overflow:hidden;}
		.g2ab-rc__kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--orange);}
		.g2ab-rc__kpi--use::before{background:var(--red);}.g2ab-rc__kpi--open::before{background:var(--green);}.g2ab-rc__kpi--in::before{background:var(--amber);}.g2ab-rc__kpi--rev::before{background:var(--orange);}
		.g2ab-rc__kpi-n{display:block;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:34px;font-weight:700;color:#fff;line-height:1;}
		.g2ab-rc__cur{font-size:20px;color:var(--muted);}
		.g2ab-rc__kpi-l{display:block;margin-top:6px;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600;}
		.g2ab-rc__qr{display:flex;gap:20px;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:16px;}
		.g2ab-rc__qr-box{background:#fff;padding:10px;border-radius:8px;width:170px;height:170px;display:flex;align-items:center;justify-content:center;}
		.g2ab-rc__qr-box svg{width:100%;height:100%;}
		.g2ab-rc__qr-info h3{margin:0 0 6px;color:#fff!important;font-size:16px;}
		.g2ab-rc__qr-info p{margin:0 0 8px;color:var(--muted);font-size:13px;max-width:46ch;}
		.g2ab-rc__qr-link{color:var(--orange);font-size:12px;word-break:break-all;}
		.g2ab-rc__body{display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start;}
		@media (max-width:980px){.g2ab-rc__body{grid-template-columns:1fr;}}
		.g2ab-rc h2.g2ab-rc__h2{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:15px;letter-spacing:.06em;text-transform:uppercase;color:#C8D0DB!important;margin:0 0 10px;padding:0;}
		.g2ab-rc__lanes{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}
		.g2ab-rc__lane{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;min-height:118px;display:flex;flex-direction:column;gap:8px;border-top:3px solid var(--border);transition:transform .12s ease;}
		.g2ab-rc__lane.is-in_use{border-top-color:var(--red);}
		.g2ab-rc__lane.is-reserved{border-top-color:var(--amber);}
		.g2ab-rc__lane.is-open{border-top-color:var(--green);}
		.g2ab-rc__lane-h{display:flex;align-items:center;justify-content:space-between;}
		.g2ab-rc__lane-n{font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:20px;font-weight:700;color:#fff;letter-spacing:.04em;}
		.g2ab-rc__lane-b{font-size:9px;font-weight:700;letter-spacing:.1em;padding:3px 8px;border-radius:999px;}
		.g2ab-rc__lane.is-in_use .g2ab-rc__lane-b{background:rgba(229,72,77,.18);color:#F2A0A2;}
		.g2ab-rc__lane.is-reserved .g2ab-rc__lane-b{background:rgba(242,179,61,.18);color:var(--amber);}
		.g2ab-rc__lane.is-open .g2ab-rc__lane-b{background:rgba(54,194,107,.18);color:#7BE0A0;}
		.g2ab-rc__lane-occ{font-size:14px;font-weight:600;color:var(--text);}
		.g2ab-rc__lane-meta{font-size:12px;color:var(--muted);}
		.g2ab-rc__lane-meta .ok{color:var(--green);}.g2ab-rc__lane-meta .warn{color:var(--amber);}
		.g2ab-rc__lane-free{color:#3b4654;}
		.g2ab-rc__lane-book{margin-top:auto;background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:7px;padding:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .12s ease;}
		.g2ab-rc__lane-book:hover{background:var(--orange);border-color:var(--orange);color:#fff;}
		.g2ab-rc__side{display:flex;flex-direction:column;gap:16px;}
		.g2ab-rc__panel{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;}
		.g2ab-rc__res-row{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;}
		.g2ab-rc__res-row:last-child{border-bottom:none;}
		.g2ab-rc__res-t{color:var(--orange);font-weight:700;}
		.g2ab-rc__res-n{color:var(--text);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
		.g2ab-rc__res-w{color:var(--muted);font-size:11px;}
		.g2ab-rc__feed-row{display:grid;grid-template-columns:auto 1fr;gap:4px 10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;}
		.g2ab-rc__feed-row:last-child{border-bottom:none;}
		.g2ab-rc__feed-a{grid-row:span 2;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:18px;font-weight:700;color:var(--green);align-self:center;}
		.g2ab-rc__feed-n{color:var(--text);font-weight:600;}
		.g2ab-rc__feed-m{color:var(--muted);font-size:11px;}
		.g2ab-rc__empty{color:var(--muted);font-size:13px;margin:4px 0;}
		.g2ab-rc__modal{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:99999;padding:20px;}
		.g2ab-rc__modal[hidden],.g2ab-rc__qr[hidden]{display:none!important;}
		.g2ab-rc__modal-card{background:var(--surface);border:1px solid var(--border);border-top:4px solid var(--orange);border-radius:14px;padding:24px;width:100%;max-width:440px;position:relative;}
		.g2ab-rc__modal-x{position:absolute;top:12px;right:14px;background:none;border:none;color:var(--muted);font-size:26px;cursor:pointer;line-height:1;}
		.g2ab-rc__modal-title{margin:0 0 16px;color:#fff!important;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;font-size:20px;letter-spacing:.03em;}
		.g2ab-rc__modal-row{display:grid;grid-template-columns:1fr .7fr .7fr;gap:10px;}
		.g2ab-rc__modal-field{margin-bottom:12px;}
		.g2ab-rc__modal-field label{display:block;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:5px;}
		.g2ab-rc__modal-field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:10px 11px;font-size:15px;}
		.g2ab-rc__modal-field input:focus{outline:none;border-color:var(--orange);}
		.g2ab-rc__modal-check{display:flex;align-items:center;gap:8px;font-size:14px;color:var(--text);margin-bottom:14px;cursor:pointer;}
		.g2ab-rc__modal-err{background:rgba(229,72,77,.16);border:1px solid rgba(229,72,77,.4);color:#F2A0A2;border-radius:7px;padding:9px 11px;font-size:13px;margin-bottom:12px;}
		.g2ab-rc__modal-go{width:100%;padding:13px;font-size:15px;}
		</style>
		<?php
	}
}
