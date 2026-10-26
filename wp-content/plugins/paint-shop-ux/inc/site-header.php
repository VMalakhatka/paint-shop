<?php
/** Site identity and navigation. Stock policy and preferences belong to Paint Core. */
defined('ABSPATH') || exit;

function psu_site_header_enabled(): bool {
    return !is_admin() && (bool) apply_filters('psu_site_header_enabled', true);
}

function psu_site_header_setup(): void {
    if (!psu_site_header_enabled()) return;
    // Remove only the known theme header; page/featured-image hooks stay intact.
    foreach ([
        ['generate_before_header', 'lavka_topbar', 1],
        ['generate_before_header', 'generate_do_skip_to_content_link', 2],
        ['generate_before_header', 'generate_top_bar', 5],
        ['generate_header', 'generate_construct_header', 10],
        ['generate_before_header', 'generate_add_navigation_before_header', 5],
        ['generate_after_header', 'generate_add_navigation_after_header', 5],
        ['generate_after_header_content', 'generate_add_navigation_float_right', 5],
        ['generate_before_right_sidebar_content', 'generate_add_navigation_before_right_sidebar', 5],
        ['generate_before_left_sidebar_content', 'generate_add_navigation_before_left_sidebar', 5],
        ['wp_footer', 'generate_clone_sidebar_navigation', 10],
    ] as [$hook, $callback, $priority]) remove_action($hook, $callback, $priority);
    add_action('wp_body_open', 'psu_render_site_header', 5);
}
add_action('wp', 'psu_site_header_setup');

add_filter('body_class', static function (array $classes): array {
    if (psu_site_header_enabled()) $classes[] = 'psu-header-page';
    return $classes;
});
add_filter('pc_alloc_header_rendered', static function ($rendered): bool {
    return (bool) $rendered || !empty($GLOBALS['psu_site_header_rendered']);
});

/** Translated link text with a decorative, bundled cart icon. */
function psu_site_header_cart_label(): string {
    return esc_html__('Cart', 'paint-shop-ux') . ' <svg class="psu-site-header__cart-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3h2l2.5 12h11l2-8H6M9 20h.01M18 20h.01"/></svg>';
}

/** Change only the configured cart link inside our primary menu, not page titles. */
function psu_site_header_menu_title($title, $item, $args) {
    if (($args->menu_id ?? '') !== 'psu-primary-menu' || !function_exists('wc_get_page_id')) return $title;
    $cart_id = wc_get_page_id('cart');
    if ($cart_id <= 0) return $title;
    $is_cart = ($item->object ?? '') === 'page' && (int) ($item->object_id ?? 0) === $cart_id;
    $is_cart = $is_cart || untrailingslashit((string) ($item->url ?? '')) === untrailingslashit(wc_get_page_permalink('cart'));
    return $is_cart ? psu_site_header_cart_label() : $title;
}

/** Use the saved menu and its Woo/role filters, without GeneratePress's search item. */
function psu_site_header_menu(): string {
    $search_priority = has_filter('wp_nav_menu_items', 'generate_menu_search_icon');
    if ($search_priority !== false) remove_filter('wp_nav_menu_items', 'generate_menu_search_icon', $search_priority);
    add_filter('nav_menu_item_title', 'psu_site_header_menu_title', 100, 3);
    try {
        $menu = wp_nav_menu([
            'theme_location' => 'primary', 'container' => false, 'container_class' => '',
            'menu_id' => 'psu-primary-menu', 'menu_class' => 'psu-site-header__menu',
            'fallback_cb' => false, 'echo' => false,
        ]);
    } finally {
        remove_filter('nav_menu_item_title', 'psu_site_header_menu_title', 100);
        if ($search_priority !== false) add_filter('wp_nav_menu_items', 'generate_menu_search_icon', $search_priority, 2);
    }
    if (is_string($menu) && trim($menu) !== '') return $menu;

    // Never expose every WordPress page if the primary menu is unassigned.
    $links = [['shop', __('Catalogue', 'paint-shop-ux')], ['cart', __('Cart', 'paint-shop-ux')], ['myaccount', __('My account', 'paint-shop-ux')]];
    $html = '<ul id="psu-primary-menu" class="psu-site-header__menu">';
    foreach ($links as [$page, $label]) {
        $url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink($page) : ($page === 'shop' ? home_url('/') : '');
        if ($url && $url !== '-1') $html .= '<li><a href="' . esc_url($url) . '">' . ($page === 'cart' ? psu_site_header_cart_label() : esc_html($label)) . '</a></li>';
    }
    return $html . '</ul>';
}

function psu_render_site_header(): void {
    if (!psu_site_header_enabled() || !empty($GLOBALS['psu_site_header_rendered'])) return;
    $GLOBALS['psu_site_header_rendered'] = true;
    $logo = file_get_contents(dirname(__DIR__) . '/assets/site-logo.svg');
    ?>
    <a class="psu-site-header__skip" href="#main"><?php esc_html_e('Skip to content', 'paint-shop-ux'); ?></a>
    <header class="psu-site-header">
        <div class="psu-site-header__identity">
            <div class="psu-site-header__container psu-site-header__brand-row">
                <a class="psu-site-header__logo" href="<?php echo esc_url(home_url('/')); ?>" rel="home" aria-label="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <?php echo $logo ?: esc_html(get_bloginfo('name')); // Trusted bundled SVG, never user-provided markup. ?>
                </a>
                <ul class="psu-site-header__contacts">
                    <li><span><?php esc_html_e('Kyiv, Olimpiiskyi shopping centre', 'paint-shop-ux'); ?></span><a href="tel:+380445932605">+380 44 593-26-05</a></li>
                    <li><span><?php esc_html_e('Odesa, 17 Lanzheronivska St.', 'paint-shop-ux'); ?></span><a href="tel:+380638571768">+380 63 857-17-68</a></li>
                    <li><span><?php esc_html_e('Wholesale sales', 'paint-shop-ux'); ?></span><a href="tel:+380503480138">+380 50 348-01-38</a></li>
                </ul>
            </div>
        </div>
        <?php foreach (['top-bar', 'header'] as $area): if (is_active_sidebar($area)): ?>
            <div class="psu-site-header__container psu-site-header__widgets"><?php dynamic_sidebar($area); ?></div>
        <?php endif; endforeach; ?>
        <div class="psu-site-header__container psu-site-header__toolbar">
            <div class="psu-site-header__controls">
                <div class="psu-site-header__allocation"><?php if (function_exists('pc_render_alloc_control')) pc_render_alloc_control(); ?></div>
                <?php do_action('pcoe_catalog_toolbar'); // Existing eligibility and render-once guard remain in PCOE. ?>
            </div>
            <button class="psu-site-header__toggle" type="button" aria-controls="psu-site-navigation" aria-expanded="true">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                <span><?php esc_html_e('Menu', 'paint-shop-ux'); ?></span>
            </button>
            <nav id="psu-site-navigation" class="psu-site-header__nav" aria-label="<?php esc_attr_e('Main navigation', 'paint-shop-ux'); ?>" data-submenu-label="<?php esc_attr_e('Submenu: %s', 'paint-shop-ux'); ?>">
                <?php echo psu_site_header_menu(); ?>
            </nav>
        </div>
    </header>
    <?php
}

add_action('wp_enqueue_scripts', static function (): void {
    if (!psu_site_header_enabled()) return;
    // Paint Core's existing control script is now emitted in the header.
    wp_enqueue_script('jquery');
    foreach (['css', 'js'] as $type) {
        $file = dirname(__DIR__) . '/assets/site-header.' . $type;
        $url = plugins_url('../assets/site-header.' . $type, __FILE__);
        if ($type === 'css') wp_enqueue_style('psu-site-header', $url, [], (string) filemtime($file));
        else wp_enqueue_script('psu-site-header', $url, [], (string) filemtime($file), true);
    }
}, 120);
