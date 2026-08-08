<?php
/**
 * Blog index, category, tag and search archive  Knowledge Hub layout with sidebar.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

if ( is_search() ) {
	$kh_title = sprintf( 'Search: %s', get_search_query() );
	$kh_intro = 'Articles and guides matching your search.';
} elseif ( is_category() || is_tag() || is_tax() ) {
	$kh_title = single_term_title( '', false );
	$kh_intro = trim( wp_strip_all_tags( term_description() ) );
	if ( '' === $kh_intro ) $kh_intro = 'Guides and articles from the Guns 2 Ammo Knowledge Hub, written by our NRA-certified instructors in Mesa, Arizona.';
} else {
	$kh_title = 'The Knowledge Hub';
	$kh_intro = 'Guides, tips, and straight talk on firearms, carry laws, range safety, and training  written by our NRA-certified instructors in Mesa, Arizona.';
}
?>
<style>
.kh-x-hero { padding:140px 32px 48px; background: radial-gradient(ellipse at 30% 40%, rgba(201,168,76,0.09), transparent 60%), linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.kh-x-hero .c { max-width:1280px; margin:0 auto; }
.kh-x-hero h1 { font-family: var(--font-display); font-size: clamp(44px,7vw,92px); line-height:0.92; color: var(--color-white); margin:14px 0 0; }
.kh-x-hero .sub { color: var(--color-fog); max-width:64ch; margin:16px 0 0; font-size:15px; line-height:1.7; }
.kh-x-cats { display:flex; gap:8px; flex-wrap:wrap; margin-top:24px; }
.kh-x-cats a { font-family: var(--font-condensed); font-weight:600; font-size:12px; letter-spacing:0.14em; text-transform:uppercase; color: var(--color-fog); border:1px solid var(--color-hairline-bright); padding:8px 14px; border-radius:999px; text-decoration:none; transition: all 180ms var(--ease-std); }
.kh-x-cats a:hover, .kh-x-cats a.cur { background: var(--color-brass); color:#111; border-color: var(--color-brass); }
.kh-x-wrap { max-width:1280px; margin:0 auto; padding:56px 32px 96px; display:grid; grid-template-columns: 1fr 320px; gap:48px; align-items:start; }
@media (max-width:980px){ .kh-x-wrap { grid-template-columns:1fr; } }
.kh-x-list { display:grid; gap:20px; }
.kh-x-card { display:grid; grid-template-columns: 260px 1fr; background: var(--color-gunmetal); border:1px solid var(--color-hairline); overflow:hidden; transition: border-color 240ms var(--ease-std), transform 240ms var(--ease-std); }
.kh-x-card:hover { border-color: var(--color-brass-dim); transform: translateY(-3px); }
@media (max-width:560px){ .kh-x-card { grid-template-columns:1fr; } }
.kh-x-card .thumb { aspect-ratio:4/3; background-size:cover; background-position:center; background-image: repeating-linear-gradient(135deg,#2a2a30 0 14px,#232329 14px 28px); }
@media (max-width:560px){ .kh-x-card .thumb { aspect-ratio:16/9; } }
.kh-x-card .bd { padding:24px; }
.kh-x-card .ct { font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-brass-bright); text-transform:uppercase; }
.kh-x-card h2 { font-family: var(--font-condensed); font-weight:600; font-size:22px; line-height:1.25; color: var(--color-white); text-transform:uppercase; letter-spacing:0.02em; margin:8px 0 10px; }
.kh-x-card h2 a { color:inherit; text-decoration:none; }
.kh-x-card:hover h2 a { color: var(--color-brass-bright); }
.kh-x-card p { color: var(--color-fog); font-size:14px; line-height:1.6; margin:0 0 14px; }
.kh-x-card .meta { font-family: var(--font-mono); font-size:10px; letter-spacing:0.18em; color: var(--color-silver); text-transform:uppercase; }
.kh-x-empty { background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding:48px; text-align:center; color: var(--color-fog); }
.kh-x-pag { margin-top:36px; }
.kh-x-pag .page-numbers { display:inline-block; padding:10px 14px; border:1px solid var(--color-hairline-bright); color: var(--color-fog); text-decoration:none; font-family: var(--font-mono); font-size:12px; letter-spacing:0.12em; margin-right:6px; }
.kh-x-pag .page-numbers.current { background: var(--color-brass); color:#111; border-color: var(--color-brass); }
</style>

<header class="kh-x-hero">
  <div class="c">
    <span class="eyebrow">Firearms Knowledge Hub</span>
    <h1><?php echo esc_html( $kh_title ); ?></h1>
    <p class="sub"><?php echo esc_html( $kh_intro ); ?></p>
    <?php
    $kh_cats = get_categories( [ 'hide_empty' => false, 'number' => 10 ] );
    if ( $kh_cats ) : ?>
    <nav class="kh-x-cats" aria-label="Categories">
      <a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>"<?php echo ( ! is_category() && ! is_tag() ) ? ' class="cur"' : ''; ?>>All Topics</a>
      <?php foreach ( $kh_cats as $kc ) : ?>
        <a href="<?php echo esc_url( get_category_link( $kc ) ); ?>"<?php echo ( is_category( $kc->term_id ) ? ' class="cur"' : '' ); ?>><?php echo esc_html( $kc->name ); ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>
</header>

<div class="kh-x-wrap">
  <main class="kh-x-list">
    <?php if ( have_posts() ) : ?>
      <?php while ( have_posts() ) : the_post();
        $cats = get_the_category();
        $thumb = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'large' ) : '';
      ?>
        <article class="kh-x-card">
          <a class="thumb" href="<?php the_permalink(); ?>" aria-label="<?php the_title_attribute(); ?>"<?php echo $thumb ? ' style="background-image:linear-gradient(180deg,rgba(26,25,30,0.15),rgba(26,25,30,0.55)),url(\'' . esc_url( $thumb ) . '\');"' : ''; ?>></a>
          <div class="bd">
            <div class="ct"><?php echo $cats ? esc_html( $cats[0]->name ) : 'Article'; ?>  <?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></div>
            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <p><?php echo esc_html( wp_trim_words( get_the_excerpt(), 26 ) ); ?></p>
            <div class="meta"><?php echo esc_html( get_the_author() ); ?></div>
          </div>
        </article>
      <?php endwhile; ?>
      <div class="kh-x-pag"><?php the_posts_pagination( [ 'mid_size' => 2, 'prev_text' => ' Newer', 'next_text' => 'Older ' ] ); ?></div>
    <?php else : ?>
      <div class="kh-x-empty">
        <h2 style="font-family:var(--font-display);font-size:36px;color:var(--color-white);">Nothing here yet</h2>
        <p style="margin-top:10px;">No articles match this view. Try another topic or head back to the <a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>" style="color:var(--color-brass-bright);">Knowledge Hub</a>.</p>
      </div>
    <?php endif; ?>
  </main>
  <?php get_template_part( 'template-parts/blog-sidebar' ); ?>
</div>

<?php get_footer();
