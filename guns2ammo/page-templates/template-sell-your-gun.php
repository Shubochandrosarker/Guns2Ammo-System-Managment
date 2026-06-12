<?php
/**
 * Template Name: Sell Your Gun
 *
 * SEO/AEO-focused landing page for selling used firearms to Guns 2 Ammo.
 * Assign to a Page with slug: sell-your-gun
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$img  = '/wp-content/uploads/';
$sent = isset( $_GET['g2a_sent'] );

$steps = [
	[ 'n' => '01', 't' => 'Tell Us About It', 'd' => 'Fill out the quick form below  make, model, condition, and what you are hoping to get. Two minutes, no obligation.' ],
	[ 'n' => '02', 't' => 'Get A Cash Offer', 'd' => 'Our buyer reviews your firearm and emails or calls you a fair, no-pressure offer  usually the same business day.' ],
	[ 'n' => '03', 't' => 'Bring It In', 'd' => 'Like the number? Bring the firearm and your valid Arizona ID to our Mesa shop. We verify and finalize on the spot.' ],
	[ 'n' => '04', 't' => 'Walk Out Paid', 'd' => 'We handle every piece of the federal paperwork. You leave with cash or store credit  your choice.' ],
];

$buy = [
	[ 't' => 'Handguns', 'd' => 'Modern carry pistols, full-size, revolvers  the everyday firearms we resell fastest.' ],
	[ 't' => 'Rifles & Shotguns', 'd' => 'AR-pattern rifles, bolt guns, hunting shotguns, lever actions and more.' ],
	[ 't' => 'Collectible & Surplus', 'd' => 'Milsurp, vintage, and collector pieces  we know what they are actually worth.' ],
	[ 't' => 'Estate & Inherited', 'd' => 'Inherited a firearm or settling an estate? We make a difficult process simple and legal.' ],
	[ 'title' => 'Optics & Accessories', 't' => 'Optics & Accessories', 'd' => 'Quality glass, holsters, and magazines can be bundled into your offer.' ],
	[ 't' => 'Non-Working Firearms', 'd' => 'Even firearms that no longer function may have parts or collector value. Ask us.' ],
];

$why = [
	[ 't' => 'A Real Cash Offer', 'd' => 'No lowball games. We make fair offers based on current market data because we resell locally every day.' ],
	[ 't' => 'Licensed & Legal', 'd' => 'As a federally licensed FFL we run the proper paperwork  far safer than a risky private-party sale.' ],
	[ 't' => 'Zero Listing Hassle', 'd' => 'Skip the online listings, the no-show buyers, and the parking-lot meetups. One visit, done.' ],
	[ 't' => 'Paid On The Spot', 'd' => 'Once we verify the firearm, you are paid immediately  take cash or boosted store credit.' ],
];

$faq = [
	[ 'q' => 'Do I need a license to sell my gun to Guns 2 Ammo?', 'a' => 'No license is required to sell a firearm you lawfully own to a licensed dealer. You simply need to be the legal owner, be an Arizona resident or able to sell lawfully, and bring a valid government photo ID when you drop it off. As an FFL, we handle all the required documentation.' ],
	[ 'q' => 'How do you decide what my firearm is worth?', 'a' => 'Offers are based on the make, model, condition, included accessories, current market demand, and how quickly the firearm resells in our Mesa store. Original box, paperwork, extra magazines, and optics all increase the offer.' ],
	[ 'q' => 'What should I bring when I come in?', 'a' => 'Bring the firearm (unloaded and cased), any magazines, the original box and paperwork if you have them, and your valid Arizona photo ID. That is everything we need to finalize.' ],
	[ 'q' => 'Can I trade my firearm toward something in your shop?', 'a' => 'Absolutely. Many sellers take store credit instead of cash  we boost the value when you trade toward a firearm, optic, or gear from our inventory.' ],
	[ 'q' => 'I inherited a firearm and do not know anything about it. Can you help?', 'a' => 'Yes  this is one of the most common reasons people come to us. Submit whatever details you have, and our team will identify the firearm, explain your options, and walk you through a legal, respectful sale.' ],
	[ 'q' => 'Is selling to a dealer safer than a private sale?', 'a' => 'Considerably. A dealer sale means no strangers at your home, no cash risk, and a documented, federally compliant transfer. It protects you legally and personally.' ],
];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $faq ) );
}
?>
<style>
.sg-hero { padding:140px 32px 80px; position:relative; overflow:hidden; }
.sg-hero::before { content:""; position:absolute; inset:0; pointer-events:none; background-image: linear-gradient(180deg, var(--hero-scrim), var(--hero-scrim-deep)), url('<?php echo esc_url( g2a_asset( 'img/guns2ammo-handgun-counter-mesa.jpg' ) ); ?>'); background-size:cover; background-position:center; }
.sg-hero::after { content:""; position:absolute; inset:0; pointer-events:none; background: radial-gradient(ellipse at 75% 35%, rgba(201,168,76,0.14), transparent 60%); }
.sg-hero .c { position:relative; max-width:1180px; margin:0 auto; }
.sg-hero h1 { font-family: var(--font-display); font-size: clamp(56px,9vw,128px); line-height:0.88; color: var(--ink-on-media); margin:14px 0 0; }
.sg-hero h1 .a { color: var(--brass-on-media); }
.sg-hero .lead { color: var(--ink-on-media-dim); max-width:58ch; margin:22px 0 0; font-size:17px; line-height:1.7; }
.sg-hero .ctas { display:flex; gap:12px; margin-top:30px; flex-wrap:wrap; }
.sg-hero .trust { display:flex; gap:28px; flex-wrap:wrap; margin-top:36px; padding-top:28px; border-top:1px solid var(--color-hairline); }
.sg-hero .trust .it { font-family: var(--font-mono); font-size:11px; letter-spacing:0.18em; text-transform:uppercase; color: var(--ink-on-media-faint); }
.sg-hero .trust .it strong { display:block; font-family: var(--font-display); font-size:32px; color: var(--brass-on-media); letter-spacing:0.03em; margin-bottom:2px; }
.sg-sec { max-width:1180px; margin:0 auto; padding:80px 32px; }
.sg-sec h2 { font-family: var(--font-display); font-size: clamp(36px,5.5vw,72px); color: var(--color-white); line-height:0.98; letter-spacing:0.01em; }
.sg-sec .intro { color: var(--color-fog); font-size:15px; line-height:1.8; max-width:66ch; margin-top:16px; }
.sg-band { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.sg-steps { display:grid; grid-template-columns: repeat(4,1fr); gap:14px; margin-top:32px; }
@media (max-width:880px){ .sg-steps { grid-template-columns:1fr 1fr; } }
@media (max-width:480px){ .sg-steps { grid-template-columns:1fr; } }
.sg-step { padding:26px; background: var(--color-void); border:1px solid var(--color-hairline); }
.sg-step .n { font-family: var(--font-display); font-size:42px; color: var(--color-brass-bright); line-height:1; }
.sg-step .t { font-family: var(--font-condensed); font-weight:600; font-size:18px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); margin-top:8px; }
.sg-step .d { color: var(--color-fog); font-size:14px; line-height:1.7; margin-top:8px; }
.sg-cards { display:grid; grid-template-columns: repeat(3,1fr); gap:14px; margin-top:32px; }
@media (max-width:820px){ .sg-cards { grid-template-columns:1fr; } }
.sg-card { padding:26px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.sg-card .t { font-family: var(--font-condensed); font-weight:600; font-size:18px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); }
.sg-card .d { color: var(--color-fog); font-size:14px; line-height:1.7; margin-top:8px; }
.sg-why { display:grid; grid-template-columns: repeat(4,1fr); gap:14px; margin-top:32px; }
@media (max-width:880px){ .sg-why { grid-template-columns:1fr 1fr; } }
@media (max-width:480px){ .sg-why { grid-template-columns:1fr; } }
/* Form */
.sg-form-wrap { max-width:900px; margin:0 auto; padding:80px 32px; }
.sg-form-card { background: var(--color-gunmetal); border:1px solid var(--color-brass-dim); padding:40px; }
@media (max-width:560px){ .sg-form-card { padding:24px; } }
.sg-form-card h2 { font-family: var(--font-display); font-size:clamp(32px,4.6vw,52px); color: var(--color-white); letter-spacing:0.02em; line-height:1; }
.sg-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:8px; }
@media (max-width:560px){ .sg-grid { grid-template-columns:1fr; } }
.sg-full { grid-column:1 / -1; }
.sg-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
</style>

<header class="sg-hero hero-media">
  <div class="c">
    <span class="eyebrow">Sell Your Firearm  Mesa, Arizona</span>
    <h1>SELL YOUR GUN.<br>GET PAID <span class="a">FAIR.</span></h1>
    <p class="lead">Guns 2 Ammo buys quality used firearms from Arizona owners every week  handguns, rifles, shotguns, collectibles, and full estates. Get a fair cash offer from a licensed local dealer, with zero listing hassle and none of the risk of a private sale.</p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="#request">Get My Cash Offer</a>
      <a class="btn btn-ghost" href="#how">How It Works</a>
    </div>
    <div class="trust">
      <div class="it"><strong>FFL</strong>Licensed Dealer</div>
      <div class="it"><strong>Same Day</strong>Offer Turnaround</div>
      <div class="it"><strong>Cash</strong>Or Boosted Trade Credit</div>
      <div class="it"><strong><?php $b = g2a_biz(); echo esc_html( number_format( (float) $b['review_rating'], 1 ) ); ?></strong><?php echo esc_html( number_format_i18n( (int) $b['review_count'] ) ); ?> Mesa Reviews</div>
    </div>
  </div>
</header>

<section class="sg-band" id="how">
  <div class="sg-sec">
    <span class="eyebrow" style="margin-bottom:14px;">How It Works</span>
    <h2>FOUR STEPS TO CASH</h2>
    <p class="intro">Selling a firearm should be simple, safe, and legal. Here is exactly how it works at our Mesa storefront  start to finish, often within a single day.</p>
    <div class="sg-steps">
      <?php foreach ( $steps as $s ) : ?>
        <div class="sg-step"><div class="n"><?php echo esc_html( $s['n'] ); ?></div><div class="t"><?php echo esc_html( $s['t'] ); ?></div><div class="d"><?php echo esc_html( $s['d'] ); ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sg-sec">
  <span class="eyebrow" style="margin-bottom:14px;">What We Buy</span>
  <h2>WE BUY MORE THAN YOU'D THINK</h2>
  <p class="intro">If it is a lawful firearm, there is a good chance we are interested. Our team resells across Arizona, so we can pay competitively on a wide range of guns and gear.</p>
  <div class="sg-cards">
    <?php foreach ( $buy as $b ) : ?>
      <div class="sg-card"><div class="t"><?php echo esc_html( $b['t'] ); ?></div><div class="d"><?php echo esc_html( $b['d'] ); ?></div></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="sg-band">
  <div class="sg-sec">
    <span class="eyebrow" style="margin-bottom:14px;">Why Sell To Us</span>
    <h2>THE SMARTER WAY TO SELL</h2>
    <p class="intro">A private sale means strangers, haggling, and legal exposure. Selling to Guns 2 Ammo means a fast, fair, fully documented transaction with people who do this every day.</p>
    <div class="sg-why">
      <?php foreach ( $why as $w ) : ?>
        <div class="sg-card"><div class="t"><?php echo esc_html( $w['t'] ); ?></div><div class="d"><?php echo esc_html( $w['d'] ); ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sg-sec">
  <span class="eyebrow" style="margin-bottom:14px;">What Drives Your Offer</span>
  <h2>HOW WE VALUE A FIREARM</h2>
  <p class="intro">Every offer is grounded in real, current market data. These are the factors that move the number  and the simple things you can do to get the strongest offer.</p>
  <ul class="bullets" style="margin-top:20px; max-width:70ch;">
    <li><strong style="color:var(--color-white);">Make &amp; model</strong>  in-demand brands and platforms resell faster and command more.</li>
    <li><strong style="color:var(--color-white);">Condition</strong>  finish wear, bore condition, and mechanical function all matter.</li>
    <li><strong style="color:var(--color-white);">Completeness</strong>  original box, manual, and extra magazines noticeably raise the offer.</li>
    <li><strong style="color:var(--color-white);">Accessories</strong>  quality optics, lights, and upgrades can be bundled into the price.</li>
    <li><strong style="color:var(--color-white);">Market demand</strong>  what Arizona buyers are actively looking for right now.</li>
  </ul>
  <p class="intro">Want to buy as well as sell? Browse our <a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>" style="color:var(--color-brass-bright);">in-store inventory</a> and <a href="<?php echo esc_url( home_url( '/collections/' ) ); ?>" style="color:var(--color-brass-bright);">firearm collections</a>, or learn about our <a href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>" style="color:var(--color-brass-bright);">FFL transfer services</a>.</p>
</section>

<div class="sg-form-wrap" id="request">
  <div class="sg-form-card">
    <span class="eyebrow" style="margin-bottom:12px;">Get Your Offer</span>
    <h2>TELL US ABOUT YOUR FIREARM</h2>
    <?php if ( $sent ) : ?>
      <div class="alert success" style="margin-top:22px;">
        <span class="ic"></span>
        <div><div class="h">Request Received</div>Thanks  your details are in. Our buyer will review your firearm and send a fair cash offer, usually the same business day. Questions? Call (602)&nbsp;715-2677.</div>
      </div>
    <?php else : ?>
      <p style="color:var(--color-fog); font-size:14px; line-height:1.7; margin:12px 0 22px;">The more detail you give us, the more accurate your offer. Not sure about a field? Leave it blank  our team will follow up. Submitting this form is free and never an obligation to sell.</p>
      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="g2a_request">
        <input type="hidden" name="g2a_subject" value="Sell Your Gun Request">
        <?php wp_nonce_field( 'g2a_request', 'g2a_nonce' ); ?>
        <div class="sg-hp" aria-hidden="true"><label>Leave empty<input type="text" name="g2a_hp" tabindex="-1" autocomplete="off"></label></div>
        <div class="sg-grid">
          <div><label class="form-label" for="s-name">Your Full Name</label><input class="field" id="s-name" name="g2a_f_name" required placeholder="Joseph Hartwell"></div>
          <div><label class="form-label" for="s-email">Email</label><input class="field" id="s-email" type="email" name="g2a_f_email" required placeholder="you@example.com"></div>
          <div><label class="form-label" for="s-phone">Phone</label><input class="field" id="s-phone" name="g2a_f_phone" required placeholder="(480) 555-0199"></div>
          <div><label class="form-label" for="s-city">Your City / Location</label><input class="field" id="s-city" name="g2a_f_location" required placeholder="Mesa, AZ"></div>
          <div><label class="form-label" for="s-make">Firearm Make &amp; Model</label><input class="field" id="s-make" name="g2a_f_firearm" required placeholder="Glock 19 Gen 5"></div>
          <div><label class="form-label" for="s-type">Firearm Type</label>
            <select class="field" id="s-type" name="g2a_f_firearm_type"><option>Handgun</option><option>Rifle</option><option>Shotgun</option><option>Collectible / Surplus</option><option>NFA item</option><option>Other</option></select>
          </div>
          <div><label class="form-label" for="s-cal">Caliber / Gauge</label><input class="field" id="s-cal" name="g2a_f_caliber" placeholder="9mm, 5.56, 12ga"></div>
          <div><label class="form-label" for="s-cond">Condition</label>
            <select class="field" id="s-cond" name="g2a_f_condition"><option>Like new / unfired</option><option>Excellent</option><option>Good</option><option>Fair</option><option>Poor / not working</option><option>Not sure</option></select>
          </div>
          <div><label class="form-label" for="s-price">Your Expected Price</label><input class="field" id="s-price" name="g2a_f_expected_price" placeholder="$  or 'open to offers'"></div>
          <div><label class="form-label" for="s-owner">Are You The Legal Owner?</label>
            <select class="field" id="s-owner" name="g2a_f_legal_owner"><option>Yes  it is mine to sell</option><option>Inherited / estate firearm</option><option>Other  please explain below</option></select>
          </div>
          <div class="sg-full"><label class="form-label" for="s-extras">Box, Papers, Magazines or Accessories?</label><input class="field" id="s-extras" name="g2a_f_included" placeholder="Original box, 3 magazines, red dot optic"></div>
          <div class="sg-full"><label class="form-label" for="s-photos">Link To Photos <span style="text-transform:none;letter-spacing:0;">(optional  paste a Google Photos / Dropbox link)</span></label><input class="field" id="s-photos" name="g2a_f_photo_link" placeholder="https://"></div>
          <div class="sg-full"><label class="form-label" for="s-notes">Anything Else We Should Know?</label><textarea class="field" id="s-notes" name="g2a_f_notes" rows="3" placeholder="History, modifications, why you're selling, or questions."></textarea></div>
          <div class="sg-full"><label class="form-label" for="s-pay">Prefer Cash Or Trade Credit?</label>
            <select class="field" id="s-pay" name="g2a_f_payment_preference"><option>Cash</option><option>Store credit (boosted value)</option><option>Either  show me both</option></select>
          </div>
        </div>
        <div style="margin-top:22px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
          <button type="submit" class="btn btn-ember btn-arrow">Get My Cash Offer</button>
          <span class="form-help">Free &amp; no obligation  ID required at drop-off</span>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<section class="sg-band">
  <div class="sg-sec">
    <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
    <h2 style="margin-bottom:26px;">SELLING YOUR FIREARM  FAQ</h2>
    <div class="acc">
      <?php foreach ( $faq as $i => $f ) : ?>
        <details<?php echo 0 === $i ? ' open' : ''; ?>>
          <summary><?php echo esc_html( $f['q'] ); ?></summary>
          <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sg-sec" style="text-align:center;">
  <span class="eyebrow" style="margin:0 auto 16px;">Mesa's Trusted Firearm Buyer</span>
  <h2>READY FOR YOUR OFFER?</h2>
  <p class="intro" style="margin:14px auto 26px;">It takes two minutes to start  and it could be the easiest, safest firearm sale you ever make.</p>
  <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
    <a class="btn btn-ember btn-arrow" href="#request">Get My Cash Offer</a>
    <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/get-support/' ) ); ?>">Questions? Get Support</a>
  </div>
</section>
<?php get_footer();
