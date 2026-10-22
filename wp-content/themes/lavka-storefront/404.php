<?php defined('ABSPATH') || exit; get_header(); ?>
<div id="primary" class="content-area">
    <main id="main" class="site-main">
        <?php do_action('lavka_storefront_before_main_content'); ?>
        <section class="lavka-entry">
            <h1><?php esc_html_e('Page not found', 'lavka-storefront'); ?></h1>
            <p><?php esc_html_e('The address may have changed. Return to the home page or try a search.', 'lavka-storefront'); ?></p>
            <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'lavka-storefront'); ?></a>
            <?php get_search_form(); ?>
        </section>
    </main>
</div>
<?php get_footer(); ?>
