<?php defined('ABSPATH') || exit; ?>
    </div>
</div>
<?php if (!lavka_storefront_has_own_footer()) : ?>
    <footer class="lavka-fallback-footer">
        <div class="lavka-fallback-footer__inner">
            <p>© <?php echo esc_html(wp_date('Y')); ?> <a href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a></p>
            <?php if (function_exists('pc_wayforpay_render_legal_footer')) pc_wayforpay_render_legal_footer(); ?>
            <div class="lavka-fallback-widgets">
                <?php foreach (['footer-1', 'footer-2', 'footer-3', 'footer-4', 'footer-5', 'footer-bar'] as $area) : if (is_active_sidebar($area)) : ?>
                    <div><?php dynamic_sidebar($area); ?></div>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </footer>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>
