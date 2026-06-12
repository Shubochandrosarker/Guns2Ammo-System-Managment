<?php
/**
 * Template Name: About
 *
 * Source: design/about.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>.hero { padding: 160px 32px 80px; min-height: 70vh; display:flex; align-items:center; position:relative; overflow:hidden; }
.hero .c { max-width:1280px; margin:0 auto; }
.hero h1 { font-family: var(--font-display); font-size: clamp(64px, 11vw, 180px); line-height: 0.88; max-width: 14ch; }
.hero h1 .a { color: var(--color-brass-bright); }
.hero p { color: var(--color-fog); max-width: 56ch; margin: 28px 0 0; font-size: 18px; line-height: 1.7; }
.hero::after { content:""; position:absolute; right:-10vw; top:20%; width:60vw; height: 60vw; max-width: 700px; max-height:700px; background: radial-gradient(circle, rgba(201,168,76,0.12), transparent 60%); pointer-events:none; }

.philosophy { padding: 100px 32px; background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.philosophy .c { max-width: 980px; margin:0 auto; text-align:center; }
.philosophy blockquote { font-family: var(--font-display); font-size: clamp(32px, 4.5vw, 56px); color: var(--color-white); line-height:1.1; margin:0; padding-top: 18px; }
.philosophy blockquote em { color: var(--color-brass-bright); font-style: normal; }
.philosophy .sig { margin-top:32px; font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase; }

.creds { padding: 100px 32px; }
.creds .c { max-width:1280px; margin:0 auto; }
.creds h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); margin: 18px 0 48px; line-height:0.95; }
.cred-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
@media(max-width:900px){ .cred-grid { grid-template-columns: 1fr 1fr; } }
@media(max-width:540px){ .cred-grid { grid-template-columns: 1fr; } }
.cred { padding: 28px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); position:relative; transition: border-color 200ms; }
.cred:hover { border-color: var(--color-brass-dim); }
.cred .ic { width: 40px; height: 40px; border: 1.5px solid var(--color-brass); color: var(--color-brass-bright); display:grid; place-items:center; font-family: var(--font-display); font-size: 22px; transform: rotate(45deg); margin-bottom: 22px; }
.cred .ic span { transform: rotate(-45deg); }
.cred h4 { font-family: var(--font-display); font-size: 28px; color: var(--color-white); margin:0 0 10px; line-height:1; }
.cred p { color: var(--color-fog); font-size: 14px; line-height:1.65; margin:0; }
.cred .meta { margin-top:18px; padding-top:14px; border-top:1px solid var(--color-hairline); font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-brass-bright); text-transform: uppercase; }

.team { padding: 100px 32px; background: var(--color-void); }
.team .c { max-width:1280px; margin:0 auto; }
.team h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); margin: 18px 0 48px; line-height:0.95; }
.team-grid { display:grid; grid-template-columns: repeat(4,1fr); gap: 12px; }
@media(max-width:900px){ .team-grid { grid-template-columns: 1fr 1fr; } }
.member { background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.member .photo { aspect-ratio: 4/5; background-image: linear-gradient(180deg, rgba(26,25,30,0.4), rgba(26,25,30,0.85)), repeating-linear-gradient(135deg, #1f1f23 0 14px, #18181b 14px 28px); position:relative; }
.member .photo::after { content: attr(data-pl); position:absolute; bottom:14px; left:14px; font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-silver); text-transform: uppercase; }
.member .body { padding: 18px; }
.member .role { font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; color: var(--color-brass-bright); text-transform: uppercase; }
.member h4 { font-family: var(--font-display); font-size: 28px; color: var(--color-white); line-height:1; margin: 6px 0 8px; }
.member p { color: var(--color-fog); font-size: 13px; line-height:1.5; margin:0; }

.tour { padding: 100px 32px; }
.tour .c { max-width:1280px; margin:0 auto; }
.tour h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); margin: 18px 0 48px; line-height:0.95; }
.tour-grid { display:grid; grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap:8px; height: 600px; }
@media(max-width:900px){ .tour-grid { grid-template-columns: 1fr 1fr; grid-template-rows: 200px 200px 200px; height:auto; } .tour-grid .photo:nth-child(1) { grid-column: span 2; } }
.photo { background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.7)), repeating-linear-gradient(135deg, #1f1f23 0 14px, #18181b 14px 28px); position:relative; overflow:hidden; }
.photo .cap { position:absolute; bottom:14px; left:14px; right:14px; font-family: var(--font-condensed); font-weight:600; letter-spacing:0.06em; text-transform: uppercase; color: var(--color-white); font-size:14px; }
.photo .cap small { font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-brass-bright); display:block; margin-bottom:4px; }
.tour-grid .photo:nth-child(1) { grid-row: span 2; }

.location { padding: 100px 32px; background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); }
.location .c { max-width:1280px; margin:0 auto; display:grid; grid-template-columns: 1.1fr 1fr; gap: 48px; align-items:start; }
@media(max-width:900px){ .location .c { grid-template-columns:1fr; } }
.location h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); line-height:0.95; margin: 18px 0 22px; }
.location p { color: var(--color-fog); margin: 0 0 32px; max-width: 50ch; }
.contact-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.contact-card { padding: 24px; background: var(--color-void); border:1px solid var(--color-hairline); }
.contact-card .label { font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase; margin-bottom:8px; }
.contact-card .val { font-family: var(--font-condensed); font-weight:600; font-size:18px; letter-spacing:0.04em; color: var(--color-white); }
.contact-card .val a { color: var(--color-brass-bright); text-decoration:none; }
.map { aspect-ratio: 4/3; background:
  linear-gradient(rgba(26,25,30,0.4), rgba(26,25,30,0.4)),
  repeating-linear-gradient(0deg, rgba(201,168,76,0.06) 0 1px, transparent 1px 60px),
  repeating-linear-gradient(90deg, rgba(201,168,76,0.06) 0 1px, transparent 1px 60px),
  #0d0d0f;
  border:1px solid var(--color-hairline); position:relative; }
.map .pin { position:absolute; left: 50%; top: 50%; transform: translate(-50%,-100%); display:grid; place-items:center; gap:6px; }
.map .pin .dot { width:14px; height:14px; background: var(--color-ember); border:2px solid var(--color-white); border-radius:50%; box-shadow: 0 0 0 0 rgba(232,128,47,0.7); animation: pulse 1.6s var(--ease-out) infinite; }
.map .pin .lbl { font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-white); background: var(--color-void); border:1px solid var(--color-hairline-bright); padding: 4px 8px; text-transform: uppercase; white-space: nowrap; }
.map .city { position:absolute; font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase; }

.cta-final { padding: 100px 32px; text-align:center; background: var(--color-void); border-top:1px solid var(--color-hairline); }
.cta-final h2 { font-family: var(--font-display); font-size: clamp(48px, 8vw, 112px); line-height:0.92; }</style>
<header class="hero">
  <div class="c">
    <span class="eyebrow"> Since 2014  Mesa, AZ</span>
    <h1 style="margin-top: 22px;">MORE THAN<br>A RANGE.<br>A TRAINING <span class="a">INSTITUTION.</span></h1>
    <p><?php echo g2a_content( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html applied inside g2a_content().
      'about_story',
      "What started as one couple's range  opened with a simple promise that every shooter, beginner or veteran, would be treated with the same respect  has grown into Mesa's premier indoor facility. We've trained 5,000+ students, served church security teams, certified law enforcement, and helped first-time buyers walk out confident.",
      'text',
      'About — Story paragraph'
    ); ?></p>
  </div>
</header>

<section class="philosophy">
  <div class="c">
    <span class="eyebrow" style="justify-content:center;"> Our Philosophy</span>
    <blockquote>"A safe shooter is a <em>disciplined</em> shooter. A disciplined shooter is a <em>trained</em> shooter. We are not in the business of selling guns. We are in the business of <em>training people</em> to handle them correctly  for life."</blockquote>
    <div class="sig"> The Guns 2 Ammo Team</div>
  </div>
</section>

<section class="creds">
  <div class="c">
    <span class="eyebrow"> Credentials</span>
    <h2>EARNED.<br>NOT CLAIMED.</h2>
    <div class="cred-grid">
      <div class="cred">
        <div class="ic"><span></span></div>
        <h4>NRA CERTIFIED</h4>
        <p>Every instructor holds NRA certification, backed by military and law-enforcement experience.</p>
        <div class="meta">National Rifle Association</div>
      </div>
      <div class="cred">
        <div class="ic"><span></span></div>
        <h4>FFL LICENSED</h4>
        <p>Federal Firearms License since 2014. Full retail, transfer, and NFA capability on-site.</p>
        <div class="meta">ATF  9-86-013-00-0J</div>
      </div>
      <div class="cred">
        <div class="ic"><span></span></div>
        <h4>RSO ON DUTY</h4>
        <p>A Range Safety Officer is present whenever the range is hot. No exceptions, ever.</p>
        <div class="meta">Mon  Fri / Sat  Sun</div>
      </div>
      <div class="cred">
        <div class="ic"><span></span></div>
        <h4>$2M INSURED</h4>
        <p>Liability coverage well above industry minimums. Your safety  and ours  is engineered.</p>
        <div class="meta">Lloyd's of London</div>
      </div>
      <div class="cred">
        <div class="ic"><span></span></div>
        <h4>12+ YEARS</h4>
        <p>Operating in Mesa since 2014. Same family. Same building. Same standard.</p>
        <div class="meta">Est. October 2014</div>
      </div>
      <div class="cred">
        <div class="ic"><span></span></div>
        <h4>5,000+ STUDENTS</h4>
        <p>From first-timers to law enforcement to church security teams  trained and certified.</p>
        <div class="meta">Cumulative  Counting</div>
      </div>
    </div>
  </div>
</section>

<section class="team">
  <div class="c">
    <span class="eyebrow"> The Team</span>
    <h2>OPERATORS,<br>TEACHERS, FAMILY.</h2>
    <p style="color: var(--color-fog); max-width:64ch; margin: 0 0 32px;">Decades of combined experience from law enforcement, military, and competitive shooting. The team you train with is the team that owns the building.</p>
    <?php
    /* Real roster — same single source as /training/ (inc/instructors.php).
       The fabricated Halsey/Cruz/Okafor grid conflicted with the training
       page's equally fabricated Mendoza/Kelly/Tran roster. */
    $g2a_team = function_exists( 'g2a_instructors' ) ? g2a_instructors() : array();
    ?>
    <div class="team-grid" data-reveal-stagger>
      <?php foreach ( $g2a_team as $g2a_member ) : ?>
        <div class="member g2a-lift">
          <?php
          /* Per-member editable headshot slot (Appearance → Site Content).
             Priority: roster photo from inc/instructors.php → client
             override → the original data-pl placeholder. No stock default
             here on purpose: a range photo is not a headshot. */
          $g2a_member_photo = ! empty( $g2a_member['photo'] ) ? $g2a_member['photo'] : '';
          $g2a_member_slot  = g2a_content(
            'about_team_photo_' . sanitize_title( $g2a_member['name'] ),
            '',
            'image',
            'About — Team photo: ' . $g2a_member['name']
          );
          if ( ! $g2a_member_photo && $g2a_member_slot ) {
            $g2a_member_photo = $g2a_member_slot;
          }
          ?>
          <?php if ( $g2a_member_photo ) : ?>
            <div class="photo" style="background-image:url('<?php echo esc_url( $g2a_member_photo ); ?>');background-size:cover;background-position:center;"></div>
          <?php else : ?>
            <div class="photo" data-pl="<?php echo esc_attr( strtoupper( $g2a_member['name'] ) ); ?>"></div>
          <?php endif; ?>
          <div class="body">
            <div class="role"><?php echo esc_html( $g2a_member['role'] ); ?></div>
            <h4><?php echo esc_html( strtoupper( $g2a_member['name'] ) ); ?></h4>
            <p><?php echo esc_html( implode( ' · ', array_slice( (array) ( $g2a_member['creds'] ?? array() ), 0, 3 ) ) ); ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="tour">
  <div class="c">
    <span class="eyebrow"> Facility</span>
    <h2>BUILT FOR PRECISION.</h2>
    <p style="color: var(--color-fog); max-width:64ch; margin: 0 0 32px;">Six 25-yard climate-controlled lanes. Smart ventilation rated for 12 air changes per hour. A retail floor stocked like a working operator's closet, not a sporting goods aisle.</p>
    <?php
    /* Facility tour — real brand photos, each one editable from
       Appearance → Site Content. Clearing a URL gracefully falls back to
       the original striped placeholder defined in the .photo CSS above. */
    $g2a_tour_slots = array(
      array( 'about_tour_01', g2a_asset( 'img/gallery/guns2ammo-range-lanes-mesa.jpg' ),  'About — Tour photo 01 (The Line)',  '01  The Line',  'Six 25-yard climate-controlled lanes' ),
      array( 'about_tour_02', g2a_asset( 'img/gallery/guns2ammo-handgun-counter.jpg' ),   'About — Tour photo 02 (Retail)',    '02  Retail',    'Curated handguns &amp; rifles' ),
      array( 'about_tour_03', g2a_asset( 'img/gallery/guns2ammo-training-class.jpg' ),    'About — Tour photo 03 (Classroom)', '03  Classroom', 'CCW &amp; safety courses' ),
      array( 'about_tour_04', g2a_asset( 'img/gallery/guns2ammo-rifle-wall.jpg' ),        'About — Tour photo 04 (Armory)',    '04  Armory',    'The rental machine guns' ),
      array( 'about_tour_05', g2a_asset( 'img/gallery/guns2ammo-retail-floor.jpg' ),      'About — Tour photo 05 (Lounge)',    '05  Lounge',    'Members &amp; guests' ),
    );
    ?>
    <div class="tour-grid">
      <?php foreach ( $g2a_tour_slots as $g2a_slot ) :
        list( $g2a_slot_key, $g2a_slot_default, $g2a_slot_label, $g2a_slot_small, $g2a_slot_cap ) = $g2a_slot;
        $g2a_slot_url = g2a_content( $g2a_slot_key, $g2a_slot_default, 'image', $g2a_slot_label );
      ?>
      <div class="photo"<?php if ( $g2a_slot_url ) : ?> style="background-image:linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.75)), url('<?php echo esc_url( $g2a_slot_url ); ?>');background-size:cover;background-position:center;"<?php endif; ?>><div class="cap"><small><?php echo esc_html( $g2a_slot_small ); ?></small><?php echo wp_kses_post( $g2a_slot_cap ); ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="location" id="contact">
  <div class="c">
    <div>
      <span class="eyebrow"> Visit  Contact</span>
      <h2>SERVING MESA,<br>GILBERT,<br>CHANDLER &amp;<br>APACHE JUNCTION.</h2>
      <p>Drop in any time during business hours. RSO is on duty. Coffee's hot.</p>
      <div class="contact-grid">
        <div class="contact-card">
          <div class="label">Address</div>
          <div class="val">6030 E Main St<br>Mesa, AZ 85205</div>
        </div>
        <div class="contact-card">
          <div class="label">Phone</div>
          <div class="val"><a href="tel:+16027152677">(602) 715-2677</a></div>
        </div>
        <div class="contact-card">
          <div class="label">Hours  Weekdays</div>
          <div class="val">Mon  Fri  10a  8p</div>
        </div>
        <div class="contact-card">
          <div class="label">Hours  Weekend</div>
          <div class="val">Sat 9a8p  Sun 11a6p</div>
        </div>
        <div class="contact-card">
          <div class="label">Email</div>
          <div class="val"><a href="mailto:sales@guns2ammo.com">sales@guns2ammo.com</a></div>
        </div>
        <div class="contact-card">
          <div class="label">Range Status</div>
          <div class="val" id="g2a-about-lanes"><span class="dot-live" style="margin-right:8px;"></span><span id="g2a-about-lanes-label">6 lanes</span></div>
          <script>
          (function(){
            fetch('<?php echo esc_url_raw( rest_url( 'g2a-booking/v1/lanes-status' ) ); ?>', { cache: 'no-store', headers: { 'Accept': 'application/json' } })
              .then(function(r){ return r.json(); })
              .then(function(j){
                if (!j || !j.success || !j.data || !j.data.total) return;
                var lb = document.getElementById('g2a-about-lanes-label');
                if (lb) lb.textContent = j.data.label;
              }).catch(function(){});
          })();
          </script>
        </div>
      </div>
      <div style="display:flex; gap:14px; margin-top: 32px; flex-wrap:wrap;">
        <a class="btn btn-ember" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane </a>
        <a class="btn btn-brass" href="tel:+16027152677">Call Us</a>
      </div>
    </div>
    <div class="map">
      <span class="city" style="top: 20%; left: 15%;">Apache Junction</span>
      <span class="city" style="top: 70%; left: 22%;">Gilbert</span>
      <span class="city" style="top: 78%; right: 18%;">Chandler</span>
      <span class="city" style="top: 28%; right: 20%;">Tempe</span>
      <div class="pin"><div class="lbl">G2A  6030 E MAIN ST</div><div class="dot"></div></div>
    </div>
  </div>
</section>

<section class="cta-final">
  <span class="eyebrow" style="justify-content:center;"> Ready When You Are</span>
  <h2 style="margin-top: 18px;">VISIT THE RANGE.</h2>
  <p style="color: var(--color-fog); max-width: 500px; margin: 22px auto 32px;">Come see for yourself why Mesa's been training with us since 2014.</p>
  <div style="display:inline-flex; gap:14px; flex-wrap:wrap;">
    <a class="btn btn-ember btn-lg" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane </a>
    <a class="btn btn-brass btn-lg" href="<?php echo esc_url( home_url( "/contact/" ) ); ?>">Get Directions</a>
  </div>
</section>
<?php get_footer();
