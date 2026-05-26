<?php
/**
 * Template Name: CCW Syllabus
 *
 * Detailed syllabus landing page for the Arizona CCW certification class.
 * Assign to a Page with slug: arizona-ccw-syllabus
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$modules = [
	[ 'time' => 'Hour 1', 'title' => 'Registration & Course Foundations', 'points' => [
		'Check-in, ID verification, and liability waiver', 'Course overview and what certification does  and does not  authorize', 'The four universal firearm safety rules', 'Range and classroom safety briefing',
	] ],
	[ 'time' => 'Hours 23', 'title' => 'Arizona Law & Reciprocity', 'points' => [
		'Arizona concealed-carry statute and permit-optional carry', 'Where you may and may not legally carry', 'Reciprocity  the 37+ states that honor an Arizona permit', 'Transporting firearms in vehicles and across state lines',
	] ],
	[ 'time' => 'Hour 4', 'title' => 'Use Of Force & Legal Doctrine', 'points' => [
		'Justification, proportionality, and the legal standard for deadly force', 'Duty to retreat, defense of others, and defense of property', 'The legal and financial aftermath of a defensive incident', 'De-escalation and conflict avoidance as the first option',
	] ],
	[ 'time' => 'Hour 5', 'title' => 'Mindset, Awareness & Safe Storage', 'points' => [
		'Situational awareness and threat recognition', 'Carrying responsibly around family and in public', 'Safe storage at home and in vehicles', 'Holster selection and concealment basics',
	] ],
	[ 'time' => 'Hours 67', 'title' => 'Live-Fire Range Block', 'points' => [
		'Grip, stance, sight alignment, and trigger control review', 'Drawing from concealment under instructor supervision', 'Marksmanship drills at defensive distances', 'The Arizona CCW live-fire qualification course',
	] ],
	[ 'time' => 'Hour 8', 'title' => 'Written Exam & Certification', 'points' => [
		'20-question written exam  80% pass mark (most students score 95+)', 'One-on-one review for anyone who needs extra time, at no charge', 'State application paperwork prepared with you before you leave', 'Same-day certificate of completion issued',
	] ],
];

$faq = [
	[ 'q' => 'How long is the Arizona CCW class?', 'a' => 'The full certification is an eight-hour course  typically a single Saturday or Sunday  combining classroom instruction, a live-fire range block, and a written exam.' ],
	[ 'q' => 'Do I have to pass a shooting test?', 'a' => 'Yes, there is a live-fire qualification course of fire. It is designed to be achievable for new shooters, and instructors coach you through it. The pass rate is above 98%.' ],
	[ 'q' => 'What should I bring to class?', 'a' => 'Valid Arizona photo ID, closed-toe shoes, and a high-neck shirt for the range. Your personal carry firearm and holster are welcome; rentals and ammunition are included if you prefer.' ],
];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $faq ) );
}
?>
<style>
.sy-hero { padding:140px 32px 64px; background: radial-gradient(ellipse at 30% 45%, rgba(201,168,76,0.12), transparent 60%), linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.sy-hero .c { max-width:1100px; margin:0 auto; }
.sy-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); }
.sy-hero .crumbs a { color: var(--color-brass-bright); text-decoration:none; }
.sy-hero h1 { font-family: var(--font-display); font-size: clamp(48px,8vw,108px); line-height:0.9; margin:14px 0 0; color: var(--color-white); }
.sy-hero h1 .a { color: var(--color-brass-bright); }
.sy-hero .lead { color: var(--color-fog); max-width:62ch; margin:20px 0 0; font-size:16px; line-height:1.75; }
.sy-hero .ctas { display:flex; gap:12px; margin-top:26px; flex-wrap:wrap; }
.sy-sec { max-width:1100px; margin:0 auto; padding:72px 32px; }
.sy-sec h2 { font-family: var(--font-display); font-size: clamp(32px,5vw,56px); color: var(--color-white); }
.sy-mod { border:1px solid var(--color-hairline); margin-top:30px; }
.sy-row { display:grid; grid-template-columns: 160px 1fr; gap:0; border-bottom:1px solid var(--color-hairline); }
.sy-row:last-child { border-bottom:0; }
@media (max-width:680px){ .sy-row { grid-template-columns:1fr; } }
.sy-row .when { padding:26px; background: rgba(201,168,76,0.05); border-right:1px solid var(--color-hairline); font-family: var(--font-display); font-size:24px; color: var(--color-brass-bright); letter-spacing:0.03em; }
@media (max-width:680px){ .sy-row .when { border-right:0; border-bottom:1px solid var(--color-hairline); } }
.sy-row .body { padding:26px; }
.sy-row .body .t { font-family: var(--font-condensed); font-weight:600; font-size:19px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); margin-bottom:12px; }
.sy-mod-list { list-style:none; padding:0; margin:0; display:grid; gap:9px; }
.sy-mod-list li { color: var(--color-fog); font-size:14px; line-height:1.6; padding-left:20px; position:relative; }
.sy-mod-list li::before { content:""; position:absolute; left:0; color: var(--color-brass-bright); font-size:9px; top:5px; }
.sy-band { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.sy-cta { text-align:center; }
</style>

<header class="sy-hero">
  <div class="c">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>">Arizona CCW</a>  Syllabus</div>
    <span class="eyebrow" style="margin-top:14px;">Arizona CCW  Full Course Syllabus</span>
    <h1>THE 8-HOUR<br><span class="a">SYLLABUS.</span></h1>
    <p class="lead">Exactly what we cover in the Arizona CCW certification class, hour by hour  from state law and use-of-force doctrine to the live-fire qualification and written exam. No surprises, no filler.</p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>#reserve">Enroll In Arizona CCW</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>">Back To CCW Overview</a>
    </div>
  </div>
</header>

<section class="sy-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Hour By Hour</span>
  <h2>WHAT THE DAY COVERS</h2>
  <div class="sy-mod">
    <?php foreach ( $modules as $m ) : ?>
      <div class="sy-row">
        <div class="when"><?php echo esc_html( $m['time'] ); ?></div>
        <div class="body">
          <div class="t"><?php echo esc_html( $m['title'] ); ?></div>
          <ul class="sy-mod-list">
            <?php foreach ( $m['points'] as $p ) : ?><li><?php echo esc_html( $p ); ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="sy-band">
  <div class="sy-sec">
    <span class="eyebrow" style="margin-bottom:14px;">Come Prepared</span>
    <h2>WHAT TO BRING</h2>
    <ul class="bullets" style="margin-top:20px; max-width:62ch;">
      <li>Valid Arizona photo ID  required to certify and to file your application.</li>
      <li>Closed-toe shoes and a high-neck shirt for the live-fire range block.</li>
      <li>Your personal carry firearm and holster, if you have them  optional.</li>
      <li>A rental firearm, 50 rounds of 9mm, and eye/ear protection are included.</li>
      <li>A notepad  the law and use-of-force material is worth keeping.</li>
    </ul>
  </div>
</section>

<section class="sy-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
  <h2 style="margin-bottom:24px;">SYLLABUS FAQ</h2>
  <div class="acc">
    <?php foreach ( $faq as $i => $f ) : ?>
      <details<?php echo 0 === $i ? ' open' : ''; ?>>
        <summary><?php echo esc_html( $f['q'] ); ?></summary>
        <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="sy-band">
  <div class="sy-sec sy-cta">
    <span class="eyebrow" style="margin:0 auto 16px;">Ready To Certify?</span>
    <h2>RESERVE YOUR ARIZONA CCW SEAT</h2>
    <p style="color:var(--color-fog); max-width:52ch; margin:14px auto 26px;">Classes run Saturdays and Sundays and most fill two weeks out. Reserve early.</p>
    <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>#reserve">Enroll In Arizona CCW</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/training/' ) ); ?>">See All Training</a>
    </div>
  </div>
</section>
<?php get_footer();
