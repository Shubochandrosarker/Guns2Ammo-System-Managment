<?php
/**
 * Template Name: Transfer Request
 *
 * Smart landing page with the Firearms Transfer Request form.
 * Assign to a Page with slug: transfer-request
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$sent = isset( $_GET['g2a_sent'] );

$faq = [
	[ 'q' => 'What happens after I submit this form?', 'a' => 'Our FFL team reviews your request and emails the seller our license and shipping address  usually within one business day. You will be copied so you know it is moving.' ],
	[ 'q' => 'How much does a transfer cost?', 'a' => 'A standard FFL transfer is a $35 flat fee, $15 for each additional firearm in the same shipment, and $100 for NFA items. The background check is included.' ],
	[ 'q' => 'Do I need to know the FFL number?', 'a' => 'No. Just tell us the seller or retailer and we handle the FFL-to-FFL paperwork directly. Most major retailers already have our license on file.' ],
];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $faq ) );
}
?>
<style>
.tr-hero { padding:140px 32px 60px; background: radial-gradient(ellipse at 28% 40%, rgba(201,168,76,0.1), transparent 60%), linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.tr-hero .c { max-width:1100px; margin:0 auto; }
.tr-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); }
.tr-hero .crumbs a { color: var(--color-brass-bright); text-decoration:none; }
.tr-hero h1 { font-family: var(--font-display); font-size: clamp(48px,8vw,104px); line-height:0.9; margin:14px 0 0; color: var(--color-white); }
.tr-hero h1 .a { color: var(--color-brass-bright); }
.tr-hero .lead { color: var(--color-fog); max-width:60ch; margin:20px 0 0; font-size:16px; line-height:1.7; }
.tr-steps { display:flex; gap:24px; flex-wrap:wrap; margin-top:30px; }
.tr-steps .s { font-family: var(--font-mono); font-size:11px; letter-spacing:0.16em; text-transform:uppercase; color: var(--color-fog); }
.tr-steps .s strong { display:block; font-family: var(--font-display); font-size:26px; color: var(--color-brass-bright); letter-spacing:0.03em; margin-bottom:4px; }
.tr-wrap { max-width:1100px; margin:0 auto; padding:64px 32px; display:grid; grid-template-columns: 1.5fr 1fr; gap:40px; align-items:start; }
@media (max-width:880px){ .tr-wrap { grid-template-columns:1fr; } }
.tr-card { background: var(--color-gunmetal); border:1px solid var(--color-brass-dim); padding:36px; }
@media (max-width:560px){ .tr-card { padding:24px; } }
.tr-card h2 { font-family: var(--font-display); font-size: clamp(30px,4.4vw,46px); color: var(--color-white); letter-spacing:0.02em; line-height:1; }
.tr-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:8px; }
@media (max-width:560px){ .tr-grid { grid-template-columns:1fr; } }
.tr-full { grid-column:1 / -1; }
.tr-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
.tr-side .box { background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding:26px; }
.tr-side .box + .box { margin-top:16px; }
.tr-side .label { font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-silver); text-transform:uppercase; }
.tr-side .val { font-family: var(--font-display); font-size:24px; color: var(--color-white); margin:4px 0 14px; line-height:1.1; }
.tr-side .val.brass { color: var(--color-brass-bright); }
.tr-faq { max-width:1100px; margin:0 auto; padding:0 32px 80px; }
.tr-faq h2 { font-family: var(--font-display); font-size:clamp(32px,5vw,56px); color:var(--color-white); margin-bottom:24px; }
</style>

<header class="tr-hero">
  <div class="c">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>">Transfers</a>  Transfer Request</div>
    <span class="eyebrow" style="margin-top:14px;">FFL Services  Start Here</span>
    <h1>FIREARMS TRANSFER<br><span class="a">REQUEST.</span></h1>
    <p class="lead">Bought a firearm online or from a private seller? Send us the details below and our FFL team will contact your seller, accept the shipment, and have your firearm ready for pickup at our Mesa storefront.</p>
    <div class="tr-steps">
      <div class="s"><strong>01</strong>Submit this request</div>
      <div class="s"><strong>02</strong>We contact your seller</div>
      <div class="s"><strong>03</strong>Firearm ships to us</div>
      <div class="s"><strong>04</strong>Background check &amp; pickup</div>
    </div>
  </div>
</header>

<div class="tr-wrap" id="request">
  <div class="tr-card">
    <span class="eyebrow" style="margin-bottom:12px;">Transfer Request Form</span>
    <h2>TELL US ABOUT THE TRANSFER</h2>
    <?php if ( $sent ) : ?>
      <div class="alert success" style="margin-top:22px;">
        <span class="ic"></span>
        <div><div class="h">Request Received</div>Thanks  your transfer request is in. Our FFL team will review it and reach out to coordinate with your seller, usually within one business day. Questions? Call (602)&nbsp;715-2677.</div>
      </div>
    <?php else : ?>
      <p style="color:var(--color-fog); font-size:14px; line-height:1.7; margin:12px 0 22px;">The more detail you give us, the faster we can get your seller what they need. Nothing here is a commitment  it simply starts the transfer.</p>
      <form method="post" action="<?php echo esc_url( g2a_form_action_url() ); ?>">
        <input type="hidden" name="action" value="g2a_request">
        <input type="hidden" name="g2a_subject" value="Firearms Transfer Request">
        <?php wp_nonce_field( 'g2a_request', 'g2a_nonce' ); ?>
        <div class="tr-hp" aria-hidden="true"><label>Leave empty<input type="text" name="g2a_hp" tabindex="-1" autocomplete="off"></label></div>
        <div class="tr-grid">
          <div><label class="form-label" for="f-name">Your Full Name</label><input class="field" id="f-name" name="g2a_f_name" required placeholder="Joseph Hartwell"></div>
          <div><label class="form-label" for="f-email">Email</label><input class="field" id="f-email" type="email" name="g2a_f_email" required placeholder="you@example.com"></div>
          <div><label class="form-label" for="f-phone">Phone</label><input class="field" id="f-phone" name="g2a_f_phone" required placeholder="(480) 555-0199"></div>
          <div><label class="form-label" for="f-resident">Arizona Resident?</label>
            <select class="field" id="f-resident" name="g2a_f_arizona_resident"><option>Yes</option><option>No</option></select>
          </div>
          <div class="tr-full"><label class="form-label" for="f-seller">Seller / Retailer Name</label><input class="field" id="f-seller" name="g2a_f_seller" required placeholder="GunBroker seller, Palmetto State Armory, private party"></div>
          <div class="tr-full"><label class="form-label" for="f-sellercontact">Seller Contact (email or phone, if you have it)</label><input class="field" id="f-sellercontact" name="g2a_f_seller_contact" placeholder="So we can send them our FFL"></div>
          <div><label class="form-label" for="f-make">Firearm Make &amp; Model</label><input class="field" id="f-make" name="g2a_f_firearm" required placeholder="Glock 19 Gen 5"></div>
          <div><label class="form-label" for="f-type">Firearm Type</label>
            <select class="field" id="f-type" name="g2a_f_firearm_type"><option>Handgun</option><option>Rifle</option><option>Shotgun</option><option>NFA item (suppressor / SBR / full-auto)</option><option>Other</option></select>
          </div>
          <div><label class="form-label" for="f-qty">Number Of Firearms</label>
            <select class="field" id="f-qty" name="g2a_f_quantity"><option>1</option><option>2</option><option>3</option><option>4+</option></select>
          </div>
          <div><label class="form-label" for="f-from">Shipping From (state)</label><input class="field" id="f-from" name="g2a_f_shipping_from" placeholder="e.g. Texas"></div>
          <div class="tr-full"><label class="form-label" for="f-notes">Anything Else We Should Know?</label><textarea class="field" id="f-notes" name="g2a_f_notes" rows="3" placeholder="Order number, timeline, or questions."></textarea></div>
        </div>
        <div style="margin-top:22px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
          <button type="submit" class="btn btn-ember btn-arrow">Submit Transfer Request</button>
          <span class="form-help">No fee until pickup  We confirm by email</span>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <aside class="tr-side">
    <div class="box">
      <div class="label">Ship Firearms To</div>
      <div class="val">Guns 2 Ammo<br><span style="font-size:16px;">Attn: FFL Receiving</span></div>
      <div class="label">Address</div>
      <div class="val brass" style="font-size:20px;">6030 E Main St<br>Mesa, AZ 85205</div>
      <?php $g2a_ffl = get_theme_mod( 'g2a_ffl_license', '' ); if ( $g2a_ffl ) : ?>
      <div class="label">FFL License Number</div>
      <div class="val brass" style="font-family:var(--font-mono); font-size:16px; letter-spacing:0.08em;"><?php echo esc_html( $g2a_ffl ); ?></div>
      <?php endif; ?>
    </div>
    <div class="box">
      <div class="label" style="margin-bottom:10px;">Transfer Fees</div>
      <ul class="bullets" style="gap:8px;">
        <li>Standard transfer  $35 flat</li>
        <li>Each additional firearm  $15</li>
        <li>NFA / Class III item  $100</li>
        <li>Background check  included</li>
      </ul>
      <a class="btn btn-ghost btn-sm" style="margin-top:16px; width:100%;" href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>#fees">Full Fee Schedule</a>
    </div>
    <div class="box">
      <div class="label" style="margin-bottom:10px;">Related</div>
      <ul class="bullets" style="gap:8px;">
        <li><a href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>" style="color:var(--color-brass-bright);">How FFL transfers work</a></li>
        <li><a href="<?php echo esc_url( home_url( '/ffl-services/' ) ); ?>" style="color:var(--color-brass-bright);">NFA &amp; Class III services</a></li>
        <li><a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>" style="color:var(--color-brass-bright);">Browse our in-store inventory</a></li>
      </ul>
    </div>
  </aside>
</div>

<section class="tr-faq">
  <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
  <h2>TRANSFER REQUEST FAQ</h2>
  <div class="acc">
    <?php foreach ( $faq as $i => $f ) : ?>
      <details<?php echo 0 === $i ? ' open' : ''; ?>>
        <summary><?php echo esc_html( $f['q'] ); ?></summary>
        <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>
<?php get_footer();
