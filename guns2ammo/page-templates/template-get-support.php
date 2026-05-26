<?php
/**
 * Template Name: Get Support
 *
 * Central support page  submit any membership, selling, transfer, order or
 * general support request. Assign to a Page with slug: get-support
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$sent = isset( $_GET['g2a_sent'] );

$topics = [
	[ 't' => 'Membership Help', 'd' => 'Billing, plan changes, linked members, cancellations, or your digital member card.', 'href' => home_url( '/account/' ) ],
	[ 't' => 'Selling A Firearm', 'd' => 'Questions about a cash offer, valuation, or an in-progress sell request.', 'href' => home_url( '/sell-your-gun/' ) ],
	[ 't' => 'FFL Transfers', 'd' => 'Status of an incoming transfer, shipping details, or transfer fees.', 'href' => home_url( '/transfers/' ) ],
	[ 't' => 'Shop & Orders', 'd' => 'Online orders, local pickup, product availability, or returns.', 'href' => home_url( '/shop/' ) ],
	[ 't' => 'Training & Classes', 'd' => 'Course scheduling, rescheduling, prerequisites, or certificates.', 'href' => home_url( '/training/' ) ],
	[ 't' => 'Range & Booking', 'd' => 'Lane reservations, hours, group bookings, or range policies.', 'href' => home_url( '/book-a-lane/' ) ],
];

$faq = [
	[ 'q' => 'How fast will I hear back?', 'a' => 'Most support requests receive a reply within one business day. For anything urgent, call us at (602) 715-2677 during open hours.' ],
	[ 'q' => 'Can I cancel or change my membership here?', 'a' => 'Yes  submit a Membership request below and our team will process the change. You can also manage your plan from your member dashboard.' ],
	[ 'q' => 'I have a question about selling a gun. Is this the right place?', 'a' => 'You can ask general questions here, but to receive an actual cash offer use the dedicated form on our Sell Your Gun page  it captures the firearm details our buyer needs.' ],
];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $faq ) );
}
?>
<style>
.gs-hero { padding:140px 32px 64px; background: radial-gradient(ellipse at 30% 40%, rgba(201,168,76,0.1), transparent 60%), linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.gs-hero .c { max-width:1100px; margin:0 auto; }
.gs-hero h1 { font-family: var(--font-display); font-size: clamp(52px,8vw,112px); line-height:0.9; margin:14px 0 0; color: var(--color-white); }
.gs-hero h1 .a { color: var(--color-brass-bright); }
.gs-hero .lead { color: var(--color-fog); max-width:60ch; margin:20px 0 0; font-size:16px; line-height:1.7; }
.gs-sec { max-width:1100px; margin:0 auto; padding:64px 32px; }
.gs-sec h2 { font-family: var(--font-display); font-size: clamp(32px,5vw,56px); color: var(--color-white); }
.gs-topics { display:grid; grid-template-columns: repeat(3,1fr); gap:14px; margin-top:28px; }
@media (max-width:820px){ .gs-topics { grid-template-columns:1fr 1fr; } }
@media (max-width:520px){ .gs-topics { grid-template-columns:1fr; } }
.gs-topic { display:block; padding:24px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); text-decoration:none; transition: border-color 200ms var(--ease-std), transform 200ms var(--ease-std); }
.gs-topic:hover { border-color: var(--color-brass-dim); transform: translateY(-3px); }
.gs-topic .t { font-family: var(--font-condensed); font-weight:600; font-size:17px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); }
.gs-topic .d { color: var(--color-fog); font-size:13px; line-height:1.65; margin-top:8px; }
.gs-form { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.gs-card { max-width:840px; margin:0 auto; }
.gs-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:8px; }
@media (max-width:560px){ .gs-grid { grid-template-columns:1fr; } }
.gs-full { grid-column:1 / -1; }
.gs-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
.gs-contact { display:flex; gap:32px; flex-wrap:wrap; margin-top:24px; }
.gs-contact .it { font-family: var(--font-mono); font-size:12px; letter-spacing:0.12em; color: var(--color-fog); }
.gs-contact .it strong { display:block; font-family: var(--font-condensed); font-weight:600; font-size:15px; color: var(--color-brass-bright); letter-spacing:0.04em; margin-bottom:3px; text-transform:uppercase; }
.gs-contact .it a { color: var(--color-brass-bright); text-decoration:none; }
</style>

<header class="gs-hero">
  <div class="c">
    <span class="eyebrow">Help Center  We're Here To Help</span>
    <h1>GET <span class="a">SUPPORT.</span></h1>
    <p class="lead">One place for every kind of help  memberships, selling a firearm, FFL transfers, online orders, training, and range bookings. Send us the details and the right person will get back to you, usually within one business day.</p>
  </div>
</header>

<section class="gs-sec">
  <span class="eyebrow" style="margin-bottom:14px;">What Do You Need Help With?</span>
  <h2>SUPPORT TOPICS</h2>
  <div class="gs-topics">
    <?php foreach ( $topics as $t ) : ?>
      <a class="gs-topic" href="<?php echo esc_url( $t['href'] ); ?>">
        <div class="t"><?php echo esc_html( $t['t'] ); ?></div>
        <div class="d"><?php echo esc_html( $t['d'] ); ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="gs-form">
  <div class="gs-sec gs-card" id="request">
    <span class="eyebrow" style="margin-bottom:12px;">Submit A Request</span>
    <h2>HOW CAN WE HELP?</h2>
    <?php if ( $sent ) : ?>
      <div class="alert success" style="margin-top:22px;">
        <span class="ic"></span>
        <div><div class="h">Request Received</div>Thanks  your support request is in. The right team member will follow up, usually within one business day. For anything urgent, call (602)&nbsp;715-2677.</div>
      </div>
    <?php else : ?>
      <p style="color:var(--color-fog); font-size:14px; line-height:1.7; margin:12px 0 22px;">Pick the topic that fits best and tell us what is going on. Include any order or membership number so we can find your account fast.</p>
      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="g2a_request">
        <input type="hidden" name="g2a_subject" value="Support Request">
        <?php wp_nonce_field( 'g2a_request', 'g2a_nonce' ); ?>
        <div class="gs-hp" aria-hidden="true"><label>Leave empty<input type="text" name="g2a_hp" tabindex="-1" autocomplete="off"></label></div>
        <div class="gs-grid">
          <div><label class="form-label" for="g-name">Full Name</label><input class="field" id="g-name" name="g2a_f_name" required placeholder="Joseph Hartwell"></div>
          <div><label class="form-label" for="g-email">Email</label><input class="field" id="g-email" type="email" name="g2a_f_email" required placeholder="you@example.com"></div>
          <div><label class="form-label" for="g-phone">Phone <span style="text-transform:none;letter-spacing:0;">(optional)</span></label><input class="field" id="g-phone" name="g2a_f_phone" placeholder="(480) 555-0199"></div>
          <div><label class="form-label" for="g-topic">Topic</label>
            <select class="field" id="g-topic" name="g2a_f_topic">
              <option>Membership</option><option>Selling A Firearm</option><option>FFL Transfer</option>
              <option>Billing &amp; Payments</option><option>Shop / Online Order</option><option>Training &amp; Classes</option>
              <option>Range Booking</option><option>Other</option>
            </select>
          </div>
          <div class="gs-full"><label class="form-label" for="g-ref">Order / Membership Number <span style="text-transform:none;letter-spacing:0;">(if you have one)</span></label><input class="field" id="g-ref" name="g2a_f_reference" placeholder="Helps us find your account faster"></div>
          <div class="gs-full"><label class="form-label" for="g-msg">How Can We Help?</label><textarea class="field" id="g-msg" name="g2a_f_message" rows="5" required placeholder="Tell us what's going on and what you'd like us to do."></textarea></div>
        </div>
        <div style="margin-top:22px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
          <button type="submit" class="btn btn-ember btn-arrow">Submit Support Request</button>
          <span class="form-help">We reply within one business day</span>
        </div>
      </form>
    <?php endif; ?>
    <div class="gs-contact">
      <div class="it"><strong>Call Us</strong><a href="tel:+16027152677">(602) 715-2677</a></div>
      <div class="it"><strong>Visit</strong>6030 E Main St, Mesa, AZ 85205</div>
      <div class="it"><strong>Hours</strong>MonThu 106  Sat 98  Sun 126</div>
    </div>
  </div>
</section>

<section class="gs-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
  <h2 style="margin-bottom:24px;">SUPPORT FAQ</h2>
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
