<?php defined('ABSPATH') || exit; get_header(); ?>
<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <?php do_action('lavka_storefront_before_main_content'); ?>
        <header class="lavka-entry"><h1><?php printf(esc_html__('Search results for “%s”', 'lavka-storefront'), esc_html(get_search_query(false))); ?></h1><?php get_search_form(); ?></header>
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); get_template_part('template-parts/content', 'excerpt'); endwhile; ?>
            <?php the_posts_pagination(); ?>
        <?php else : ?>
            <section class="lavka-entry"><p><?php esc_html_e('Nothing found. Try a different search.', 'lavka-storefront'); ?></p></section>
        <?php endif; ?>
    </main>
</div>
<?php if (lavka_storefront_sidebar_layout() !== 'no-sidebar') get_sidebar(); ?>
<?php get_footer(); ?>
