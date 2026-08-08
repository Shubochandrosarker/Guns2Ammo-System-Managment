<?php
/**
 * Reservation / request form partial.
 *
 * Usage:
 *   get_template_part( 'template-parts/reservation-form', null, [
 *       'subject'  => 'Basic Handgun Course',
 *       'heading'  => 'RESERVE YOUR SEAT',
 *       'intro'    => 'Tell us when you want to train',
 *       'cta'      => 'Request This Reservation',
 *       'packages' => [ 'Basic Package ($249)', 'Premium Package ($449)' ], // optional
 *   ] );
 *
 * `packages`, when passed, renders a "Package" select (posted as g2a_course,
 * the same field g2a_handle_reservation() already reads server-side) so one
 * shared form can offer a few tiers instead of every tier needing its own
 * page. Buttons elsewhere on the page can pre-select an option by pointing
 * href="#reserve" and adding data-g2a-package="<exact option text>" — see
 * assets/js/chrome.js.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rf_subject  = $args['subject'] ?? 'Reservation Request';
$rf_heading  = $args['heading'] ?? 'RESERVE YOUR SEAT';
$rf_intro    = $args['intro'] ?? 'Send your details and our team will confirm availability by phone or email  usually within one business day.';
$rf_cta      = $args['cta'] ?? 'Request Reservation';
$rf_packages = ! empty( $args['packages'] ) && is_array( $args['packages'] ) ? array_values( array_filter( $args['packages'] ) ) : [];
$rf_sent     = isset( $_GET['g2a_sent'] );
// Single source of truth for NAP — see inc/business-info.php.
$rf_biz      = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
?>
<section class="g2a-resv" id="reserve">
  <style>
  .g2a-resv { max-width:880px; margin:0 auto; padding:80px 32px; }
  .g2a-resv .rf-card { background: var(--color-gunmetal); border:1px solid var(--color-brass-dim); padding:40px; }
  @media (max-width:560px){ .g2a-resv .rf-card { padding:26px; } }
  .g2a-resv h2 { font-family: var(--font-display); font-size: clamp(34px,5vw,56px); color: var(--color-white); letter-spacing:0.02em; line-height:1; }
  .g2a-resv .rf-intro { color: var(--color-fog); margin:14px 0 26px; font-size:15px; line-height:1.7; max-width:60ch; }
  .g2a-resv .rf-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  @media (max-width:560px){ .g2a-resv .rf-grid { grid-template-columns:1fr; } }
  .g2a-resv .rf-full { grid-column:1 / -1; }
  .g2a-resv .rf-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
  </style>
  <div class="rf-card">
    <span class="eyebrow" style="margin-bottom:14px;">Reservations</span>
    <h2><?php echo esc_html( $rf_heading ); ?></h2>
    <?php if ( $rf_sent ) : ?>
      <div class="alert success" style="margin-top:22px;">
        <span class="ic"></span>
        <div><div class="h">Request Received</div>Thanks  your reservation request is in. Our team will reach out to confirm availability shortly. For anything urgent, call <?php echo str_replace( ' ', '&nbsp;', esc_html( trim( $rf_biz['phone'] ?? '(602) 715-2677' ) ) ); ?>.</div>
      </div>
    <?php else : ?>
      <p class="rf-intro"><?php echo esc_html( $rf_intro ); ?></p>
      <form method="post" action="<?php echo esc_url( g2a_form_action_url() ); ?>">
        <input type="hidden" name="action" value="g2a_reservation">
        <input type="hidden" name="g2a_subject" value="<?php echo esc_attr( $rf_subject ); ?>">
        <?php wp_nonce_field( 'g2a_reservation', 'g2a_nonce' ); ?>
        <div class="rf-hp" aria-hidden="true"><label>Leave this empty<input type="text" name="g2a_hp" tabindex="-1" autocomplete="off"></label></div>
        <div class="rf-grid">
          <?php if ( $rf_packages ) : ?>
          <div class="rf-full">
            <label class="form-label" for="rf-package">Package</label>
            <select class="field" id="rf-package" name="g2a_course">
              <?php foreach ( $rf_packages as $pkg ) : ?>
                <option><?php echo esc_html( $pkg ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div>
            <label class="form-label" for="rf-name">Full Name</label>
            <input class="field" id="rf-name" name="g2a_name" required placeholder="Joseph Hartwell">
          </div>
          <div>
            <label class="form-label" for="rf-email">Email Address</label>
            <input class="field" id="rf-email" name="g2a_email" type="email" required placeholder="you@example.com">
          </div>
          <div>
            <label class="form-label" for="rf-phone">Phone Number</label>
            <input class="field" id="rf-phone" name="g2a_phone" required placeholder="(480) 555-0199">
          </div>
          <div>
            <label class="form-label" for="rf-date">Preferred Date</label>
            <input class="field" id="rf-date" name="g2a_date" type="date">
          </div>
          <div>
            <label class="form-label" for="rf-count">Participants</label>
            <select class="field" id="rf-count" name="g2a_count">
              <option>1 person</option><option>2 people</option><option>3 people</option>
              <option>4 people</option><option>5+ / group</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="rf-exp">Experience Level</label>
            <select class="field" id="rf-exp" name="g2a_experience">
              <option>First time</option><option>Beginner</option><option>Intermediate</option>
              <option>Advanced</option><option>Law Enforcement / Military</option>
            </select>
          </div>
          <div class="rf-full">
            <label class="form-label" for="rf-notes">Anything We Should Know?</label>
            <textarea class="field" id="rf-notes" name="g2a_notes" rows="3" placeholder="Questions, accessibility needs, or who you're booking for."></textarea>
          </div>
        </div>
        <div style="margin-top:22px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
          <button type="submit" class="btn btn-ember btn-arrow"><?php echo esc_html( $rf_cta ); ?></button>
          <span class="form-help">No charge until confirmed  Free cancellation</span>
        </div>
      </form>
    <?php endif; ?>
  </div>
</section>
