<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;
get_header();
?>
<main id="primary" class="lw-page"><div class="lw-container">
<?php if (section_closed()) echo '<p class="lw-test-notice" role="status">' . esc_html(closed_notice()) . '</p>'; ?>
<?php is_singular('lavka_workshop') ? article() : schedule(); ?>
<footer class="lw-note"><span aria-hidden="true">✳</span><p><?php esc_html_e('A few hours offline. Something beautiful to take home.', 'lavka-workshops'); ?></p></footer>
</div></main>
<?php get_footer(); ?>
