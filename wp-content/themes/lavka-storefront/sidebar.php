<?php
defined('ABSPATH') || exit;
$layout = lavka_storefront_sidebar_layout();
if ($layout === 'no-sidebar') return;
$left = $layout === 'left-sidebar';
?>
<aside id="<?php echo $left ? 'left-sidebar' : 'right-sidebar'; ?>" class="widget-area" aria-label="<?php esc_attr_e('Catalogue sidebar', 'lavka-storefront'); ?>">
    <div class="<?php echo $left ? 'inside-left-sidebar' : 'inside-right-sidebar'; ?>">
        <?php dynamic_sidebar($left ? 'sidebar-2' : 'sidebar-1'); ?>
    </div>
</aside>
