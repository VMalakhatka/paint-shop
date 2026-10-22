<?php
/** Content templates for ordinary pages. Keep WordPress content and query APIs. */
defined('ABSPATH') || exit;
$view = $GLOBALS['psu_public_page_view'] ?? '';
get_header();
?>
<div id="primary" class="psu-public-content">
    <main id="main" class="psu-public-main">
        <nav class="psu-page-breadcrumb" aria-label="<?php esc_attr_e('Breadcrumbs', 'paint-shop-ux'); ?>">
            <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'paint-shop-ux'); ?></a>
            <span aria-hidden="true">/</span>
            <span><?php echo esc_html($view === 'page' ? get_the_title(get_queried_object_id()) : ($view === 'search' ? __('Search', 'paint-shop-ux') : __('Page not found', 'paint-shop-ux'))); ?></span>
        </nav>
        <?php if ($view === 'page') : ?>
            <?php while (have_posts()) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('psu-page-card'); ?>>
                    <header class="psu-page-heading"><h1><?php the_title(); ?></h1></header>
                    <?php if (!post_password_required() && has_post_thumbnail()) : ?>
                        <figure class="psu-page-featured"><?php the_post_thumbnail('large'); ?></figure>
                    <?php endif; ?>
                    <div class="entry-content psu-page-prose">
                        <?php the_content(); ?>
                        <?php wp_link_pages(['before' => '<nav class="psu-page-parts" aria-label="' . esc_attr__('Page sections', 'paint-shop-ux') . '">', 'after' => '</nav>']); ?>
                    </div>
                    <?php if (comments_open() || get_comments_number()) comments_template(); ?>
                </article>
            <?php endwhile; ?>
        <?php elseif ($view === 'not-found') : ?>
            <section class="psu-page-card psu-page-empty">
                <p class="psu-page-status" aria-hidden="true">404</p>
                <header class="psu-page-heading"><h1><?php esc_html_e('Page not found', 'paint-shop-ux'); ?></h1></header>
                <p><?php esc_html_e('The address may have changed. Open the catalogue or try a search.', 'paint-shop-ux'); ?></p>
                <a class="button psu-page-catalog" href="<?php echo esc_url(psu_public_page_catalog_url()); ?>"><?php esc_html_e('Open catalogue', 'paint-shop-ux'); ?></a>
                <?php psu_public_page_search_form(true); ?>
            </section>
        <?php elseif ($view === 'search') : ?>
            <section class="psu-page-card">
                <header class="psu-page-heading"><h1><?php printf(esc_html__('Search results for “%s”', 'paint-shop-ux'), esc_html(get_search_query(false))); ?></h1></header>
                <?php psu_public_page_search_form(); ?>
                <?php if (have_posts()) : ?>
                    <div class="psu-page-results">
                        <?php while (have_posts()) : the_post(); ?>
                            <article class="psu-page-result">
                                <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                                <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags(get_the_excerpt()), 36)); ?></p>
                            </article>
                        <?php endwhile; ?>
                    </div>
                    <?php the_posts_pagination(['prev_text' => __('Previous', 'paint-shop-ux'), 'next_text' => __('Next', 'paint-shop-ux')]); ?>
                <?php else : ?>
                    <p class="psu-page-no-results"><?php esc_html_e('Nothing found. Try a different search or open the catalogue.', 'paint-shop-ux'); ?></p>
                    <a class="button" href="<?php echo esc_url(psu_public_page_catalog_url()); ?>"><?php esc_html_e('Open catalogue', 'paint-shop-ux'); ?></a>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php get_footer(); ?>
