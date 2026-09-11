<?php
/*
Plugin Name: PSU Search & Filters
Description: Базовые фильтры для витрин Woo (location / in_stock). Поиск — Relevanssi.
Version: 1.2.1
Author: PaintCore
Text Domain: psu-search-filters
Domain Path: /languages
*/
if (!defined('ABSPATH')) exit;

function psu_searchable_identifier_meta_keys(): array {
    return [
        '_sku',
        '_gtin',
        '_wc_gtin_code',
        '_global_unique_id',
        '_wpm_gtin_code',
        '_alg_ean',
        '_ean',
        '_sku_gtin',
    ];
}

/** Keep identifier fields indexed regardless of the Relevanssi custom-field mode. */
function psu_add_relevanssi_identifier_meta_keys($fields): array {
    $current = is_array($fields) ? $fields : [];
    return array_values(array_unique(array_merge($current, psu_searchable_identifier_meta_keys())));
}
add_filter('relevanssi_index_custom_fields', 'psu_add_relevanssi_identifier_meta_keys', 20, 2);

function psu_find_exact_identifier_product_ids(string $needle): array {
    global $wpdb;

    $needle = trim($needle);
    if ($needle === '' || strlen($needle) > 128) return [];

    $keys = psu_searchable_identifier_meta_keys();
    $key_placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $sql = $wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$key_placeholders}) AND meta_value = %s LIMIT 50",
        array_merge($keys, [$needle])
    );
    $matched_ids = array_map('intval', (array) $wpdb->get_col($sql));
    $product_ids = [];

    foreach ($matched_ids as $matched_id) {
        $post_type = get_post_type($matched_id);
        if ($post_type === 'product_variation') {
            $matched_id = (int) wp_get_post_parent_id($matched_id);
        }
        if ($matched_id > 0 && get_post_type($matched_id) === 'product') {
            $product_ids[$matched_id] = $matched_id;
        }
    }

    return array_values($product_ids);
}

/**
 * Фильтры для витрин:
 * - ?location=slug1,slug2  (таксономия 'location')
 * - ?in_stock=1            (только в наличии)
 * Цену (?min_price/&max_price) и атрибуты (?filter_pa_*) обрабатывает сам Woo.
 */
add_action('pre_get_posts', function(WP_Query $q){
    if (is_admin() || !$q->is_main_query()) return;

    // Витринные контексты Woo
    if (!function_exists('is_woocommerce') ||
        !(is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag() || is_search())) {
        return;
    }

    $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';
    if ($search !== '') {
        $exact_ids = psu_find_exact_identifier_product_ids($search);
        if ($exact_ids) {
            $q->set('post__in', $exact_ids);
            $q->set('orderby', 'post__in');
            $q->set('_psu_original_search', $search);
            $q->set('s', '');
        }
    }

    // Фильтр по складам: ?location[]=odesa&location[]=kiev1
    if (!empty($_GET['location'])) {
        $raw_locations = is_array($_GET['location'])
            ? wp_unslash($_GET['location'])
            : explode(',', (string) wp_unslash($_GET['location']));
        $slugs = array_filter(array_map('sanitize_title', $raw_locations));
        if ($slugs) {
            $tax_query = (array) $q->get('tax_query');
            $tax_query[] = [
                'taxonomy' => 'location',
                'field'    => 'slug',
                'terms'    => $slugs,
                'operator' => 'IN',
            ];
            $q->set('tax_query', $tax_query);
        }
    }

    if (!empty($_GET['brand'])) {
        $brand = sanitize_title(wp_unslash($_GET['brand']));
        if ($brand !== '') {
            $tax_query = (array) $q->get('tax_query');
            $tax_query[] = [
                'taxonomy' => 'product_brand',
                'field'    => 'slug',
                'terms'    => [$brand],
            ];
            $q->set('tax_query', $tax_query);
        }
    }

    // Только в наличии: ?in_stock=1
    if (!empty($_GET['in_stock'])) {
        $meta_query = (array) $q->get('meta_query');
        $meta_query[] = [
            'key'     => '_stock_status',
            'value'   => 'instock',
            'compare' => '=',
        ];
        $q->set('meta_query', $meta_query);
    }

    // На витринах и в поиске — товары
    $q->set('post_type', ['product']);
}, 30);

add_filter('get_search_query', function ($search) {
    global $wp_query;
    if ($wp_query instanceof WP_Query) {
        $original = (string) $wp_query->get('_psu_original_search');
        if ($original !== '') return $original;
    }
    return $search;
});

function psu_catalog_filter_url(): string {
    if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
        $term = get_queried_object();
        $url = $term instanceof WP_Term ? get_term_link($term) : new WP_Error();
        if (!is_wp_error($url)) return $url;
    }
    if (function_exists('is_shop') && is_shop()) return wc_get_page_permalink('shop');
    return home_url('/');
}

add_action('woocommerce_before_shop_loop', function () {
    if (!function_exists('is_woocommerce') || !(is_shop() || is_product_taxonomy() || is_search())) return;

    $selected_locations = [];
    if (!empty($_GET['location'])) {
        $selected_locations = is_array($_GET['location'])
            ? array_map('sanitize_title', wp_unslash($_GET['location']))
            : array_map('sanitize_title', explode(',', (string) wp_unslash($_GET['location'])));
    }

    $locations = get_terms([
        'taxonomy'   => 'location',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    $brands = get_terms([
        'taxonomy'   => 'product_brand',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($locations)) $locations = [];
    if (is_wp_error($brands)) $brands = [];

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $brand = isset($_GET['brand']) ? sanitize_title(wp_unslash($_GET['brand'])) : '';
    $min_price = isset($_GET['min_price']) ? wc_format_decimal(wp_unslash($_GET['min_price'])) : '';
    $max_price = isset($_GET['max_price']) ? wc_format_decimal(wp_unslash($_GET['max_price'])) : '';
    ?>
    <form class="psu-catalog-filters" method="get" action="<?php echo esc_url(psu_catalog_filter_url()); ?>">
        <div class="psu-catalog-filters__search">
            <label for="psu-catalog-search"><?php esc_html_e('Search products', 'psu-search-filters'); ?></label>
            <input id="psu-catalog-search" type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Name, SKU or barcode', 'psu-search-filters'); ?>">
            <input type="hidden" name="post_type" value="product">
        </div>

        <label class="psu-catalog-filters__field">
            <span><?php esc_html_e('Brand', 'psu-search-filters'); ?></span>
            <select name="brand">
                <option value=""><?php esc_html_e('All brands', 'psu-search-filters'); ?></option>
                <?php foreach ($brands as $brand_term): ?>
                    <option value="<?php echo esc_attr($brand_term->slug); ?>" <?php selected($brand, $brand_term->slug); ?>><?php echo esc_html($brand_term->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <fieldset class="psu-catalog-filters__locations">
            <legend><?php esc_html_e('Warehouses', 'psu-search-filters'); ?></legend>
            <?php foreach ($locations as $location): ?>
                <label><input type="checkbox" name="location[]" value="<?php echo esc_attr($location->slug); ?>" <?php checked(in_array($location->slug, $selected_locations, true)); ?>> <span><?php echo esc_html($location->name); ?></span></label>
            <?php endforeach; ?>
        </fieldset>

        <label class="psu-catalog-filters__stock"><input type="checkbox" name="in_stock" value="1" <?php checked(!empty($_GET['in_stock'])); ?>> <span><?php esc_html_e('In stock only', 'psu-search-filters'); ?></span></label>

        <div class="psu-catalog-filters__price">
            <span><?php esc_html_e('Price', 'psu-search-filters'); ?></span>
            <input type="number" min="0" step="0.01" name="min_price" value="<?php echo esc_attr($min_price); ?>" placeholder="<?php echo esc_attr__('From', 'psu-search-filters'); ?>">
            <input type="number" min="0" step="0.01" name="max_price" value="<?php echo esc_attr($max_price); ?>" placeholder="<?php echo esc_attr__('To', 'psu-search-filters'); ?>">
        </div>

        <?php if (isset($_GET['pp'])): ?><input type="hidden" name="pp" value="<?php echo esc_attr((int) $_GET['pp']); ?>"><?php endif; ?>
        <?php if (isset($_GET['orderby'])): ?><input type="hidden" name="orderby" value="<?php echo esc_attr(sanitize_key(wp_unslash($_GET['orderby']))); ?>"><?php endif; ?>
        <div class="psu-catalog-filters__actions">
            <button type="submit"><?php esc_html_e('Apply filters', 'psu-search-filters'); ?></button>
            <a href="<?php echo esc_url(psu_catalog_filter_url()); ?>"><?php esc_html_e('Reset', 'psu-search-filters'); ?></a>
        </div>
    </form>
    <?php
}, 7);

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_woocommerce') || !(is_shop() || is_product_taxonomy() || is_search())) return;

    wp_register_style('psu-search-filters-inline', false);
    wp_enqueue_style('psu-search-filters-inline');
    wp_add_inline_style('psu-search-filters-inline', '
        .psu-catalog-filters{display:grid;grid-template-columns:minmax(220px,2fr) minmax(160px,1fr) minmax(190px,1.2fr);gap:12px;align-items:end;margin:18px 0 22px;padding:14px;border:1px solid #d8d8d8;background:#fff}
        .psu-catalog-filters label,.psu-catalog-filters fieldset,.psu-catalog-filters__price{margin:0}
        .psu-catalog-filters label>span,.psu-catalog-filters legend,.psu-catalog-filters__price>span{display:block;margin-bottom:5px;font-size:13px;font-weight:600}
        .psu-catalog-filters input[type="search"],.psu-catalog-filters input[type="number"],.psu-catalog-filters select{width:100%;min-height:40px;margin:0}
        .psu-catalog-filters__locations{display:flex;min-width:0;padding:0;border:0;gap:10px;flex-wrap:wrap}
        .psu-catalog-filters__locations legend{width:100%}
        .psu-catalog-filters__locations label,.psu-catalog-filters__stock{display:flex;align-items:center;gap:6px;min-height:40px}
        .psu-catalog-filters__locations label>span,.psu-catalog-filters__stock>span{display:inline;margin:0;font-weight:400}
        .psu-catalog-filters__price{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .psu-catalog-filters__price>span{grid-column:1/-1}
        .psu-catalog-filters__actions{display:flex;align-items:center;gap:12px}
        .psu-catalog-filters__actions button{min-height:40px;margin:0}
        .psu-expanded-subcategories{padding-bottom:18px;border-bottom:1px solid #ddd}
        @media(max-width:900px){.psu-catalog-filters{grid-template-columns:1fr 1fr}}
        @media(max-width:600px){.psu-catalog-filters{grid-template-columns:1fr}.psu-catalog-filters__actions button{flex:1 1 auto}}
    ');
});

/**
 * Reindex products after searchable identifiers are written after post save.
 *
 * External imports often create the post first and add `_sku` afterwards,
 * which is too late for Relevanssi's wp_after_insert_post index update.
 */
function psu_queue_relevanssi_product_reindex($meta_id, int $object_id, string $meta_key, $meta_value): void {
    if (!in_array($meta_key, array_merge(psu_searchable_identifier_meta_keys(), ['_ms_hash']), true)) {
        return;
    }

    if (!in_array(get_post_type($object_id), ['product', 'product_variation'], true)) {
        return;
    }

    static $post_ids = [];
    static $shutdown_registered = false;

    $post_ids[$object_id] = true;

    if ($shutdown_registered) {
        return;
    }

    $shutdown_registered = true;
    add_action('shutdown', function () use (&$post_ids): void {
        if (!function_exists('relevanssi_insert_edit')) {
            return;
        }

        foreach (array_keys($post_ids) as $post_id) {
            relevanssi_insert_edit((int) $post_id);
        }
    }, 20);
}
add_action('added_post_meta', 'psu_queue_relevanssi_product_reindex', 10, 4);
add_action('updated_post_meta', 'psu_queue_relevanssi_product_reindex', 10, 4);
add_action('deleted_post_meta', 'psu_queue_relevanssi_product_reindex', 10, 4);

// Подсветка поисковых слов в заголовках (клиент-сайд, только на страницах поиска)
add_action('wp_footer', function () {
    if (!is_search()) return;

    $q = isset($_GET['s']) ? (string) $_GET['s'] : '';
    if ($q === '') return;

    // Передадим запрос в JS как JSON (безопасно)
    ?>
    <script>
    (function(){
      try {
        var q = <?php echo wp_json_encode($q); ?>;
        q = (q || '').trim();
        if (!q) return;

        // Разбиваем запрос на слова, длинные — первыми (лучше подсветка)
        var terms = q.split(/\s+/).filter(Boolean).sort(function(a,b){return b.length - a.length;});

        var titles = document.querySelectorAll('.woocommerce-loop-product__title');
        var esc = function(s){ return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };

        titles.forEach(function(el){
          var html = el.innerHTML; // уже безопасный текст из PHP (esc_html)
          terms.forEach(function(t){
            var rx = new RegExp('(' + esc(t) + ')', 'gi');
            html = html.replace(rx, '<span class="relevanssi-query-term">$1</span>');
          });
          el.innerHTML = html;
        });
      } catch(e) { /* тихо игнорируем */ }
    })();
    </script>
    <?php
});
