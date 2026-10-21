<?php defined('ABSPATH') || exit; get_header(); ?>
<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <?php do_action('lavka_storefront_before_main_content'); ?>
        <?php if (is_archive()) : ?>
            <header class="lavka-entry"><h1><?php the_archive_title(); ?></h1><?php the_archive_description(); ?></header>
        <?php elseif (is_home()) : ?>
            <header class="lavka-entry"><h1><?php echo esc_html(get_option('page_for_posts') ? get_the_title(get_option('page_for_posts')) : __('Latest posts', 'lavka-storefront')); ?></h1></header>
        <?php endif; ?>
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); get_template_part('template-parts/content', 'excerpt'); endwhile; ?>
            <?php the_posts_pagination(); ?>
        <?php else : ?>
            <section class="lavka-entry"><h2><?php esc_html_e('Nothing found', 'lavka-storefront'); ?></h2><?php get_search_form(); ?></section>
        <?php endif; ?>
    </main>
</div>
<?php if (lavka_storefront_sidebar_layout() !== 'no-sidebar') get_sidebar(); ?>
<?php get_footer(); ?>
