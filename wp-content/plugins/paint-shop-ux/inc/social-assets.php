<?php
/** Keep WP Social's global assets off catalog pages without social controls. */
if (!defined('ABSPATH')) exit;

final class PSU_Social_Assets {
    private const STYLES = ['xs-front-style', 'xs_login_font_login_css'];
    private const SCRIPTS = ['xs_front_main_js', 'xs_social_custom'];
    private static bool $deferred = false;

    public static function boot(): void {
        add_action('wp_enqueue_scripts', [self::class, 'defer_catalog_assets'], 100);
        add_filter('the_content', [self::class, 'rendered_content'], PHP_INT_MAX);
        add_filter('do_shortcode_tag', [self::class, 'rendered_content'], PHP_INT_MAX);
        add_filter('widget_text_content', [self::class, 'rendered_content'], PHP_INT_MAX);
        add_filter('widget_block_content', [self::class, 'rendered_content'], PHP_INT_MAX);
    }

    public static function defer_catalog_assets(): void {
        if (is_admin() || wp_doing_ajax() || !defined('WSLU_VERSION')) return;
        $catalog = is_front_page()
            || (function_exists('is_shop') && is_shop())
            || (function_exists('is_product_taxonomy') && is_product_taxonomy())
            || (is_search() && get_query_var('post_type') === 'product');
        if (!$catalog || self::needs_head_assets()) return;

        // Fail open if the vendor changes its handles. Retain registrations,
        // dependencies and localized nonces for a late-rendered social block.
        foreach (self::STYLES as $handle) {
            if (!wp_style_is($handle, 'registered')) return;
        }
        foreach (self::SCRIPTS as $handle) {
            if (!wp_script_is($handle, 'registered')) return;
        }
        foreach (self::STYLES as $handle) wp_dequeue_style($handle);
        foreach (self::SCRIPTS as $handle) wp_dequeue_script($handle);
        self::$deferred = true;
    }

    private static function needs_head_assets(): bool {
        $style = get_option('xs_style_setting_data_share', []);
        $fixed = in_array($style['login_button_content'] ?? '',
            ['left_content', 'right_content', 'top_content', 'bottom_content'], true);
        // These render outside the_content; preserve their existing asset path.
        $widgets = is_active_widget(false, false, 'login_widget', true)
            || is_active_widget(false, false, 'share_widget', true)
            || is_active_widget(false, false, 'counter_widget', true);
        $custom = did_action('elementor/loaded') || did_action('wslu_social_pro/plugin_loaded');
        return (bool) apply_filters('psu_social_assets_required', $fixed || $widgets || $custom);
    }

    public static function rendered_content(string $html): string {
        if (!self::$deferred || !preg_match('/\b(?:xs_social_(?:share|counter)_widget|xs-social-login-container)\b/', $html)) return $html;
        self::$deferred = false;
        foreach (self::STYLES as $handle) wp_enqueue_style($handle);
        foreach (self::SCRIPTS as $handle) {
            wp_script_add_data($handle, 'group', 1);
            wp_enqueue_script($handle);
        }
        // Catalog templates render after wp_head. Print only these styles once,
        // before their markup; WordPress will print the scripts in the footer.
        if (did_action('wp_head')) {
            ob_start();
            wp_print_styles(self::STYLES);
            $html = ob_get_clean() . $html;
        }
        return $html;
    }
}

PSU_Social_Assets::boot();
