<?php defined('ABSPATH') || exit; ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('inside-article lavka-entry'); ?>>
    <?php if (lavka_storefront_show_title()) : ?>
        <header class="entry-header"><h1 class="entry-title"><?php the_title(); ?></h1></header>
    <?php endif; ?>
    <?php if (!post_password_required() && has_post_thumbnail()) : ?><div class="post-thumbnail"><?php the_post_thumbnail('large'); ?></div><?php endif; ?>
    <div class="entry-content">
        <?php the_content(); ?>
        <?php wp_link_pages(['before' => '<nav class="page-links" aria-label="' . esc_attr__('Page sections', 'lavka-storefront') . '">', 'after' => '</nav>']); ?>
    </div>
    <?php if (comments_open() || get_comments_number()) comments_template(); ?>
</article>
