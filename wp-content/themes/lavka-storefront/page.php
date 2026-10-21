<?php defined('ABSPATH') || exit; get_header(); ?>
<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <?php do_action('lavka_storefront_before_main_content'); ?>
        <?php while (have_posts()) : the_post(); get_template_part('template-parts/content', 'page'); endwhile; ?>
    </main>
</div>
<?php get_footer(); ?>
