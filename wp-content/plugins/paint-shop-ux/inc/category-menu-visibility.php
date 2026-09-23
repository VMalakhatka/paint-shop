<?php
if (!defined('ABSPATH')) exit;

final class PSU_Category_Menu_Visibility {
    const OPTION = 'psu_category_menu_excluded';

    public static function save($ids) {
        if (!current_user_can('edit_theme_options')) return new WP_Error('forbidden', __('Access denied.', 'paint-shop-ux'));
        if (!is_array($ids)) return new WP_Error('invalid', __('Invalid category selection.', 'paint-shop-ux'));
        $ids = array_values(array_unique(array_filter(array_map('absint', array_filter($ids, 'is_scalar')))));
        if ($ids) {
            $valid = get_terms(['taxonomy' => 'product_cat', 'include' => $ids, 'hide_empty' => false, 'fields' => 'ids']);
            if (is_wp_error($valid)) return $valid;
            $ids = array_values(array_intersect($ids, array_map('intval', $valid)));
        }
        sort($ids);
        update_option(self::OPTION, $ids, false);
        if (get_option(self::OPTION) !== $ids) return new WP_Error('save_failed', __('Category settings could not be saved.', 'paint-shop-ux'));
        return true;
    }

    public static function handle_post() {
        if (!current_user_can('edit_theme_options')) wp_die(esc_html__('Access denied.', 'paint-shop-ux'), '', ['response' => 403]);
        check_admin_referer('psu_category_visibility');
        $result = self::save(wp_unslash($_POST['excluded'] ?? []));
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
        wp_safe_redirect(add_query_arg('visibility_saved', '1', admin_url('themes.php?page=psu-category-menu')));
        exit;
    }

    public static function search() {
        if (!current_user_can('edit_theme_options')) wp_send_json_error([], 403);
        check_ajax_referer('psu_category_visibility_search', 'security');
        $search = isset($_GET['term']) && is_string($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $page = max(1, absint($_GET['page'] ?? 1));
        if ($page > 1000) wp_send_json_error([], 400);
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'hierarchical' => false,
            'search' => $search, 'orderby' => 'name', 'order' => 'ASC', 'number' => 31, 'offset' => ($page - 1) * 30]);
        if (is_wp_error($terms)) wp_send_json_error([], 500);
        $items = [];
        foreach (array_slice($terms, 0, 30) as $term) $items[] = ['id' => $term->term_id, 'text' => self::label($term)];
        wp_send_json(['results' => $items, 'pagination' => ['more' => count($terms) > 30]]);
    }

    private static function label($term) {
        $names = [];
        foreach (array_reverse(get_ancestors($term->term_id, 'product_cat')) as $id) {
            $parent = get_term($id, 'product_cat');
            if ($parent instanceof WP_Term) $names[] = $parent->name;
        }
        $names[] = $term->name;
        return wp_strip_all_tags(html_entity_decode(implode(' / ', $names), ENT_QUOTES, 'UTF-8'));
    }

    public static function assets($hook) {
        if ($hook !== 'appearance_page_psu-category-menu') return;
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('psu-category-visibility', plugins_url('../assets/category-visibility.css', __FILE__), ['woocommerce_admin_styles'], PSU_Category_Menu::VERSION);
        wp_enqueue_script('psu-category-visibility', plugins_url('../assets/category-visibility.js', __FILE__), ['jquery', 'selectWoo'], PSU_Category_Menu::VERSION, true);
        wp_localize_script('psu-category-visibility', 'psuCategoryVisibility', [
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('psu_category_visibility_search'),
        ]);
    }

    public static function form() {
        if (!empty($_GET['visibility_saved'])) echo '<div class="notice notice-success"><p>' . esc_html__('Category menu settings saved.', 'paint-shop-ux') . '</p></div>';
        echo '<h2>' . esc_html__('Hidden categories', 'paint-shop-ux') . '</h2>';
        echo '<form class="psu-category-visibility" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('psu_category_visibility');
        echo '<input type="hidden" name="action" value="psu_category_visibility_save">';
        echo '<p><label for="psu-category-excluded">' . esc_html__('Always hide categories and their subcategories', 'paint-shop-ux') . '</label></p>';
        echo '<select id="psu-category-excluded" name="excluded[]" multiple style="width:100%;max-width:720px" data-placeholder="' . esc_attr__('Search categories', 'paint-shop-ux') . '">';
        foreach (PSU_Category_Menu::excluded() as $id) {
            $term = get_term($id, 'product_cat');
            if ($term instanceof WP_Term) echo '<option selected value="' . (int) $id . '">' . esc_html(self::label($term)) . '</option>';
        }
        echo '</select>';
        submit_button(__('Save changes', 'paint-shop-ux'));
        echo '</form>';
    }
}
add_action('admin_post_psu_category_visibility_save', [PSU_Category_Menu_Visibility::class, 'handle_post']);
add_action('wp_ajax_psu_category_visibility_search', [PSU_Category_Menu_Visibility::class, 'search']);
add_action('admin_enqueue_scripts', [PSU_Category_Menu_Visibility::class, 'assets']);
