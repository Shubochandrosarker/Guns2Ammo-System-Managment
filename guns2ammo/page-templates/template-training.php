<?php
/**
 * Template Name: Training
 *
 * Source: design/training.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>body { background: var(--color-void); }
  .hero { padding: 140px 32px 80px; border-bottom:1px solid var(--color-hairline); background: radial-gradient(ellipse at 30% 50%, rgba(201,168,76,0.08), transparent 60%); }
  .hero .c { max-width: 1280px; margin:0 auto; }
  .hero .eb { display:inline-flex; align-items:center; gap:10px; font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-brass-bright); text-transform: uppercase; margin-bottom: 18px; }
  .hero .eb::before { content:""; width: 28px; height:1px; background: var(--color-brass); }
  .hero h1 { font-family: var(--font-display); font-size: clamp(56px, 9vw, 132px); line-height: 0.92; letter-spacing: 0.02em; color: var(--color-white); }
  .hero h1 .a { color: var(--color-brass-bright); }
  .hero p { color: var(--color-fog); max-width: 60ch; margin: 22px 0 0; font-size:17px; line-height:1.7; }
  .hero .meta { display:flex; gap: 36px; flex-wrap:wrap; margin-top: 36px; }
  .hero .meta .it { font-family: var(--font-mono); font-size:11px; letter-spacing:0.22em; color: var(--color-silver); text-transform: uppercase; }
  .hero .meta .it strong { display:block; font-family: var(--font-display); font-size: 32px; color: var(--color-white); letter-spacing: 0.04em; margin-top: 4px; }

  .pathways { padding: 80px 32px; }
  .pathways .c { max-width: 1280px; margin:0 auto; }
  .ph { display:flex; align-items: baseline; justify-content: space-between; margin-bottom: 36px; flex-wrap: wrap; gap: 18px; }
  .ph h2 { font-family: var(--font-display); font-size: clamp(36px, 5vw, 64px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .ph p { color: var(--color-fog); max-width: 36ch; }

  .courses { display:grid; grid-template-columns: repeat(2, 1fr); gap: 0; border:1px solid var(--color-hairline); }
  @media (max-width: 900px) { .courses { grid-template-columns: 1fr; } }
  .course { padding: 36px; border-right:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); display: flex; flex-direction: column; gap: 18px; transition: background 250ms var(--ease-std); }
  .course:hover { background: rgba(201,168,76,0.03); }
  .course:nth-child(2n) { border-right: none; }
  .course .top { display:flex; align-items:center; justify-content: space-between; }
  .course .lvl { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; color: var(--color-brass-bright); text-transform: uppercase; }
  .course .price { font-family: var(--font-display); font-size: 36px; color: var(--color-brass-bright); line-height: 1; }
  .course h3 { font-family: var(--font-display); font-size: 36px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1.05; }
  .course .lead { color: var(--color-fog); line-height: 1.7; }
  .course .specs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; padding: 14px 0; border-top: 1px solid var(--color-hairline); border-bottom: 1px solid var(--color-hairline); }
  .course .specs .s { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.18em; color: var(--color-silver); text-transform: uppercase; }
  .course .specs .s strong { display:block; color: var(--color-white); font-family: var(--font-condensed); font-size: 14px; letter-spacing: 0.04em; margin-top: 2px; }
  .course .acts { display: flex; gap: 10px; align-items: center; margin-top: auto; }

  .featured { padding: 100px 32px; background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
  .featured .c { max-width: 1280px; margin:0 auto; display:grid; grid-template-columns: 1fr 1.2fr; gap: 56px; align-items:center; }
  @media (max-width: 980px) { .featured .c { grid-template-columns: 1fr; } }
  .featured .ph2 { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.24em; color: var(--color-ember); text-transform: uppercase; margin-bottom: 14px; }
  .featured h2 { font-family: var(--font-display); font-size: clamp(48px, 7vw, 88px); line-height: 0.95; color: var(--color-white); letter-spacing: 0.02em; }
  .featured h2 .a { color: var(--color-brass-bright); }
  .featured p { color: var(--color-fog); margin: 22px 0 28px; font-size: 16px; line-height: 1.75; }
  .featured .vis { aspect-ratio: 4/3; background: var(--color-void); border:1px solid var(--color-brass-dim); display:grid; place-items:center; position: relative; overflow:hidden; }
  .featured .vis::before { content:""; position:absolute; inset:0; background: repeating-linear-gradient(90deg, transparent 0, transparent 60px, rgba(201,168,76,0.06) 60px, rgba(201,168,76,0.06) 61px); }
  .featured .vis .stamp { font-family: var(--font-display); font-size: clamp(80px, 14vw, 200px); color: var(--color-brass-bright); opacity: 0.18; letter-spacing: 0.06em; }
  .featured .vis .ph-tag { position:absolute; top:14px; left:14px; font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; }

  .calendar { padding: 100px 32px; }
  .calendar .c { max-width: 1280px; margin:0 auto; }
  .cal-h { display:flex; align-items: baseline; justify-content: space-between; margin-bottom: 36px; flex-wrap: wrap; gap: 18px; }
  .cal-h h2 { font-family: var(--font-display); font-size: clamp(36px, 5vw, 64px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .cal-grid { display: grid; gap: 0; border:1px solid var(--color-hairline); }
  .cal-row { display:grid; grid-template-columns: 110px 1fr 200px 140px 160px; padding: 22px 24px; border-bottom: 1px solid var(--color-hairline); align-items:center; gap: 18px; transition: background 200ms var(--ease-std); }
  .cal-row:hover { background: rgba(201,168,76,0.03); }
  .cal-row:last-child { border-bottom: none; }
  @media (max-width: 900px) { .cal-row { grid-template-columns: 1fr; gap: 8px; } .cal-row .a { display:none; } }
  .cal-row .date { font-family: var(--font-display); font-size: 28px; color: var(--color-brass-bright); line-height: 1; }
  .cal-row .date small { display:block; font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); margin-top: 4px; }
  .cal-row .nm { font-family: var(--font-condensed); font-weight: 600; font-size: 17px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }
  .cal-row .nm small { display:block; font-family: var(--font-mono); font-weight: 400; font-size: 10px; letter-spacing: 0.2em; color: var(--color-silver); margin-top: 4px; text-transform: uppercase; }
  .cal-row .time { font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.16em; color: var(--color-fog); text-transform: uppercase; }
  .cal-row .seats { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; }
  .cal-row .seats.ok { color: var(--color-active); }
  .cal-row .seats.low { color: var(--color-ember); }
  .cal-row .seats.full { color: var(--color-silver); }

  .instructors { padding: 100px 32px; background: var(--color-void); border-top:1px solid var(--color-hairline); }
  .instructors .c { max-width: 1280px; margin:0 auto; }
  .ig { display:grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 36px; }
  @media (max-width: 900px) { .ig { grid-template-columns: 1fr; } }
  .inst { padding: 28px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
  .inst .av { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, var(--color-brass), var(--color-brass-dim)); display:grid; place-items:center; font-family: var(--font-display); font-size: 26px; color: var(--color-void); margin-bottom: 18px; }
  .inst .nm { font-family: var(--font-display); font-size: 26px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .inst .role { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-brass-bright); text-transform: uppercase; margin-top: 6px; }
  .inst p { color: var(--color-fog); font-size: 14px; line-height: 1.7; margin: 14px 0; }
  .inst .creds { display:flex; gap: 6px; flex-wrap: wrap; }
  .inst .creds span { font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.2em; padding: 4px 8px; border:1px solid var(--color-hairline-bright); color: var(--color-silver); text-transform: uppercase; }

  .private { padding: 100px 32px; }
  .private .c { max-width: 1080px; margin: 0 auto; background: var(--color-gunmetal); border:1px solid var(--color-brass-dim); padding: 56px; display: grid; grid-template-columns: 1.4fr 1fr; gap: 56px; align-items: center; }
  @media (max-width: 900px) { .private .c { grid-template-columns: 1fr; padding: 36px; } }
  .private h2 { font-family: var(--font-display); font-size: clamp(36px, 5vw, 56px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; }
  .private p { color: var(--color-fog); margin: 18px 0 28px; line-height: 1.7; }
  .private .rate { text-align: right; }
  .private .rate .num { font-family: var(--font-display); font-size: 96px; color: var(--color-brass-bright); line-height: 0.9; }
  .private .rate .lbl { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase; margin-top: 8px; }</style>
<section class="hero">
  <div class="c">
    <div class="eb">Section 02  Education</div>
    <h1>TRAINING<br>& <span class="a">CERTIFICATION</span></h1>
    <p>A full pathway of courses from new-shooter fundamentals to Arizona CCW certification — taught by NRA-certified instructors with military and law-enforcement backgrounds.</p>
    <div class="meta">
      <div class="it">Active Courses<strong>09</strong></div>
      <div class="it">Annual Graduates<strong>1,400+</strong></div>
      <div class="it">Pass Rate<strong>98%</strong></div>
      <div class="it">Member Discount<strong>25%</strong></div>
    </div>
  </div>
</section>

<section class="pathways">
  <div class="c">
    <div class="ph">
      <h2>Course Catalog</h2>
      <p>Pick your pathway. Members save 25% on every course; bring a +1 and they save too.</p>
    </div>

    <div class="courses">
      <div class="course">
        <div class="top"><div class="lvl"> Level 01  Beginner</div><div class="price">$95</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/basic-handgun/' ) ); ?>" style="color:inherit;text-decoration:none;">BASIC HANDGUN</a></h3>
        <div class="lead">First-time shooters welcome. Grip, stance, sight alignment, trigger control, and your first 50 rounds on the range under 1:1 instruction.</div>
        <div class="specs"><div class="s">Duration<strong>4 Hours</strong></div><div class="s">Class Size<strong>1–6</strong></div><div class="s">Prereq<strong>None</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/basic-handgun/' ) ); ?>">View Course </a><span class="form-help">Member: $71.25</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 02  Certification</div><div class="price">$85</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>" style="color:inherit;text-decoration:none;">ARIZONA CCW</a></h3>
        <div class="lead">4-hour Arizona CCW certification: A.R.S. &sect;13-3112, use-of-force law, and DPS paperwork handled in class. Add the live-fire session for $149.99 (5 hours, Monday evenings).</div>
        <div class="specs"><div class="s">Duration<strong>4 Hours</strong></div><div class="s">Class Size<strong>4–10</strong></div><div class="s">Prereq<strong>None</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>">View Course </a><span class="form-help">Live-fire option: $149.99</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 03  Multi-State</div><div class="price">$175</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/california-ccw/' ) ); ?>" style="color:inherit;text-decoration:none;">CALIFORNIA CCW</a></h3>
        <div class="lead">CA non-resident certification meeting all California Penal Code 26165 training requirements. Bundle with AZ for 30+ states reciprocity.</div>
        <div class="specs"><div class="s">Duration<strong>16 Hours</strong></div><div class="s">Class Size<strong>4–8</strong></div><div class="s">Prereq<strong>AZ CCW</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/california-ccw/' ) ); ?>">View Course </a><span class="form-help">Member: $131.25</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 03  Specialty</div><div class="price">$249</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/church-security/' ) ); ?>" style="color:inherit;text-decoration:none;">CHURCH SECURITY</a></h3>
        <div class="lead">Faith-based facility protection: threat assessment, plain-clothes carry, congregation safety planning, and coordinated team response.</div>
        <div class="specs"><div class="s">Duration<strong>12 Hours</strong></div><div class="s">Class Size<strong>6–12</strong></div><div class="s">Prereq<strong>CCW</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/church-security/' ) ); ?>">View Course </a><span class="form-help">Member: $186.75</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 02  Family</div><div class="price">$65</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/womens-intro/' ) ); ?>" style="color:inherit;text-decoration:none;">WOMEN'S INTRO</a></h3>
        <div class="lead">Female-instructed beginner course for new women shooters. Tuesday Ladies Day pricing applied  non-judgmental, patiently paced.</div>
        <div class="specs"><div class="s">Duration<strong>3 Hours</strong></div><div class="s">Class Size<strong>1–4</strong></div><div class="s">Prereq<strong>None</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/womens-intro/' ) ); ?>">View Course </a><span class="form-help">Member: $48.75</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 04  Advanced</div><div class="price">$295</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/defensive-pistol/' ) ); ?>" style="color:inherit;text-decoration:none;">DEFENSIVE PISTOL</a></h3>
        <div class="lead">Stress-fire drills, multiple targets, low-light, malfunction clearance, and force-on-force scenarios with airsoft conversions.</div>
        <div class="specs"><div class="s">Duration<strong>10 Hours</strong></div><div class="s">Class Size<strong>4–8</strong></div><div class="s">Prereq<strong>CCW</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/defensive-pistol/' ) ); ?>">View Course </a><span class="form-help">Member: $221.25</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 02  Beginner</div><div class="price">$115</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/rifle-fundamentals/' ) ); ?>" style="color:inherit;text-decoration:none;">RIFLE FUNDAMENTALS</a></h3>
        <div class="lead">Master the AR-15 platform from the ground up  zeroing, marksmanship positions, reloads, and safe long-gun handling.</div>
        <div class="specs"><div class="s">Duration<strong>5 Hours</strong></div><div class="s">Class Size<strong>1–6</strong></div><div class="s">Prereq<strong>None</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/rifle-fundamentals/' ) ); ?>">View Course </a><span class="form-help">Member: $86.25</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 01  Awareness</div><div class="price">$55</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/refuse-to-be-a-victim/' ) ); ?>" style="color:inherit;text-decoration:none;">REFUSE TO BE A VICTIM</a></h3>
        <div class="lead">A no-firearms personal-safety seminar  awareness, avoidance, and practical strategies for home, travel, and digital life.</div>
        <div class="specs"><div class="s">Duration<strong>4 Hours</strong></div><div class="s">Class Size<strong>4–20</strong></div><div class="s">Prereq<strong>None</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/refuse-to-be-a-victim/' ) ); ?>">View Course </a><span class="form-help">Member: $41.25</span></div>
      </div>

      <div class="course">
        <div class="top"><div class="lvl"> Level 01  Youth</div><div class="price">$45</div></div>
        <h3><a href="<?php echo esc_url( home_url( '/training/youth-firearm-safety/' ) ); ?>" style="color:inherit;text-decoration:none;">YOUTH FIREARM SAFETY</a></h3>
        <div class="lead">An age-appropriate firearm-safety class for young people  what to do if they ever encounter a gun, with parents involved throughout.</div>
        <div class="specs"><div class="s">Duration<strong>2 Hours</strong></div><div class="s">Class Size<strong>1–6</strong></div><div class="s">Prereq<strong>Guardian</strong></div></div>
        <div class="acts"><a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/training/youth-firearm-safety/' ) ); ?>">View Course </a><span class="form-help">Member: $33.75</span></div>
      </div>
    </div>
  </div>
</section>

<section class="featured">
  <div class="c">
    <div class="vis"><span class="ph-tag">Photo  CCW Range Day</span><div class="stamp">CCW</div></div>
    <div>
      <div class="ph2"> Featured Pathway</div>
      <h2>FROM NEW SHOOTER<br>TO <span class="a">DUAL-STATE</span> CARRIER</h2>
      <p>Stack Basic Handgun  Arizona CCW  California Non-Resident in three weekends and walk away with reciprocity in 38 states. Members save $97 across the bundle.</p>
      <div style="display:flex; gap:10px; flex-wrap: wrap;">
        <a class="btn btn-brass" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>">View CCW Pathway </a>
        <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/memberships/" ) ); ?>">Become A Member First</a>
      </div>
    </div>
  </div>
</section>

<section class="calendar" id="schedule">
  <div class="c">
    <div class="cal-h">
      <h2>Upcoming Sessions</h2>
      <span class="form-help">Schedule updated daily  Reservations confirmed by email</span>
    </div>
    <div>
      <?php echo do_shortcode( '[g2a_events type="ccw" limit="12"]' ); ?>
    </div>
  </div>
</section>

<section class="instructors">
  <div class="c">
    <div class="ph">
      <h2>The Instructors</h2>
      <p>1:1 ratios on advanced courses. No assistants, no shortcuts.</p>
    </div>
    <?php g2a_render_instructors(); ?>
  </div>
</section>

<section class="private">
  <div class="c">
    <div>
      <h2><a href="<?php echo esc_url( home_url( '/private-instruction/' ) ); ?>" style="color:inherit;text-decoration:none;">PRIVATE INSTRUCTION</a></h2>
      <p>One-on-one or two-shooter private sessions on your schedule. Bring your own firearm or use ours. Tailored to your skill gap  not a curriculum.</p>
      <div style="display:flex; gap:10px; flex-wrap: wrap;">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/private-instruction/' ) ); ?>">Explore Private Instruction</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/private-instruction/' ) ); ?>#reserve">Request A Session</a>
      </div>
    </div>
    <div class="rate">
      <div class="num">$140</div>
      <div class="lbl">Per Hour  2 Shooter Max</div>
    </div>
  </div>
</section>
<?php get_footer();
