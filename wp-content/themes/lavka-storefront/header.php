<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php if (!lavka_storefront_has_own_header()) : ?>
    <a class="screen-reader-text" href="#main"><?php esc_html_e('Skip to content', 'lavka-storefront'); ?></a>
    <header class="lavka-fallback-header">
        <div class="lavka-fallback-header__inner">
            <?php if (has_custom_logo()) the_custom_logo(); ?>
            <p class="site-title"><a href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a></p>
            <nav aria-label="<?php esc_attr_e('Main navigation', 'lavka-storefront'); ?>">
                <?php wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'fallback_cb' => false]); ?>
            </nav>
            <?php foreach (['top-bar', 'header'] as $area) : if (is_active_sidebar($area)) : ?>
                <div class="lavka-fallback-widgets"><?php dynamic_sidebar($area); ?></div>
            <?php endif; endforeach; ?>
        </div>
    </header>
<?php endif; ?>
<div id="page" class="site lavka-shell">
    <div id="content" class="site-content lavka-layout">
