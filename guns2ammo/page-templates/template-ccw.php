<?php
/**
 * Template Name: Arizona CCW Certification
 *
 * Source: design/ccw.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>.ccw-hero { padding: 140px 32px 64px; min-height:60vh; display:flex; align-items:center; background: radial-gradient(ellipse at 30% 50%, rgba(201,168,76,0.10), transparent 60%), linear-gradient(180deg,var(--color-surface-2),var(--color-void)); position:relative; }
.ccw-hero .c { max-width:1280px; margin:0 auto; width:100%; }
.ccw-hero h1 { font-family: var(--font-display); font-size: clamp(56px,9vw,128px); line-height:0.92; }
.ccw-hero h1 .a { color: var(--color-brass-bright); }
.ccw-hero p { color: var(--color-fog); max-width: 56ch; margin: 24px 0 32px; font-size: 17px; }
.ccw-meta { display:flex; gap:32px; margin-top:32px; flex-wrap:wrap; padding-top:32px; border-top:1px solid var(--color-hairline); }
.ccw-meta .v { font-family: var(--font-display); font-size:48px; color: var(--color-brass-bright); line-height:1; }
.ccw-meta .l { font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-silver); text-transform: uppercase; margin-top:6px; }

.cols { padding: 100px 32px; }
.cols .c { max-width:1280px; margin:0 auto; display:grid; grid-template-columns: repeat(3,1fr); gap:24px; }
@media(max-width:900px){ .cols .c { grid-template-columns:1fr; } }
.col { padding: 32px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.col h3 { font-family: var(--font-display); font-size:32px; color: var(--color-white); margin: 0 0 18px; }
.col ul { list-style:none; padding:0; margin:0; }
.col ul li { padding: 10px 0; font-size:14px; color: var(--color-fog); display:flex; gap:12px; border-bottom:1px solid var(--color-hairline); }
.col ul li:last-child { border-bottom:0; }
.col ul li::before { content:""; color: var(--color-brass-bright); font-size:10px; line-height:1.6; }

.timeline { padding: 100px 32px; background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.timeline .c { max-width:1280px; margin:0 auto; }
.timeline h2 { font-family: var(--font-display); font-size: clamp(40px,6vw,72px); margin-bottom: 56px; }
.tl-grid { display:grid; grid-template-columns: repeat(5, 1fr); gap:8px; position:relative; }
@media(max-width:900px){ .tl-grid { grid-template-columns:1fr; } }
.tl-step { padding: 24px; background: var(--color-void); border:1px solid var(--color-hairline); position:relative; }
.tl-step .num { font-family: var(--font-display); font-size:48px; color: var(--color-brass-bright); line-height:1; }
.tl-step h4 { font-family: var(--font-condensed); font-weight:600; font-size:16px; letter-spacing:0.06em; text-transform: uppercase; color: var(--color-white); margin: 12px 0 6px; }
.tl-step p { font-size:13px; color: var(--color-fog); margin:0; }
.tl-step::after { content:""; position:absolute; right:-8px; top:50%; transform: translateY(-50%); width:16px; height:1px; background: var(--color-brass-dim); z-index:2; }
.tl-step:last-child::after { display:none; }
@media(max-width:900px){ .tl-step::after { display:none; } }

.instr { padding: 100px 32px; }
.instr .c { max-width:1280px; margin:0 auto; display:grid; grid-template-columns: 1fr 1fr; gap:48px; align-items:center; }
@media(max-width:900px){ .instr .c { grid-template-columns:1fr; } }
.creds { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
.cred { padding: 18px; border:1px solid var(--color-hairline); background: var(--color-gunmetal); }
.cred .ic { color: var(--color-brass-bright); font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; }
.cred .v { font-family: var(--font-condensed); font-weight:600; font-size:18px; color: var(--color-white); margin-top:6px; letter-spacing:0.04em; }

.faq { padding: 100px 32px; background: var(--color-void); border-top:1px solid var(--color-hairline); }
.faq .c { max-width: 880px; margin:0 auto; }
.faq h2 { font-family: var(--font-display); font-size: clamp(40px,6vw,72px); margin-bottom: 32px; }
.q { border-bottom:1px solid var(--color-hairline); padding: 18px 0; cursor: pointer; }
.q summary { list-style: none; display:flex; justify-content:space-between; gap:24px; align-items:center; font-family: var(--font-condensed); font-weight:600; font-size:18px; letter-spacing:0.04em; color: var(--color-white); text-transform: uppercase; }
.q summary::-webkit-details-marker { display:none; }
.q summary .ex { width:24px; height:24px; border:1px solid var(--color-brass); color: var(--color-brass-bright); display:grid; place-items:center; transition: transform 240ms var(--ease-out); flex-shrink:0; }
.q[open] summary .ex { transform: rotate(45deg); }
.q .a { padding: 14px 0 4px; color: var(--color-fog); font-size:15px; line-height:1.65; }</style>
<header class="ccw-hero">
  <div class="c">
    <span class="eyebrow"> Arizona CCW Course  NRA-Certified Curriculum</span>
    <h1 style="margin-top:18px;">ARIZONA CCW<br><span class="a">COURSE.</span></h1>
    <p>One day. Live-fire training. Same-day certificate. Our class is designed by instructors with backgrounds in law enforcement, military, and competitive shooting.</p>
    <div style="display:flex; gap:14px; flex-wrap:wrap;">
      <a class="btn btn-ember btn-lg" href="#reserve">Enroll Now  $75</a>
      <a class="btn btn-brass btn-lg" href="<?php echo esc_url( home_url( '/arizona-ccw-syllabus/' ) ); ?>">View Syllabus</a>
    </div>
    <div class="ccw-meta">
      <div><div class="v">8 hrs</div><div class="l">Class Length</div></div>
      <div><div class="v">37+</div><div class="l">Reciprocal States</div></div>
      <div><div class="v">$75</div><div class="l">Tuition</div></div>
      <div><div class="v">Same Day</div><div class="l">Certificate</div></div>
    </div>
  </div>
</header>

<section class="cols" id="syllabus">
  <div class="c">
    <div class="col">
      <span class="eyebrow"> 01</span>
      <h3 style="margin-top:14px;">WHAT YOU'LL LEARN</h3>
      <ul>
        <li>Arizona CCW law and reciprocity</li>
        <li>Use-of-force doctrine and de-escalation</li>
        <li>Safe storage in vehicle and home</li>
        <li>Marksmanship fundamentals</li>
        <li>Drawing from concealment</li>
        <li>Live-fire qualification course</li>
        <li>Mindset, awareness &amp; conflict avoidance</li>
      </ul>
      <a class="btn btn-ghost btn-sm" style="margin-top:18px;" href="<?php echo esc_url( home_url( '/arizona-ccw-syllabus/' ) ); ?>">View Full Syllabus </a>
    </div>
    <div class="col">
      <span class="eyebrow"> 02</span>
      <h3 style="margin-top:14px;">WHAT'S INCLUDED</h3>
      <ul>
        <li>8-hour structured curriculum</li>
        <li>50 rounds of 9mm ammunition</li>
        <li>Eye and ear protection</li>
        <li>Course manual and pocket reference</li>
        <li>Lane fee for live fire portion</li>
        <li>Same-day certificate of completion</li>
        <li>State paperwork prep assistance</li>
      </ul>
      <a class="btn btn-ghost btn-sm" style="margin-top:18px;" href="<?php echo esc_url( home_url( '/arizona-ccw-syllabus/' ) ); ?>">View Full Syllabus </a>
    </div>
    <div class="col">
      <span class="eyebrow"> 03</span>
      <h3 style="margin-top:14px;">WHO SHOULD ATTEND</h3>
      <ul>
        <li>First-time owners and new carriers</li>
        <li>Anyone re-certifying expired permits</li>
        <li>Out-of-state CCW holders adding AZ</li>
        <li>Church security volunteers</li>
        <li>Spouses and family of LEOs</li>
        <li>Healthcare and home-care workers</li>
      </ul>
      <a class="btn btn-ghost btn-sm" style="margin-top:18px;" href="<?php echo esc_url( home_url( '/arizona-ccw-syllabus/' ) ); ?>">View Full Syllabus </a>
    </div>
  </div>
</section>

<section class="instr" id="ca" style="border-top:1px solid var(--color-hairline);">
  <div class="c" style="display:block; max-width:880px;">
    <span class="eyebrow">California Applicants</span>
    <h2 style="font-family: var(--font-display); font-size:clamp(34px,5vw,56px); line-height:0.98; margin: 16px 0 16px;">CALIFORNIA CCW<br>SHOOTING QUALIFICATIONS</h2>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.85; max-width:72ch;">This booking is for the live-fire qualification component only. It is not the California classroom education or legal instruction requirement. Students qualify with the specific firearms they plan to place on their permit.</p>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.85; max-width:72ch; margin-top:16px;">Qualifications are scheduled in supervised lane slots with RSO oversight and separate pricing from standard class bookings.</p>
  </div>
</section>

<section class="timeline">
  <div class="c">
    <span class="eyebrow"> Day Of Class</span>
    <h2 style="margin-top:18px;">FIVE STEPS.<br>ONE DAY.</h2>
    <div class="tl-grid">
      <div class="tl-step"><div class="num">01</div><h4>Registration</h4><p>Arrive, sign waiver, ID check, breakfast on us.</p></div>
      <div class="tl-step"><div class="num">02</div><h4>Morning Class</h4><p>Law, doctrine, mechanics, de-escalation.</p></div>
      <div class="tl-step"><div class="num">03</div><h4>Live Fire</h4><p>Range time. Drawing, drills, qualification.</p></div>
      <div class="tl-step"><div class="num">04</div><h4>Written Test</h4><p>20 questions. Pass mark 80%. Most score 95+.</p></div>
      <div class="tl-step"><div class="num">05</div><h4>Certificate</h4><p>Walk out with proof. State paperwork in hand.</p></div>
    </div>
  </div>
</section>

<section class="instr">
  <div class="c">
    <div>
      <span class="eyebrow"> Instructor Authority</span>
      <h2 style="font-family: var(--font-display); font-size:clamp(40px,5vw,64px); line-height:0.95; margin: 18px 0 18px;">TAUGHT BY<br>OPERATORS.</h2>
      <p style="color: var(--color-fog); max-width:48ch;">Our instructors come from law enforcement, military, and competitive shooting backgrounds. Every primary instructor holds NRA certification. Every assistant has at least 5,000 documented training hours.</p>
    </div>
    <div class="creds">
      <div class="cred"><div class="ic"> NRA</div><div class="v">Certified Instructors</div></div>
      <div class="cred"><div class="ic"> ATF</div><div class="v">FFL Licensed Range</div></div>
      <div class="cred"><div class="ic"> 4.7</div><div class="v">Google  449+ Reviews</div></div>
      <div class="cred"><div class="ic"> DPS</div><div class="v">AZ Approved Curriculum</div></div>
      <div class="cred"><div class="ic"> $2M</div><div class="v">Liability Insured</div></div>
      <div class="cred"><div class="ic"> 12+</div><div class="v">Years In Mesa</div></div>
    </div>
  </div>
</section>

<section class="instr" style="border-top:1px solid var(--color-hairline);">
  <div class="c" style="display:block; max-width:880px;">
    <span class="eyebrow"> Why Certify</span>
    <h2 style="font-family: var(--font-display); font-size:clamp(34px,5vw,56px); line-height:0.98; margin: 16px 0 16px;">ARIZONA IS PERMIT-OPTIONAL.<br>HERE'S WHY A PERMIT STILL MATTERS.</h2>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.85; max-width:72ch;">Arizona allows lawful adults to carry concealed without a permit  so why take the class? Because a permit does three things constitutional carry cannot. It unlocks <strong style="color:var(--color-white);">reciprocity</strong> in 37+ states, so you stay legal when you travel. It can <strong style="color:var(--color-white);">streamline firearm purchases</strong> by serving as a background-check alternative at the counter. And the class itself gives you the one thing carrying a firearm truly demands: a working knowledge of <strong style="color:var(--color-white);">use-of-force law, de-escalation, and the legal aftermath</strong> of a defensive decision.</p>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.85; max-width:72ch; margin-top:16px;">Our curriculum is built by instructors with law-enforcement, military, and competitive-shooting backgrounds. New shooters are welcome  about a third of every class has never carried before. Want the full hour-by-hour breakdown first? <a href="<?php echo esc_url( home_url( '/arizona-ccw-syllabus/' ) ); ?>" style="color:var(--color-brass-bright);">Read the complete Arizona CCW syllabus</a>, explore <a href="<?php echo esc_url( home_url( '/training/' ) ); ?>" style="color:var(--color-brass-bright);">all our training courses</a>, or step up afterward to <a href="<?php echo esc_url( home_url( '/training/defensive-pistol/' ) ); ?>" style="color:var(--color-brass-bright);">Defensive Pistol</a>.</p>
  </div>
</section>

<section class="faq">
  <div class="c">
    <span class="eyebrow"> Frequently Asked</span>
    <h2 style="margin-top:18px;">QUESTIONS.</h2>

    <details class="q"><summary>Do I need to bring my own gun?<span class="ex">+</span></summary><div class="a">No. Rentals are included for the live-fire portion. If you'd like to qualify with your personal carry firearm, you're welcome to bring it.</div></details>
    <details class="q"><summary>How long until the state issues my permit?<span class="ex">+</span></summary><div class="a">Once you mail in your packet, AZ DPS typically returns the permit in 46 weeks. We help you complete the paperwork before you leave.</div></details>
    <details class="q"><summary>Which states honor an Arizona CCW?<span class="ex">+</span></summary><div class="a">37+ states currently recognize AZ permits, including TX, FL, OH, PA, GA, and most of the Mountain West. Reciprocity changes  check the latest list with us.</div></details>
    <details class="q"><summary>What's the pass rate?<span class="ex">+</span></summary><div class="a">Above 98%. The class is designed to teach, not to fail you. Anyone who needs additional time gets it at no charge.</div></details>
    <details class="q"><summary>I've never fired a gun. Is this for me?<span class="ex">+</span></summary><div class="a">Yes  and we love first-timers. About 30% of every class is brand new. The morning curriculum starts from absolute fundamentals.</div></details>
    <details class="q"><summary>Do you offer the California CCW class?<span class="ex">+</span></summary><div class="a">Yes. Our CA-eligible curriculum runs monthly and is taught by California-certified instructors. Schedule and pricing differ.</div></details>
    <details class="q"><summary>What's the refund policy?<span class="ex">+</span></summary><div class="a">Full refund up to 48 hours before class. Inside 48 hours we'll reschedule you at no charge.</div></details>
    <details class="q"><summary>Can my spouse and I attend together?<span class="ex">+</span></summary><div class="a">Absolutely  and we offer a 15% couples discount when you book together.</div></details>
  </div>
</section>

<section class="section" style="padding: 100px 32px; background: var(--color-gunmetal); text-align:center;">
  <span class="eyebrow" style="justify-content:center;"> Ready</span>
  <h2 style="font-family: var(--font-display); font-size:clamp(48px,7vw,96px); margin: 18px 0;">ENROLL TODAY.</h2>
  <p style="color: var(--color-fog); max-width: 500px; margin: 0 auto 32px;">Classes run Saturdays and Sundays. Most weeks fill 2 weeks out  book early.</p>
  <div style="display:inline-flex; gap:14px; flex-wrap:wrap;">
    <a class="btn btn-ember btn-lg" href="#reserve">Enroll  $75</a>
    <a class="btn btn-brass btn-lg" href="tel:+16027152677">Call (602) 715-2677</a>
  </div>
</section>

<?php
if ( function_exists( 'g2a_has_booking' ) && g2a_has_booking() ) : ?>
<section class="section g2a-plugin-host" id="reserve">
  <div class="container" style="max-width:1080px;">
    <span class="eyebrow" style="margin-bottom:14px;">Reservations</span>
    <h2 style="font-family:var(--font-display);font-size:clamp(34px,5vw,56px);color:var(--color-white);letter-spacing:0.02em;margin-bottom:24px;">ENROLL IN ARIZONA CCW</h2>
    <?php echo g2a_plugin_section( 'g2a_classes_booking', ' booking_type="ccw-class" form="default-class-booking"' ); ?>
  </div>
</section>
<?php else :
	get_template_part( 'template-parts/reservation-form', null, [
		'subject' => 'Arizona CCW Certification Class',
		'heading' => 'ENROLL IN ARIZONA CCW',
		'intro'   => 'Reserve your seat in the 8-hour Arizona CCW certification class. Send your details and our team will confirm the next available Saturday or Sunday  usually within one business day.',
		'cta'     => 'Request My CCW Seat',
	] );
endif;
get_footer();
