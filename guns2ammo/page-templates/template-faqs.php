<?php
/**
 * Template Name: FAQs
 *
 * Renders the canonical FAQ list inside the branded theme shell.
 * Data lives in inc/faqs.php (g2a_faqs_data()) so the rendered
 * HTML and the FAQPage JSON-LD never drift apart.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$faqs = function_exists( 'g2a_faqs_data' ) ? g2a_faqs_data() : array();
?>
<style>
.g2a-faqs-hero { padding: 140px 32px 32px; text-align: center; position: relative; }
.g2a-faqs-hero h1 { font-family: var(--font-display); font-size: clamp(48px, 7vw, 80px); color: var(--color-white); letter-spacing: 0.02em; line-height: 0.95; margin: 0 auto; }
.g2a-faqs-hero p  { color: var(--color-fog); max-width: 56ch; margin: 18px auto 0; }
.g2a-faqs-wrap { max-width: 920px; margin: 0 auto; padding: 16px 24px 80px; }
.g2a-faqs-topic { margin: 36px 0 18px; }
.g2a-faqs-topic h2 { font-family: var(--font-condensed); font-weight: 600; font-size: 22px; color: var(--color-brass-bright); letter-spacing: 0.14em; text-transform: uppercase; margin: 0 0 14px; }
.g2a-faqs-item { background: var(--color-gunmetal); border: 1px solid var(--color-hairline); border-radius: 4px; margin: 0 0 10px; overflow: hidden; transition: border-color 220ms var(--ease-std); }
.g2a-faqs-item[open] { border-color: var(--color-brass-dim); }
.g2a-faqs-item > summary { list-style: none; cursor: pointer; padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; gap: 18px; color: var(--color-white); font-family: var(--font-ui); font-weight: 500; font-size: 16px; line-height: 1.4; }
.g2a-faqs-item > summary::-webkit-details-marker { display: none; }
.g2a-faqs-item > summary::after { content: ""; flex: 0 0 14px; width: 14px; height: 14px; border-right: 2px solid var(--color-brass-bright); border-bottom: 2px solid var(--color-brass-bright); transform: rotate(45deg) translate(-3px, -3px); transition: transform 220ms var(--ease-std); }
.g2a-faqs-item[open] > summary::after { transform: rotate(-135deg); }
.g2a-faqs-item .g2a-faqs-body { padding: 0 22px 18px; color: var(--color-fog); font-size: 15px; line-height: 1.65; animation: g2a-faqs-fade 240ms var(--ease-out, ease-out); }
@keyframes g2a-faqs-fade { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
.g2a-faqs-cta { background: rgba(201,168,76,0.06); border: 1px solid var(--color-brass-dim); padding: 24px 28px; margin-top: 32px; display: grid; grid-template-columns: 1fr auto; gap: 18px; align-items: center; }
.g2a-faqs-cta h3 { font-family: var(--font-condensed); font-weight: 600; font-size: 22px; color: var(--color-white); text-transform: uppercase; margin: 0 0 4px; letter-spacing: 0.04em; }
.g2a-faqs-cta p  { margin: 0; color: var(--color-fog); font-size: 14px; }
@media (max-width: 540px) {
  .g2a-faqs-cta { grid-template-columns: 1fr; }
  .g2a-faqs-cta .btn { width: 100%; text-align: center; }
}
</style>

<section class="g2a-faqs-hero">
  <span class="eyebrow" style="margin-bottom:18px; display:inline-block;">Frequently Asked Questions</span>
  <h1>HOW CAN WE HELP?</h1>
  <p>Range hours, membership pricing, firearm transfers, training classes, safety, online orders — the most common questions, answered. Can't find what you're looking for? Call us at <?php echo esc_html( get_theme_mod( 'g2a_phone', '(602) 715-2677' ) ); ?>.</p>
</section>

<div class="g2a-faqs-wrap">
  <?php foreach ( $faqs as $group ) : ?>
    <section class="g2a-faqs-topic">
      <h2><?php echo esc_html( $group['topic'] ); ?></h2>
      <?php foreach ( $group['items'] as $i => $it ) : ?>
        <details class="g2a-faqs-item"<?php echo $i === 0 ? ' open' : ''; ?>>
          <summary><?php echo esc_html( $it['q'] ); ?></summary>
          <div class="g2a-faqs-body"><?php echo wp_kses_post( wpautop( $it['a'] ) ); ?></div>
        </details>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <div class="g2a-faqs-cta">
    <div>
      <h3>Still have questions?</h3>
      <p>Call the range, send us a message, or stop in. Real people, same-day answers.</p>
    </div>
    <a class="btn btn-brass" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Contact Us</a>
  </div>
</div>

<?php get_footer();
