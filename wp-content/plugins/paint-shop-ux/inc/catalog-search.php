<?php
defined('ABSPATH') || exit;

/** Read-only indexed suggestions. No shared response cache: prices are customer-specific. */
final class PSU_Catalog_Search {
    const LIMIT = 6;

    public static function context() {
        return !is_admin() && function_exists('is_shop') && (is_shop() || is_product_taxonomy() || is_search());
    }

    public static function widget($instance, $widget) {
        if (!self::context() || $instance === false) return $instance;
        if (in_array($widget->id_base, ['search', 'woocommerce_product_search'], true)) return false;
        if ($widget->id_base === 'block') {
            $blocks = array_values(array_filter(parse_blocks($instance['content'] ?? ''), static function ($block) {
                return $block['blockName'] !== null || trim($block['innerHTML']) !== '';
            }));
            if (count($blocks) === 1 && $blocks[0]['blockName'] === 'core/search') return false;
        }
        return $instance;
    }

    public static function assets() {
        if (!self::context()) return;
        wp_enqueue_style('psu-catalog-search', plugins_url('../assets/catalog-search.css', __FILE__), [], '1.0.0');
        wp_enqueue_script('psu-catalog-search', plugins_url('../assets/catalog-search.js', __FILE__), [], '1.0.0', true);
        wp_localize_script('psu-catalog-search', 'psuCatalogSearch', [
            'url'=>admin_url('admin-ajax.php'),
            'loading'=>__('Searching...', 'paint-shop-ux'),
            'empty'=>__('No matching products.', 'paint-shop-ux'),
            'error'=>__('Suggestions are unavailable. You can still submit your search.', 'paint-shop-ux'),
            'results'=>__('Product suggestions', 'paint-shop-ux'),
        ]);
    }

    private static function text($input, $key) {
        return isset($input[$key]) && is_scalar($input[$key]) ? trim(sanitize_text_field((string) $input[$key])) : '';
    }

    public static function query_args(array $input) {
        $args = ['post_type'=>'product', 'post_status'=>'publish', 'has_password'=>false,
            'posts_per_page'=>self::LIMIT, 'no_found_rows'=>true, 'ignore_sticky_posts'=>true,
            '_psu_suggest'=>true, '_psu_unit_filter'=>self::text($input, 'unit')];
        $tax = [];
        foreach (['product_cat'=>'product_cat', 'brand'=>'product_brand'] as $key=>$taxonomy) {
            $slug = self::text($input, $key);
            if ($slug !== '') {
                $term = get_term_by('slug', sanitize_title($slug), $taxonomy);
                if (!$term) $args['_psu_invalid_filter'] = true;
                else $tax[] = ['taxonomy'=>$taxonomy, 'field'=>'term_id', 'terms'=>[$term->term_id]];
            }
        }
        $locations = $input['location'] ?? [];
        if (!is_array($locations)) $locations = explode(',', (string) $locations);
        $locations = array_slice(array_filter(array_map('sanitize_title', array_filter($locations, 'is_scalar'))), 0, 20);
        $location_ids = [];
        foreach ($locations as $slug) {
            $term = get_term_by('slug', $slug, 'location');
            if (!$term) $args['_psu_invalid_filter'] = true;
            else $location_ids[] = $term->term_id;
        }
        if ($location_ids) $tax[] = ['taxonomy'=>'location', 'field'=>'term_id', 'terms'=>$location_ids];
        $visibility = wc_get_product_visibility_term_ids();
        $blocked = [$visibility['exclude-from-search'] ?? 0];
        if (get_option('woocommerce_hide_out_of_stock_items') === 'yes') $blocked[] = $visibility['outofstock'] ?? 0;
        $tax[] = ['taxonomy'=>'product_visibility', 'field'=>'term_taxonomy_id', 'terms'=>array_filter($blocked), 'operator'=>'NOT IN'];
        $args['tax_query'] = $tax;
        $meta = [];
        if (!empty($input['in_stock'])) $meta[] = ['key'=>'_stock_status','value'=>'instock'];
        foreach (['min_price'=>'>=', 'max_price'=>'<='] as $key=>$compare) {
            $price = self::text($input, $key);
            if ($price !== '' && is_numeric($price)) $meta[] = ['key'=>'_price','value'=>max(0, (float)$price),'type'=>'DECIMAL(19,4)','compare'=>$compare];
        }
        $args['meta_query'] = $meta;
        return $args;
    }

    public static function products(array $input) {
        $needle = self::text($input, 'catalog_search');
        if (mb_strlen($needle) < 2 || mb_strlen($needle) > 100) return [];
        $args = self::query_args($input);
        if (!empty($args['_psu_invalid_filter'])) return [];
        $exact = psu_find_exact_identifier_product_ids($needle);
        $sku_id = wc_get_product_id_by_sku($needle);
        if ($sku_id) {
            $sku_id = wp_get_post_parent_id($sku_id) ?: $sku_id;
            $exact = array_values(array_unique(array_merge([$sku_id], $exact)));
        }
        $ids = [];
        if ($exact) {
            $query = new WP_Query(array_merge($args, ['post__in'=>$exact, 'orderby'=>'post__in']));
            $ids = wp_list_pluck($query->posts, 'ID');
        }
        if (count($ids) < self::LIMIT) {
            $search_args = array_merge($args, ['s'=>$needle, 'post__not_in'=>$ids, 'orderby'=>'relevance', 'posts_per_page'=>self::LIMIT-count($ids)]);
            if (function_exists('relevanssi_do_query')) {
                $query = new WP_Query();
                $query->parse_query($search_args);
                relevanssi_do_query($query);
            } else {
                $query = new WP_Query($search_args);
            }
            $ids = array_merge($ids, wp_list_pluck($query->posts, 'ID'));
        }
        $result = [];
        foreach (array_unique($ids) as $id) {
            $product = wc_get_product($id);
            if (!$product || $product->get_status() !== 'publish' || get_post_field('post_password', $id) !== ''
                || in_array($product->get_catalog_visibility(), ['hidden','catalog'], true)) continue;
            $stocks = [];
            if (function_exists('slu_collect_location_stocks_for_product')) {
                foreach (slu_collect_location_stocks_for_product($product) as $row) {
                    if ((float)($row['qty'] ?? 0) > 0) $stocks[] = (string)$row['name'] . ': ' . wc_format_localized_decimal($row['qty']);
                }
            }
            $result[] = ['id'=>$id, 'name'=>$product->get_name(), 'sku'=>$product->get_sku(),
                'url'=>get_permalink($id), 'image'=>wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src(),
                'price'=>wp_kses_post($product->get_price_html()),
                'stock'=>$stocks ? implode(' · ', $stocks) : ($product->is_in_stock() ? __('In stock', 'paint-shop-ux') : __('Out of stock', 'paint-shop-ux'))];
            if (count($result) === self::LIMIT) break;
        }
        return $result;
    }

    public static function respond() {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        $input = wp_unslash($_GET);
        wp_send_json(['items'=>self::products($input)]);
    }
}
add_filter('widget_display_callback', [PSU_Catalog_Search::class, 'widget'], 30, 2);
add_action('wp_enqueue_scripts', [PSU_Catalog_Search::class, 'assets']);
add_action('wp_ajax_psu_catalog_suggest', [PSU_Catalog_Search::class, 'respond']);
add_action('wp_ajax_nopriv_psu_catalog_suggest', [PSU_Catalog_Search::class, 'respond']);
