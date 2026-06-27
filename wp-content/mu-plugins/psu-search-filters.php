<?php
/*
Plugin Name: PSU Search & Filters
Description: Базовые фильтры для витрин Woo (location / in_stock). Поиск — Relevanssi.
Version: 1.1.0
Author: PaintCore
Text Domain: psu-search-filters
Domain Path: /languages
*/
if (!defined('ABSPATH')) exit;

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

    // Фильтр по складам: ?location=odesa,kiev1
    if (!empty($_GET['location'])) {
        $slugs = array_filter(array_map('sanitize_title', explode(',', (string)$_GET['location'])));
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

/**
 * Reindex products after searchable identifiers are written after post save.
 *
 * External imports often create the post first and add `_sku` afterwards,
 * which is too late for Relevanssi's wp_after_insert_post index update.
 */
function psu_queue_relevanssi_product_reindex($meta_id, int $object_id, string $meta_key, $meta_value): void {
    if (!in_array($meta_key, ['_sku', '_gtin'], true)) {
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
