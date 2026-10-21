<?php
defined('ABSPATH') || exit;
if (post_password_required()) return;
?>
<section id="comments" class="comments-area">
    <?php if (have_comments()) : ?>
        <h2><?php esc_html_e('Comments', 'lavka-storefront'); ?></h2>
        <ol class="comment-list"><?php wp_list_comments(['style' => 'ol', 'short_ping' => true, 'avatar_size' => 40]); ?></ol>
        <?php the_comments_pagination(); ?>
    <?php endif; ?>
    <?php if (!comments_open() && get_comments_number()) : ?><p><?php esc_html_e('Comments are closed.', 'lavka-storefront'); ?></p><?php endif; ?>
    <?php comment_form(); ?>
</section>
