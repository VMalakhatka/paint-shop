<?php
/** Own storefront footer; GeneratePress is only a placement adapter. */
defined('ABSPATH') || exit;

function psu_site_footer_enabled(): bool {
    return !is_admin() && (bool) apply_filters('psu_site_footer_enabled', true);
}

function psu_site_footer_setup(): void {
    if (!psu_site_footer_enabled()) return;
    remove_action('generate_before_footer_content', 'pc_wayforpay_render_legal_footer', 5);
    remove_action('generate_footer', 'generate_construct_footer_widgets', 5);
    remove_action('generate_footer', 'generate_construct_footer', 10);
    add_action('generate_footer', 'psu_render_site_footer', 10);
    // Standard WP fallback also works when the next theme has no GP hooks.
    add_action('wp_footer', 'psu_render_site_footer', 5);
}
add_action('wp', 'psu_site_footer_setup');

function psu_render_site_footer(): void {
    static $rendered = false;
    if ($rendered || !psu_site_footer_enabled()) return;
    $rendered = true;
    $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
    $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';
    ?>
    <footer class="psu-site-footer" aria-label="<?php esc_attr_e('Site footer', 'paint-shop-ux'); ?>">
        <div class="psu-site-footer__top psu-site-footer__container">
            <a class="psu-site-footer__brand" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Lavka Khudozhnyka', 'paint-shop-ux'); ?></a>
            <nav class="psu-site-footer__navigation" aria-label="<?php esc_attr_e('Shop links', 'paint-shop-ux'); ?>">
                <a href="<?php echo esc_url($shop); ?>"><?php esc_html_e('Catalogue', 'paint-shop-ux'); ?></a>
                <?php if ($account && $account !== '-1'): ?>
                    <a href="<?php echo esc_url($account); ?>"><?php esc_html_e('My account', 'paint-shop-ux'); ?></a>
                <?php endif; ?>
                <?php if (function_exists('pc_wholesale_customer_can_access') && pc_wholesale_customer_can_access() && function_exists('pc_wholesale_help_url')): ?>
                    <a href="<?php echo esc_url(pc_wholesale_help_url()); ?>"><?php esc_html_e('How to order', 'paint-shop-ux'); ?></a>
                <?php endif; ?>
            </nav>
        </div>
        <?php
        // The legal module remains the single source of seller data and policy URLs.
        if (function_exists('pc_wayforpay_render_legal_footer')) pc_wayforpay_render_legal_footer();

        // Preserve configured footer widgets without inheriting theme layout/styles.
        $areas = array_filter(['footer-1', 'footer-2', 'footer-3', 'footer-4', 'footer-5', 'footer-bar'], 'is_active_sidebar');
        if ($areas): ?>
            <div class="psu-site-footer__widgets psu-site-footer__container">
                <?php foreach ($areas as $area): ?><div><?php dynamic_sidebar($area); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="psu-site-footer__bottom psu-site-footer__container">
            <span>© <?php echo esc_html(wp_date('Y')); ?> <?php esc_html_e('Lavka Khudozhnyka', 'paint-shop-ux'); ?></span>
        </div>
    </footer>
    <?php
}

add_action('wp_enqueue_scripts', static function (): void {
    if (!psu_site_footer_enabled()) return;
    $file = dirname(__DIR__) . '/assets/site-footer.css';
    wp_enqueue_style('psu-site-footer', plugins_url('assets/site-footer.css', dirname(__DIR__) . '/paint-shop-ux.php'), [], (string) filemtime($file));
}, 120);
