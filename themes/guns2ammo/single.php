<?php
/**
 * Single blog post  article + Knowledge Hub sidebar.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>
.sp-hero { padding:130px 32px 0; background: linear-gradient(180deg, var(--color-surface-2), var(--color-void)); }
.sp-hero .c { max-width:1280px; margin:0 auto; }
.sp-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.18em; text-transform:uppercase; color: var(--color-silver); }
.sp-hero .crumbs a { color: var(--color-brass-bright); text-decoration:none; }
.sp-wrap { max-width:1280px; margin:0 auto; padding:40px 32px 96px; display:grid; grid-template-columns: 1fr 320px; gap:48px; align-items:start; }
@media (max-width:980px){ .sp-wrap { grid-template-columns:1fr; } }
.sp-article .chips { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
.sp-article h1 { font-family: var(--font-display); font-size: clamp(40px,5.6vw,76px); line-height:0.95; color: var(--color-white); letter-spacing:0.01em; }
.sp-article .byline { font-family: var(--font-mono); font-size:11px; letter-spacing:0.18em; text-transform:uppercase; color: var(--color-silver); margin-top:16px; display:flex; gap:14px; flex-wrap:wrap; }
.sp-article .feat { margin:28px 0 8px; border:1px solid var(--color-brass-dim); padding:8px; background: var(--color-gunmetal); }
.sp-article .feat img { display:block; width:100%; height:auto; }
.g2a-prose { color: var(--color-fog); font-size:17px; line-height:1.85; margin-top:28px; }
.g2a-prose p { margin:0 0 20px; }
.g2a-prose h2 { font-family: var(--font-display); font-size:clamp(28px,3.4vw,40px); color: var(--color-white); letter-spacing:0.02em; margin:36px 0 14px; }
.g2a-prose h3 { font-family: var(--font-condensed); font-weight:600; font-size:22px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); margin:28px 0 12px; }
.g2a-prose a { color: var(--color-brass-bright); }
.g2a-prose ul, .g2a-prose ol { margin:0 0 20px; padding-left:22px; }
.g2a-prose li { margin:0 0 8px; }
.g2a-prose blockquote { border-left:3px solid var(--color-brass); margin:24px 0; padding:6px 0 6px 22px; color: var(--color-white); font-family: var(--font-condensed); font-size:20px; }
.g2a-prose img { height:auto; border:1px solid var(--color-hairline); }
.sp-promo { margin:36px 0; background: linear-gradient(135deg, rgba(201,168,76,0.16), rgba(232,128,47,0.06)); border:1px solid var(--color-brass-dim); padding:30px; display:grid; grid-template-columns:1fr auto; gap:20px; align-items:center; }
@media (max-width:600px){ .sp-promo { grid-template-columns:1fr; } }
.sp-promo h3 { font-family: var(--font-display); font-size:30px; color: var(--color-white); letter-spacing:0.02em; }
.sp-promo p { color: var(--color-fog); font-size:14px; margin:6px 0 0; }
.sp-foot { margin-top:40px; padding-top:28px; border-top:1px solid var(--color-hairline); }
.sp-tags { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px; }
.sp-nav { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; }
.sp-nav a { color: var(--color-fog); text-decoration:none; font-family: var(--font-condensed); font-weight:600; letter-spacing:0.04em; text-transform:uppercase; font-size:14px; }
.sp-nav a:hover { color: var(--color-brass-bright); }
.sp-author { display:flex; gap:16px; align-items:center; background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding:22px; margin-top:24px; }
.sp-author .av { width:54px; height:54px; border-radius:50%; background: var(--color-brass); color:#111; display:grid; place-items:center; font-family: var(--font-display); font-size:22px; flex:0 0 auto; }
.sp-author .nm { font-family: var(--font-condensed); font-weight:600; font-size:16px; color: var(--color-white); text-transform:uppercase; letter-spacing:0.03em; }
.sp-author .nm a { color:inherit; text-decoration:none; }
.sp-author .rl { font-family: var(--font-mono); font-size:10px; letter-spacing:0.2em; color: var(--color-silver); text-transform:uppercase; margin-top:3px; }
.sp-author .bio { color:var(--color-fog); font-size:14px; line-height:1.55; margin:8px 0 0; max-width:64ch; }
</style>

<?php while ( have_posts() ) : the_post();
	$cats = get_the_category();
	$author = get_the_author();
	$author_id  = (int) get_the_author_meta( 'ID' );
	$author_url = (string) get_the_author_meta( 'user_url', $author_id );
	$author_bio = trim( (string) get_the_author_meta( 'description', $author_id ) );
?>
<header class="sp-hero">
  <div class="c">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>">Knowledge Hub</a><?php if ( $cats ) : ?>  <a href="<?php echo esc_url( get_category_link( $cats[0] ) ); ?>"><?php echo esc_html( $cats[0]->name ); ?></a><?php endif; ?></div>
  </div>
</header>

<div class="sp-wrap">
  <article class="sp-article">
    <div class="chips">
      <?php foreach ( array_slice( (array) $cats, 0, 3 ) as $c ) : ?>
        <a class="chip chip--brass" style="text-decoration:none;" href="<?php echo esc_url( get_category_link( $c ) ); ?>"><?php echo esc_html( $c->name ); ?></a>
      <?php endforeach; ?>
    </div>
    <h1><?php the_title(); ?></h1>
    <div class="byline">
      <span><?php echo esc_html( get_the_date() ); ?></span>
      <span>By <?php if ( $author_url ) : ?><a href="<?php echo esc_url( $author_url ); ?>" rel="author"><?php echo esc_html( $author ); ?></a><?php else : ?><?php echo esc_html( $author ); ?><?php endif; ?></span>
      <span><?php echo esc_html( max( 1, (int) round( str_word_count( wp_strip_all_tags( get_the_content() ) ) / 200 ) ) ); ?> min read</span>
    </div>
    <?php if ( has_post_thumbnail() ) : ?>
      <div class="feat"><?php the_post_thumbnail( 'large', [ 'loading' => 'eager', 'fetchpriority' => 'high' ] ); ?></div>
    <?php endif; ?>

    <div class="g2a-prose"><?php the_content(); ?></div>

    <div class="sp-promo">
      <div>
        <h3>Sell Your Used Guns To Guns&nbsp;2&nbsp;Ammo</h3>
        <p>Fair cash offers from a licensed Mesa dealer  no listing hassle, same-day turnaround.</p>
      </div>
      <a class="btn btn-brass btn-arrow" href="<?php echo esc_url( home_url( '/sell-your-gun/' ) ); ?>">Get My Cash Offer</a>
    </div>

    <footer class="sp-foot">
      <?php if ( has_tag() ) : ?>
        <div class="sp-tags"><?php
          foreach ( (array) get_the_tags() as $t ) {
            echo '<a class="chip" style="text-decoration:none;" href="' . esc_url( get_tag_link( $t ) ) . '">#' . esc_html( $t->name ) . '</a>';
          }
        ?></div>
      <?php endif; ?>
      <div class="sp-author">
        <div class="av"><?php echo esc_html( strtoupper( substr( $author, 0, 2 ) ) ); ?></div>
        <div>
          <div class="nm"><?php if ( $author_url ) : ?><a href="<?php echo esc_url( $author_url ); ?>" rel="author"><?php echo esc_html( $author ); ?></a><?php else : ?><?php echo esc_html( $author ); ?><?php endif; ?></div>
          <div class="rl">Guns 2 Ammo  Mesa, Arizona</div>
          <?php if ( $author_bio ) : ?><p class="bio"><?php echo esc_html( $author_bio ); ?></p><?php endif; ?>
        </div>
      </div>
      <div class="sp-nav" style="margin-top:24px;">
        <?php previous_post_link( '%link', ' %title' ); ?>
        <?php next_post_link( '%link', '%title ' ); ?>
      </div>
    </footer>
  </article>

  <?php get_template_part( 'template-parts/blog-sidebar' ); ?>
</div>
<?php endwhile; ?>
<?php get_footer();
