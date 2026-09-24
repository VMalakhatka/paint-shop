<?php
if (!defined('ABSPATH')) exit;

final class PSU_Category_Menu_Visibility {
    const OPTION = 'psu_category_menu_excluded';

    public static function tile_args($args) {
        if (!PSU_Category_Menu::excluded()) return $args;
        $index = PSU_Category_Menu::index();
        if (is_wp_error($index)) return $args;
        $args['exclude'] = array_values(array_unique(array_merge(
            wp_parse_id_list($args['exclude'] ?? []), array_keys($index['blocked'])
        )));
        return $args;
    }

    public static function tile_cache_key($key) {
        // Woo caches the final hierarchy without query args. Retain WP's term-query
        // cache, but bypass this outer cache while exclusions can affect the result.
        return PSU_Category_Menu::excluded() ? false : $key;
    }

    public static function shortcode_tiles($terms) {
        if (!is_array($terms) || !PSU_Category_Menu::excluded()) return $terms;
        $index = PSU_Category_Menu::index();
        if (is_wp_error($index)) return $terms;
        return array_values(array_filter($terms, static function ($term) use ($index) {
            return !isset($index['blocked'][$term->term_id]);
        }));
    }

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
        $offset = absint($_GET['offset'] ?? 0);
        if ($offset > 100000) wp_send_json_error([], 400);
        $result = self::branch(absint($_GET['parent'] ?? 0), $offset, $search);
        if (is_wp_error($result)) wp_send_json_error([], 500);
        wp_send_json($result);
    }

    public static function branch($parent, $offset, $search = '') {
        $index = PSU_Category_Menu::index();
        if (is_wp_error($index)) return $index;
        $args = ['taxonomy' => 'product_cat', 'hide_empty' => false, 'hierarchical' => false,
            'orderby' => 'name', 'order' => 'ASC', 'number' => 31, 'offset' => $offset,
            'psu_category_menu' => true];
        if ($search !== '') $args['search'] = $search;
        else {
            $args['include'] = $index['children'][$parent] ?? [];
            if (!$args['include']) return ['items' => [], 'next' => null];
        }
        $terms = get_terms($args);
        if (is_wp_error($terms)) return $terms;
        $items = [];
        foreach (array_slice($terms, 0, 30) as $term) {
            $path = PSU_Category_Menu::path($term->term_id, $index);
            array_pop($path);
            $items[] = ['id' => $term->term_id, 'name' => wp_strip_all_tags(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8')), 'path' => self::label($term),
                'parents' => $path, 'children' => !empty($index['children'][$term->term_id])];
        }
        return ['items' => $items, 'next' => count($terms) > 30 ? $offset + 30 : null];
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
        wp_enqueue_style('psu-category-visibility', plugins_url('../assets/category-visibility.css', __FILE__), ['dashicons'], PSU_Category_Menu::VERSION);
        wp_enqueue_script('psu-category-visibility', plugins_url('../assets/category-visibility.js', __FILE__), [], PSU_Category_Menu::VERSION, true);
        wp_localize_script('psu-category-visibility', 'psuCategoryVisibility', [
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('psu_category_visibility_search'),
            'labels' => ['expand' => __('Expand %s', 'paint-shop-ux'), 'collapse' => __('Collapse %s', 'paint-shop-ux'),
                'hide' => __('Hide %s', 'paint-shop-ux'), 'remove' => __('Show %s again', 'paint-shop-ux'),
                'loading' => __('Loading categories...', 'paint-shop-ux'), 'retry' => __('Retry', 'paint-shop-ux'),
                'error' => __('Categories could not be loaded.', 'paint-shop-ux'), 'empty' => __('No categories found.', 'paint-shop-ux'),
                'more' => __('More categories', 'paint-shop-ux')],
        ]);
    }

    public static function form() {
        if (!empty($_GET['visibility_saved'])) echo '<div class="notice notice-success"><p>' . esc_html__('Category menu settings saved.', 'paint-shop-ux') . '</p></div>';
        echo '<h2>' . esc_html__('Hidden categories', 'paint-shop-ux') . '</h2>';
        echo '<form class="psu-category-visibility" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('psu_category_visibility');
        echo '<input type="hidden" name="action" value="psu_category_visibility_save">';
        echo '<p>' . esc_html__('Always hide categories and their subcategories', 'paint-shop-ux') . '</p>';
        echo '<div data-selected>';
        foreach (PSU_Category_Menu::excluded() as $id) {
            $term = get_term($id, 'product_cat');
            if ($term instanceof WP_Term) echo '<input type="hidden" name="excluded[]" value="' . (int) $id . '" data-label="' . esc_attr(self::label($term)) . '">';
        }
        echo '</div><label for="psu-category-search">' . esc_html__('Search categories', 'paint-shop-ux') . '</label>';
        echo '<input type="search" id="psu-category-search" autocomplete="off">';
        echo '<div class="psu-visibility-status" role="status" aria-live="polite"></div>';
        echo '<ul class="psu-visibility-tree" aria-label="' . esc_attr__('Product categories', 'paint-shop-ux') . '"></ul>';
        submit_button(__('Save changes', 'paint-shop-ux'));
        echo '</form>';
    }
}
add_action('admin_post_psu_category_visibility_save', [PSU_Category_Menu_Visibility::class, 'handle_post']);
add_action('wp_ajax_psu_category_visibility_search', [PSU_Category_Menu_Visibility::class, 'search']);
add_action('admin_enqueue_scripts', [PSU_Category_Menu_Visibility::class, 'assets']);
add_filter('woocommerce_product_subcategories_args', [PSU_Category_Menu_Visibility::class, 'tile_args'], 100);
add_filter('woocommerce_get_product_subcategories_cache_key', [PSU_Category_Menu_Visibility::class, 'tile_cache_key']);
add_filter('woocommerce_product_categories', [PSU_Category_Menu_Visibility::class, 'shortcode_tiles']);
