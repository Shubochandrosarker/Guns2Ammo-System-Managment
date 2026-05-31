<?php
/**
 * Template Name: Arsenal Weapon
 *
 * One template, one landing page per Signature Experience weapon platform.
 * Assign to a Page whose slug is one of: mp5, m16, ak-47
 * (recommended as children of /machine-gun/).
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$slug = '';
$qo   = get_queried_object();
if ( $qo && isset( $qo->post_name ) ) $slug = $qo->post_name;

$img = '/wp-content/uploads/';

$weapons = [
	'mp5' => [
		'tag'      => 'Submachine Gun  01',
		'title'    => 'MP5',
		'sub'      => 'The world\'s most iconic submachine gun',
		'lead'     => 'Roller-delayed, buttery-smooth, and instantly recognizable  the MP5 is the easiest full-auto platform to control and the perfect first machine gun.',
		'image'    => $img . '2025/06/IMG_1055.webp',
		'cartridge'=> '919mm Parabellum',
		'specs'    => [ 'Rate of Fire' => '~800 RPM', 'Magazine' => '25 Rounds', 'Action' => 'Roller-delayed blowback', 'Caliber' => '9mm', 'Origin' => 'Germany', 'From' => '$249' ],
		'story'    => 'Adopted by elite units and law-enforcement teams worldwide, the MP5 earned its reputation on control. Its roller-delayed action keeps recoil flat and predictable, so even a first-time shooter can hold a tight group on full auto. If you have ever seen a special-operations film, you have seen this gun.',
		'feel'     => 'Soft, smooth, and almost gentle for a machine gun. The 9mm cartridge means modest recoil  most first-timers are grinning before the first magazine is empty.',
		'includes' => [ 'One-on-one certified RSO instruction', 'Ammunition, eye and ear protection', 'Targets and certificate of completion', 'A FREE Arizona CCW class with every package' ],
		'faq'      => [
			[ 'q' => 'Is the MP5 good for a first-timer?', 'a' => 'It is the best choice for a first machine gun. Low recoil and a smooth action make it easy to control and genuinely fun from the first shot.' ],
			[ 'q' => 'Do I need any experience?', 'a' => 'None. A certified RSO is one-on-one with you the entire time and walks you through every step.' ],
			[ 'q' => 'Can I add other platforms?', 'a' => 'Yes  the Premium and Elite packages let you add the M16 and AK-47. Many guests shoot all three.' ],
		],
	],
	'm16' => [
		'tag'      => 'Rifle  02',
		'title'    => 'M16',
		'sub'      => 'The modern American service rifle',
		'lead'     => 'Light, fast, and accurate  the M16 is the rifle that defined a half-century of American service. Full-auto, direct-impingement, and a thrill to run.',
		'image'    => $img . '2025/06/noveske-gen4-556-sbr-bazooka-green-guns2ammo-mesa-az.webp',
		'cartridge'=> '5.5645mm NATO',
		'specs'    => [ 'Rate of Fire' => '~700 RPM', 'Magazine' => '30 Rounds', 'Action' => 'Direct impingement', 'Caliber' => '5.56 NATO', 'Origin' => 'United States', 'From' => '$329' ],
		'story'    => 'In service since the 1960s, the M16 is the rifle generations of American troops carried. Its lightweight design and high-velocity 5.56 cartridge make it flat-shooting and remarkably easy to handle on full auto  a piece of living history you get to fire yourself.',
		'feel'     => 'Snappy but light. The 5.56 round produces a sharp, fast report with very manageable muzzle rise  controllable bursts come quickly with RSO coaching.',
		'includes' => [ 'One-on-one certified RSO instruction', 'Ammunition, eye and ear protection', 'Targets and certificate of completion', 'A FREE Arizona CCW class with every package' ],
		'faq'      => [
			[ 'q' => 'How is the M16 different from an AR-15?', 'a' => 'Mechanically they are close cousins  the key difference is the M16\'s select-fire capability, which lets you experience true full-auto on our supervised range.' ],
			[ 'q' => 'Is the recoil hard to handle?', 'a' => 'No. The 5.56 cartridge is light-recoiling, and your RSO teaches burst control so you stay on target.' ],
			[ 'q' => 'Can beginners shoot it?', 'a' => 'Absolutely. No experience is required  every guest is coached one-on-one from the first round.' ],
		],
	],
	'ak-47' => [
		'tag'      => 'Rifle  03',
		'title'    => 'AK-47',
		'sub'      => 'The most recognizable rifle on earth',
		'lead'     => 'Rugged, thunderous, and unmistakable  the AK-47 delivers the raw, hard-hitting full-auto experience guests come back for.',
		'image'    => $img . '2025/03/IMG_20250317_085324.webp',
		'cartridge'=> '7.6239mm',
		'specs'    => [ 'Rate of Fire' => '~600 RPM', 'Magazine' => '30 Rounds', 'Action' => 'Long-stroke gas piston', 'Caliber' => '7.6239', 'Origin' => 'Russia', 'From' => '$349' ],
		'story'    => 'Designed in 1947, the AK-47 is the most produced and most recognizable rifle in history. Its long-stroke piston action is famously reliable, and its heavier 7.62 cartridge gives every burst a deep, authoritative punch you feel in your chest.',
		'feel'     => 'Loud and powerful  this is the big-bore full-auto experience. The 7.62 round has real recoil, but your RSO sets you up so it is exhilarating, never overwhelming.',
		'includes' => [ 'One-on-one certified RSO instruction', 'Ammunition, eye and ear protection', 'Targets and certificate of completion', 'A FREE Arizona CCW class with every package' ],
		'faq'      => [
			[ 'q' => 'Is the AK-47 too much for a beginner?', 'a' => 'Not with our coaching. The recoil is heavier than the MP5 or M16, but a one-on-one RSO sets your stance and grip so you stay in full control.' ],
			[ 'q' => 'Why does the AK feel so different?', 'a' => 'The larger 7.6239 cartridge delivers a deeper, harder-hitting recoil impulse  that authoritative punch is exactly why guests love it.' ],
			[ 'q' => 'Can I shoot it with the other platforms?', 'a' => 'Yes. The Premium and Elite packages combine the AK-47 with the MP5 and M16 for the full arsenal experience.' ],
		],
	],
];

$w = $weapons[ $slug ] ?? $weapons['mp5'];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $w['faq'] ) );
}
?>
<style>
.ar-hero { padding:140px 32px 72px; position:relative; overflow:hidden; }
.ar-hero::before { content:""; position:absolute; inset:0; pointer-events:none; background-image: linear-gradient(180deg, rgba(26,25,30,0.74), rgba(26,25,30,0.97)), url('<?php echo esc_url( $w['image'] ); ?>'); background-size:cover; background-position:center; }
.ar-hero::after { content:""; position:absolute; inset:0; pointer-events:none; background: radial-gradient(ellipse at 70% 40%, rgba(232,128,47,0.12), transparent 60%); }
.ar-hero .c { position:relative; max-width:1180px; margin:0 auto; }
.ar-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); }
.ar-hero .crumbs a { color: var(--color-brass-bright); text-decoration:none; }
.ar-hero h1 { font-family: var(--font-display); font-size: clamp(72px,14vw,200px); line-height:0.84; margin:10px 0 0; color: var(--color-white); }
.ar-hero .sub { font-family: var(--font-condensed); font-weight:500; letter-spacing:0.12em; text-transform:uppercase; color: var(--color-brass-bright); font-size:clamp(14px,2.4vw,20px); margin-top:10px; }
.ar-hero .lead { color: var(--color-fog); max-width:56ch; margin:20px 0 0; font-size:16px; line-height:1.7; }
.ar-hero .ctas { display:flex; gap:12px; margin-top:28px; flex-wrap:wrap; }
.ar-sec { max-width:1180px; margin:0 auto; padding:72px 32px; }
.ar-sec h2 { font-family: var(--font-display); font-size: clamp(34px,5vw,60px); color: var(--color-white); }
.ar-specs { display:grid; grid-template-columns: repeat(6,1fr); gap:1px; background: var(--color-hairline); border:1px solid var(--color-hairline); margin-top:30px; }
@media (max-width:780px){ .ar-specs { grid-template-columns:repeat(2,1fr); } }
.ar-specs .sp { background: var(--color-gunmetal); padding:22px 18px; }
.ar-specs .sp .v { font-family: var(--font-display); font-size:26px; color: var(--color-brass-bright); line-height:1; }
.ar-specs .sp .l { font-family: var(--font-mono); font-size:9px; letter-spacing:0.2em; color: var(--color-silver); text-transform:uppercase; margin-top:6px; }
.ar-2col { display:grid; grid-template-columns:1fr 1fr; gap:44px; }
@media (max-width:820px){ .ar-2col { grid-template-columns:1fr; gap:28px; } }
.ar-2col p { color: var(--color-fog); font-size:15px; line-height:1.8; margin-top:14px; }
.ar-mod { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.ar-ccw { background: linear-gradient(135deg, rgba(201,168,76,0.12), rgba(232,128,47,0.05)); border:1px solid var(--color-brass-dim); padding:36px; display:grid; grid-template-columns:1fr auto; gap:24px; align-items:center; margin-top:30px; }
@media (max-width:680px){ .ar-ccw { grid-template-columns:1fr; } }
.ar-ccw h3 { font-family: var(--font-display); font-size:32px; color: var(--color-white); }
.ar-ccw p { color: var(--color-fog); font-size:14px; margin:8px 0 0; }
.ar-rel { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-top:26px; }
@media (max-width:680px){ .ar-rel { grid-template-columns:1fr; } }
.ar-rel a { display:block; padding:20px; background: var(--color-void); border:1px solid var(--color-hairline); text-decoration:none; transition:border-color 200ms var(--ease-std); }
.ar-rel a:hover { border-color: var(--color-brass-dim); }
.ar-rel a .nm { font-family: var(--font-display); font-size:30px; color: var(--color-white); }
.ar-rel a .mt { font-family: var(--font-mono); font-size:10px; letter-spacing:0.2em; color: var(--color-brass-bright); text-transform:uppercase; }
</style>

<header class="ar-hero">
  <div class="c">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/machine-gun/' ) ); ?>">Signature Experience</a>  <?php echo esc_html( $w['title'] ); ?></div>
    <span class="eyebrow" style="margin-top:14px; color:var(--color-ember);"><?php echo esc_html( $w['tag'] ); ?></span>
    <h1><?php echo esc_html( $w['title'] ); ?></h1>
    <div class="sub"><?php echo esc_html( $w['sub'] ); ?></div>
    <p class="lead"><?php echo esc_html( $w['lead'] ); ?></p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="#reserve">Book This Experience</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/machine-gun/' ) ); ?>#tiers">View All Packages</a>
    </div>
  </div>
</header>

<section class="ar-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Platform Specs  <?php echo esc_html( $w['cartridge'] ); ?></span>
  <h2>BY THE NUMBERS</h2>
  <div class="ar-specs">
    <?php foreach ( $w['specs'] as $l => $v ) : ?>
      <div class="sp"><div class="v"><?php echo esc_html( $v ); ?></div><div class="l"><?php echo esc_html( $l ); ?></div></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="ar-mod">
  <div class="ar-sec">
    <div class="ar-2col">
      <div>
        <span class="eyebrow" style="margin-bottom:14px;">The Story</span>
        <h2>WHY IT'S A LEGEND</h2>
        <p><?php echo esc_html( $w['story'] ); ?></p>
      </div>
      <div>
        <span class="eyebrow" style="margin-bottom:14px;">On The Floor</span>
        <h2>WHAT IT FEELS LIKE</h2>
        <p><?php echo esc_html( $w['feel'] ); ?></p>
      </div>
    </div>
  </div>
</section>

<section class="ar-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Every Package Includes</span>
  <h2>WHAT YOU GET</h2>
  <ul class="bullets" style="margin-top:20px; max-width:60ch;">
    <?php foreach ( $w['includes'] as $inc ) : ?><li><?php echo esc_html( $inc ); ?></li><?php endforeach; ?>
  </ul>
  <div class="ar-ccw">
    <div>
      <h3>Includes A FREE Arizona CCW Class</h3>
      <p>A $75 value  same-day certificate, carry legally across 37+ reciprocal states.</p>
    </div>
    <a class="btn btn-brass btn-arrow" href="<?php echo esc_url( home_url( '/free-ccw-class/' ) ); ?>">Read CCW Details</a>
  </div>
</section>

<section class="ar-mod">
  <div class="ar-sec">
    <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
    <h2 style="margin-bottom:26px;"><?php echo esc_html( $w['title'] ); ?> FAQ</h2>
    <div class="acc">
      <?php foreach ( $w['faq'] as $i => $f ) : ?>
        <details<?php echo 0 === $i ? ' open' : ''; ?>>
          <summary><?php echo esc_html( $f['q'] ); ?></summary>
          <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="ar-sec">
  <span class="eyebrow" style="margin-bottom:14px;">The Rest Of The Arsenal</span>
  <h2>EXPLORE EVERY PLATFORM</h2>
  <div class="ar-rel">
    <a href="<?php echo esc_url( home_url( '/machine-gun/mp5/' ) ); ?>"><span class="mt">Submachine Gun</span><div class="nm">MP5</div></a>
    <a href="<?php echo esc_url( home_url( '/machine-gun/m16/' ) ); ?>"><span class="mt">Rifle</span><div class="nm">M16</div></a>
    <a href="<?php echo esc_url( home_url( '/machine-gun/ak-47/' ) ); ?>"><span class="mt">Rifle</span><div class="nm">AK-47</div></a>
  </div>
</section>

<?php
get_template_part( 'template-parts/reservation-form', null, [
	'subject' => $w['title'] . ' Machine Gun Experience',
	'heading' => 'BOOK THE ' . $w['title'] . ' EXPERIENCE',
	'intro'   => 'Reserve your ' . $w['title'] . ' experience. Send your details and our team will confirm a date  Signature Experiences book 46 weeks out, so reserve early.',
	'cta'     => 'Request This Experience',
] );
get_footer();
