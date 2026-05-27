<?php
/**
 * Header  Guns 2 Ammo child theme
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!doctype html>
<html <?php language_attributes(); ?> class="g2a-loading">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1A191E">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="g2a-skip-link" href="#g2a-main"><?php esc_html_e( 'Skip to main content', 'guns2ammo' ); ?></a>

<!-- Preloader -->
<div id="g2a-preloader" role="status" aria-label="Loading">
	<div class="pl-stack">
		<div class="pl-logo"><span class="mark"></span>GUNS&nbsp;2&nbsp;AMMO</div>
		<div class="pl-bar"></div>
		<div class="pl-meta">Loading the range...</div>
	</div>
</div>

<?php get_template_part( 'template-parts/nav' ); ?>
<?php get_template_part( 'template-parts/mobile-drawer' ); ?>

<main id="g2a-main">
