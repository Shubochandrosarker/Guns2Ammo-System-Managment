<?php
/**
 * Footer  Guns 2 Ammo child theme.
 *
 * All NAP + review + founding info comes from g2a_biz()
 * (inc/business-info.php) so footer can't drift from header /
 * homepage / schema.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$biz       = g2a_biz();
$phone     = $biz['phone'];
$phone_tel = $biz['phone_tel'];
$email     = $biz['email'];
$addr1     = $biz['addr1'];
$addr2     = $biz['addr2'];
$rating    = number_format( (float) $biz['review_rating'], 1 );
// "449+" / "500+" was an inconsistent suffix — drop the "+" so
// the public number always matches the GBP / Customizer number
// exactly. Use the raw integer.
$reviews   = (int) $biz['review_count'];
$founded   = (int) $biz['founded_year'];
?>
</main><!-- /#g2a-main -->

<section class="g2a-news" style="background:#151419; padding: 56px 32px; border-top:1px solid var(--color-hairline);">
	<div class="newsletter-band" style="max-width: 1280px; margin: 0 auto;">
		<div>
			<h3>GET RANGE UPDATES</h3>
			<p>New inventory, training schedules, and exclusive member pricing  straight to your inbox.</p>
		</div>
		<?php
		// Real newsletter wiring — posts to WPistic Contact Form's
		// /wp-admin/admin-ajax.php endpoint and reports the result in
		// the inline status span. Falls back to a mailto: link if the
		// plugin isn't active (so the form never silently no-ops).
		$nl_endpoint = admin_url( 'admin-ajax.php' );
		$nl_action   = 'wpcf_newsletter_subscribe';
		$nl_nonce    = wp_create_nonce( 'wpcf_newsletter' );
		?>
		<form class="g2a-newsletter-form"
		      data-endpoint="<?php echo esc_url( $nl_endpoint ); ?>"
		      data-action="<?php echo esc_attr( $nl_action ); ?>"
		      data-nonce="<?php echo esc_attr( $nl_nonce ); ?>"
		      data-source="footer"
		      aria-label="Newsletter signup">
			<input type="email" name="email" placeholder="your@email.com" required aria-label="Email">
			<button type="submit" class="btn-arrow">JOIN</button>
			<span class="g2a-newsletter-status" role="status" aria-live="polite" style="display:block; margin-top:8px; font-size:13px; color: var(--color-fog);"></span>
		</form>
		<script>
		(function(){
		  var f = document.currentScript.previousElementSibling;
		  if (!f || f.tagName !== 'FORM') return;
		  f.addEventListener('submit', function (e) {
		    e.preventDefault();
		    var btn = f.querySelector('button');
		    var status = f.querySelector('.g2a-newsletter-status');
		    var email = f.querySelector('input[type=email]').value.trim();
		    if (!email) return;
		    var original = btn.innerHTML;
		    btn.disabled = true;
		    btn.textContent = '...';
		    status.textContent = '';
		    var fd = new FormData();
		    fd.append('action', f.dataset.action);
		    fd.append('_wpnonce', f.dataset.nonce);
		    fd.append('email', email);
		    fd.append('source', f.dataset.source || 'footer');
		    fetch(f.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body: fd })
		      .then(function (r) { return r.json().catch(function(){ return null; }); })
		      .then(function (j) {
		        if (j && j.success) {
		          btn.textContent = 'JOINED';
		          status.style.color = '#9DE05B';
		          status.textContent = (j.data && j.data.message) || 'Subscribed.';
		          f.querySelector('input[type=email]').value = '';
		        } else {
		          btn.disabled = false;
		          btn.innerHTML = original;
		          status.style.color = '#E8802F';
		          status.textContent = (j && j.data && j.data.message) || 'Something went wrong. Please try again.';
		        }
		      })
		      .catch(function () {
		        btn.disabled = false;
		        btn.innerHTML = original;
		        status.style.color = '#E8802F';
		        status.textContent = 'Network error. Please try again.';
		      });
		  });
		})();
		</script>
	</div>
</section>

<footer class="g2a-foot" role="contentinfo">
	<div class="grid" data-reveal-group>
		<div data-reveal>
			<div class="brand"><span class="mark"></span>GUNS&nbsp;2&nbsp;AMMO</div>
			<div style="color: var(--color-fog); font-size:14px; max-width:32ch; margin-bottom:22px; line-height: 1.65;"><?php echo esc_html( $biz['slogan'] ); ?> <?php /* translators: %d: founding year, e.g. 2014 */ printf( esc_html__( 'Since %d.', 'guns2ammo' ), $founded ); ?></div>
			<div style="display:inline-flex; align-items:center; gap:12px; padding: 10px 16px; border:1px solid var(--color-hairline-bright); margin-bottom: 18px;">
				<span style="color: var(--color-brass-bright); font-family: var(--font-mono); font-size: 14px; letter-spacing: 0.08em;">*****</span>
				<span style="font-family: var(--font-condensed); font-weight:600; font-size:18px; color: var(--color-white);"><?php echo esc_html( $rating ); ?></span>
				<span style="font-family: var(--font-mono); font-size:11px; letter-spacing:0.16em; color: var(--color-silver); text-transform: uppercase;"><?php echo esc_html( $reviews ); ?> Google</span>
			</div>
			<div style="display:flex; gap:10px;">
				<a href="<?php echo esc_url( get_theme_mod( 'g2a_social_fb', '#' ) ); ?>" aria-label="Facebook" style="width:36px; height:36px; border:1px solid var(--color-hairline-bright); border-radius:50%; display:grid; place-items:center; color: var(--color-fog);"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11.01 10.13 11.93v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.69.23 2.69.23v2.96h-1.51c-1.49 0-1.96.93-1.96 1.88v2.27h3.33l-.53 3.49h-2.8V24c5.74-.92 10.13-5.91 10.13-11.93z"/></svg></a>
				<a href="<?php echo esc_url( get_theme_mod( 'g2a_social_ig', '#' ) ); ?>" aria-label="Instagram" style="width:36px; height:36px; border:1px solid var(--color-hairline-bright); border-radius:50%; display:grid; place-items:center; color: var(--color-fog);"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63a5.88 5.88 0 0 0-2.13 1.38A5.88 5.88 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.73 1.46 1.38 2.13a5.88 5.88 0 0 0 2.13 1.38c.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.88 5.88 0 0 0 2.13-1.38 5.88 5.88 0 0 0 1.38-2.13c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.88 5.88 0 0 0-1.38-2.13A5.88 5.88 0 0 0 19.86.63C19.1.33 18.22.13 16.95.07 15.67.01 15.26 0 12 0Zm0 5.84A6.16 6.16 0 1 1 5.84 12 6.16 6.16 0 0 1 12 5.84ZM12 16a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm6.4-11.85a1.44 1.44 0 1 0 1.44 1.44 1.44 1.44 0 0 0-1.44-1.44Z"/></svg></a>
				<a href="<?php echo esc_url( get_theme_mod( 'g2a_social_x', '#' ) ); ?>" aria-label="X" style="width:36px; height:36px; border:1px solid var(--color-hairline-bright); border-radius:50%; display:grid; place-items:center; color: var(--color-fog);"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 2h3l-7.5 8.5L22 22h-6.8l-5.3-7-6.1 7H1l8-9.2L1.6 2h7l4.8 6.4L18 2z"/></svg></a>
				<a href="<?php echo esc_url( get_theme_mod( 'g2a_social_yt', '#' ) ); ?>" aria-label="YouTube" style="width:36px; height:36px; border:1px solid var(--color-hairline-bright); border-radius:50%; display:grid; place-items:center; color: var(--color-fog);"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8ZM9.6 15.6V8.4l6.2 3.6-6.2 3.6Z"/></svg></a>
			</div>
		</div>
		<div data-reveal>
			<h5>QUICK ACCESS</h5>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/book-a-lane/' ) ); ?>">Book a Lane</a></li>
				<li><a href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>">Membership</a></li>
				<li><a href="<?php echo esc_url( home_url( '/training/' ) ); ?>">Training Courses</a></li>
				<li><a href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>">Firearm Transfers</a></li>
				<li><a href="<?php echo esc_url( home_url( '/sell-your-gun/' ) ); ?>">Sell Your Gun</a></li>
				<li><a href="<?php echo esc_url( home_url( '/machine-gun/' ) ); ?>">Machine Gun Packages</a></li>
				<li><a href="<?php echo esc_url( home_url( '/get-support/' ) ); ?>">Get Support</a></li>
			</ul>
		</div>
		<div data-reveal>
			<h5>COLLECTIONS</h5>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/collections/' ) ); ?>">All Collections</a></li>
				<li><a href="<?php echo esc_url( home_url( '/collections/handguns/' ) ); ?>">Handguns</a></li>
				<li><a href="<?php echo esc_url( home_url( '/collections/rifles/' ) ); ?>">Rifles</a></li>
				<li><a href="<?php echo esc_url( home_url( '/collections/ammunition/' ) ); ?>">Ammunition</a></li>
				<li><a href="<?php echo esc_url( home_url( '/collections/magazines/' ) ); ?>">Magazines</a></li>
				<li><a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">All Products</a></li>
			</ul>
		</div>
		<div data-reveal>
			<h5>VISIT US</h5>
			<?php
			// Compact Google Maps embed (no API key) with an animated
			// brass pin overlaid. The address feeding the query is the
			// same g2a_biz() NAP used everywhere else.
			$g2a_map_q   = rawurlencode( 'Guns 2 Ammo, ' . $addr1 . ', ' . $addr2 );
			$g2a_map_src = 'https://www.google.com/maps?q=' . $g2a_map_q . '&output=embed';
			?>
			<div class="foot-map" style="margin-bottom:16px;">
				<iframe src="<?php echo esc_url( $g2a_map_src ); ?>"
				        title="<?php echo esc_attr( sprintf( __( 'Map to Guns 2 Ammo, %1$s, %2$s', 'guns2ammo' ), $addr1, $addr2 ) ); ?>"
				        loading="lazy"
				        referrerpolicy="no-referrer-when-downgrade"></iframe>
				<span class="foot-map__ring" aria-hidden="true"></span>
				<span class="foot-map__pin" aria-hidden="true">
					<svg viewBox="0 0 30 38" fill="currentColor" stroke="rgba(20,19,23,0.55)" stroke-width="1" aria-hidden="true"><path d="M15 1C7.8 1 2 6.8 2 14c0 9.6 13 23 13 23s13-13.4 13-23C28 6.8 22.2 1 15 1z"/><circle cx="15" cy="14" r="5" fill="#1A191E" stroke="none"/></svg>
				</span>
			</div>
			<ul>
				<li style="font-family: var(--font-condensed); font-weight:600; font-size:15px; color: var(--color-white); line-height:1.4;"><?php echo esc_html( $addr1 ); ?><br><?php echo esc_html( $addr2 ); ?></li>
				<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', '+1' . $phone ) ); ?>" style="color: var(--color-brass-bright); display:inline-flex; align-items:center; gap:8px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.92V20a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.86 19.86 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <?php echo esc_html( $phone ); ?></a></li>
				<li><a href="mailto:<?php echo esc_attr( $email ); ?>" style="color: var(--color-brass-bright); display:inline-flex; align-items:center; gap:8px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/></svg> <?php echo esc_html( $email ); ?></a></li>
				<li style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; padding-top:8px;">
					<a href="<?php echo esc_url( ! empty( $biz['directions_url'] ) ? $biz['directions_url'] : get_theme_mod( 'g2a_directions_url', 'https://www.google.com/maps/dir/?api=1&destination=Guns+2+Ammo%2C+6030+E+Main+St+%23103%2C+Mesa%2C+AZ+85205%2C+United+States&destination_place_id=ChIJaSRMhpGvK4cR4kE_E-jZvKE' ) ); ?>" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2 C8 2 5 5 5 9 c0 6 7 13 7 13 s7-7 7-13 c0-4-3-7-7-7 z"/><circle cx="12" cy="9" r="2.5"/></svg>Get Directions</a>
					<a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" style="display:inline-flex; align-items:center; gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>Contact</a>
				</li>
			</ul>
		</div>
	</div>
	<div class="strip">
		<span> <?php echo esc_html( date( 'Y' ) ); ?> Guns 2 Ammo  Mesa, Arizona  FFL Licensed</span>
		<span><a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy</a>  <a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">Terms</a>  <a href="<?php echo esc_url( home_url( '/refund-and-returns-policy/' ) ); ?>">Refunds</a>  <a href="<?php echo esc_url( home_url( '/range-safety/' ) ); ?>">Range Rules</a>  <a href="<?php echo esc_url( home_url( '/sitemap/' ) ); ?>">Sitemap</a></span>
	</div>
</footer>

<!-- Quick-view modal (mounted once, populated by chrome.js) -->
<div class="qv-backdrop" id="g2a-qv" role="dialog" aria-modal="true" aria-hidden="true">
	<div class="qv-modal">
		<div class="qv-image" id="g2a-qv-img" data-pl="PRODUCT IMAGE PLACEHOLDER"></div>
		<div class="qv-body">
			<button class="qv-close" id="g2a-qv-close" aria-label="Close"></button>
			<div class="qv-brand" id="g2a-qv-brand"></div>
			<h3 class="qv-title" id="g2a-qv-title"></h3>
			<div class="qv-price"><span id="g2a-qv-price"></span> <span class="was" id="g2a-qv-was"></span></div>
			<div class="star-rating" id="g2a-qv-stars" style="color: var(--color-brass-bright); font-family: var(--font-mono); font-size:13px; letter-spacing:0.1em; margin-top:8px;"></div>
			<p class="qv-desc" id="g2a-qv-desc"></p>
			<div class="qv-meta" id="g2a-qv-meta"></div>
			<div style="display:flex; gap:10px; flex-wrap:wrap;">
				<a class="btn btn-ember" id="g2a-qv-cart">Add To Cart</a>
				<a class="btn btn-brass btn-arrow" id="g2a-qv-detail">View Full Details</a>
			</div>
		</div>
	</div>
</div>

<?php wp_footer(); ?>
</body>
</html>
