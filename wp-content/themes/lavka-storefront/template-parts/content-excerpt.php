<?php defined('ABSPATH') || exit; ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('lavka-entry'); ?>>
    <header class="entry-header"><h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2></header>
    <?php if (!post_password_required() && has_post_thumbnail()) : ?><a class="post-thumbnail" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php the_post_thumbnail('medium_large', ['alt' => '']); ?></a><?php endif; ?>
    <div class="entry-content"><?php the_excerpt(); ?></div>
</article>
