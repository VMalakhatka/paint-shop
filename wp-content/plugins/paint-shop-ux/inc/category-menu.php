<?php
if (!defined('ABSPATH')) exit;

/** Shared category renderer for the native widget and the transitional WPB adapter. */
final class PSU_Category_Menu {
    const PAGE_SIZE = 30;
    const INITIAL_LIMIT = 60;
    const VERSION = '1.3.2';
    private static $legacy_used = false;
    private static $dirty = false;

    public static function boot() {
        add_filter('widget_display_callback', [__CLASS__, 'widget'], 20, 3);
        add_action('wp_enqueue_scripts', [__CLASS__, 'styles'], 30);
        add_action('wp_footer', [__CLASS__, 'legacy_assets'], 19);
        add_filter('do_shortcode_tag', [__CLASS__, 'shortcode_used'], 10, 2);
        add_action('wpb_wmca_before_accordion', [__CLASS__, 'mark_legacy']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('terms_clauses', [__CLASS__, 'stable_order'], 20, 3);
        add_action('clean_term_cache', [__CLASS__, 'invalidate'], 10, 2);
        add_action('clean_taxonomy_cache', [__CLASS__, 'taxonomy_changed']);
        add_action('added_term_meta', [__CLASS__, 'term_meta_changed'], 10, 3);
        add_action('updated_term_meta', [__CLASS__, 'term_meta_changed'], 10, 3);
        add_action('deleted_term_meta', [__CLASS__, 'term_meta_changed'], 10, 3);
    }

    public static function config($widget_id) {
        if (preg_match('/^psu_category_menu-(\d+)$/D', $widget_id, $match)) {
            if (!is_active_widget(false, $widget_id, 'psu_category_menu', true)) return null;
            $instances = get_option('widget_psu_category_menu', []);
            if (!isset($instances[(int) $match[1]]) || !is_array($instances[(int) $match[1]])) return null;
            return array_merge(PSU_Category_Widget::settings($instances[(int) $match[1]]), ['widget' => $widget_id]);
        }
        return self::legacy_config($widget_id);
    }

    public static function legacy_config($widget_id) {
        if (!preg_match('/^wpb_wmca_accordion_widget-(\d+)$/D', $widget_id, $match)) return null;
        if (!is_active_widget(false, $widget_id, 'wpb_wmca_accordion_widget', true)) return null;
        $instances = get_option('widget_wpb_wmca_accordion_widget', []);
        $instance = $instances[(int) $match[1]] ?? [];
        $id = absint($instance['id'] ?? 0);
        if (!$id || get_post_status($id) !== 'publish'
            || get_post_meta($id, 'wpb_wmca_data_socure', true) !== 'taxonomy'
            || get_post_meta($id, 'wpb_wmca_taxonomy', true) !== 'product_cat'
            || get_post_meta($id, 'wpb_wmca_tax_hide_out_of_stock', true) === 'on') return null;
        $orderby = get_post_meta($id, 'wpb_wmca_tax_orderby', true) ?: 'name';
        if (!in_array($orderby, ['name', 'slug', 'term_id', 'id', 'count', 'menu_order'], true)) return null;
        return [
            'widget' => $widget_id, 'id' => $id, 'orderby' => $orderby,
            'order' => get_post_meta($id, 'wpb_wmca_tax_order', true) === 'DESC' ? 'DESC' : 'ASC',
            'hide_empty' => get_post_meta($id, 'wpb_wmca_tax_hide_empty', true) === 'on',
            'show_count' => get_post_meta($id, 'wpb_wmca_tax_show_count', true) === 'on',
        ];
    }

    public static function taxonomy_changed($taxonomy) { self::invalidate([], $taxonomy); }
    public static function stable_order($clauses, $taxonomies, $args) {
        if (!empty($args['psu_category_menu']) && !empty($clauses['orderby'])) {
            $clauses['orderby'] .= ($args['order'] === 'DESC' ? ' DESC' : ' ASC') . ', t.term_id';
        }
        return $clauses;
    }
    public static function term_meta_changed($meta_id, $term_id, $key) {
        if (in_array($key, ['order', 'order_product_cat'], true)) {
            $term = get_term($term_id, 'product_cat');
            if ($term instanceof WP_Term) self::invalidate([], 'product_cat');
        }
    }
    public static function invalidate($ids = [], $taxonomy = '') {
        if ($taxonomy !== '' && $taxonomy !== 'product_cat') return;
        // Invalidate immediately and again after bulk writes, not once per SKU in a sync batch.
        if (self::$dirty) return;
        self::$dirty = true;
        self::bump_version();
        add_action('shutdown', [__CLASS__, 'bump_version']);
    }
    public static function bump_version() {
        update_option('psu_category_menu_version', wp_generate_uuid4(), false);
    }
    private static function key($kind, $parts = []) {
        return 'psu_cm_' . md5(wp_json_encode([
            self::VERSION, $kind, get_current_blog_id(), get_locale(), home_url('/'),
            get_option('psu_category_menu_version', '1'), get_option('woocommerce_permalinks'),
            get_option('permalink_structure'), self::excluded(), $parts,
        ]));
    }

    /** Compact server-only structural index. No names, links or full tree sent to the client. */
    public static function index() {
        $key = self::key('index');
        $index = self::$dirty ? false : get_transient($key);
        if (is_array($index)) return $index;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, parent, count FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'product_cat'
        ));
        if ($wpdb->last_error) return new WP_Error('category_query', __('Categories could not be loaded.', 'paint-shop-ux'));
        $index = self::build_index($rows, self::excluded());
        if (!self::$dirty) set_transient($key, $index, HOUR_IN_SECONDS);
        return $index;
    }
    public static function excluded() {
        $ids = get_option('psu_category_menu_excluded', []);
        return is_array($ids) ? array_values(array_unique(array_filter(array_map('absint', array_filter($ids, 'is_scalar'))))) : [];
    }
    public static function build_index($rows, $excluded = []) {
        $parents = []; $children = []; $visible = []; $blocked = [];
        foreach ($rows as $row) {
            $id = (int) $row->term_id;
            $parents[$id] = (int) $row->parent;
            $children[(int) $row->parent][] = $id;
        }
        $todo = $excluded;
        while ($todo) {
            $id = (int) array_pop($todo);
            if (isset($blocked[$id]) || !isset($parents[$id])) continue;
            $blocked[$id] = true;
            foreach ($children[$id] ?? [] as $child) $todo[] = $child;
        }
        foreach ($rows as $row) {
            if ((int) $row->count < 1 || isset($blocked[(int) $row->term_id])) continue;
            $id = (int) $row->term_id;
            while ($id && isset($parents[$id]) && !isset($visible[$id])) {
                $visible[$id] = true;
                $id = $parents[$id];
            }
        }
        return ['parents' => $parents, 'children' => $children, 'visible' => $visible, 'blocked' => $blocked];
    }
    private static function allowed($id, $config, $index) {
        return isset($index['parents'][$id]) && !isset($index['blocked'][$id])
            && (empty($config['hide_empty']) || isset($index['visible'][$id]));
    }
    private static function children($parent, $config, $index) {
        $ids = $index['children'][$parent] ?? [];
        return array_values(array_filter($ids, static function ($id) use ($index, $config) {
            return self::allowed($id, $config, $index);
        }));
    }
    public static function path($id, $index) {
        $path = [];
        while ($id && isset($index['parents'][$id]) && !isset($path[$id])) {
            $path[$id] = $id;
            $id = $index['parents'][$id];
        }
        return array_reverse(array_values($path));
    }
    private static function node($term, $config, $index) {
        // The active-page pin must obey the same visibility rules as paginated branches.
        if (!self::allowed($term->term_id, $config, $index)) return null;
        $url = class_exists('PCQO_Category_Links') ? PCQO_Category_Links::catalogue_url($term) : get_term_link($term);
        if (is_wp_error($url)) return null;
        return [
            'id' => $term->term_id,
            'slug' => $term->slug,
            'name' => wp_strip_all_tags(html_entity_decode(apply_filters('list_cats', $term->name, $term), ENT_QUOTES, 'UTF-8')),
            'url' => $url, 'count' => $config['show_count'] ? (int) $term->count : null,
            'children' => (bool) self::children($term->term_id, $config, $index),
        ];
    }
    public static function branch($config, $parent, $offset = 0, $limit = self::PAGE_SIZE) {
        $key = self::key('branch', [$config, $parent, $offset, $limit]);
        $cached = self::$dirty ? false : get_transient($key);
        if (is_array($cached)) return $cached;
        $index = self::index();
        if (is_wp_error($index)) return $index;
        if ($parent && !self::allowed($parent, $config, $index)) {
            return new WP_Error('category_missing', __('Category not found.', 'paint-shop-ux'), ['status' => 404]);
        }
        $ids = self::children($parent, $config, $index);
        if (!$ids || $offset >= count($ids)) return ['items' => [], 'next' => null];
        // parent + number does not produce SQL LIMIT in WP_Term_Query. Use immediate-child IDs instead.
        $terms = get_terms([
            'taxonomy' => 'product_cat', 'include' => $ids, 'hide_empty' => false,
            'hierarchical' => false, 'orderby' => $config['orderby'], 'order' => $config['order'],
            'number' => $limit, 'offset' => $offset, 'update_term_meta_cache' => false,
            'psu_category_menu' => true,
        ]);
        if (is_wp_error($terms)) return $terms;
        $path = self::path($parent, $index);
        if ($path) get_terms(['taxonomy' => 'product_cat', 'include' => $path, 'hide_empty' => false, 'hierarchical' => false, 'update_term_meta_cache' => false]);
        $items = [];
        foreach ($terms as $term) {
            $node = self::node($term, $config, $index);
            if ($node) $items[] = $node;
        }
        $result = ['items' => $items, 'next' => $offset + $limit < count($ids) ? $offset + $limit : null];
        if (!self::$dirty) set_transient($key, $result, HOUR_IN_SECONDS);
        return $result;
    }

    /** Project public URLs after the shared cache; never store a page-specific link there. */
    public static function links($branch, $quick_order_page = 0) {
        if (!$quick_order_page || is_wp_error($branch)) return $branch;
        $base = class_exists('PCQO_Category_Links') ? PCQO_Category_Links::page_url($quick_order_page) : '';
        if (!$base) return new WP_Error('category_menu_missing', __('Category menu is unavailable.', 'paint-shop-ux'), ['status' => 404]);
        foreach ($branch['items'] as &$item) $item['url'] = add_query_arg('cat', $item['slug'], $base);
        unset($item);
        return $branch;
    }

    public static function routes() {
        register_rest_route('paint-shop-ux/v1', '/categories', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [__CLASS__, 'request'],
            'args' => [
                'widget' => ['required' => true, 'type' => 'string'],
                'parent' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0,
                    'validate_callback' => static function ($value) { return is_numeric($value) && (int) $value == $value && $value >= 0 && $value <= 100000; }],
                'locale' => ['type' => 'string', 'default' => ''],
                'quick_order_page' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
        ]);
    }
    public static function request($request) {
        $config = self::config($request['widget']);
        if (!$config) return new WP_Error('category_menu_missing', __('Category menu is unavailable.', 'paint-shop-ux'), ['status' => 404]);
        $locale = $request['locale'] ?: get_locale();
        if (!in_array($locale, array_unique(array_merge(['en_US', get_locale()], get_available_languages())), true)) {
            return new WP_Error('category_locale', __('Unsupported language.', 'paint-shop-ux'), ['status' => 400]);
        }
        $switched = switch_to_locale($locale);
        try {
            $branch = self::links(self::branch($config, (int) $request['parent'], (int) $request['offset']), (int) $request['quick_order_page']);
            if (is_wp_error($branch)) return $branch;
            $response = rest_ensure_response($branch);
            // Cache public DTOs server-side; never cache user-specific WordPress REST response headers.
            $response->header('Cache-Control', 'no-store');
            return $response;
        } finally { if ($switched) restore_previous_locale(); }
    }

    public static function styles() {
        if (!is_active_widget(false, false, 'psu_category_menu', true)
            && !is_active_widget(false, false, 'wpb_wmca_accordion_widget', true)) return;
        wp_enqueue_style('psu-category-menu', plugins_url('../assets/category-menu.css', __FILE__), [], self::VERSION);
    }
    private static function compact() {
        return (function_exists('is_account_page') && is_account_page())
            || (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout());
    }
    public static function widget($instance, $widget, $args) {
        if ($instance === false || is_admin() || !apply_filters('psu_lazy_category_menu_enabled', true)) return $instance;
        $config = self::legacy_config($widget->id);
        if (!$config) return $instance;
        self::render($instance, $widget, $args, $config);
        return false;
    }
    public static function render($instance, $widget, $args, $config) {
        if (!function_exists('wc_get_page_permalink') || !taxonomy_exists('product_cat')) return;
        echo wp_kses_post($args['before_widget']);
        $title = !empty($instance['title']) ? apply_filters('widget_title', $instance['title'], $instance, $widget->id_base) : __('Product categories', 'paint-shop-ux');
        echo wp_kses_post($args['before_title']) . wp_kses_post($title) . wp_kses_post($args['after_title']);
        $shop = wc_get_page_permalink('shop');
        if (self::compact()) {
            echo '<a class="psu-category-menu__catalog" href="' . esc_url($shop) . '">' . esc_html__('Open catalogue', 'paint-shop-ux') . '</a>';
        } else {
            wp_enqueue_script('psu-category-menu', plugins_url('../assets/category-menu.js', __FILE__), [], self::VERSION, true);
            $index = self::index();
            $quick_order_page = class_exists('PCQO_Category_Links') ? PCQO_Category_Links::current_page_id() : 0;
            $current = is_tax('product_cat') ? get_queried_object_id() : 0;
            if ($quick_order_page && isset($_GET['cat']) && is_string($_GET['cat'])) {
                $term = get_term_by('slug', sanitize_title(wp_unslash($_GET['cat'])), 'product_cat');
                if ($term) $current = $term->term_id;
            }
            $path = is_wp_error($index) ? [] : self::path($current, $index);
            $budget = self::INITIAL_LIMIT;
            $labels = ['expand' => __('Expand %s', 'paint-shop-ux'), 'collapse' => __('Collapse %s', 'paint-shop-ux'),
                'loading' => __('Loading categories...', 'paint-shop-ux'), 'error' => __('Categories could not be loaded.', 'paint-shop-ux'),
                'retry' => __('Retry', 'paint-shop-ux'), 'more' => __('More categories', 'paint-shop-ux'), 'empty' => __('No subcategories.', 'paint-shop-ux')];
            echo '<nav class="psu-category-menu" aria-label="' . esc_attr__('Product categories', 'paint-shop-ux') . '" data-widget="' . esc_attr($widget->id) . '" data-endpoint="' . esc_url(rest_url('paint-shop-ux/v1/categories')) . '" data-locale="' . esc_attr(get_locale()) . '" data-quick-order-page="' . (int) $quick_order_page . '" data-labels="' . esc_attr(wp_json_encode($labels)) . '">';
            self::render_branch($config, 0, $path, $current, $index, $budget, $quick_order_page);
            echo '<div class="screen-reader-text" role="status" aria-live="polite"></div></nav>';
            echo '<noscript><a href="' . esc_url($shop) . '">' . esc_html__('Open catalogue', 'paint-shop-ux') . '</a></noscript>';
        }
        echo wp_kses_post($args['after_widget']);
    }
    private static function render_branch($config, $parent, $path, $current, $index, &$budget, $quick_order_page) {
        $limit = min(self::PAGE_SIZE, max(0, $budget));
        $branch = $limit ? self::branch($config, $parent, 0, $limit) : ['items' => [], 'next' => 0];
        echo '<ul id="' . esc_attr($config['widget'] . '-branch-' . $parent) . '" data-parent="' . (int) $parent . '">';
        if (is_wp_error($branch)) {
            echo '<li class="psu-category-menu__error">' . esc_html__('Categories could not be loaded.', 'paint-shop-ux') . ' <button hidden type="button" data-offset="0">' . esc_html__('Retry', 'paint-shop-ux') . '</button> <a href="' . esc_url(wc_get_page_permalink('shop')) . '">' . esc_html__('Open catalogue', 'paint-shop-ux') . '</a></li></ul>';
            return;
        }
        $next_path = (int) ($path[0] ?? 0);
        if ($next_path && !in_array($next_path, array_column($branch['items'], 'id'), true)) {
            $term = get_term($next_path, 'product_cat');
            if ($term instanceof WP_Term) {
                $node = self::node($term, $config, $index);
                if ($node) { $node['pinned'] = true; $branch['items'][] = $node; }
            }
        }
        $branch = self::links($branch, $quick_order_page);
        $budget -= count($branch['items']);
        foreach ($branch['items'] as $node) {
            $open = $node['id'] === $next_path;
            $name = $node['name'];
            $list_id = $config['widget'] . '-branch-' . $node['id'];
            echo '<li data-category="' . (int) $node['id'] . '"' . (!empty($node['pinned']) ? ' data-pinned="1"' : '') . '><div class="psu-category-menu__row"><a href="' . esc_url($node['url']) . '"' . ($node['id'] === $current ? ' aria-current="page"' : '') . '>' . esc_html($name);
            if ($node['count'] !== null) echo ' <small>(' . (int) $node['count'] . ')</small>';
            echo '</a>';
            if ($node['children']) echo '<button hidden type="button" class="psu-category-menu__toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . esc_attr($list_id) . '" aria-label="' . esc_attr(sprintf($open ? __('Collapse %s', 'paint-shop-ux') : __('Expand %s', 'paint-shop-ux'), $name)) . '" data-name="' . esc_attr($name) . '"><span aria-hidden="true">+</span></button>';
            echo '</div>';
            if ($node['children']) {
                if ($open) self::render_branch($config, $node['id'], array_slice($path, 1), $current, $index, $budget, $quick_order_page);
                else echo '<ul hidden id="' . esc_attr($list_id) . '" data-parent="' . (int) $node['id'] . '" data-unloaded="1"></ul>';
            }
            echo '</li>';
        }
        if ($branch['next'] !== null) echo '<li class="psu-category-menu__more"><button hidden type="button" data-offset="' . (int) $branch['next'] . '">' . esc_html__('More categories', 'paint-shop-ux') . '</button></li>';
        echo '</ul>';
    }
    public static function mark_legacy() { self::$legacy_used = true; }
    public static function shortcode_used($output, $tag) {
        if (in_array($tag, ['wpb_wmca_accordion_pro', 'wpb_category_accordion', 'wpb_menu_accordion'], true)) self::mark_legacy();
        return $output;
    }
    public static function legacy_assets() {
        if (self::$legacy_used) return;
        $handles = ['wpb_wmca_jquery_cookie', 'wpb_wmca_accordion_script', 'wpb_wmca_accordion_init'];
        $scripts = wp_scripts();
        foreach ($scripts->queue as $handle) {
            if (!in_array($handle, $handles, true) && array_intersect($scripts->registered[$handle]->deps ?? [], $handles)) return;
        }
        foreach ($handles as $handle) wp_dequeue_script($handle);
        // The legacy stylesheet was printed in the head before we can prove which shortcodes render.
    }
}
PSU_Category_Menu::boot();
