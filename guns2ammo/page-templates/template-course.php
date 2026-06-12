<?php
/**
 * Template Name: Course Landing
 *
 * One template, one landing page per training course. Assign to a Page whose
 * slug is one of: basic-handgun, california-ccw, church-security, womens-intro,
 * defensive-pistol, rifle-fundamentals, refuse-to-be-a-victim, youth-firearm-safety
 * (recommended as children of /training/).
 *
 * Arizona CCW has its own dedicated page at /ccw/.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$slug = '';
$qo   = get_queried_object();
if ( $qo && isset( $qo->post_name ) ) $slug = $qo->post_name;

$img = '/wp-content/uploads/';

$courses = [
	'basic-handgun' => [
		'eyebrow' => 'Course  Level 01  Beginner',
		'title'   => 'BASIC HANDGUN',
		'lead'    => 'The honest starting line for every new shooter. Grip, stance, sight alignment, trigger control  then your first 50 rounds downrange under one-on-one instruction.',
		'price'   => '$95', 'member' => '$71.25', 'duration' => '4 Hours', 'size' => '16', 'prereq' => 'None',
		'image'   => g2a_asset( 'img/guns2ammo-customer-first-visit-target.jpg' ),
		'overview'=> 'Basic Handgun is built for people who have never touched a firearm and for those who want to rebuild their fundamentals correctly. Our instructors keep the class small, the pace patient, and the range time hands-on  you will leave able to safely load, fire, and clear a handgun.',
		'learn'   => [ 'The four universal rules of firearm safety', 'Proper grip, stance, and recoil management', 'Sight alignment and sight picture', 'Trigger control and follow-through', 'Safe loading, unloading, and malfunction clearing' ],
		'modules' => [
			[ 't' => 'Classroom Fundamentals', 'd' => 'Safety rules, firearm types, ammunition basics, and range etiquette before you ever touch the line.' ],
			[ 't' => 'Dry-Fire Practice', 'd' => 'Grip and trigger drills with an empty firearm so the mechanics are second nature before live fire.' ],
			[ 't' => 'Live Fire  50 Rounds', 'd' => 'One-on-one coaching on the indoor range  your first real shots, done right.' ],
		],
		'audience'=> 'First-time shooters, newer carriers rebuilding fundamentals, and anyone nervous about their first range visit.',
		'faq'     => [
			[ 'q' => 'Do I need my own firearm?', 'a' => 'No. A range rental and eye/ear protection can be included  just let us know when you reserve. If you own a handgun, bring it.' ],
			[ 'q' => 'Is this class beginner-friendly?', 'a' => 'Completely. Most students have never fired a gun. Our instructors specialize in first-timers and never rush or judge.' ],
			[ 'q' => 'What should I bring?', 'a' => 'Valid photo ID, closed-toe shoes, and a willingness to learn. Everything else can be provided.' ],
		],
	],
	'california-ccw' => [
		'eyebrow' => 'Course  Level 03  Multi-State',
		'title'   => 'CALIFORNIA CCW',
		'lead'    => 'California non-resident certification meeting all California Penal Code training requirements  completed in Arizona, paired with your AZ permit for broad reciprocity.',
		'price'   => '$175', 'member' => '$131.25', 'duration' => '16 Hours', 'size' => '48', 'prereq' => 'Arizona CCW',
		'image'   => g2a_asset( 'img/guns2ammo-range-training-session.jpg' ),
		'overview'=> 'For California residents and frequent visitors, this course covers the classroom hours, legal instruction, and live-fire qualification California requires. Bundle it with your Arizona CCW and you carry legally across a large block of reciprocal states.',
		'learn'   => [ 'California Penal Code use-of-force law', 'Safe handling, storage, and transport requirements', 'Live-fire qualification to California standards', 'Multi-state reciprocity planning', 'Documentation handled for your application' ],
		'modules' => [
			[ 't' => 'Legal Block', 'd' => 'California statute, justified use of force, and the legal weight of carrying concealed.' ],
			[ 't' => 'Handling & Storage', 'd' => 'State-mandated safe-handling and storage instruction with practical demonstration.' ],
			[ 't' => 'Live-Fire Qualification', 'd' => 'Range qualification scored to California standards, with your certificate issued on completion.' ],
		],
		'audience'=> 'California residents, dual-state travelers, and AZ permit holders who want maximum reciprocity.',
		'faq'     => [
			[ 'q' => 'Do I need the Arizona CCW first?', 'a' => 'We recommend it  the AZ + CA combination unlocks the widest reciprocity. Ask us about bundling both for a member discount.' ],
			[ 'q' => 'Does this issue the actual California permit?', 'a' => 'This is the training requirement. The permit itself is issued by your California issuing authority; we provide the certified training documentation.' ],
			[ 'q' => 'How long is the course?', 'a' => 'Sixteen hours, typically run across a weekend. Reserve early  multi-state classes fill quickly.' ],
		],
	],
	'church-security' => [
		'eyebrow' => 'Course  Level 03  Specialty',
		'title'   => 'CHURCH SECURITY',
		'lead'    => 'Faith-based facility protection: threat assessment, plain-clothes carry, congregation safety planning, and coordinated response for volunteer security teams.',
		'price'   => '$249', 'member' => '$186.75', 'duration' => '12 Hours', 'size' => '612', 'prereq' => 'CCW',
		'image'   => g2a_asset( 'img/guns2ammo-shooter-on-lane.jpg' ),
		'overview'=> 'Built for church safety teams and volunteer ministries, this course goes beyond marksmanship into team coordination, de-escalation, medical readiness, and protecting a congregation without disrupting worship.',
		'learn'   => [ 'Threat assessment for places of worship', 'Discreet plain-clothes carry and positioning', 'Team communication and coordinated response', 'De-escalation and crowd management', 'Trauma-care basics and emergency planning' ],
		'modules' => [
			[ 't' => 'Assess & Plan', 'd' => 'Map your facility, identify vulnerabilities, and build a written safety plan for your team.' ],
			[ 't' => 'Team Tactics', 'd' => 'Positioning, communication, and coordinated movement during a service.' ],
			[ 't' => 'Response & Aftermath', 'd' => 'Live-fire decision drills, medical response, and legal aftermath for volunteer protectors.' ],
		],
		'audience'=> 'Church safety teams, ministry volunteers, and faith communities building a protection program.',
		'faq'     => [
			[ 'q' => 'Can our whole team train together?', 'a' => 'Yes  this course is designed for teams. Group bookings of 612 are ideal and we can arrange private dates for your ministry.' ],
			[ 'q' => 'Is a CCW required?', 'a' => 'An active concealed-carry permit is the prerequisite. We can get your team through Arizona CCW first if needed.' ],
			[ 'q' => 'Do you cover legal liability?', 'a' => 'Yes. We cover the legal framework for volunteer protectors and the importance of a documented, church-approved plan.' ],
		],
	],
	'womens-intro' => [
		'eyebrow' => 'Course  Level 02  Family',
		'title'   => "WOMEN'S INTRO",
		'lead'    => 'A female-instructed beginner course for new women shooters  patient, supportive, and entirely judgment-free. Tuesday Ladies Day pricing applied.',
		'price'   => '$65', 'member' => '$48.75', 'duration' => '3 Hours', 'size' => '14', 'prereq' => 'None',
		'image'   => g2a_asset( 'img/guns2ammo-customer-first-visit-target.jpg' ),
		'overview'=> 'Taught by our Women\'s Lead instructor, this small-group course gives new women shooters a calm, encouraging first experience. No pressure, no ego  just clear fundamentals and supportive coaching at your pace.',
		'learn'   => [ 'Firearm safety in plain, practical terms', 'Comfortable grip and stance for any hand size', 'Loading, firing, and clearing with confidence', 'Choosing a firearm that genuinely fits you', 'A clear next step into carry or defensive training' ],
		'modules' => [
			[ 't' => 'Welcome & Safety', 'd' => 'A relaxed classroom start  safety, terminology, and your questions answered.' ],
			[ 't' => 'Hands-On Fundamentals', 'd' => 'Dry-fire grip and trigger work sized to you, with a female instructor beside you.' ],
			[ 't' => 'Range Time', 'd' => 'Your first live rounds on a calmer Tuesday floor, fully coached.' ],
		],
		'audience'=> 'Women who are new to firearms, or returning after a bad first experience elsewhere.',
		'faq'     => [
			[ 'q' => 'Is the instructor a woman?', 'a' => 'Yes. This course is taught by our Women\'s Lead, who built our Tuesday Ladies program from a handful of students to 200+ active shooters.' ],
			[ 'q' => 'Can I bring a friend?', 'a' => 'Please do. Class size is 1–4, so you and friends can train together in the same supportive group.' ],
			[ 'q' => 'What if I have never held a gun?', 'a' => 'That is exactly who this is for. We start at the very beginning and never make you feel rushed.' ],
		],
	],
	'defensive-pistol' => [
		'eyebrow' => 'Course  Level 04  Advanced',
		'title'   => 'DEFENSIVE PISTOL',
		'lead'    => 'Stress-fire drills, multiple targets, low-light shooting, malfunction clearance, and force-on-force scenarios. The course that pressure-tests your carry skills.',
		'price'   => '$295', 'member' => '$221.25', 'duration' => '10 Hours', 'size' => '48', 'prereq' => 'CCW',
		'image'   => g2a_asset( 'img/guns2ammo-range-lane-view.jpg' ),
		'overview'=> 'Defensive Pistol is for carriers who want their skills tested under stress, not just on a calm range. Expect movement, decision-making, low-light work, and scenario-based training that builds real defensive competence.',
		'learn'   => [ 'Drawing from concealment under stress', 'Engaging multiple targets and shoot/no-shoot decisions', 'Low-light and reduced-visibility shooting', 'Rapid malfunction clearance', 'Force-on-force scenario decision-making' ],
		'modules' => [
			[ 't' => 'Stress Fundamentals', 'd' => 'Draw, presentation, and accuracy under a shot timer and added pressure.' ],
			[ 't' => 'Movement & Decisions', 'd' => 'Multiple targets, movement, cover, and shoot/no-shoot judgment drills.' ],
			[ 't' => 'Force-on-Force', 'd' => 'Scenario training with airsoft conversions  the closest safe analog to a real encounter.' ],
		],
		'audience'=> 'Experienced carriers and CCW holders ready to pressure-test and sharpen their skills.',
		'faq'     => [
			[ 'q' => 'How much experience do I need?', 'a' => 'You should hold a CCW or have solid handgun fundamentals. If you are unsure, Basic Handgun or our Arizona CCW course is the right first step.' ],
			[ 'q' => 'What gear do I need?', 'a' => 'Your carry handgun, a sturdy belt and holster, and 300+ rounds. We will send a full gear list on confirmation.' ],
			[ 'q' => 'Is force-on-force safe?', 'a' => 'Yes. Scenario work uses airsoft conversion firearms with full protective gear under strict RSO supervision.' ],
		],
	],
	'rifle-fundamentals' => [
		'eyebrow' => 'Course  Level 02  Beginner',
		'title'   => 'RIFLE FUNDAMENTALS',
		'lead'    => 'Master the modern sporting rifle from the ground up  zeroing, marksmanship, reloads, and safe handling of the AR-15 platform.',
		'price'   => '$115', 'member' => '$86.25', 'duration' => '5 Hours', 'size' => '16', 'prereq' => 'None',
		'image'   => g2a_asset( 'img/guns2ammo-rifle-wall-display.jpg' ),
		'overview'=> 'Whether you just bought your first AR-15 or want to use one confidently for home defense, Rifle Fundamentals covers zeroing, marksmanship positions, reloads, and the safe handling habits every rifle owner needs.',
		'learn'   => [ 'AR-15 platform basics and safe handling', 'Zeroing your optic or iron sights', 'Standing, kneeling, and supported positions', 'Reloads and basic malfunction clearance', 'Home-defense considerations for rifles' ],
		'modules' => [
			[ 't' => 'Platform & Safety', 'd' => 'How the rifle works, controls, ammunition, and the safety habits specific to long guns.' ],
			[ 't' => 'Zero & Marksmanship', 'd' => 'Establish a reliable zero, then build accuracy across practical shooting positions.' ],
			[ 't' => 'Manipulation Drills', 'd' => 'Reloads, malfunction clearance, and confident handling on the live-fire range.' ],
		],
		'audience'=> 'New AR-15 owners, home-defense planners, and shooters moving from handgun to rifle.',
		'faq'     => [
			[ 'q' => 'Can I rent a rifle for class?', 'a' => 'Yes. Rifle and eye/ear rentals can be added when you reserve, or bring your own AR-pattern rifle.' ],
			[ 'q' => 'Do I need rifle experience?', 'a' => 'None at all. This course assumes you are starting fresh and builds from safety up.' ],
			[ 'q' => 'What ammunition do I need?', 'a' => 'Plan on roughly 150 rounds of factory-new 5.56/.223. We stock it in store if you would rather buy here.' ],
		],
	],
	'refuse-to-be-a-victim' => [
		'eyebrow' => 'Course  Level 01  Awareness',
		'title'   => 'REFUSE TO BE A VICTIM',
		'lead'    => 'A no-firearms personal-safety seminar  awareness, avoidance, and practical strategies to keep you and your family safer at home, online, and on the move.',
		'price'   => '$55', 'member' => '$41.25', 'duration' => '4 Hours', 'size' => '420', 'prereq' => 'None',
		'image'   => g2a_asset( 'img/guns2ammo-shooter-on-lane.jpg' ),
		'overview'=> 'Not every safety decision involves a firearm. This seminar focuses on prevention  recognizing threats early, avoiding dangerous situations, and building practical security habits for your home, vehicle, travel, and digital life.',
		'learn'   => [ 'Situational awareness and early threat recognition', 'Home and personal-property security', 'Safe travel and vehicle strategies', 'Personal and digital-safety habits', 'Building a family safety plan' ],
		'modules' => [
			[ 't' => 'Awareness & Mindset', 'd' => 'How predators select targets and how awareness removes you from the list.' ],
			[ 't' => 'Home & Travel Security', 'd' => 'Practical, low-cost steps to harden your home, vehicle, and routines.' ],
			[ 't' => 'Digital & Family Safety', 'd' => 'Online safety, scam avoidance, and a written family emergency plan.' ],
		],
		'audience'=> 'Anyone wanting personal-safety skills without firearms  great for families and groups.',
		'faq'     => [
			[ 'q' => 'Is there any live fire?', 'a' => 'No. Refuse To Be A Victim is a classroom seminar focused entirely on awareness and prevention.' ],
			[ 'q' => 'Is this good for a group or club?', 'a' => 'Excellent for it  we host groups up to 20 and can arrange private dates for clubs, workplaces, and families.' ],
			[ 'q' => 'Will it help me decide about carrying?', 'a' => 'Yes. Many students use it as a thoughtful first step before exploring Basic Handgun or Arizona CCW.' ],
		],
	],
	'youth-firearm-safety' => [
		'eyebrow' => 'Course  Level 01  Youth',
		'title'   => 'YOUTH FIREARM SAFETY',
		'lead'    => 'An age-appropriate firearm-safety class for young people  teaching what to do if they ever encounter a gun, with parents involved every step.',
		'price'   => '$45', 'member' => '$33.75', 'duration' => '2 Hours', 'size' => '16', 'prereq' => 'Parent / guardian present',
		'image'   => g2a_asset( 'img/guns2ammo-indoor-range-lanes-mesa.jpg' ),
		'overview'=> 'Curiosity is normal  unsafe outcomes are preventable. This calm, age-appropriate class teaches young people the firearm-safety rule that matters most: stop, do not touch, leave, and tell an adult. A parent or guardian attends throughout.',
		'learn'   => [ 'Stop  Don\'t Touch  Leave  Tell An Adult', 'Why firearms are not toys', 'What to do if a friend finds a gun', 'How to speak up to a trusted adult', 'Optional supervised intro to safe handling (older youth)' ],
		'modules' => [
			[ 't' => 'The Core Rule', 'd' => 'The simple, memorable safety response taught in a calm, non-frightening way.' ],
			[ 't' => 'Real Situations', 'd' => 'Friendly role-play of common scenarios so the right response becomes a habit.' ],
			[ 't' => 'Parent Partnership', 'd' => 'Guidance for parents on continuing the conversation and securing firearms at home.' ],
		],
		'audience'=> 'Families with children and teens. A parent or guardian must attend the full class.',
		'faq'     => [
			[ 'q' => 'What ages is this for?', 'a' => 'We tailor the class to the group  from young children through teens. Tell us your child\'s age when you reserve.' ],
			[ 'q' => 'Does my child handle a real firearm?', 'a' => 'Younger children do not. For older, parent-approved youth we may include supervised safe-handling  always your call.' ],
			[ 'q' => 'Do I have to stay?', 'a' => 'Yes  a parent or guardian attends the entire class. Youth safety is a partnership.' ],
		],
	],
];

$c = $courses[ $slug ] ?? $courses['basic-handgun'];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $c['faq'] ) );
}
?>
<style>
.cr-hero { padding: 140px 32px 72px; position:relative; overflow:hidden; }
.cr-hero::before { content:""; position:absolute; inset:0; pointer-events:none; background-image: linear-gradient(180deg, var(--hero-scrim), var(--hero-scrim-deep)), url('<?php echo esc_url( $c['image'] ); ?>'); background-size:cover; background-position:center; }
.cr-hero .c { position:relative; max-width:1180px; margin:0 auto; }
.cr-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--ink-on-media-faint); }
.cr-hero .crumbs a { color: var(--brass-on-media); text-decoration:none; }
.cr-hero h1 { font-family: var(--font-display); font-size: clamp(52px,8vw,116px); line-height:0.9; margin:14px 0 0; color: var(--ink-on-media); }
.cr-hero .lead { color: var(--ink-on-media-dim); max-width:60ch; margin:20px 0 0; font-size:16px; line-height:1.7; }
.cr-chips { display:flex; gap:10px; flex-wrap:wrap; margin-top:26px; }
.cr-chip { font-family: var(--font-mono); font-size:11px; letter-spacing:0.16em; text-transform:uppercase; color: var(--color-fog); border:1px solid var(--color-hairline-bright); padding:8px 12px; }
.cr-chip strong { color: var(--color-brass-bright); }
.cr-hero .ctas { display:flex; gap:12px; margin-top:28px; flex-wrap:wrap; }
.cr-sec { max-width:1180px; margin:0 auto; padding:72px 32px; }
.cr-2col { display:grid; grid-template-columns: 1.1fr 1fr; gap:48px; }
@media (max-width:880px){ .cr-2col { grid-template-columns:1fr; gap:32px; } }
.cr-sec h2 { font-family: var(--font-display); font-size: clamp(34px,5vw,60px); color: var(--color-white); letter-spacing:0.01em; line-height:1; }
.cr-sec p.body { color: var(--color-fog); font-size:15px; line-height:1.8; margin-top:16px; }
.cr-mod { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.cr-mods { display:grid; grid-template-columns: repeat(3,1fr); gap:14px; margin-top:30px; }
@media (max-width:820px){ .cr-mods { grid-template-columns:1fr; } }
.cr-mcard { padding:26px; background: var(--color-void); border:1px solid var(--color-hairline); }
.cr-mcard .t { font-family: var(--font-condensed); font-weight:600; font-size:18px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); }
.cr-mcard .d { color: var(--color-fog); font-size:14px; line-height:1.7; margin-top:8px; }
.cr-rel { display:grid; grid-template-columns: repeat(3,1fr); gap:12px; margin-top:26px; }
@media (max-width:760px){ .cr-rel { grid-template-columns:1fr; } }
.cr-rel a { display:block; padding:20px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); text-decoration:none; transition: border-color 200ms var(--ease-std); }
.cr-rel a:hover { border-color: var(--color-brass-dim); }
.cr-rel a .nm { font-family: var(--font-condensed); font-weight:600; font-size:16px; text-transform:uppercase; color: var(--color-white); }
.cr-rel a .mt { font-family: var(--font-mono); font-size:10px; letter-spacing:0.2em; color: var(--color-brass-bright); text-transform:uppercase; }
</style>

<header class="cr-hero hero-media">
  <div class="c">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/training/' ) ); ?>">Training</a>  <?php echo esc_html( $c['title'] ); ?></div>
    <span class="eyebrow" style="margin-top:14px;"><?php echo esc_html( $c['eyebrow'] ); ?></span>
    <h1><?php echo esc_html( $c['title'] ); ?></h1>
    <p class="lead"><?php echo esc_html( $c['lead'] ); ?></p>
    <div class="cr-chips">
      <span class="cr-chip">Tuition <strong><?php echo esc_html( $c['price'] ); ?></strong></span>
      <span class="cr-chip">Member <strong><?php echo esc_html( $c['member'] ); ?></strong></span>
      <span class="cr-chip">Duration <strong><?php echo esc_html( $c['duration'] ); ?></strong></span>
      <span class="cr-chip">Class Size <strong><?php echo esc_html( $c['size'] ); ?></strong></span>
      <span class="cr-chip">Prerequisite <strong><?php echo esc_html( $c['prereq'] ); ?></strong></span>
    </div>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="#reserve">Reserve Your Seat</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>">Save 25% As A Member</a>
    </div>
  </div>
</header>

<section class="cr-sec">
  <div class="cr-2col">
    <div>
      <span class="eyebrow" style="margin-bottom:14px;">Course Overview</span>
      <h2>WHAT THIS COURSE IS</h2>
      <p class="body"><?php echo esc_html( $c['overview'] ); ?></p>
      <p class="body"><strong style="color:var(--color-white);">Who it's for:</strong> <?php echo esc_html( $c['audience'] ); ?></p>
    </div>
    <div>
      <span class="eyebrow" style="margin-bottom:14px;">What You'll Learn</span>
      <h2>SKILLS YOU LEAVE WITH</h2>
      <ul class="bullets" style="margin-top:18px;">
        <?php foreach ( $c['learn'] as $l ) : ?><li><?php echo esc_html( $l ); ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>

<section class="cr-mod">
  <div class="cr-sec">
    <span class="eyebrow" style="margin-bottom:14px;">The Curriculum</span>
    <h2>HOW THE DAY RUNS</h2>
    <div class="cr-mods">
      <?php foreach ( $c['modules'] as $m ) : ?>
        <div class="cr-mcard"><div class="t"><?php echo esc_html( $m['t'] ); ?></div><div class="d"><?php echo esc_html( $m['d'] ); ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="cr-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
  <h2 style="margin-bottom:26px;"><?php echo esc_html( $c['title'] ); ?> FAQ</h2>
  <div class="acc">
    <?php foreach ( $c['faq'] as $i => $f ) : ?>
      <details<?php echo 0 === $i ? ' open' : ''; ?>>
        <summary><?php echo esc_html( $f['q'] ); ?></summary>
        <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="cr-mod">
  <div class="cr-sec">
    <span class="eyebrow" style="margin-bottom:14px;">Keep Building</span>
    <h2>RELATED TRAINING</h2>
    <div class="cr-rel">
      <a href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>"><span class="mt">Certification</span><div class="nm">Arizona CCW</div></a>
      <a href="<?php echo esc_url( home_url( '/training/defensive-pistol/' ) ); ?>"><span class="mt">Advanced</span><div class="nm">Defensive Pistol</div></a>
      <a href="<?php echo esc_url( home_url( '/private-instruction/' ) ); ?>"><span class="mt">1-on-1</span><div class="nm">Private Instruction</div></a>
    </div>
    <div style="margin-top:24px;">
      <a class="btn btn-ghost btn-arrow" href="<?php echo esc_url( home_url( '/training/' ) ); ?>">View Full Course Catalog</a>
    </div>
  </div>
</section>

<?php
get_template_part( 'template-parts/reservation-form', null, [
	'subject' => $c['title'] . ' Course',
	'heading' => 'RESERVE YOUR SEAT',
	'intro'   => 'Reserve a seat in ' . $c['title'] . '. Send your details and our team will confirm the next available date  usually within one business day.',
	'cta'     => 'Request This Course',
] );
get_footer();
