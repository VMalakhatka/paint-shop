<?php
/** Supplier visibility and ordering for the storefront, owned by PSU Search & Filters. */
defined('ABSPATH') || exit;

function psu_catalog_supplier_settings(): array {
    $settings = get_option('psu_catalog_suppliers', []);
    return is_array($settings) ? $settings : [];
}

function psu_catalog_supplier_terms(bool $visible_only = false): array {
    $terms = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($terms)) return [];
    $settings = psu_catalog_supplier_settings();
    usort($terms, static function ($a, $b) use ($settings): int {
        $order = (int) ($settings[$a->term_id]['order'] ?? 1000) <=> (int) ($settings[$b->term_id]['order'] ?? 1000);
        return $order ?: strnatcasecmp($a->name, $b->name) ?: ($a->term_id <=> $b->term_id);
    });
    if ($visible_only) {
        $terms = array_values(array_filter($terms, static function ($term) use ($settings): bool {
            return $term->count > 0 && ($settings[$term->term_id]['visible'] ?? true);
        }));
    }
    return $terms;
}

function psu_catalog_use_supplier_order(WP_Query $query): bool {
    if (is_admin() || !$query->is_main_query() || !$query->get('_psu_catalog_query')) return false;
    if ($query->get('_psu_original_search')) return false;
    $order = isset($_GET['orderby']) && is_string($_GET['orderby']) ? sanitize_key($_GET['orderby']) : '';
    // Explicit customer sorting wins. The default catalogue order groups suppliers.
    return $order === '' || $order === 'menu_order';
}

add_filter('posts_clauses', function (array $clauses, WP_Query $query): array {
    if (!psu_catalog_use_supplier_order($query) || $query->is_search()) return $clauses;
    global $wpdb;
    $terms = psu_catalog_supplier_terms();
    if (!$terms) return $clauses;
    $cases = [];
    foreach ($terms as $rank => $term) {
        $cases[] = 'WHEN ' . (int) $term->term_taxonomy_id . ' THEN ' . (int) $rank;
    }
    // One scalar per product avoids duplicate products and sorts before pagination.
    $rank_sql = '(SELECT MIN(CASE psu_tr.term_taxonomy_id ' . implode(' ', $cases) . ' END)'
        . " FROM {$wpdb->term_relationships} psu_tr WHERE psu_tr.object_id = {$wpdb->posts}.ID)";
    $clauses['orderby'] = 'COALESCE(' . $rank_sql . ', ' . count($terms) . ") ASC, {$wpdb->posts}.post_title ASC, {$wpdb->posts}.ID ASC";
    return $clauses;
}, 100, 2);

// Relevanssi orders its complete hit set separately from WP SQL, before slicing pages.
add_filter('relevanssi_hits_filter', function (array $data, WP_Query $query): array {
    if (!psu_catalog_use_supplier_order($query) || empty($data[0])) return $data;
    $ranks = [];
    foreach (psu_catalog_supplier_terms() as $rank => $term) $ranks[$term->term_id] = $rank;
    $ids = array_map(static fn($hit): int => is_object($hit) ? (int) $hit->ID : (int) $hit, $data[0]);
    update_object_term_cache($ids, 'product');
    $product_ranks = [];
    foreach ($ids as $id) {
        $terms = get_the_terms($id, 'product_brand');
        $product_ranks[$id] = count($ranks);
        foreach (is_array($terms) ? $terms : [] as $term) {
            $product_ranks[$id] = min($product_ranks[$id], $ranks[$term->term_id] ?? count($ranks));
        }
    }
    $positions = array_flip($ids);
    usort($data[0], static function ($a, $b) use ($product_ranks, $positions): int {
        $a_id = is_object($a) ? (int) $a->ID : (int) $a;
        $b_id = is_object($b) ? (int) $b->ID : (int) $b;
        return ($product_ranks[$a_id] <=> $product_ranks[$b_id]) ?: ($positions[$a_id] <=> $positions[$b_id]);
    });
    return $data;
}, 30, 2);

add_action('admin_menu', function (): void {
    add_submenu_page(
        function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : 'woocommerce',
        __('Lavka: catalogue suppliers', 'psu-search-filters'),
        __('Lavka: catalogue suppliers', 'psu-search-filters'),
        'manage_woocommerce',
        'psu-catalog-suppliers',
        'psu_render_catalog_suppliers_admin'
    );
});

add_action('admin_post_psu_save_catalog_suppliers', function (): void {
    if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('You do not have permission to manage suppliers.', 'psu-search-filters'), '', ['response' => 403]);
    check_admin_referer('psu_save_catalog_suppliers');
    $settings = psu_catalog_supplier_settings();
    $rows = isset($_POST['suppliers']) && is_array($_POST['suppliers']) ? wp_unslash($_POST['suppliers']) : [];
    foreach ($rows as $id => $row) {
        $id = absint($id);
        if (!is_array($row) || !$id || !term_exists($id, 'product_brand')) continue;
        $settings[$id] = ['visible' => !empty($row['visible']), 'order' => max(0, min(99999, (int) ($row['order'] ?? 1000)))];
    }
    update_option('psu_catalog_suppliers', $settings, false);
    $url = add_query_arg([
        'page' => 'psu-catalog-suppliers', 'saved' => 1,
        'supplier_page' => max(1, absint($_POST['supplier_page'] ?? 1)),
        'supplier_search' => isset($_POST['supplier_search']) && is_string($_POST['supplier_search']) ? sanitize_text_field(wp_unslash($_POST['supplier_search'])) : '',
    ], admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
});

function psu_render_catalog_suppliers_admin(): void {
    if (!current_user_can('manage_woocommerce')) return;
    $settings = psu_catalog_supplier_settings();
    $terms = psu_catalog_supplier_terms();
    $search = isset($_GET['supplier_search']) && is_string($_GET['supplier_search']) ? sanitize_text_field(wp_unslash($_GET['supplier_search'])) : '';
    if ($search !== '') $terms = array_values(array_filter($terms, static fn($term): bool => mb_stripos($term->name, $search) !== false));
    $pages = max(1, (int) ceil(count($terms) / 50));
    $page = min($pages, max(1, absint($_GET['supplier_page'] ?? 1)));
    $terms = array_slice($terms, ($page - 1) * 50, 50);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Lavka: catalogue suppliers', 'psu-search-filters'); ?></h1>
        <?php if (isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Supplier settings saved.', 'psu-search-filters'); ?></p></div><?php endif; ?>
        <form method="get">
            <input type="hidden" name="page" value="psu-catalog-suppliers">
            <p><label for="psu-supplier-search"><?php esc_html_e('Supplier', 'psu-search-filters'); ?></label>
            <input id="psu-supplier-search" type="search" name="supplier_search" value="<?php echo esc_attr($search); ?>">
            <?php submit_button(__('Search', 'psu-search-filters'), 'secondary', '', false); ?></p>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="psu_save_catalog_suppliers">
            <input type="hidden" name="supplier_page" value="<?php echo esc_attr($page); ?>">
            <input type="hidden" name="supplier_search" value="<?php echo esc_attr($search); ?>">
            <?php wp_nonce_field('psu_save_catalog_suppliers'); ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Supplier', 'psu-search-filters'); ?></th>
                    <th><?php esc_html_e('Show in filter', 'psu-search-filters'); ?></th>
                    <th><?php esc_html_e('Display order', 'psu-search-filters'); ?></th>
                    <th><?php esc_html_e('Products', 'psu-search-filters'); ?></th>
                </tr></thead>
                <tbody><?php foreach ($terms as $term): ?>
                    <tr><th scope="row"><?php echo esc_html($term->name); ?></th>
                    <td><input type="checkbox" name="suppliers[<?php echo (int) $term->term_id; ?>][visible]" value="1" aria-label="<?php echo esc_attr(sprintf(__('Show %s in filter', 'psu-search-filters'), $term->name)); ?>" <?php checked($settings[$term->term_id]['visible'] ?? true); ?>></td>
                    <td><input type="number" min="0" max="99999" step="1" class="small-text" name="suppliers[<?php echo (int) $term->term_id; ?>][order]" value="<?php echo esc_attr($settings[$term->term_id]['order'] ?? 1000); ?>" aria-label="<?php echo esc_attr(sprintf(__('Display order: %s', 'psu-search-filters'), $term->name)); ?>"></td>
                    <td><?php echo (int) $term->count; ?></td></tr>
                <?php endforeach; ?></tbody>
            </table>
            <?php submit_button(__('Save supplier settings', 'psu-search-filters')); ?>
        </form>
        <div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links([
            'base' => add_query_arg(['page' => 'psu-catalog-suppliers', 'supplier_search' => $search, 'supplier_page' => '%#%'], admin_url('admin.php')),
            'format' => '', 'current' => $page, 'total' => $pages,
        ])); ?></div></div>
    </div>
    <?php
}
