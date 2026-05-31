<?php
/**
 * Template Name: Private Instruction
 *
 * Dedicated landing page for one-on-one private training.
 * Assign to a Page with slug: private-instruction
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$img = '/wp-content/uploads/';

$faq = [
	[ 'q' => 'How much does a private session cost?', 'a' => 'Private instruction is $140 per hour for up to two shooters. Members receive a discount  ask when you book. Range time, targets, and eye/ear are included.' ],
	[ 'q' => 'Can I use my own firearm?', 'a' => 'Absolutely. Train with the firearm you actually carry or own. If you prefer, range rentals are available so you can try different platforms.' ],
	[ 'q' => 'What can a private session cover?', 'a' => 'Anything  first-time fundamentals, draw from concealment, malfunction clearing, accuracy troubleshooting, or prep for a CCW class. It is built around your goals, not a fixed curriculum.' ],
	[ 'q' => 'How far in advance should I book?', 'a' => 'A few days is usually enough, but weekends fill fast. Send a request with your preferred dates and we will confirm the soonest available slot.' ],
];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $faq ) );
}
?>
<style>
.pi-hero { padding:140px 32px 72px; position:relative; overflow:hidden; }
.pi-hero::before { content:""; position:absolute; inset:0; pointer-events:none; background-image: linear-gradient(180deg, rgba(26,25,30,0.82), rgba(26,25,30,0.96)), url('<?php echo esc_url( $img . '2025/03/IMG_20250317_085324.webp' ); ?>'); background-size:cover; background-position:center; }
.pi-hero .c { position:relative; max-width:1180px; margin:0 auto; }
.pi-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); }
.pi-hero .crumbs a { color: var(--color-brass-bright); text-decoration:none; }
.pi-hero h1 { font-family: var(--font-display); font-size: clamp(52px,8.5vw,124px); line-height:0.9; margin:14px 0 0; color: var(--color-white); }
.pi-hero h1 .a { color: var(--color-ember); }
.pi-hero .lead { color: var(--color-fog); max-width:58ch; margin:20px 0 0; font-size:16px; line-height:1.7; }
.pi-hero .ctas { display:flex; gap:12px; margin-top:28px; flex-wrap:wrap; }
.pi-sec { max-width:1180px; margin:0 auto; padding:72px 32px; }
.pi-sec h2 { font-family: var(--font-display); font-size: clamp(34px,5vw,60px); color: var(--color-white); letter-spacing:0.01em; }
.pi-grid { display:grid; grid-template-columns: repeat(3,1fr); gap:14px; margin-top:30px; }
@media (max-width:820px){ .pi-grid { grid-template-columns:1fr; } }
.pi-card { padding:26px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.pi-card .n { font-family: var(--font-display); font-size:38px; color: var(--color-brass-bright); line-height:1; }
.pi-card .t { font-family: var(--font-condensed); font-weight:600; font-size:18px; text-transform:uppercase; color: var(--color-white); margin-top:8px; }
.pi-card .d { color: var(--color-fog); font-size:14px; line-height:1.7; margin-top:8px; }
.pi-rate { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.pi-rate .row { max-width:1180px; margin:0 auto; padding:56px 32px; display:grid; grid-template-columns:1fr auto; gap:32px; align-items:center; }
@media (max-width:760px){ .pi-rate .row { grid-template-columns:1fr; } }
.pi-rate .num { font-family: var(--font-display); font-size:clamp(72px,11vw,128px); color: var(--color-brass-bright); line-height:0.85; }
.pi-rate .lbl { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; color: var(--color-silver); text-transform:uppercase; margin-top:10px; }
</style>

<header class="pi-hero">
  <div class="c">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/training/' ) ); ?>">Training</a>  Private Instruction</div>
    <span class="eyebrow" style="margin-top:14px;">Training  One-on-One</span>
    <h1>PRIVATE<br><span class="a">INSTRUCTION.</span></h1>
    <p class="lead">One-on-one or two-shooter sessions on your schedule, built entirely around your skill gap  not a fixed curriculum. The fastest way to get genuinely better with a firearm.</p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="#reserve">Request A Session</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/training/' ) ); ?>">Browse Group Courses</a>
    </div>
  </div>
</header>

<section class="pi-sec">
  <div style="max-width:62ch;">
    <span class="eyebrow" style="margin-bottom:14px;">Why Go Private</span>
    <h2>YOUR GOALS, YOUR PACE</h2>
    <p style="color:var(--color-fog); font-size:15px; line-height:1.8; margin-top:16px;">Group classes are excellent  but nothing closes a skill gap faster than undivided instructor attention. Private sessions are perfect for first-time shooters who want a calm start, carriers troubleshooting accuracy or draw speed, couples and families training together, and anyone preparing for a CCW or defensive course.</p>
  </div>
  <div class="pi-grid">
    <div class="pi-card"><div class="n">01</div><div class="t">Tell Us Your Goal</div><div class="d">First firearm, draw from concealment, fixing a flinch, CCW prep  whatever you want to work on.</div></div>
    <div class="pi-card"><div class="n">02</div><div class="t">We Match An Instructor</div><div class="d">NRA, USCCA, and former law-enforcement instructors  paired to your goal and experience level.</div></div>
    <div class="pi-card"><div class="n">03</div><div class="t">Train One-On-One</div><div class="d">Focused range time with coaching on every shot. Bring your own firearm or use a rental.</div></div>
  </div>
</section>

<section class="pi-rate">
  <div class="row">
    <div>
      <span class="eyebrow" style="margin-bottom:14px;">Simple Pricing</span>
      <h2 style="font-family:var(--font-display);">ONE FLAT HOURLY RATE</h2>
      <p style="color:var(--color-fog); margin-top:14px; max-width:48ch;">Range time, targets, and eye/ear protection are included. Members save on every session  and a +1 trains with you at no extra charge.</p>
    </div>
    <div style="text-align:right;">
      <div class="num">$140</div>
      <div class="lbl">Per Hour  Up To 2 Shooters</div>
    </div>
  </div>
</section>

<section class="pi-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
  <h2 style="margin-bottom:26px;">PRIVATE INSTRUCTION FAQ</h2>
  <div class="acc">
    <?php foreach ( $faq as $i => $f ) : ?>
      <details<?php echo 0 === $i ? ' open' : ''; ?>>
        <summary><?php echo esc_html( $f['q'] ); ?></summary>
        <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<?php
get_template_part( 'template-parts/reservation-form', null, [
	'subject' => 'Private Instruction Session',
	'heading' => 'REQUEST A PRIVATE SESSION',
	'intro'   => 'Tell us your goal and preferred dates. Our team will match you with the right instructor and confirm a time  usually within one business day.',
	'cta'     => 'Request Private Session',
] );
get_footer();
